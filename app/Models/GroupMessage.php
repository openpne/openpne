<?php

namespace App\Models;

use Database\Factories\GroupMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One utterance in a group's talk. Ordering is the (created_at, id) tuple everywhere — see
 * App\Features\GroupTalk\Queries\GroupTalkMessages.
 */
#[Fillable(['group_id', 'member_id', 'in_reply_to_id', 'body'])]
class GroupMessage extends Model
{
    /** @use HasFactory<GroupMessageFactory> */
    use HasFactory;

    /** @return BelongsTo<Group, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /** @return BelongsTo<Member, $this> The author, or null once they have withdrawn. */
    public function author(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }
}
