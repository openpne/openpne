# OpenPNE

OpenPNE is an open-source social networking platform you can self-host. It powers community SNS sites — invitation-only or open registration — with member profiles, diaries, communities, direct messaging, friend relationships, timeline, and more.

This repository is a Laravel 13 reimplementation succeeding the previous version (symfony 1.4 based, see [OpenPNE3](https://github.com/openpne/OpenPNE3)).

## Getting started

The only requirement is [Docker](https://docs.docker.com/get-started/get-docker/) with Compose:

```bash
bin/dev-up                # http://localhost:8080  (caught mail: http://localhost:8025)
```

On first start the `app` container runs `composer install`, generates
`APP_KEY`, and runs migrations; the `vite` sidecar runs `npm ci` and starts
the Vite dev server on `:5173`. Source is bind-mounted so code changes are
reflected without a rebuild. SQLite is used by default.

Day-to-day commands run inside the containers:

```bash
docker compose exec app php artisan test
docker compose exec app vendor/bin/pint --test    # drop --test to auto-format
docker compose exec vite npm run type-check
```

Notes:

- Containers that write to the source tree run as `OPENPNE_UID:OPENPNE_GID`
  (`bin/dev-up` defaults both to your uid/gid; plain `docker compose` falls
  back to `1000:1000`), so everything they create — `vendor/`,
  `node_modules/`, `storage/`, the SQLite file — stays owned by the host
  user.
- `node_modules/` contains platform-specific binaries, so on macOS avoid
  mixing host `npm` and the Docker path in one checkout: the `vite`
  container reinstalls automatically when the platform changed, but a
  host-side install after that needs a manual `npm ci` too.
- To rebuild frontend assets through Docker, run
  `docker compose run --rm vite npm run build`.
- If port `8080` is taken, set `OPENPNE_HTTP_PORT=18080` before
  `bin/dev-up`. Port `5173` is fixed (Vite always binds it
  inside the container and `public/hot` references that port, so a
  host-side remap would not actually redirect the browser).

## Without Docker

Requires PHP 8.3+, Composer 2.x, and Node.js 26+ on the host:

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate

php artisan serve         # http://localhost:8000
npm run dev               # Vite dev server on :5173 (HMR)
```

## Upgrading from OpenPNE 3

Running an OpenPNE 3 site? [docs/upgrading-from-openpne3.md](docs/upgrading-from-openpne3.md)
migrates its data into a fresh OpenPNE 4 install, with the old site serving throughout.

## License

Apache License 2.0 — see [LICENSE](LICENSE).
