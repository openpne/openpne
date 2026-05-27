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

# Ensure runtime dirs are writable by www-data; bind-mounted files keep host
# ownership, so framework caches and the SQLite db (+ journal/WAL) need to be
# writable for everyone.
chmod -R o+rwX storage bootstrap/cache database 2>/dev/null || true

exec "$@"
