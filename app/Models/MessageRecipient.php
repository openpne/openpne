<?php

namespace App\Models;

use Database\Factories\MessageRecipientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * Receipts of delivered (non-draft) messages. A draft carries a receipt too, but it is never the
     * recipient's to see or act on in any box (only the sender works a draft), so every recipient-side
     * query must scope through this — otherwise a draft's recipient could reach the unsent body.
     *
     * @param  Builder<MessageRecipient>  $query
     */
    public function scopeOfDelivered(Builder $query): void
    {
        $query->whereHas('message', fn (Builder $q) => $q->where('is_draft', false));
    }
}
