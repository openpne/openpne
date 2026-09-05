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

// Deleting this row leaves the File behind: the cascade only runs from `files`, so the caller purges
// the File itself.
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
     * Null when the bytes are missing or are not a readable image, such as a non-raster file
     * imported by the OpenPNE 3 upgrade. Reading the bytes on demand is deliberate: the banner pool
     * is small and admin-only.
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

    public function dimensionsLabel(): ?string
    {
        $dimensions = $this->dimensions();

        return $dimensions !== null ? $dimensions[0].' × '.$dimensions[1] : null;
    }
}
