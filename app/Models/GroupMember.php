<?php

namespace App\Models;

use App\Features\Group\GroupRole;
use Database\Factories\GroupMemberFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['group_id', 'member_id', 'role'])]
class GroupMember extends Model
{
    /** @use HasFactory<GroupMemberFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'role' => GroupRole::class,
        ];
    }

    /** @return BelongsTo<Group, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
