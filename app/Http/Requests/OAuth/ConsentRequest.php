<?php

declare(strict_types=1);

namespace App\Http\Requests\OAuth;

use App\Features\Member\MemberNameRules;
use App\Mcp\OAuth\ConsentReauth;
use App\Models\Member;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `actor` names who the connection speaks for: `self`, `new` with a `name`, or `ai:{id}`. Approving
 * re-authenticates with the member's password (`current_password:member`), verified once per sitting
 * through ConsentReauth, as minting a personal token does.
 */
class ConsentRequest extends FormRequest
{
    private ?bool $requiresPassword = null;

    public function authorize(): bool
    {
        return $this->user() instanceof Member;
    }

    protected function prepareForValidation(): void
    {
        // An empty field is "not provided", not a wrong password: the form submits it even inside
        // the window, when the UI shows no password field at all.
        if (in_array($this->input('current_password'), [null, ''], true)) {
            $this->request->remove('current_password');
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'auth_token' => ['required', 'string'],
            'current_password' => [
                Rule::requiredIf(fn (): bool => $this->requiresPassword()),
                'current_password:member',
            ],
            'actor' => ['required', 'string', 'regex:/^(self|new|ai:[0-9]+)$/'],
            'name' => ['exclude_unless:actor,new', ...MemberNameRules::rules()],
            'read_only' => ['sometimes', 'boolean'],
        ];
    }

    /** Decided once per request, so the rule and the stamp cannot straddle the window's edge. */
    public function requiresPassword(): bool
    {
        return $this->requiresPassword ??= ! ConsentReauth::isFresh($this->session());
    }

    /** @return 'self'|'new'|int */
    public function actor(): string|int
    {
        $actor = (string) $this->validated('actor');

        return str_starts_with($actor, 'ai:') ? (int) substr($actor, 3) : $actor;
    }
}
