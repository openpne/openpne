<?php

namespace App\Models;

use Database\Factories\GroupEventImageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// One image attached to an event, pointing at a stored File. A join row only — no timestamps;
// the bytes and their timestamps belong to the File, which cascades this row away when deleted.
#[Fillable(['post_id', 'file_id', 'number'])]
class GroupEventImage extends Model
{
    /** @use HasFactory<GroupEventImageFactory> */
    use HasFactory;

    public $timestamps = false;

    /** @return BelongsTo<File, $this> */
    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }

    /** @return BelongsTo<GroupEvent, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(GroupEvent::class, 'post_id');
    }
}
