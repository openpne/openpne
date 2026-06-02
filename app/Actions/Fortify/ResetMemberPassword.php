<?php

namespace App\Actions\Fortify;

use App\Models\Member;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
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

        $member->forceFill([
            'password' => Hash::make($input['password']),
        ])->save();
    }
}
