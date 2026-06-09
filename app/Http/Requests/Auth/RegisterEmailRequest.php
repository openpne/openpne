<?php

namespace App\Http\Requests\Auth;

use App\Captcha\Captcha;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The registration email-entry submission. Deliberately no `unique` rule: an already-registered
 * address must look identical to a fresh one (enumeration-safety lives in IssueRegistrationToken,
 * which no-ops for a known member). Email normalization is also the action's job.
 */
class RegisterEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
        ];
    }

    /**
     * Verify the captcha in an after-callback rather than as a field rule, so an *absent* `altcha`
     * (a bot simply omitting it) is still rejected — a field rule is skipped when the field is
     * missing. No-op when CAPTCHA is disabled (NullCaptcha passes everything).
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                // Only verify once the rest of the form is valid: a captcha solution is single-use, so
                // spending it on a submit that fails on the email would make the corrected resubmit
                // (Modern keeps the solved widget) look like a replay.
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $payload = $this->input('altcha');
                if (! app(Captcha::class)->verify(is_string($payload) ? $payload : null)) {
                    $validator->errors()->add('altcha', __('Captcha verification failed. Please try again.'));
                }
            },
        ];
    }
}
