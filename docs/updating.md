# Updating between releases

Moves a site from one tagged release to a later one. (Coming from OpenPNE 3 is a different
operation: [upgrading-from-openpne3.md](upgrading-from-openpne3.md).)

The site is down for the whole update. A release can carry a migration that neither version of the
code can run across ([invariant 3](internals/runtime.md#key-invariants)), and these steps do not ask
whether this one does: every update is done in maintenance mode.

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

## Steps

Run them in the app root, as the user the site runs as.

1. **Stop the site.**

   ```bash
   php artisan down --render="errors::503"
   ```

   `--render` draws the maintenance page now and serves it without starting the application, so a
   request that arrives while the code is being replaced still gets that page. A queue worker
   takes no new job while the site is down; wait for the job it is running, if any, to finish
   before going on.

2. **Back up**, now that nothing is writing: a dump of the database, and `.env` if it is not
   already kept somewhere else. With the default `OPENPNE_FILES_DISK=blob` the stored files are in
   that dump. If the setting names a disk, copy what the disk holds at the same moment, so the
   rows and the files they point to match. For SQLite,
   `sqlite3 database/database.sqlite ".backup '/path/to/copy.sqlite'"` writes a consistent copy.

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

1. Check out that release and run step 6 for it.
2. Put back `.env` if you changed it.
3. Restore the database **into an emptied one** — drop it and create it again first. A dump
   replaces only the tables it contains, and a migration that ran before the failure may have
   created one it does not; left behind, that table stops the next attempt at `migrate`. (For
   SQLite, put the copied file back in place of the database file.)
4. Restore the disk's files, if you copied them in step 2.
5. Do step 8, then `php artisan up`.
