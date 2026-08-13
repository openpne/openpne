<?php

namespace App\Models;

use Database\Factories\GroupMessageImageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// An image attached to a talk message, pointing at a stored File. A join row only — no timestamps;
// the bytes and their timestamps belong to the File, which cascades this row away when deleted.
#[Fillable(['group_message_id', 'file_id', 'number'])]
class GroupMessageImage extends Model
{
    /** @use HasFactory<GroupMessageImageFactory> */
    use HasFactory;

    public $timestamps = false;

    /** @return BelongsTo<File, $this> */
    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }

    /** @return BelongsTo<GroupMessage, $this> */
    public function groupMessage(): BelongsTo
    {
        return $this->belongsTo(GroupMessage::class);
    }
}
