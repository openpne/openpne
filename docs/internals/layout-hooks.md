# Layout hooks

A layout hook is a fixed position in a shell where a composer package draws HTML. The package's
service provider registers a closure for a position; the shell calls it on every render and writes
what it returns, raw. OpenPNE itself registers nothing, stores nothing for a hook, and gives the
administrator no screen for one: a hook is code, trusted like a view, and whatever it draws is as
present as the shell around it. The operator-editable layer is a different thing — the Classic
HTML insertion slots described in [classic-compatibility.md](classic-compatibility.md),
"Design-customization compatibility".

```php
use App\Support\LayoutHook;
use App\Support\LayoutHooks;

public function boot(): void
{
    $this->app->make(LayoutHooks::class)->register(
        LayoutHook::ClassicSideBannerEnd,
        fn (): ?string => request()->routeIs('login') ? null : view('vendor-package::side-unit')->render(),
    );
}
```

Several registrations on one position render in registration order. A closure may return a string,
an `Htmlable`, or null; it runs once per render with the request available, so anything that varies
by route, viewer or the package's own tables is decided there. A closure that throws is reported and
contributes nothing — the Classic 4xx page is drawn by the same layout, and a failing package must
not turn a 404 into a 500.

## Positions

Every case of [`LayoutHook`](../../app/Support/LayoutHook.php) is emitted by exactly one named
position; a position whose registrations render nothing leaves no markup behind.

Classic ([`layouts/classic.blade.php`](../../resources/views/layouts/classic.blade.php)), every PC
page including the login screen and the 4xx screen:

| Case | Where |
|---|---|
| `ClassicHead` | end of `<head>`, after the operator's `pc_html_head` |
| `ClassicHeaderEnd` | end of `#HeaderContainer`, under the top banner (default skin) or the navbar container (theme) |
| `ClassicLayoutStart` | first child of `#Layout{A..E}`, before the flash boxes |
| `ClassicLeftEnd` | end of `#Left`; only pages with a side menu have the column, as in OpenPNE 3 |
| `ClassicCenterEnd` | end of `#Center`, after the page content |
| `ClassicLayoutEnd` | last child of `#Layout…`, after `#Bottom` |
| `ClassicSideBannerEnd` | end of `#sideBanner`, after the operator's gadgets; the column is drawn when either has content |
| `ClassicFooterEnd` | end of `#FooterContainer` |
| `ClassicBodyEnd` | before `</body>`, after the operator's `pc_html_bottom` |

Modern, two Blade positions in [`app.blade.php`](../../resources/views/app.blade.php) and six the
React shell draws:

| Case | Where | Who sees it |
|---|---|---|
| `ModernHead` | end of `<head>` | everyone, written once per full page load |
| `ModernBodyEnd` | before `</body>` | everyone, once per full page load |
| `ModernLeftNavEnd` | last child of the left `<aside>` ([`left-nav.tsx`](../../resources/js/components/left-nav.tsx)) | lg and wider |
| `ModernMainStart` | inside `<main>`, after the flash, before the page ([`member-frame.tsx`](../../resources/js/components/member-frame.tsx)) | every width, every member-frame page |
| `ModernMainEnd` | inside `<main>`, after the page | every width |
| `ModernRightRailEnd` | last child of the right `<aside>` ([`right-rail.tsx`](../../resources/js/components/right-rail.tsx)); a guest, who has no rail of their own, still gets the column when this renders | xl and wider, looks that have a rail (`unified` has none) |
| `ModernAuthStart` | before the brand block ([`auth-layout.tsx`](../../resources/js/layouts/auth-layout.tsx)), as an `<aside>` | the login, registration and recovery screens |
| `ModernAuthEnd` | after the language switcher, as an `<aside>` | the same screens |

The admin panel is not hooked (Filament has its own render hooks), nor is the 5xx layout, which reads nothing.

## Script nonce

When the host has switched on the operator script policy ([security.md](security.md), "Operator
script policy"), a Classic page runs only scripts that carry the request's nonce. A package reads it
with `Vite::cspNonce()` and writes it on every `<script>` it draws, inline or `src`; the value is
null when the policy is off, and the attribute is then left out. A script that one of those scripts
inserts runs without a nonce of its own; an inline event handler does not run at all.

```blade
<script @if ($nonce = Vite::cspNonce()) nonce="{{ $nonce }}" @endif src="https://loader.example/unit.js" async></script>
```

## Modern

The six React positions reach the client as the shared `layoutHooks` prop: position value to HTML,
non-empty entries only, re-sent on every visit. [`<LayoutHook>`](../../resources/js/components/layout-hook.tsx)
writes the string through `innerHTML` and then re-creates every `<script>` it contained, because a
script that arrives by `innerHTML` never runs; the scripts run again only when the string changes.

Two lifetimes follow from Inertia's persistent layout, which keeps the shell and the frame mounted
while pages change under them:

- `ModernLeftNavEnd` and `ModernRightRailEnd` mount once per full page load, like the two Blade positions.
- `ModernMainStart` and `ModernMainEnd` are keyed on the URL, so a new URL (a query change
  included) gets fresh nodes and its scripts run again; a visit that lands on the same URL — a
  redirect back after a form post, a link to the current page — keeps them. Fresh per URL, not per
  visit. The auth positions mount per auth page, which has no persistent layout.

A persistent position that wants to react to navigation listens for Inertia's `inertia:navigate`
document event in its own script; the shell does not re-run it. The re-created scripts are inserted
dynamically, so they run asynchronously and in no guaranteed order: a queue-based loader works, an
inline script that needs a preceding `src` script finished does not, and `document.write` must not
be used.

## Key invariants

1. A position whose registrations render nothing leaves no wrapper, column or attribute behind;
   the default install's markup is unchanged.
2. OpenPNE gives a hook no setting and no admin screen. What is drawn and when is the registering
   package's code; the operator-editable layer stays the `pc_html_*` slots.
3. Every `LayoutHook` case is emitted at its one named position, and every `<LayoutHook name>` in
   the React shell names a case (`LayoutHookCoverageTest`).
4. A throwing renderer is reported and renders nothing; it never fails the page.
