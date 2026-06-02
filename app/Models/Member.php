<?php

namespace App\Models;

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

    /** @return HasMany<MemberImage, $this> */
    public function images(): HasMany
    {
        return $this->hasMany(MemberImage::class, 'member_id');
    }

    /** @return HasMany<MemberProfile, $this> */
    public function memberProfiles(): HasMany
    {
        return $this->hasMany(MemberProfile::class, 'member_id');
    }

    /**
     * The avatar shown for this member, or null. A single primary image is kept today.
     *
     * @return HasOne<MemberImage, $this>
     */
    public function primaryImage(): HasOne
    {
        return $this->hasOne(MemberImage::class, 'member_id')->where('is_primary', true);
    }
}
