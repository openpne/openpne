<?php

namespace App\Models;

use Database\Factories\CommunityEventMemberFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An RSVP row (OpenPNE 3 community_event_member): a member's presence on an event's roster. */
#[Fillable(['community_event_id', 'member_id'])]
class CommunityEventMember extends Model
{
    /** @use HasFactory<CommunityEventMemberFactory> */
    use HasFactory;

    /** @return BelongsTo<CommunityEvent, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(CommunityEvent::class, 'community_event_id');
    }

    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
