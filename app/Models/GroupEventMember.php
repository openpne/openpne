<?php

namespace App\Models;

use Database\Factories\GroupEventMemberFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['group_event_id', 'member_id'])]
class GroupEventMember extends Model
{
    /** @use HasFactory<GroupEventMemberFactory> */
    use HasFactory;

    /** @return BelongsTo<GroupEvent, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(GroupEvent::class, 'group_event_id');
    }

    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
