#!/bin/sh
# JS toolchain entrypoint for the `vite` sidecar. Runs in a stock node image via
# the source bind mount — as the bind-mount owner's uid (compose `user:`), so
# node_modules/ and generated assets keep host ownership — and installs deps on
# first use.
set -eu

cd /var/www/html

# node_modules lives on the bind mount and contains platform-specific native
# binaries (rolldown, tailwind oxide). A tree installed by a macOS host would
# break inside this Linux container, so reinstall whenever the recorded install
# platform differs (host-side installs leave no marker and also trigger this).
want="$(uname -s)-$(uname -m)"
if [ ! -d node_modules/.bin ] || [ "$(cat node_modules/.openpne-platform 2>/dev/null)" != "$want" ]; then
    npm ci
    printf '%s' "$want" > node_modules/.openpne-platform
fi

if [ "$#" -eq 0 ]; then
    set -- npm run dev
fi

exec "$@"
