# OpenPNE

OpenPNE is an open-source social networking platform you can self-host. It powers community SNS sites — invitation-only or open registration — with member profiles, diaries, communities, direct messaging, friend relationships, timeline, and more.

This repository is a Laravel 13 reimplementation succeeding the previous version (symfony 1.4 based, see [OpenPNE3](https://github.com/openpne/OpenPNE3)).

## Requirements

- PHP 8.3+
- Composer 2.x
- Node.js 22+ (for the frontend toolchain)

## Getting started

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
npm run build
```

## Development

```bash
php artisan serve         # http://localhost:8000
npm run dev               # Vite dev server on :5173 (HMR)
php artisan test
vendor/bin/pint --test    # lint check (drop --test to auto-format)
npm run type-check        # TypeScript type check
```

## Docker

```bash
docker compose up -d      # http://localhost:8080
```

On first start the `app` container runs `composer install`, generates
`APP_KEY`, and runs migrations; the `vite` sidecar runs `npm ci` and starts
the Vite dev server on `:5173`. Source is bind-mounted so code changes are
reflected without a rebuild. SQLite is used by default.

Notes:

- `vendor/` and `node_modules/` live in named volumes (independent of any
  host install). After updating `composer.lock` or `package-lock.json`, run
  `docker compose down -v` so the volumes are reset and dependencies
  reinstall on the next `up`.
- If port `8080` is taken, set `OPENPNE_HTTP_PORT=18080` before
  `docker compose up -d`. Port `5173` is fixed (Vite always binds it
  inside the container and `public/hot` references that port, so a
  host-side remap would not actually redirect the browser).

## License

Apache License 2.0 — see [LICENSE](LICENSE).
