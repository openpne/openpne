# Upgrading from OpenPNE 3

Migrates one OpenPNE 3 site's data into a fresh OpenPNE 4 install: members and their profiles,
diaries, communities, messages, files, and the site's own settings.

You will do this twice. First as a **rehearsal** against a dump, while OpenPNE 3 keeps serving — that
is where you find out what your source needs fixed, and it costs nothing to repeat. Then as the
**cutover** (step 7), against a source no one is writing to any more. The rehearsal is not the
migration: anything members write to OpenPNE 3 after you take its dump is not in that dump.

Read [Requirements](#requirements) before dumping anything — two of them decide whether the upgrade
can run at all.

## Requirements

- **MySQL**, for both the source and the target. The upgrade is a set-based `INSERT...SELECT` over
  the OpenPNE 3 DDL; it does not run on SQLite.
- **OpenPNE 3 core 3.6.x or newer.** An older source is missing tables and columns the upgrade
  reads. The preflight names each one rather than failing partway through.
- **Database file storage** (OpenPNE 3's default), where every uploaded file has its bytes in a
  `file_bin` row. A site converted to filesystem storage is not supported: the preflight rejects a
  `file` / `file_bin` count mismatch instead of migrating file metadata without the bytes.
- The OpenPNE 3 tables must be readable from OpenPNE 4's own database connection — restored into the
  same database, or into another database on the same MySQL instance.
- For the prefixed and separate-database layouts below, the database user additionally needs to drop
  a foreign key on the source's `file_bin` and rename that table. Check this during the rehearsal; a
  privilege error is a bad thing to meet mid-cutover.

## Starting state

The upgrade writes into an OpenPNE 4 install that is configured but **not yet migrated**:

- a fresh, empty MySQL database, with OpenPNE 4's `DB_*` pointing at it
- dependencies installed and `APP_KEY` generated
- no migrations run yet, and nothing running them behind your back

That last one is easy to trip over. The Docker path in [the README](../README.md#getting-started)
migrates on container start (`docker/entrypoint.sh`), so do not `bin/dev-up` the install you intend
to upgrade into before restoring the dump — step 1 explains why the order matters.

The source is always a **restored dump you can throw away**, never the database a live OpenPNE 3 site
is serving from. Every layout mutates it: at minimum the upgrade re-points `file_bin`'s foreign key
onto the new `files` table, and two of them move the table outright.

## What migrates

`php artisan openpne:upgrade-matrix` renders what each step carries, column by column, including the
filters that split one OpenPNE 3 table across several OpenPNE 4 ones. That is the mapping for a
canonical OpenPNE 3 schema.

While you upgrade, the tool reports the drift from that schema it can actually see: a required table
or column missing, and — for the tables whose recognised set can be enumerated — names it does not
recognise. It cannot notice every local customisation, such as a column some plugin added to a table
it copies.

## 1. Restore the OpenPNE 3 dump

Restore the dump **before** running `php artisan migrate`.

Uploaded files are the reason. A site's `file_bin` is commonly gigabytes, and the upgrade never
copies those bytes — it re-points their foreign key onto the new `files` table, which is a metadata
change no matter how large the table is. That only works if the restored `file_bin` is already
there when OpenPNE 4's schema is created: its migration skips the table when it exists. Migrate
first and you get an empty `file_bin` that the restore then collides with.

```console
$ mysql -u openpne -p openpne4 < openpne3-dump.sql
```

Where you restore it decides two flags:

| Source layout | Flags | |
|---|---|---|
| Same database as OpenPNE 4 | *(none)* | The default. OpenPNE 3 and OpenPNE 4 table names do not overlap. |
| Same database, prefixed tables | `--source-prefix=op3_` | |
| Another database, same instance | `--source-database=openpne3` | For a customised source whose table names would clash. |

The last two additionally **consume the source's `file_bin`**: it is renamed onto OpenPNE 4's table
rather than copied, so the bytes leave the source entirely.

## 2. Create the OpenPNE 4 schema

```console
$ php artisan migrate --force
```

## 3. Dry-run, and read the preflight

```console
$ php artisan openpne:upgrade-from-3 --dry-run
```

Writes nothing. It prints the SQL each step would run, and — the part worth reading — what the
preflight found in your source.

**`ERROR` aborts the run**, before anything is written. These are states the upgrade cannot survive:
a required table or column missing (an older or customised OpenPNE 3), a plugin whose tables are
only partly present, files whose bytes are missing, or two mail translations that would land on the
same template and locale. Fix the source and dry-run again.

**`WARN` migrates anyway** — the row is carried, and you decide whether what it reports matters:

| Warning | What it means |
|---|---|
| A `name` the upgrade does not recognise, with a row count | A third-party plugin or a local customisation put it there. OpenPNE 4 has no home for it, so those rows are not migrated. |
| A mail template that does not render | An admin customised it into something OpenPNE 4's template renderer rejects, or it links through an OpenPNE 3 route that no longer exists. |
| A mail translation in a locale OpenPNE 4 never reads | It migrates but is never sent: OpenPNE 4 resolves a recipient to `ja` or `en`. |

The mail-template warnings say which kind each one is, because it changes what happens after the
cutover. A parse error, a sandbox violation, or a route that cannot be mapped means the mail
**throws instead of sending** until someone fixes the template — worth resolving before you switch
over, either in the OpenPNE 3 source or in OpenPNE 4's own mail template editor once the data is in.
A missing variable only renders as empty text.

Each template is reported by its first fault, because that is where rendering stopped. Fixing it can
reveal the next one, so re-run the dry run after editing a template rather than assuming one pass
cleared it.

## 4. Run the upgrade

```console
$ php artisan openpne:upgrade-from-3
```

Each step commits with its own checkpoint, so an interrupted run resumes from the first step that
did not finish; re-running is safe. The checkpoint records *that* a step ran, not the definition it
ran under — so if you update OpenPNE 4 itself partway through a migration, the earlier checkpoints
no longer describe the current steps. Start over with `--force-restart`, which clears the
upgrade-owned target tables along with the checkpoints.

That makes `--force-restart` a rehearsal tool. Once OpenPNE 4 is taking traffic, those tables are
the live site.

## 5. Verify before switching over

```console
$ php artisan openpne:verify-upgrade
```

Read-only, and takes the same `--source-prefix` / `--source-database` you upgraded with. It does not
trust what the runner reported: it re-counts the live source and the target independently, and fails
the command if any check fails. It checks that each step's source rows, its recorded row count, and
the rows it owns in the target all agree; that every file has its bytes at the right length with the
foreign key rewired; and that no OpenPNE 3 password hash is left at rest.

**Row counts agree trivially when both sides are empty.** A step that migrated nothing — because a
source table was not what you assumed, or its filter matched no rows — reports the same pass as one
that migrated correctly. List those steps and account for each:

```console
$ php artisan openpne:verify-upgrade --json | jq -r '.checks[] | select(.detail == "0 rows") | .name'
```

Some are expected: a step carries an OpenPNE 3 plugin your site never installed, or a setting whose
absence already means what you want. The rest are the ones to look into. That judgement is yours —
the command cannot know which features your site had.

## 6. After the upgrade

- **Passwords** — OpenPNE 3 hashes are wrapped rather than reset, so everyone signs in with their
  existing password, and each hash is upgraded on its owner's first successful login.
- **Surface** — the site is on Classic by default, matching what its members knew. Switch when
  ready: `php artisan openpne:surface-mode modern_only`.
- **Emoji** — carrier emoji codes are rewritten to Unicode. Sixteen carrier logos have no Unicode
  equivalent and stay as literal text.
- **Site policy** — the imported policy pages are reformatted as Markdown, which is how OpenPNE 4
  renders them.
- **Mail templates** — fix whatever the preflight flagged, in the admin mail template editor.

## 7. Cutover

Rehearse until the dry run holds no surprises and verification passes. Then migrate the data people
will actually keep using:

1. Stop writes to OpenPNE 3 — maintenance mode, or however that site takes traffic. From here on,
   nothing new is being written that the dump could miss.
2. Take the final dump.
3. Restore it as in step 1, into a **fresh** OpenPNE 4 database — not the one you rehearsed into.
   That one holds the rehearsal's rows and checkpoints; re-running over them is a different
   operation than a clean run, and not the one you tested. Point `DB_*` at the new database. With
   `--source-database`, the source needs a fresh database of its own as well: the rehearsal renamed
   the last one's `file_bin` away.
4. Repeat steps 2 through 5 against it, with the same flags you rehearsed with.
5. Point traffic at OpenPNE 4.

Keep the rehearsal database until you are satisfied with the new site. It costs nothing and answers
"was it already like that before?" without touching production.

## Rolling back

Before the cutover there is nothing to undo: OpenPNE 3 never stopped serving, and OpenPNE 4 lives in
its own database. Drop it and start again.

After the cutover, rolling back means going back to a site that stopped receiving writes when you
froze it — so the decision point is step 7's traffic switch, not the migration. Anything written to
OpenPNE 4 after that is only in OpenPNE 4.
