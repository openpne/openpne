<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Images attached to a talk message, shaped exactly like timeline_post_images: a pure join row,
 * group_message_id -> the message, file_id -> the stored bytes, number = the slot. No timestamps
 * (the File carries them). The FK cascade drops only this join row, never the File bytes.
 *
 * The schema holds N slots though the composer attaches one, so the timeline transfer can bring
 * migrated content that carries several.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_message_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_message_id')->constrained('group_messages')->cascadeOnDelete();
            // Signed INT to match files.id (see create_files migration); foreignId() would emit
            // BIGINT UNSIGNED and fail the FK. Deleting the File cascades this row away.
            $table->integer('file_id');
            $table->unsignedTinyInteger('number');
            $table->foreign('file_id')->references('id')->on('files')->cascadeOnDelete();

            $table->index(['group_message_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_message_images');
    }
};
