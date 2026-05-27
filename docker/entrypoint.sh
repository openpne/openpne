#!/bin/bash
# Idempotent first-run initialization for the openpne-oss container.
# Re-runs of `docker compose up` skip steps whose artifacts already exist.

set -e

cd /var/www/html

# Composer deps live in a named volume (host uid is not involved).
if [ ! -f vendor/autoload.php ]; then
    composer install --no-interaction --prefer-dist
fi

# .env is bind-mounted; create from .env.example on first run, otherwise leave
# it. Generate APP_KEY whenever it is unset (covers user-provided .env without
# a key, not only the first-boot copy).
if [ ! -f .env ]; then
    cp .env.example .env
fi
if ! grep -q '^APP_KEY=base64:' .env; then
    php artisan key:generate --ansi
fi

# Default DB driver is SQLite (per .env.example). If the user overrides
# DB_CONNECTION via docker-compose.override.yml or a custom .env, do not block
# startup when migrations cannot reach the DB — they can fix .env and re-run.
if grep -q '^DB_CONNECTION=sqlite' .env; then
    if [ ! -f database/database.sqlite ]; then
        touch database/database.sqlite
    fi
    php artisan migrate --force --ansi
else
    php artisan migrate --force --ansi \
        || echo "WARN: 'php artisan migrate' failed — check DB_* in .env and DB reachability; starting the server anyway."
fi

# Files created above land on the host bind mount owned by root (the container
# user during init), which makes .env un-editable and database/database.sqlite
# un-resettable from the host without sudo. Hand the two user-facing artifacts
# back to whichever uid owns the bind mount root.
owner="$(stat -c '%u:%g' /var/www/html)"
chown "$owner" .env 2>/dev/null || true
[ -f database/database.sqlite ] && chown "$owner" database/database.sqlite 2>/dev/null || true

# Runtime caches, logs, and the SQLite DB are written by the www-data worker at
# every request. Open write access for the worker (host ownership is preserved).
chmod -R o+rwX storage bootstrap/cache database 2>/dev/null || true

exec "$@"
