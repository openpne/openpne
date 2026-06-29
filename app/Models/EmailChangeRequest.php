<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A pending email-address change. `token` holds the SHA-256 hash of the raw token the confirmation
 * URL carries; the raw token only ever lives in the emailed link. Only created_at is tracked (expiry
 * is derived from it), so timestamps are off. One row per member (the column is unique); it cascades
 * away with the member. Mirrors RegistrationToken.
 *
 * @property int $id
 * @property int $member_id
 * @property string $new_email
 * @property string $token
 * @property Carbon|null $created_at
 */
class EmailChangeRequest extends Model
{
    use Prunable;

    public $timestamps = false;

    protected $fillable = ['member_id', 'new_email', 'token', 'created_at'];

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
     * Expired pending changes are dead state (the link no longer works), so prune them past the TTL.
     *
     * @return Builder<EmailChangeRequest>
     */
    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subMinutes((int) config('openpne.email_change.token_ttl_minutes')));
    }
}
