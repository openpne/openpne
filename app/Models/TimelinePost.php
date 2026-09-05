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

    /** @return BelongsTo<TimelinePost, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'in_reply_to_id');
    }

    /** @return HasMany<TimelinePost, $this> oldest first by id, as OpenPNE 3 reads them */
    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'in_reply_to_id')->orderBy('id');
    }

    /**
     * Newest first, so an eager load capped by `limit()` keeps the tail of the thread rather than
     * its head; the caller flips the loaded rows back to reading order.
     *
     * @return HasMany<TimelinePost, $this>
     */
    public function recentReplies(): HasMany
    {
        return $this->hasMany(self::class, 'in_reply_to_id')->orderByDesc('id');
    }

    /** @return HasMany<TimelinePostImage, $this> */
    public function images(): HasMany
    {
        return $this->hasMany(TimelinePostImage::class)->orderBy('number');
    }

    /**
     * @return HasMany<TimelinePostMention, $this> ascending by offset, the order EntityText requires
     *                                             and does not re-check
     */
    public function mentions(): HasMany
    {
        return $this->hasMany(TimelinePostMention::class)->orderBy('offset');
    }

    /**
     * @return HasMany<TimelinePostTag, $this> ascending by offset, the order EntityText requires and
     *                                         does not re-check
     */
    public function tags(): HasMany
    {
        return $this->hasMany(TimelinePostTag::class)->orderBy('offset');
    }

    /**
     * Every tag is linkable now, and this stays the one seam a scoped surface would answer at
     * (docs/internals/timeline.md, "Why `linkableTags()` still exists").
     *
     * @return Collection<int, TimelinePostTag>
     */
    public function linkableTags(): Collection
    {
        return $this->tags;
    }
}
