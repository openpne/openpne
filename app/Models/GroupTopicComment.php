<?php

namespace App\Models;

use Database\Factories\GroupTopicCommentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['group_topic_id', 'member_id', 'number', 'body'])]
class GroupTopicComment extends Model
{
    /** @use HasFactory<GroupTopicCommentFactory> */
    use HasFactory;

    /** @return BelongsTo<GroupTopic, $this> */
    public function topic(): BelongsTo
    {
        return $this->belongsTo(GroupTopic::class, 'group_topic_id');
    }

    /** @return BelongsTo<Member, $this> The author, or null once they have withdrawn. */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /** @return HasMany<GroupTopicCommentImage, $this> Attached images, in slot (number) order. */
    public function images(): HasMany
    {
        return $this->hasMany(GroupTopicCommentImage::class, 'post_id')->orderBy('number');
    }
}
