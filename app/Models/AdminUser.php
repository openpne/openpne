<?php

namespace App\Models;

use Database\Factories\AdminUserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

// An administrator operates a single SNS through the Filament `/admin` panel
// and authenticates against the `admin` guard, separate from the member-facing
// guard. Login identifier is `username` (not email) because OpenPNE 3's
// `admin_user` table has no email column — accounts carried over from
// OpenPNE 3 migrate as-is.
#[Fillable(['username', 'password'])]
#[Hidden(['password', 'remember_token'])]
class AdminUser extends Authenticatable implements FilamentUser, HasName
{
    /** @use HasFactory<AdminUserFactory> */
    use HasFactory;

    // Singular `admin_user`, matching the singular `member` table rather than
    // Eloquent's pluralized default.
    protected $table = 'admin_user';

    /**
     * Every administrator has full access to the operator panel: the MVP has
     * no administrator role split, so panel access is governed only by whether
     * an `admin_user` row exists. Role-based restriction lands later.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }

    /**
     * Display name in the panel (e.g. the user menu). OpenPNE 3 administrators
     * have only a username, so it doubles as the display name.
     */
    public function getFilamentName(): string
    {
        return $this->username;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        // Single bcrypt column. The transparent legacy-hash upgrade for accounts
        // carried over from OpenPNE 3 belongs to the upgrade tooling, not here.
        return [
            'password' => 'hashed',
        ];
    }
}
