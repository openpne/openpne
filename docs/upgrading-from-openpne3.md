# Upgrading from OpenPNE 3

Migrates one OpenPNE 3 site's data into a fresh OpenPNE 4 install: members and their profiles,
diaries, communities, messages, files, and the site's own settings.

You will do this twice. First as a **rehearsal** against a dump, while OpenPNE 3 keeps serving — that
is where you find out what your source needs fixed, and it costs nothing to repeat. Then as the
**cutover** (stage 6), against a source no one is writing to any more. The rehearsal is not the
migration: anything members write to OpenPNE 3 after you take its dump is not in that dump.

Two words this document uses in a specific way:

- **the source** — the restored copy of your OpenPNE 3 database that the upgrade reads. Never the
  database your live OpenPNE 3 site is serving from.
- **a step** — the upgrade copies the data table by table, and each of those units is a step. The
  commands name them in their output (`FileUpgrade`, `MemberUpgrade`, …). The numbered *stages*
  below are this document's own; they are not the same thing.

Read [Requirements](#requirements) before dumping anything — some of them decide whether the upgrade
can run at all, and one decides how to take the dump.

## Requirements

- **MySQL**, for both the source and the target. The upgrade is a set-based `INSERT...SELECT` over
  the OpenPNE 3 DDL; it does not run on SQLite.
- **OpenPNE 3 core 3.6.x or newer.** An older source is missing tables and columns the upgrade
  reads. It refuses to start and names each one rather than failing partway through.
- **Database file storage** (OpenPNE 3's default), where every uploaded file has its bytes in a
  `file_bin` row. A site converted to filesystem storage is not supported: the upgrade rejects a
  `file` / `file_bin` count mismatch instead of migrating file metadata without the bytes.
- **A schema-and-data dump** — `mysqldump` of the OpenPNE 3 database as it writes one by default,
  `DROP TABLE` statements included. A data-only dump (`--no-create-info`) carries nothing to create
  the source tables from, and the target has no OpenPNE 3 tables of its own to receive the rows.
- The OpenPNE 3 tables must be readable from OpenPNE 4's own database connection — restored into the
  same database, or into another database on the same MySQL instance.
- For the prefixed and separate-database layouts in stage 2, the database user additionally needs to
  drop a foreign key on the source's `file_bin` and rename that table. Check this during the
  rehearsal; a privilege error is a bad thing to meet mid-cutover.

## 1. Install OpenPNE 4 on a fresh database

Install it the ordinary way — [with Docker](../README.md#getting-started) or
[without](../README.md#without-docker) — with one thing set before you start it: `.env` must point at
a **fresh, empty MySQL database**, not the SQLite default. These are the settings the rest of this
document refers to as `DB_*`:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=openpne4
DB_USERNAME=openpne
DB_PASSWORD=your-password
```

Create the file first (`cp .env.example .env`) and edit it, then install. Both paths create the
schema as part of that — `php artisan migrate` on the host, or the first container start with the
Docker setup, which also installs dependencies and generates `APP_KEY`.

Compose ships no database of its own, so with Docker `DB_HOST` has to name a MySQL server the
*container* can reach: `127.0.0.1` there means the container itself, not your machine.

**Where to run the commands.** Every `php artisan …` below runs from the application root, on the
machine holding that `.env`. With the Docker setup, prefix them:
`docker compose exec app php artisan …`.

## 2. Restore the OpenPNE 3 dump

```console
$ mysql -u openpne -p openpne4 < openpne3-dump.sql
```

`file_bin` is the one table OpenPNE 3 and OpenPNE 4 both have, and restoring over OpenPNE 4's empty
one is exactly what should happen. That table holds the uploaded files' bytes — commonly gigabytes —
and the upgrade never copies them: it re-points them at the new `files` table, which is a bookkeeping
change no matter how large the table is. What arrives with the dump stays where it lands.

That works because `mysqldump` drops each table before recreating it. Restoring into the same
database from a dump taken with `--skip-add-drop-table` will collide on `file_bin` instead: drop
OpenPNE 4's empty one first, or restore before creating the schema in stage 1 — OpenPNE 4 leaves a
`file_bin` alone when it is already there. The layouts below sidestep this by keeping the source's
tables under their own names.

Where you restore it decides an option you will pass to the commands in stages 3 to 5:

| Source layout | Option | |
|---|---|---|
| Same database as OpenPNE 4 | *(none needed)* | The default. Nothing else collides — `file_bin` is the only shared name. |
| Same database, prefixed tables | `--source-prefix=op3_` | Use the prefix your dump actually has. |
| Another database, same MySQL server | `--source-database=openpne3` | For a customised source whose table names would clash. |

Every command in stages 3 to 5 takes it the same way, and they all need it — each one has to find the
source the same way the others did:

```console
$ php artisan openpne:upgrade-from-3 --dry-run --source-database=openpne3
```

The two non-default layouts also **consume the source's `file_bin`**: that table is moved onto
OpenPNE 4's rather than copied, so the bytes leave the source entirely.

## 3. Dry-run, and read the report

```console
$ php artisan openpne:upgrade-from-3 --dry-run
```

Writes nothing. It prints the SQL each step would run, and — the part worth reading — what its
**preflight** found: a read-only inspection of your source that runs before anything is written.

**`ERROR` aborts the run**, before anything is written. These are states the upgrade cannot survive:
a required table or column missing (an older or customised OpenPNE 3), a plugin whose tables are
only partly present, files whose bytes are missing, or two mail translations that would land on the
same template and locale. Fix the source and dry-run again.

**`WARN` migrates anyway** — the row is carried, and you decide whether what it reports matters:

| Warning | What it means |
|---|---|
| A `name` the upgrade does not recognise, with a row count | A third-party plugin or a local customisation put it there. OpenPNE 4 has no home for it, so those rows are not migrated. |
| A mail template that does not render | An admin customised it into something OpenPNE 4 cannot render, or it links through an OpenPNE 3 address that no longer exists. |
| A mail translation in a locale OpenPNE 4 never reads | It migrates but is never sent: OpenPNE 4 sends each recipient `ja` or `en`. |

The mail-template warnings say which kind each one is, because it changes what happens after the
cutover. A template OpenPNE 4 cannot parse, one using something it does not allow, or one linking to
an address that cannot be mapped means the mail **fails instead of sending** until someone fixes it.
A missing variable is milder: the mail sends with that piece of text empty.

**Where you fix them decides whether the fix survives.** Editing the template in **OpenPNE 3**, in
its own notification mail editor, puts the fix in the final dump — so it arrives already working at
the cutover. Editing it in the **OpenPNE 4** admin (*Settings → Mail template settings*) only changes
the database in front of you: during a rehearsal that database is thrown away, because the cutover
restores a fresh dump into a fresh one. Use the OpenPNE 4 editor to confirm a template renders, then
either port the fix back to OpenPNE 3 or plan to redo it right after the cutover — that mail does not
send in between.

Each template is reported by its first fault, because that is where rendering stopped. Fixing it can
reveal the next one, so re-run the dry run after editing a template rather than assuming one pass
cleared it.

## 4. Run the upgrade

```console
$ php artisan openpne:upgrade-from-3
```

Each step is committed as it finishes, so an interrupted run picks up from the first step that did
not complete; re-running is safe. What it records is *that* a step ran, not the definition it ran
under — so if you update OpenPNE 4 itself partway through a migration, those records no longer
describe the current steps. Start over with `--force-restart`, which empties the tables the upgrade
owns along with its records of what ran.

That makes `--force-restart` a rehearsal tool. Once OpenPNE 4 is taking traffic, those tables are
the live site.

## 5. Verify before switching over

```console
$ php artisan openpne:verify-upgrade
```

Read-only, and takes the same option you upgraded with. It does not trust what the upgrade reported:
it re-counts the source and the target independently, and fails if any check fails. It checks that
each step's source rows, the number it recorded, and the rows it owns in the target all agree; that
every file has its bytes at the right length and pointing at the right place; and that no OpenPNE 3
password hash is left behind.

**Row counts agree trivially when both sides are empty.** A step that migrated nothing — because a
source table was not what you assumed, or because it matched no rows — reports the same pass as one
that migrated correctly. List those steps and account for each:

```console
$ php artisan openpne:verify-upgrade | grep ': 0 rows'
```

(`--json` emits the same report as JSON if you would rather consume it from a script.)

Some are expected: a step carries an OpenPNE 3 plugin your site never installed, or a setting whose
absence already means what you want. The rest are the ones to look into. That judgement is yours —
the command cannot know which features your site had.

## What the upgrade changed

Nothing to perform here — this is what the migrated site looks like, so you can tell an intended
change from a problem when you go through it.

- **Passwords** — OpenPNE 3 passwords keep working, and each one is quietly re-secured on its owner's
  first sign-in. Nobody has to reset anything.
- **Surface** — the site looks like OpenPNE 3 (the "Classic" surface), matching what its members
  knew. Moving to the new one is a separate decision you can take whenever you like, before or long
  after the cutover: `php artisan openpne:surface-mode modern_only`.
- **Emoji** — old carrier emoji codes become real emoji. Sixteen carrier logos have no modern
  equivalent and stay as literal text like `[i:108]`.
- **Site policy** — the imported terms and privacy pages are reformatted as Markdown, which is how
  OpenPNE 4 renders them. Worth reading once to see how they came out.
- **Mail templates** — anything the dry run flagged is still broken here; stage 3 covers where to fix
  it so the fix is still there after the cutover.

## 6. Cutover

Rehearse until the dry run holds no surprises and verification passes. Then migrate the data people
will actually keep using:

1. Stop writes to OpenPNE 3 — maintenance mode, or however that site takes traffic. From here on,
   nothing new is being written that the dump could miss.
2. Take the final dump.
3. Create another fresh, empty MySQL database, point `DB_*` at it, and create the schema there
   (`php artisan migrate --force`). Do not reuse the rehearsal's database: it still holds the
   rehearsal's rows and the upgrade's record of what already ran.
4. Restore the final dump in the same layout you rehearsed with (stage 2). With `--source-database`,
   that means a fresh database for the source too — the rehearsal moved the last one's `file_bin`
   away.
5. Repeat stages 3 through 5 against it, with the same option you rehearsed with.
6. Point traffic at OpenPNE 4.

Nothing you changed inside OpenPNE 4 during the rehearsal is here — this site is built from a
different dump into a different database. Mail templates are the usual case; stage 3 explains how to
avoid having to redo them.

Keep the rehearsal databases until you are satisfied with the new site. They cost nothing and answer
"was it already like that before?" without touching production.

## Rolling back

Before the cutover there is nothing to undo: OpenPNE 3 never stopped serving, and OpenPNE 4 lives in
its own database. Drop it and start again.

After the cutover, rolling back means going back to a site frozen at the moment you stopped its
writes — so the decision point is stage 6's traffic switch, not the migration. Anything written to
OpenPNE 4 after that is only in OpenPNE 4.
