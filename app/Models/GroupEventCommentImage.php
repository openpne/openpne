<?php

namespace App\Models;

use Database\Factories\GroupEventCommentImageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// No timestamps; deleting the File cascades this row away.
#[Fillable(['post_id', 'file_id', 'number'])]
class GroupEventCommentImage extends Model
{
    /** @use HasFactory<GroupEventCommentImageFactory> */
    use HasFactory;

    public $timestamps = false;

    /** @return BelongsTo<File, $this> */
    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }

    /** @return BelongsTo<GroupEventComment, $this> */
    public function comment(): BelongsTo
    {
        return $this->belongsTo(GroupEventComment::class, 'post_id');
    }
}
