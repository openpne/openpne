#!/usr/bin/env bash
# A release as an archive that a server installs without Composer or Node
# (docs/updating.md, "From a release archive").
#
#   build <ref> <dir> <version>      write openpne-<version>.tar.gz, .zip and SHA256SUMS into <dir>
#   verify <dir> <version> <ref>     install each archive in an empty directory, with composer, npm
#                                    and node made to fail, and ask the site for its pages
#   update <old dir> <old version> <new dir> <new version>
#                                    install the old archive, give it a member, an entry and a
#                                    stored file, then walk docs/updating.md to the new one
#   shared-update <old dir> <old version> <new dir> <new version>
#                                    the same through docs/installing-on-a-shared-host.md: its
#                                    commands, taken from the guide, and openpne:setup in a terminal
#
# `update` reads ARCHIVE_DB: sqlite-default (database/database.sqlite in WAL mode, the default),
# sqlite-outside (WAL too, at an absolute path outside the tree), relocated (that, with .env and
# storage/ outside the tree too) or mysql (DB_* from the environment, an empty database). ARCHIVE_NEW_MARK,
# when set, is text only the new release's login page has: the page must have it after the update
# and must have lost it after going back. `shared-update` reads ARCHIVE_DB too: sqlite (the default)
# or mysql, with DB_* as for `update`.
set -euo pipefail

port=${ARCHIVE_PORT:-8141}
server=""
worker=""
holder=""
work=""

die() { printf '::error::%s\n' "$*" >&2; exit 1; }
step() { printf '\n== %s\n' "$*"; }
commit() { git rev-parse --verify --quiet "$1^{commit}" || die "$1 is not a commit in this repository"; }

stop() { # stop <pid variable name>
  local pid=${!1}
  if [ -n "$pid" ]; then kill "$pid" 2>/dev/null || true; wait "$pid" 2>/dev/null || true; printf -v "$1" ''; fi
}
cleanup() { stop server; stop worker; stop holder; if [ -n "$work" ] && [ -z "${ARCHIVE_KEEP:-}" ]; then rm -rf "$work"; fi; }
trap cleanup EXIT

