# Classic compatibility

The Classic surface is not "the old UI". It is the compatibility contract that
lets an existing OpenPNE 3 site move to OpenPNE 4 without breaking its members'
links, its rendered HTML, or its admin-configured look. Three things are
guarded:

- URL compatibility
- rendered HTML / CSS / JS compatibility
- admin design-customization compatibility

Modern is the long-term product surface. Classic compatibility must never become
a design constraint on Modern: Classic is a compatibility runtime, Modern is the
future experience. How the two share business logic is in
[feature-modules.md](feature-modules.md); this document is about what the Classic
adapter must preserve.

## URLs are canonical, not Classic-only

An OpenPNE 3 URL is treated as an OpenPNE 4 **canonical** URL candidate, not as a
"Classic URL". A canonical route (`/diary/123`) is served as Classic or Modern by
[`SurfaceResolver`](../../app/Support/SurfaceResolver.php); the URL itself does not
change when the surface does.

- A canonical route SHOULD reuse the OpenPNE 3 URL.
- When product/security/HTTP semantics justify a new canonical URL, the OpenPNE 3
  URL is kept as a **compatibility URL** that redirects (301 when the move is
  permanent, 302 when a future module may reclaim the URL) or resolves to the same
  content, and the canonical↔legacy relation is recorded in the route parity. See [`RouteParity::compatRedirects()`](../../app/Compat/RouteParity.php) —
  e.g. the OpenPNE 3 access-block URL `/member/config?category=accessBlock` 302s to
  the canonical `block.list` ([`routes/web.php`](../../routes/web.php)).
- Persisted URLs — saved links, mail, notifications — use the canonical URL.
- `/m/*` — the transition-era Modern URL space — is retired. The whole prefix
  permanently redirects (308) to the canonical URL: a prefix-stripping catch-all
  (`compat.m_prefix`) plus explicit redirects for the retired RESTful community GET
  shapes ([`routes/web.php`](../../routes/web.php)). No code emits a `/m/` URL;
  [`MPrefixLiteralGuardTest`](../../tests/Feature/Frontend/MPrefixLiteralGuardTest.php)
  fails on any quoted `/m/` literal outside those redirects.

## Response selection

`SurfaceResolver::resolve()` chooses Classic or Modern for a canonical route in
priority order: the feature's `modern_status` → an Inertia navigation (the
`X-Inertia` header: it can only come from the Modern SPA, which cannot consume
Classic Blade, so it is always served Modern) → the install's
[`surface_mode`](../../app/Support/SurfaceMode.php) when `modern_only` → a member's
durable surface choice → the `surface_mode` default surface. The URL carries no
surface. The full chain is documented in
[feature-modules.md](feature-modules.md#surface-selection). `surface_mode` is
DB-authoritative (`SnsSettingKey::SurfaceMode`, config as the absent-row fallback): a
fresh install resolves to the config default — `modern_only`, since a new site has no
Classic heritage — while the OpenPNE 3 → 4 upgrade writes a `classic_default` row so a
migrated site keeps its Classic look.

Under `modern_only` the Classic surface is never served, so its operator configuration
(the "Appearance (Classic)" admin settings) and the member config page's surface picker
are hidden — no one is shown a Classic option they cannot use.

The root (`/`) is the canonical OpenPNE 3 `member/home`: the same resolver renders the
Classic home or redirects to the Modern dashboard, and it is where login and registration
land. `/member` aliases it. Both are recorded in the [member route
parity](../../app/Compat/Parities/MemberRouteParity.php).

## Rendering compatibility

The Classic adapter is responsible for reproducing the OpenPNE 3 output hooks that
existing themes and customizations depend on:

- `<body id="page_{module}_{action}">` — derived from the route parity
  ([`RouteParity::bodyId()`](../../app/Compat/RouteParity.php)) and passed to the
  Blade view as `pageId`, so the controller holds no copy and the id stays faithful
  to what OpenPNE 3 emitted.
- `secure_page` / `insecure_page` body classes, `LayoutA`–`LayoutE`, `localNav`, gadget
  slots, and the `opSkinBasicPlugin` / `opSkinThemePlugin` CSS hooks.
- The parts frame, reproducing OpenPNE 3's `_partsLayout.php`:
  [`x-classic.parts`](../../resources/views/components/classic/parts.blade.php) owns it — new
  page parts must not hand-write the nesting. The already-faithful hand-written boxes that
  predate it are frozen as a locked set by
  [`ClassicPartsFrameGuardTest`](../../tests/Unit/Views/ClassicPartsFrameGuardTest.php).
  It emits `.dparts > .parts > .partsHeading`, collapsing to a lone `.parts` for the kinds whose
  OpenPNE 3 body partial forced it (`informationBox`, `line`, `memberImageBox`,
  `searchFormLine`). Body markup stays with the caller because it is per-kind:
  `box` / `descriptionBox` / `informationBox` wrap it in `.body`; `listBox` / `alertBox` put a
  `<table>` under `.parts` and `form` puts `<form><table>` there — none use a `.body` wrapper,
  and `div.parts table` draws the grid border either way. Confirm screens have no one shape in
  OpenPNE 3 — `yesNo` (community delete / drop member), the `form` kind (community join / quit,
  topic and event delete), or a hand-written `box` holding a `.block` (diary / message delete) —
  so each reproduces the one its own OpenPNE 3 screen used, and none of them uses `.body`. In the
  `form` kind, `.block` appears only when OpenPNE 3 passed the `body` option (join / quit); its
  delete confirms passed none, so their question text is an OpenPNE 4 addition in bare markup.
- The `#Layout{A..C}` letter — OpenPNE 3's `setLayout` / `view.yml` / `decorate_with` choice — is
  resolved per screen by [`RouteParity::layouts()`](../../app/Compat/RouteParity.php) through the
  `classic_layout()` helper, defaulting to OpenPNE 3's global `layoutC`; gadget pages (home /
  profile / login) instead pass the admin-configured layout's letter. Letter and columns are coupled
  by the skin CSS — `#Left` floats only under `LayoutA` (270px) / `LayoutB` (175px) — so a
  two-column screen must declare `A`/`B`; a `RouteParityLayoutTest` tripwire fires when a new
  Classic view with a `sidemenu` / `top` section forgets to.

