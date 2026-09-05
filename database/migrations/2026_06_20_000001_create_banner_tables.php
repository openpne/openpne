<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('banners', function (Blueprint $table) {
            $table->id();
            // Placement identifier (OpenPNE 3 banner.name).
            $table->string('name', 64)->unique();
            $table->boolean('is_use_html')->default(false);
            $table->text('html')->nullable();
            $table->timestamps();
        });

        Schema::create('banner_images', function (Blueprint $table) {
            $table->id();
            // Signed INT to match files.id.
            $table->integer('file_id');
            $table->text('url')->nullable();
            // Image label, used as the <img> alt (OpenPNE 3 banner_image.name).
            $table->string('name', 64)->nullable();
            $table->timestamps();

            $table->foreign('file_id')->references('id')->on('files')->cascadeOnDelete();
        });

        Schema::create('banner_use_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('banner_id')->constrained('banners')->cascadeOnDelete();
            $table->foreignId('banner_image_id')->constrained('banner_images')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('banner_use_images');
        Schema::dropIfExists('banner_images');
        Schema::dropIfExists('banners');
    }
};
