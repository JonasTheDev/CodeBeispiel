<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PictureController;

// Picture Controller Routes
Route::controller(PictureController::class)->group( function () {

    //Public Routes
    Route::get("/getPictures/{case?}/{sort?}", 'index');

    //Admin Routes
    Route::group(['prefix' => 'admin', 'middleware' => ['auth:web']], function(){
        Route::post('/Picture', 'create');
        Route::post('/Picture/{id}', 'update');
        Route::delete('/Picture/{id}', 'delete');
    });

});
