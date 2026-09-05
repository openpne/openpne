<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * See docs/internals/notifications.md, "The per-member catalog".
 */
#[Fillable(['member_id', 'kind', 'channel', 'is_enabled'])]
class MemberNotificationSetting extends Model
{
    protected function casts(): array
    {
        return ['is_enabled' => 'boolean'];
    }

    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }
}
