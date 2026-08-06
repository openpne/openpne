<?php

namespace App\Models;

use App\Models\Concerns\HasLinkCard;
use App\Support\BodyFormat;
use Database\Factories\CommunityTopicFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['community_id', 'member_id', 'name', 'body', 'topic_updated_at', 'format'])]
class CommunityTopic extends Model
{
    /** @use HasFactory<CommunityTopicFactory> */
    use HasFactory;

    use HasLinkCard;

    protected function casts(): array
    {
        return [
            'link_card_synced_at' => 'datetime',
            'topic_updated_at' => 'datetime',
            'format' => BodyFormat::class,
        ];
    }

    /** @return BelongsTo<Community, $this> */
    public function community(): BelongsTo
    {
        return $this->belongsTo(Community::class);
    }

    /** @return BelongsTo<Member, $this> The author, or null once they have withdrawn. */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /** @return HasMany<CommunityTopicComment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(CommunityTopicComment::class);
    }

    /** @return HasMany<CommunityTopicImage, $this> Attached images, in slot (number) order. */
    public function images(): HasMany
    {
        return $this->hasMany(CommunityTopicImage::class, 'post_id')->orderBy('number');
    }
}
