<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * `token` holds a SHA-256 hash; the raw token exists only in the printed link. Written only through
 * App\Auth\AdminPasswords, which orders its locks (docs/internals/security.md, "Administrator password links").
 *
 * @property int $admin_user_id
 * @property string $token
 * @property Carbon $expires_at
 * @property Carbon|null $created_at
 */
class AdminPasswordSetupToken extends Model
{
    use MassPrunable;

    protected $primaryKey = 'admin_user_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['admin_user_id', 'token', 'expires_at', 'created_at'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return Builder<AdminPasswordSetupToken>
     */
    public function prunable(): Builder
    {
        return static::where('expires_at', '<', now());
    }
}
