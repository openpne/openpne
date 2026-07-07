<?php

namespace App\Features\Member\Serializers;

use App\Features\Member\MfaSetupReauth;
use App\Models\Member;
use Illuminate\Contracts\Session\Session;
use Laravel\Fortify\Fortify;

/**
 * Two-factor state for the member settings UI, shared by the Classic category page and the
 * Modern detail page. Secret material is scoped to the state that needs it: the otpauth QR and
 * setup key appear only while set-up is pending (inert until confirmed), and recovery codes
 * appear only on the request right after they were (re)generated — flagged by a session flash,
 * read from the member row, never stored in the session.
 */
class MemberMfaSerializer
{
    /** Flash key set by the controller right after confirm/regenerate mints fresh recovery codes. */
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
