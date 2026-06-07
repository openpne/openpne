<?php

namespace App\Models;

use Database\Factories\CommunityTopicImageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// One image attached to a topic, pointing at a stored File (successor of OpenPNE 3
// `community_topic_image`). A join row only — no timestamps; the bytes and their
// timestamps belong to the File, which cascades this row away when deleted.
#[Fillable(['post_id', 'file_id', 'number'])]
class CommunityTopicImage extends Model
{
    /** @use HasFactory<CommunityTopicImageFactory> */
    use HasFactory;

    public $timestamps = false;

    /** @return BelongsTo<File, $this> */
    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }

    /** @return BelongsTo<CommunityTopic, $this> */
    public function topic(): BelongsTo
    {
        return $this->belongsTo(CommunityTopic::class, 'post_id');
    }
}
