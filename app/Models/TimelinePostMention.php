<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['timeline_post_id', 'member_id', 'offset', 'length'])]
class TimelinePostMention extends Model
{
    public $timestamps = false;

    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /** @return BelongsTo<TimelinePost, $this> */
    public function timelinePost(): BelongsTo
    {
        return $this->belongsTo(TimelinePost::class);
    }
}
