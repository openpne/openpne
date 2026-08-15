<?php

namespace Tests\Fixtures;

use App\Files\FileStorage;
use App\Models\File;

/**
 * Answers for one file with a CountedByteStream, and delegates every other to the real backend, so
 * a test can say "this row understates its bytes" at any scale while the rest of the fixture stays
 * genuinely stored. Bind it over FileStorage with prepare() called on the stream.
 */
class CountingFileStorage implements FileStorage
{
    public function __construct(
        private readonly FileStorage $inner,
        private readonly int $countedFileId,
    ) {}

    public function readStream(File $file)
    {
        return $this->counted($file) ? CountedByteStream::open() : $this->inner->readStream($file);
    }

    public function writeStream(File $file, $stream): void
    {
        $this->inner->writeStream($file, $stream);
    }

    public function delete(File $file): void
    {
        $this->inner->delete($file);
    }

    public function exists(File $file): bool
    {
        return $this->counted($file) || $this->inner->exists($file);
    }

    private function counted(File $file): bool
    {
        return (int) $file->getKey() === $this->countedFileId;
    }
}
