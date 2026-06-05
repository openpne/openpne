<?php

namespace App\Models;

use Database\Factories\CommunityCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'is_allow_member_community', 'sort_order', 'parent_id'])]
class CommunityCategory extends Model
{
    /** @use HasFactory<CommunityCategoryFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_allow_member_community' => 'boolean',
        ];
    }

    /** @return HasMany<Community, $this> */
    public function communities(): HasMany
    {
        return $this->hasMany(Community::class, 'community_category_id');
    }

    /** @return BelongsTo<CommunityCategory, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }
}
