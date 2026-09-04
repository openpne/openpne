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
   feed marks nothing. Reading what a row points at marks it read too: the feed is an inbox, so a
   row is spent by the thing it announces.
   [`NotificationTarget`](../../app/Features/Notifications/NotificationTarget.php) is the per-kind
   table of what that is, and
   [`ConsumeNotificationRows`](../../app/Features/Notifications/ConsumeNotificationRows.php) the
   rule; on a chat surface the read is the read cursor rather than the page view, since opening a
   conversation is not reading what sits above where the reader stopped. A landing page is a GET, so
   **a GET writes `read_at`** here — unlike the row-open and the auth screens, which are POSTs so
   that no prefetch can spend them. What a prefetch spends here is a bell number: the domain state
   the row is about is untouched. A row that is no longer there — the talk broadcast replaces a
   room's row with each message — returns to the feed rather than erroring; only a row that exists
   but belongs to a switched-off unit is refused.
   **Returning to the feed re-reads it**: a restore hands back the page as it was left — Inertia's stored page state on a
   popstate, the whole document from the back/forward cache — which is the state before the row the
   member just opened was marked read. Modern re-reads every restored page, this one included
   ([`revalidate-on-restore.ts`](../../resources/js/lib/revalidate-on-restore.ts)); on Classic —
   full documents, where revalidating means a whole re-request — only this screen reloads
   ([`classic-refresh-on-restore.js`](../../public/js/classic-refresh-on-restore.js)); every other
   Classic page keeps its restored document and has its header ask `GET /notifications/center/counts`
   for the badges again ([`classic-notification-center.js`](../../public/js/classic-notification-center.js)),
   with the panel fetching its rows afresh when next opened. Modern reports the
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
them every 60s while it is visible — and immediately on returning to it — from
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
the feed never mutates domain state. The other direction is the rule, not a violation of it —
reading, or answering, what a row is about marks the row read. OpenPNE 3 kept only the per-event
side — a `member_config` array capped at 20, which both its badges and its panel read — so a count
there could never disagree with the list under it. These layers answer more questions than that
store could, which is why each display surface has to say which layer it is reading.

## The per-member catalog

[`NotificationKind`](../../app/Notifications/Settings/NotificationKind.php) is the closed
registry of member-configurable notification kinds (the extension's `notification_config.yml`
catalog). Each kind is toggleable per
[`NotificationChannel`](../../app/Notifications/Settings/NotificationChannel.php): **mail**
gates the notification email, **web** gates the layer-3 `database` record. Rows live in
[`member_notification_settings`](../../database/migrations/2026_07_08_000001_create_member_notification_settings_table.php);
an **absent row means the kind's per-channel default**, which for every imported kind is enabled,
matching the extension's absent `member_config` = `'1'`. Typed access is
`Member::wantsNotification()` / `setNotificationSetting()`.

