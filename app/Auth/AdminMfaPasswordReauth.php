<?php

namespace App\Auth;

use Closure;
use Filament\Facades\Filament;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * Password re-authentication ("sudo mode") for the three admin MFA management actions
 * (set-up / disable / regenerate), shared across all three modals.
 *
 * Implicit and fail-fast: it runs even when the field is blank ($implicit) and THROWS rather than
 * collecting an error, aborting the whole validator before any later field runs. This is
 * load-bearing on the disable modal: its recovery-code field verifies DURING validation and
 * *consumes* the code from the database (AdminAppAuthentication then logs mfa.recovery_code_used).
 * Laravel does not short-circuit validation across fields, so a merely-collected error would still
 * let a valid recovery code be spent under a wrong or absent password. Throwing first prevents that,
 * which is why the password field is placed first in each schema.
 *
 * Hash::check bypasses LegacyEloquentUserProvider::validateCredentials, which is safe here: the admin
 * panel has no remember-me, so every session starts from a full credential login, and that login has
 * already retired any md5_bcrypt wrap to a plain bcrypt (pinned by
 * AdminMfaTest::test_first_login_of_a_wrapped_admin_with_mfa_retires_the_password_scheme).
 *
 * The throttle is rule-internal and keyed per admin (not per IP): Wizard::nextStep() validates a
 * step's child schema directly and never reaches the action-level ->rateLimit(5) (which only runs in
 * callMountedAction), and with an empty code the vendor's per-modal code limiter is not hit either, so
 * step-level password validation would otherwise be an unthrottled guessing oracle. One shared budget
 * across the three modals stops it being multiplied by hopping between them.
 */
class AdminMfaPasswordReauth implements ValidationRule
{
    public bool $implicit = true;

    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 60;

    public function validate(string $attribute, #[SensitiveParameter] mixed $value, Closure $fail): void
    {
        $admin = Filament::auth()->user();
        $key = 'admin-mfa-reauth:'.($admin?->getAuthIdentifier() ?? 'unknown');

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages([
                $attribute => trans('auth.throttle', ['seconds' => RateLimiter::availableIn($key)]),
            ]);
        }

        if (! is_string($value) || blank($value)) {
            throw ValidationException::withMessages([
                $attribute => trans('validation.required', ['attribute' => $this->displayName($attribute)]),
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
     * The field key arrives as a full state path (e.g. mountedActions.0.data.current_password); the
     * validator's own attribute map is not reachable from a thrown exception, so resolve the display
     * name from the last segment the way Laravel would.
     */
    private function displayName(string $attribute): string
    {
        $segment = Str::afterLast($attribute, '.');

        return Lang::has("validation.attributes.{$segment}")
            ? trans("validation.attributes.{$segment}")
            : str_replace('_', ' ', Str::snake($segment));
    }
}
