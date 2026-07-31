# Feature toggles

An administrator can switch a **feature unit** off site-wide. OpenPNE 3 did this by disabling a
plugin (`plugin.is_enabled`) or, for friends, by `sns_config.enable_friend_link`; OpenPNE 4 keeps
the same semantics in one registry.

The seven units are the cases of [`App\Support\Feature`](../../app/Support/Feature.php):
`diary`, `message`, `timeline`, `community`, `communityTopic`, `communityEvent`, `friend`. The case
value is the feature vocabulary the [surface resolver](feature-modules.md#surface-selection)
already uses, the route-name prefix, and the URL segment — one word per unit.

**Switching a unit off is a gate, not a data operation.** Diaries, messages, topics, files and
friendships stay exactly as they were, and switching the unit back on restores the feature intact.
Nothing is deleted, anonymized or rewritten.

## Storage

Each unit's flag is an `sns_settings` row, declared as a
[`SnsSettingKey`](../../app/Support/SnsSettingKey.php) in the `Features` group
(`feature_diary_enabled`, …) and read through
[`SnsSettingService`](../../app/Services/SnsSettingService.php)'s core cache tier, so the gate costs
no extra query per request. The DB row is authoritative; this is **not** the dormant
`config/features.php` / `modern_status` seam, which answers a different question (which surface
renders a feature, see [feature-modules.md](feature-modules.md#surface-selection)) and stays unused.

**An absent row means enabled**, mirroring OpenPNE 3's lazy `plugin` rows: an install that never
opened the settings page runs every feature, and the upgrade writes a row only for a unit OpenPNE 3
had switched off. Decoding is deliberately **fail-open** — only an explicit `'0'` disables — because
this is an availability switch: a malformed value must not black out a module and strand its
content. The security keys in the same enum fail *closed*, for the opposite reason.

## Dependencies

`communityTopic` and `communityEvent` live inside `community`
(`Feature::parent()`). `Feature::enabled()` is the unit's own flag **and** every ancestor's, so
switching communities off takes the topic board and events with it whatever their own rows say. The
dependency is resolved in the registry, never re-stated at a call site.

## Enforcement

[`EnsureFeatureEnabled`](../../app/Http/Middleware/EnsureFeatureEnabled.php) answers 404 for a
disabled unit's routes, the answer OpenPNE 3 gave once a plugin's routes stopped existing. The
routes stay **registered** —
[`RouteParityAuditTest`](../../tests/Feature/Compat/RouteParityAuditTest.php) asserts every mapped
route exists — so the middleware, not the route table, is the gate. It is attached in
[`routes/web.php`](../../routes/web.php): to each unit's route group, and individually to the
feature-owned endpoints that live outside their prefix (`member.config.diary`,
`notifications.center.friendAccept` / `friendReject`).

`FeatureRouteMiddlewarePinTest` walks the whole route inventory and fails on a feature-owned route
that is neither gated nor consciously allowlisted, so a route added later cannot dodge the gate.

Deliberately **not** gated: the shared compose preview (its store path gates per feature), blocks,
member profiles, and the `/m/*` compat redirects (their canonical target answers).

## Classic surface

A 404 is the answer of last resort; the Classic screens stop offering the unit in the first place.
The navigation ([`NavigationService`](../../app/Services/NavigationService.php)) resolves each row's
target route and drops the row when that route carries the gate of a switched-off unit — **the
`routes/web.php` wiring is the ownership record, so there is no second list of route names here**,
and an unnamed alias or an out-of-prefix endpoint is covered for free. It also means ownership is
per row, not per nav: the message entry inside a member's local nav goes when messages go, and stays
when friends go. The gadgets do the same through
[`GadgetKind::feature()`](../../app/Gadgets/GadgetKind.php), and the community home, the member
settings nav and the home links ask the registry directly.

Resolution is per request throughout. The nav and gadget row caches never embed feature state, so
switching a unit takes effect without clearing them.

## Modern surface

The Inertia shell shares `enabledFeatures`, the resolved state of every unit
(`Feature::enabledMap()`, so a call site never re-applies the dependency). The nav sections, the
compose action, the dashboard sections and the profile digest read it and stop offering a
switched-off unit.

**Those checks are presentation only.** A disabled unit's data never enters the Inertia payload:
the controllers and the cross-feature aggregate queries skip the work and hand the serializers
empty collections, so an emptied section and a genuinely empty one are indistinguishable in the
JSON — hiding a section in React would otherwise ship the rows it hides. A feature's own queries
carry no check (their routes already answer 404); the constraint sits in the aggregate that reaches
across units — [`ProfileStats`](../../app/Features/Profile/Queries/ProfileStats.php) and
[`JoinedCommunityActivity`](../../app/Features/Home/Queries/JoinedCommunityActivity.php) hold their
own ([feature-modules.md](feature-modules.md#key-invariants) invariant 2), the dashboard and profile
adapters hold the rest. `UnreadCounts` reports a switched-off unit's badge as zero, unqueried.

## Notifications

A switched-off unit sends nothing and shows nothing, and its stored rows are left alone.

**The send gate is `shouldSend()`**, contributed by
[`GatedByFeature`](../../app/Notifications/Concerns/GatedByFeature.php) to every notification that
implements [`FeatureNotification`](../../app/Notifications/FeatureNotification.php) — the one place a
notification names its unit. It cannot live in `via()` or `templateChannels*()`: those run when a
notification is *queued*, and `SendQueuedNotifications` replays the channels decided back then, so a
unit switched off in the meantime would never be re-read there. `NotificationSender` consults
`shouldSend()` immediately before every channel send, queued or not. The broadcast jobs bail early
too, but only to skip the audience walk. Account and security mail (password reset, registration,
the takeover alerts) belongs to no unit and always sends.

**The display filter is the `type` column** — the class each row was written by — applied by
[`VisibleNotifications`](../../app/Features/Notifications/Queries/VisibleNotifications.php) to the
feed, the header center's window and the unread badge count. Not the payload's `kind`: that is a
nullable JSON path, where a row missing the key falls out of both sides of a comparison and
disappears silently. `FeatureNotificationMap` lists the classes, and an architecture test walks the
feature notification namespaces so a class added later cannot skip either half.

Mark-all-read runs through the same filter, so **a hidden row stays unread** and returns exactly as
the member left it. Opening one 404s rather than redirecting to a switched-off unit's screen. The
member's notification settings drop a unit's kinds while it is off (`NotificationCategory::feature()`);
the stored opt-ins stay and apply again when it returns.

## File delivery

A file is fetched by URL and no page mediates it, so
[`FilePolicy`](../../app/Policies/FilePolicy.php) refuses a switched-off unit's bytes where it
resolves the owning entity — otherwise a diary's images would still render while the diary itself
answers 404 ([feature-modules.md](feature-modules.md#key-invariants) invariant 2). A topic or event
names its own unit and the parent chain does the rest. Avatars and banner images belong to no unit
and are never gated.

The admin file monitor reads through its own route (`AdminFileController`, admin guard, no policy),
so an operator keeps moderating a unit they switched off.

## What a disabled unit does not change

Switching `friend` off does **not** collapse `Visibility::Friends`: existing friendships keep
deciding who may read friends-only content, so nothing a member published becomes more visible than
they chose. The friend-scoped feeds and pickers other features own keep working on that same data.

## Not in this layer yet

The upgrade does not yet carry OpenPNE 3's plugin state over, and there is no admin page to switch a
unit from — both land in follow-up changes, and until then the flags move only in the database.
