<?php

namespace App\Models;

use Database\Factories\DirectMessageFileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// No timestamps; deleting the File cascades this row away.
#[Fillable(['direct_message_id', 'file_id', 'number'])]
class DirectMessageFile extends Model
{
    /** @use HasFactory<DirectMessageFileFactory> */
    use HasFactory;

    public $timestamps = false;

    /** @return BelongsTo<File, $this> */
    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }

    /** @return BelongsTo<DirectMessage, $this> */
    public function directMessage(): BelongsTo
    {
        return $this->belongsTo(DirectMessage::class);
    }
}
