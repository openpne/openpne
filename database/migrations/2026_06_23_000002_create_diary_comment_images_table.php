<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * OpenPNE 3's diary_comment_image carries no `number` column, so neither does this and the images
 * order by id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('diary_comment_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('diary_comment_id')->constrained('diary_comments')->cascadeOnDelete();
            // Signed INT to match files.id.
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
