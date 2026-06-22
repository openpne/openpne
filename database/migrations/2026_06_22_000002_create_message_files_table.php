<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Images attached to a message (successor of OpenPNE 3 opMessagePlugin `message_file`). A pure join
 * row, like community_topic_images: message_id -> the message, file_id -> the stored bytes, number =
 * the 1..N slot. OpenPNE 3 caps it at app_message_max_image_file_num (default 3); the cap lives in
 * the upload validation (PostImages::MAX_IMAGES), not the schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('messages')->cascadeOnDelete();
            // Signed INT to match files.id (see create_files migration); foreignId() would emit
            // BIGINT UNSIGNED and fail the FK. Deleting the File cascades this row away; deleting the
            // message cascades via message_id. The owned File's bytes are purged explicitly by the
            // action (a FK cascade drops only this row, never the bytes).
            $table->integer('file_id');
            $table->unsignedTinyInteger('number');
            $table->foreign('file_id')->references('id')->on('files')->cascadeOnDelete();

            $table->index(['message_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_files');
    }
};
