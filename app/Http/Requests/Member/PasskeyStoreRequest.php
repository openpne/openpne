<?php

namespace App\Http\Requests\Member;

use App\Features\Member\PasskeyReauth;
use App\Http\Requests\Concerns\RewordsUnreadableCredential;
use App\Models\Member;
use Illuminate\Auth\Access\AuthorizationException;
use Laravel\Passkeys\Http\Requests\PasskeyRegistrationRequest;

class PasskeyStoreRequest extends PasskeyRegistrationRequest
{
    use RewordsUnreadableCredential;

    public function authorize(): bool
    {
        return $this->user() instanceof Member && PasskeyReauth::isFresh($this->session());
    }

    protected function failedAuthorization(): void
    {
        throw new AuthorizationException(__('Some time has passed since you confirmed your password. Please confirm it again.'));
    }

    /**
     * The package asks the client for a name; this app derives it from the credential instead, so
     * whatever was posted is dropped here rather than stored.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['name']);

        return $rules;
    }
}
