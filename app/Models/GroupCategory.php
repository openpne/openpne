<?php

namespace App\Models;

use Database\Factories\GroupCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'is_allow_member_group', 'sort_order', 'parent_id'])]
class GroupCategory extends Model
{
    /** @use HasFactory<GroupCategoryFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_allow_member_group' => 'boolean',
        ];
    }

    /**
     * Whether an ordinary member may create a group in category $id. Null = no category
     * (always allowed); a missing or admin-only category is rejected. OpenPNE 3 hid non-allowed
     * categories from members and refused them on save.
     */
    public static function memberCreatable(?int $id): bool
    {
        if ($id === null) {
            return true;
        }

        return (bool) static::query()->whereKey($id)->value('is_allow_member_group');
    }

    /** @return HasMany<Group, $this> */
    public function groups(): HasMany
    {
        return $this->hasMany(Group::class, 'group_category_id');
    }

    /** @return BelongsTo<GroupCategory, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }
}
