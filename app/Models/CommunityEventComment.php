<?php

namespace App\Models;

use Database\Factories\CommunityEventCommentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['community_event_id', 'member_id', 'number', 'body'])]
class CommunityEventComment extends Model
{
    /** @use HasFactory<CommunityEventCommentFactory> */
    use HasFactory;

    /** @return BelongsTo<CommunityEvent, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(CommunityEvent::class, 'community_event_id');
    }

    /** @return BelongsTo<Member, $this> The author, or null once they have withdrawn. */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /** @return HasMany<CommunityEventCommentImage, $this> Attached images, in slot (number) order. */
    public function images(): HasMany
    {
        return $this->hasMany(CommunityEventCommentImage::class, 'post_id')->orderBy('number');
    }
}
