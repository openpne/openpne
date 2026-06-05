<?php

namespace App\Models;

use Database\Factories\MemberImageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Links a member to a stored File as a profile image (successor of OpenPNE 3
// `member_image`). The bytes belong to the File; deleting the File cascades the row.
#[Fillable(['member_id', 'file_id'])]
class MemberImage extends Model
{
    /** @use HasFactory<MemberImageFactory> */
    use HasFactory;

    /** @return BelongsTo<File, $this> */
    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }

    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
