<?php

namespace App\Models;

use App\Models\Concerns\ClearsPasswordScheme;
use Database\Factories\AdminUserFactory;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthentication;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthenticationRecovery;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
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
#[Hidden(['password', 'password_scheme', 'remember_token'])]
class AdminUser extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery, HasName
{
    /** @use HasFactory<AdminUserFactory> */
    // The App-authentication traits add the encrypted secret/recovery casts and hide
    // the columns via their initializers; getAppAuthenticationHolderName is overridden
    // below because admins have no email.
    use ClearsPasswordScheme, HasFactory, InteractsWithAppAuthentication, InteractsWithAppAuthenticationRecovery;

    // Table is the inferred `admin_users` (plural). OpenPNE 3's own `admin_user`
    // is the upgrade source, kept distinct so both coexist in a same-database upgrade.

    /**
     * Every administrator has full access to the operator panel: there is no
     * administrator role split, so panel access is governed only by whether
     * an `admin_users` row exists.
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
     * The account label an authenticator app shows next to the TOTP code. The
     * trait's default is the email column, which administrators do not have.
     */
    public function getAppAuthenticationHolderName(): string
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
