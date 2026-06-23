<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Images attached to a diary (successor of OpenPNE 3 `diary_image`). A pure join row:
 * diary_id -> the diary, file_id -> the stored bytes, number = the 1..N slot. OpenPNE 3
 * caps it at app_diary_max_image_file_num (default 3); the cap lives in the upload
 * validation, not the schema. No timestamps (the File carries them). The OpenPNE 3
 * source column is `diary_id`, so the upgrade copies it verbatim.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('diary_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('diary_id')->constrained('diaries')->cascadeOnDelete();
            // Signed INT to match files.id (see create_files migration); foreignId() would
            // emit BIGINT UNSIGNED and fail the FK. Deleting the File cascades this row away;
            // deleting the diary cascades via diary_id. The owned File's bytes are purged
            // explicitly by DeleteDiary (a FK cascade drops only this row, never the bytes).
            $table->integer('file_id');
            $table->unsignedTinyInteger('number');
            $table->foreign('file_id')->references('id')->on('files')->cascadeOnDelete();

            $table->index(['diary_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('diary_images');
    }
};
