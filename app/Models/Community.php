<?php

namespace App\Models;

use App\Features\Community\JoinPolicy;
use Database\Factories\CommunityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'description', 'register_policy', 'community_category_id', 'file_id'])]
class Community extends Model
{
    /** @use HasFactory<CommunityFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'register_policy' => JoinPolicy::class,
        ];
    }

    /** @return HasMany<CommunityMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(CommunityMember::class);
    }

    /** @return BelongsTo<CommunityCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(CommunityCategory::class, 'community_category_id');
    }

    /** @return BelongsTo<Member, $this> */
    public function pendingAdmin(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'pending_admin_member_id');
    }

    /** @return BelongsTo<File, $this> */
    public function image(): BelongsTo
    {
        return $this->belongsTo(File::class, 'file_id');
    }
}
