#!/bin/bash
# Idempotent first-run initialization for the openpne-oss container.
# Re-runs of `docker compose up` skip steps whose artifacts already exist.
# The container runs as the bind-mount owner's uid (compose `user:`), so every
# file created here lands on the host with the right ownership — no chown
# handback, no permission widening.

set -e

cd /var/www/html

# Runs every start: near no-op when vendor/ matches composer.lock, and resolves
# lockfile drift (pulls, branch switches) that a vendor-exists check would miss.
composer install --no-interaction --prefer-dist

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

exec "$@"
