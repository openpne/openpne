<?php

namespace App\Models;

use Database\Factories\MemberFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
     * Friends of this member. Stored as a bidirectional mirror in the
     * `friendships` table: a friendship between A and B is two rows
     * (A→B and B→A). Both rows are written and removed atomically by
     * the Friend feature Action — relation accessors here are read-only.
     *
     * @return BelongsToMany<Member, $this>
     */
    public function friendships(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'friendships', 'member_id', 'friend_id')
            ->withPivot('created_at');
    }

    /**
     * Pending friend requests this member has sent.
     *
     * @return BelongsToMany<Member, $this>
     */
    public function friendRequestsSent(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'friend_requests', 'requester_id', 'target_id')
            ->withPivot('created_at');
    }

    /**
     * Pending friend requests this member has received.
     *
     * @return BelongsToMany<Member, $this>
     */
    public function friendRequestsReceived(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'friend_requests', 'target_id', 'requester_id')
            ->withPivot('created_at');
    }

    /**
     * Members this member has blocked.
     *
     * @return BelongsToMany<Member, $this>
     */
    public function blocksMade(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'member_blocks', 'blocker_id', 'blocked_id')
            ->withPivot('created_at');
    }

    /**
     * Members who have blocked this member.
     *
     * @return BelongsToMany<Member, $this>
     */
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
}
