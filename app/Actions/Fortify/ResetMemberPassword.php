<?php

namespace App\Actions\Fortify;

use App\Models\Member;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetMemberPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    /**
     * Validate and reset the member's forgotten password.
     *
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function reset(Member $member, array $input): void
    {
        Validator::make($input, [
            'password' => $this->passwordRules(),
        ])->validate();

        // A reset answers a possible compromise, so every other authenticated foothold for this member
        // must drop. Rotate remember_token (invalidates "remember me" cookies on all devices) and, on
        // the database session driver, delete the member's server-side sessions outright. For other
        // drivers the auth.session middleware is the best-effort fallback (it drops a session on its
        // next protected request once the stored password hash no longer matches).
        $member->forceFill([
            'password' => Hash::make($input['password']),
            'remember_token' => Str::random(60),
        ])->save();

        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))
                ->where('user_id', $member->getAuthIdentifier())
                ->delete();
        }
    }
}
