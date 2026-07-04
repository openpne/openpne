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
- admin responses drop the `XSRF-TOKEN` cookie — one global cookie name, so the
  last responding realm would overwrite the member token and 419 the member
  realm's next Inertia POST. Nothing on the admin realm reads it (Livewire
  sends its page-embedded token).

## Key invariants

1. **The admin realm is exactly**: `admin`, `admin/*`, the Livewire endpoint
   prefix (`EndpointResolver::prefix()`, APP_KEY-derived), and the Filament
   system route prefix (`filament/*` — export/import downloads). Pinned against
   the real routes by `AdminSessionStoreTest`.
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
