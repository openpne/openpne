<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The single image attached to a timeline post (successor of OpenPNE 3 `activity_image`).
 * A pure join row: timeline_post_id -> the post, file_id -> the stored bytes, number = the slot
 * (always 1 — OpenPNE 3 allows one image per activity, and replies carry none). No timestamps
 * (the File carries them). OpenPNE 3's external-URI image variant is not migrated (file-backed
 * only). The owned File's bytes are purged explicitly by DeleteTimelinePost, not the FK cascade.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timeline_post_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('timeline_post_id')->constrained('timeline_posts')->cascadeOnDelete();
            // Signed INT to match files.id (see create_files migration); foreignId() would emit
            // BIGINT UNSIGNED and fail the FK. Deleting the File cascades this row away.
            $table->integer('file_id');
            $table->unsignedTinyInteger('number');
            $table->foreign('file_id')->references('id')->on('files')->cascadeOnDelete();

            $table->index(['timeline_post_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('timeline_post_images');
    }
};
