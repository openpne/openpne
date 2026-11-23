# Updating between releases

Moves a site from one tagged release to a later one. (Coming from OpenPNE 3 is a different
operation: [upgrading-from-openpne3.md](upgrading-from-openpne3.md).)

The site is down for the whole update. A release can carry a migration that neither version of the
code can run across ([invariant 3](internals/runtime.md#key-invariants)), and these steps do not ask
whether this one does: every update is done in maintenance mode.

The steps are written for a site that is a git checkout. A site installed from a release archive
follows [From a release archive](#from-a-release-archive), which says where it differs.

## Before you start

- **Read the notes of the release you are moving to**, and of each release in between if you are
  crossing several. While the version carries `-alpha`, a release may ask for a manual step.
- **A migration is not undone by going back.** The way back from a failed update is the backup you
  take in step 2, restored under the release you came from. Checking out the older release against
  a database the newer one has migrated leaves old code running on a schema it does not know.
- **A site that has been following `main`** moves to a release only if that release contains the
  commit the site is on. Check it with the tag you are about to check out:

  ```bash
  git fetch --tags origin
  git merge-base --is-ancestor "$(git merge-base HEAD origin/main)" v4.0.0-alpha.N && echo ok
  ```

  No `ok` means the release does not contain your commit (or `origin/main` is not the branch the
  site was cloned from). The site's database may then hold migrations the release's code does not
  have, which `migrate` does not report. Wait for a release that contains your commit.

- **A SQLite site needs the `sqlite3` extension on the command line**, for the copy step 2 takes.

## Steps

Run them in the app root, as the user the site runs as.

1. **Stop the site.**

   ```bash
   php artisan down --render="errors::503"
   ```

   `--render` draws the maintenance page now and serves it to a page request without starting the
   application, so a page request that arrives while the code is being replaced still gets it. (A
   request that asks for JSON is still handed to the application.) A queue worker
   takes no new job while the site is down; wait for the job it is running, if any, to finish
   before going on.

2. **Back up**, now that nothing is writing: a dump of the database, and `.env` if it is not
   already kept somewhere else. With the default `OPENPNE_FILES_DISK=blob` the stored files are in
   that dump. If the setting names a disk, copy what the disk holds at the same moment, so the
   rows and the files they point to match. For SQLite, this writes a consistent copy of the
   database the site's settings name:

   ```bash
   php artisan openpne:backup-sqlite /path/to/copy.sqlite
   ```

   An earlier copy at that path is replaced only once the new one is whole, and `copy.sqlite.lock`
   is left beside it for the next run. The command refuses a path that would write over the
   database or a file beside it.

3. **Remove the caches the running release built**, while its code is still the one checked out:

   ```bash
   php artisan config:clear
   php artisan route:clear
   php artisan view:clear
   php artisan event:clear
   php artisan filament:optimize-clear
   ```

   Not `optimize:clear`: that also runs `cache:clear`, which empties the application cache — the
   login throttle's counters among what it holds.

4. **Check out the release.**

   ```bash
   git fetch --tags origin
   git checkout v4.0.0-alpha.N
   ```

   The checkout leaves any commit of your own behind. A site that carries local changes brings
   them onto the release its own way, in place of this step.

5. **Look at what the release added to `.env.example`** and give your `.env` what it needs before
   anything runs under the new code (`vX` is the release you came from):

   ```bash
   git diff vX v4.0.0-alpha.N -- .env.example
   ```

6. **Install what it needs and build the frontend.**

   ```bash
   composer install --no-dev --optimize-autoloader
   npm ci
   npm run build
   ```

7. **Migrate.**

   ```bash
   php artisan migrate --force
   ```

8. **Make the running processes load the new code.** Reload PHP-FPM (or whatever serves PHP) so
   OPcache drops the old files, then:

   ```bash
   php artisan queue:restart
   ```

   That makes each worker exit once its current job is done; whatever supervises the worker starts
   it again, and one started by hand has to be started again by hand. If your deployment runs
   `php artisan optimize`, or caches the configuration or the routes some other way, do that again
   here.

9. **Open the site.**

   ```bash
   php artisan up
   ```

## If a step fails

Leave the site down and go back to the release you came from:

1. Put back `.env` if you changed it.
2. Check out that release and run step 6 for it.
3. Restore the database **into an emptied one** — drop it and create it again first, or drop
   every table in it. A dump
   replaces only the tables it contains, and a migration that ran before the failure may have
   created one it does not; left behind, that table stops the next attempt at `migrate`. (For
   SQLite, stop the queue worker first, so that nothing holds the database open; then put the
   copied file back in place of the database file and remove the `-wal` and `-shm` files beside
   it, which belong to the file it replaces.)
4. Restore the disk's files, if you copied them in step 2.
5. Do step 8, start the queue worker again if you stopped it, then `php artisan up`.

## From a release archive

A release that has `openpne-<version>.tar.gz` and `openpne-<version>.zip` among its assets can be
installed and updated from either: the release's tree with `vendor/` and the built frontend in it,
so the server needs neither Composer nor Node. Check a download against the release's `SHA256SUMS`
(`sha256sum --check --ignore-missing SHA256SUMS`).

A release without them cannot be reached this way. A site that updates from archives waits for the
next release that has one, or builds this one from source on another machine; the archive of an
earlier release is not this release.

A site installed by [installing-on-a-shared-host.md](installing-on-a-shared-host.md) updates as that
guide says, in the directories it sets up, rather than by the steps below.

An archive replaces the whole directory, so the steps differ wherever the ones above use git,
Composer or npm. Below, the site is at `/srv/openpne/site`, as [installing.md](installing.md) sets
one up, runs `OLD` and moves to `NEW`; use your own path and versions. The user the site runs as
has to be able to write to the directory above it (`/srv/openpne` here), and unpacks the archive
itself, so that the new tree is its own.

- **Before you start**: there is no `main` to have followed, so that check does not apply.
- **Step 1**, and then **stop the queue worker** rather than wait for it: stop it in whatever
  supervises it, or take its line out of cron. A worker left running keeps the old directory's
  code, and the old directory's database file if the database is SQLite inside it. With
  `QUEUE_CONNECTION=deferred` there is no worker.
- **Steps 2 and 3** as written.
- **In place of steps 4 to 6**:

  1. Unpack the release beside the site, not over it: a file the new release no longer has would
     stay behind and still be loaded.

     ```bash
     tar -xzf /srv/openpne/openpne-NEW.tar.gz -C /srv/openpne   # makes /srv/openpne/openpne-NEW
     ```

  2. Carry the site's own files into it.

     ```bash
     cp -a /srv/openpne/site/.env /srv/openpne/openpne-NEW/.env
     rm -rf /srv/openpne/openpne-NEW/storage
     cp -a /srv/openpne/site/storage /srv/openpne/openpne-NEW/storage
     chmod o-rwx /srv/openpne/openpne-NEW/bootstrap/cache    # if the site's own is closed to others
     # what the release added:
     diff /srv/openpne/site/.env.example /srv/openpne/openpne-NEW/.env.example
     ```

     Give `/srv/openpne/openpne-NEW/.env` what the last command shows it needs. The copy of
     `storage/` takes the room and the time of everything in it, stored files included when
     `OPENPNE_FILES_DISK` names a local disk. A site that keeps `.env` or `storage/` outside the
     app root ([runtime.md](internals/runtime.md)) carries neither, and runs the `chmod` line all
     the same: `bootstrap/cache` is in the release's tree.

     - **A directory or a file you added** comes along as it is, a theme of your own for one.
       Copy what is yours, not the directory around it: the themes a release ships are the new
       release's.

       ```bash
       cp -a /srv/openpne/site/public/opSkinThemePlugin/NAME \
         /srv/openpne/openpne-NEW/public/opSkinThemePlugin/NAME
       ```

     - **A file of the release that you edited** (`public/.htaccess`, `public/robots.txt`) takes
       your edit, not your file, or the new release's own changes to it are lost. With the
       archive of the release the site runs:

       ```bash
       tar -xzf /srv/openpne/openpne-OLD.tar.gz -C /tmp
       diff -u /tmp/openpne-OLD/public/.htaccess /srv/openpne/site/public/.htaccess \
         > /tmp/htaccess.patch
       patch /srv/openpne/openpne-NEW/public/.htaccess < /tmp/htaccess.patch
       ```

       If `patch` rejects a part, stop and bring that part over by hand.
     - **The database**: nothing for MySQL, and nothing for a SQLite file that `DB_DATABASE` names
       by a path outside the app root. For SQLite at the default `database/database.sqlite`, put
       the copy step 2 made at `/srv/openpne/openpne-NEW/database/database.sqlite`. Do not copy
       `database.sqlite` itself: under WAL the last rows committed can still be in the `-wal` file
       beside it ([changing-database-engine.md](changing-database-engine.md)).

  3. Put the release at the site's path, so the web server, cron and the worker's supervisor go on
     pointing where they did, and enter it again: a shell that was in `/srv/openpne/site` is in the
     directory that was moved away, whatever its prompt says.

     ```bash
     mv /srv/openpne/site /srv/openpne/openpne-OLD
     mv /srv/openpne/openpne-NEW /srv/openpne/site
     cd /srv/openpne/site
     ```

     `/srv/openpne/openpne-OLD` must not exist beforehand: `mv` would put the site inside it.

- **Step 7** as written.
- **Step 8**: reload PHP-FPM, run `php artisan optimize` if your deployment does, and **start the
  worker** you stopped.
- **Step 9** as written.

**If a step fails**, leave the site down and go back to the directory you came from:

1. Stop the queue worker, if step 8 had started it.
2. If the release had been put at the site's path, move the directories back and enter the old one
   again. If the failure came before that, the site is where it was: move nothing.

   ```bash
   mv /srv/openpne/site /srv/openpne/openpne-failed
   mv /srv/openpne/openpne-OLD /srv/openpne/site
   cd /srv/openpne/site
   ```

   `/srv/openpne/openpne-failed` must not exist beforehand either.

3. Do steps 1, 3 and 4 of [If a step fails](#if-a-step-fails): the `.env` if it is kept outside the
   app root and you changed it, the database, the disk's files.
4. `php artisan view:clear`. With `storage/` outside the app root the two releases share the
   compiled views, and the old release would go on drawing the new one's.
5. Reload PHP-FPM, run `php artisan optimize` if your deployment does, start the worker, then
   `php artisan up`.
