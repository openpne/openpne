#!/usr/bin/env bash
# Usage: audit-delta.sh <base-dir> <head-dir>
#
# Fails only when the head lockfiles carry a (package, advisory) pair the base
# lockfiles do not. Each dir holds composer.json, composer.lock, package.json,
# package-lock.json and the same .npmrc. Pairs present on both sides are printed
# and ignored: they belong to the scheduled full audit, not to the PR under test.
set -euo pipefail

base="$1"
head="$2"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

composer_keys() {
  # "<package> <advisoryId>" per line. --abandoned=ignore: abandonment is reported by the full audit only.
  local dir="$1" out="$2"
  composer audit --locked --format=json --abandoned=ignore --no-interaction -d "$dir" > "$out" 2> "$out.err" || true
  if ! jq -e 'has("advisories")' "$out" > /dev/null 2>&1; then
    echo "composer audit did not return a report for $dir:" >&2
    cat "$out" "$out.err" >&2
    exit 2
  fi
  jq -r '.advisories | to_entries[] | .key as $pkg | .value[] | "\($pkg) \(.advisoryId)"' "$out" | sort -u
}

npm_keys() {
  # "<package> <advisory url>" per line; only root advisories carry an object in `via`.
  local dir="$1" out="$2"
  npm audit --json --package-lock-only --prefix "$dir" > "$out" 2> "$out.err" || true
  if ! jq -e 'has("vulnerabilities")' "$out" > /dev/null 2>&1; then
    echo "npm audit did not return a report for $dir:" >&2
    cat "$out" "$out.err" >&2
    exit 2
  fi
  jq -r '.vulnerabilities | to_entries[] | .key as $pkg | .value.via[] | select(type == "object") | "\($pkg) \(.url)"' "$out" | sort -u
}

status=0
for eco in composer npm; do
  "${eco}_keys" "$base" "$work/$eco-base.json" > "$work/$eco-base.txt"
  "${eco}_keys" "$head" "$work/$eco-head.json" > "$work/$eco-head.txt"
  comm -13 "$work/$eco-base.txt" "$work/$eco-head.txt" > "$work/$eco-new.txt"
  comm -12 "$work/$eco-base.txt" "$work/$eco-head.txt" > "$work/$eco-shared.txt"

  if [ -s "$work/$eco-shared.txt" ]; then
    echo "::notice::$eco advisories already present on the base branch (not blocking; tracked by the scheduled audit):"
    sed 's/^/  /' "$work/$eco-shared.txt"
  fi
  if [ -s "$work/$eco-new.txt" ]; then
    echo "::error::$eco advisories introduced by this change:"
    sed 's/^/  /' "$work/$eco-new.txt"
    status=1
  else
    echo "$eco: no new advisories"
  fi
done

exit "$status"
