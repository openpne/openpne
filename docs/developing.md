# Developing

`bin/dev-up`, which the README's [installation](../README.md#to-try-or-develop-docker-compose)
starts a site with, is the development stack. On first start the `app` container runs
`composer install`, creates `.env` with `APP_URL` set to the stack's address, generates `APP_KEY`,
and runs migrations; the `vite` sidecar runs `npm ci` and starts the Vite dev server on `:5173`.
Source is bind-mounted so code changes are reflected without a rebuild.

Day-to-day commands run inside the containers:

```bash
docker compose exec app php artisan test --parallel
docker compose exec app vendor/bin/pint --test    # drop --test to auto-format
docker compose exec vite npm run type-check
```

A change under `resources/js` is checked by the frontend lane, by `i18n:check`
and by PHP tests that read those files, so run them at once before pushing (host
toolchain, as it needs npm and php together; the production build is not part of
it):

```bash
composer test-frontend
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
- The stack runs an [imgproxy](https://imgproxy.net) sidecar; set
  `OPENPNE_IMAGE_PROCESSOR=imgproxy` in `.env` to process pictures through it
  instead of GD (`docs/internals/images.md`, "Processing").
- If port `8080` is taken, set `OPENPNE_HTTP_PORT=18080` before
  `bin/dev-up`, and give `APP_URL` in `.env` the same port if an earlier
  start has already created that file. Port `5173` is fixed (Vite always binds it
  inside the container and `public/hot` references that port, so a
  host-side remap would not actually redirect the browser).

## Without Docker

Requires PHP 8.3, 8.4 or 8.5, Composer 2.x, and Node.js 26+ on the host:

```bash
composer install
npm install
cp .env.example .env                     # then set APP_URL=http://localhost:8000
php artisan key:generate
touch database/database.sqlite
php artisan openpne:install              # schema + the initial data of a new site
php artisan openpne:admin:create admin   # the first administrator, for /admin
php artisan openpne:member:invite you@example.com   # prints the first member's registration link
php artisan passport:keys  # optional: lets AI clients sign in to the MCP server

php artisan serve         # http://localhost:8000
npm run dev               # Vite dev server on :5173 (HMR)
```

`APP_URL` is what a printed link starts with, so it has to carry the port. Mail goes to the
application log here, and only once `php artisan queue:work` has run.
