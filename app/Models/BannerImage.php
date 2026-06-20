<?php

namespace App\Models;

use Database\Factories\BannerImageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

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
}
