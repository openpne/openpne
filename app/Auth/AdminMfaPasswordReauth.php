<?php

namespace App\Auth;

use Filament\Facades\Filament;
use Filament\Forms\Components\Field;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Throws rather than collecting an error, because Laravel still validates the later fields and the
 * disable modal's recovery-code rule consumes the code as it validates. Hash::check on the stored
 * hash is correct only because the admin panel has no remember-me: every session began with a
 * credential login that already retired any md5_bcrypt wrap.
 */
final class AdminMfaPasswordReauth
{
    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 60;

    public static function gate(Field $password): void
    {
        $admin = Filament::auth()->user();
        $attribute = $password->getStatePath();
        $value = $password->getState();
        // Keyed per admin, not per action: the wizard's per-step validation never reaches the
        // action-level rate limit, and one budget across the three modals cannot be multiplied by
        // hopping between them.
        $key = 'admin-mfa-reauth:'.($admin?->getAuthIdentifier() ?? 'unknown');

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages([
                $attribute => trans('auth.throttle', ['seconds' => RateLimiter::availableIn($key)]),
            ]);
        }

        if (! is_string($value) || blank($value)) {
            throw ValidationException::withMessages([
                $attribute => trans('validation.required', ['attribute' => self::displayName($attribute)]),
            ]);
        }

        if ($admin === null || ! Hash::check($value, $admin->getAuthPassword())) {
            RateLimiter::hit($key, self::DECAY_SECONDS);

            throw ValidationException::withMessages([
                $attribute => trans('validation.current_password'),
            ]);
        }

        RateLimiter::clear($key);
    }

    /**
     * The attribute is a full state path (e.g. mountedActions.0.data.password); the validator's own
     * attribute map is not reachable from a thrown exception, so resolve the display name from the
     * last segment the way Laravel would.
     */
    private static function displayName(string $attribute): string
    {
        $segment = Str::afterLast($attribute, '.');

        return Lang::has("validation.attributes.{$segment}")
            ? trans("validation.attributes.{$segment}")
            : str_replace('_', ' ', Str::snake($segment));
    }
}
