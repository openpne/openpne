<?php

namespace App\Files;

use App\Models\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * The metadata row and the bytes are written inside one DB transaction, and a disk backend's write,
 * which cannot join it, is compensated here rather than in FileObserver — a rollback never fires the
 * deleting event ([file-storage.md](../../docs/internals/file-storage.md) § Writing an upload).
 */
class FileUploader
{
    /** MIME types the metadata stripper rewrites in memory before storing (gif and non-images bypass). */
    private const STRIPPABLE = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(
        private readonly FileStorage $storage,
        private readonly ImageMetadataStripper $stripper,
    ) {}

    /**
     * @throws ImageMetadataStripException when a strip fails closed; the caller converts it to a field validation error
     */
    public function store(UploadedFile $upload, ?string $relatedType = null, ?int $relatedId = null, ?string $explicitVisibility = null): File
    {
        $type = $upload->getMimeType() ?? 'application/octet-stream';
        // Stripping runs before byte_size is captured, so the delivery Content-Length matches the
        // stored bytes.
        $stripped = $this->shouldStrip($type)
            ? $this->stripper->strip((string) file_get_contents($upload->getRealPath()), $type)
            : null;

        [$width, $height] = $this->dimensions($upload, $type, $stripped);

        $file = new File([
            // Opaque, backend-agnostic storage key and URL token (collision is
            // caught by the files.name unique index).
            'name' => Str::random(40),
            'type' => $type,
            'original_filename' => $upload->getClientOriginalName(),
            'related_entity_type' => $relatedType,
            'related_entity_id' => $relatedId,
            // null = inherit visibility from the owner; an ownerless admin asset passes 'public' so
            // FilePolicy serves it (an ownerless file is otherwise fail-closed denied).
            'explicit_visibility' => $explicitVisibility,
            'byte_size' => $stripped !== null ? strlen($stripped) : (int) $upload->getSize(),
            'width' => $width,
            'height' => $height,
        ]);

        $stream = $stripped !== null ? $this->memoryStream($stripped) : fopen($upload->getRealPath(), 'rb');

        if ($stream === false) {
            throw new RuntimeException("Unable to open the uploaded file [{$file->name}].");
        }

        $saved = false;

        try {
            DB::transaction(function () use ($file, $stream, &$saved): void {
                $file->save();
                // The row now owns its unique name, so the storage key is ours to
                // clean up if a later step fails.
                $saved = true;
                $this->storage->writeStream($file, $stream);
            });
        } catch (Throwable $e) {
            // Compensate only when the row was saved: if save() itself failed on the `name` unique
            // index the key belongs to a pre-existing file, whose bytes must not be deleted.
            if ($saved) {
                $this->storage->delete($file);
            }

            throw $e;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return $file;
    }

    /**
     * Measured from the bytes that will actually be stored, since stripping rewrites the container.
     *
     * @return array{0: int|null, 1: int|null}
     */
    private function dimensions(UploadedFile $upload, string $type, ?string $stripped): array
    {
        if (! str_starts_with($type, 'image/')) {
            return [null, null];
        }

        return ImageDimensions::fromBytes($stripped ?? (string) file_get_contents($upload->getRealPath()))
            ?? [null, null];
    }

    private function shouldStrip(string $mime): bool
    {
        return (bool) config('openpne.images.strip_metadata') && in_array($mime, self::STRIPPABLE, true);
    }

    /**
     * @return resource
     */
    private function memoryStream(string $bytes)
    {
        $stream = fopen('php://temp', 'r+b');
        assert($stream !== false);
        fwrite($stream, $bytes);
        rewind($stream);

        return $stream;
    }
}
