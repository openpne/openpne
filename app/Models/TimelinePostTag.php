<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// One #hashtag in a timeline post's body: the normalized tag, plus the half-open code-point range
// [offset, offset+length) of the raw text it was written as. A join row only — no timestamps; the
// post carries them.
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
