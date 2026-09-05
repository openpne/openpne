<?php

namespace App\Models;

use App\Features\Auth\RegistrationTokenSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * `token` holds a SHA-256 hash; the raw token exists only in the emailed link.
 *
 * @property int $id
 * @property string $email
 * @property string $token
 * @property RegistrationTokenSource $source
 * @property int|null $inviter_id
 * @property Carbon|null $created_at
 */
class RegistrationToken extends Model
{
    use Prunable;

    public $timestamps = false;

    protected $fillable = ['email', 'token', 'source', 'inviter_id', 'created_at'];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'source' => RegistrationTokenSource::class,
        ];
    }

    /** The member who issued a member invite, or null for self/admin issuance (or a deleted inviter). */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'inviter_id');
    }

    /**
     * @return Builder<RegistrationToken>
     */
    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subMinutes((int) config('openpne.registration.token_ttl_minutes')));
    }
}
