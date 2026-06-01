<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Uploaded-file metadata (successor of the OpenPNE 3 `file` table). Holds metadata
 * only; the bytes live in the companion `file_bin` table.
 *
 * `id` is a SIGNED INT (not the project-default BIGINT UNSIGNED of `$table->id()`).
 * OpenPNE 3's real `file.id` is `INT AUTO_INCREMENT` (signed), and the upgrade
 * tool migrates `file_bin` by a metadata-only FK rewire onto this table rather
 * than copying ~GB of BLOBs. That only stays metadata-only if `files.id` matches
 * the existing `file_bin.file_id` (signed INT) in type and signedness — so the id
 * type here is load-bearing for the upgrade, not a style choice.
 *
 * INVARIANT for future tables: any table that references `files.id` as a foreign
 * key (member_images, message_file, ...) must declare its column as
 * `$table->integer('file_id')` (signed INT) to match — NOT `$table->foreignId()`,
 * which emits BIGINT UNSIGNED and would fail FK creation against this signed INT.
 *
 * `related_entity_type` / `related_entity_id` point at the owning entity (a member,
 * a diary, a community, ...) and are the source of visibility inheritance; there is
 * deliberately no owner/member column (OpenPNE 3 `file` has none either — ownership
 * and authorization flow through the related entity). A null related_entity_type
 * means "not yet linked": the FilePolicy (delivery slice) must resolve a null /
 * missing / deleted owning entity fail-closed (private), never as public.
 * `explicit_visibility` overrides that inheritance (null = inherit).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('files', function (Blueprint $table) {
            // Signed INT auto-increment PK (see file header: matches OpenPNE 3
            // file.id so the upgrade tool's file_bin FK rewire stays metadata-only).
            $table->integer('id', true, false);

            // Backend-agnostic storage key and URL token: an opaque unique token
            // (OpenPNE 3 generated it from a random filename). The bytes are keyed
            // by `id` in file_bin, but local/S3 backends store under this `name`.
            $table->string('name', 64)->unique();
            $table->string('type', 64); // MIME type
            // OpenPNE 3 `file.original_filename` is TEXT; keep TEXT so a >255-char
            // upload name is not truncated by the upgrade INSERT...SELECT.
            $table->text('original_filename')->nullable();

            // Owning entity for visibility inheritance (see file header for the
            // fail-closed rule the FilePolicy must honour on null/missing/deleted).
            $table->string('related_entity_type')->nullable();
            $table->unsignedBigInteger('related_entity_id')->nullable();
            // Explicit visibility override; null = inherit from the related entity.
            // Kept as a string (not a boolean) so the delivery slice can express
            // friend-only / community-only without a schema change.
            $table->string('explicit_visibility')->nullable();

            // Byte length of the stored content (OpenPNE 3 `filesize`, renamed for
            // clarity). Lets quota accounting and `byte_size = LENGTH(bin)`
            // verification avoid reading the BLOB.
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
