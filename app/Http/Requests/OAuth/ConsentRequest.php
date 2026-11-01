<?php

declare(strict_types=1);

namespace App\Http\Requests\OAuth;

use App\Features\Member\MemberNameRules;
use App\Models\Member;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `actor` names who the connection speaks for: `self`, `new` with a `name`, or `ai:{id}`.
 */
class ConsentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof Member;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'auth_token' => ['required', 'string'],
            'actor' => ['required', 'string', 'regex:/^(self|new|ai:[0-9]+)$/'],
            'name' => ['exclude_unless:actor,new', ...MemberNameRules::rules()],
            'read_only' => ['sometimes', 'boolean'],
        ];
    }

    /** @return 'self'|'new'|int */
    public function actor(): string|int
    {
        $actor = (string) $this->validated('actor');

        return str_starts_with($actor, 'ai:') ? (int) substr($actor, 3) : $actor;
    }
}
