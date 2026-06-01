<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A member's profile image(s), each pointing at a stored File. Successor of the
 * OpenPNE 3 `member_image` table.
 *
 * `is_primary` marks the avatar shown for the member. The current upload flow keeps
 * a single (primary) image per member by replacement; the column is here so the
 * OpenPNE 3 multi-image model (up to three) can be restored without a schema change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            // Signed INT to match files.id (the create_files migration keeps it a
            // signed INT; foreignId() would emit BIGINT UNSIGNED and fail the FK).
            $table->integer('file_id');
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->foreign('file_id')->references('id')->on('files')->cascadeOnDelete();
            $table->index('member_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_images');
    }
};
