<?php

namespace App\Models;

use Database\Factories\CommunityTopicCommentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['community_topic_id', 'member_id', 'number', 'body'])]
class CommunityTopicComment extends Model
{
    /** @use HasFactory<CommunityTopicCommentFactory> */
    use HasFactory;

    /** @return BelongsTo<CommunityTopic, $this> */
    public function topic(): BelongsTo
    {
        return $this->belongsTo(CommunityTopic::class, 'community_topic_id');
    }

    /** @return BelongsTo<Member, $this> The author, or null once they have withdrawn. */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
