<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Images attached to an event comment (successor of OpenPNE 3 `community_event_comment_image`).
 * Same shape as community_event_images: post_id -> the comment, file_id -> the stored bytes,
 * number = the 1..N slot. No timestamps (the File carries them).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('community_event_comment_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('community_event_comments')->cascadeOnDelete();
            // Signed INT to match files.id (see create_files migration). DeleteEventComment purges
            // the owned File's bytes; the FK cascade only drops this join row.
            $table->integer('file_id');
            $table->unsignedTinyInteger('number');
            $table->foreign('file_id')->references('id')->on('files')->cascadeOnDelete();

            $table->index(['post_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('community_event_comment_images');
    }
};
