<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Images attached to a community event (successor of OpenPNE 3 `community_event_image`).
 * A pure join row: post_id -> the event, file_id -> the stored bytes, number = the 1..N
 * slot. OpenPNE 3 caps it at app_community_event_max_image_file_num (default 3); the cap
 * lives in the upload validation, not the schema. No timestamps (the File carries them).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('community_event_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('community_events')->cascadeOnDelete();
            // Signed INT to match files.id (see create_files migration); foreignId() would
            // emit BIGINT UNSIGNED and fail the FK. Deleting the File cascades this row away;
            // deleting the event cascades via post_id. The owned File's bytes are purged
            // explicitly by DeleteEvent (a FK cascade drops only this row, never the bytes).
            $table->integer('file_id');
            $table->unsignedTinyInteger('number');
            $table->foreign('file_id')->references('id')->on('files')->cascadeOnDelete();

            $table->index(['post_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('community_event_images');
    }
};
