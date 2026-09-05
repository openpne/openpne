<?php

namespace App\Models;

use Database\Factories\GroupEventImageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// No timestamps; deleting the File cascades this row away.
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
