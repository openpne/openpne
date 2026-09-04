<?php

namespace App\Files;

use App\Models\File;

/**
 * Byte-level operations only: delivery sits above this seam and is backend-independent
 * ([file-storage.md](../../docs/internals/file-storage.md)).
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
