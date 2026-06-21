<?php

namespace App\Models;

use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A private message authored by its sender (successor of OpenPNE 3 SendMessageData). Per-recipient
 * delivery and read/trash state live on message_recipients; the sender's own trash state is on this
 * row. is_draft true = authored but not delivered.
 */
#[Fillable(['sender_id', 'subject', 'body', 'parent_id', 'thread_id', 'is_draft'])]
class Message extends Model
{
    /** @use HasFactory<MessageFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_draft' => 'boolean',
            'sender_deleted_at' => 'datetime',
            'sender_purged_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Member, $this> */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /** @return HasMany<MessageRecipient, $this> */
    public function recipients(): HasMany
    {
        return $this->hasMany(MessageRecipient::class);
    }

    /** Direct reply parent (OpenPNE 3 return_message_id), or null. @return BelongsTo<Message, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** Thread root (OpenPNE 3 thread_message_id), or null. @return BelongsTo<Message, $this> */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(self::class, 'thread_id');
    }
}
