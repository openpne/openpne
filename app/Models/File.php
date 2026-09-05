<?php

namespace App\Models;

use Database\Factories\FileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

// The bytes are deliberately not a relation on this model: they are reached only through the
// App\Files\FileStorage contract, whatever the backend.
#[Fillable(['name', 'type', 'original_filename', 'related_entity_type', 'related_entity_id', 'explicit_visibility', 'byte_size', 'width', 'height'])]
class File extends Model
{
    /** @use HasFactory<FileFactory> */
    use HasFactory;

    /** The one `explicit_visibility` value that makes a file web-readable regardless of owner. */
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
            'width' => 'integer',
            'height' => 'integer',
        ];
    }

    /**
     * Always an in-app URL, never a disk URL, so every fetch passes the policy.
     */
    public function url(): string
    {
        return route('file.show', ['file' => $this->name]);
    }

    /**
     * Login-free, and valid only for a file marked `explicit_visibility` public.
     */
    public function publicUrl(): string
    {
        return route('file.public', ['file' => $this->name]);
    }

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
     * The size must be whitelisted in `openpne.images.allowed_sizes` to resolve. On Classic the
     * requested size is the rendered size (docs/internals/images.md, "Classic is not part of this").
     */
    public function thumbnailUrl(int $width, int $height, bool $square = false): string
    {
        $format = $this->imageFormat() ?? 'jpg';
        $geometry = "w{$width}_h{$height}".($square ? '_sq' : '');

        return route('image.show', ['format' => $format, 'geometry' => $geometry, 'name' => $this->name, 'ext' => $format]);
    }
}
