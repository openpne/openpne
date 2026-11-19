<?php

namespace App\Http\Requests\Member;

use App\Auth\Reauth\ReauthPurpose;
use App\Auth\Reauth\RequiresReauth;
use App\Models\Member;
use App\Rules\NewEmailAddress;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * The uniqueness check is case-insensitive (`lower(email)`, as IssueRegistrationToken does), so an
 * upgraded mixed-case row is not missed.
 */
class RequestEmailChangeRequest extends FormRequest
{
    use RequiresReauth;

    public function authorize(): bool
    {
        return $this->user() instanceof Member;
    }

    public function reauthPurpose(): ReauthPurpose
    {
        return ReauthPurpose::EmailChange;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['new_email' => NewEmailAddress::rules()] + $this->reauthRules();
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // Only after the format rule passes — otherwise a malformed value would reach the lookup.
            if ($validator->errors()->has('new_email')) {
                return;
            }

            $member = $this->user();
            if (! $member instanceof Member) {
                return;
            }

            $email = Str::lower(Str::trim((string) $this->input('new_email')));

            if ($email === Str::lower(Str::trim((string) $member->email))) {
                $validator->errors()->add('new_email', __('This is already your email address.'));

                return;
            }

            if (Member::whereRaw('lower(email) = ?', [$email])->whereKeyNot($member->getKey())->exists()) {
                $validator->errors()->add('new_email', __('This email address is already in use.'));
            }
        });
    }
}
