<?php

namespace App\Files;

use App\Models\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Creates a File metadata row and stores its bytes through the FileStorage seam.
 *
 * The metadata row and the bytes are written inside one DB transaction. For the
 * DB-BLOB backend this is fully atomic (both rows are in the same database). For
 * a disk backend the physical write cannot join the DB transaction, so a failure
 * after the bytes were written is compensated by removing them best-effort here
 * (not in FileObserver — a transaction rollback never fires the deleting event).
 * The residual race (commit fails after a successful disk write yet the file is
 * not removed) is accepted: with no metadata row the bytes are unreachable and
 * only waste space.
 */
class FileUploader
{
    /** MIME types the metadata stripper rewrites in memory before storing (gif and non-images bypass). */
    private const STRIPPABLE = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(
        private readonly FileStorage $storage,
        private readonly ImageMetadataStripper $stripper,
    ) {}

    public function store(UploadedFile $upload, ?string $relatedType = null, ?int $relatedId = null, ?string $explicitVisibility = null): File
    {
        $type = $upload->getMimeType() ?? 'application/octet-stream';
        // Strip EXIF/GPS (and XMP/comments) before byte_size is captured, so the delivery
        // Content-Length matches the stored, stripped length. May throw ImageMetadataStripException
        // (fail-closed) — left to propagate; the caller converts it to a field validation error.
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
            // Only compensate when the row was saved: then the name/id is ours.
            // If save() itself failed (e.g. a `name` unique-constraint collision),
            // the key belongs to a pre-existing file, so a disk backend must NOT
            // delete by it. The transaction has already rolled back the row (and
            // the file_bin row, for DB-BLOB); a disk backend's physical write, if
            // it happened, is removed here.
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
     * The image's rendered pixel size, or [null, null] for anything else. Measured from the bytes
     * that will actually be stored, since stripping rewrites the container. Only an image is read
     * into memory — a video or archive would be a pointless multi-megabyte allocation. Bytes that
     * do not decode still upload (the size stays unknown); it is metadata, not a validation gate.
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
