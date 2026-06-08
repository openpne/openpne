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
