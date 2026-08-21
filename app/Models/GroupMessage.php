<?php

namespace App\Models;

use App\Models\Concerns\HasLinkCard;
use Database\Factories\GroupMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * One utterance in a group's talk. Ordering is the (created_at, id) tuple everywhere — see
 * App\Features\GroupTalk\Queries\GroupTalkMessages.
 *
 * A message is never edited, so HasLinkCard's invalidation half has no call site here: the card
 * attached to a body is attached to the only body that row will ever have.
 */
#[Fillable(['group_id', 'member_id', 'in_reply_to_id', 'body'])]
class GroupMessage extends Model
{
    /** @use HasFactory<GroupMessageFactory> */
    use HasFactory;

    use HasLinkCard;

    protected function casts(): array
    {
        return ['link_card_synced_at' => 'datetime'];
    }

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

    /**
     * @return HasMany<GroupMessageMention, $this> The @mentions in the body, ascending by offset —
     *                                             the order EntityText expects to walk them in.
     */
    public function mentions(): HasMany
    {
        return $this->hasMany(GroupMessageMention::class)->orderBy('offset');
    }

    /** @return HasMany<GroupMessageImage, $this> Attached images, in slot (number) order. */
    public function images(): HasMany
    {
        return $this->hasMany(GroupMessageImage::class)->orderBy('number');
    }

    /**
     * @return MorphMany<Reaction, $this> The emoji on this message, oldest first — the order the
     *                                    chips are drawn in, so a new emoji joins the end of the row
     *                                    rather than shuffling the ones already there.
     */
    public function reactions(): MorphMany
    {
        return $this->morphMany(Reaction::class, 'reactable')->orderBy('created_at')->orderBy('id');
    }
}
