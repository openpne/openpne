<?php

namespace App\Models;

use Database\Factories\DirectMessageRecipientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
     * A draft has no receipt, so this states `is_draft = false` only as a belt against a stray one.
     *
     * @param  Builder<DirectMessageRecipient>  $query
     */
    public function scopeOfDelivered(Builder $query): void
    {
        $query->whereHas('directMessage', fn (Builder $q) => $q->where('is_draft', false));
    }

    /**
     * Purged is excluded as well as trashed, so a stray purged-without-trashed row never resurfaces.
     *
     * @param  Builder<DirectMessageRecipient>  $query
     */
    public function scopeRecipientLive(Builder $query): void
    {
        $query->whereNull('recipient_deleted_at')->whereNull('recipient_purged_at');
    }

    /**
     * @param  Builder<DirectMessageRecipient>  $query
     */
    public function scopeRecipientTrashed(Builder $query): void
    {
        $query->whereNotNull('recipient_deleted_at')->whereNull('recipient_purged_at');
    }
}
