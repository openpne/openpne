<?php

namespace App\Models;

use Database\Factories\GroupTopicCommentImageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// No timestamps; deleting the File cascades this row away.
#[Fillable(['post_id', 'file_id', 'number'])]
class GroupTopicCommentImage extends Model
{
    /** @use HasFactory<GroupTopicCommentImageFactory> */
    use HasFactory;

    public $timestamps = false;

    /** @return BelongsTo<File, $this> */
    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }

    /** @return BelongsTo<GroupTopicComment, $this> */
    public function comment(): BelongsTo
    {
        return $this->belongsTo(GroupTopicComment::class, 'post_id');
    }
}
