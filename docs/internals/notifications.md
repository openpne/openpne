# Notifications

The member-facing notification system: what fires them, how a member opts in or out (the
"notification catalog"), and how the pieces layer. The catalog mirrors an **OpenPNE 3
notification extension** — an add-on carried by some OpenPNE 3 sites, not vanilla OpenPNE 3 —
whose per-member `member_config` opt-in keys are what the upgrade imports. Mail wording is the
admin-editable template system (see [`app/Mail/Template/`](../../app/Mail/Template)); this
document covers the delivery model around it.

## The three layers

1. **Live counts** — "needs action" numbers derived from each domain's own truth (pending
   `friend_requests` rows, unread `direct_message_recipients`, pending `group_join_requests`).
   No notification table, no seen state: acting on the item (accept / reject / read) is what
   makes the count drop. [`App\Features\Home\UnreadCounts`](../../app/Features/Home/UnreadCounts.php).
2. **Display surfaces over layer 1** — Modern's nav badges, its dashboard notice panel, and the
   Classic home cautions. The notice panel reads layer 1 alone: "needs action" items only, so the
   layer-3 unread-row count stays with the bell — a panel row would restate that badge without
   adding anything. The Classic header's notification center reads layer 3 alone (below).
3. **Per-event records** — one row per event in the standard Laravel `notifications` table
   (the `database` channel of each notification class), carrying a `kind` discriminator plus
   entity ids. Read state is the row's own `read_at`. Read by the feed
   ([`app/Features/Notifications/`](../../app/Features/Notifications), `/notifications`):
   rows are hydrated at render time from their ids (a withdrawn actor degrades to
   a fallback label), opening a row marks it read and redirects to its target, and viewing the
   feed marks nothing — only opening a row or the explicit mark-all does. **Returning to the feed
   re-reads it**: a restore hands back the page as it was left — Inertia's stored page state on a
   popstate, the whole document from the back/forward cache — which is the state before the row the
   member just opened was marked read. Modern revalidates from
   [`history-restore.ts`](../../resources/js/lib/history-restore.ts), Classic reloads from
   [`classic-refresh-on-restore.js`](../../public/js/classic-refresh-on-restore.js); only this
   screen carries either, since a screen that cannot go stale behind the member's own back has no
   reason to pay for it. Modern reports the
   unread-row count (via `UnreadCounts`, alongside the layer-1 numbers) in the nav badge and the
   phone bottom bar's notifications tab. Both read the shared `unread` prop, so they cannot
   disagree.

   Both surfaces serve it. A row's sentence is
   [`NotificationKindLabel`](../../app/Features/Notifications/NotificationKindLabel.php)'s, so
   Classic Blade and the Modern client print one wording from one source. Classic lists each row
   as a POST to the open route, which keeps target resolution — and its access checks — on that
   request rather than on every listed row.

   Classic also reaches these rows from the header notification center. Its badges and its panel
   share one capped window
   ([`NotificationCenterWindow`](../../app/Features/Notifications/NotificationCenterWindow.php)),
   split by
   [`NotificationCenterCategory`](../../app/Features/Notifications/NotificationCenterCategory.php),
   so a badge can never stand over rows the panel does not show — counting the whole unread table
   there would. The feed's own mark-all still counts everything, since it pages past that window.
   The panel is also the one place a `%friend%` request can be answered without leaving the page,
   and those buttons follow the request's own state rather than the row's `read_at`. See
   [classic-compatibility.md](classic-compatibility.md).

### Liveness

The shared `unread` counts would otherwise only move on a navigation. An open Modern tab refreshes
them every 60s while it is visible — and immediately on returning to it, and on a page restored from
history, where the counts come back as they were before the member read anything and a badge would
otherwise climb back over what they have already read — from
[`GET /unread-counts`](../../app/Features/Home/UnreadCountsController.php), which runs the count
queries and the sidebar's room list ([group-talk.md](group-talk.md#the-joined-group-list-is-a-room-list))
and nothing else, then pushes both into the shared props client-side
([`unread-sync.tsx`](../../resources/js/components/unread-sync.tsx), whose applier
[owns the rule that they move together](../../resources/js/lib/unread-payload.ts)). The rooms ride
this response rather than one of their own because the groups badge counts exactly the rooms listed
under it: read apart, a refresh would zero the badge above a row still claiming five. The document
title mirrors the layer-3 unread-row count as a `(N) ` prefix, applied through Inertia's title
callback because the head manager owns that DOM write. A failed refresh keeps what it has. There is
no websocket at this layer; a closed tab is reached by web push instead (below).

