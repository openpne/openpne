<?php

namespace App\Models;

use Database\Factories\DirectMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A `direct_message_recipients` row means "delivered", so a draft's pending recipient lives here in
 * `draft_recipient_id` until sending materializes it into a receipt and clears it.
 */
#[Fillable(['sender_id', 'draft_recipient_id', 'subject', 'body', 'parent_id', 'thread_id', 'is_draft'])]
class DirectMessage extends Model
{
    /** @use HasFactory<DirectMessageFactory> */
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

    /** @return BelongsTo<Member, $this> */
    public function draftRecipient(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'draft_recipient_id');
    }

    /** @return HasMany<DirectMessageRecipient, $this> */
    public function recipients(): HasMany
    {
        return $this->hasMany(DirectMessageRecipient::class);
    }

    /** @return HasMany<DirectMessageFile, $this> */
    public function files(): HasMany
    {
        return $this->hasMany(DirectMessageFile::class)->orderBy('number');
    }

    /** @return BelongsTo<DirectMessage, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return BelongsTo<DirectMessage, $this> */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(self::class, 'thread_id');
    }

    /**
     * Purged is excluded as well as trashed, so a stray purged-without-trashed row never resurfaces.
     *
     * @param  Builder<DirectMessage>  $query
     */
    public function scopeSenderLive(Builder $query): void
    {
        $query->whereNull('sender_deleted_at')->whereNull('sender_purged_at');
    }

    /**
     * @param  Builder<DirectMessage>  $query
     */
    public function scopeSenderTrashed(Builder $query): void
    {
        $query->whereNotNull('sender_deleted_at')->whereNull('sender_purged_at');
    }
}
