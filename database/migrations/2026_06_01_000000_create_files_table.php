<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * `files.id` is a signed INT matching OpenPNE 3 `file.id`, so the upgrade re-points the `file_bin`
 * FK onto this table instead of copying its BLOBs (docs/internals/upgrade.md, "file_bin").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('files', function (Blueprint $table) {
            // Any table with an FK onto files.id must declare that column `$table->integer()`;
            // foreignId() emits BIGINT UNSIGNED and the FK fails to create.
            $table->integer('id', true, false);

            // An opaque random token, as OpenPNE 3 generated it.
            $table->string('name', 64)->unique();
            $table->string('type', 64);
            // OpenPNE 3 `file.original_filename` is TEXT, so TEXT here keeps a >255-char name from
            // being truncated by the upgrade INSERT...SELECT.
            $table->text('original_filename')->nullable();

            $table->string('related_entity_type')->nullable();
            $table->unsignedBigInteger('related_entity_id')->nullable();
            $table->string('explicit_visibility')->nullable();

            // OpenPNE 3 `file.filesize`.
            $table->unsignedBigInteger('byte_size');

            // OpenPNE 3 `file` carries created_at / updated_at; mirror them so the
            // upgrade INSERT...SELECT maps straight across.
            $table->dateTime('created_at');
            $table->dateTime('updated_at');

            $table->index(['related_entity_type', 'related_entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('files');
    }
};
