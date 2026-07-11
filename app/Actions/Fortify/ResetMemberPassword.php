<?php

namespace App\Actions\Fortify;

use App\Auth\SessionRevocation;
use App\Models\EmailChangeRequest;
use App\Models\Member;
use App\Notifications\Member\PasswordChangedNotification;
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
        // Supply the member's name (email is already in $input) so the context-word rule can reject a
        // password that embeds either.
        Validator::make(['name' => $member->name] + $input, [
            'password' => $this->passwordRules(),
        ])->validate();

        // A reset answers a possible compromise, so every other authenticated foothold for this member
        // must drop. remember_token rotates in the same save as the password; the session purge is
        // SessionRevocation's (for non-database drivers the auth.session middleware is the best-effort
        // fallback — it drops a session once the stored password hash no longer matches).
        $member->forceFill([
            'password' => Hash::make($input['password']),
            'remember_token' => Str::random(60),
        ])->save();

        SessionRevocation::purgeMemberSessions((int) $member->getAuthIdentifier());

        // A reset answers a possible compromise, so void any pending email change too: otherwise an
        // attacker who requested one before the reset still holds a live confirmation token.
        EmailChangeRequest::where('member_id', $member->getKey())->delete();

        // Security alert to the member's own address (takeover detection), the same as an in-session change.
        $member->notify(new PasswordChangedNotification($member->locale ?? app()->getLocale()));
    }
}
