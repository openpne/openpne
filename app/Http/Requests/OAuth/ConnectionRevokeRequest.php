<?php

declare(strict_types=1);

namespace App\Http\Requests\OAuth;

use App\Mcp\OAuth\ConsentReauth;
use App\Models\Member;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Ending a connection re-authenticates the way making one does, through the same window. */
class ConnectionRevokeRequest extends FormRequest
{
    private ?bool $requiresPassword = null;

    public function authorize(): bool
    {
        return $this->user() instanceof Member;
    }

    protected function prepareForValidation(): void
    {
        if (in_array($this->input('current_password'), [null, ''], true)) {
            $this->request->remove('current_password');
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'current_password' => [
                Rule::requiredIf(fn (): bool => $this->requiresPassword()),
                'current_password:member',
            ],
        ];
    }

    public function requiresPassword(): bool
    {
        return $this->requiresPassword ??= ! ConsentReauth::isFresh($this->session());
    }
}
