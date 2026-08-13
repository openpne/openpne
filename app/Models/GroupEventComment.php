<?php

namespace App\Models;

use Database\Factories\GroupEventCommentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['group_event_id', 'member_id', 'number', 'body'])]
class GroupEventComment extends Model
{
    /** @use HasFactory<GroupEventCommentFactory> */
    use HasFactory;

    /** @return BelongsTo<GroupEvent, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(GroupEvent::class, 'group_event_id');
    }

    /** @return BelongsTo<Member, $this> The author, or null once they have withdrawn. */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /** @return HasMany<GroupEventCommentImage, $this> Attached images, in slot (number) order. */
    public function images(): HasMany
    {
        return $this->hasMany(GroupEventCommentImage::class, 'post_id')->orderBy('number');
    }
}
