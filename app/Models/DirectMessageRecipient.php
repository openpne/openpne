<?php

namespace App\Models;

use Database\Factories\DirectMessageRecipientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recipient's receipt of a message, carrying that side's read state and trash state.
 * read_at null = unread; the *_at columns mirror the sender-side
 * trash columns on DirectMessage.
 */
#[Fillable(['direct_message_id', 'recipient_id', 'read_at'])]
class DirectMessageRecipient extends Model
{
    /** @use HasFactory<DirectMessageRecipientFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
            'recipient_deleted_at' => 'datetime',
            'recipient_purged_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<DirectMessage, $this> */
    public function directMessage(): BelongsTo
    {
        return $this->belongsTo(DirectMessage::class);
    }

    /** @return BelongsTo<Member, $this> */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /**
     * Receipts of delivered (non-draft) messages. A receipt is created only when a message is sent
     * (see SendDirectMessage / UpdateDraft), so this normally holds for every receipt; it stays as the
     * single guard against a stray draft receipt ever surfacing to its recipient.
     *
     * @param  Builder<DirectMessageRecipient>  $query
     */
    public function scopeOfDelivered(Builder $query): void
    {
        $query->whereHas('directMessage', fn (Builder $q) => $q->where('is_draft', false));
    }

    /**
     * A live receipt: in an active box, neither trashed nor purged. (Active boxes exclude purged too,
     * so a stray purged-without-trashed row never resurfaces.)
     *
     * @param  Builder<DirectMessageRecipient>  $query
     */
    public function scopeRecipientLive(Builder $query): void
    {
        $query->whereNull('recipient_deleted_at')->whereNull('recipient_purged_at');
    }

    /**
     * A receipt in the trash: moved to trash, not yet purged.
     *
     * @param  Builder<DirectMessageRecipient>  $query
     */
    public function scopeRecipientTrashed(Builder $query): void
    {
        $query->whereNotNull('recipient_deleted_at')->whereNull('recipient_purged_at');
    }
}
