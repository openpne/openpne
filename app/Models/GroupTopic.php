<?php

namespace App\Models;

use App\Models\Concerns\HasLinkCard;
use App\Support\BodyFormat;
use Database\Factories\GroupTopicFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['group_id', 'member_id', 'name', 'body', 'topic_updated_at', 'format'])]
class GroupTopic extends Model
{
    /** @use HasFactory<GroupTopicFactory> */
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

    /** @return BelongsTo<Group, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /** @return BelongsTo<Member, $this> The author, or null once they have withdrawn. */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /** @return HasMany<GroupTopicComment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(GroupTopicComment::class);
    }

    /** @return HasMany<GroupTopicImage, $this> */
    public function images(): HasMany
    {
        return $this->hasMany(GroupTopicImage::class, 'post_id')->orderBy('number');
    }
}
