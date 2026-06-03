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
- `/m/*` is a transition-era, opt-in Modern entry point. It is **not** persisted in
  data, mail, notifications, or external shares; once a feature's Modern is
  promoted to the canonical route, `/m/*` becomes a redirect candidate.

## Response selection

`SurfaceResolver::resolve()` chooses Classic or Modern for a canonical route in
priority order: the feature's `modern_status` → an explicit `/m/*` route → a
per-install `modern_only` mode → a per-member session override → the per-install
default surface. The full chain and the fact that most inputs currently fall back
to built-in defaults are documented in
[feature-modules.md](feature-modules.md#surface-selection); today the effective
behavior is that a canonical route renders Classic and `/m/*` renders Modern.

The root (`/`) is the canonical OpenPNE 3 `member/home`: the same resolver renders the
Classic home or redirects to the Modern dashboard, and it is where login and registration
land. `/member` aliases it. Both are recorded in the [member route
parity](../../app/Compat/Parities/MemberRouteParity.php).

The per-member override (`migration_ui_override`, held in the session) is a
minimal safety valve for the migration period — it lets an individual try Modern,
or fall back to Classic — not a permanent preference system. It has no writer yet.

## Rendering compatibility

The Classic adapter is responsible for reproducing the OpenPNE 3 output hooks that
existing themes and customizations depend on:

- `<body id="page_{module}_{action}">` — derived from the route parity
  ([`RouteParity::bodyId()`](../../app/Compat/RouteParity.php)) and passed to the
  Blade view as `pageId`, so the controller holds no copy and the id stays faithful
  to what OpenPNE 3 emitted.
- `secure_page` / `insecure_page` body classes, `LayoutA`–`LayoutE`, the
  `dparts` / `parts` / `partsHeading` / `body` structure, `localNav`, gadget slots,
  and the `opSkinBasicPlugin` / `opSkinThemePlugin` CSS hooks.

Modern does **not** inherit any of these. It uses its own theme primitives and
Inertia props. Pulling `LayoutA`–`LayoutE` or `dparts` into Modern erodes the
reason Modern exists.

### Default skin

The Classic shell ([`layouts/classic.blade.php`](../../resources/views/layouts/classic.blade.php))
reproduces the base OpenPNE 3 `pc_frontend` layout DOM (`#Body > #Container > #Header /
#Contents / #Footer`, `div#globalNav > ul > li`, `#localNav`, `#Layout{A..E} > #Center`, and the
`alertBox` flash). The OpenPNE 3 default skin (`opSkinBasicPlugin`) is vendored verbatim and
served statically from [`public/opSkinBasicPlugin/`](../../public/opSkinBasicPlugin) through a
plain `<link>`; the `$classicSkinCss` view variable is the seam a later theme / `customizing.css`
resolver swaps. `@vite` is not used for Classic.

Classic forms use the OpenPNE 3 `.form` two-column `<table>` (`<th>` label / `<td>` field), the
`input_text` / `input_file` / `input_submit` classes, the `operation` button area, and the
`.input` / `.publicFlag` float for a profile field's per-value visibility. Single-action
confirmations and the avatar upload stay free-form (no field table).

`#localNav` is OpenPNE 3's secondary nav bar (`#localNav ul.{type} > li`). OpenPNE 3 drives it
from an admin-editable `Navigation` table and a per-module/per-context type (`default` / `friend` /
`community`), shown on secure pages only. Until that data source and the friend/community contexts
exist, the shell renders the shipped `default` set on authenticated pages and leaves the `#localNav`
hook empty for guests. The `#Footer` bar renders `openpne.classic.footer_html` (a trusted-HTML seam
for the future admin SnsConfig footer; `$classicFooterHtml` overrides it per request).

Carried gaps in this slice: the shell renders single-column `LayoutC`, while OpenPNE 3
`member/home` / `member/profile` are `layoutA` — deferred until the `#Left` sidemenu/gadget
slots land (Level 2). The skin's one dead `url(./skin/default/img/marker.gif)` ref (already
broken in OpenPNE 3) and its fixed 950px width are kept as-is. Theme switching, admin custom
CSS, and gadget layout are not ported. `#localNav` carries only the shipped `default` set, not the
admin `Navigation` data or the `friend` / `community` type contexts, and its `li` ids are not the
OpenPNE 3 `{type}_{op_url_to_id(uri)}` strings (Level 2). The footer omits the privacy-policy /
terms links until those routes exist.

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

Modern does not apply the same CSS/HTML. It offers its own migration targets
(logo, primary color, header image, footer/free area, a scoped safe-HTML slot,
navigation settings). The admin UI MUST label which scope a setting affects so an
operator is never misled that a Classic-only custom CSS applies to Modern:

```text
classic only:  OpenPNE 3-compatible custom CSS / HTML insertion / legacy gadget layout
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
  preserved on the field today; the SMT emoji JS that keys off it is handled
  separately in the JS migration.

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

- `/m/*` appearing in a saved URL, mail template, or notification payload.
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
   canonical URL, never a `/m/*` URL.
3. The Classic `<body id>` MUST come from the route parity, not a copy in the
   controller, so it stays faithful to OpenPNE 3's `page_{module}_{action}`.
4. Modern MUST NOT inherit Classic's HTML-compatibility structures
   (`LayoutA`–`LayoutE`, `dparts`, opSkin hooks) or load legacy JS.
5. A Level 1 compatibility item MUST be detectable by an invariant test, the route
   parity, or a customization smoke check; a dropped Level 2/3 item MUST be recorded
   with its reason.
