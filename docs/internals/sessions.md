# Sessions

The member realm and the admin realm keep **separate session stores**: both
logins coexist in one browser, `url.intended` cannot redirect a login across
realms, and either side's logout (`session()->invalidate()`) destroys only its
own store. ("Realm" is this member/admin split — "surface" stays reserved for
the member realm's Classic/Modern presentation.)

| Realm | Guard | Cookie (`config/session.php`) | DB table | Login |
|-------|-------|------------------------------|----------|-------|
| Member (`/`, everything else) | `member` | `session.cookie` (`SESSION_COOKIE`) | `session.table` (`sessions`) | `/login` (Fortify) |
| Admin (`/admin*`, Livewire + Filament system routes) | `admin` | `session.admin_cookie` (`SESSION_ADMIN_COOKIE`) | `session.admin_table` (`admin_sessions`) | `/admin/login` (Filament) |

`/mcp` is a third realm and holds no session at all: it is mounted outside the `web` group, accepts a
bearer token as its only credential, and answers 401 instead of redirecting a guest
([mcp.md](mcp.md)). `UseAdminSessionStore` still runs — it is global — and pinning a store nothing
starts is harmless; a test asserts the response carries no cookie and writes no session row.

The switch is [`UseAdminSessionStore`](../../app/Http/Middleware/UseAdminSessionStore.php),
**global** middleware keyed by request path. It cannot live in the Filament
panel stack: after the initial page load every panel interaction is a Livewire
update/upload request served by the `web` group's `StartSession`, and Livewire's
persistent middleware re-applies only after the session is already resolved.
Per request it pins, both ways:

- `session.cookie` + `session.table` — read lazily by the session manager at
  driver-build time, so the config pin lands before `StartSession`.
- the default auth guard (`Auth::shouldUse`) — the database session handler
  stamps `sessions.user_id` from the default guard, so admin-realm rows carry
  `admin_users` ids even on requests where Filament's own `Authenticate` never
  runs (login-screen Livewire updates, uploads, the locale switch).
- the `__Secure-` cookie-name prefix — added to whichever realm cookie is
  active, but only when `session.secure` is on (explicit `SESSION_SECURE_COOKIE`
  or `force_https`); a browser rejects a `__Secure-` cookie that is not Secure
  over HTTPS, so a plain-HTTP host must stay unprefixed or login breaks. The
  prefix is not applied to the `XSRF-TOKEN` cookie (read by name from JS) or the
  remember-me cookies (guard-named); those keep their Secure flag from
  `session.secure` but no prefix.
  A site already on HTTPS renames its session cookie on deploy, so everyone
  re-logs in once. A `SESSION_COOKIE` / `SESSION_ADMIN_COOKIE` an operator has
  already prefixed (`__Secure-`/`__Host-`) is passed through unchanged — the
  operator then owns satisfying the browser's prefix invariants.
- admin responses drop the `XSRF-TOKEN` cookie — one global cookie name, so the
  last responding realm would overwrite the member token and 419 the member
  realm's next Inertia POST. Nothing on the admin realm reads it (Livewire
  sends its page-embedded token).

## The previous URL

`redirect()->back()` — which is how a validation error and a failed login return
to their form — goes to the URL the session recorded last. The framework records
every routed GET that is not an XHR, which gets this wrong both ways:

- it **records subresources**, the cookie-bearing ones a page loads for itself
  (the brand mark on the sign-in screen, an avatar, a polling fetch). Whichever
  loads last would become the back target, so the visitor lands on an image or a
  JSON endpoint and never sees the error — the Inertia client is handed a
  response it cannot render.
- it **drops client-side page visits**, which are XHRs that are also navigations
  (Inertia's client sends `X-Requested-With` alongside `X-Inertia`). back() would
  then reach past them for whatever full page load came before.

[`App\Http\Middleware\StartSession`](../../app/Http/Middleware/StartSession.php)
asks instead whether the request is a page the visitor is on — a question with a
request half and a response half. The request has to be a navigation:

| `Sec-Fetch-Dest` | Navigation |
|---|---|
| `document` | yes — an ordinary navigation |
| `empty` with `X-Inertia` / `X-Livewire-Navigate` | yes — a client-side visit |
| `empty` otherwise, `image`, `style`, `script`, `font`, `manifest`, … | no |
| absent | the framework's rule: yes unless the request is an XHR |

And the response has to be a page: `text/html`, or an Inertia page response
(`X-Inertia`). A client without Fetch Metadata passes the first test with an
image, a stylesheet, the manifest or a JSON poll; none passes the second, so
none can become the back target.

The framework's other guards stand (GET, a matched route, not a prefetch, not
precognitive), and a fallback match is never recorded whatever it answered: it
is a 404 for an unmatched URL, so it is not somewhere to send a visitor back to.

It is swapped in by container binding (`AppServiceProvider`), not by replacing the
class in the `web` group, so the middleware priority list still finds the session
middleware under the framework's name. The framework does not hand the response
to the store, so the request is wrapped to keep it.

## Key invariants

1. **The admin realm is exactly**: `admin`, `admin/*`, the Livewire endpoint
   prefix (`EndpointResolver::prefix()`, APP_KEY-derived), and the Filament
   system route prefix (`filament/*` — export/import downloads). One predicate,
   [`AdminRealm::matches()`](../../app/Support/AdminRealm.php), pinned against the
   real routes by `AdminSessionStoreTest`.
2. **Livewire renders only on the admin realm** (no components outside
   `app/Filament`, architecture-test enforced). A member-realm Livewire
   component would run on the admin session store — extend the realm
   predicate before introducing one.
3. **Session purges are per-realm and go through `App\Auth\SessionRevocation`**,
   which reads the stable `session.member_table` / `session.admin_table` keys.
   Never purge via `config('session.table')`: that key is pinned to the serving
   realm, so an admin-realm action revoking a member (a ban) would target the
   wrong table.
4. One process serves one request (FPM). The store/guard pin mutates config, so
   a long-lived runtime (Octane) would need per-request driver resets; tests
   simulate fresh workers with `TestCase::freshRequestState()`.
5. Cookie separation works on **every session driver** (stores are keyed by
   cookie name / session id); `session.admin_table` only matters to the
   `database` driver. GC sweeps each table on its own realm's traffic, so
   stale `admin_sessions` rows may linger between admin visits — lifetime is
   still enforced on read.

A per-realm idle lifetime (shorter admin sessions) would slot into the same
middleware pin.
