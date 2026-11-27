# Administrators as members

An administrator is a member holding the administrator role: they sign in as a member, and the
role opens the admin panel at `/admin` in that same session ([sessions.md](sessions.md)). Nothing
else reads the role, so on the member pages an administrator is a member like any other, except that
they can be neither withdrawn nor have login rejected ([Who can hold the role](#who-can-hold-the-role)).
How an administrator signs in and what guards the panel is in [security.md](security.md),
"Administrators".

## The admin panel

`Member::canAccessPanel()` is true for a member holding the role who is not an AI account and whose
login is not rejected. `EnsureAdministrator` asks it on every admin-realm request
([sessions.md](sessions.md), "Key invariants" 1), from the `web` group and from the panel's own
stack, since panel pages do not pass the `web` group and Livewire's uploads, previews and the
Filament export and import downloads do not pass the panel's. Before the role it runs `auth.session`,
which signs out a session whose password has changed since it signed in, so a session the change left
behind (no row is deleted on a session driver other than `database`) opens no admin-realm route; it
is the only `auth.session` on the admin realm. After the role it asks for [the panel's
entry](#the-panels-entry). A guest is sent to the member login
and, once signed in, back to the URL they asked for; a member the panel refuses gets 403. The only
admin-realm route it leaves alone is `/admin/logout`, which Filament's own check guards; an
architecture test lists every admin-realm route and requires the gate ahead of route-model binding
on each. An Inertia visit to the admin realm, as the Modern login's redirect to `url.intended` makes,
is answered with a full page load ahead of every check (`LoadAdminRealmInFull`), so a refusal too
arrives as a page. The panel has no login page of its own; `/admin/login`
redirects to `/admin`.

- **Logging out of the panel** logs the member out and lands on the member login. A member whose
  role was taken while the panel was open gets 403 there, and logs out from the member pages.
- **Authorization is the gate.** Every Resource and RelationManager sets
  `$shouldSkipAuthorization`, so Filament never asks the member policies, which would judge the
  administrator as the content's reader or author; no code names an `admin` guard. An architecture
  test holds both.
- **Actions name their administrator.** Panel actions that write a member (ban, unban, withdrawal,
  MFA reset, the role) take the acting administrator as an argument, for the log
  (`admin_member_id`) and, for the role, for [the last-administrator rule](#the-last-administrator).
- **The member list** has an Administrator column and filter, and the row actions to give and take
  the role, each confirmed. Giving is hidden for an AI account and a member whose login is
  rejected, taking on the administrator's own row, and a refusal the action still returns is
  shown with its reason.
- **The panel's language** is a session toggle (`/admin/locale/session`) under a key of its own,
  as OpenPNE 3 kept the admin culture apart from the member's in either direction
  ([i18n.md](i18n.md)): it never writes `members.locale`, and the member pages' toggle never
  writes it. The panel and its Livewire requests follow it.
- **The dashboard** reminds an administrator without a confirmed TOTP factor to add one in the
  member settings, and suggests a passkey. No panel widget polls, so a dashboard left open does not
  keep [the panel's entry](#the-panels-entry) open.
- **Registering through an administrator invite** lands on the panel, whatever URL the visitor
  asked for before.

## The panel's entry

A member session alone does not open the panel: it must also hold the entry mark
(`App\Auth\AdminEntry`), which lasts `SESSION_LIFETIME` minutes (120 by default) from the last
admin-realm request. `EnsureAdministrator` checks it after the role on every route it gates, and each
request it lets through starts the period again.

- **A proof stamps it.** The entry screen (`/member/admin-entry`, Classic and Modern) asks for a
  member re-authentication proof under the purpose `admin-entry` ([security.md](security.md),
  "Member re-authentication"): the password with the TOTP factor or an unused recovery code, or a
  passkey. A proof regenerates the session id and keeps the CSRF token, so the member's other open
  tabs still submit. A login stamps it too when `url.intended` is on the admin realm, as for a guest
  sent from the panel to the login and for a registration through an administrator invite, which
  sets it before logging in; any other login does not.
- **Bound to what it was proven against**: the member, the role generation and the password hash.
  A revoke followed by a grant, or a new password, voids it, and a passkey proof given for the entry
  but not yet spent as well.
- **A lapsed mark** sends a GET page visit or a Livewire update to the entry screen; Livewire
  follows the redirect with the whole window. The entry returns to the page visited, or else to the
  last page the session opened when that is on the admin realm, or else to the panel's front. Any
  other request (an image, an upload, a background fetch) gets 403; a refused Livewire upload reports
  through an update, which then moves the window. What a form held is lost, as when the
  panel's own session used to expire.
- `/admin/logout` is the one admin-realm route the check skips, as the gate does.
- Each stamp logs `admin.entered` ([logging.md](logging.md)).

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

`openpne:admin:revoke` refuses to revoke the last administrator. A revoke from the panel also
refuses the actor's own role, and a grant or revoke from the panel is refused when the actor is no
longer an administrator by the time it takes its locks (the `actor` argument of both actions). The
remaining administrators are counted from rows read under lock, so two revokes at once cannot both
see another administrator left.

## Locks

Every grant and revoke locks all administrator rows (`AdministratorLock`), then the target member's
row, in one transaction. A registration's grant takes neither: it writes the row it has just inserted,
and an administrator added can break no rule a revoke checks. A withdrawal's mark and a login rejection lock only the member's row. So a
grant and a ban, or a grant and a withdrawal, of one member serialize on that row, and each re-reads
it under the lock: a ban committed first refuses the grant, a grant committed first refuses the ban.
On MySQL under REPEATABLE READ, the administrator rows are read through the index on
`admin_granted_at` with gap locks. While a grant or revoke is open, every insert into `members`, a
registration among them, waits for it. A grant's or revoke's own write to that index can deadlock
with another grant or revoke on those gaps (two grants on a site with no administrator yet do not
even wait for each other first); InnoDB rolls one back, and both actions run their transaction again
(`attempts: 3`), so a caller sees the deadlock only if it repeats. Laravel retries only the outermost
transaction, so neither action may be called inside another one. SQLite has no row locks; its writes
are serialized instead.

## Inviting an administrator

`openpne:admin:invite <email> [--json]` prints a registration link and writes an administrator
invite for the address, in the transaction that writes the link and under the capacity lock
([capacity.md](capacity.md), "Reservation"). It refuses a site at capacity and an address a member already
has (grant that member the role instead). The address is asked again at the end of the transaction,
so a registration that commits first leaves the command failing with neither a link nor an invite
written. Stdout carries only the URL, or one line of JSON
`{"email","url","expires_at"}`; every failure and the note go to stderr.

- **The invite belongs to the address, not to the link.** `admin_role_invites` holds one row per
  address with its expiry, the link's lifetime from the moment it was issued
  (`OPENPNE_REGISTRATION_TOKEN_TTL_MINUTES`). A registration of the address while the invite is live
  grants the role, through whichever link: one link per address means a newer self-registration or
  member invite replaces the operator's link, and a mark on the link would let anyone erase the invite
  by asking again. Whoever completes a newer link read the address's mailbox or was handed a link an
  operator printed, so they are the address's holder all the same. Changing a member's address never grants the role.
- **Closed sites.** The invite is issued in every registration mode, `closed` included, as the way
  back in when no one can reach the panel. Its link has a source of its own,
  `admin_role_invite`, which completes in any mode only while the address's invite is live. In
  `closed` nothing else completes: a link of another source to an invited address answers 404 like
  any other ([security.md](security.md), "Registration links").
- **Completing.** The transaction that creates the member re-reads, under lock, the link (still the
  address's current one and within its lifetime) and the invite, and checks the mode against both,
  reading it from its row under a shared lock so a mode change commits wholly before or after.
  A link replaced meanwhile creates no one. An invite that lapsed meanwhile refuses the invite's own
  link in every mode, and any link in `closed`; another link elsewhere registers a member without the
  role. A live invite is deleted as the role is set.
- **Expired invites** are kept seven days past their expiry, then swept with the other expired links
  ([runtime.md](runtime.md), "Scheduled tasks").

## The registration screen

Every page drawn at a link to an address with a live invite draws nothing an operator or a package
put in the shells: the Classic HTML slots, footer, banners, side-banner gadgets and custom CSS, and
every layout hook on both surfaces ([layout-hooks.md](layout-hooks.md)). That is the completion
screen, the paused screen that stands in for it on a full site, and an error page at the same URL:
`ShellInsertions` decides from the route and its token, so the 419 of a form left open past the
session holds too, and once the controller has found the invite live it keeps that finding, so an
invite spent while the page is drawn does not bring the insertions back. Every completion screen shows the address in a read-only field with
`autocomplete="username"`, so a password manager files the new password under it, and the route
carries `no-store` ([security.md](security.md), "Response headers").

## Command line

| Command | Does |
|---|---|
| `openpne:admin:invite <email> [--json]` | [Inviting an administrator](#inviting-an-administrator) |
| `openpne:admin:grant <email\|id>` | gives an existing member the role; exits 0 without a change for an administrator, non-zero for a member who cannot hold it or does not exist |
| `openpne:admin:revoke <email\|id>` | takes the role, never from the last administrator |
| `openpne:admin:list [--json]` | the member count, the administrators and the administrator invites |

`openpne:admin:list --json` prints one line, a contract hosting automation reads:
`{"members":N,"admins":[{"id","email","name","two_factor","passkeys","granted_at"}],"invites":[{"email","expires_at","expired"}]}`.
`members` counts every row of `members`, AI accounts included. `two_factor` is a confirmed TOTP
factor and `passkeys` the member's count of them. Times are ISO 8601 in UTC. `invites` holds the
administrator invites with their expiry, an expired one with `expired: true` until it is swept
seven days later, whether or not housekeeping has run. Stdout carries only the JSON, raw past the
console formatter, so a name or an address arrives unchanged.

A locked-out administrator recovers as a member does, with a password reset by mail, a recovery
code or `openpne:member:disable-mfa`. When no member who can open the panel is left,
`openpne:admin:grant` gives the role to one, and `openpne:admin:invite` to an address that is not
a member yet.
