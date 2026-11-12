# Sessions

The member realm and the admin realm keep **separate session stores**: both
logins coexist in one browser, `url.intended` cannot redirect a login across
realms, and either side's logout (`session()->invalidate()`) destroys only its
own store. ("Realm" is this member/admin split — "surface" stays reserved for
the member realm's Classic/Modern presentation.)

| Realm | Guard | Cookie (`config/session.php`) | DB table | Login |
|-------|-------|------------------------------|----------|-------|
| Member (`/`, everything else) | `member` | `session.cookie` (`SESSION_COOKIE`) | `session.table` (`sessions`) | `/login` (Fortify), `/passkeys/login` |
| Admin (`/admin*`, Livewire + Filament system routes) | `admin` | `session.admin_cookie` (`SESSION_ADMIN_COOKIE`) | `session.admin_table` (`admin_sessions`) | `/admin/login` (Filament) |

`/mcp` is a third realm and holds no session at all: it is mounted outside the `web` group, accepts a
bearer token as its only credential, and answers 401 instead of redirecting a guest
([mcp.md](mcp.md)). The OAuth token endpoint, client registration (`/oauth/token`, `/oauth/register`)
and the `/.well-known` metadata sit in that realm too; the consent screen `/oauth/authorize` is a
member page. `UseAdminSessionStore` still runs — it is global — and pinning a store nothing starts
is harmless; a test asserts the response carries no cookie and writes no session row.

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
- the cookie-name prefix of whichever realm cookie is active (below).
- admin responses drop the `XSRF-TOKEN` cookie — one global cookie name, so the
  last responding realm would overwrite the member token and 419 the member
  realm's next Inertia POST. Nothing on the admin realm reads it (Livewire
  sends its page-embedded token).

## Cookie-name prefix

The two realm session cookies and the `XSRF-TOKEN` cookie are all issued under the session config's path, domain and Secure flag,
and all take a prefix the browser enforces, chosen from that config on every
request ([`CookiePrefix`](../../app/Support/CookiePrefix.php)):

| `session.secure` | `session.domain` | `session.path` | Name |
|---|---|---|---|
| off | any | any | the base name |
| on | unset or empty | `/`, unset or empty | `__Host-` + base |
| on | set | any | `__Secure-` + base |
| on | unset or empty | a sub-path | `__Secure-` + base |

`session.secure` is on with an explicit `SESSION_SECURE_COOKIE` or with
`force_https`. A browser accepts a `__Secure-` cookie only with the Secure
attribute over HTTPS, and a `__Host-` cookie only when it also carries no Domain
attribute and Path=/ — so a plain-HTTP host stays unprefixed or login breaks,
and a site that scopes its cookie to a parent domain or a sub-path keeps
`__Secure-`. A `__Host-` cookie belongs to the one host that set it: another
host under the same parent domain cannot plant a cookie of that name for it.

A `__Secure-` cookie carries no such guarantee: on a site that scopes its
cookies to a parent domain or a sub-path, another host under that domain can
still plant an `XSRF-TOKEN` cookie.

The `XSRF-TOKEN` name is the CSRF middleware's
([`PreventRequestForgery`](../../app/Http/Middleware/PreventRequestForgery.php)),
and the Modern shell declares it in `<meta name="xsrf-cookie">` for the JS that
echoes the cookie in `X-XSRF-TOKEN`; Classic and the admin realm send the
page-embedded token instead.

A change of prefix renames the cookies: everyone re-logs in once on the deploy
that brings a session-cookie prefix. A `SESSION_COOKIE` /
`SESSION_ADMIN_COOKIE` an operator has already prefixed (`__Secure-`/`__Host-`)
is passed through unchanged — the operator then owns satisfying the browser's
prefix terms.

## Lifetimes

| Setting | Default | Applies to |
|---|---|---|
| `SESSION_LIFETIME` | 120 | idle minutes of a guest's session, of the admin realm, and of every session on a driver other than `database` |
| `SESSION_MEMBER_LIFETIME` | 43200 (30 days) | idle minutes of a signed-in member's session |
| `SESSION_MEMBER_ABSOLUTE_LIFETIME` | 576000 (400 days) | minutes from sign-in after which a member's session ends however recently it was used |

A member stays signed in through the session alone: the `member` guard issues
no remember-me cookie and ignores one a browser still holds (it answers with a
cookie that clears it). Why the member values are what they are is in
[security.md](security.md#session-lifetime).

- **Idle lifetime is per row.**
  [`RealmDatabaseSessionHandler`](../../app/Session/RealmDatabaseSessionHandler.php)
  replaces the framework's `database` handler and reads `sessions.user_id`: a
  row that names a member is live, and swept, by the member lifetime, any other
  row by `SESSION_LIFETIME`. The handler built for `admin_sessions` carries no
  member lifetime. A member lifetime set below `SESSION_LIFETIME` is raised to
  it, so signing in never shortens a session.
- **The cookies follow.** The session cookie and `XSRF-TOKEN` of a response to a
  signed-in member, outside the admin realm, expire at the member lifetime
  ([`MemberSessionLifetime`](../../app/Session/MemberSessionLifetime.php)). With
  `SESSION_EXPIRE_ON_CLOSE` both keep the framework's expiry.
- **Only the `database` driver.** On any other driver nothing above applies and
  a member's session idles out at `SESSION_LIFETIME`: those drivers cannot end
  one member's sessions, which the long lifetime depends on
  (`App\Auth\SessionRevocation`).
- **The absolute lifetime is the guard's.**
  [`App\Auth\SessionGuard`](../../app/Auth/SessionGuard.php) stamps the session at
  login and, before it resolves a user, drops the login from a session whose
  stamp is older than the limit. The session is not invalidated, so the request
  that finds it expired is answered as a guest's. A session that predates the
  stamp starts its clock at its next request. This check runs on every driver.

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

And the response has to be a page: a successful (2xx) answer that is
`text/html`, or an Inertia page response (`X-Inertia`). A client without Fetch
Metadata passes the first test with an image, a stylesheet, the manifest or a
JSON poll; none passes the second, so none can become the back target. Nor can
an error page — a 404 for a stale icon URL is HTML on the Modern surface, and
the framework's fallback 404 for an unmatched URL is the same case: neither is
somewhere to send a visitor back to. A redirect is not recorded either; the page
it leads to is. The one thing the response half cannot tell apart is an HTML
fragment (the Classic timeline's rows and replies): those are 2xx `text/html`,
and stay out only because their scripts send `X-Requested-With` — a fragment
endpoint fetched without it would be recorded for a client without Fetch
Metadata.

The framework's other guards stand (GET, a matched route, not a prefetch, not
precognitive).

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
6. **`members.last_login_at` names the calendar day of the member's last activity**:
   `App\Listeners\Auth\RecordLastLogin` writes it on every `member`-guard `Login`
   (password, passkey, two-factor, post-registration) and
   again on the first `Authenticated` of each later day (site timezone), because
   a session kept alive by polling never logs in again. A failed write is
   reported, not thrown, so it cannot block a login. The write leaves
   `updated_at` alone; an AI account never authenticates, so its value stays
   NULL. Site operators read it (`MAX(last_login_at)`, the admin member list);
   members see each other's only as a calendar day in member search
   (`App\Features\Member\LastLoginLabel`).
7. **A member's long idle lifetime rests on `sessions.user_id`**, which the
   database handler stamps from the default guard on every write. A member-realm
   request served under another default guard would write the row as a guest's
   and cut the session to `SESSION_LIFETIME`.
