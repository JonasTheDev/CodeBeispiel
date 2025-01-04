<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('pictures', function (Blueprint $table) {
            $table->uuid('id');
            $table->timestamps();
            $table->string('title', 255);
            $table->string('originalPath', 1000);
            $table->unsignedSmallInteger('startpagePosition')->default(0);
            $table->unsignedSmallInteger('galleryPosition')->default(0);
            $table->text('description')->default("Bildbeschreibung derzeit nicht verfügbar.");
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pictures');
    }
};