Modern does **not** inherit any of these. It uses its own theme primitives and
Inertia props. Pulling `LayoutA`–`LayoutE` or `dparts` into Modern erodes the
reason Modern exists.

### Default skin

The Classic shell ([`layouts/classic.blade.php`](../../resources/views/layouts/classic.blade.php))
reproduces the base OpenPNE 3 `pc_frontend` layout DOM (`#Body > #Container > #Header /
#Contents / #Footer`, `div#globalNav > ul > li`, `#localNav`, `#Layout{A..E} > #Center`, and the
`alertBox` flash). The OpenPNE 3 default skin (`opSkinBasicPlugin`) is vendored verbatim and
served statically from [`public/opSkinBasicPlugin/`](../../public/opSkinBasicPlugin) through a
plain `<link>`; the `$classicSkinCss` view variable overrides which skin stylesheet is linked. Admin
custom CSS is linked after it as its own `text/css` document
([`/cache/css/customizing.css`](../../app/Http/Controllers/CustomizingCssController.php), OpenPNE 3
parity) rather than inlined, so `@charset` / `@import` / relative `url(...)` keep stylesheet
semantics. `@vite` is not used for Classic.

An unset member/community image falls back to the OpenPNE 3 `no_image.gif`, vendored at
[`public/images/no_image.gif`](../../public/images/no_image.gif) and rendered through the shared
[`x-classic.image`](../../resources/views/components/classic/image.blade.php) component (Modern has
its own placeholder).

Classic forms use the OpenPNE 3 `.form` two-column `<table>` (`<th>` label / `<td>` field), the
`input_text` / `input_file` / `input_submit` classes, the `operation` button area, and the
`.input` / `.publicFlag` float for a profile field's per-value visibility. Single-action
confirmations and the avatar upload stay free-form (no field table).

