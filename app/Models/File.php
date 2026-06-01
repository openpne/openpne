<?php

namespace App\Models;

use Database\Factories\FileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

// Metadata of an uploaded file (the `files` table, successor of OpenPNE 3 `file`).
//
// The bytes are NOT an Eloquent relation on this model: they live in `file_bin`
// and are reached only through the App\Files\FileStorage contract, so there is a
// single access path to the content regardless of the storage backend
// (DB-BLOB / local / S3). `id` is a signed INT (see the create_files migration:
// it must match file_bin.file_id for the upgrade tool's metadata-only FK rewire).
#[Fillable(['name', 'type', 'original_filename', 'related_entity_type', 'related_entity_id', 'explicit_visibility', 'byte_size'])]
class File extends Model
{
    /** @use HasFactory<FileFactory> */
    use HasFactory;

    protected $table = 'files';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'related_entity_id' => 'integer',
            'byte_size' => 'integer',
        ];
    }

    /**
     * The app route that serves this file's bytes, keyed by the opaque `name` token.
     * Always an in-app URL (never a direct disk URL) so FileController + FilePolicy
     * gate every fetch.
     */
    public function url(): string
    {
        return route('file.show', ['file' => $this->name]);
    }
}
