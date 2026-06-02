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

The intended call flow:

```text
Controller / FormRequest -> Data -> Action / Query -> model changes or payload
```

## Surface selection

A feature that serves both Classic and Modern registers two route groups: a
**canonical** group (e.g. `/friend/list`) and a `/m/*` **Modern** group whose
routes set `->defaults('surface', 'modern')` and are named `{feature}.modern.{rest}`
(see [`routes/web.php`](../../routes/web.php)). The controller is a single class
per feature; it picks the surface via
[`SurfaceResolver`](../../app/Support/SurfaceResolver.php) rather than branching by
route group:

```php
return $this->respondWith($request, [
    SurfaceResolver::CLASSIC => fn () => view('diary.list', [...]),
    SurfaceResolver::MODERN  => fn () => Inertia::render('diary/list', [...]),
]);
```

`SurfaceResolver::resolve()` decides Classic vs Modern in priority order:

1. the feature's `modern_status` (anything other than `native` forces Classic);
2. an explicit `/m/*` route (`route('surface') === 'modern'`);
3. a per-install `tenant_mode` of `modern_only`;
4. a per-member `migration_ui_override` held in the session;
5. the per-install `tenant_default_surface`.

The selection logic is wired into every dual-surface controller, but most of its
inputs currently fall back to built-in defaults: there is no `config/features.php`
(so `modern_status` defaults to `native`), `config/openpne.php` carries no
`tenant_mode` / `tenant_default_surface` (defaulting to `mixed` / `classic`), and
nothing writes `migration_ui_override` yet. The effective behavior today is
therefore: a canonical route renders Classic, its `/m/*` sibling renders Modern.
The rest of the chain is in place for when those inputs are populated.

`SurfaceResolver::redirectName()` keeps a post-submit redirect on the surface it
came from by mapping `friend.list` ⇄ `friend.modern.list`; `canonicalName()` is
the inverse, so a `/m/*` route that fell back to Classic still resolves its
parity/body-id lookups by canonical name.

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
a Modern status (above).

**Admin** (Filament) may use plain CRUD for simple, non-behavioral master data
(labels, categories, basic config). For SNS-meaningful operations — anything with
a side effect, such as deleting a diary/comment/member, approving a join request,
or member withdrawal — it calls the shared **Action**, so cleanup and side
effects are not bypassed.

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
  reference: it short-circuits when the owner blocks the viewer, then constrains
  `visibility <= clearanceFor(viewer, owner)`.

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
   fetch a broad set and hide forbidden rows in the view.
3. A feature's side effects (mail, unread, file deletion, counter repair) MUST
   originate in an Action — directly, or via an event the Action emits — never in
   a controller, view, or Filament Resource.
4. A model MUST reach the Modern surface through a Serializer, not Eloquent
   `toArray()`, so the exposed columns stay explicit.
5. A `/m/*` route MUST set `->defaults('surface', 'modern')` and be named
   `{feature}.modern.{rest}`, so `SurfaceResolver` redirect/canonical mapping holds.
