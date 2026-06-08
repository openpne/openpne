<?php

namespace App\Models;

use Database\Factories\CommunityEventCommentImageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// One image attached to an event comment (successor of OpenPNE 3
// `community_event_comment_image`). A join row only — no timestamps; the File owns the bytes.
#[Fillable(['post_id', 'file_id', 'number'])]
class CommunityEventCommentImage extends Model
{
    /** @use HasFactory<CommunityEventCommentImageFactory> */
    use HasFactory;

    public $timestamps = false;

    /** @return BelongsTo<File, $this> */
    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }

    /** @return BelongsTo<CommunityEventComment, $this> */
    public function comment(): BelongsTo
    {
        return $this->belongsTo(CommunityEventComment::class, 'post_id');
    }
}
