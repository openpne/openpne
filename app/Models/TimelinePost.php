<?php

namespace App\Models;

use App\Models\Concerns\HasLinkCard;
use App\Support\Visibility;
use Database\Factories\TimelinePostFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// One timeline post, always SNS-wide. A reply is a row whose in_reply_to_id points at its parent;
// top-level posts leave it null.
#[Fillable(['member_id', 'in_reply_to_id', 'body', 'visibility'])]
class TimelinePost extends Model
{
    /** @use HasFactory<TimelinePostFactory> */
    use HasFactory;

    use HasLinkCard;

    protected function casts(): array
    {
        return [
            'link_card_synced_at' => 'datetime',
            'visibility' => Visibility::class,
        ];
    }

    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /** @return BelongsTo<TimelinePost, $this> The parent this post replies to, or null. */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'in_reply_to_id');
    }

    /** @return HasMany<TimelinePost, $this> Replies to this post, oldest first (OpenPNE 3 reads by id). */
    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'in_reply_to_id')->orderBy('id');
    }

    /**
     * The same replies, newest first, so an eager load capped by `limit()` keeps the tail of the
     * thread rather than its head (RecentReplies flips the loaded rows back to reading order).
     *
     * @return HasMany<TimelinePost, $this>
     */
    public function recentReplies(): HasMany
    {
        return $this->hasMany(self::class, 'in_reply_to_id')->orderByDesc('id');
    }

    /** @return HasMany<TimelinePostImage, $this> The attached image (slot 1); empty for a reply. */
    public function images(): HasMany
    {
        return $this->hasMany(TimelinePostImage::class)->orderBy('number');
    }

    /** @return HasMany<TimelinePostMention, $this> The @mentions in the body, in body order. */
    public function mentions(): HasMany
    {
        return $this->hasMany(TimelinePostMention::class)->orderBy('offset');
    }

    /** @return HasMany<TimelinePostTag, $this> The #hashtags in the body, in body order. */
    public function tags(): HasMany
    {
        return $this->hasMany(TimelinePostTag::class)->orderBy('offset');
    }

    /**
     * The tags that have somewhere to go — now all of them. It answered a real question while the
     * community timeline existed: those posts carried tags like any other, but the tag page is
     * SNS-wide and excluded them, so linking one handed the reader a page that did not contain the
     * post they clicked from. Group talk replaced that scope and parses no hashtags at all
     * (docs/internals/timeline.md), so every remaining post is SNS-wide and every tag is linkable.
     *
     * Kept as a method rather than folded into tags(): both surfaces read it, so it stays the one
     * seam a future scoped surface would answer at instead of each renderer deciding again.
     *
     * @return Collection<int, TimelinePostTag>
     */
    public function linkableTags(): Collection
    {
        return $this->tags;
    }
}
