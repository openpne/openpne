<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A pending admin-issued two-factor reset. `token` holds the SHA-256 hash of the raw token the reset URL
 * carries (mailed to the member's registered address); the raw token only ever lives in that emailed
 * link. Only created_at is tracked (expiry is derived from it), so timestamps are off. One row per member
 * (the column is unique); it cascades away with the member. There is no cancel token: consuming a reset
 * link needs the account password (see the migration docblock).
 *
 * @property int $id
 * @property int $member_id
 * @property string $token
 * @property Carbon|null $created_at
 */
class MfaResetRequest extends Model
{
    use Prunable;

    public $timestamps = false;

    protected $fillable = ['member_id', 'token', 'created_at'];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /**
     * Expired pending resets are dead state (the link no longer works), so prune them past the TTL.
     *
     * @return Builder<MfaResetRequest>
     */
    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subMinutes((int) config('openpne.mfa_reset.token_ttl_minutes')));
    }
}
