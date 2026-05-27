#!/bin/sh
# Vite dev server for the `vite` sidecar. Runs in a stock node image via the
# source bind mount. Installs JS deps on first run, then starts the dev server.
set -e

cd /var/www/html

[ -d node_modules/.bin ] || npm ci

# public/hot is written by Vite as root. Hand it back to whichever uid owns
# the bind mount root so the host developer can clean it up.
(
    until [ -e public/hot ]; do sleep 0.5; done
    chown "$(stat -c '%u:%g' /var/www/html)" public/hot 2>/dev/null || true
) &

exec npm run dev
