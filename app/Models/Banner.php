<?php

namespace App\Models;

use Database\Factories\BannerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['name', 'is_use_html', 'html'])]
class Banner extends Model
{
    /** @use HasFactory<BannerFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['is_use_html' => 'boolean'];
    }

    /** @return BelongsToMany<BannerImage, $this> */
    public function images(): BelongsToMany
    {
        return $this->belongsToMany(BannerImage::class, 'banner_use_images')->withTimestamps();
    }

    public function randomImage(): ?BannerImage
    {
        return $this->images()->inRandomOrder()->first();
    }
}
