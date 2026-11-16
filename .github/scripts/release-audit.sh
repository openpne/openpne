#!/usr/bin/env bash
# Refuses a release that ships a dependency with a known advisory, unless the advisory is named in
# $ALLOWED (comma or space separated: PKSA-…, GHSA-…, and CVE-… where Composer reports one). Dev
# dependencies are not shipped and not looked at.
#
#   release-audit.sh                                audit the lock files of this checkout
#   release-audit.sh judge <composer report> <npm report>   decide on reports saved as JSON
set -euo pipefail

die() { printf '::error::%s\n' "$*" >&2; exit 1; }

# "<package> <id>…" per advisory: every id it goes by, so that any one of them accepts it.
composer_advisories() {
  jq -e 'has("advisories")' "$1" > /dev/null 2>&1 \
    || die "composer audit did not return a report (an audit that could not run accepts nothing): $(head -c 300 "$1")"
  jq -r '.advisories | to_entries[] | .key as $pkg | .value[]
    | [.advisoryId, .cve, ((.sources // [])[] | .remoteId), ((.link // "") | split("/") | last)]
    | map(select(type == "string" and test("^(PKSA|GHSA|CVE)-"))) | unique | [$pkg] + . | join(" ")' "$1"
}

# Only a root advisory carries an object in `via`; the rest name the package that brings it in.
npm_advisories() {
  jq -e 'has("vulnerabilities")' "$1" > /dev/null 2>&1 \
    || die "npm audit did not return a report (an audit that could not run accepts nothing): $(head -c 300 "$1")"
  jq -r '.vulnerabilities | to_entries[] | .key as $pkg | .value.via[] | select(type == "object")
    | "\($pkg) \(.url | split("/") | last)"' "$1"
}

judge() {
  local composer npm found refused
  # One assignment each: a report that cannot be read has to stop here, not be outvoted by the other.
  composer=$(composer_advisories "$1") || die "the composer report could not be read"
  npm=$(npm_advisories "$2") || die "the npm report could not be read"
  found=$(printf '%s\n%s\n' "$composer" "$npm" | grep . | sort -u || true)
  if [ -z "$found" ]; then
    echo "No advisory against what the release ships."
    return
  fi
  refused=$(printf '%s\n' "$found" | awk -v allowed="${ALLOWED:-}" '
    BEGIN { n = split(allowed, ids, /[ ,]+/); for (i = 1; i <= n; i++) ok[ids[i]] = 1 }
    { for (i = 2; i <= NF; i++) if ($i in ok) next; print }')
  echo "Advisories against what the release ships (package, then the ids each goes by):"
  printf '%s\n' "$found" | sed 's/^/  /'
  [ -z "$refused" ] \
    || die "not accepted for this release: $(printf '%s' "$refused" | tr '\n' ';'). Update the dependency, or start the release again with the advisory's id in allow_advisories."
  echo "Every one of them was accepted for this release."
}

case "${1:-}" in
  judge)
    [ $# = 3 ] || die "usage: release-audit.sh judge <composer report> <npm report>"
    judge "$2" "$3"
    ;;
  '')
    work=$(mktemp -d)
    trap 'rm -rf "$work"' EXIT
    # Both exit non-zero when they find something; the reports say what, so the status is not read.
    composer audit --locked --no-dev --format=json --abandoned=ignore --no-interaction > "$work/composer.json" 2> "$work/composer.err" || true
    npm audit --json --package-lock-only --omit=dev > "$work/npm.json" 2> "$work/npm.err" || true
    cat "$work/composer.err" "$work/npm.err" >&2
    judge "$work/composer.json" "$work/npm.json"
    ;;
  *) die "usage: release-audit.sh [judge <composer report> <npm report>]" ;;
esac