`#globalNav` and `#localNav` are OpenPNE 3's primary and secondary nav bars, both driven from the
admin-editable `navigations` table (`App\Services\NavigationService`), keyed by type:
`secure_global` / `insecure_global` for `#globalNav`, `default` / `friend` / `community` for
`#localNav` (secure pages only). `#localNav` renders the `community` set on a community page (its
Top / Topics / Events / Join / Leave links), the `friend` set (the subject member's id-scoped links)
on a page about another member, and the `default` set on the viewer's own pages — switching on the
community / subject a controller records via `Controller::markLocalNavCommunity` /
`markLocalNavSubject` (OpenPNE 3 `sf_nav_type`/`sf_nav_id`), community winning as its module's
default_nav does; guests get an empty hook. The table is seeded with OpenPNE 3's default set (an admin
editor and the upgrade tool populate it); navigation settings are Classic-only — Modern's nav
is component-driven, not data-driven from this table. A stored `uri` is a normalized internal path
(with an optional `:id` slot threaded with the context id, else `?id=` appended) or an http(s) URL;
the renderer hides any item whose path matches no route or an OpenPNE 3 compatibility shim, and
renders a logout-style item (GET-unreachable in OpenPNE 4) as a POST form button. The `<li>` id is
`{prefix}_{op_url_to_id(source_uri)}` — `source_uri` keeps the original OpenPNE 3 value so a site's
custom CSS keeps matching after the upgrade normalizes `uri`. The `#Footer` bar renders the admin
`footer_before` / `footer_after` setting, chosen by the page's `secure_page` / `insecure_page` class
(OpenPNE 3 `isSecurePage`); `$classicFooterHtml` overrides it per request.

Carried gaps in this slice: the skin's one dead `url(./skin/default/img/marker.gif)` ref (already
broken in OpenPNE 3) and its fixed 950px width are kept as-is; there is a single static skin (no
theme switching); the footer has no privacy-policy / terms links (no such routes exist); the
`#SmtSwitch` smartphone-view toggle is not ported (OpenPNE 4 has no separate smartphone frontend
to switch to).

## JavaScript compatibility

Legacy OpenPNE 3 JavaScript is Classic-only and ported only where a real
customization depends on it. Modern never loads legacy jQuery / `tiny_mce` and
similar. Classic does not aim for unconditional byte-for-byte JS reproduction: if
the HTML/CSS hooks are present and a server-rendered or lightweight alternative
keeps existing customizations working, the difference is acceptable and recorded
as a gap (below).

## Design-customization compatibility

OpenPNE 3 admin design customizations are reproduced in Classic as far as
possible: active skin/theme, custom CSS, PC HTML insertion slots, footer HTML,
banners, gadgets, layout and navigation settings.

Custom CSS, the PC HTML insertion slots (`pc_html_head` / `top2` / `top` / `bottom2` / `bottom`) and
the footer (`footer_before` / `footer_after`) are stored in `sns_settings` (the `Design`
[`SnsSettingKey`](../../app/Support/SnsSettingKey.php) group), edited on the admin
[`DesignSettings`](../../app/Filament/Pages/DesignSettings.php) page, carried over verbatim by
[`SnsSettingUpgrade`](../../app/Upgrade/Steps/SnsSettingUpgrade.php), and emitted raw as trusted
operator HTML/CSS — stored without trimming so a stylesheet's leading `@charset` survives.

A banner (OpenPNE 3 `op_banner`) shows operator HTML or one of a pool of images at random, by login
state. The top placements render in `#topBanner` above the content (`top_after` when signed in, else
`top_before`); the side placements (`side_after` / `side_before`) are emitted bare by the `sideBanner`
gadget in the PC side column (no wrapper — the `#sideBanner` column is itself the gadget zone). Images
are uploaded and edited in the [`BannerImages`](../../app/Filament/Resources/BannerImages/BannerImageResource.php)
resource and served publicly through [`BannerImageController`](../../app/Http/Controllers/BannerImageController.php)
(banners show to guests, so unlike other files they are not auth-gated); each placement's mode, the
images it shows (picked from that shared pool), and any HTML are set on the
[`Banner`](../../app/Filament/Pages/BannerSettings.php) page.

Modern does not apply the same CSS/HTML. It offers its own migration targets
(logo, primary color, header image, footer/free area, a scoped safe-HTML slot).
Its navigation is component-driven, so the `navigations` table is Classic-only.
The admin UI MUST label which scope a setting affects so an operator is never
misled that a Classic-only custom CSS applies to Modern:

```text
classic only:  OpenPNE 3-compatible custom CSS / HTML insertion / legacy gadget layout / navigation menu settings
modern only:   Modern logo / color / header image / modern layout
both:          SNS name / terms / basic navigation labels / policy URLs
```

