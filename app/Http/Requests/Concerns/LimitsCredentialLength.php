<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Validation\Validator;

/** The package decodes these fields before any challenge is checked, so each is refused past a length no authenticator approaches (docs/internals/security.md, "Member passkeys"). */
trait LimitsCredentialLength
{
    public const CREDENTIAL_FIELD_MAX = 16384;

    /** Every field webauthn-lib's deserialiser decodes. */
    public const CREDENTIAL_FIELDS = [
        'credential.id',
        'credential.rawId',
        'credential.response.clientDataJSON',
        'credential.response.authenticatorData',
        'credential.response.signature',
        'credential.response.userHandle',
        'credential.response.attestationObject',
    ];

    public function withValidator(Validator $validator): void
    {
        $max = 'max:'.self::CREDENTIAL_FIELD_MAX;

        $validator->addRules(array_fill_keys(self::CREDENTIAL_FIELDS, [$max]));
        $validator->setCustomMessages(array_fill_keys(
            array_map(fn (string $field): string => $field.'.max', self::CREDENTIAL_FIELDS),
            __('The passkey could not be read.'),
        ));
    }
}
