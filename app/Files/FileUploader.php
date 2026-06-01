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
 * not removed) is left to a future periodic orphan-file GC.
 */
class FileUploader
{
    public function __construct(private readonly FileStorage $storage) {}

    public function store(UploadedFile $upload, ?string $relatedType = null, ?int $relatedId = null): File
    {
        $file = new File([
            // Opaque, backend-agnostic storage key and URL token (collision is
            // caught by the files.name unique index).
            'name' => Str::random(40),
            'type' => $upload->getMimeType() ?? 'application/octet-stream',
            'original_filename' => $upload->getClientOriginalName(),
            'related_entity_type' => $relatedType,
            'related_entity_id' => $relatedId,
            'byte_size' => (int) $upload->getSize(),
        ]);

        $stream = fopen($upload->getRealPath(), 'rb');

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
}
