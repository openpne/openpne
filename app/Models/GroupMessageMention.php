<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// One @mention in a talk message's body: the half-open code-point range [offset, offset+length)
// that names a member. A join row only — no timestamps; the message carries them.
#[Fillable(['group_message_id', 'member_id', 'offset', 'length'])]
class GroupMessageMention extends Model
{
    public $timestamps = false;

    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /** @return BelongsTo<GroupMessage, $this> */
    public function groupMessage(): BelongsTo
    {
        return $this->belongsTo(GroupMessage::class);
    }
}
