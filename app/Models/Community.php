<?php

namespace App\Models;

use App\Features\Community\JoinPolicy;
use App\Features\CommunityTopic\TopicPostAuthority;
use App\Features\CommunityTopic\TopicReadAccess;
use Database\Factories\CommunityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'description', 'register_policy', 'topic_read_access', 'topic_post_authority', 'community_category_id', 'file_id'])]
class Community extends Model
{
    /** @use HasFactory<CommunityFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'register_policy' => JoinPolicy::class,
            'topic_read_access' => TopicReadAccess::class,
            'topic_post_authority' => TopicPostAuthority::class,
        ];
    }

    /**
     * Confirmed members only. Pending applicants live in community_join_requests
     * (see applicants()), so this relation never includes them.
     *
     * @return HasMany<CommunityMember, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(CommunityMember::class);
    }

    /**
     * Members with a pending join request (Approval policy), via the community_join_requests
     * pivot. Distinct from members(): an applicant is not yet a member.
     *
     * @return BelongsToMany<Member, $this>
     */
    public function applicants(): BelongsToMany
    {
        return $this->belongsToMany(Member::class, 'community_join_requests', 'community_id', 'member_id')
            ->withPivot('created_at');
    }

    /** @return HasMany<CommunityTopic, $this> */
    public function topics(): HasMany
    {
        return $this->hasMany(CommunityTopic::class);
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
