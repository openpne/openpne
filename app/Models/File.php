<?php

namespace App\Models;

use Database\Factories\FileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

// Metadata of an uploaded file (the `files` table).
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

    /** explicit_visibility value that makes a file web-readable regardless of owner (FilePolicy). */
    public const VISIBILITY_PUBLIC = 'public';

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

    /**
     * Login-free URL for a file marked explicit_visibility='public' (an admin asset). Served by the
     * public PublicFileController, unlike url() which is behind the authed FileController.
     */
    public function publicUrl(): string
    {
        return route('file.public', ['file' => $this->name]);
    }

    /** The image format token (jpg/png/gif/webp) from the MIME type, or null if not a supported image. */
    public function imageFormat(): ?string
    {
        return match ($this->type) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => null,
        };
    }

    /**
     * URL of a thumbnail variant, in the OpenPNE 3-compatible /cache/img form. The size
     * must be whitelisted (config openpne.images.allowed_sizes) to resolve.
     *
     * Where CSS sizes the image (every Modern surface), ask for at least twice the box it
     * paints into, not the box itself: the whitelist is OpenPNE 3's 1x-display set, so the
     * box size reads as the obvious argument and leaves every retina client upscaling.
     * Classic is the exception — its `<img>` carries no dimensions, so the size requested
     * here *is* the rendered size and changing it moves the layout.
     */
    public function thumbnailUrl(int $width, int $height, bool $square = false): string
    {
        $format = $this->imageFormat() ?? 'jpg';
        $geometry = "w{$width}_h{$height}".($square ? '_sq' : '');

        return route('image.show', ['format' => $format, 'geometry' => $geometry, 'name' => $this->name, 'ext' => $format]);
    }
}
