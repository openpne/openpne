<?php

namespace App\Models;

use App\Features\CommunityTopic\TopicPostAuthority;
use App\Features\CommunityTopic\TopicReadAccess;
use App\Features\Group\JoinPolicy;
use Database\Factories\GroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'description', 'register_policy', 'topic_read_access', 'topic_post_authority', 'group_category_id', 'file_id', 'is_default', 'is_join_notification_enabled'])]
class Group extends Model
{
    /** @use HasFactory<GroupFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'register_policy' => JoinPolicy::class,
            'topic_read_access' => TopicReadAccess::class,
            'topic_post_authority' => TopicPostAuthority::class,
            'is_default' => 'boolean',
            'is_join_notification_enabled' => 'boolean',
        ];
    }

    /**
     * Confirmed members only. Pending applicants live in group_join_requests
     * (see applicants()), so this relation never includes them.
     *
     * @return HasMany<GroupMember, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(GroupMember::class);
    }

    /**
     * Members with a pending join request (Approval policy), via the group_join_requests
     * pivot. Distinct from members(): an applicant is not yet a member.
     *
     * @return BelongsToMany<Member, $this>
     */
    public function applicants(): BelongsToMany
    {
        return $this->belongsToMany(Member::class, 'group_join_requests', 'group_id', 'member_id')
            ->withPivot('created_at');
    }

    /**
     * The topic board. The FK is named explicitly here and on events()/timelinePosts(): those
     * tables keep the `community_id` spelling, which no longer follows from this model's name.
     *
     * @return HasMany<CommunityTopic, $this>
     */
    public function topics(): HasMany
    {
        return $this->hasMany(CommunityTopic::class, 'community_id');
    }

    /** @return HasMany<CommunityEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(CommunityEvent::class, 'community_id');
    }

    /** @return HasMany<TimelinePost, $this> Posts scoped to this group's timeline, replies included. */
    public function timelinePosts(): HasMany
    {
        return $this->hasMany(TimelinePost::class, 'community_id');
    }

    /** @return BelongsTo<GroupCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(GroupCategory::class, 'group_category_id');
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
