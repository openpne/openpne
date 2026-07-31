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

## What a disabled unit does not change

Switching `friend` off does **not** collapse `Visibility::Friends`: existing friendships keep
deciding who may read friends-only content, so nothing a member published becomes more visible than
they chose. The friend-scoped feeds and pickers other features own keep working on that same data.

## Not in this layer yet

Notifications and file delivery still reach a disabled unit, and the upgrade does not yet carry
OpenPNE 3's plugin state over. They land in follow-up changes; the admin page is not shipped until
they do.
