# Feature modules

OpenPNE 4 serves three UI surfaces:

- **Admin** — Filament.
- **Classic** — Blade, OpenPNE 3-compatible surface (see [classic-compatibility.md](classic-compatibility.md)).
- **Modern** — React + Inertia, the long-term product surface.

A feature's business behavior lives **once**, in a shared feature module under
[`app/Features/{Feature}/`](../../app/Features), and each surface is a thin
adapter over it. The same delete, the same visibility rule, and the same side
effect are never re-implemented per surface. The building blocks are:

| Block | Holds | Lives in |
|-------|-------|----------|
| **Action** | a mutation (with its transaction, cleanup, and side effects) | `app/Features/{Feature}/Actions/` |
| **Query** | a read workflow, with visibility constraints baked in | `app/Features/{Feature}/Queries/` |
| **Data** | an explicit input/output shape, when it is shared or non-trivial | `app/Features/{Feature}/Data/` |
| **Serializer** | a stable payload shape for Inertia and a future API | `app/Features/{Feature}/Serializers/` |

A feature also owns its domain exceptions (`Exceptions/`), its events
(`Events/`), and any feature-specific primitive it exposes — e.g.
[`Block/BlockLookup`](../../app/Features/Block/BlockLookup.php),
[`Friend/FriendRequestLock`](../../app/Features/Friend/FriendRequestLock.php). A primitive
shared across features instead lives outside any one feature, e.g.
[`Support/Visibility`](../../app/Support/Visibility.php) (content visibility for diaries and
profiles).

This directory layout is conventional, not enforced (there is no PHP interface a
feature implements, and no architecture test). The **boundaries** below are the
contract; the folders are how we keep them visible.

## Terminology: Serializer, not Resource

A feature's output transformer is a **Serializer**, never a "Resource". Filament
calls its admin CRUD classes "Resources", so reusing the word for feature
serialization collides in the same codebase. A Serializer may internally use a
Laravel `JsonResource`, but the feature folder is `Serializers/`, and in practice
serializers are plain classes with static methods returning explicit array
shapes — see [`FriendSerializer`](../../app/Features/Friend/Serializers/FriendSerializer.php).
A **Filament Resource** is an admin presentation adapter, not a shared contract.

## Boundary rules

- Mutations go in **Actions**; read workflows go in **Queries**.
- An Action receives a **Data object, typed values, models, or IDs — never an
  `Illuminate\Http\Request` or a `FormRequest`**. The FormRequest stays at the
  adapter boundary and converts HTTP input into a Data object or typed arguments.
- Serialization for Inertia / a future API goes in **Serializers**. Eloquent
  models do not cross the network through `toArray()`; they go through a
  serializer so the exposed columns stay explicit.
- Controllers, Blade views, Inertia pages, and Filament Resources stay thin.
- A surface may freely vary route, layout, redirect, and visual components. It
  must **not** fork a feature's rules, side effects, or compatibility behavior
  per surface.

Three of these boundary rules are pinned in CI by
[`FeatureModuleBoundaryTest`](../../tests/Feature/Architecture/FeatureModuleBoundaryTest.php).

The intended call flow:

```text
Controller / FormRequest -> Data -> Action / Query -> model changes or payload
```

## Surface selection

A feature that serves both Classic and Modern registers one **canonical** route
group (e.g. `/friend/list`); URLs carry no surface, and the retired `/m/*` Modern
URL space permanently redirects to the canonical URLs
([`routes/web.php`](../../routes/web.php), guarded by
[`RouteSpaceGuardTest`](../../tests/Feature/Compat/RouteSpaceGuardTest.php)). The
controller is a single class per feature; it picks the surface via
[`SurfaceResolver`](../../app/Support/SurfaceResolver.php):

```php
return $this->respondWith($request, [
    SurfaceResolver::CLASSIC => fn () => view('diary.list', [...]),
    SurfaceResolver::MODERN  => fn () => Inertia::render('diary/list', [...]),
]);
```

`SurfaceResolver::resolve()` decides Classic vs Modern in priority order:

1. the feature's `modern_status` (anything other than `native` forces Classic —
   note this is the one documented exception to "`modern_only` never serves
   Classic"; today it is a dormant seam with no `config/features.php`, revisit the
   interaction if that config materializes);
2. an Inertia navigation (the `X-Inertia` header): it can only originate from the
   Modern SPA, which cannot consume Classic Blade, so it is always served Modern —
   a Modern session sticks across links; a deliberate Modern→Classic handoff (the
   surface picker) goes through `Inertia::location`, a full page load;
3. the install's [`surface_mode`](../../app/Support/SurfaceMode.php) when it is `modern_only` (Classic is not served);
4. a member's **durable** surface choice
   ([`PreferenceKey::PreferredSurface`](../../app/Support/PreferenceKey.php), see
   [member-preferences.md](member-preferences.md));
5. the `surface_mode`'s default surface (`classic_default` → Classic, `modern_default` → Modern).

