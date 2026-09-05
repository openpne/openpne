<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * OpenPNE 3 `member_image` kept up to three images with an is_primary flag; the unique `member_id`
 * here makes OpenPNE 4 a single avatar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->unique()->constrained('members')->cascadeOnDelete();
            // Signed INT to match files.id.
            $table->integer('file_id');
            $table->timestamps();

            $table->foreign('file_id')->references('id')->on('files')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_images');
    }
};
