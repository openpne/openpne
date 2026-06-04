<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One member's stored value for one App\Support\PreferenceKey. The `key` column holds the
 * PreferenceKey case value; `value` is that key's codec output. The typed read/write goes
 * through Member::preference()/setPreference()/resetPreference(), not this model directly.
 */
#[Fillable(['member_id', 'key', 'value'])]
class MemberPreference extends Model
{
    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }
}
