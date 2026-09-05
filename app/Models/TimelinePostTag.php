<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['timeline_post_id', 'tag', 'offset', 'length'])]
class TimelinePostTag extends Model
{
    public $timestamps = false;

    /** @return BelongsTo<TimelinePost, $this> */
    public function timelinePost(): BelongsTo
    {
        return $this->belongsTo(TimelinePost::class);
    }
}
