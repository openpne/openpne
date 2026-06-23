<?php

namespace App\Models;

use Database\Factories\DiaryImageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// One image attached to a diary, pointing at a stored File (successor of OpenPNE 3
// `diary_image`). A join row only — no timestamps; the bytes and their timestamps
// belong to the File, which cascades this row away when deleted.
#[Fillable(['diary_id', 'file_id', 'number'])]
class DiaryImage extends Model
{
    /** @use HasFactory<DiaryImageFactory> */
    use HasFactory;

    public $timestamps = false;

    /** @return BelongsTo<File, $this> */
    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }

    /** @return BelongsTo<Diary, $this> */
    public function diary(): BelongsTo
    {
        return $this->belongsTo(Diary::class);
    }
}