`surface_mode` is a single [`SurfaceMode`](../../app/Support/SurfaceMode.php) value
(`modern_only` | `classic_default` | `modern_default`) that folds "is Classic served?"
and "which surface is the default?" into one setting. It is **DB-authoritative**:
[`SnsSettingKey::SurfaceMode`](../../app/Support/SnsSettingKey.php) in `sns_settings`,
read through [`SnsSettingService`](../../app/Services/SnsSettingService.php), with
`config('openpne.surface_mode')` as the absent-row fallback only (not a competing env
tier). A fresh install has no row and resolves to the config default; the OpenPNE 3 → 4
upgrade writes a `classic_default` row so a migrated site keeps its Classic look
([`UpgradeRunner`](../../app/Upgrade/Runner/UpgradeRunner.php)), and `openpne:surface-mode`
switches a live site.

The selection logic is wired into every dual-surface controller. There is no
`config/features.php`, so `modern_status` defaults to `native`. Whether a feature is served
**at all** is a separate, DB-authoritative mechanism — the `sns_settings` feature toggles
([feature-toggles.md](feature-toggles.md)) — which does not use this config. The durable member
choice (4) is writable — the member config page sets it — so a member can opt into
either surface persistently. A post-submit redirect targets the canonical route
name; the follow-up GET resolves the surface the same way as any other request.

A feature's **Modern status** is described with four values — `native`,
`fallback`, `island`, `none`. These are a product vocabulary for how far Modern
covers a feature; only `native` vs not-`native` is a code-level branch in the
resolver. The finer distinctions live in the route parity and product notes, not
as a type.

## Surface responsibilities

**Classic** owns OpenPNE 3 URL / HTML / layout / theme / form compatibility and
calls the shared module for behavior. Its OpenPNE 3 `<body id="page_{module}_{action}">`
hook is derived from the route parity, not held in the controller — see
[classic-compatibility.md](classic-compatibility.md).

