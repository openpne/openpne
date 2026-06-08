<?php

namespace App\Models;

use Database\Factories\CommunityEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['community_id', 'member_id', 'name', 'body', 'event_updated_at', 'open_date', 'open_date_comment', 'area', 'application_deadline', 'capacity'])]
class CommunityEvent extends Model
{
    /** @use HasFactory<CommunityEventFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'event_updated_at' => 'datetime',
            'open_date' => 'datetime',
            'application_deadline' => 'datetime',
            'capacity' => 'integer',
        ];
    }

    /** @return BelongsTo<Community, $this> */
    public function community(): BelongsTo
    {
        return $this->belongsTo(Community::class);
    }

    /** @return BelongsTo<Member, $this> The author, or null once they have withdrawn. */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /** @return HasMany<CommunityEventComment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(CommunityEventComment::class);
    }

    /** @return BelongsToMany<Member, $this> Members who have RSVP'd, via community_event_members. */
    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(Member::class, 'community_event_members')->withTimestamps();
    }

    /** Past the join window: now is later than the day after open_date (OpenPNE 3 isClosed). */
    public function isClosed(): bool
    {
        return now()->greaterThan($this->open_date->copy()->addDay());
    }

    /** Past the RSVP deadline: set and now is later than the day after it (OpenPNE 3 isExpired). */
    public function isExpired(): bool
    {
        return $this->application_deadline !== null
            && now()->greaterThan($this->application_deadline->copy()->addDay());
    }

    /** At the participant cap: set and reached (OpenPNE 3 isAtCapacity). */
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
