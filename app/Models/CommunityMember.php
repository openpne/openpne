<?php

namespace App\Models;

use App\Features\Community\CommunityRole;
use Database\Factories\CommunityMemberFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['community_id', 'member_id', 'role'])]
class CommunityMember extends Model
{
    /** @use HasFactory<CommunityMemberFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'role' => CommunityRole::class,
        ];
    }

    /** @return BelongsTo<Community, $this> */
    public function community(): BelongsTo
    {
        return $this->belongsTo(Community::class);
    }

    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
