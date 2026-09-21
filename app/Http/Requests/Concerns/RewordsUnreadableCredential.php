<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Validation\ValidationException;

/** The package refuses a credential it cannot deserialise under a key shared app-wide, so the member-facing wording is the app's own. */
trait RewordsUnreadableCredential
{
    protected function passedValidation(): void
    {
        try {
            parent::passedValidation();
        } catch (ValidationException) {
            throw ValidationException::withMessages(['credential' => __('The passkey could not be read.')]);
        }
    }
}
