# Updating between releases

Moves a site from one tagged release to a later one. (Coming from OpenPNE 3 is a different
operation: [upgrading-from-openpne3.md](upgrading-from-openpne3.md).)

The site is down for the whole update. A release can carry a migration that neither version of the
code can run across ([internals/runtime.md](internals/runtime.md), "Key invariants"), and these
steps do not ask whether this one does: every update is done in maintenance mode.

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

  No `ok` means the site is ahead of that release: its database already holds migrations the
  release's code does not have, and `migrate` reports nothing to do. Wait for a release that
  contains your commit.

## Steps

Run them in the app root, as the user the site runs as.

1. **Stop the site.**

   ```bash
   php artisan down
   ```

   A queue worker takes no new job while the site is down. Wait for the job it is running, if any,
   to finish before going on.

2. **Back up the database and the stored files together**, now that nothing is writing to either:
   a dump of the database, and a copy of the storage directory's `app/`. Taken at the same moment,
   the rows and the files they point to match. For SQLite,
   `sqlite3 database/database.sqlite ".backup '/path/to/copy.sqlite'"` writes a consistent copy.
   Keep `.env` with them if it is not already kept somewhere else.

3. **Remove the caches the running release built**, while its code is still the one checked out:

   ```bash
   php artisan config:clear
   php artisan route:clear
   php artisan view:clear
   php artisan event:clear
   ```

   Not `optimize:clear`: that also runs `cache:clear`, which empties the application cache — the
   login throttle's counters among what it holds.

4. **Check out the release.**

   ```bash
   git fetch --tags origin
   git checkout v4.0.0-alpha.N
   ```

5. **Install what it needs and build the frontend.**

   ```bash
   composer install --no-dev --optimize-autoloader
   npm ci
   npm run build
   ```

6. **Look at what the release added to `.env.example`** and decide whether your `.env` wants any of
   it (`vX` is the release you came from):

   ```bash
   git diff vX v4.0.0-alpha.N -- .env.example
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

   If your deployment caches the configuration or the routes, build those caches again here.

9. **Open the site.**

   ```bash
   php artisan up
   ```

## If a step fails

Leave the site down. Check out the release you came from, run step 5 for it, restore the database
and the storage directory's `app/` from the backup, do step 8, and bring the site up. Then report
what failed.
