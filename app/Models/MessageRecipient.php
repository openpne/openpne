<?php

namespace App\Models;

use Database\Factories\MessageRecipientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recipient's receipt of a message (successor of OpenPNE 3 MessageSendList), carrying that
 * side's read state and trash state. read_at null = unread; the *_at columns mirror the sender-side
 * trash columns on Message.
 */
#[Fillable(['message_id', 'recipient_id', 'read_at'])]
class MessageRecipient extends Model
{
    /** @use HasFactory<MessageRecipientFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
            'recipient_deleted_at' => 'datetime',
            'recipient_purged_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Message, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /** @return BelongsTo<Member, $this> */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
