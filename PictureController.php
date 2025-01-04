<?php

/**
 * Controller from an Picture-Service of a Laravel-Webapp with CRUD-Functions.
 * Functions were called via REST-API Routes to manage Picture-Entries in a InertiaJS-VueJS Frontend.
 */


namespace App\Http\Controllers;

use App\Models\Picture;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;

class PictureController extends Controller
{

    /* Private Func */

    /**
     * Adjust positions in the specified column.
     * 
     * This function updates positions for entries in a specified column based on a given position.
     * It can either increment positions to make room for a new entry or decrement positions to close gaps after deletion.
     * 
     * @param string $column The column to adjust (e.g., 'galleryPosition', 'startpagePosition').
     * @param int $position The reference position for adjustment.
     * @param bool $decrement If true, decrement positions; otherwise, increment them.
     * @return void
     */
    private function adjustPositions(string $column, int $position, bool $decrement = false): void
    {
        if ($decrement) {
            //Decrement positions for all items with a position greater than the given position
            Picture::where($column, '>', $position)
                ->decrement($column);
        } else {
            //Increment positions for all items with a position greater than or equal to the given position
            Picture::where($column, '>=', $position)
                ->increment($column);
        }
    }

    /**
     * Delete a single picture by ID.
     * 
     * Removes file from storage, entry from DB and adjust positions of the remaining entries.
     * 
     * @param int $id ID of picture-entry
     * @return void
     */
    private function deleteById(int $id): void
    {

        //Get the picture entry
        $picture = Picture::findOrFail($id);

        DB::transaction(function () use ($picture, $id) {
            if (!$picture) {
                Log::error("Picture could not be deleted; Entry with ID $id not found.");
                return;
            }

            //Adjust positions
            if ($picture->startpagePosition > 0) {
                $this->adjustPositions('startpagePosition', $picture->startpagePosition, true);
            }

            if ($picture->galleryPosition > 0) {
                $this->adjustPositions('galleryPosition', $picture->galleryPosition, true);
            }

            //Delete the file
            Storage::delete($picture->originalPath);


            //Delete the picture record
            if ($picture->originalPath) {
                try {
                    Storage::delete($picture->originalPath);
                } catch (\Throwable $ex) {
                    Log::warning("File could not be deleted for Picture ID $picture->id. Error: " . $ex->getMessage());
                }
            }
        });
    }


    /* Public Func */

    /**
     * Get picture-entries
     * 
     * Returns a Collection of Pictures, filtered by use-case.
     * If no case is specified, all pictures will be returned.
     * 
     * @param string $case Use-Case for the images. Can be "gallery", "startPage" or "none".
     * If "none" is used, all images will be returned. Default is "none"
     * @param string $sort Sort order. Can be "asc" or "desc". Default is "desc"
     * @return Collection Collection of Pictures, ordered by the sort order
     */
    public function index(?string $case = "none", ?string $sort = "desc"): Collection
    {
        //Get the wanted use-case (gallery, startpage, none)
        switch ($case) {
            case "gallery":
                $pictures = Picture::where('galleryPosition', '>', 0)->orderBy('galleryPosition', $sort)->get();
                break;
            case "startPage":
                $pictures = Picture::where('startpagePosition', '>', 0)->orderBy('startpagePosition', $sort)->get();
                break;
            default:
                $pictures = Picture::orderBy('updated_at', $sort)->get();
                break;
        }

        //Change the path from the internal filepath to a generated url
        foreach ($pictures as $singlePicture) {
            $singlePicture->originalPath = Storage::url($singlePicture->originalPath);
        }

        return $pictures;
    }


    /**
     * Create new picture-entry
     * 
     * Validates inputs from given request and creates new picture-entry.
     * Make sure given inputs are in formData format to ensure that the uploaded file can be processed!
     * "galleryPosition" and "startpagePosition" can be left empty, ORM will make it 0.
     * 
     *
     * @param Request $request HTTP-POST Request with 'title', 'file', 'galleryPosition', 'startpagePosition' and 'description'
     * @return JsonResponse Response with success-bool and text-message (for better debugging)
     */
    public function create(Request $request): JsonResponse
    {
        //Validate Inputs for later use
        try {
            $safeValues = $request->validate([
                'title' => ['required'],
                'file' => ['required', 'mimes:png,jpg,jpeg,pdf'],
                'galleryPosition' => ['numeric', 'nullable'],
                'startpagePosition' => ['numeric', 'nullable'],
                'description' => ['string', 'max:500'],
            ]);
        } catch (ValidationException $ex) {
            return response()->json([
                'success' => false,
                'message' => $ex->getMessage(),
            ]);
        }

        //Creation Logic
        try {

            //Using transaction to keep db atomic, on error case it automatically does a rollback
            DB::transaction(function () use ($safeValues, $request) {
                $picture = new Picture();
                $picture->title = $safeValues['title'];
                $picture->description = $safeValues['description'] ?? null;

                //Startpage-Positioning
                //If no position is given or position is 0 -> skip
                //0 is set as default, so there is no need to set it manually
                if (isset($safeValues['startpagePosition']) && $safeValues['startpagePosition'] > 0) {
                    $this->adjustPositions('startpagePosition', $safeValues['startpagePosition']);
                    $picture->startpagePosition = $safeValues['startpagePosition'];
                }

                //Same for Gallery-Positioning
                if (isset($safeValues['galleryPosition']) && $safeValues['galleryPosition'] > 0) {
                    $this->adjustPositions('galleryPosition', $safeValues['galleryPosition']);
                    $picture->galleryPosition = $safeValues['galleryPosition'];
                }

                //Save image with storage-function to generate an absolute path and pass it to DB
                $picture->originalPath = Storage::putFile('public/gallery', $request->file('file'));
                $picture->save();
            });

            return response()->json([
                'success' => true,
                'message' => 'Picture created successfully',
            ]);
        } catch (\Throwable $ex) {
            Log::error('Picture creation failed: ' . $ex->getMessage());
            return response()->json([
                'success' => false,
                'message' => $ex->getMessage(),
            ]);
        }
    }

