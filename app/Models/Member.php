<?php

namespace App\Models;

use App\Support\PreferenceKey;
use App\Support\Visibility;
use Database\Factories\MemberFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class Member extends Authenticatable
{
    /** @use HasFactory<MemberFactory> */
    use HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'profile_visibility' => Visibility::class,
        ];
    }

    /**
     * `friendships` is a bidirectional mirror: a friendship between A and B
     * is two rows (A→B and B→A). This accessor only sees rows anchored on
     * `$this`, so it relies on the mirror being maintained.
     *
     * @return BelongsToMany<Member, $this>
     */
    public function friendships(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'friendships', 'member_id', 'friend_id')
            ->withPivot('created_at');
    }

    /** @return BelongsToMany<Member, $this> */
    public function friendRequestsSent(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'friend_requests', 'requester_id', 'target_id')
            ->withPivot('created_at');
    }

    /** @return BelongsToMany<Member, $this> */
    public function friendRequestsReceived(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'friend_requests', 'target_id', 'requester_id')
            ->withPivot('created_at');
    }

    /** @return BelongsToMany<Member, $this> */
    public function blocksMade(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'member_blocks', 'blocker_id', 'blocked_id')
            ->withPivot('created_at');
    }

    /** @return BelongsToMany<Member, $this> */
    public function blocksReceived(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'member_blocks', 'blocked_id', 'blocker_id')
            ->withPivot('created_at');
    }

    public function isFriendsWith(self $other): bool
    {
        return $this->friendships()->whereKey($other->getKey())->exists();
    }

    public function hasPendingRequestFrom(self $other): bool
    {
        return $this->friendRequestsReceived()->whereKey($other->getKey())->exists();
    }

    /** @return HasMany<Diary, $this> */
    public function diaries(): HasMany
    {
        return $this->hasMany(Diary::class, 'member_id');
    }

    /** @return HasMany<MemberProfile, $this> */
    public function memberProfiles(): HasMany
    {
        return $this->hasMany(MemberProfile::class, 'member_id');
    }

    /** @return HasMany<CommunityMember, $this> */
    public function communityMemberships(): HasMany
    {
        return $this->hasMany(CommunityMember::class, 'member_id');
    }

    /**
     * Communities this member has a pending join request to, via the community_join_requests
     * pivot (confirmed memberships are communityMemberships()).
     *
     * @return BelongsToMany<Community, $this>
     */
    public function communityJoinRequests(): BelongsToMany
    {
        return $this->belongsToMany(Community::class, 'community_join_requests', 'member_id', 'community_id')
            ->withPivot('created_at');
    }

    /** @return HasMany<MemberPreference, $this> */
    public function preferences(): HasMany
    {
        return $this->hasMany(MemberPreference::class, 'member_id');
    }

    /**
     * The member's typed value for $key, or the key default when unset. Reads the loaded
     * `preferences` relation (lazy-loaded once and cached), so repeated calls share one query.
     */
    public function preference(PreferenceKey $key): Visibility
    {
        return $key->decode($this->preferences->firstWhere('key', $key->value)?->value);
    }

    /**
     * Store an explicit value for $key (even one equal to the default). Returning to
     * default-following is resetPreference(), not setPreference($default).
     */
    public function setPreference(PreferenceKey $key, Visibility $value): void
    {
        $this->preferences()->updateOrCreate(
            ['key' => $key->value],
            ['value' => $key->encode($value)],
        );
        $this->unsetRelation('preferences');
    }

    /** Drop any stored value for $key so reads fall back to the key default. */
    public function resetPreference(PreferenceKey $key): void
    {
        $this->preferences()->where('key', $key->value)->delete();
        $this->unsetRelation('preferences');
    }

    /**
     * The member's profile image (avatar), or null. One per member, enforced by the
     * member_images.member_id unique key.
     *
     * @return HasOne<MemberImage, $this>
     */
    public function avatar(): HasOne
    {
        return $this->hasOne(MemberImage::class, 'member_id');
    }
}
