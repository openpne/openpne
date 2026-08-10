# Runtime

Some deployments keep an application's secrets and writable runtime state
outside its code checkout — for example when the checkout is a read-only,
immutable release. By default the application reads its `.env` from the project
root and writes to the in-project `storage/` directory, but two process
environment variables, read during startup (see
[`bootstrap/app.php`](../../bootstrap/app.php)), let a deployer relocate either:

| Variable | Relocates | Laravel call |
|----------|-----------|--------------|
| `OPENPNE_ENV_PATH` | the directory the `.env` file is loaded from | `useEnvironmentPath()` |
| `LARAVEL_STORAGE_PATH` | the `storage/` directory | `useStoragePath()` |

Set them in the process manager, web server, or container environment — both
are read with `getenv()` before the app boots. When neither is set the
application uses its default in-project paths and behaves identically to a
stock install.

## Key invariants

1. With `OPENPNE_ENV_PATH` and `LARAVEL_STORAGE_PATH` unset, the hook is a no-op:
   it only relocates paths, never changes behavior.
2. `OPENPNE_ENV_PATH` MUST NOT be set inside `.env` — it is resolved before the
   `.env` file is loaded and is what tells the framework where that file lives.

## Site timezone

`APP_TIMEZONE` (IANA name, default `UTC`) is the site's single clock. There is no
per-member timezone: stored wall-clock time and displayed wall-clock time are the
same value read in the same zone, on both surfaces.

That makes it a **deployment-time setting, not a runtime one**. Changing it on a
live site does not re-render existing rows into the new zone — it reinterprets
them, so every timestamp already stored silently shifts meaning. Set it before
the first write and treat a later change as a data migration.

| Variable | Effect |
|----------|--------|
| `APP_TIMEZONE` | The zone every timestamp is written and displayed in. Must be a canonical IANA name — aliases like `Etc/UTC`, `GMT` and `Japan` are rejected, so use `UTC` / `Asia/Tokyo`. Validated at boot (`App\Support\SiteTimezone`) because `date_default_timezone_set` only warns on a name it cannot use, leaving the previous zone silently in effect. |

Key invariants:

1. **The application stamps its own timestamps.** Four tables default
   `created_at` to the database clock (`useCurrent()`): `friend_requests`,
   `friendships`, `member_blocks`, `community_join_requests`. No connection
   timezone is configured, so that clock is UTC on SQLite and the server's zone on
   MySQL — a row the app did not stamp puts a second clock in one column. Every
   write path passes `now()` explicitly; the default remains only for raw SQL and
   the upgrade importer.
2. **The client formats in the site's zone, not the browser's.** Instants are
   serialized as offset-bearing ISO and the zone travels with them as the
   `timezone` shared prop, so Modern places them on the same clock Classic renders
   (`resources/js/lib/date.ts`). Formatting with the browser's zone is what made
   the two surfaces disagree by the viewer's offset. `Intl` and `toLocale*` are
   restricted to that module by eslint, so a new call site cannot reintroduce the
   drift; Modern renders instants through `<Timestamp>` / `<CivilDate>`, which bind
   the site's zone and locale and put the machine-readable value in `dateTime`.
   Where the display drops information — a day standing in for an instant — the
   element also carries the exact value as a native `title`. That title is a
   **mouse-only convenience and must stay non-essential**: `title` on a
   non-interactive element reaches neither the keyboard nor assistive technology, so
   anything a reader needs in order to act belongs in the visible text or `dateTime`.
   A display whose visible text stops naming the date at all needs a real
   disclosure, not a title.
3. **Instants and civil dates are different types.** An event's open date is a
   `Y-m-d` calendar day with no instant attached; reading one as an instant shifts
   it a day for viewers west of UTC. The client has separate formatters and will
   render a misrouted value verbatim rather than shift it.

### Upgrading from OpenPNE 3

OpenPNE 3 stores `DATETIME` as its server's wall clock, and the upgrade copies
those values through unchanged. So `APP_TIMEZONE` must already name the zone that
OpenPNE 3 ran in **before** the upgrade runs — otherwise every migrated timestamp
is off by the difference.

There is deliberately no migration that shifts existing rows. Once a database
holds both migrated OpenPNE 3 wall-clock values and rows OpenPNE 4 wrote under a
different zone, the values alone cannot say which is which, and a blanket shift
would corrupt one of the two sets. Set the zone first; a database that already
mixed them needs a re-import, not a conversion.

## Reverse proxy & HTTPS

The app almost always runs behind a reverse proxy (the fleet edge, or a
self-hoster's nginx/Caddy/Cloudflare). Two settings make it see the real client
instead of the proxy; both are wired in [`bootstrap/app.php`](../../bootstrap/app.php)
and `config/openpne.php`:

| Variable | Effect |
|----------|--------|
| `TRUSTED_PROXIES` | Proxy IP/CIDR list (comma-separated) or `*`. Makes `$request->ip()` and the HTTPS check read `X-Forwarded-For`/`-Proto`. Empty = trust none. |
| `OPENPNE_FORCE_HTTPS` | Force `https://` URL generation + a `Secure` session cookie. Defaults on when `APP_ENV=production`. |

Why this matters, not just hardening:

1. **Rate limits bind to the real client.** The `login` and `register-email`
   limiters key on `$request->ip()`. With no trusted proxy that is the proxy's
   address, so every client shares one bucket and the per-IP limit silently
   stops working. `TRUSTED_PROXIES` must name the proxy for the limiters to mean
   anything in production.
2. **Generated links stay HTTPS and on the right host.** `OPENPNE_FORCE_HTTPS`
   keeps password-reset/registration links `https://` even when TLS terminates
   upstream; `trustHosts()` (pinned to `APP_URL`, enforced outside local/testing)
   rejects a forged `Host` so it cannot poison those links. `X-Forwarded-Host`
   is intentionally **not** trusted — the validated `Host` is authoritative.

The deployment side (nginx `real_ip` / passing `X-Forwarded-*`) is the
operator's/hosting layer's responsibility; this app only consumes the headers
once `TRUSTED_PROXIES` says the proxy may set them.

## Scheduled tasks

A deployment must run Laravel's scheduler — `php artisan schedule:run` every
minute from cron (or a systemd timer) — or scheduled work silently never runs.
Currently that is the daily prune of expired pending tokens — registration
links and email-change links ([`routes/console.php`](../../routes/console.php));
without the scheduler those rows accumulate. `php artisan schedule:list` shows
what is registered.
