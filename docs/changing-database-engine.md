# Changing the database engine

OpenPNE 4 runs on MySQL, MariaDB or SQLite. A fresh install starts on SQLite because it needs no
server to set up; [the OpenPNE 3 upgrade](upgrading-from-openpne3.md) requires MySQL.
`openpne:copy-database` moves an existing site's data between SQLite and MySQL, in either direction,
and not yet to or from MariaDB. Every foreign-key column
is indexed on both engines, so a query that read an index on one engine reads one on the other; the
two mention tables carry a wider index on SQLite ([why](internals/ordering.md#sqlite-foreign-key-indexes)),
and what the engines sort and match differently is described below.

Converting a site that already serves people is not a step anyone has to take, and it is not part of
any upgrade. It changes what those people see — searching and sorting behave differently on each
engine — so start from that, and leave a site that works where it is.

## What changes about the site

**Searching and sorting.** This is the engine's behaviour, not the copy's. On MySQL a search folds
case, kana and width together but keeps the voiced mark and accents: searching members for `タナカ`
there also finds `たなか` and `ﾀﾅｶ`, while a search for `ハンタ` does not find `ハンダ`, nor `jose`
find `José`. SQLite's `LIKE` folds the case of ASCII letters and nothing else, so a search for
`タナカ` finds only `タナカ`. Ordering by name follows: MySQL sorts names through its Japanese
collation, hiragana and katakana together and kanji in that collation's order, while SQLite ignores
the case of ASCII letters and otherwise sorts by code point, so the kana sort apart. Run the searches
your members actually run against the copy before you let anyone else near it
([how each engine compares](internals/text-comparison.md)).

**Email addresses and usernames.** A member's email address and an administrator's username compare
without case on both engines. MySQL also treats as one what differs in the case of any letter, not
only ASCII, in width, in hiragana and katakana, in small and full-size kana, in compatibility forms
(`①` and `1`) or in the kind of space inside the value, where SQLite folds only the case of ASCII
letters: a copy from SQLite to MySQL stops on two addresses or usernames that differ only that way. Every other value compares
exactly on both engines: two groups whose names differ only in case are two groups.

**What one file costs and buys.** Sessions, the cache and the queue are all database-backed by
default, so on SQLite they share the site's single file with its content. Under SQLite's default
journal a write and concurrent reads coexist right up to the commit, which needs a lock no reader can
hold at the same time — so on a site with real concurrency, writes and reads start failing each other
off. `DB_JOURNAL_MODE=WAL` is the answer, and set it before traffic arrives: switching a database's
journal mode needs that same exclusive lock, so doing it while another connection holds a transaction
fails with `database is locked`.

The WAL journal mode is persistent — it is recorded in the database file, so it holds even if the
variable is later unset. Keep it set all the same: the backup copy below is not in WAL, and a
database restored from one, or carried into a new release by an update from an archive, gets WAL
back only from the variable. A typo does not announce itself the same way: depending on its shape it
either leaves the previous mode in place or fails every connection outright. Confirm it took.

```console
$ php artisan tinker --execute="echo DB::selectOne('pragma journal_mode')->journal_mode;"
wal
```

Backing the site up is then one command, not one file copy. Under WAL a committed row can still be
in the `-wal` file beside the database, so copying the database on its own quietly produces a backup
missing the newest writes. This takes the whole of it, in the app root:

```bash
php artisan openpne:backup-sqlite /path/to/copy.sqlite
```

It copies inside one read transaction, which under WAL holds no writer up, so it finishes however
much the site goes on writing, and it waits up to a minute for a lock the site holds for a moment.
The copy is in the default journal mode; with
`DB_JOURNAL_MODE=WAL` set, the site's first connection to a database restored from it puts it back
in WAL.

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
on both sides afterwards. Each table's id counter comes along, so the target never hands out the id
of a row the source deleted ([Row ids](internals/runtime.md#row-ids)); a copy into MySQL that fails
leaves the counters raised on empty tables, and running it again is fine. It copies between SQLite
and MySQL only. Nothing is written to the source. Then point the site at what it produced:

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
before any row is written:

- **The two sides are at different schema versions.** It names the migrations only one side has. Both
  databases have to describe the same schema for rows to mean the same thing in each.
- **The target already holds rows.** Copy into an empty database, so the result is the source and
  nothing else.
- **A table or column exists on only one side.** Only a hand-altered site reaches this. Either
  direction is refused: what the target has no home for would be dropped, losing exactly the data
  nobody else has a copy of, and what only the target defines would end up in the result holding
  something the source never had. Reconcile the two on a copy of the source, or extend the schema on
  both sides.
- **A side is neither SQLite nor MySQL**: another driver, or MariaDB, which this command does not
  copy to or from yet. It is refused before anything is written to the target.
- **A table's id counter does not fit a signed 64-bit integer.** This is checked after the target's
  schema is created and before any row is written, so running it again needs no cleanup.

Extra *tables on the source* are the one exception — after an OpenPNE 3 upgrade the OpenPNE 3 tables
are still there, and they are no part of the OpenPNE 4 schema. The command names each one it leaves
behind rather than copying it or passing over it in silence.
