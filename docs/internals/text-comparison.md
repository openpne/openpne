# Text comparison

How the app compares the text a member types with the text it stores. How the engines differ for a
site's members is in [Changing the database engine](../changing-database-engine.md).

## Stored text

On MySQL every OpenPNE 4 table and its text columns are `utf8mb4_0900_bin` (the `mysql` connection's
default, which needs MySQL 8.0.17), and SQLite compares `BINARY`. An `=`, an `IN`, a join, a
`GROUP BY` and a unique index compare code points on both engines, and `utf8mb4_0900_bin` is NO PAD,
so a trailing space is part of the value. Two group names, two profile item names or two values of a
unique profile item that differ in case, kana or width are two values. The `mariadb` connection keeps
`utf8mb4_unicode_ci`.

## Identifiers

Five columns hold what a person signs in with or is reached at, and compare without case through
[`IdentifierCollation`](../../app/Support/Database/IdentifierCollation.php): `members.email`,
`registration_tokens.email`, `password_reset_tokens.email`, `email_change_requests.new_email` and
`admin_users.username`. MySQL uses `utf8mb4_0900_as_ci`, which also folds width but tells accents
apart (`é` is not `e`) and is NO PAD; SQLite uses `NOCASE`, which folds ASCII letters only. A
migration that adds such a column takes its collation from `IdentifierCollation::for()`.

Every form trims what is typed into these columns. The administrator login and form trim through
Filament's `trim()`, since a Livewire request skips `TrimStrings`. A stored value with whitespace at
either end therefore matches nothing anyone can type.

## Search and name order

A search a member types and a list ordered by a name go through
[`SearchCollation`](../../app/Support/Database/SearchCollation.php), not through the column's own
collation:

| Engine | Search (`whereMatches`) | Name order (`orderBy`) |
|---|---|---|
| MySQL | `LOWER(column) COLLATE utf8mb4_ja_0900_as_cs LIKE LOWER(?)` | `column COLLATE utf8mb4_ja_0900_as_cs` |
| SQLite | `column LIKE ?` | `column COLLATE NOCASE` |
| other | `LOWER(column) LIKE LOWER(?)` | the column's own |

The collation is the connection's `comparison_collation`. On MySQL a search treats uppercase and
lowercase, hiragana and katakana, and full- and half-width letters, digits and kana as one, and tells
apart the voiced and semi-voiced marks, small kana, accents (`José` is not `jose`), emoji and
compatibility forms (`①` is not `1`). Some half-width forms do not match their full-width ones, for
example a voiced or semi-voiced kana (`ｶﾞ` and `ﾊﾟ`, two characters each, are not `ガ` and `パ`),
the prolonged sound mark (`ｺｰﾋｰ` is not `コーヒー`) and the middle dot (`ｱ･ｲ` is not `ア・イ`).
SQLite's `LIKE` folds the case of ASCII letters and nothing else, and `NOCASE` orders the same way.

The caller builds the pattern, so whether `%` and `_` in a term are wildcards is the screen's choice:
the mention and recipient pickers escape them, the admin panel goes through `whereContains`, which
does, and the member, diary and group searches do not.

A name order is a list a reader scans by name: the mention and recipient pickers, the muted talk
rooms, group categories after `sort_order`, and the admin tables' sorts by a member's, a group's or an
administrator's name. Ordering through an expression is a filesort, even on an indexed column.

### Filament

Filament's own search SQL compares through the column's collation, and told to fold case it lowers
the column in SQL and the term in PHP, which disagree for some letters (`İstanbul`, `ΟΣ`) so that a
name would not find itself. The panel does not use it: each searchable table column passes
`searchable(query: CollatedSearch::on(...))`, and each globally searchable resource uses
`CollatedGlobalSearch`. Filament still splits the term into words, requires every word, and lets each
word match any searchable column; only the comparison of one word with one column goes through
`whereContains`. The key is not named `search_collation`, which Filament would
append to SQL of its own.

### Guards

An architecture test reads every string literal in `app/` and fails on the word `like` outside the
helper, and on Laravel's `whereLike` family. Each allowed literal is listed with its reason — a
constant prefix, an OpenPNE 3 table the upgrade reads — and must name exactly one literal, so a new
LIKE in the same file is not let through; English prose and codes that say "like" are listed apart.
The same test fails on a `searchable()` without `query:` and on the components that search with SQL
of Filament's own and take no query (`MorphToSelect`, `SelectColumn`, the attach and associate
actions, the query builder's text and relationship constraints), unless listed with a reason. A
name order that skips the helper is not caught; it falls back to the column's own order.

## Converting an existing site

A site made before the switch has `utf8mb4_unicode_ci` tables.
`2026_11_19_000001_convert_text_columns_to_binary_collation` converts them, and runs on a fresh
install too, where only the columns that name a collation of their own change.

- **Which tables.** The OpenPNE 4 tables it names, not the database: an upgraded site keeps
  OpenPNE 3's tables beside them, and the upgrade reads those as OpenPNE 3 compared them. `file_bin`
  is left out, since on such a site it is OpenPNE 3's own table. Each named table's default changes
  too, so a column a later migration adds inherits `utf8mb4_0900_bin`.
- **When.** On MySQL only while the connection's collation is `utf8mb4_0900_bin`. A site that sets
  `DB_COLLATION` to anything else keeps its tables, and unsetting it later converts nothing, since the
  migration has already run. MariaDB is never converted.
- **What stops it.** Before changing anything it refuses when a unique identifier column holds two
  values `IdentifierCollation` treats as one, or a value with whitespace at either end. The message
  names the column and the row ids, never the values. `email_change_requests.new_email` is not
  checked: two members may ask for the same address.
- **How.** MySQL gets one `ALTER TABLE` per table that copies each column's type, nullability and
  default, built for every table before the first runs. MySQL does not roll DDL back, so a failure
  midway leaves some tables converted, and running it again converts the rest. SQLite rebuilds the
  five identifier tables in one transaction, with foreign keys off before it opens, and puts their
  triggers back.
- **Back.** `down()` returns the identifier columns to the table's collation. The tables stay
  binary: `utf8mb4_unicode_ci` would merge names that are now distinct.

A test lists every unique index and primary key over a text column with the kind of value it holds,
so a new one fails until someone decides whether it is an identifier.
