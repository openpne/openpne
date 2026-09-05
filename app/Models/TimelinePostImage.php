<?php

namespace App\Models;

use Database\Factories\TimelinePostImageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// No timestamps; deleting the File cascades this row away.
#[Fillable(['timeline_post_id', 'file_id', 'number'])]
class TimelinePostImage extends Model
{
    /** @use HasFactory<TimelinePostImageFactory> */
    use HasFactory;

    public $timestamps = false;

    /** @return BelongsTo<File, $this> */
    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }

    /** @return BelongsTo<TimelinePost, $this> */
    public function timelinePost(): BelongsTo
    {
        return $this->belongsTo(TimelinePost::class);
    }
}
