<?php

namespace App\Files;

use App\Models\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * The metadata row, the bytes and the canonical are written inside one DB transaction; a disk
 * backend's write and the cache disk cannot join it, so both are compensated here rather than in
 * FileObserver — a rollback never fires the deleting event (docs/internals/file-storage.md, "Writing
 * an upload"). A cache disk that will not take the canonical does not fail the upload.
 */
class FileUploader
{
    public function __construct(
        private readonly FileStorage $storage,
        private readonly ImageProcessor $processor,
        private readonly ImageCache $cache,
    ) {}

    /**
     * @throws ImageProcessingException when the picture is refused; the caller converts it to a field validation error
     * @throws ImageProcessorUnavailableException when the processor is down; the caller converts it the same way
     */
    public function store(UploadedFile $upload, ?string $relatedType = null, ?int $relatedId = null, ?string $explicitVisibility = null): File
    {
        $type = $upload->getMimeType() ?? 'application/octet-stream';
        $format = ImageSpec::formatFor($type);

        // Only a raster is held in memory; anything else streams from the temp file.
        $bytes = $format !== null ? (string) file_get_contents($upload->getRealPath()) : null;

        // Produced before anything is saved, so a refusal costs no compensation.
        $canonical = $format !== null ? $this->processor->process((string) $bytes, $type, ImageSpec::canonical($format)) : null;

        if ($canonical !== null && strlen($canonical->bytes) > ImageSourceLimit::bytes()) {
            throw new ImageProcessingException(sprintf('The canonical is %d bytes, over the %d byte source limit.', strlen($canonical->bytes), ImageSourceLimit::bytes()));
        }

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
            // The stored bytes are the upload as received; the canonical's length is not this.
            'byte_size' => $bytes !== null ? strlen($bytes) : (int) $upload->getSize(),
            'width' => $canonical?->width,
            'height' => $canonical?->height,
        ]);

        $stream = $bytes !== null ? $this->memoryStream($bytes) : fopen($upload->getRealPath(), 'rb');

        if ($stream === false) {
            throw new RuntimeException("Unable to open the uploaded file [{$file->name}].");
        }

        $saved = false;

        try {
            DB::transaction(function () use ($file, $stream, $canonical, &$saved): void {
                $file->save();
                // The row now owns its unique name, so the storage key is ours to
                // clean up if a later step fails.
                $saved = true;

                // Published (best-effort) before the bytes, so a storage failure is the only thing left to undo.
                if ($canonical !== null) {
                    $this->cache->putCanonical($file, $canonical);
                }

                $this->storage->writeStream($file, $stream);
            });
        } catch (Throwable $e) {
            // Compensate only when the row was saved: if save() itself failed on the `name` unique
            // index the key belongs to a pre-existing file, whose bytes and cache must not be deleted.
            if ($saved) {
                $this->storage->delete($file);
                $this->cache->purge($file);
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
