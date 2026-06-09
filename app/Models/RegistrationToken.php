<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Support\Carbon;

/**
 * A pending email-confirmation registration. `token` holds the SHA-256 hash of the raw token that
 * the registration URL carries; the raw token only ever lives in the emailed link. Only created_at
 * is tracked (expiry is derived from it), so timestamps are off.
 *
 * @property int $id
 * @property string $email
 * @property string $token
 * @property Carbon|null $created_at
 */
class RegistrationToken extends Model
{
    use Prunable;

    public $timestamps = false;

    protected $fillable = ['email', 'token', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    /**
     * Expired tokens are dead state (the link no longer works), so prune them past the TTL.
     *
     * @return Builder<RegistrationToken>
     */
    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subMinutes((int) config('openpne.registration.token_ttl_minutes')));
    }
}
