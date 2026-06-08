<?php

namespace App\Actions\Fortify;

use App\Models\Member;
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

        // Rotate the remember-me token too: a reset is the response to a possible compromise, so any
        // "remember me" cookie still held on another device must stop authenticating. Active server
        // sessions are dropped by the auth.session middleware on their next request (the stored
        // password hash no longer matches).
        $member->forceFill([
            'password' => Hash::make($input['password']),
            'remember_token' => Str::random(60),
        ])->save();
    }
}
