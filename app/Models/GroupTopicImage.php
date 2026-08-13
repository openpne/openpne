<?php

namespace App\Models;

use Database\Factories\GroupTopicImageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// One image attached to a topic, pointing at a stored File. A join row only — no timestamps;
// the bytes and their timestamps belong to the File, which cascades this row away when deleted.
#[Fillable(['post_id', 'file_id', 'number'])]
class GroupTopicImage extends Model
{
    /** @use HasFactory<GroupTopicImageFactory> */
    use HasFactory;

    public $timestamps = false;

    /** @return BelongsTo<File, $this> */
    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }

    /** @return BelongsTo<GroupTopic, $this> */
    public function topic(): BelongsTo
    {
        return $this->belongsTo(GroupTopic::class, 'post_id');
    }
}
