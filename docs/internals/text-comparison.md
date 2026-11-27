# Text comparison

How the app compares the text a member types with the text it stores. How the engines differ for a
site's members is in [Changing the database engine](../changing-database-engine.md).

## Stored text

On MySQL every OpenPNE 4 table and its text columns are `utf8mb4_0900_bin` (the `mysql` connection's
default, which needs MySQL 8.0.17), apart from the identifiers below and `push_subscriptions.endpoint`,
which is `ascii_bin`; SQLite compares `BINARY`. An `=`, an `IN`, a join, a
`GROUP BY` and a unique index compare code points on each engine, and `utf8mb4_0900_bin` is NO PAD,
so a trailing space is part of the value. Two group names, two profile item names or two values of a
unique profile item that differ in case, kana or width are two values. MariaDB uses other names for
the same comparisons ([MariaDB](#mariadb)).

## Identifiers

Six columns hold what a person signs in with or is reached at, and compare without case through
[`IdentifierCollation`](../../app/Support/Database/IdentifierCollation.php): `members.email`,
`registration_tokens.email`, `password_reset_tokens.email`, `email_change_requests.new_email`,
`admin_role_invites.email` and `admin_users.username`. MySQL uses `utf8mb4_0900_as_ci`, which also treats as one hiragana and
katakana, small and full-size kana, full- and half-width forms, compatibility forms (`①` is `1`) and
the kinds of space inside a value, tells accents and voiced marks apart (`é` is not `e`, `が` is not
`か`) and is NO PAD; MariaDB uses `utf8mb4_uca1400_nopad_as_ci`, which does the same but follows UCA
14.0.0 where MySQL follows 9.0.0, so some letters, most of them added to Unicode after 9.0, compare
otherwise; SQLite uses `NOCASE`, which folds ASCII letters only. A migration that adds such
a column takes its collation from `IdentifierCollation::for()`.

Everything that writes or looks up these columns trims with `Str::trim`, which also removes Unicode
spaces such as U+3000: `TrimStrings` on a request, Filament's `trim()` on the administrator login
and form (a Livewire request skips `TrimStrings`), and the console commands, the invitation page and
the actions that write these columns on their own. A stored value with whitespace at either end
therefore matches nothing anyone can type. The OpenPNE 3 upgrade and `openpne:copy-database` carry
values as they are; the upgrade warns about such a login.

A new email address, one a person registers, is invited under or changes to, must pass
[`NewEmailAddress`](../../app/Rules/NewEmailAddress.php): the HTML Living Standard's "valid e-mail
address" (printable ASCII, no quoted local part, no address literal) that also passes Laravel's
`email:filter`, which turns away consecutive dots and a domain without one, both of which the former
allows. It is stored lowercased, and between such values each collation's equality is
byte equality, so two addresses that reach different mailboxes are never taken for one; a domain
with other characters is entered in its punycode form (`xn--`). The validators, the actions that
write `registration_tokens` and `email_change_requests`, and a saving hook on `Member` check it, and
a pending row that fails it reads as expired. Sign-in and the password reset do not, so the server
still finds an address already stored, such as an upgraded member's, though a browser's email field
refuses a non-ASCII local part and may send a non-ASCII domain as punycode. On MySQL such an address
still compares equal to the ASCII address it folds into (`ｕｓｅｒ@example.com` is
`user@example.com`), which then no one can register; the upgrade warns about it
(`W-MEMBER-EMAIL-NON-ASCII`). A password reset token is keyed by the address, so an email change
deletes it before the address changes; one issued meanwhile for the member as read before the change
still goes to the new address, since the queued notification reads the member again when it is sent.

## Search and name order

A search a member types and a list ordered by a name go through
[`SearchCollation`](../../app/Support/Database/SearchCollation.php), not through the column's own
collation:

| Engine | Search (`whereContains`) | Name order (`orderBy`) |
|---|---|---|
| MySQL | `LOWER(column) COLLATE utf8mb4_ja_0900_as_cs LIKE LOWER(?)` | `column COLLATE utf8mb4_ja_0900_as_cs` |
| MariaDB | `LOWER(column COLLATE utf8mb4_uca1400_as_cs) LIKE LOWER(? COLLATE utf8mb4_uca1400_as_cs)` | `column COLLATE utf8mb4_uca1400_as_cs` |
| SQLite | `column LIKE ?` | `column COLLATE NOCASE` |
| other | `LOWER(column) LIKE LOWER(?)` | the column's own |

The collation is the connection's `comparison_collation`. On MySQL a search treats uppercase and
lowercase, hiragana and katakana, and full- and half-width letters, digits and kana as one, and tells
apart the voiced and semi-voiced marks, small kana, accents (`José` is not `jose`), emoji and
compatibility forms (`①` is not `1`). Some half-width forms do not match their full-width ones, for
example a voiced or semi-voiced kana (`ｶﾞ` and `ﾊﾟ`, two characters each, are not `ガ` and `パ`),
the prolonged sound mark (`ｺｰﾋｰ` is not `コーヒー`) and the middle dot (`ｱ･ｲ` is not `ア・イ`).
On MariaDB a search lowers both sides through that collation's case table, which reaches past ASCII
(`ẞ` finds `ß`) but maps letters to lowercase rather than folding case (`ς` does not find `Σ`, nor `ſ`
find `S`), and tells apart hiragana and katakana, full- and half-width forms, the voiced marks, small kana,
accents and emoji; a name order puts kanji not by reading but in code point order, the CJK Unified
Ideographs block ahead of its extensions (`㐂`, U+3402, after `一`, U+4E00).
SQLite's `LIKE` folds the case of ASCII letters and nothing else, and `NOCASE` orders the same way.

A search finds the term as typed. `whereContains` escapes `%`, `_` and the escape character `!`
before wrapping the term in `%…%`, and appends `ESCAPE '!'` to each `LIKE` above; naming the escape
also stops MySQL and MariaDB from reading a backslash in the term as their default one.

A name order is a list a reader scans by name: the mention and recipient pickers, the muted talk
rooms, group categories after `sort_order`, and the admin tables' sorts by a member's, a group's or an
administrator's name. Ordering through an expression is a filesort, even on an indexed column.

### Search limits

The diary, member and group searches, and the keyword filter of a member's Modern diary archive,
refuse a field longer than 100 characters (code points, spaces included) and a diary keyword of more
than 10 terms ([`SearchInput`](../../app/Support/SearchInput.php)).
Each diary term is two `LIKE`s with two bound values each, so the term cap keeps a statement far below
the 999 bound values that SQLite before 3.32 accepts. A refused search goes back to the same screen
without its query and shows the reason in the `error` flash; it is not logged, and a JSON request gets
a 422. The term is never shortened, so the results always answer what was typed. The three screens are
also rate-limited (`throttle:search`, [security.md](security.md#rate-limits-on-member-requests)).

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
name order that skips the helper is not caught; it falls back to the column's own order. Inside the
helper, the method that takes a ready-made pattern is private, so `whereContains` is the only way
in and every caller's term is escaped.

## MariaDB

MariaDB 10.11 has none of MySQL's `0900` collations, so the `mariadb` connection names its own:
`utf8mb4_nopad_bin` for stored text, which is NO PAD and in code point order like
`utf8mb4_0900_bin`, and `utf8mb4_uca1400_nopad_as_ci` for the identifiers ([Identifiers](#identifiers)). Its JSON is `LONGTEXT` held to a column
`CHECK (json_valid(...))`, and the three JSON columns (`passkeys.credential`,
`home_issue_items.stats`, `openpne4_upgrade_state.metadata`) keep the `utf8mb4_bin` MariaDB gives
them, since changing a column's collation redefines it without the check. A search and a name order
go through `utf8mb4_uca1400_as_cs` ([Search and name order](#search-and-name-order)): no 10.11
collation treats kana and width as one without also taking small kana for full-size ones.

## Converting an existing site

A site made before the switch has `utf8mb4_unicode_ci` tables.
`2026_11_19_000001_convert_text_columns_to_binary_collation` converts them. It runs on a fresh install
too, where it changes the identifier columns and the columns that name a collation of their own, and
on SQLite rebuilds the five identifier tables.

- **Which tables.** The OpenPNE 4 tables it names, not the database: an upgraded site keeps
  OpenPNE 3's tables beside them, and the upgrade reads those as OpenPNE 3 compared them. `file_bin`
  is left out, since on such a site it is OpenPNE 3's own table. Each named table's default changes
  too, so a column a later migration adds inherits the binary collation.
- **When.** On MySQL only while the connection's collation is `utf8mb4_0900_bin`, and on MariaDB
  only while it is `utf8mb4_nopad_bin`. A site that sets
  `DB_COLLATION` to anything else keeps its tables, and unsetting it later converts nothing, since the
  migration has already run. Such a site is outside what later migrations assume: one that adds an
  identifier column names the identifier collation through `IdentifierCollation::for()` whatever the
  tables hold.
- **What stops it.** Before changing anything it refuses when a unique identifier column holds two
  values `IdentifierCollation` treats as one, or a value with whitespace at either end. The message
  names the column and the row ids, never the values. `email_change_requests.new_email` is not
  checked: two members may ask for the same address. It refuses a server of the other engine behind
  the connection, such as a MariaDB that has the `0900` collations behind the `mysql` connection, and
  on MariaDB a text column with a check other than a JSON column's `json_valid()`, even a column
  already in the wanted collation.
- **How.** MySQL and MariaDB get one `ALTER TABLE` per table that copies each column's type,
  nullability and default, built for every table before the first runs, and MariaDB's JSON columns
  are left out. Neither rolls DDL back, so a failure
  midway leaves some tables converted, and running it again converts the rest. SQLite rebuilds the
  five identifier tables in one transaction, with foreign keys off before it opens, and puts back
  their triggers and their AUTOINCREMENT high-water marks, so a deleted id is not handed out again.
- **Back.** `down()` returns the identifier columns to the table's collation. The tables stay
  binary: `utf8mb4_unicode_ci` would merge names that are now distinct.

A test lists every unique index and primary key over a text column with the kind of value it holds,
so a new one fails until someone decides whether it is an identifier.
