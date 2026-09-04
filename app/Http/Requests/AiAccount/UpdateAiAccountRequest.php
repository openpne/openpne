<?php

declare(strict_types=1);

namespace App\Http\Requests\AiAccount;

use App\Features\AiAccount\SelfIntroductionField;
use App\Features\Member\MemberNameRules;
use App\Features\Profile\ProfileFieldRules;
use App\Models\Member;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The name and self-introduction are held to the profile editor's own rules, so a value this panel
 * writes is one the profile page could have written. No re-authentication: this is the account's
 * face, not its credentials.
 */
class UpdateAiAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof Member;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = ['name' => MemberNameRules::rules()];

        $field = app(SelfIntroductionField::class)();
        if ($field !== null) {
            // ProfileFieldRules keys its rules by profile id; this form posts one fixed key
            // instead, so neither surface's markup nor the error it shows depends on an id that
            // differs per install.
            $rules['self_introduction'] = app(ProfileFieldRules::class)
                ->forValue($field, (int) $this->aiAccount()->getKey())['profile.'.$field->getKey()];
        }

        return $rules;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        $field = app(SelfIntroductionField::class)();

        return $field !== null && app(ProfileFieldRules::class)->isUniqueText($field)
            ? ['self_introduction.unique' => __('This value is already in use.')]
            : [];
    }

    /** The account named by the route, which the route's ownership gate has already vouched for. */
    private function aiAccount(): Member
    {
        $member = $this->route('member');
        assert($member instanceof Member);

        return $member;
    }
}
