# Administrators as members

A member can hold the administrator role. The role, its commands and its registration path exist,
but the admin panel does not read the role yet: it still signs in its own administrator accounts
through the `admin` guard ([security.md](security.md), "Administrator password links"). Until it
does, granting the role changes nothing a member can do.

## The role

Two columns of `members`: `admin_granted_at` (null when the member is not an administrator) and
`admin_generation`, which steps by one each time the role is actually granted or revoked and never
goes down. Granting an administrator again writes nothing, so the generation, unlike the time, tells
a grant made after a revoke from the one before it. There is one tier: every administrator has the
same powers. `App\Features\Member\Actions\GrantAdministrator` and `RevokeAdministrator` write the
role, and registration through an invite does ([Inviting an administrator](#inviting-an-administrator)).
Each grant and revoke logs `admin.granted` / `admin.revoked` with the actor: `cli`, or the id of the
administrator acting from the panel ([logging.md](logging.md)).

## Who can hold the role

A grant is refused for an AI account, a member whose login is rejected, and a member being
withdrawn. An administrator can be neither withdrawn (by themselves or from the panel) nor have
login rejected, so revoking is the only way an administrator is lost and [the last-administrator
rule](#the-last-administrator) holds there alone. The panel hides both actions for an administrator,
the withdrawal form answers 403, and `WithdrawMember` and `RejectMemberLogin` throw
`AdministratorRoleHeld` for a grant that lands after the caller looked.

A withdrawal first commits `members.withdrawal_started_at` under the member row's lock, before
anything is purged, and refuses an administrator there. The mark stays if the withdrawal stops part
way, so a half-purged member is never granted the role; it goes with the row.

## The last administrator

`openpne:admin:revoke` refuses to revoke the last administrator. A revoke from the panel will also
refuse the actor's own role, and a grant or revoke from the panel is refused when the actor is no
longer an administrator by the time it takes its locks (the `actor` argument of both actions). The
remaining administrators are counted from rows read under lock, so two revokes at once cannot both
see another administrator left.

## Locks

Every write of the role locks all administrator rows (`AdministratorLock`), then the target member's
row, in one transaction. A withdrawal's mark and a login rejection lock only the member's row. So a
grant and a ban, or a grant and a withdrawal, of one member serialize on that row, and each re-reads
it under the lock: a ban committed first refuses the grant, a grant committed first refuses the ban.
On MySQL under REPEATABLE READ, the administrator rows are read through the index on
`admin_granted_at`, whose gap locks can briefly delay a registration while a revoke is open. SQLite
has no row locks; its writes are serialized instead.

## Inviting an administrator

`openpne:admin:invite <email> [--json]` prints a registration link and writes an administrator
invite for the address, in the transaction that writes the link and under the capacity lock
([capacity.md](capacity.md), "Reservation"). It refuses an address a member already has (grant that
member the role instead) and a site at capacity. Stdout carries only the URL, or one line of JSON
`{"email","url","expires_at"}`; every failure and the note go to stderr.

- **The invite belongs to the address, not to the link.** `admin_role_invites` holds one row per
  address with its expiry, the link's lifetime from the moment it was issued
  (`OPENPNE_REGISTRATION_TOKEN_TTL_MINUTES`). A registration of the address while the invite is live
  grants the role, through whichever link: one link per address means a newer self-registration or
  member invite replaces the operator's link, and a mark on the link would let anyone erase the invite
  by asking again. Whoever completes a newer link read the address's mailbox, so they are the
  address's holder all the same. Changing a member's address never grants the role.
- **Closed sites.** The invite is issued in every registration mode, `closed` included, as the way
  back in when no one can reach the panel. Its link has a source of its own,
  `admin_role_invite`, which completes in any mode only while the address's invite is live. In
  `closed` nothing else completes: a link of another source to an invited address answers 404 like
  any other ([security.md](security.md), "Registration links").
- **Completing.** The transaction that creates the member re-reads, under lock, the link (still the
  address's current one and within its lifetime) and the invite, and checks the mode against both.
  A link replaced meanwhile creates no one; an invite that lapsed meanwhile grants nothing, and in
  `closed` refuses. A live invite is deleted as the role is set.
- **Expired invites** are kept seven days past their expiry, then swept with the other expired links
  ([runtime.md](runtime.md), "Scheduled tasks").

## The registration screen

The completion screen, opened through any link to an address with a live invite (and the paused
screen that stands in for it on a full site), draws nothing an operator or a package put in the
shells: the Classic HTML slots, footer, banners, side-banner gadgets and custom CSS, and every layout
hook on both surfaces ([layout-hooks.md](layout-hooks.md)). `ShellInsertions` withholds them for the
request. Every completion screen shows the address in a read-only field with
`autocomplete="username"`, so a password manager files the new password under it, and the route
carries `no-store` ([security.md](security.md), "Response headers").
