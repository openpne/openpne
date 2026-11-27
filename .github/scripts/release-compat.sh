#!/usr/bin/env bash
# What a site that follows the tagged releases is promised: it can update from the last release to
# this commit (docs/updating.md).
#
#   last-release [<tag>]          print the highest release tag, <tag> left out, or nothing when
#                                 there is none
#   baseline [<branch>]           the highest release tag, unless a release/<version> branch is
#                                 ahead of it and the change is not going onto a release branch
#   migrations <baseline> <ref>   refuse a change to a migration the baseline already shipped
#   rehearse <baseline> <ref>     install the baseline, then walk docs/updating.md to <ref>
#
# `rehearse` needs php, composer, node and a database: DB_* from the environment, SQLite in a
# temporary file when DB_CONNECTION is unset or sqlite.
set -euo pipefail

allowlist="$(cd "$(dirname "$0")" && pwd)/../release-compat-allow"
cd "$(git rev-parse --show-toplevel)"

die() { printf '::error::%s\n' "$*" >&2; exit 1; }
commit() { git rev-parse --verify --quiet "$1^{commit}" || die "$1 is not a commit in this repository"; }

# Bare versions on stdin, highest first. `~` sorts below the end of a string under `sort -V`, which
# is what puts 4.0.0-alpha.2 below 4.0.0.
by_version() { sed 's/-/~/' | sort -V -r | sed 's/~/-/'; }

# Names that are a release tag (v4.0.0-alpha.1, v4.0.0) or a release branch; a branch that only
# starts like one (release/4.0.0-alpha.2-copy-fix) is somebody's work, not a release.
tag_re='^v[0-9][^/]*$'
branch_re='^release/[0-9]+\.[0-9]+\.[0-9]+(-(alpha|beta|rc)\.[0-9]+)?$'

# A checkout that did not fetch them would see none and pass every check below for ever.
in_sync() { # in_sync <what> <ls-remote flag> <remote pattern> <prefix to strip> <name pattern> <local names>
  local listing remote
  listing=$(git ls-remote "$2" origin "$3") \
    || die "could not ask origin for its $1 (the reason is above). This says nothing about what is being checked: run this again."
  remote=$(printf '%s\n' "$listing" | awk -v p="$4" '$2 !~ /\^\{\}$/ { sub("^" p, "", $2); print $2 }' | { grep -E "$5" || true; } | sort)
  [ "$remote" = "$6" ] \
    || die "the $1 here differ from origin's (here: $(printf '%s' "$6" | grep -c . || true), origin: $(printf '%s' "$remote" | grep -c . || true)): git fetch --prune --prune-tags origin, or, in a workflow run, run it again"
}

last_release() {
  local except=${1:-} tags top
  export LC_ALL=C
  tags=$(git tag --list 'v[0-9]*' | { grep -E "$tag_re" || true; } | sort)
  in_sync "release tags" --tags 'refs/tags/v[0-9]*' 'refs/tags/' "$tag_re" "$tags"
  top=$(printf '%s\n' "$tags" | { grep -vxF -e "$except" || true; } | sed -n 's/^v//p' | by_version | head -n 1)
  [ -z "$top" ] || printf 'v%s\n' "$top"
}

# What a release/<version> branch holds before its tag exists is as good as shipped: a change to it
# on main would surface only once the tag is published. A change going onto a release branch is
# held to the last tag alone, because an urgent fix is cut from that tag, behind the branch being read.
baseline() {
  local onto=${1:-} tag branches ahead
  export LC_ALL=C
  tag=$(last_release)
  if [[ "$onto" == release/* ]]; then
    [ -z "$tag" ] || printf '%s\n' "$tag"
    return
  fi
  branches=$(git for-each-ref --format='%(refname:lstrip=3)' 'refs/remotes/origin/release/' | { grep -E "$branch_re" || true; } | sort)
  in_sync "release branches" --heads 'refs/heads/release/*' 'refs/heads/' "$branch_re" "$branches"
  ahead=$(printf '%s\n' "$branches" | sed -n 's|^release/||p' | by_version | head -n 1)

  if [ -n "$ahead" ] && [ "$(printf '%s\n%s\n' "$ahead" "${tag#v}" | by_version | head -n 1)" = "$ahead" ] && [ "v$ahead" != "$tag" ]; then
    printf 'origin/release/%s\n' "$ahead"
  elif [ -n "$tag" ]; then
    printf '%s\n' "$tag"
  fi
}

migrations() {
  local base target last changes status path shipped edited failed=0
  # Migration names are compared the way the framework orders them, byte by byte.
  export LC_ALL=C
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
      grep -h '\.ERROR:' storage/logs/*.log 2>/dev/null | tail -n 3 | cut -c1-2000 >&2 || true
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
  expect 302 /admin

  printf '\nrehearsal: a site on %s updated to %s\n' "$1" "$target"
}

case "${1:-}" in
  last-release) last_release "${2:-}" ;;
  baseline) baseline "${2:-}" ;;
  migrations) [ $# = 3 ] || die "usage: release-compat.sh migrations <baseline> <ref>"; migrations "$2" "$3" ;;
  rehearse) [ $# = 3 ] || die "usage: release-compat.sh rehearse <baseline> <ref>"; rehearse "$2" "$3" ;;
  *) die "usage: release-compat.sh last-release [<tag>] | baseline [<branch>] | migrations <baseline> <ref> | rehearse <baseline> <ref>" ;;
esac