One kind's default is not fixed: `group_talk_new_message`'s **web** default is the administrator's
`group_talk_notify_default` setting ([group-talk.md](group-talk.md#what-talk-notifies)) and its mail
default is off whatever the site says. On a channel like that (`NotificationKind::hasSiteDefault()`,
the broadcast's web channel only) a stored row is an **override**: a value equal to the current default is not written, and writing one
deletes the row instead — the stated exception to the invariant below. Without it, a settings save
(the Classic form posts every kind on every save) would freeze the site default into a row per member and
the next administrator flip would silently pass them by.

Both surfaces therefore label such a value **"(default)"** while no row backs it (`siteDefault`, from
[`NotificationSettingsSerializer`](../../app/Features/Notifications/Serializers/NotificationSettingsSerializer.php)),
so an unticked box reads as the site's answer rather than one the member gave. The talk category also
lists the rooms they have muted one at a time
([`MutedTalkRooms`](../../app/Features/GroupTalk/Queries/MutedTalkRooms.php)) — a mute is an exception
to what these toggles say, and it is otherwise only visible from inside the room
([group-talk.md](group-talk.md#mute)).

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
nothing dereferences the gone row. An AI account's withdrawal mails nobody — it has no address for a
receipt, and the operator notice is about the membership, which it was never part of. The two-factor-disabled alert fires only for the removal of a
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

An **AI account** (a member with an owner, see [mcp.md](mcp.md)) receives no outbound channel:
[`SuppressAiAccountNotifications`](../../app/Listeners/Notifications/SuppressAiAccountNotifications.php)
drops `mail` and web push for it at `NotificationSending`, whatever the catalog decided. The
`database` row is kept — it is the account's own record of what happened to it, and what an MCP
client reads.

The other direction — an AI account as the *actor* a notification is about — is a display rule:
wherever the actor's name is baked into a string (the feed sentence, the push body, a mail template
variable), it goes through [`MemberDisplayName`](../../app/Features/Member/MemberDisplayName.php),
which appends the AI marker. Surfaces that render components ship `isAi` on the member reference and
draw a chip instead, so no name is ever marked twice.

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

A talk message broadcasts to its room where the site asked for it. The audience
([`GroupTalkBroadcastRecipients`](../../app/Features/GroupTalk/Queries/GroupTalkBroadcastRecipients.php))
is the group's members minus the author / banned / blocked / already mentioned, and minus anyone who
muted the room. Because the kind's default can be false, the queued
[`BroadcastGroupMessagePosted`](../../app/Jobs/BroadcastGroupMessagePosted.php) reads each chunk's
rows in both polarities (`explicit ?? default`) rather than an opted-out set; with both defaults off
and nobody opted in it exits on two indexed probes without walking the membership, and with someone
opted in the audience is narrowed to the opt-ins. That bound depends on
`Member::setNotificationSetting` deleting, for a kind and channel whose default is a site setting, a
row equal to that default rather than storing it, so the rows are genuine opt-ins and not every
member who ever saved the settings form. It is dispatched with a short delay so a member
reading the room marks it read first: the job then drops anyone whose cursor has passed the message,
which is a race rather than a guarantee. Its feed row is the room's — one per (member, group), read or
unread ([group-talk.md](group-talk.md#what-talk-notifies)) — the one kind whose rows are deleted
outside the display filter, and the one whose bell badge moves with the rooms badge by design.

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
so every timeline notification, both group talk notifications (the mention and the per-message
broadcast, which additionally re-checks mute and whether the message has since been read) and the
direct message re-run their eligibility in `shouldSend()`, the one hook `NotificationSender` consults
immediately before each channel send
([`TimelineNotificationEligibility`](../../app/Features/Timeline/TimelineNotificationEligibility.php),
[`GroupTalkNotificationEligibility`](../../app/Features/GroupTalk/GroupTalkNotificationEligibility.php),
and [`DirectMessageReceivedNotification`](../../app/Notifications/DirectMessage/DirectMessageReceivedNotification.php)'s
own — asked once, so it stays with the notification — each composed with the feature gate through a
trait alias). `via()` cannot serve this: it runs at enqueue time, and `SendQueuedNotifications` replays
the channels decided back then.

Two of those re-checks are **`database`-only**, and are the delivery-time half of the rule that a read
target spends its rows: a direct message whose receipt is already read, and a talk mention the
recipient's cursor has passed, write no feed row — the row would otherwise arrive already spent. Mail
still goes in both cases, so the branch is on the channel rather than folded into the shared gate.

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

A tap on the notification is answered by the worker ([`public/sw.js`](../../public/sw.js)) **with
messages, never by opening the destination itself**; the page routes
([`lib/notification-open.ts`](../../resources/js/lib/notification-open.ts) on Modern,
`push-reconcile.js` on Classic). With no window open, the app is opened at the scope root. Every open
window is then offered the tap (`open-offer`, answered on its port) and the first to answer is
focused and handed the destination (`open`) — a Classic login or admin page has no receiver, and one of
those in front must not swallow the tap (Modern's auth pages answer, and the login's intended URL
carries the destination through). The offer is repeated for a few seconds
because a page still loading cannot answer: the container holds a worker's messages only until
DOMContentLoaded and drops later ones nobody listens for, which is also why the receiver is registered
from the entry module's top level (before that event), never from a component effect. When no page
answers, the front window is shown, and `navigate()`d to the destination except on WebKit.
`openWindow()` never gets a deeper URL: on an iOS home-screen web app that opens the URL in an
embedded browser sheet over an app window left blank — the member is left on an empty page with a URL
bar and no way back but restarting the app (reported since iOS 16.4) — and `navigate()` there was
observed to do nothing.

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

That endpoint is a URL the site later POSTs to, on a client that neither validates the destination nor
pins the connection — see [outbound-http.md](outbound-http.md#the-push-endpoint-seam) for what holds
its shape.

## Key invariants

- `NotificationKind` is the only kind list; the stored `kind` column holds its case value.
- An absent settings row means the kind's `defaultEnabled($channel)`. An explicit choice is stored
  even when it equals the default (the UI saves what the member picked; the upgrade copies source
  rows verbatim where present), so a default flip later applies only to members with no stored row —
  **except on a channel whose default is a site setting** (`hasSiteDefault($channel)`), where a row is an
  override and a value equal to the current default is never stored, so an administrator's flip
  reaches everyone who has not chosen otherwise. `Member::setNotificationSetting` and the OpenPNE 3
  import (`App\Upgrade\Steps\MemberNotificationSettingUpgrade`) are the only writers of these rows,
  and the import never touches a native kind.
- A kind whose default can be false is read in **both polarities** by every fan-out — never as the
  opted-out set alone. [`BroadcastTimelinePosted`](../../app/Jobs/BroadcastTimelinePosted.php),
  [`BroadcastDiaryPosted`](../../app/Jobs/BroadcastDiaryPosted.php) and
  [`GroupNewPostFanout`](../../app/Features/Group/GroupNewPostFanout.php) load only the opted-out set
  (`optedOut`), which is correct **because their kinds default to true on both channels**;
  [`BroadcastGroupMessagePosted`](../../app/Jobs/BroadcastGroupMessagePosted.php) loads both.
- The imported `member_config` key names derive from `NotificationKind::op3ConfigName()`
  (`is_send_{name}_web` / `is_send_pc_{name}_mail`) over `importableCases()` — the upgrade has no
  second name list, and a native kind has no key to invent (asking for one throws).
- Layer-3 `read_at` never feeds layer 1: no count reads it, and reading the feed moves no domain
  state. The other way is the rule — reading (or answering) what a row is about marks the row read.
- Push follows the feed: it is dispatched from the `database` send, never gated separately, and its
  listener never lets an exception escape into the job that wrote the row.
- The worker answers a notification tap with messages and the page routes; the only URL that ever
  reaches `openWindow()` is the scope root, and `navigate()` is the last resort for a window no page
  claimed — never on WebKit.
- The page's receiver for those messages is registered before DOMContentLoaded (the entry module's
  top level; a deferred script on Classic). A listener added in a component effect never hears a
  message sent to a page the worker just opened.