    /**
     * Update an existing picture-entry
     * 
     * Validates inputs from given request and updates the specified picture-entry.
     * Inputs are all optional, so only the given inputs will be updated.
     * Make sure given inputs are in formData format to ensure that the uploaded file can be processed if updated!
     * 
     * 
     * @param Request $request HTTP-PUT Request with 'title', 'file', 'galleryPosition', 'startpagePosition' and 'description'
     * @param int $id ID of the picture to update
     * @return JsonResponse Response with success-bool and text-message (for better debugging)
     */
    public function update(Request $request, int $id): JsonResponse
    {
        //Check if Entry exists
        $picture = Picture::find($id);

        if (!$picture) {
            return response()->json([
                'success' => false,
                'message' => 'Picture not found',
            ]);
        }

        //Validate inputs
        try {
            $safeValues = $request->validate([
                'title' => ['string', 'nullable'],
                'file' => ['mimes:png,jpg,jpeg,pdf', 'nullable'],
                'galleryPosition' => ['numeric', 'nullable'],
                'startpagePosition' => ['numeric', 'nullable'],
                'description' => ['string', 'max:500', 'nullable'],
            ]);
        } catch (ValidationException $ex) {
            return response()->json([
                'success' => false,
                'message' => $ex->getMessage(),
            ]);
        }

        //Update logic
        try {
            DB::transaction(function () use ($safeValues, $request, $picture) {

                //Update title
                if (isset($safeValues['title'])) {
                    $picture->title = $safeValues['title'];
                }

                //Update description
                if (isset($safeValues['description'])) {
                    $picture->description = $safeValues['description'];
                }

                //Update startpagePosition
                if (isset($safeValues['startpagePosition']) && $safeValues['startpagePosition'] > 0) {
                    $this->adjustPositions('startpagePosition', $safeValues['startpagePosition']);
                    $picture->startpagePosition = $safeValues['startpagePosition'];
                }

                //Update galleryPosition
                if (isset($safeValues['galleryPosition']) && $safeValues['galleryPosition'] > 0) {
                    $this->adjustPositions('galleryPosition', $safeValues['galleryPosition']);
                    $picture->galleryPosition = $safeValues['galleryPosition'];
                }

                //Update file
                if ($request->hasFile('file')) {

                    //Delete the old file to prevent unused files from piling up
                    if ($picture->originalPath) {
                        Storage::delete($picture->originalPath);
                    }
                    $picture->originalPath = Storage::putFile('public/gallery', $request->file('file'));
                }

                $picture->save();
            });

            return response()->json([
                'success' => true,
                'message' => 'Picture updated successfully',
            ]);
        } catch (\Throwable $ex) {
            Log::error('Picture update failed: ' . $ex->getMessage());
            return response()->json([
                'success' => false,
                'message' => $ex->getMessage(),
            ]);
        }
    }

    /**
     * Delete a picture entry.
     * 
     * Deletes the picture entry specified by its ID.
     * Ensures that the file in the storage is also removed.
     * Automatically reorders positions if the deleted entry had a valid position in 'galleryPosition' or 'startpagePosition'.
     * 
     * @param int $id The ID of the picture to delete.
     * @return JsonResponse Response with success-bool and a descriptive message.
     */
    public function delete(int $id): JsonResponse
    {
        try {
            DB::transaction(function () use ($id) {
                $this->deleteById($id);
            });

            return response()->json([
                'success' => true,
                'message' => 'Picture deleted successfully.',
            ]);
        } catch (ModelNotFoundException $ex) {
            return response()->json([
                'success' => false,
                'message' => 'Picture not found.',
            ]);
        } catch (\Throwable $ex) {
            Log::error('Picture deletion failed: ' . $ex->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while deleting the picture.',
            ]);
        }
    }


    /**
     * Bulk delete picture-entries.
     * 
     * Deletes multiple pictures by their IDs by leveraging the delete method for a single picture.
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function bulkDelete(Request $request): JsonResponse
    {
        $ids = $request->input('ids', []);

        if (empty($ids)) {
            return response()->json([
                'success' => false,
                'message' => 'No picture IDs provided',
            ], 400);
        }

        foreach ($ids as $id) {
            $this->deleteById($id); // Delegating to a reusable delete method
        }


        return response()->json([
            'success' => true,
            'message' => 'Pictures deleted successfully',
        ]);
    }


}