**Read-state separation is the invariant**: layer-1 counts never consume `read_at`, and reading
the feed never mutates domain state. OpenPNE 3 kept only the per-event side — a `member_config`
array capped at 20, which both its badges and its panel read — so a count there could never
disagree with the list under it. These layers answer more questions than that store could, which
is why each display surface has to say which layer it is reading.

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
**wired** kinds (those with an OpenPNE 4 sender) appear in the settings UI. A kind with no
`op3Name` is native to OpenPNE 4: there is no stored choice to import, so `importableCases()`
(not `cases()`) is what the upgrade derives its source keys from.

`dependOnNot` encodes the extension's "(x only)" variants: `DirectMessageNewOnlyFriends` only takes
effect while `DirectMessageNew` is off — an enabled broad kind already covers the narrower audience.
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

A notification belonging to a feature unit an administrator switched off is dropped by its
`shouldSend()`, not here — `via()` runs too early for a queued notification to see the change. That
gate is orthogonal to everything above: template and opt-out channel dropping is unchanged, and the
feed rows already written stay put. See [feature-toggles.md](feature-toggles.md#notifications).

The group-join notice to admins is `['mail', 'database']` but gated differently: not a member
catalog kind, so the opt-out is the **per-community** `groups.is_join_notification_enabled`
(applied by the recipient query — an opted-out community notifies no one), plus the admin's global
`group-join` template toggle for the mail part.

## Broadcast fan-out

A new-diary broadcast reaches its whole audience (visibility-scoped: everyone / the author's friends /
nobody), so it does not gate through `via()`. A queued [`BroadcastDiaryPosted`](../../app/Jobs/BroadcastDiaryPosted.php)
job walks the audience ([`DiaryPostedRecipients`](../../app/Features/Diary/Queries/DiaryPostedRecipients.php))
in id-ordered chunks; each chunk resolves every recipient's channels from **one** opt-out query over
the `member_notification_settings` fan-out index (absent-means-on), never a per-recipient cold read.
The two kinds compose as a union (which realises `dependOnNot`): mailed/fed if `diaryNewPost` is on,
**or** the recipient is a friend and `diaryNewPostOnlyFriends` is on. Each recipient gets exactly one
notification carrying its decided channels, so the `database` feed row is never duplicated per channel.

New community topic/event postings broadcast the same way ([`GroupNewPostFanout`](../../app/Features/Group/GroupNewPostFanout.php)),
but to the community's confirmed members (minus the author / banned / blocked) and gated by a single
new-post kind — no friends-only variant. Their mail leg also needs the shared (configurable)
`group-posting` template to be enabled, resolved once per broadcast rather than per recipient.

A community comment fans out the same fan-out, but its audience additionally **excludes the author and
every co-commenter** — they get the inline Reply / Related notification instead. That makes the
per-comment precedence Reply > Related > Group, with one notification per member; the broadcast and
the inline notifications share the `group_*_commented` feed kind, distinguished by the reason. The
excluded ids are snapshotted when the comment is posted and passed to the async job, not re-derived
when it runs: a comment deleted in between would otherwise drop its author from the exclusion and
notify them twice.

A new timeline post broadcasts like a diary — visibility-scoped audience
([`TimelinePostedRecipients`](../../app/Features/Timeline/Queries/TimelinePostedRecipients.php)), the
same two-kind union, a queued [`BroadcastTimelinePosted`](../../app/Jobs/BroadcastTimelinePosted.php)
— with the community broadcast's exclusion shape on top: the members the post `@mentions` are
subtracted, and its mail leg needs the shared (configurable) `timeline-posting` template. Its reply
notifications subtract the same set. Who hears what, and why, is
[timeline.md](timeline.md#notifications).

## Delivery-time re-checks

A queued notification decides its channels when it is *enqueued* and delivers them later. For most
kinds that gap is harmless: the recipient already holds the thing being announced. It is not harmless
where the mail carries content the recipient's access to can lapse — a ban, a new block, a revoked
friendship on a Friends thread, a member who has left the group, a message the recipient has purged —
so every timeline notification, the group talk mention and the direct message re-run their eligibility
in `shouldSend()`, the one hook `NotificationSender` consults immediately before each channel send
([`TimelineNotificationEligibility`](../../app/Features/Timeline/TimelineNotificationEligibility.php),
[`GroupTalkNotificationEligibility`](../../app/Features/GroupTalk/GroupTalkNotificationEligibility.php),
and [`DirectMessageReceivedNotification`](../../app/Notifications/DirectMessage/DirectMessageReceivedNotification.php)'s
own — asked once, so it stays with the notification — each composed with the feature gate through a
trait alias). `via()` cannot serve this: it runs at enqueue time, and `SendQueuedNotifications` replays
the channels decided back then.

## Web push

A layer-3 row only reaches a member who is looking. Web push delivers the same event a second time,
to a device, so a closed tab is still reachable.

It is **not a channel of its own**. The dispatch is a listener on the framework's `NotificationSent`
event filtered to `channel === 'database'`
([`SendWebPushNudge`](../../app/Listeners/Notifications/SendWebPushNudge.php)): a feed row having been
written is the eligibility, which means every catalog opt-in, fan-out rule and feature gate above has
already decided, and a notification added later is pushed without being told about push.
[`NotificationChannel`](../../app/Notifications/Settings/NotificationChannel.php) stays closed at
web + mail, and the **web** opt-in gates both the row and the push. The nudge sends on
`WebPushChannel`, so it cannot re-enter the filter.

The listener runs inside the queued job that wrote the row, so everything past its two pure filters
is wrapped in a `try`/`report()`: an exception escaping it would retry that job and **duplicate the
feed row**. Its guards are ordered config → subscription probe → member toggle, since most members
have no device and the probe is what keeps a member-wide fan-out at one query per recipient.

The payload ([`WebPushNudge`](../../app/Notifications/Push/WebPushNudge.php)) carries the same
sentence the feed row renders (`NotificationKindLabel`, so there is no second wording list), a fixed
tag so a new nudge collapses the previous one on the device, and the unread count read at send time
for the app badge. It is constructed from scalars, not models: an actor who withdraws between the row
and the send degrades to the withdrawn-member label instead of failing to restore.

Three switches, at three scopes:

- **The site**: a VAPID keypair (`OPENPNE_VAPID_*`, `config/webpush.php`). Absent, the feature does
  not exist — no shared prop, the subscribe endpoints 404, nothing is sent
  ([`WebPushConfig`](../../app/Notifications/Push/WebPushConfig.php) is the single predicate all three
  read). There is no administrator setting beside it: the keys *are* the switch.
- **The member**: `PreferenceKey::PushDelivery`, one global pause switch — not a per-kind set, since
  the catalog already decided which events exist. Subscribing a device is the consent, so it defaults
  to enabled.
- **The device**: a row in `push_subscriptions`, written by `POST /push/subscriptions`. The endpoint
  is the subscription's identity and is unique globally, so re-registering it under another member
  moves the row. The store is capped at 10 devices per member, oldest pruned. An endpoint the push
  service reports as 404/410 is deleted by the package's report handler, so expiry needs no wiring.

The subscribe UI is Modern-only, but **ownership reconciliation runs on every authenticated surface**:
the Modern shell (`UnreadSync`) and the Classic header (a gated partial loading `push-reconcile.js`)
both re-POST the browser's existing subscription to reclaim the row for whoever is signed in now (this
also heals a cap-pruned row) so a shared browser cannot keep delivering the previous member's pushes —
the account switch is closed on whichever surface B signs in on. The POST fires only on an **ownership
transition** — a per-browser `openpne-push-bound` marker (endpoint + member id + timestamp) records the
last confirmed binding, and a match within a 12h TTL skips the POST — so a confirmed same-member binding
costs zero requests and ordinary browsing (Classic is a full reload per page) never trips the store
route's `throttle:30,1`. How an unconfirmed rebind is handled turns on what the marker already knows
(`reconcileOutcome`). A **known-foreign** subscription — the marker's endpoint matches but its member
differs, so this device was confirmed as *another* member's — is shed on **any** non-2xx: a transient
failure is no reason to keep delivering the prior member's pushes to whoever is signed in now, and the
retry only fires on the next navigation (not a timer), so a kept foreign subscription is not
time-bounded. For **our own, or an ownership-unknown** subscription, only a **definitive refusal**
(400/401/403/404/419/422) sheds it; a 408/425/429/5xx or a dropped request is the server not answering,
so the subscription is kept and the next navigation retries (the marker stays unwritten until a 2xx) —
a transient outage must not unsubscribe a member's own device. Failing to *obtain* the subscription
handle is a no-op, not a fail-close — nothing is bound, so nothing is shed; likewise a member who never
opted in has no subscription to rebind, and this never subscribes a fresh browser.

That endpoint is a URL the site later POSTs to, over a Guzzle client outside `App\Outbound` — see
[outbound-http.md](outbound-http.md#the-push-endpoint-seam) for what holds its shape.

## Key invariants

- `NotificationKind` is the only kind list; the stored `kind` column holds its case value.
- An absent settings row means the kind's `defaultEnabled()`. An explicit choice is stored even
  when it equals the default (the UI saves what the member picked; the upgrade copies source
  rows verbatim where present), so a default flip later applies only to members with no stored row.
- The imported `member_config` key names derive from `NotificationKind::op3ConfigName()`
  (`is_send_{name}_web` / `is_send_pc_{name}_mail`) over `importableCases()` — the upgrade has no
  second name list, and a native kind has no key to invent (asking for one throws).
- Layer-1 counts and layer-3 `read_at` never feed each other.
- Push follows the feed: it is dispatched from the `database` send, never gated separately, and its
  listener never lets an exception escape into the job that wrote the row.
