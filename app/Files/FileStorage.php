<?php

namespace App\Files;

use App\Models\File;

/**
 * Stores and retrieves the bytes of an uploaded file, keyed by the File entity.
 *
 * This is OpenPNE's own storage seam rather than a Laravel filesystem disk,
 * because the default DB-BLOB backend keys bytes by file_id (a surrogate key in
 * the frozen `file_bin` table), which a path-string disk abstraction cannot
 * address without re-deriving the id from `files` on every call. Passing the
 * File entity (which already carries id and name) lets each backend use its
 * native key directly: DbBlobFileStorage by file_id, DiskFileStorage by name.
 *
 * Scope: this contract is intentionally the four byte-level operations only.
 * URL generation / delivery is NOT here — the DB-BLOB backend cannot return a
 * URL (its bytes are streamed by a controller) while disk backends have native
 * URLs; the File::url() single entry point will be added above this seam later
 * without changing it.
 */
interface FileStorage
{
    /**
     * Write the bytes of $file from $stream, overwriting any existing content.
     *
     * Concurrent writes to the same file are out of scope: an upload always
     * targets a freshly created File (a fresh id / name), so writes never race on
     * one key in the supported flows.
     *
     * @param  resource  $stream
     */
    public function writeStream(File $file, $stream): void;

    /**
     * Open the stored bytes of $file for reading.
     *
     * @return resource
     */
    public function readStream(File $file);

    /**
     * Remove the stored bytes of $file. Idempotent: a missing object is not an error.
     */
    public function delete(File $file): void;

    /**
     * Whether stored bytes exist for $file.
     */
    public function exists(File $file): bool;
}
