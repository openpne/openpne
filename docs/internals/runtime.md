# Runtime

Some deployments keep an application's secrets and writable runtime state
outside its code checkout — for example when the checkout is a read-only,
immutable release. By default the application reads its `.env` from the project
root and writes to the in-project `storage/` directory, but two process
environment variables, read during startup (see
[`bootstrap/app.php`](../../bootstrap/app.php)), let a deployer relocate either:

| Variable | Relocates | Laravel call |
|----------|-----------|--------------|
| `OPENPNE_ENV_PATH` | the directory the `.env` file is loaded from | `useEnvironmentPath()` |
| `LARAVEL_STORAGE_PATH` | the `storage/` directory | `useStoragePath()` |

Set them in the process manager, web server, or container environment — both
are read with `getenv()` before the app boots. When neither is set the
application uses its default in-project paths and behaves identically to a
stock install.

## OAuth signing keys

An AI client that signs in rather than pasting a token ([mcp.md](mcp.md), "Signing in from a
client") needs the site to hold an RSA pair. `php artisan passport:keys` writes
`storage/oauth-private.key` and `storage/oauth-public.key` (so `LARAVEL_STORAGE_PATH` relocates
them with the rest); `PASSPORT_PRIVATE_KEY` and `PASSPORT_PUBLIC_KEY` carry the same pair in the
environment instead. Without either the OAuth routes are not registered and the endpoint behaves
as a bearer-only one; the choice is made when the routes load, so a cached route table is rebuilt
after the keys change. Replacing the pair ends every access token at once; clients recover by
refreshing.

## Key invariants

1. With `OPENPNE_ENV_PATH` and `LARAVEL_STORAGE_PATH` unset, the hook is a no-op:
   it only relocates paths, never changes behavior.
2. `OPENPNE_ENV_PATH` MUST NOT be set inside `.env` — it is resolved before the
   `.env` file is loaded and is what tells the framework where that file lives.
3. A migration that drops a column the old code still writes, or adds a NOT NULL column the new code
   needs, runs with the site in maintenance mode (`php artisan down`): neither version of the code can
   run against the other's table ([group-boards.md](group-boards.md#the-board-key-is-bumped_at) is one).
4. Stored bytes are never served from a bare disk URL: every file, thumbnail and
   the custom stylesheet streams through the app, so an install never needs
   `storage:link` and the storage path can live anywhere `LARAVEL_STORAGE_PATH`
   points. The image fast path keeps this: it runs inside `public/index.php`, reads the cache disk
   by its filesystem path, and finds its own file under the same `LARAVEL_STORAGE_PATH`
   ([images.md](images.md), "The fast path"). A deploy that wants the file in place before the first
   request, or gone the moment the fast path is switched off, runs `php artisan openpne:image-fast-path`.

## Site timezone

`APP_TIMEZONE` (IANA name, default `UTC`) is the site's single clock. There is no
per-member timezone: stored wall-clock time and displayed wall-clock time are the
same value read in the same zone, on both surfaces.

That makes it a **deployment-time setting, not a runtime one**. Changing it on a
live site does not re-render existing rows into the new zone — it reinterprets
them, so every timestamp already stored silently shifts meaning. Set it before
the first write and treat a later change as a data migration.

| Variable | Effect |
|----------|--------|
| `APP_TIMEZONE` | The zone every timestamp is written and displayed in. Must be a canonical IANA name — aliases like `Etc/UTC`, `GMT` and `Japan` are rejected, so use `UTC` / `Asia/Tokyo`. Validated at boot (`App\Support\SiteTimezone`) because `date_default_timezone_set` only warns on a name it cannot use, leaving the previous zone silently in effect. |

Key invariants:

1. **The application stamps its own timestamps.** No connection timezone is
   configured, so the database clock is UTC on SQLite and the server's zone on
   MySQL — a row the app did not stamp would put a second clock in one column.
   Every write path passes `now()` explicitly, and no `created_at` column carries
   a database default: `friend_requests`, `friendships`, `member_blocks` and
   `group_join_requests` refuse an unstamped row rather than stamping it
   themselves (the upgrade importer maps each source timestamp explicitly). On
   MySQL that refusal rests on strict mode, which the connection config sets;
   without it an unstamped row stores a zero date. Two columns still default to
   the database clock: `failed_jobs.failed_at`, which Laravel always stamps
   itself, and the talk read cursor `group_members.talk_read_at`, a backstop
   described in [group-talk.md](group-talk.md), "Unread".
2. **The client formats in the site's zone, not the browser's.** Instants are
   serialized as offset-bearing ISO and the zone travels with them as the
   `timezone` shared prop, so Modern places them on the same clock Classic renders
   (`resources/js/lib/date.ts`). Formatting with the browser's zone is what made
   the two surfaces disagree by the viewer's offset. `Intl` and `toLocale*` are
   restricted to that module by eslint, so a new call site cannot reintroduce the
   drift; Modern renders instants through `<Timestamp>` / `<CivilDate>`, which bind
   the site's zone and locale and put the machine-readable value in `dateTime`.
   Where the display drops information — a day standing in for an instant — the
   element also carries the exact value as a native `title`. That title is a
   **mouse-only convenience and must stay non-essential**: `title` on a
   non-interactive element reaches neither the keyboard nor assistive technology, so
   anything a reader needs in order to act belongs in the visible text or `dateTime`.
   A display whose visible text stops naming the date at all needs a real
   disclosure, not a title.
3. **Instants and civil dates are different types.** An event's open date is a
   `Y-m-d` calendar day with no instant attached; reading one as an instant shifts
   it a day for viewers west of UTC. The client has separate formatters and will
   render a misrouted value verbatim rather than shift it.
4. **Times are stored as `DATETIME`, never `TIMESTAMP`.** MySQL converts a
   `TIMESTAMP` through the connection's `time_zone` on every read and write and
   refuses a value outside 1970 to 2038-01-19 UTC; a `DATETIME` keeps the wall-clock
   value as written. Migrations use `datetimes()` / `dateTime()`, and a schema test
   on the MySQL lane fails on any `TIMESTAMP` column.

### Upgrading from OpenPNE 3

OpenPNE 3 stores `DATETIME` as its server's wall clock, and the upgrade copies
those values through unchanged. So `APP_TIMEZONE` must already name the zone that
OpenPNE 3 ran in **before** the upgrade runs — otherwise every migrated timestamp
is off by the difference.

There is deliberately no migration that shifts existing rows. Once a database
holds both migrated OpenPNE 3 wall-clock values and rows OpenPNE 4 wrote under a
different zone, the values alone cannot say which is which, and a blanket shift
would corrupt one of the two sets. Set the zone first; a database that already
mixed them needs a re-import, not a conversion.

## Reverse proxy & HTTPS

The app almost always runs behind a reverse proxy (the fleet edge, or a
self-hoster's nginx/Caddy/Cloudflare). Two settings make it see the real client
instead of the proxy. `TRUSTED_PROXIES` is read as configuration
([`config/trustedproxy.php`](../../config/trustedproxy.php)): from `.env`, unless the process
environment has the variable, even an empty one, and a cached configuration holds the value it was
built with. A non-empty value in the web server's process environment is read on every request as
well ([`bootstrap/app.php`](../../bootstrap/app.php)) and comes before a cached one.
`config/openpne.php` reads the others:

| Variable | Effect |
|----------|--------|
| `TRUSTED_PROXIES` | Proxy IP/CIDR list (comma-separated) or `*`. Makes `$request->ip()` and the HTTPS check read `X-Forwarded-For`/`-Proto`. Empty = trust none. |
| `OPENPNE_FORCE_HTTPS` | Force `https://` URL generation + a `Secure` session cookie. Defaults on when `APP_ENV=production`. |
| `OPENPNE_OPERATOR_RAW_HTML` | Print administrator-written page content as raw HTML instead of Markdown ([security.md](security.md#administrator-content)). Off by default. |

Why this matters, not just hardening:

1. **Rate limits bind to the real client.** The `login` and `register-email`
   limiters key on `$request->ip()`. With no trusted proxy that is the proxy's
   address, so every client shares one bucket and the per-IP limit silently
   stops working. `TRUSTED_PROXIES` must name the proxy for the limiters to mean
   anything in production.
2. **Generated links stay HTTPS and on the right host.** `OPENPNE_FORCE_HTTPS`
   keeps password-reset/registration links `https://` even when TLS terminates
   upstream; `trustHosts()` (pinned to `APP_URL`, enforced outside local/testing)
   rejects a forged `Host` so it cannot poison those links. `X-Forwarded-Host`
   is intentionally **not** trusted — the validated `Host` is authoritative.

The deployment side (nginx `real_ip` / passing `X-Forwarded-*`) is the
operator's/hosting layer's responsibility; this app only consumes the headers
once `TRUSTED_PROXIES` says the proxy may set them.

## Static asset delivery

The JavaScript and CSS under `public/build/assets/` are most of what a first visit downloads, and
how the web server sends them decides how long that visit takes. Two things are expected of it:

- **Compress static text files** (`.js`, `.css`, `.svg`).
- **Mark `public/build/assets/*` as `Cache-Control: public, max-age=31536000, immutable`.** Every
  file there has a content hash in its name, so a new build is a new URL. Only a file that exists
  may carry the header: a 404 for an asset that a deploy has not placed yet must stay uncached.

[`public/.htaccess`](../../public/.htaccess) does both on Apache where `mod_deflate`, `mod_headers`
and `mod_setenvif` are loaded, and nothing where they are not. Any other server (nginx, Caddy) needs
the same two rules in its own configuration.

The scripts and stylesheets the Classic screens load from elsewhere under `public/` (`js/`, `css/`,
the OpenPNE 3 plugin directories, the theme packages) keep their paths and are linked as
`<path>?v=<16 hex digits>`, a digest of the file's bytes keyed with the application key. A file
whose bytes are new has a URL no cache has seen, so a page from a new release does not run the
previous release's script or stylesheet out of one. Images, and the files a stylesheet names through
`url()`, carry no version. Three things follow for a deployment:

- **A cache in front keys on the query string.**
- **These URLs must not be given the long-lived header.** The web server answers any `v` with the
  file it has now, so a URL an earlier release linked may hold a later release's file. A rollback,
  or a release that returns a file to bytes it had before, links that URL again and gets what the
  cache holds for as long as the cache keeps it.
- **A new page is served only once every origin has the new files**: the version is computed from
  the files beside the code that rendered the page.

Replacing the application key changes every version once.

The shipped rules leave responses from `index.php` alone. Compressing a page that carries a CSRF
token next to reflected input is the BREACH side channel, so whether to compress dynamic responses
is the operator's decision.

## Apache .htaccess files

The release has two, and they use only directives that `AllowOverride FileInfo` permits, so a host
that forbids `Options` or `Require` in `.htaccess` still runs the site. The one at the top of the
tree answers every request with 403, for a document root that is the tree, or holds it, instead of
being `public/`. `public/.htaccess` has rewrite rules of its own, which Apache applies under
`public/` in place of the top file's; it sends a request for an existing directory to `index.php`,
so Apache lists none. Two server settings change that. With `DirectorySlash Off` and `Indexes`,
mod_rewrite skips a request for the directory of either file that lacks the trailing slash, and
Apache lists the names in it; by default it leaves out the entries it refuses, so the top one shows
only `public/`. With `RewriteOptions InheritDown` where the top file is read, `public/` takes its
rule as well and the whole site answers 403. Neither file sets `RewriteOptions` to get round that, because one set in
a `.htaccess` replaces the server's, and the server's own rules would then stop reaching `public/`
unnoticed. A `.htaccess` with rewrite directives anywhere else in
the tree would lift the 403 for its directory, and the release has none. A server that hands `.php`
requests to PHP-FPM by `ProxyPassMatch` does so before reading any `.htaccess`, so there the 403
covers the other files only.

## Cache reads

Site settings, terms, navigation rows and gadget rows are cached under one key each, and a request
asks for them many times over: every feature-unit check is a settings read. Those services read
through Laravel's memoized store (`Cache::memo()`), so **a key is fetched from the cache store once
per request, queued job or console command** and answered from memory after that. With
`CACHE_STORE=database`, the default, each fetch is a query.

Key invariants:

1. **A forget goes through the same memoized store.** `Cache::forget()` would empty the store and
   leave the rest of the request answering from memory. Each service's `clearCache()` forgets
   through it, and so does the migration that rewrites a setting without going through the service.
2. **What another process writes and clears is seen from the next request on.** A request that has
   read a key keeps that answer until it ends, which is the consistency a single page wants and no
   different from having read the key a moment earlier. A console command keeps it until it exits,
   however long it runs.
3. **A cold key is fetched twice.** The miss is memoized, the write that follows forgets it, and the
   next read fetches what was written; a warm key is fetched once.

Counters that must be read fresh, the login throttle among them, stay on the plain store.

## Scheduled tasks

A deployment should run Laravel's scheduler — `php artisan schedule:run` every
minute from cron (or a systemd timer). Without it the weekly prune of unreferenced
link cards and the image each holds ([`routes/console.php`](../../routes/console.php))
never runs, and those rows accumulate. The daily prune of expired pending tokens —
registration, email-change and MFA-reset links — is registered here too but does
not depend on it: the first signed-in request of each site day runs the same prune
after its response (`TickHousekeeping`, one cache lock per day), as does the sweep of
spent OAuth codes, tokens and never-used clients (`openpne:prune-oauth`). Nor does the daily
06:00 home issue: the Modern front page publishes the issue that is due when it is
first read after 06:00 ([home-issues.md](home-issues.md#publish-on-read)), so the
scheduled run only moves publication from that first visit to 06:00 itself.
`php artisan schedule:list` shows what is registered.

Every task is due at a minute that is a multiple of five, so cron every five minutes, the line
`openpne:setup` prints, reaches each one.

Each runs at a fixed time read in the site's own clock (`APP_TIMEZONE`), so an
operator running many sites on one host gets them together and has no lever here
to stagger them.

## Initial data

`php artisan openpne:install` runs the migrations and then `Database\Seeders\InstallSeeder`, which
writes the default profile fields, navigation, gadget layout and banner slots a new site starts with.
The seeder runs once: when `navigations`, `gadgets` or `profiles` already has a row it writes nothing
and says so, because rows there are an administrator's work (or an upgrade's) and reseeding would
restore what they removed. `banners` does not count — opening the admin Banner page creates its rows.
The seeders it calls are an implementation detail; a deployment runs `openpne:install` (or
`db:seed --class=Database\Seeders\InstallSeeder`) and `openpne:admin:create`, not the seeders by name.
A site being upgraded from OpenPNE 3 is not installed this way: the upgrade brings those rows from
the source and refuses a schema that already holds them ([upgrade.md](upgrade.md#source-preflight)).

## Row ids

A deleted row's id is never handed to a new row: whatever still names that id (a file's owner, a URL
in a body) would attach to the stranger. Both engines keep a high-water mark per table, SQLite's
`sqlite_sequence` (tables are `AUTOINCREMENT`) and MySQL's `AUTO_INCREMENT`, and two places keep it
where the engine would not:

- `openpne:copy-database` carries each table's mark to the target
  ([changing-database-engine.md](../changing-database-engine.md#copying-the-database)).
- On SQLite, Laravel rebuilds a table to apply `change()`, an added or dropped foreign key, a
  primary key change, or (before SQLite 3.35) `dropColumn()`, and the rebuild resets the mark to
  the highest id left. `App\Database\SQLiteConnection` restores it after each schema Blueprint,
  failed ones included, so an insert later in the same migration does not reuse an id. Only a
  rebuild inside one Blueprint is covered: a migration that rebuilds a table across several
  (creating a copy, copying the rows, dropping the original, renaming the copy) or in raw SQL
  restores the mark itself. The rebuild also drops the table's triggers and nothing puts them back,
  so a migration that may rebuild a table holding triggers drops them if they exist and creates them
  again: a `dropColumn()` rebuilds below SQLite 3.35 and keeps them from 3.35. Doing that in one
  transaction with the rebuild means turning foreign keys off before the transaction opens: SQLite
  ignores the pragma inside one, and the rebuild's `DROP TABLE` would cascade.

Nothing restores a mark lowered another way: `TRUNCATE`, an `ALTER TABLE … AUTO_INCREMENT` that
lowers it, a dump restored without it, an upgrade from OpenPNE 3 (it carries rows, not marks), a
rebuild run before this existed, or a row another process inserts between a rebuild and the
restore, which is one reason an update runs in maintenance mode ([updating.md](../updating.md)).

## Release marker

A deployment may place a `RELEASE` file at the app root, `key=value` lines, one per line; a release
archive ([updating.md](../updating.md), "From a release archive") carries one with `version` and `sha`.
The app reads only `sha` (`App\Support\ReleaseMarker`): the upgrade's run report carries it as
`release`, the run's first write stores it as the build that started the run, and the
completion marker, `openpne:upgrade-status` and `openpne:verify-upgrade` report it, so a fleet
operator can tell which build migrated a site. No file, or no `sha` line, reports `null`;
nothing else in the app depends on it.

## Failure replies

A reply that is not an Inertia page — the maintenance page, an uncaught 500, an
expired session's 419 — would otherwise appear in Inertia's raw overlay, which is
not gated by environment. The Modern app cancels that overlay and shows its own
dialog instead, worded by status and leaving the page as it was, in development
too (`resources/js/lib/http-exception.ts`; the trace is in the log). An Inertia
page that carries an error status is left to the router. A full navigation that
meets a 5xx gets `errors/plain.blade.php` in every realm, the admin panel
included: it reads no settings and carries no `%term%` placeholder, because the
database is what tends to be broken when one is shown. 403, 404 and 419 on a full
Classic navigation keep their OpenPNE 3 rendering
([classic-compatibility.md](classic-compatibility.md)).

## Queue

Notifications, mail, web push, the link-card fetch and the drawing of a posted picture's
variants ([images.md](images.md), "Drawn when posted") are queued, on the `database`
connection by default, which needs a worker (`php artisan queue:work`; job timeouts
need `ext-pcntl`, and a worker is restarted by `queue:restart` after a deploy). A
host that cannot keep one running sets `QUEUE_CONNECTION=deferred`: each job then
runs in the same process **after the response has been sent**, so a post answers
once its rows are written and pays for its notifications, its push sends and its
link-card fetch afterwards, out of the member's wait (a posted picture's variants are
not drawn there at all). Each deferred job runs on its
own and a failure is logged, not retried and not kept in `failed_jobs`; the jobs of a
request that failed (a 4xx or 5xx, even after its writes committed) are dropped
with it, and a job queued by a console command runs only when the command exits
with 0. The client sees the end of the response only because a response with a
string body declares its `Content-Length` (`DeclareContentLength`, measured once
the body is final; a streamed or file reply is not covered): under mod_php a
response without one is held open until the script ends. A proxy that strips the
header, or a compressing module that recomputes it, puts that wait back. A web
request that runs an artisan command in-process drops the jobs queued before it,
and `max_execution_time` counts the deferred work with the request: a timeout
there is fatal and takes the remaining jobs with it.

A delayed job runs at once on `deferred` as on `sync`: the grace a talk message
waits before notifying, so that a member reading the room is not notified of what
they have just read ([group-talk.md](group-talk.md)), is waited only by a worker.
`sync` itself — every job inside the request, a failing job failing the request —
is kept for tests. A host with cron but no worker keeps the grace by running
`php artisan queue:work --stop-when-empty` from cron instead.

## Site check

`php artisan openpne:check` names what a site lacks, a line each with the fix, and exits 1 when
anything does; a warning or a line it could not check does not count. It has three parts. What can
be known before installing runs without `.env` (`--only=before`, which the release archive's check
runs). The installed site reads `.env`, the database and the files beside the release. The web part
fetches the site from `APP_URL`, through [`SiteFetcher`](../../app/SiteCheck/SiteFetcher.php), the
outbound seam's second exception ([outbound-http.md](outbound-http.md)): no redirect is followed, and
no more than 64 KiB of a response is read.

The web part writes files into the site's own directories for the length of the check, named
`openpne-check-` and 32 hex digits, and removes them in a `finally`, and on SIGINT, SIGTERM or SIGHUP
where pcntl's signal functions can be called:

- a text file of one name, each holding its own token, in `public/`, in the release's top directory
  and in `storage/` when that is the release's own. Fetched at `APP_URL/<name>`, the right document
  root answers with the `public/` file's token; any other token is a file of the release being served,
  and until the `public/` file answers, the release is not judged unserved. With `--parent-url`, the
  address that shows the directory holding the release, a file in that directory proves the address,
  and the release's and `openpne-data/`'s files must not answer through it; with either of the first
  two unwritten, that address is not checked.
- a PHP file in `public/` that does not boot the app, so that it answers when `.env` cannot be read,
  and that parses on old PHP, since naming an old web PHP is what it is for. It answers only a request
  carrying its token, with the PHP version, SAPI, extensions, a few ini values, the names of the proxy
  headers present and the peer address, and writes an empty file into `storage/framework/` whose owner
  the command compares with its own: a web PHP running as another user, or unable to write there,
  fails. Proxy headers with `TRUSTED_PROXIES` empty, or a peer outside a set `TRUSTED_PROXIES`, are
  warnings; nothing seen becomes configuration, and the request is the server's own, whose path can
  differ from a member's.

One web check runs at a time: an exclusive lock on `storage/framework/openpne-check.lock`, not waited
for, and a second check reports the web part as not checked. Under that lock the files of a killed
run, or the owner file of a probe that outlived its fetch's timeout, are removed only when over ten
minutes old, so a running check's files are never taken for a misconfiguration.

Where `openpne-data/` exists beside the release, the check takes it for a directory that must stay
unserved even where a host serves the release's parent, and requires in it an `.htaccess` that is the
release's top one byte for byte (what `openpne:setup` writes there), mode 0700, and for MySQL the option
file its dump reads, `mysql-backup.cnf`, at 0600, with a schema-only dump through it that still succeeds.

## Site setup

`php artisan openpne:setup` is the questions-and-answers way in for a shared host: it writes a new
site's `.env`, then runs `openpne:install`, `openpne:admin:create` and `openpne:member:invite`
unchanged, waiting on `openpne:check`'s web part in between. It runs only on a terminal. Run again,
it goes on from the first step not yet done: `openpne-data/`, `.env`, the MySQL option file,
`APP_KEY`, an administrator, the web check. `openpne:install` runs every time, being a no-op on an
installed site, except where an administrator exists and a migration has not run, which is a new
version unpacked over a live site and stops the command; a failed `openpne:install` stops it too.

Key invariants:

1. **One run at a time for a site**: a lock on `storage/framework/openpne-setup.lock` is taken before
   anything is read, and the run that wrote `.env` hands it to the run it starts on descriptor 3.
   Once it is held, what a killed run left beside `.env` and in `openpne-data/` is removed.
2. **`.env` is written only where none exists**, from `.env.example` with the answers and a new
   `APP_KEY` set, through a file beside it at 0600 that is renamed into place once `Dotenv::parse`
   reads every answer back. An answer `Env` or Dotenv would read otherwise (`true`, a quoted value,
   `${`, a control character, `"` with `\` or `'`, a lone `"` right after `=`) is refused before it
   is written. The later writes are `APP_KEY` and `TRUSTED_PROXIES`, each only while `.env` holds it
   empty and through the same file beside it, the first never over a database that holds an
   administrator and the second typed by the operator, never taken from what the web check saw;
   every other key must read back unchanged. A `.env` without `APP_URL` or `DB_CONNECTION` is not
   gone on with, and a database that holds an administrator already, a `database-<n>.sqlite` in
   `openpne-data/` included, is not given a new `.env`.
3. **The process that wrote `.env` does not go on with it**: it runs the command again through
   `proc_open`, its environment stripped of the keys `.env` holds, and where `proc_open` is disabled
   asks to be run again. Where the environment gives `APP_KEY`, `DB_URL` or a `DB_` connection key
   otherwise than `.env`, which the web reads alone, it stops before the database is touched.
4. **`openpne-data/`** is made beside the release, and on every run set to 0700 and given the
   release's top `.htaccess` as the code holds it (through a file beside it), before anything goes
   in, in place of any other and whether or not the top one is still in the
   release; the SQLite database (made only where nothing has its name, a broken link included), the
   MySQL option file (`mysql-backup.cnf`, 0600) and the backups go there. The backup line it prints
   writes `backup.sqlite` through `openpne:backup-sqlite` ([SQLite backup](#sqlite-backup)) or
   `backup.sql` beside a `.new` copy moved over the last, and refuses a path holding a character a
   shell or cron reads as its own. The MySQL line runs in a subshell under umask 077, so `backup.sql`
   is 0600 as `backup.sqlite` is while the shell keeps its umask. The same copy is written once into
   a 0600 file there, judged by a table read back from it, and removed: for SQLite in the process,
   without the command's lock and rename, and for MySQL by the dump where `proc_open` allows.
   The SQLite line and the cron line name PHP by `PHP_BINARY`, which has its links resolved, except
   where that path holds the patch release, which a host's update can take away: there by the first
   `php<major>.<minor>`, then `php`, on PATH that is the same file and outside such a path. A
   `mysqldump` that is MariaDB's is named by the `mariadb-dump` on PATH that is the same file, else
   by the file it links to, as MariaDB's tool called `mysqldump` warns at every run.
   `openpne:check` names both the same way, and a sentence of either command says `php` where
   PATH's `php` is that PHP.
5. **A failed `openpne:install` ends the run** with what it said, a migration killed midway
   included, as what it made is in the way of the next. Only where the database holds neither a
   member nor an administrator does it say how to start over: in a new, empty database (for SQLite an
   unused file name in `openpne-data/`), the one given left as it is. It never says to remove one, as
   one given may hold another application's tables, and empties none itself.
6. **`storage/` and `bootstrap/cache` are closed to other users only after** the web check has shown
   the web's PHP running as this account.
7. **SQLite is offered only where the check passes it**: `pdo_sqlite` on SQLite 3.26.0 or later and
   the `sqlite3` extension, which takes the backup, both judged by
   [`SqliteSupport`](../../app/SiteCheck/SqliteSupport.php); a version that cannot be read is not
   taken for too old. Before a site is installed, the check names what SQLite lacks; where MySQL is
   all that is left, setup asks whether to go on before asking for the server.

## SQLite backup

`php artisan openpne:backup-sqlite <path>` copies the site's SQLite database while the site reads and
writes it, through SQLite's online backup in PHP's `sqlite3` extension
([`SqliteBackup`](../../app/Database/SqliteBackup.php)). Every page is copied in one step, inside one
read transaction: under WAL no writer waits for it, and in a rollback journal a commit waits for the
copy to end, failing where that passes the site's minute. The command waits up to a minute for a
lock the site holds. The installed check fails an SQLite site without the extension, or below
SQLite 3.26.0, and does not try the copy, which takes as long and as much disk as the database.

Key invariants:

1. **Nothing is written where it would reach the database or another run's files**: `<path>`,
   `<path>.new`, `<path>.lock` and the `-journal`, `-wal` and `-shm` beside the first two are
   compared with the database and the files beside it, by name, with links followed, and by device
   and inode, before anything is opened. A `<path>` ending as those files do (`.lock`, `.new`,
   `-journal`, `-wal`, `-shm`, in any case) is refused, and so is a `<path>.lock` that is a link or not a file.
2. **One run at a time for a path**: an exclusive lock on `<path>.lock`, not waited for and never
   removed, held until the copy is renamed into place and what was beside it removed. A second run
   fails without touching anything.
3. **The copy is written whole beside the last**: into `<path>.new` (0600), removed first with what a
   killed run left beside it, and renamed over `<path>` only once the backup finished, the copy's
   journal mode is `delete` and the file is flushed to disk. A run that fails, or a power cut, leaves
   `<path>` holding the last copy or this one, never part of one. That this one is still there after
   a power cut right after the run needs the directory flushed too, which is done where the file
   system can.
4. **No flush inside the read transaction**: SQLite would flush the copy before it ends the backup's
   read transaction, holding commits up for as long as the disk takes, so the copy is written with
   `synchronous` off and flushed after.
5. **The copy is out of WAL**: the backup takes the database's header, WAL mark included, and a
   `-wal` file found beside a copy is read into it.
6. **A last copy with a `-journal`, `-wal` or `-shm` beside it is not replaced**: something has it
   open, or stopped while writing to it.

## Database server

`openpne:setup`, `openpne:check`, `openpne:install` and `openpne:copy-database` read the server
behind a MySQL or MariaDB connection from its `VERSION()`, through
[`DatabaseServerProbe`](../../app/Database/DatabaseServerProbe.php), and
[`DatabaseServer`](../../app/Database/DatabaseServer.php) is the one place that decides which servers
they take, MariaDB from 10.11 (`supported()`), and which driver each takes: `mariadb` for MariaDB,
`mysql` for MySQL (`driver()`). A server without `utf8mb4_0900_bin`, MariaDB 10 among them, refuses
the `mysql` connection's session with error 1273, so on that error the probe asks again through a
session that names no collation.

- Setup takes back the answers for a MariaDB below 10.11 and offers SQLite again, or, where the host
  has no SQLite, asks for another server; for a server it takes, it writes that server's driver as
  `DB_CONNECTION`. A `.env` naming a server below the floor, or a driver other than the server's,
  stops it before `openpne:install`.
- Check fails the site on either, even where the connection works.
- Install stops on either before it migrates, with setup's message in the shell's language, else the
  site's, and also on a `DB_URL` whose scheme sets a driver other than the server's, which overrides
  `DB_CONNECTION`'s and which setup and check do not weigh. It reads the server through a session
  that names no database, as `migrate` creates a database that is missing; where it cannot read the
  server, it leaves the failure to `migrate` to report.
- Copy-database refuses the `mariadb` driver and a MariaDB server on either side before writing
  anything.

Elsewhere in these internals, MySQL also means MariaDB 10.11 or later unless a passage says
otherwise.

### Table definition cache

A MySQL server that holds many sites' databases needs `table_definition_cache` larger than
`table_open_cache`, with room to spare. Otherwise, once the open tables hold more table definitions
than `table_definition_cache` allows (`Open_table_definitions` above it), a prepared `DELETE` on a
table whose children are `ON DELETE CASCADE` or `SET NULL` fails with error 1615 (`Prepared
statement needs to be re-prepared`), as does an `UPDATE` on a parent of an `ON UPDATE CASCADE` child.
Retrying does not help; `FLUSH TABLES` clears it only until the tables fill the cache again. This
passage was measured on MySQL 8.4, not on MariaDB.

A new site's `migrate` sends no such statement to its empty database.

## Bound parameters

SQLite before 3.32 takes at most 999 bound parameters in one statement by default, and the limit
cannot be raised at run time. An id list that grows with the site's data (a member's friends, the
replies to a post, a fan-out chunk) goes into SQL through `whereIntegerInRaw()` /
`whereIntegerNotInRaw()`, which write the integers into the statement and bind none. A list that
has to bind, such as uuid keys or the rows of a multi-row insert, is split under the limit.

Two admin paths still bind per row they change, and fail past the limit on such SQLite: attaching
banner images (`sync()` in `BannerSettings`, a multi-row insert), and Filament's table reorder on
every reorderable admin table (one key per row).
