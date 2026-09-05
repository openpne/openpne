<?php

namespace App\Models;

use Database\Factories\GroupTopicImageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// No timestamps; deleting the File cascades this row away.
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
