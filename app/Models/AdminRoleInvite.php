<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Keyed by address rather than by registration link, so a newer link for the address keeps it: a
 * registration of the address while it is live grants the administrator role, whichever link was
 * used (docs/internals/administrators.md, "Inviting an administrator").
 *
 * @property string $email
 * @property int $expires_at Unix seconds
 * @property Carbon|null $created_at
 */
class AdminRoleInvite extends Model
{
    use MassPrunable;

    /** Kept this long past expiry, so `openpne:admin:list` still shows that the invite lapsed. */
    public const KEPT_EXPIRED_DAYS = 7;

    protected $primaryKey = 'email';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['email', 'expires_at', 'created_at'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function isLive(): bool
    {
        return $this->expires_at > now()->getTimestamp();
    }

    /** @param  string  $email  already trimmed and lowercased */
    public static function liveFor(string $email): bool
    {
        return static::whereKey($email)->where('expires_at', '>', now()->getTimestamp())->exists();
    }

    /** Whether the address a registration link was issued to has a live invite, whether or not the link itself still works. */
    public static function liveForLink(string $rawToken): bool
    {
        $email = RegistrationToken::where('token', hash('sha256', $rawToken))->value('email');

        return is_string($email) && static::liveFor($email);
    }

    /**
     * @return Builder<AdminRoleInvite>
     */
    public function prunable(): Builder
    {
        return static::where('expires_at', '<', now()->subDays(self::KEPT_EXPIRED_DAYS)->getTimestamp());
    }
}
