#!/bin/sh
# JS toolchain entrypoint for the `vite` sidecar. Runs in a stock node image via
# the source bind mount — as the bind-mount owner's uid (compose `user:`), so
# node_modules/ and generated assets keep host ownership — and installs deps on
# first use.
set -eu

cd /var/www/html

# node_modules lives on the bind mount and contains platform-specific native
# binaries (rolldown, tailwind oxide). npm ci has no cheap no-op mode, so a
# stamp records what the tree was installed for — platform (a macOS host
# install would break in this Linux container) and lockfile hash (pulls and
# branch switches would leave a stale tree) — and any mismatch reinstalls.
# Host-side installs leave no stamp and also trigger this.
want="$(uname -s)-$(uname -m) $(sha256sum package-lock.json | cut -d' ' -f1)"
if [ ! -d node_modules/.bin ] || [ "$(cat node_modules/.openpne-stamp 2>/dev/null)" != "$want" ]; then
    npm ci
    printf '%s' "$want" > node_modules/.openpne-stamp
fi

if [ "$#" -eq 0 ]; then
    set -- npm run dev
fi

exec "$@"
