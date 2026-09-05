<?php

namespace App\Models;

use Database\Factories\DiaryCommentImageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// No number column (OpenPNE 3 has none) and no timestamps; deleting the File cascades this row away.
#[Fillable(['diary_comment_id', 'file_id'])]
class DiaryCommentImage extends Model
{
    /** @use HasFactory<DiaryCommentImageFactory> */
    use HasFactory;

    public $timestamps = false;

    /** @return BelongsTo<File, $this> */
    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }

    /** @return BelongsTo<DiaryComment, $this> */
    public function comment(): BelongsTo
    {
        return $this->belongsTo(DiaryComment::class, 'diary_comment_id');
    }
}
