<?php

namespace App\Models;

use Database\Factories\CommunityTopicCommentImageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// One image attached to a topic comment (successor of OpenPNE 3
// `community_topic_comment_image`). A join row only — no timestamps; the File owns the bytes.
#[Fillable(['post_id', 'file_id', 'number'])]
class CommunityTopicCommentImage extends Model
{
    /** @use HasFactory<CommunityTopicCommentImageFactory> */
    use HasFactory;

    public $timestamps = false;

    /** @return BelongsTo<File, $this> */
    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }

    /** @return BelongsTo<CommunityTopicComment, $this> */
    public function comment(): BelongsTo
    {
        return $this->belongsTo(CommunityTopicComment::class, 'post_id');
    }
}
