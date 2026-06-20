<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * OpenPNE 3 design banners (banner / banner_image / banner_use_image). A banner is a fixed
 * placement (`name`, e.g. top_before / top_after) showing either operator HTML or one of a pool
 * of images chosen at random; banner_use_images is the placement↔image many-to-many.
 *
 * The structure mirrors OpenPNE 3 so the (file-step-gated) upgrade is a straight table copy.
 * OpenPNE 3's banner.caption (I18n) is not carried: it was an admin-only label, never rendered,
 * and OpenPNE 4 labels the fixed placements in the UI.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('banners', function (Blueprint $table) {
            $table->id();
            // Placement identifier (OpenPNE 3 banner.name): top_before / top_after.
            $table->string('name', 64)->unique();
            $table->boolean('is_use_html')->default(false);
            $table->text('html')->nullable();
            $table->timestamps();
        });

        Schema::create('banner_images', function (Blueprint $table) {
            $table->id();
            // Signed INT to match files.id (foreignId() would emit BIGINT UNSIGNED and fail the FK).
            $table->integer('file_id');
            // External link target (OpenPNE 3 banner_image.url); the image links here when set.
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
