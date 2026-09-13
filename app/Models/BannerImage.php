<?php

namespace App\Models;

use Database\Factories\BannerImageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

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

    /**
     * Null until the file has a recorded size: a row imported from OpenPNE 3 or uploaded before sizes
     * were recorded gets one from `openpne:backfill-image-dimensions`, never from reading the bytes here.
     *
     * @return array{0: int, 1: int}|null
     */
    public function dimensions(): ?array
    {
        $file = $this->file;

        if ($file === null || $file->width === null || $file->height === null) {
            return null;
        }

        return [(int) $file->width, (int) $file->height];
    }

    public function dimensionsLabel(): ?string
    {
        $dimensions = $this->dimensions();

        return $dimensions !== null ? $dimensions[0].' × '.$dimensions[1] : null;
    }
}
