#!/bin/sh
# JS toolchain entrypoint for the `vite` sidecar. Runs in a stock node image via
# the source bind mount, installs deps on first use, and returns generated
# host-facing assets to the bind mount owner.
set -eu

cd /var/www/html

[ -d node_modules/.bin ] || npm ci

owner="$(stat -c '%u:%g' /var/www/html)"

fix_host_artifact_ownership() {
    [ -e public/hot ] && chown "$owner" public/hot 2>/dev/null || true
    [ -d public/build ] && chown -R "$owner" public/build 2>/dev/null || true
}

watch_hot_file() {
    until [ -e public/hot ]; do sleep 0.5; done
    chown "$owner" public/hot 2>/dev/null || true
}

if [ "$#" -eq 0 ]; then
    set -- npm run dev
fi

if [ "$1" = "npm" ] && [ "${2:-}" = "run" ] && [ "${3:-}" = "dev" ]; then
    watch_hot_file &
    exec "$@"
fi

set +e
"$@"
status=$?
set -e

fix_host_artifact_ownership

exit "$status"
