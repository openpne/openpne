<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One member's opt-in/out for one notification kind on one channel. The `kind` column holds the
 * App\Notifications\Settings\NotificationKind case value, `channel` a NotificationChannel value.
 * An absent row means "enabled" — the typed read/write goes through
 * Member::wantsNotification()/setNotificationSetting(), not this model directly.
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
