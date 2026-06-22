<?php

namespace App\Models;

use Database\Factories\MessageFileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// One image attached to a message, pointing at a stored File (successor of OpenPNE 3
// `message_file`). A join row only — no timestamps; the bytes and their timestamps belong to the
// File, which cascades this row away when deleted.
#[Fillable(['message_id', 'file_id', 'number'])]
class MessageFile extends Model
{
    /** @use HasFactory<MessageFileFactory> */
    use HasFactory;

    public $timestamps = false;

    /** @return BelongsTo<File, $this> */
    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }

    /** @return BelongsTo<Message, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
