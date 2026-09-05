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

// The login identifier is `username`: OpenPNE 3's `admin_user` table has no email column.
#[Fillable(['username', 'password'])]
#[Hidden(['password', 'password_scheme', 'remember_token'])]
class AdminUser extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery, HasName
{
    /** @use HasFactory<AdminUserFactory> */
    // The app-authentication traits hide their own secret and recovery columns, so `#[Hidden]` lists only the rest.
    use ClearsPasswordScheme, HasFactory, InteractsWithAppAuthentication, InteractsWithAppAuthenticationRecovery;

    // The table is `admin_users`, kept distinct from OpenPNE 3's `admin_user` so both coexist in a
    // same-database upgrade.

    /**
     * There is no administrator role split: any `admin_users` row has full access to the panel.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }

    /**
     * OpenPNE 3 administrators have only a username, so it doubles as the display name.
     */
    public function getFilamentName(): string
    {
        return $this->username;
    }

    /**
     * The trait's default is the email column, which administrators do not have.
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
        // Wrapping a legacy OpenPNE 3 hash is the upgrade tooling's, not this cast's.
        return [
            'password' => 'hashed',
        ];
    }
}
