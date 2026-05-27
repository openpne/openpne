#!/bin/bash
# Idempotent first-run initialization for the openpne-oss container.
# Re-runs of `docker compose up` skip steps whose artifacts already exist.

set -e

cd /var/www/html

# Composer deps live in a named volume (host uid is not involved).
if [ ! -f vendor/autoload.php ]; then
    composer install --no-interaction --prefer-dist
fi

# .env is bind-mounted; create from .env.example on first run, otherwise leave it.
if [ ! -f .env ]; then
    cp .env.example .env
    php artisan key:generate --ansi
fi

# Default DB driver is SQLite (per .env.example).
if [ ! -f database/database.sqlite ]; then
    touch database/database.sqlite
fi

php artisan migrate --force --ansi

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
