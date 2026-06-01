<?php

namespace Tests\Feature\File;

use App\Files\FileStorage;
use App\Files\FileUploader;
use App\Models\File;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * Exercises the default DB-BLOB file storage backend. Runs on both supported
 * engines — SQLite (the default test lane) and MySQL (the second lane) — covering
 * the same behaviour. The local-disk backend is exercised as a control.
 */
class FileStorageTest extends TestCase
{
    use RefreshDatabase;

    public function test_blob_roundtrip_preserves_every_byte_value(): void
    {
        $storage = app(FileStorage::class);
        $file = File::factory()->create();
        // 1 KiB spanning all 256 byte values, so NUL and 0xFF are present — an
        // ASCII-only fixture would hide text-binding / encoding corruption.
        $bytes = $this->binaryFixture(1024);

        $storage->writeStream($file, $this->streamOf($bytes));

        $this->assertSame($bytes, $this->readAll($storage, $file));
    }

    public function test_blob_roundtrip_above_64kib_proves_longblob(): void
    {
        // MySQL BLOB caps at 64 KiB; this would truncate unless the column is
        // LONGBLOB (SQLite BLOB is unbounded, so it passes trivially there).
        $storage = app(FileStorage::class);
        $file = File::factory()->create();
        $bytes = $this->binaryFixture(100 * 1024);

        $storage->writeStream($file, $this->streamOf($bytes));

        $this->assertSame($bytes, $this->readAll($storage, $file));
    }

    public function test_blob_roundtrip_multi_megabyte(): void
    {
        // A few MB stays well under the default max_allowed_packet; a regression
        // that tightened it would surface here rather than in production uploads.
        $storage = app(FileStorage::class);
        $file = File::factory()->create();
        $bytes = $this->binaryFixture(3 * 1024 * 1024);

        $storage->writeStream($file, $this->streamOf($bytes));

        $this->assertSame($bytes, $this->readAll($storage, $file));
    }

    public function test_blob_write_overwrites_existing_bytes(): void
    {
        $storage = app(FileStorage::class);
        $file = File::factory()->create();

        $storage->writeStream($file, $this->streamOf('first'));
        $storage->writeStream($file, $this->streamOf('second'));

        $this->assertSame('second', $this->readAll($storage, $file));
    }

    public function test_blob_exists_reflects_presence(): void
    {
        $storage = app(FileStorage::class);
        $file = File::factory()->create();

        $this->assertFalse($storage->exists($file));
        $storage->writeStream($file, $this->streamOf('x'));
        $this->assertTrue($storage->exists($file));
        $storage->delete($file);
        $this->assertFalse($storage->exists($file));
    }

    public function test_blob_delete_is_idempotent(): void
    {
        $storage = app(FileStorage::class);
        $file = File::factory()->create();

        // No bytes were ever written; deleting must be a no-op, not an error,
        // because the deleting observer runs before the file_bin FK cascade.
        $storage->delete($file);

        $this->assertFalse($storage->exists($file));
    }

    public function test_blob_read_missing_throws(): void
    {
        $storage = app(FileStorage::class);
        $file = File::factory()->create();

        $this->expectException(RuntimeException::class);
        $storage->readStream($file);
    }

    public function test_uploader_persists_metadata_and_bytes(): void
    {
        $bytes = $this->binaryFixture(2048);
        $upload = UploadedFile::fake()->createWithContent('avatar.bin', $bytes);

        $file = app(FileUploader::class)->store($upload, 'community_image', 7);

        $this->assertTrue($file->exists);
        $this->assertSame('community_image', $file->related_entity_type);
        $this->assertSame(7, $file->related_entity_id);
        $this->assertSame(strlen($bytes), $file->byte_size);
        // byte_size must equal the stored content length (verification invariant).
        $this->assertSame($file->byte_size, strlen($this->readAll(app(FileStorage::class), $file)));
        $this->assertTrue(app(FileStorage::class)->exists($file));
    }

    public function test_deleting_file_row_removes_its_bytes(): void
    {
        $storage = app(FileStorage::class);
        $file = File::factory()->create();
        $storage->writeStream($file, $this->streamOf('payload'));
        $this->assertTrue($storage->exists($file));

        // The FileObserver removes the bytes; for DB-BLOB the FK cascade would too.
        $file->delete();

        $this->assertSame(0, DB::table('file_bin')->where('file_id', $file->id)->count());
    }

    public function test_name_collision_on_save_does_not_remove_the_existing_files_bytes(): void
    {
        // A `name` unique-constraint violation means the generated name already
        // belongs to another file, so the failed upload's compensation must NOT
        // delete by that name (it would destroy the existing file's bytes on a
        // disk backend). Force the collision by pinning the random token.
        config()->set('openpne.files.disk', 'local');
        Storage::fake('local');
        Str::createRandomStringsUsing(fn (int $length): string => str_repeat('a', $length));

        try {
            $existing = app(FileUploader::class)->store(UploadedFile::fake()->createWithContent('a.bin', 'KEEP'));
            Storage::disk('local')->assertExists($existing->name);

            try {
                app(FileUploader::class)->store(UploadedFile::fake()->createWithContent('b.bin', 'OTHER'));
                $this->fail('expected a name unique-constraint violation on the second upload');
            } catch (Throwable) {
                // expected: the second upload collides on files.name
            }

            // The pre-existing file's bytes must be untouched by the failed upload.
            Storage::disk('local')->assertExists($existing->name);
            $this->assertSame('KEEP', $this->readAll(app(FileStorage::class), $existing));
        } finally {
            Str::createRandomStringsNormally();
        }
    }

    public function test_local_disk_backend_roundtrip_and_delete(): void
    {
        // Control: the disk backend stores bytes by File::name on a Laravel disk.
        config()->set('openpne.files.disk', 'local');
        Storage::fake('local');

        $bytes = $this->binaryFixture(4096);
        $upload = UploadedFile::fake()->createWithContent('doc.bin', $bytes);

        $file = app(FileUploader::class)->store($upload);

        Storage::disk('local')->assertExists($file->name);
        $this->assertSame($bytes, $this->readAll(app(FileStorage::class), $file));

        $file->delete();
        Storage::disk('local')->assertMissing($file->name);
    }

    private function readAll(FileStorage $storage, File $file): string
    {
        $stream = $storage->readStream($file);
        $contents = stream_get_contents($stream);
        fclose($stream);

        return $contents;
    }

    /**
     * @return resource
     */
    private function streamOf(string $bytes)
    {
        $stream = fopen('php://temp', 'r+b');
        assert($stream !== false);
        fwrite($stream, $bytes);
        rewind($stream);

        return $stream;
    }

    private function binaryFixture(int $size): string
    {
        $alphabet = implode('', array_map('chr', range(0, 255)));

        return substr(str_repeat($alphabet, (int) ceil($size / 256)), 0, $size);
    }
}
