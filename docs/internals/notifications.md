# Notifications

The member-facing notification system: what fires them, how a member opts in or out (the
"notification catalog"), and how the pieces layer. The catalog mirrors an **OpenPNE 3
notification extension** — an add-on carried by some OpenPNE 3 sites, not vanilla OpenPNE 3 —
whose per-member `member_config` opt-in keys are what the upgrade imports. Mail wording is the
admin-editable template system (see [`app/Mail/Template/`](../../app/Mail/Template)); this
document covers the delivery model around it.

## The three layers

1. **Live counts** — "needs action" numbers derived from each domain's own truth (pending
   `friend_requests` rows, unread `message_recipients`, pending `community_join_requests`).
   No notification table, no seen state: acting on the item (accept / reject / read) is what
   makes the count drop. [`App\Features\Home\UnreadCounts`](../../app/Features/Home/UnreadCounts.php).
2. **Display surfaces over layer 1** — nav badges and the dashboard notice block. They only
   read layer-1 counts.
3. **Per-event records** — one row per event in the standard Laravel `notifications` table
   (the `database` channel of each notification class), carrying a `kind` discriminator plus
   entity ids. Read state is the row's own `read_at`. Read by the feed
   ([`app/Features/Notifications/`](../../app/Features/Notifications), `/m/notifications`,
   Modern-only): rows are hydrated at render time from their ids (a withdrawn actor degrades to
   a fallback label), opening a row marks it read and redirects to its target, and viewing the
   feed marks nothing — only opening a row or the explicit mark-all does. The nav badge is the
   unread-row count (via `UnreadCounts`, alongside the layer-1 numbers).

**Read-state separation is the invariant**: layer-1 counts never consume `read_at`, and reading
the feed never mutates domain state. OpenPNE 3's notification centre failed by mixing aggregate
and per-event information behind one ambiguous read model; the layers keep the two truths apart.

## The per-member catalog

[`NotificationKind`](../../app/Notifications/Settings/NotificationKind.php) is the closed
registry of member-configurable notification kinds (the extension's `notification_config.yml`
catalog). Each kind is toggleable per
[`NotificationChannel`](../../app/Notifications/Settings/NotificationChannel.php): **mail**
gates the notification email, **web** gates the layer-3 `database` record. Rows live in
[`member_notification_settings`](../../database/migrations/2026_07_08_000001_create_member_notification_settings_table.php);
an **absent row means the kind's default (enabled)**, matching the extension's absent
`member_config` = `'1'`. Typed access is
`Member::wantsNotification()` / `setNotificationSetting()`.

Every catalog item is registered so the one-shot upgrade can preserve stored choices, but only
**wired** kinds (those with an OpenPNE 4 sender) appear in the settings UI. Timeline kinds stay
unwired until community-scoped timeline lands.

`dependOnNot` encodes the extension's "(x only)" variants: `MessageNewOnlyFriends` only takes
effect while `MessageNew` is off — an enabled broad kind already covers the narrower audience.
Delivery reproduces the extension's chain: broad kind on → deliver; else narrow kind on and the
relation holds (e.g. sender is a friend) → deliver.

## Gating flow

A notification's `via($notifiable)` decides channels through
[`RendersMailTemplate::templateChannelsFor()`](../../app/Notifications/Concerns/RendersMailTemplate.php):

- `mail` requires the template to be enabled (admin's `mail_templates.is_enabled`, always true
  for non-configurable templates) **and** the recipient's mail toggle for the kind.
- `database` requires the recipient's web toggle for the kind.

Account-security and transactional mails (password reset, email change, registration-complete,
the withdrawal receipt + admin notice, and the takeover-detection alerts — password changed,
two-factor enabled/disabled, …) are not catalog kinds and are never member-gated — they are
`['mail']` only and always sent. The withdrawal mails address on-demand notifiables (the Member row
is already deleted): a scalar `MemberWithdrawn` payload carries the name, email, and locale so
nothing dereferences the gone row. The two-factor-disabled alert fires only for the removal of a
*live* factor: each caller reads `hasEnabledTwoFactorAuthentication()` before disabling and gates the
alert on it, so cancelling a pending set-up — and the operator lockout CLI acting on a member with no
active factor — sends nothing.

The community-join notice to admins is `['mail', 'database']` but gated differently: not a member
catalog kind, so the opt-out is the **per-community** `communities.is_join_notification_enabled`
(applied by the recipient query — an opted-out community notifies no one), plus the admin's global
`community-join` template toggle for the mail part.

## Key invariants

- `NotificationKind` is the only kind list; the stored `kind` column holds its case value.
- An absent settings row means the kind's `defaultEnabled()`. An explicit choice is stored even
  when it equals the default (the UI saves what the member picked; the upgrade copies source
  rows verbatim where present), so a default flip later applies only to members with no stored row.
- The imported `member_config` key names derive from `NotificationKind::op3ConfigName()`
  (`is_send_{name}_web` / `is_send_pc_{name}_mail`) — the upgrade has no second name list.
- Layer-1 counts and layer-3 `read_at` never feed each other.