## Compatibility levels

Classic compatibility is judged per item, not as uniform "exact reproduction".

- **Level 1 — Must preserve.** Directly affects whether migration succeeds: an
  OpenPNE 3 URL resolving as canonical or compatibility URL; redirect/parity record
  for any moved canonical URL; primary flows (login, registration, password reset,
  withdrawal); existing-content view URLs; the layout/id/class a theme's CSS
  depends on; configured custom CSS / HTML insertion / banner / gadget; URLs in
  mail and notifications. Level 1 is kept detectable by an invariant test, the route
  parity, or a customization smoke check.
- **Level 2 — Should preserve.** Existing customizations may break: fine-grained
  `parts` structure, pager/list/form class names, plugin CSS loading,
  page-specific body ids. Not preserving one is recorded as a gap with its reason
  and impact.
- **Level 3 — May differ.** Acceptable when functionally equivalent: internal
  controller structure, exact validation wording, JS implementation, minor HTML
  nesting. **OpenPNE 3 form field names are not preserved.** OpenPNE 4 introduces
  fields with no OpenPNE 3 counterpart (e.g. a diary's visibility, which derives
  from OpenPNE 3's `public_flag` with a different value set), so matching the old
  field names would require per-form input translation, while the compatibility
  hooks most likely to affect existing customizations are ids, classes, layout
  structure, URLs, and configured HTML/CSS. The OpenPNE 3 core and the bundled
  plugins checked during this port do not key JS/CSS off field *names*; a theme, a
  plugin from another version, or an admin-panel customization still could, and
  such a case is treated as a Level 2 gap if it surfaces. The `#diary_body` *id* is
  preserved on the field.

Record a Level 2/3 gap with a short reason in the feature's parity or gap notes. A
gap promotes to Level 2 if a real theme or customization is found to depend on it.

## Audit

Classic compatibility is not eyeballed. The machine-checked check that exists today
is the **route parity**: `php artisan openpne:route-parity` renders the OpenPNE 3 ↔
Laravel route mapping from the typed [`App\Compat\Parities`](../../app/Compat/Parities)
classes, which the test suite binds to the real routes (a renamed/removed route
fails CI) and to the OpenPNE 3 route inventory (an un-ported route surfaces rather
than being silently dropped). Feature parity, HTML-skeleton parity, asset parity,
and customization smoke are added as the Classic surface grows.

Some red flags are grep-able and belong in review or a static check:

- any quoted `/m/` URL literal (now guard-tested by `MPrefixLiteralGuardTest`, not
  a review item).
- Modern code importing/including `dparts`, `LayoutA`–`LayoutE`, or opSkin hooks.
- Business logic or side effects written directly in a Classic or Modern controller
  instead of the shared Action/Query.

## Relationship to feature modules

The Classic adapter carries compatibility (canonical URL, OpenPNE 3 HTML structure,
CSS/JS/customization); it holds no business logic. Both the Classic and Modern
adapters call the same feature module. Where the boundary sits — Data in, Serializer
out, side effects in Actions — is defined in [feature-modules.md](feature-modules.md).
Presentation compatibility that the adapter owns (legacy redirect targets, template
variables, HTML id/class hooks, OpenPNE 3-equivalent flash/validation keys) stays in
the adapter; the domain rule behind it stays in the feature module.

## Key invariants

1. An OpenPNE 3 URL MUST remain reachable — as the canonical URL, or as a
   compatibility URL that redirects/resolves to the same content with the relation
   recorded in the route parity.
2. A persisted or outbound URL (saved link, mail, notification) MUST be the
   canonical URL, never a `/m/*` URL — structural now that no route or emitter
   carries the prefix (`RouteSpaceGuardTest`, `MPrefixLiteralGuardTest`).
3. The Classic `<body id>` MUST come from the route parity, not a copy in the
   controller, so it stays faithful to OpenPNE 3's `page_{module}_{action}`.
4. Modern MUST NOT inherit Classic's HTML-compatibility structures
   (`LayoutA`–`LayoutE`, `dparts`, opSkin hooks) or load legacy JS.
5. A Level 1 compatibility item MUST be detectable by an invariant test, the route
   parity, or a customization smoke check; a dropped Level 2/3 item MUST be recorded
   with its reason.
