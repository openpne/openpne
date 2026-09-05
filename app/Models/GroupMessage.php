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
 * A message is never edited, so HasLinkCard's invalidation half has no call site here.
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
     * @return HasMany<GroupMessageMention, $this> ascending by offset, the order EntityText requires
     *                                             and does not re-check
     */
    public function mentions(): HasMany
    {
        return $this->hasMany(GroupMessageMention::class)->orderBy('offset');
    }

    /** @return HasMany<GroupMessageImage, $this> */
    public function images(): HasMany
    {
        return $this->hasMany(GroupMessageImage::class)->orderBy('number');
    }

    /**
     * @return MorphMany<Reaction, $this> oldest first: the reactor list is capped, so this order
     *                                    decides which reactors it shows
     */
    public function reactions(): MorphMany
    {
        return $this->morphMany(Reaction::class, 'reactable')->orderBy('created_at')->orderBy('id');
    }
}
