#!/usr/bin/env bash
# Runs laravel-lang's lang:update for the PHP namespaces and restores lang/{ja,en}.json from
# HEAD on every exit, because the publisher rewrites them too (docs/internals/i18n.md, "Ownership: publisher-managed vs app-authored").
set -u

cd "$(dirname "$0")/.." || exit 1
json=(lang/ja.json lang/en.json)

if ! git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    echo "lang-update: not a git checkout; run 'php artisan lang:update' and restore ${json[*]} by hand." >&2
    exit 1
fi
if ! git diff --quiet HEAD -- "${json[@]}"; then
    echo "lang-update: ${json[*]} differ from HEAD; commit or stash them first." >&2
    exit 1
fi

status=1
restore() {
    if ! git checkout HEAD -- "${json[@]}"; then
        echo "lang-update: could not restore ${json[*]} from HEAD." >&2
        exit 1
    fi
    exit "$status"
}
trap restore EXIT

php artisan lang:update
status=$?
