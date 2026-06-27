<?php

namespace App\Models;

use App\Files\FileStorage;
use Database\Factories\BannerImageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Throwable;

// A banner image in the shared pool, pointing at a stored File (successor of OpenPNE 3
// `banner_image`). The File's bytes are public (FilePolicy), since a banner shows to guests.
// Deleting the row leaves the File; the delete action purges it explicitly (the files→banner_images
// cascade only runs the other way).
#[Fillable(['file_id', 'url', 'name'])]
class BannerImage extends Model
{
    /** @use HasFactory<BannerImageFactory> */
    use HasFactory;

    /** @return BelongsTo<File, $this> */
    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }

    /** @return BelongsToMany<Banner, $this> */
    public function banners(): BelongsToMany
    {
        return $this->belongsToMany(Banner::class, 'banner_use_images')->withTimestamps();
    }

    /** @var array{0: int, 1: int}|null */
    private ?array $dimensions = null;

    private bool $dimensionsResolved = false;

    /**
     * Pixel dimensions of the stored image ([width, height]), or null when the bytes are missing or
     * not a readable image (e.g. a non-raster file imported by a future upgrade). Reads the bytes on
     * demand (memoized per instance) — fine for the small, admin-only banner pool.
     *
     * @return array{0: int, 1: int}|null
     */
    public function dimensions(): ?array
    {
        if ($this->dimensionsResolved) {
            return $this->dimensions;
        }
        $this->dimensionsResolved = true;

        $file = $this->file;
        if ($file === null) {
            return null;
        }

        $storage = app(FileStorage::class);
        if (! $storage->exists($file)) {
            return null;
        }

        try {
            $stream = $storage->readStream($file);
            $bytes = stream_get_contents($stream);
            fclose($stream);
        } catch (Throwable) {
            return null;
        }

        $size = is_string($bytes) ? @getimagesizefromstring($bytes) : false;

        return $this->dimensions = $size !== false ? [$size[0], $size[1]] : null;
    }

    /** "W × H" for display, or null when the dimensions can't be read. */
    public function dimensionsLabel(): ?string
    {
        $dimensions = $this->dimensions();

        return $dimensions !== null ? $dimensions[0].' × '.$dimensions[1] : null;
    }
}
