# Text comparison

How the app compares the text a member types with the text it stores. What a site's members see
differ between the engines is in [Changing the database engine](../changing-database-engine.md).

## Search and name order

A search a member types and a list ordered by a name go through
[`SearchCollation`](../../app/Support/Database/SearchCollation.php), not through the column's own
collation:

| Engine | Search (`whereMatches`) | Name order (`orderBy`) |
|---|---|---|
| MySQL | `LOWER(column) COLLATE utf8mb4_ja_0900_as_cs LIKE LOWER(?)` | `column COLLATE utf8mb4_ja_0900_as_cs` |
| SQLite | `column LIKE ?` | `column COLLATE NOCASE` |
| other | `LOWER(column) LIKE LOWER(?)` | the column's own |

The collation is the connection's `search_collation`. On MySQL a search treats uppercase and
lowercase, hiragana and katakana, and full- and half-width forms as one, and tells apart the voiced
and semi-voiced marks, small kana and emoji. A half-width voiced kana (`ｶﾞ`) is two characters, so it
does not match the full-width one (`ガ`). SQLite's `LIKE` folds the case of ASCII letters and nothing
else, and `NOCASE` orders the same way.

The caller builds the pattern, so whether `%` and `_` in a term are wildcards is the screen's choice:
the mention and recipient pickers escape them, the member, diary and group searches do not.

A name order is a list a reader scans by name: the mention and recipient pickers, the muted talk
rooms, group categories after `sort_order`, and the admin tables' sorts by a member's, a group's or an
administrator's name. Ordering through an expression is a filesort, even on an indexed column.

### Filament

The admin panel builds its own search SQL. Filament appends a configured `search_collation` whether
or not it lowers the column, and the column unlowered is case-sensitive under
`utf8mb4_ja_0900_as_cs`. So on a connection with one, the panel makes every searching component —
table columns, selects, select filters, attach and associate actions, the query builder's
relationship operator — lower both sides, and each globally searchable resource does the same through
`FoldsGlobalSearchCase`. On SQLite nothing is forced: Filament would lower the term with PHP's
multibyte `Str::lower` and the column with SQLite's ASCII-only `lower()`, and `ÄRZTE` would stop
finding itself. A table-wide `searchable([...])` and `MorphToSelect` take no default and are not used.

### Guards

An architecture test reads every string literal in `app/` and fails on the word `like` outside the
helper, and on Laravel's `whereLike` family. Each allowed literal is listed with its reason — a
constant prefix, an OpenPNE 3 table the upgrade reads, prose — and a listing no literal matches any
more fails, so the list cannot outlive its code. The same test fails on a table-wide
`searchable([...])` and on `MorphToSelect`. A name order that skips the helper is not caught; it falls
back to the column's own order.