# One request against a server started for it in the current directory, so each check sees the
# code and the caches on disk.
expect() {
  local want=$1 page=$2 got="" _
  # A server an earlier check left behind would answer for this site.
  got=$(curl -s -o /dev/null --max-time 5 -w '%{http_code}' "http://127.0.0.1:$port/" || true)
  [ "$got" = 000 ] || die "something already answers on port $port"
  php artisan serve --host=127.0.0.1 --port="$port" >"$work/serve.log" 2>&1 &
  server=$!
  for _ in $(seq 1 60); do
    got=$(curl -s -o "$work/page" --max-time 10 -w '%{http_code}' "http://127.0.0.1:$port$page" || true)
    [ "$got" != 000 ] && break
    sleep 0.5
  done
  stop server
  # Without ext-pcntl `artisan serve` does not take the server it started down with it.
  pkill -f "^[^ ]*php[^ ]* -S 127\.0\.0\.1:$port " 2>/dev/null || true
  if [ "$got" != "$want" ]; then
    cat "$work/serve.log" >&2
    grep -h '\.ERROR:' storage/logs/*.log 2>/dev/null | tail -n 3 | cut -c1-2000 >&2 || true
    die "$page answered $got, expected $want"
  fi
  printf '%s -> %s\n' "$page" "$got"
}

set_env() { # set_env <file> <key> <value>: replace the line, commented out or not, or add it
  local line found=0 out
  out=$(mktemp)
  while IFS= read -r line || [ -n "$line" ]; do
    if [ "$found" = 0 ] && [[ "$line" =~ ^#?\ ?$2= ]]; then
      printf '%s=%s\n' "$2" "$3"
      found=1
    else
      printf '%s\n' "$line"
    fi
  done <"$1" >"$out"
  [ "$found" = 1 ] || printf '%s=%s\n' "$2" "$3" >>"$out"
  cat "$out" >"$1"
  rm -f "$out"
}

unpack() { # unpack <archive> <into>: prints the directory the archive holds
  local archive=$1 into=$2 top
  mkdir -p "$into"
  case "$archive" in
    *.tar.gz) tar -xzf "$archive" -C "$into" ;;
    *.zip) unzip -q "$archive" -d "$into" ;;
    *) die "$archive is neither a .tar.gz nor a .zip" ;;
  esac
  top=$(find "$into" -mindepth 1 -maxdepth 1)
  [ "$(printf '%s\n' "$top" | wc -l)" = 1 ] && [ -d "$top" ] || die "$archive does not unpack to one directory"
  printf '%s\n' "$top"
}

# What a deployment calls and an archive must not need.
without_build_tools() {
  local shims=$work/shims tool
  mkdir -p "$shims"
  for tool in composer npm npx node; do
    printf '#!/bin/sh\necho "%s was called: a release archive must not need it" >&2\nexit 97\n' "$tool" >"$shims/$tool"
    chmod +x "$shims/$tool"
  done
  PATH="$shims:$PATH"
}

build() {
  local sha out version name
  sha=$(commit "$1")
  mkdir -p "$2"
  out=$(cd "$2" && pwd)
  version=$3
  name="openpne-$version"
  work=$(mktemp -d)

  step "the tree of $sha, as git archive exports it"
  git archive --format=tar --prefix="$name/" "$sha" | tar -x -C "$work"
  cd "$work/$name"

  step "dependencies, without the development ones"
  # The two commands composer's hooks come to, named so that the archive's contents do not follow
  # whatever a hook goes on to do.
  composer install --no-dev --optimize-autoloader --no-scripts --no-interaction --no-progress
  php artisan package:discover
  php artisan filament:assets

  step "the frontend"
  npm ci --no-audit --no-fund
  npm run build
  rm -rf node_modules

  printf 'version=%s\nsha=%s\n' "$version" "$sha" >RELEASE
  # Whatever the commands above wrote that belongs to one site rather than to the release.
  rm -f .env storage/logs/*.log storage/framework/views/*.php storage/*.key public/hot \
    bootstrap/cache/config.php bootstrap/cache/routes-v7.php bootstrap/cache/events.php
  rm -rf bootstrap/cache/filament

  step "archives"
  cd "$work"
  tar --sort=name --owner=0 --group=0 --numeric-owner -czf "$out/$name.tar.gz" "$name"
  rm -f "$out/$name.zip"
  python3 - "$work" "$name" "$out/$name.zip" <<'PY'
import os, sys, zipfile
root, name, out = sys.argv[1:4]
with zipfile.ZipFile(out, 'w', zipfile.ZIP_DEFLATED) as z:
    for path, dirs, files in sorted(os.walk(os.path.join(root, name))):
        dirs.sort()
        rel = os.path.relpath(path, root)
        z.write(path, rel + '/')
        for f in sorted(files):
            z.write(os.path.join(path, f), os.path.join(rel, f))
PY
  (cd "$out" && sha256sum "$name.tar.gz" "$name.zip" >SHA256SUMS)
  ls -l "$out"
}

# check_tree <site> <sha>: what an archive has to hold, and what it must not.
check_tree() {
  local site=$1 sha=$2 path failed=0
  for path in .env.example .htaccess artisan RELEASE tests/release-archive-probe.php public/index.php public/.htaccess public/robots.txt \
    public/build/manifest.json public/build/.vite/license.md public/js/filament public/css/filament \
    vendor/autoload.php bootstrap/cache/packages.php bootstrap/cache/services.php \
    storage/app/private storage/app/public storage/framework/cache/data storage/framework/sessions \
    storage/framework/views storage/logs database/migrations; do
    [ -e "$site/$path" ] || { printf '::error::missing from the archive: %s\n' "$path" >&2; failed=1; }
  done
  for path in .env .git .github node_modules auth.json public/hot public/storage \
    bootstrap/cache/config.php bootstrap/cache/routes-v7.php bootstrap/cache/events.php bootstrap/cache/filament \
    vendor/phpunit vendor/bin/phpunit vendor/laravel/pint; do
    [ ! -e "$site/$path" ] || { printf '::error::must not be in the archive: %s\n' "$path" >&2; failed=1; }
  done
  # A site's own data: a database file, a log, a compiled view, a session, a signing key.
  path=$({ find "$site/storage" -type f ! -name .gitignore; find "$site/database" -type f -name '*.sqlite*'; } | head -n 5)
  [ -z "$path" ] || { printf '::error::a site'"'"'s own data is in the archive:\n%s\n' "$path" >&2; failed=1; }
  # Rewrite rules of its own in any other directory would take the place of the top file's 403 there.
  path=$(find "$site" -name .htaccess ! -path "$site/.htaccess" ! -path "$site/public/.htaccess" | head -n 5)
  [ -z "$path" ] || { printf '::error::an .htaccess besides the top one and public/'"'"'s is in the archive:\n%s\n' "$path" >&2; failed=1; }
  grep -qxF "sha=$sha" "$site/RELEASE" || { printf '::error::RELEASE does not name %s\n' "$sha" >&2; failed=1; }
  [ "$failed" = 0 ] || exit 1
}

# install <site>: the steps of a new site, in the current directory, on SQLite in the tree.
install() {
  cp .env.example .env
  set_env .env APP_ENV production
  set_env .env APP_DEBUG false
  set_env .env OPENPNE_FORCE_HTTPS false
  set_env .env APP_URL "http://127.0.0.1:$port"
  : >database/database.sqlite
  php artisan key:generate --force
  php artisan openpne:install
}

verify() {
  local dir version sha format site asset
  dir=$(cd "$1" && pwd)
  version=$2
  sha=$(commit "$3")
  work=$(mktemp -d)
  (cd "$dir" && sha256sum --check --quiet SHA256SUMS)
  without_build_tools

  for format in tar.gz zip; do
    step "openpne-$version.$format in an empty directory"
    site=$(unpack "$dir/openpne-$version.$format" "$work/$format")
    check_tree "$site" "$sha"
    cd "$site"
    # What a host is checked for before a site exists, so it runs before install writes .env.
    php artisan openpne:check --only=before --lang=en || die "openpne:check --only=before found what the archive lacks"
    install
    php artisan openpne:admin:invite first@example.com | grep -E '^http://127\.0\.0\.1:[0-9]+/register/[A-Za-z0-9]{40}$' >/dev/null \
      || die "openpne:admin:invite printed no registration link"
    php artisan optimize
    expect 200 /login
    expect 302 /admin
    expect 200 /js/filament/filament/app.js
    asset=$(php -r 'echo array_values(json_decode(file_get_contents("public/build/manifest.json"), true))[0]["file"];')
    expect 200 "/build/$asset"
    cd "$work"
  done
  printf '\nverify: both archives of %s install and answer without composer, npm or node\n' "$version"
}

update() {
  local old_dir=$1 old_version=$2 new_dir=$3 new_version=$4 mode=${ARCHIVE_DB:-sqlite-default}
  local root old new env_file storage db theme marker pid path name
  old_dir=$(cd "$old_dir" && pwd)
  new_dir=$(cd "$new_dir" && pwd)
  work=$(cd "$(mktemp -d)" && pwd -P)
  root=$work/srv
  without_build_tools
  # The probe of the release in place: each one knows its own code.
  probe() { php tests/release-archive-probe.php "$1" "$work/probe.json"; }

  step "a site on $old_version at $root/openpne ($mode)"
  old=$(unpack "$old_dir/openpne-$old_version.tar.gz" "$root")
  mv "$old" "$root/openpne"
  cd "$root/openpne"
  cp .env.example .env
  env_file=$root/openpne/.env
  storage=$root/openpne/storage
  db=""
  case "$mode" in
    sqlite-default)
      db=$root/openpne/database/database.sqlite
      set_env .env DB_JOURNAL_MODE WAL
      ;;
    sqlite-outside | relocated)
      mkdir -p "$work/data"
      db=$work/data/site.sqlite
      set_env .env DB_DATABASE "$db"
      set_env .env DB_JOURNAL_MODE WAL
      ;;
    mysql)
      for key in DB_CONNECTION DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD; do
        [ -n "${!key:-}" ] || die "ARCHIVE_DB=mysql needs $key in the environment"
        set_env .env "$key" "${!key}"
      done
      ;;
    *) die "ARCHIVE_DB is sqlite-default, sqlite-outside, relocated or mysql, not $mode" ;;
  esac
  [ -z "$db" ] || : >"$db"
  if [ "$mode" = relocated ]; then
    mkdir -p "$work/etc"
    mv .env "$work/etc/.env"
    mv storage "$work/data/storage"
    env_file=$work/etc/.env
    storage=$work/data/storage
    export OPENPNE_ENV_PATH=$work/etc LARAVEL_STORAGE_PATH=$storage
  fi
  set_env "$env_file" APP_URL "http://127.0.0.1:$port"
  set_env "$env_file" OPENPNE_FILES_DISK local
  php artisan key:generate --force
  php artisan openpne:install
  php artisan queue:work --sleep=1 >"$work/worker.log" 2>&1 &
  worker=$!

  step "what the operator and the members have put in it"
  theme=public/opSkinThemePlugin/harbor
  mkdir -p "$theme/css"
  printf 'body { color: teal; }\n' >"$theme/css/main.css"
  printf '# The operator'"'"'s own rule.\nHeader set X-Operator "kept"\n' >>public/.htaccess
  marker=app/OnlyInTheOldRelease.php
  printf '<?php\n' >"$marker"
  if [ "$mode" = sqlite-default ]; then
    # A connection that read before the writes below keeps them from being checkpointed out of the
    # -wal file for as long as it stays open.
    mkfifo "$work/hold"
    exec 9<>"$work/hold"
    (cd "$work" && exec sqlite3 "$db" <"$work/hold" >/dev/null) &
    holder=$!
    printf 'BEGIN;\nSELECT count(*) FROM members;\n' >&9
    sleep 1
  fi
  probe seed
  probe check
  expect 200 /login

  step "docs/updating.md, from a release archive: stop the site and its worker"
  php artisan down --render="errors::503"
  expect 503 /login
  stop worker

  step "back up"
  if [ -n "$db" ]; then
    php artisan openpne:backup-sqlite "$work/backup.sqlite"
    [ "$(sqlite3 "$work/backup.sqlite" 'PRAGMA journal_mode')" = delete ] \
      || die "the openpne:backup-sqlite copy is not out of WAL"
    if [ "$mode" = sqlite-default ]; then
      # The fixture has to be one where the shortcut loses data, or this check proves nothing.
      cp "$db" "$work/alone.sqlite"
      [ "$(sqlite3 "$work/alone.sqlite" 'SELECT count(*) FROM members')" = 0 ] \
        || die "database.sqlite copied alone still holds the member: the fixture no longer leaves rows in the -wal file"
      [ "$(sqlite3 "$work/backup.sqlite" 'SELECT count(*) FROM members')" = 1 ] \
        || die "the openpne:backup-sqlite copy does not hold the member"
    fi
  fi

  step "remove the caches the running release built"
  php artisan config:clear
  php artisan route:clear
  php artisan view:clear
  php artisan event:clear
  php artisan filament:optimize-clear

  step "unpack $new_version beside the site and carry the site's own files into it"
  new=$(unpack "$new_dir/openpne-$new_version.tar.gz" "$root/unpacked")
  diff "$root/openpne/.env.example" "$new/.env.example" || true
  if [ "$mode" != relocated ]; then
    cp -a "$root/openpne/.env" "$new/.env"
    rm -rf "$new/storage"
    cp -a "$root/openpne/storage" "$new/storage"
  fi
  chmod o-rwx "$new/bootstrap/cache"
  cp -a "$root/openpne/$theme" "$new/$theme"
  # The operator's edit to a file the release ships, as a patch against the archive it came from.
  old=$(unpack "$old_dir/openpne-$old_version.tar.gz" "$work/pristine")
  diff -u "$old/public/.htaccess" "$root/openpne/public/.htaccess" >"$work/htaccess.patch" || true
  cp "$new/public/.htaccess" "$work/new.htaccess"
  patch --quiet "$new/public/.htaccess" <"$work/htaccess.patch"
  if [ "$mode" = sqlite-default ]; then
    cp "$work/backup.sqlite" "$new/database/database.sqlite"
  fi

  step "put it at the site's path"
  # Whatever still runs in the directory would go on running the old release from where it goes.
  for pid in /proc/[0-9]*; do
    [ "${pid#/proc/}" != "$$" ] || continue
    [ "$(readlink "$pid/cwd" 2>/dev/null || true)" != "$root/openpne" ] \
      || die "process ${pid#/proc/} ($(tr '\0' ' ' <"$pid/cmdline")) still runs in the directory about to be moved away"
  done
  mv "$root/openpne" "$root/openpne-old"
  mv "$new" "$root/openpne"
  cd "$root/openpne"

  step "migrate, start the worker, open the site"
  php artisan migrate --force
  php artisan queue:work --sleep=1 >"$work/worker.log" 2>&1 &
  worker=$!
  php artisan up
  stop holder

  step "the site after the update"
  expect 200 /login
  [ -z "$db" ] || [ "$(sqlite3 -cmd ".timeout 60000" "$db" 'PRAGMA journal_mode')" = wal ] || die "the database is not in WAL mode once the updated site has opened it"
  [ -z "${ARCHIVE_NEW_MARK:-}" ] || grep -qF "$ARCHIVE_NEW_MARK" "$work/page" || die "the login page is not the new release's"
  expect 302 /admin
  probe check
  grep -qxF "version=$new_version" RELEASE || die "RELEASE is not the new release's"
  [ -f "$theme/css/main.css" ] || die "the operator's theme did not come along"
  [ -z "$(find bootstrap/cache -maxdepth 0 -perm /o=rwx)" ] || die "bootstrap/cache, where cached settings go, is open to other users"
  [ ! -e "$marker" ] || die "a file only the old release had is still there"
  grep -qF 'X-Operator' public/.htaccess || die "the operator's rule is gone from public/.htaccess"
  diff <(grep -vF -e 'X-Operator' -e "The operator's own rule." public/.htaccess) "$work/new.htaccess" >/dev/null \
    || die "public/.htaccess is not the new release's file with the operator's rule added"
  sleep 2
  kill -0 "$worker" 2>/dev/null || die "the queue worker did not stay up under the new release"

  if [ -z "$db" ]; then
    printf '\nupdate: a site on %s reached %s from its archive and kept its data (%s; going back is walked on SQLite)\n' "$old_version" "$new_version" "$mode"
    return
  fi

  step "going back: the old directory at the site's path, the backup in place of the database"
  php artisan down --render="errors::503"
  stop worker
  mv "$root/openpne" "$root/openpne-failed"
  mv "$root/openpne-old" "$root/openpne"
  cd "$root/openpne"
  rm -f "$db" "$db-wal" "$db-shm"
  cp "$work/backup.sqlite" "$db"
  # Read before the site opens the copy: its first connection switches it to WAL under a lock that
  # sqlite3, which does not wait, would fail on.
  [ "$(sqlite3 "$db" 'PRAGMA integrity_check')" = ok ] || die "the database put back does not pass its integrity check"
  for path in "$root/openpne-failed"/database/migrations/*.php; do
    name=$(basename "$path" .php)
    [ ! -e "database/migrations/$name.php" ] || continue
    [ "$(sqlite3 "$db" "SELECT count(*) FROM migrations WHERE migration = '$name'")" = 0 ] \
      || die "the database put back has run $name, which the old release does not have"
  done
  php artisan view:clear
  php artisan queue:work --sleep=1 >"$work/worker.log" 2>&1 &
  worker=$!
  php artisan up
  expect 200 /login
  [ "$(sqlite3 -cmd ".timeout 60000" "$db" 'PRAGMA journal_mode')" = wal ] || die "the database put back is not in WAL mode once the site has opened it"
  [ -z "${ARCHIVE_NEW_MARK:-}" ] || ! grep -qF "$ARCHIVE_NEW_MARK" "$work/page" || die "the old release is drawing the new release's login page"
  probe check
  [ -e "$marker" ] || die "going back did not bring back the old release's code"

  printf '\nupdate: a site on %s reached %s from its archive, kept its data, and could go back (%s)\n' "$old_version" "$new_version" "$mode"
}

# setup_in_a_terminal <sqlite|mysql> <host> <port> <database> <user> <password>: openpne:setup in the
# current directory, answered as the guide's reader answers it, up to the wait for the domain.
setup_in_a_terminal() {
  python3 - "$@" <<'PY'
import fcntl, os, pty, re, select, struct, sys, termios, time
db, host, port, database, user, password = sys.argv[1:7]
ansi = re.compile(rb'\x1b\[[0-9;?]*[ -/]*[@-~]|\x1b\][^\x07\x1b]*(?:\x07|\x1b\\)|\x1b[()][A-Z0-9]|\x1b[=>78]')
pid, fd = pty.fork()
if pid == 0:
    os.environ.update(TERM='xterm-256color', LANG='C.UTF-8')
    os.execvp('php', ['php', 'artisan', 'openpne:setup', '--lang=en'])
fcntl.ioctl(fd, termios.TIOCSWINSZ, struct.pack('HHHH', 50, 140, 0, 0))
raw, pos = b'', 0
def screen():
    return ansi.sub(b'', raw).replace(b'\r', b'').decode('utf-8', 'replace')
def pump(wait):
    global raw
    if select.select([fd], [], [], wait)[0]:
        try:
            data = os.read(fd, 65536)
        except OSError:
            return None
        raw += data
        return data
    return b''
def expect(pattern, timeout=180):
    global pos
    end = time.time() + timeout
    while time.time() < end:
        found = re.compile(pattern).search(screen(), pos)
        if found:
            pos = found.end()
            return found
        if pump(0.2) is None:
            break
    sys.stdout.write(screen())
    sys.exit(f'::error::openpne:setup did not ask {pattern!r}')
def send(keys):
    time.sleep(0.3)
    while pump(0.05):
        pass
    os.write(fd, keys.encode())
expect(r"The site's address"); send('https://site.invalid\r')
expect(r"The site's name"); send('Archive SNS\r')
expect(r'Time zone'); send('\r')
# A host with one database it can use is told which, not asked.
if expect(r'┌ Database |The database is ').group(0) != 'The database is ':
    if db == 'mysql':
        send('\x1b[B')
    send('\r')
if db == 'mysql':
    expect(r'Database server'); send(host + '\r')
    expect(r'Port'); send('\r')
    expect(r'Database name'); send(database + '\r')
    expect(r'User name'); send(user + '\r')
    expect(r'Password'); send(password + '\r')
expect(r'Mail'); send('\r')
expect(r'Background work'); send('\r')
expect(r'Check again\?', 300); send('n\r')
end = time.time() + 120
while pump(1) is not None and time.time() < end:
    pass
sys.stdout.write(screen())
os.kill(pid, 9) if os.waitpid(pid, os.WNOHANG) == (0, 0) else None
PY
}

shared_update() {
  local old_dir=$1 old_version=$2 new_dir=$3 new_version=$4 mode=${ARCHIVE_DB:-sqlite}
  local repo doc home site data marker key database=DATABASE host="" dbport="" user="" password="" tables
  repo=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
  doc=$repo/docs/installing-on-a-shared-host.md
  old_dir=$(cd "$old_dir" && pwd)
  new_dir=$(cd "$new_dir" && pwd)
  work=$(cd "$(mktemp -d)" && pwd -P)
  home=$work/home
  site=$home/openpne/site
  data=$home/openpne/openpne-data
  mkdir "$home"
  without_build_tools
  case "$mode" in
    sqlite) ;;
    mysql)
      for key in DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD; do
        [ -n "${!key:-}" ] || die "ARCHIVE_DB=mysql needs $key in the environment"
      done
      [ "$DB_PORT" = 3306 ] || die "shared-update takes setup's port, 3306, not $DB_PORT"
      host=$DB_HOST dbport=$DB_PORT database=$DB_DATABASE user=$DB_USERNAME password=$DB_PASSWORD
      ;;
    *) die "ARCHIVE_DB is sqlite or mysql for shared-update, not $mode" ;;
  esac
  # Setup refuses a DB_ value of the shell's that differs from .env's, which alone the web reads.
  unset DB_CONNECTION DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD
  export HOME=$home
  probe() { php tests/release-archive-probe.php "$1" "$work/probe.json"; }
  # walk <version> <heading>...: the guide's commands under those headings, run in order by one shell
  # that stops at the first that fails, as the guide has its reader do.
  walk() {
    local version=$1
    shift
    python3 -I "$repo/.github/scripts/doc-blocks.py" "$doc" "$mode" "$version" "$database" "$work/files" "$@" >"$work/walk.sh"
    sed 's/^/$ /' "$work/walk.sh"
    (cd "$home" && bash -euo pipefail "$work/walk.sh")
  }

  step "the guide's step 2: $old_version unpacked as ~/openpne/site ($mode)"
  mkdir "$work/files"
  cp "$old_dir/openpne-$old_version.tar.gz" "$old_dir/SHA256SUMS" "$work/files/"
  walk "$old_version" "2. Put the release in place"

  step "the guide's step 3: openpne:setup, stopped at the wait for the domain"
  (cd "$site" && setup_in_a_terminal "$mode" "$host" "$dbport" "$database" "$user" "$password")
  [ -f "$site/.env" ] || die "openpne:setup wrote no .env"
  case "$mode" in
    sqlite) [ -f "$data/database.sqlite" ] || die "openpne:setup made no openpne-data/database.sqlite" ;;
    mysql) [ -f "$data/mysql-backup.cnf" ] || die "openpne:setup wrote no openpne-data/mysql-backup.cnf" ;;
  esac

  step "what the members have put in it"
  cd "$site"
  # The site answers its own address alone, and `expect` asks over plain HTTP on 127.0.0.1.
  set_env .env APP_URL "http://127.0.0.1:$port"
  set_env .env OPENPNE_FORCE_HTTPS false
  marker=app/OnlyInTheOldRelease.php
  printf '<?php\n' >"$marker"
  probe seed
  probe check
  expect 200 /login

  step "the guide's update to $new_version"
  cd "$home"
  rm -f "$work/files"/*
  cp "$new_dir/openpne-$new_version.tar.gz" "$new_dir/SHA256SUMS" "$work/files/"
  walk "$new_version" "1. Download the release" "2. Stop the site and back it up" \
    "3. Carry the site's own files into the release" "4. Put it in place and open the site"

  step "the site after the update"
  cd "$site"
  expect 200 /login
  probe check
  grep -qxF "version=$new_version" RELEASE || die "RELEASE is not the new release's"
  [ ! -e "$marker" ] || die "a file only the old release had is still there"
  [ -e "$home/openpne/site-before-$new_version/$marker" ] || die "the old release is not at site-before-$new_version"
  [ -z "$(find bootstrap/cache -maxdepth 0 -perm /o=rwx)" ] || die "bootstrap/cache, where cached settings go, is open to other users"
  case "$mode" in
    sqlite)
      [ "$(sqlite3 "$data/before-$new_version.sqlite" 'SELECT count(*) FROM members')" = 1 ] \
        || die "the copy taken before the update does not hold the member"
      [ "$(stat -c %a "$data/before-$new_version.sqlite")" = 600 ] || die "the copy taken before the update is readable by others"
      ;;
    mysql)
      grep -q '^CREATE TABLE `members`' "$data/before-$new_version.sql" || die "the copy taken before the update has no members table"
      [ "$(stat -c %a "$data/before-$new_version.sql")" = 600 ] || die "the copy taken before the update is readable by others"
      ;;
  esac

  step "the guide's going back, from step 4 on"
  php artisan down --render="errors::503"
  if [ "$mode" = mysql ]; then
    # What the guide has the reader do in the host's database tool.
    tables=$(mysql --defaults-extra-file="$data/mysql-backup.cnf" -N -e 'SET SESSION group_concat_max_len = 1000000; SELECT GROUP_CONCAT(CONCAT("`", table_name, "`")) FROM information_schema.tables WHERE table_schema = DATABASE()' "$database")
    mysql --defaults-extra-file="$data/mysql-backup.cnf" -e "SET FOREIGN_KEY_CHECKS = 0; DROP TABLE $tables" "$database"
  fi
  cd "$home"
  walk "$new_version" "From step 4 on"
  cd "$site"
  [ -e "$marker" ] || die "going back did not bring back the old release's code"
  [ -d "$home/openpne/site-failed-$new_version" ] || die "the failed release is not at site-failed-$new_version"
  [ "$mode" != sqlite ] || [ "$(sqlite3 "$data/database.sqlite" 'PRAGMA integrity_check')" = ok ] \
    || die "the database put back does not pass its integrity check"
  expect 200 /login
  probe check

  printf '\nshared-update: a site set up by openpne:setup on %s reached %s by the guide, kept its data, and could go back (%s)\n' "$old_version" "$new_version" "$mode"
}

usage='usage: release-archive.sh build <ref> <dir> <version> | verify <dir> <version> <ref> | update|shared-update <old dir> <old version> <new dir> <new version>'
case "${1:-}" in
  build) [ $# = 4 ] || die "$usage"; cd "$(git rev-parse --show-toplevel)"; build "$2" "$3" "$4" ;;
  verify) [ $# = 4 ] || die "$usage"; cd "$(git rev-parse --show-toplevel)"; verify "$2" "$3" "$4" ;;
  update) [ $# = 5 ] || die "$usage"; update "$2" "$3" "$4" "$5" ;;
  shared-update) [ $# = 5 ] || die "$usage"; shared_update "$2" "$3" "$4" "$5" ;;
  *) die "$usage" ;;
esac
