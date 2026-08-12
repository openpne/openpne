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

// One timeline post. A reply is a row whose in_reply_to_id points at its parent; top-level
// posts leave it null. community_id set scopes the post to that community's timeline; null is
// SNS-wide.
#[Fillable(['member_id', 'community_id', 'in_reply_to_id', 'body', 'visibility'])]
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

    /** @return BelongsTo<Community, $this> The community this post is scoped to, or null for SNS-wide. */
    public function community(): BelongsTo
    {
        return $this->belongsTo(Community::class);
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
     * The tags that have somewhere to go. A community post's tags are stored and normalized like
     * any other, but the tag page is SNS-wide and excludes community posts — linking one would
     * hand the reader a page that does not contain the post they clicked it from. Both surfaces
     * read this rather than tags(), so neither can start linking them alone.
     *
     * @return Collection<int, TimelinePostTag>
     */
    public function linkableTags(): Collection
    {
        return $this->community_id === null ? $this->tags : new Collection;
    }
}
