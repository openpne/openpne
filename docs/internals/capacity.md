# Capacity

Two caps a host sets on a site and an administrator cannot: how many members it may hold, and how
many AI accounts. A site with neither row behaves as every site did before the caps existed.

## The two settings

| Key | Group | Value |
|---|---|---|
| `member_capacity` | `Capacity` | an integer, or absent / `''` for no cap |
| `ai_account_capacity` | `Capacity` | the same |

`SettingGroup::Capacity` has no admin page. Every settings page saves its whole group from the
form state, so a key placed in `Auth` would be reset to its default each time the registration
settings were saved; a group of its own keeps the host's value out of every form.

A non-numeric stored value reads as no cap: the writer below only stores integers, so such a value
is corruption, not a decision.

**Writing one.** `php artisan openpne:capacity member 50`, `… member unlimited`, `… ai 20`; with no
arguments it prints both caps and the counts behind them. The command is the supported writer
because `SnsSettingService` caches the row for up to an hour: a process that writes the row
directly must then clear that cache (`SnsSettingService::clearCache()`), or the gates below keep
answering from the old value for that long.

## Member capacity

A **seat** is a member who is not an AI account (`members.owner_member_id IS NULL`), or a
registration link still inside its TTL (`registration_tokens.created_at`,
`openpne.registration.token_ttl_minutes`). Withdrawal deletes the row, so a former member holds
nothing; an expired link frees its seat without being pruned. AI accounts hold no seat: they are
counted under their own cap, so a site offering AI accounts does not spend its member seats on them.

`App\Features\Auth\MemberCapacity` answers every question:

- `remaining()` — `limit − members − pending`, floored at 0; `null` under no cap.
- `isFull()` — no seat for a **new link**.
- `isFullForCompletion()` — `members ≥ limit`: a live link's own seat is already in `pending()`, so
  completing it only asks whether the members alone fill the cap. OpenPNE 3 also counted the
  invitee's pre-member row at this step and so refused the last seat to the person it was issued to.
- `state()` — `Unlimited | Ok | Low | Full | Over` for the dashboard; `Low` is five seats or fewer
  left, `Over` is a cap lowered below what the site already holds.

### Where a seat is taken

| Path | Screen | Gate |
|---|---|---|
| self-registration entry (`/register`) | registration-paused box instead of the form; the submit redirects back to it | `IssueRegistrationToken` |
| member invitation (`/invite`) | the same box, under the invite page's own id | `IssueRegistrationToken` |
| administrator invitation (admin "Member invites") | the OpenPNE 3 notice in place of the form; a batch that fills the site part-way reports how many were not sent | `IssueRegistrationToken` |
| link completion (`/register/{token}`) | the paused box instead of the account form; the token is kept, so the link works again once a seat frees | `CompleteRegistration` |

The entry screens check before rendering so a visitor sees the notice rather than a form that will
refuse; the two actions are the gates, and `RegistrationTokenWritersTest` pins that no other code
writes a registration token.

Re-mailing a link to an address that already holds a live one takes no seat, so a visitor who lost
the mail is not kept out of a full site until their first link expires.

### Reservation

Under a finite cap, each gate takes the cap's own `sns_settings` row `FOR UPDATE`
(`App\Support\CapacityLock`), re-reads the counts, decides, and writes inside that transaction.
Two writers racing for the last seat are serialized on that row, and the cap read under the lock is
the row's, not the cached one. The member and AI rows are separate locks; `CreateAiAccount` holds
the owner's member row first and the AI cap row second, and nothing takes them in the other order.
Under no cap the gate neither locks nor opens a transaction.

### Asking for a seat

The paused box carries one button: `POST /register/capacity-request`, which mails
`admin_mail_address` the `member-capacity-request` template (the OpenPNE 3 wording, naming the
site's host) and flashes a confirmation. It answers 404 unless the site is full, and on a closed
registration mode, where there is nothing to reopen. A guest or a member may send it, so it sits
outside the guest middleware; the `capacity-request` limiter allows 3 a minute per address and 20 an
hour site-wide — the site-wide limb is what bounds a relay from many addresses, at the cost that
spending it silences real requests for the hour.

## AI account capacity

`ai_account_capacity` caps `members` rows with an owner across the whole site, independently of the
per-member `ai_account_limit` an administrator sets. `AiAccountSettings::canCreate($owned)` is the
one answer every create affordance renders (the member's AI page on both surfaces and the OAuth
consent screen); `CreateAiAccount` re-reads it under the locks and refuses with
`SiteCapacityReached` once the site is full, after the per-member limit has had its say.

## Dashboard

`MemberCapacityWidget` appears only once a cap is set. It shows seats taken against the cap, how
many are invited and how many are left, coloured by `state()`, and the AI count against its cap
when that is set. It has no control behind it.
