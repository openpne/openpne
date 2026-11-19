# Text comparison

How the app compares the text a member types with the text it stores. How the engines differ for a
site's members is in [Changing the database engine](../changing-database-engine.md).

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
example a voiced or semi-voiced kana (`ｶﾞ` and `ﾊﾟ`, two characters each, are not `ガ` and `パ`), the
prolonged sound mark (`ｺｰﾋｰ` is not `コーヒー`) and the middle dot (`･`). SQLite's `LIKE` folds the case of ASCII letters and nothing
else, and `NOCASE` orders the same way.

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
