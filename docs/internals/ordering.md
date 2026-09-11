# Ordering and paging

Every list a member scrolls is ordered by one **axis** and cut into pages one way. This page is the
rule; the feature documents record how each list applies it.

## Axes

| Axis | Meaning | Columns | Lists |
|---|---|---|---|
| Posting time | when the row was written | `(created_at, id)` | diary lists, timeline feeds, group talk, direct-message conversations, notifications, new members, group search and a member's groups |
| Last activity | when the thread last moved | a dedicated activity column plus `id`, or a correlated `(latest_at, latest_id)` | group boards, the talk room list, the conversation list, the diary comment-history box |
| Structural | the row's place inside its parent | `(parent, number)`, `id`, `sort_order`, `role` | comment threads, images, participants, settings tables, the admin tables' key sort |
| Other | relevance or an aggregate | the score, then `id` | home-issue candidates, groups by member count |

A list on the posting-time axis never orders by `id` alone: migrated rows carry OpenPNE 3 ids that
are not monotonic in time, and an id stands in for time only until the first backdated row.

## The tuple

A time column is second-precise, so it is not a total order on its own: two rows written in one
second sit on a page edge in whichever order the engine's scan happens to return them, and an
OFFSET page then repeats one row and loses another
([group-talk.md](group-talk.md#ordering-is-the-created_at-id-tuple) records the measurement). The
order is therefore always the time column **and a unique key**, in the same direction.

The key only has to be unique; it need not be monotonic (a notification's id is a random UUID). On a
pivot table it is the other half of the composite primary key. Across a UNION it is a pair no two
arms share — the mailbox's trash orders by `(sort_at, role, row_id)`, where `role` names the arm.

Filament appends the table's primary key to any sort it renders, so an admin table's
`defaultSort('id', 'desc')` is a structural order and needs no time column.

## Prev / next derive from the list

A "previous" or "next" link belongs to a list, so it is the next row **in that list's order**, found
by a keyset comparison on the same tuple: [`AdjacentDiaries`](../../app/Features/Diary/Queries/AdjacentDiaries.php)
walks the author's archive on `(created_at, id)`;
[`ShowDirectMessage`](../../app/Features/DirectMessage/Queries/ShowDirectMessage.php) walks the
mailbox on the box's own time column and row id. A link that walked `id` while the list walked
`created_at` would skip or repeat rows wherever the two disagree — which, on migrated data, they do.

## Keyset and offset

A **stream** — newest first, growing while it is read, consumed by scrolling — pages by keyset: the
client names the row it has and asks for what lies beyond it, so a row posted meanwhile shifts nothing.
An **archive** — a list a reader jumps into by page number — pages by OFFSET. Group talk and
direct-message conversations are streams today; which of the remaining lists are streams is decided
list by list in the feature documents.

The keyset comparison is written out as `t < ? OR (t = ? AND id < ?)`: SQLite has no row-value
comparison. A cursor is `{iso8601}|{id}`, opaque to the client, and names a position rather than a
permission — a malformed cursor reads as no cursor.

## One index per axis

An index follows a paging axis, not a WHERE clause: `(scope…, time column, id)`, one per axis a
table is paged along. Visibility ranges, block anti-joins and LIKE filters are applied while the
axis index is scanned and never earn an index of their own. The `id` suffix is implied on both
engines — InnoDB stores the primary key at the end of every secondary index and SQLite stores the
rowid — but writing it keeps the axis legible in the schema. A time column leads so that InnoDB does
not adopt the index to back a foreign key (errno 1553 on a later drop).

## SQLite foreign-key indexes

Laravel's `constrained()` creates an index on MySQL, where InnoDB requires one for the constraint,
and none on SQLite. A foreign-key column a query filters or joins on is therefore indexed explicitly
in the migration, or it is unindexed on the SQLite lane.

## Guards

Each time-ordered query pins its `order by` clause in a test through the query log
([`PinsOrderBy`](../../tests/Support/PinsOrderBy.php)): a same-second fixture alone rarely bites,
because an index scan already returns a tie in key order on both engines, so the SQL text is the
assertion with teeth.
