<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;


class Picture extends Model
{
    // Make MassAssignment possible and use UUIDs per default
    use HasFactory, HasUuids;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'pictures';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'title','originalPath','startpagePosition','galleryPosition','description'
    ];

    /**
     * The model's default values for attributes.
     *
     * @var array
     */
    protected $attributes = [
        'startpagePosition' => 0,
        'galleryPosition' => 0,
        'description' => "Bildbeschreibung derzeit nicht verfügbar."
    ];
}
