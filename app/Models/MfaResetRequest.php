<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * `token` holds a SHA-256 hash; the raw token exists only in the emailed link. There is no cancel
 * token because consuming a reset link also demands the account password.
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
     * @return Builder<MfaResetRequest>
     */
    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subMinutes((int) config('openpne.mfa_reset.token_ttl_minutes')));
    }
}
