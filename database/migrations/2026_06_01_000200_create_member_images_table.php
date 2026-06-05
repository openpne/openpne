<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A member's profile image (avatar), pointing at a stored File. Successor of the
 * OpenPNE 3 `member_image` table.
 *
 * `member_id` is unique: one image per member. OpenPNE 3 kept up to three with an
 * is_primary flag; OpenPNE 4 is a single avatar, so the cardinality is a DB constraint
 * rather than application logic.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->unique()->constrained('members')->cascadeOnDelete();
            // Signed INT to match files.id (the create_files migration keeps it a
            // signed INT; foreignId() would emit BIGINT UNSIGNED and fail the FK).
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
