# Changing the database engine

OpenPNE 4 runs on either MySQL or SQLite. A fresh install starts on SQLite because it needs no server
to set up; [the OpenPNE 3 upgrade](upgrading-from-openpne3.md) requires MySQL. `openpne:copy-database`
moves an existing site's data from one to the other, in either direction.

Converting a site that already serves people is not a step anyone has to take, and it is not part of
any upgrade. It changes what those people see — searching and sorting behave differently on each
engine — so start from that, and leave a site that works where it is.

## What changes about the site

**Searching and sorting.** This is the engine's behaviour, not the copy's. MySQL compares text
through `utf8mb4_unicode_ci`, which folds a great deal together before matching: searching members
for `タナカ` there also finds `たなか` and `ﾀﾅｶ`, and `ハンダ` too, since that collation ignores the
voiced mark. SQLite's `LIKE` folds the case of ASCII letters and nothing else, so the same search
finds `タナカ` alone. Ordering follows: SQLite sorts by code point, so uppercase precedes lowercase
and the kana sort apart. Run the searches your members actually run against the copy before you let
anyone else near it.

**What one file costs and buys.** Sessions, the cache and the queue are all database-backed by
default, so on SQLite they share the site's single file with its content. SQLite's default journal
makes a writer block readers for the length of its write, which a site with any concurrency will
feel — set `journal_mode` on the `sqlite` connection in `config/database.php` to `WAL`, and set it
before traffic arrives: changing a database's journal mode takes an exclusive lock, so doing it while
connections are open fails with `database is locked`.

Backing the site up is then one command, not one file copy. Under WAL a committed row can still be
in the `-wal` file beside the database, so copying the database on its own quietly produces a backup
missing the newest writes. `sqlite3 database.sqlite ".backup out.sqlite"` and `VACUUM INTO` each take
the whole of it.

## Copying the database

Do not attempt this with a dump. mysqldump writes MySQL's own backslash string escapes, which SQLite
reads as literal characters, and `--hex-blob` writes `0x…` blob literals, which SQLite parses as
*integers* without complaining — every uploaded file's bytes would silently become a number.
`openpne:copy-database` instead builds the target schema with `migrate`, so no DDL is translated, and
moves rows through PDO, which leaves escaping and BLOB binding to the driver.

Run it with nothing writing to the site: the copy is one snapshot per table, not a single instant
across all of them.

```console
$ touch /path/to/database.sqlite
$ php artisan openpne:copy-database --to=sqlite --to-database=/path/to/database.sqlite
```

`--to` names a connection from `config/database.php` and `--to-database` overrides which database
that connection opens, which is what makes the pair meaningful: both `sqlite` and `mysql` read the
same `DB_DATABASE` otherwise, and one of them would open the other's. `--from` and `--from-database`
are the same for the source, which defaults to the connection the site itself uses.

The command creates the schema, copies every table, and reports failure unless the row counts match
on both sides afterwards. Nothing is written to the source. Then point the site at what it produced:

```dotenv
DB_CONNECTION=sqlite
DB_DATABASE=/path/to/database.sqlite
```

Swapping the options runs it the other way (`--from=sqlite --from-database=… --to=mysql`), which is
how a site that outgrows SQLite goes back.

## When it refuses

Three of these mean the target is not what the command can safely write into, and it stops before
writing anything:

- **The two sides are at different schema versions.** It names the migrations only one side has. Both
  databases have to describe the same schema for rows to mean the same thing in each.
- **The target already holds rows.** Copy into an empty database, so the result is the source and
  nothing else.
- **The source has a column the schema does not define.** Only a hand-altered site reaches this.
  Dropping the column quietly would lose exactly the data nobody else has a copy of, so remove it on
  a copy of the source first, or add it to the schema on both sides.

Extra *tables* on the source are not an error — after an OpenPNE 3 upgrade the OpenPNE 3 tables are
still there, and they are no part of the OpenPNE 4 schema. The command names each one it leaves
behind rather than copying or silently ignoring it.
