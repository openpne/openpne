<?php

namespace App\Features\Member\Serializers;

use App\Features\Member\MfaSetupReauth;
use App\Models\Member;
use Illuminate\Contracts\Session\Session;
use Laravel\Fortify\Fortify;

/**
 * Secret material is scoped to the state that needs it: the QR and setup key only while set-up is
 * pending, the recovery codes only on the request after they were generated. That request is flagged
 * by a session flash; the codes are read from the member row, never stored in the session.
 */
class MemberMfaSerializer
{
    /** Flashed by the controller on the request that minted fresh recovery codes. */
    public const SHOW_RECOVERY_CODES = 'mfa.show_recovery_codes';

    /** @return array<string, mixed> */
    public static function state(Member $member, Session $session): array
    {
        if (blank($member->two_factor_secret)) {
            return ['state' => 'disabled'];
        }

        if ($member->two_factor_confirmed_at === null) {
            // A data URI (not raw SVG markup) so both surfaces render it as a plain <img>.
            return [
                'state' => 'pending',
                'qrCode' => 'data:image/svg+xml;base64,'.base64_encode($member->twoFactorQrCodeSvg()),
                'secret' => Fortify::currentEncrypter()->decrypt($member->two_factor_secret),
                // The confirm form only shows a password field when the enable step's re-auth
                // window has lapsed (ConfirmMfaRequest demands it in lockstep).
                'requiresPassword' => ! MfaSetupReauth::isFresh($session),
            ];
        }

        $state = [
            'state' => 'enabled',
            'recoveryCodesCount' => count($member->recoveryCodes()),
        ];

        if ($session->get(self::SHOW_RECOVERY_CODES)) {
            $state['recoveryCodes'] = $member->recoveryCodes();
        }

        return $state;
    }
}
