<?php

namespace App\Features\Auth;

use App\Services\SnsSettingService;
use App\Support\SnsSettingKey;

/**
 * Who may create an account, mirroring OpenPNE 3's invite_mode. OpenPNE 3 defaulted to invite-only
 * and 404'd the open self-registration entry unless invite_mode was set to open — OpenPNE keeps that
 * floor, so open self-registration is opt-in, not the default.
 */
enum RegistrationMode: string
{
    case Open = 'open';      // OpenPNE 3 invite_mode=2: anyone may self-register at /register (behind the CAPTCHA).
    case Invite = 'invite';  // OpenPNE 3 invite_mode=1 (default): members invite; no open entry (member-invite is a gap → admin-created only for now).
    case Closed = 'closed';  // OpenPNE 3 invite_mode=0: registration disabled.

    /**
     * The configured mode from the admin `registration_mode` setting. Falls back to the (restrictive)
     * Invite default on an unset/unknown value, so a missing row or a typo never opens registration.
     */
    public static function current(): self
    {
        return self::tryFrom((string) app(SnsSettingService::class)->get(SnsSettingKey::RegistrationMode))
            ?? self::Invite;
    }

    /** Whether the open self-registration entry (/register) is exposed; the other modes 404 it. */
    public function allowsOpenRegistration(): bool
    {
        return $this === self::Open;
    }
}
