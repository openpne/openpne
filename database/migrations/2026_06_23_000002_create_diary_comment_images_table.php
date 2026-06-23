<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Images attached to a diary comment (successor of OpenPNE 3 `diary_comment_image`). A pure join
 * row: diary_comment_id -> the comment, file_id -> the stored bytes. Unlike diary_image and the
 * community image tables, OpenPNE 3's diary_comment_image carries no `number` column, so neither
 * does this — the images order by id (insertion order). No timestamps (the File carries them).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('diary_comment_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('diary_comment_id')->constrained('diary_comments')->cascadeOnDelete();
            // Signed INT to match files.id (see create_files migration); foreignId() would emit
            // BIGINT UNSIGNED and fail the FK. Deleting the File cascades this row away; deleting
            // the comment cascades via diary_comment_id. The owned File's bytes are purged
            // explicitly by DeleteComment / DeleteDiary (a FK cascade drops only this row).
            $table->integer('file_id');
            $table->foreign('file_id')->references('id')->on('files')->cascadeOnDelete();

            $table->index('diary_comment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('diary_comment_images');
    }
};
