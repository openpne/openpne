<?php

namespace App\Models;

use Database\Factories\DiaryCommentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['diary_id', 'member_id', 'number', 'body'])]
class DiaryComment extends Model
{
    /** @use HasFactory<DiaryCommentFactory> */
    use HasFactory;

    /** @return BelongsTo<Diary, $this> */
    public function diary(): BelongsTo
    {
        return $this->belongsTo(Diary::class);
    }

    /** @return BelongsTo<Member, $this> The author, or null once they have withdrawn. */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /** @return HasMany<DiaryCommentImage, $this> Attached images, insertion-ordered (no slot column). */
    public function images(): HasMany
    {
        return $this->hasMany(DiaryCommentImage::class)->orderBy('id');
    }

    /**
     * OpenPNE 3 lets the comment author or the diary author delete a comment. A withdrawn
     * author (member_id null) can no longer act, so only the diary author can then remove it.
     */
    public function isDeletableBy(Member $member): bool
    {
        return ($this->member_id !== null && $member->is($this->member))
            || $member->is($this->diary->member);
    }
}
