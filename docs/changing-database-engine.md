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
for `タナカ` there also finds `たなか` and `ﾀﾅｶ`, and a search for `ハンタ` finds `ハンダ`, since that
collation ignores the voiced mark as well. SQLite's `LIKE` folds the case of ASCII letters and
nothing else, so each of those searches finds only what was typed. Ordering follows: SQLite sorts by
code point, so uppercase precedes lowercase and the kana sort apart. Run the searches your members
actually run against the copy before you let anyone else near it.

**What one file costs and buys.** Sessions, the cache and the queue are all database-backed by
default, so on SQLite they share the site's single file with its content. Under SQLite's default
journal a write and concurrent reads coexist right up to the commit, which needs a lock no reader can
hold at the same time — so on a site with real concurrency, writes and reads start failing each other
off. `DB_JOURNAL_MODE=WAL` is the answer, and set it before traffic arrives: switching a database's
journal mode needs that same exclusive lock, so doing it while another connection holds a transaction
fails with `database is locked`.

The WAL journal mode is persistent — it is recorded in the database file, so it holds even if the
variable is later unset. A typo does not announce itself the same way: depending on its shape it
either leaves the previous mode in place or fails every connection outright. Confirm it took.

```console
$ php artisan tinker --execute="echo DB::selectOne('pragma journal_mode')->journal_mode;"
wal
```

Backing the site up is then one command, not one file copy. Under WAL a committed row can still be
in the `-wal` file beside the database, so copying the database on its own quietly produces a backup
missing the newest writes. `sqlite3 database.sqlite ".backup out.sqlite"` takes the whole of it and
keeps WAL mode; `VACUUM INTO` also takes the whole of it but produces a file in the default journal
mode, so a database restored from one needs setting again.

**How much fsync to pay for that.** `DB_SYNCHRONOUS` is the other half of the WAL decision, and takes
`OFF`, `NORMAL`, `FULL` or `EXTRA`. Unset leaves SQLite's own `FULL`, which flushes to disk on every
commit. `NORMAL` is the usual companion to WAL: it stops paying for that on each commit, at the price
of a power cut being able to take the most recent ones with it. This one is a property of the
connection rather than of the database, so unlike a journal mode it has to be set wherever the site
runs, and there is nothing in the file to inherit it from.

Anything the four names do not cover — a typo, a number outside `0`–`3`, a blank — reads as unset and
leaves you on `FULL`. That is deliberate: passed through verbatim, SQLite would resolve an
unrecognised word to `NORMAL` and an out-of-range number to `OFF`, so a typo would buy a quieter disk
by making the database less durable than the default it replaced. It still means a typo gets you the
default rather than what you asked for, so confirm this one too:

```console
$ php artisan tinker --execute="echo DB::selectOne('pragma synchronous')->synchronous;"
1
```

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

Swapping the options runs it the other way, which is how a site that outgrows SQLite goes back. Both
overrides are needed there, for the same reason: on a site serving from SQLite, `DB_DATABASE` holds
the SQLite path, and without `--to-database` the MySQL connection would take that path for a database
name. Create the empty MySQL database first — the command creates the schema in it, not the database
itself.

```console
$ php artisan openpne:copy-database \
    --from=sqlite --from-database=/path/to/database.sqlite \
    --to=mysql --to-database=openpne4
```

## When it refuses

Each of these means the target is not something the command can safely write into, and it stops
before writing anything:

- **The two sides are at different schema versions.** It names the migrations only one side has. Both
  databases have to describe the same schema for rows to mean the same thing in each.
- **The target already holds rows.** Copy into an empty database, so the result is the source and
  nothing else.
- **A table or column exists on only one side.** Only a hand-altered site reaches this. Either
  direction is refused: what the target has no home for would be dropped, losing exactly the data
  nobody else has a copy of, and what only the target defines would end up in the result holding
  something the source never had. Reconcile the two on a copy of the source, or extend the schema on
  both sides.

Extra *tables on the source* are the one exception — after an OpenPNE 3 upgrade the OpenPNE 3 tables
are still there, and they are no part of the OpenPNE 4 schema. The command names each one it leaves
behind rather than copying it or passing over it in silence.
