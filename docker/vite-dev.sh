#!/bin/sh
# JS toolchain entrypoint for the `vite` sidecar. Runs in a stock node image via
# the source bind mount — as the bind-mount owner's uid (compose `user:`), so
# node_modules/ and generated assets keep host ownership — and installs deps on
# first use.
set -eu

cd /var/www/html

[ -d node_modules/.bin ] || npm ci

if [ "$#" -eq 0 ]; then
    set -- npm run dev
fi

exec "$@"
