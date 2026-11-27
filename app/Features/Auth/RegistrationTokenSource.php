<?php

namespace App\Features\Auth;

/**
 * How a pending registration token was issued. Stored on `registration_tokens.source` and checked
 * against the current RegistrationMode at completion, so a token only completes in a mode that would
 * still issue it (see RegistrationMode::allows).
 */
enum RegistrationTokenSource: string
{
    case Selfservice = 'self';      // The registrant requested it themselves at /register (open mode only).
    case MemberInvite = 'member_invite'; // A member invited the address; completion auto-friends the inviter while friends are on.
    case AdminInvite = 'admin_invite';   // An administrator invited the address, or an operator did with openpne:member:invite.
    case AdminRoleInvite = 'admin_role_invite'; // An operator invited the address to be an administrator, with openpne:admin:invite.
}
