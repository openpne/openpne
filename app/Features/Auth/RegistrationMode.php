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
    case Open = 'open';            // OpenPNE 3 invite_mode=2: anyone may self-register at /register (behind the CAPTCHA).
    case Invite = 'invite';        // OpenPNE 3 invite_mode=1 (default): members invite (/invite); no open self-registration entry.
    case AdminOnly = 'admin_only'; // OpenPNE 3 invite_mode=0 + admin_invite: only an admin may invite; members cannot.
    case Closed = 'closed';        // Registration suspended: even a valid token cannot complete.

    /**
     * The configured mode from the admin `registration_mode` setting. Falls back to the (restrictive)
     * Invite default on an unset/unknown value, so a missing row or a typo never opens registration.
     */
    public static function current(): self
    {
        return self::tryFrom((string) app(SnsSettingService::class)->get(SnsSettingKey::RegistrationMode))
            ?? self::Invite;
    }

    /** Admin-facing label (translation key) for the current mode; matches the settings page options. */
    public function label(): string
    {
        return match ($this) {
            self::Open => 'Anyone can register',
            self::Invite => 'Invite only',
            self::AdminOnly => 'Admin invite only',
            self::Closed => 'Registration closed',
        };
    }

    /** Whether the open self-registration entry (/register) is exposed; the other modes 404 it. */
    public function allowsOpenRegistration(): bool
    {
        return $this === self::Open;
    }

    /** Whether a logged-in member may invite others (OpenPNE 3 invite_mode >= member-invite). */
    public function allowsMemberInvite(): bool
    {
        return $this === self::Open || $this === self::Invite;
    }

    /** Whether an admin may invite (OpenPNE 3 admin_invite: always, short of a global suspend). */
    public function allowsAdminInvite(): bool
    {
        return $this !== self::Closed;
    }

    /**
     * Whether a token of this origin may still complete in the current mode. The mode gates issuance,
     * but this re-check at completion is what makes a tightened mode retroactive: switching to
     * admin_only stops outstanding self/member links from completing, not just new issuance. Closed
     * makes every branch false, so all tokens are dead.
     */
    public function allows(RegistrationTokenSource $source): bool
    {
        return match ($source) {
            RegistrationTokenSource::Selfservice => $this->allowsOpenRegistration(),
            RegistrationTokenSource::MemberInvite => $this->allowsMemberInvite(),
            RegistrationTokenSource::AdminInvite => $this->allowsAdminInvite(),
        };
    }
}
