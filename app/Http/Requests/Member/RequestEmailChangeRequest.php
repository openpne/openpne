<?php

namespace App\Http\Requests\Member;

use App\Models\Member;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * The request step of an email-address change. Re-authenticates with the current password
 * (current_password:member) per OWASP before touching the login identifier, then validates the new
 * address: format + a case-insensitive uniqueness check (whereRaw lower(email), mirroring
 * IssueRegistrationToken so an upgraded mixed-case row is not missed), and a no-op when it already
 * equals the member's current address.
 */
class RequestEmailChangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof Member;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'password' => ['required', 'current_password:member'],
            'new_email' => ['required', 'string', 'email', 'max:255'],
        ];
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

            $email = Str::lower(trim((string) $this->input('new_email')));

            if ($email === Str::lower(trim((string) $member->email))) {
                $validator->errors()->add('new_email', __('This is already your email address.'));

                return;
            }

            if (Member::whereRaw('lower(email) = ?', [$email])->exists()) {
                $validator->errors()->add('new_email', __('This email address is already in use.'));
            }
        });
    }
}
