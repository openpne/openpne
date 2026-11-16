#!/usr/bin/env bash
# What a site that follows the tagged releases is promised: it can update from the last release to
# this commit (docs/updating.md).
#
#   baseline                      print the highest release tag, or nothing when there is none
#   migrations <baseline> <ref>   refuse a change to a migration the baseline already shipped
#   rehearse <baseline> <ref>     install the baseline, then walk docs/updating.md to <ref>
#
# `rehearse` needs php, composer, node and a database: DB_* from the environment, SQLite in a
# temporary file when DB_CONNECTION is unset or sqlite.
set -euo pipefail

# Migration names are compared the way the framework orders them, byte by byte.
export LC_ALL=C

allowlist="$(cd "$(dirname "$0")" && pwd)/../release-compat-allow"
cd "$(git rev-parse --show-toplevel)"

die() { printf '::error::%s\n' "$*" >&2; exit 1; }
commit() { git rev-parse --verify --quiet "$1^{commit}" || die "$1 is not a commit in this repository"; }

baseline() {
  local remote_tags local_tags
  # A checkout that did not fetch tags would see none and pass every check below for ever.
  remote_tags=$(git ls-remote --tags origin 'refs/tags/v[0-9]*' | awk '$2 !~ /\^\{\}$/ { sub("^refs/tags/", "", $2); print $2 }' | sort)
  local_tags=$(git tag --list 'v[0-9]*' | sort)
  [ "$remote_tags" = "$local_tags" ] \
    || die "the release tags here differ from origin's (here: $(printf '%s' "$local_tags" | grep -c . || true), origin: $(printf '%s' "$remote_tags" | grep -c . || true)): fetch the tags first"

  git -c versionsort.suffix=- tag --list 'v[0-9]*' --sort=-v:refname | head -n 1
}

migrations() {
  local base target last changes status path shipped edited failed=0
  base=$(commit "$1")
  target=$(commit "$2")
  last=$(git ls-tree --name-only "$base" database/migrations/ | sort | tail -n 1)
  changes=$(git diff --name-status --no-renames "$base" "$target" -- database/migrations)

  while IFS=$'\t' read -r status path; do
    [ -n "$status" ] || continue
    case "$status" in
      A)
        if [[ ! "$path" > "$last" ]]; then
          printf '::error file=%s::sorts before %s, the last migration %s shipped: a fresh install and an updated site would run it in different orders. Give it a later timestamp.\n' "$path" "$last" "$1" >&2
          failed=1
        fi
        ;;
      M)
        shipped=$(git rev-parse "$base:$path")
        edited=$(git rev-parse "$target:$path")
        if [ -f "$allowlist" ] && grep -qxF "$shipped $edited $path" "$allowlist"; then
          printf 'allowed by .github/release-compat-allow: %s\n' "$path"
        else
          printf '::error file=%s::%s shipped this migration, and a site that has run it will not run it again. Change the schema in a new migration; if this exact edit is safe for a site that already ran the old one, list "%s %s %s" in .github/release-compat-allow.\n' "$path" "$1" "$shipped" "$edited" "$path" >&2
          failed=1
        fi
        ;;
      *)
        printf '::error file=%s::%s shipped this migration and it is gone (status %s): a site that has run it still lists it.\n' "$path" "$1" "$status" >&2
        failed=1
        ;;
    esac
  done <<<"$changes"

  [ "$failed" = 0 ] || exit 1
  printf 'migrations: no unlisted change to what %s shipped, and every new one sorts after %s\n' "$1" "$last"
}

rehearse() {
  # Not local: the EXIT trap below still needs them after this function has returned.
  local base target port
  server=""
  base=$(commit "$1")
  target=$(commit "$2")
  work=${REHEARSAL_DIR:-$(mktemp -d)}
  site="$work/site"
  port=${REHEARSAL_PORT:-8137}

  stop_server() { if [ -n "$server" ]; then kill "$server" 2>/dev/null || true; wait "$server" 2>/dev/null || true; server=""; fi; }
  cleanup() { stop_server; git worktree remove --force "$site" 2>/dev/null || true; }
  trap cleanup EXIT

  # One request against a server started for it, so each check sees the code and caches on disk.
  expect() {
    local want=$1 page=$2 got="" _
    php artisan serve --host=127.0.0.1 --port="$port" >"$work/serve.log" 2>&1 &
    server=$!
    for _ in $(seq 1 60); do
      got=$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:$port$page" || true)
      [ "$got" != 000 ] && break
      sleep 0.5
    done
    stop_server
    if [ "$got" != "$want" ]; then
      cat "$work/serve.log" >&2
      tail -n 60 storage/logs/*.log >&2 2>/dev/null || true
      die "$page answered $got, expected $want"
    fi
    printf '%s -> %s\n' "$page" "$got"
  }
  step() { printf '\n== %s\n' "$*"; }

  git worktree add --detach "$site" "$base" >/dev/null
  cd "$site"
  if [ "${DB_CONNECTION:-sqlite}" = sqlite ]; then
    export DB_CONNECTION=sqlite DB_DATABASE="$work/database.sqlite"
    : >"$DB_DATABASE"
  fi

  step "a site on $1, built the way a deployment builds it"
  composer install --no-dev --optimize-autoloader --no-interaction --no-progress
  cp .env.example .env
  php artisan key:generate --force
  php artisan openpne:install
  npm ci
  npm run build
  php artisan optimize
  expect 200 /login

  step "docs/updating.md, steps 1 to 9, to $target"
  php artisan down --render="errors::503"
  expect 503 /login
  php artisan config:clear
  php artisan route:clear
  php artisan view:clear
  php artisan event:clear
  php artisan filament:optimize-clear
  git checkout --quiet --detach "$target"
  composer install --no-dev --optimize-autoloader --no-interaction --no-progress
  npm ci
  # The older release's build is untracked and would outlive the checkout, answering for this one.
  rm -rf public/build
  npm run build
  php artisan migrate --force
  php artisan optimize
  php artisan queue:restart
  php artisan up
  expect 200 /login
  expect 200 /admin/login

  printf '\nrehearsal: a site on %s updated to %s\n' "$1" "$target"
}

case "${1:-}" in
  baseline) baseline ;;
  migrations) [ $# = 3 ] || die "usage: release-compat.sh migrations <baseline> <ref>"; migrations "$2" "$3" ;;
  rehearse) [ $# = 3 ] || die "usage: release-compat.sh rehearse <baseline> <ref>"; rehearse "$2" "$3" ;;
  *) die "usage: release-compat.sh baseline | migrations <baseline> <ref> | rehearse <baseline> <ref>" ;;
esac
