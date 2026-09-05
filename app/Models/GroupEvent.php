<?php

namespace App\Models;

use App\Models\Concerns\HasLinkCard;
use App\Support\BodyFormat;
use Database\Factories\GroupEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['group_id', 'member_id', 'name', 'body', 'event_updated_at', 'open_date', 'open_date_comment', 'area', 'application_deadline', 'capacity', 'format'])]
class GroupEvent extends Model
{
    /** @use HasFactory<GroupEventFactory> */
    use HasFactory;

    use HasLinkCard;

    protected function casts(): array
    {
        return [
            'link_card_synced_at' => 'datetime',
            'event_updated_at' => 'datetime',
            'open_date' => 'datetime',
            'application_deadline' => 'datetime',
            'capacity' => 'integer',
            'format' => BodyFormat::class,
        ];
    }

    /** @return BelongsTo<Group, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /** @return BelongsTo<Member, $this> The author, or null once they have withdrawn. */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /** @return HasMany<GroupEventComment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(GroupEventComment::class);
    }

    /** @return HasMany<GroupEventImage, $this> */
    public function images(): HasMany
    {
        return $this->hasMany(GroupEventImage::class, 'post_id')->orderBy('number');
    }

    /** @return BelongsToMany<Member, $this> */
    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(Member::class, 'group_event_members')->withTimestamps();
    }

    /** @return HasMany<GroupEventMember, $this> */
    public function eventMembers(): HasMany
    {
        return $this->hasMany(GroupEventMember::class);
    }

    /** OpenPNE 3 `isClosed`. */
    public function isClosed(): bool
    {
        return now()->greaterThan($this->open_date->copy()->addDay());
    }

    /** OpenPNE 3 `isExpired`. */
    public function isExpired(): bool
    {
        return $this->application_deadline !== null
            && now()->greaterThan($this->application_deadline->copy()->addDay());
    }

    /** OpenPNE 3 `isAtCapacity`. */
    public function isFull(): bool
    {
        return $this->capacity !== null && $this->participantCount() >= $this->capacity;
    }

    public function isParticipant(Member $member): bool
    {
        return $this->participants()->whereKey($member->getKey())->exists();
    }

    public function participantCount(): int
    {
        return $this->participants()->count();
    }
}