**Modern** owns the new product experience, mobile UX, Inertia props, and React
components. It does not have to cover every Classic feature at once; each carries
a Modern status (above). Every non-auth Modern page renders inside the default
Inertia layout ([`MemberLayout`](../../resources/js/components/member-layout.tsx),
wired via `createInertiaApp`'s `layout` option in
[`app.tsx`](../../resources/js/app.tsx)): nav chrome plus the page frame
([`MemberFrame`](../../resources/js/components/member-frame.tsx)), which owns the
single `<main>`, the hub header (h1 = nav label, tabs, primary action), and central
flash. Per-section defaults live in the chrome registry
([`lib/member-chrome.ts`](../../resources/js/lib/member-chrome.ts)) — the same
source the nav reads, so nav labels and hub headings cannot drift. The layout
resolves that chrome once and hands the result to both the shell and the frame, so
the mobile (< lg) top bar varies with the page class: brand on the dashboard and on
hubs, where the bottom nav already carries the global links; on a detail page back
plus the registry's `scope` — the community or member the page belongs to, as one
tappable identity block in the brand block's grammar. A form (and any page with no
single scope) shows its context as static centered text instead. The in-page
breadcrumb row is therefore desktop-only.
**A page renders only its content** (no own `<main>`, heading only outside the
registry's hub modes, no FlashMessage — `MemberFrameGuardTest` enforces this);
deviations are registry entries, or
`Page.layout = (props) => ({ chrome: {…} })` for one-offs.
Modern pages build on the shared primitives in
[`components/ui/`](../../resources/js/components/ui) (Button, Input, Field, …) and the
semantic design tokens in [`app.css`](../../resources/css/app.css), not bare controls or
raw Tailwind palette — `RawPaletteGuardTest` enforces the no-raw-palette rule, so a page is
dark-correct and re-themeable by construction.

**Admin** (Filament) may use plain CRUD for simple, non-behavioral master data
(labels, categories, basic config). For SNS-meaningful operations — anything with
a side effect, such as deleting a diary/comment/member, approving a join request,
or member withdrawal — it calls the shared **Action**, so cleanup and side
effects are not bypassed.

The admin panel authorizes through its own `admin` guard (an `AdminUser`, not a
`Member`), so it cannot satisfy a member-actor check. A `Delete*` Action that
gates on the acting member therefore splits in two: `__invoke(Member $actor, …)`
keeps the frontend's author/actor check, and an author-less `purge(…)` core holds
the actual deletion + cleanup. Member adapters call `__invoke`; the Filament
Resource calls `purge` directly (its guard is the authorization). The cleanup
lives once, in `purge`. Member withdrawal is the same shape: `WithdrawMember` has
no per-actor check, and each caller — the panel (its guard authorizes) and the
member-facing withdrawal flow — is its own adapter.

## When to use this contract

Apply it when a feature: appears on both Classic and Modern; is triggered from
both admin and member UI; has an effect beyond a single row; touches files,
notifications, unread state, counters, access control, public visibility, or
OpenPNE 3 compatibility behavior; or needs a validation/query/serialization shape
shared with Inertia or a future API. Do not force the full pattern onto a small
one-off Filament-only setting page.

## Authorization and visibility

Authorization is part of the feature contract, not a view concern.

- **Policies** answer "can this actor perform this operation on this object?" An
  adapter gates a mutation through a Policy or capability check before calling the
  Action; an Action reachable from several surfaces may also assert critical
  capabilities itself.
- **Queries** answer "which objects can this actor see in this workflow?" A read
  Query bakes in its visibility constraints (`public_flag` equivalents,
  friendships, blocks, membership) rather than returning a broad set for the UI to
  filter. [`ListDiaries`](../../app/Features/Diary/Queries/ListDiaries.php) is the
  reference: it delegates to
  [`DiaryVisibilityScope`](../../app/Features/Diary/DiaryVisibilityScope.php), which
  short-circuits when the owner blocks the viewer, then constrains
  `visibility <= clearanceFor(viewer, owner)`.
- **A guest is a viewer too.** Where a feature has a guest-reachable screen, `?Member`
  runs the whole way through the Query and its row-level twin, and the guest threshold is
  `Visibility::Open` **and** the feature's web-public switch — `DiaryVisibility`
  / `TimelineVisibility` `allowsWebPublic()`. The switch belongs in the scope and the
  row check, not in the controller: images and other bytes are fetched by URL through
  [`FilePolicy`](../../app/Policies/FilePolicy.php), which no page mediates, so a
  controller-only gate would leave a published URL readable after the switch went off.

A relation lookup exposed as a feature primitive is **named by direction and
use**, because a block is one-directional. [`BlockLookup`](../../app/Features/Block/BlockLookup.php)
distinguishes the unary boolean by direction:
`ownerBlocksViewer($owner, $viewer)` (one-directional visibility) vs
`hasAnyBlockBetween($a, $b)` (bidirectional interaction gate). A direction-ambiguous
name like `isBlocked` is avoided.

## Side effects

Actions own a feature's side effects. Controllers, views, and Filament Resources
do not independently send mail, update unread state, delete files, or repair
counters.

- Synchronous invariants and compatibility cleanup that must commit atomically run
  inside the Action's `DB::transaction`.
- Cross-feature notifications are **events emitted from the Action**, handled by a
  listener. [`SendFriendRequest`](../../app/Features/Friend/Actions/SendFriendRequest.php)
  dispatches [`FriendRequested`](../../app/Features/Friend/Events/FriendRequested.php)
  (an `ShouldDispatchAfterCommit` event) inside its transaction;
  [`app/Listeners/Friend/NotifyFriendRequested`](../../app/Listeners/Friend/NotifyFriendRequested.php)
  handles the notification. Mail and other slow or retryable work belong behind the
  event/listener (a queued job), never in a controller.

## Testing

Test thickness sits on the shared module, and UI tests stay thin — the same full
behavior is not re-asserted per surface when a shared Action or Query owns the
invariant.

| Layer | What its tests cover | Example |
|-------|----------------------|---------|
| Actions | mutation, transaction, side effects, cleanup | [`tests/Feature/Friend/Actions/`](../../tests/Feature/Friend/Actions) |
| Queries | visibility, filtering, ordering, pagination | [`tests/Feature/Diary/Queries/ListDiariesTest.php`](../../tests/Feature/Diary/Queries/ListDiariesTest.php) |
| Feature primitives | ordering / direction invariants | [`tests/Unit/Support/VisibilityTest.php`](../../tests/Unit/Support/VisibilityTest.php) |
| Listeners | notification dispatched on the event | [`tests/Feature/Friend/Listeners/`](../../tests/Feature/Friend/Listeners) |
| Classic adapter | route compatibility, redirects, key HTML hooks | [`tests/Feature/Diary/Classic/DiaryRoutesTest.php`](../../tests/Feature/Diary/Classic/DiaryRoutesTest.php) |

## Key invariants

1. An Action / Query MUST NOT receive an `Illuminate\Http\Request` or a
   `FormRequest`. Input crosses the adapter boundary as a Data object or typed
   arguments.
2. A read Query MUST embed its own visibility constraints; an adapter MUST NOT
   fetch a broad set and hide forbidden rows in the view. A guest-reachable read
   MUST apply the feature's web-public switch inside the Query and the row-level
   check, never only in the controller.
3. A guest-reachable route MUST still carry `auth.session`: without it a session
   whose password hash is stale keeps a non-null viewer, and every gate on the page
   reads that viewer's clearance (`PublicRouteBoundaryTest`).
4. A feature's side effects (mail, unread, file deletion, counter repair) MUST
   originate in an Action — directly, or via an event the Action emits — never in
   a controller, view, or Filament Resource.
5. A model MUST reach the Modern surface through a Serializer, not Eloquent
   `toArray()`, so the exposed columns stay explicit.
6. A route MUST NOT live under `/m/`, carry a `surface` route default, or use a
   `.modern.` route name — the URL space is canonical-only and the surface is
   resolved per request (`RouteSpaceGuardTest`).
7. A `Delete*` Action that checks the acting member MUST keep the deletion +
   cleanup in an author-less `purge(…)` the admin panel calls; `__invoke` only
   adds the member-actor check. The cleanup is not duplicated in the Resource.
