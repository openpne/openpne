<?php

namespace App\Models;

use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A private message authored by its sender (successor of OpenPNE 3 SendMessageData). Per-recipient
 * delivery and read/trash state live on message_recipients; the sender's own trash state is on this
 * row. is_draft true = authored but not delivered. A message_recipients row means "delivered", so a
 * draft's pending recipient lives here in draft_recipient_id and is materialized into a receipt (and
 * cleared) when the draft is sent.
 */
#[Fillable(['sender_id', 'draft_recipient_id', 'subject', 'body', 'parent_id', 'thread_id', 'is_draft'])]
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

    /** The pending recipient while this is a draft (null once sent). @return BelongsTo<Member, $this> */
    public function draftRecipient(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'draft_recipient_id');
    }

    /** @return HasMany<MessageRecipient, $this> */
    public function recipients(): HasMany
    {
        return $this->hasMany(MessageRecipient::class);
    }

    /** @return HasMany<MessageFile, $this> Attached images, in slot (number) order. */
    public function files(): HasMany
    {
        return $this->hasMany(MessageFile::class)->orderBy('number');
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

    /**
     * The sender's live copy: in an active box, neither trashed nor purged. (Active boxes exclude
     * purged too, so a stray purged-without-trashed row never resurfaces.)
     *
     * @param  Builder<Message>  $query
     */
    public function scopeSenderLive(Builder $query): void
    {
        $query->whereNull('sender_deleted_at')->whereNull('sender_purged_at');
    }

    /**
     * The sender's copy in the trash: moved to trash, not yet purged.
     *
     * @param  Builder<Message>  $query
     */
    public function scopeSenderTrashed(Builder $query): void
    {
        $query->whereNotNull('sender_deleted_at')->whereNull('sender_purged_at');
    }
}
