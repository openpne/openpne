<?php

namespace App\Models;

use App\Support\Visibility;
use Database\Factories\TimelinePostFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// One timeline post (successor of OpenPNE 3 activity_data). A reply is a row whose in_reply_to_id
// points at its parent; top-level posts leave it null.
#[Fillable(['member_id', 'in_reply_to_id', 'body', 'visibility'])]
class TimelinePost extends Model
{
    /** @use HasFactory<TimelinePostFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
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

    /** @return HasMany<TimelinePostImage, $this> The attached image (slot 1); empty for a reply. */
    public function images(): HasMany
    {
        return $this->hasMany(TimelinePostImage::class)->orderBy('number');
    }
}
