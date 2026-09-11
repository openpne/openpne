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

Filament appends the table's primary key to any sort it renders, in the direction of the sort the
reader or `defaultSort` chose and ascending when neither did, so an admin table's
`defaultSort('id', 'desc')` is a structural order; a query-level time order states its own key so
the two directions agree.

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
permission. A web surface reads a malformed cursor as no cursor; the MCP realm refuses one it did not
hand out ([mcp.md](mcp.md)).

A time column from `timestamps()` is nullable, and a row with no time has no place in the order:
the prev / next queries answer "no neighbours" for it rather than compare against NULL, and no
cursor reaches it. The lists here rely on every write path filling the column, which the upgrade
tool does as well.

## One index per axis

An index follows a paging axis, not a WHERE clause: `(scope…, time column, id)`, one per axis a
table is paged along; the feature documents list which axes are indexed today. Visibility ranges,
block anti-joins and LIKE filters are applied while the axis index is scanned and never earn an index
of their own. That is a trade: a feed page's row query falls from a sort over every visible row to a
few index reads (its OFFSET count still scans the table), while a keyword search whose hits are sparse
or absent walks the whole axis index looking for them and runs slower than the table scan it replaced
(measured on MySQL 8.4 at 200k diaries: the feed 134 ms → 1.5 ms, a six-hit search 0.23 s → 0.35 s).
The feed is every member's page; the sparse search is the price. The `id` suffix is implied on both
engines — InnoDB stores the primary key at the end of every secondary index and SQLite stores the
rowid — but writing it keeps the axis legible in the schema. A site-wide index leads with the time
column so that InnoDB does not adopt it to back a foreign key; a scoped `(parent_id, time column)`
index is adopted by design and is replaced by creating the new one before dropping the old (errno
1553 on a drop that leaves the key unbacked).

| Table | Site-wide axis | Scoped axis |
|---|---|---|
| `diaries` | `(created_at, id)` — recent feed (search pays for it, above) | `(member_id, created_at)` — archive, recent five, prev / next |
| `timeline_posts` | `(created_at, id)` — home, all-member and tag feeds | `(member_id, created_at)` — a member's timeline |
| `members` | `(created_at, id)` — the member list without a name filter, newcomers | — |
| `groups` | `(created_at, id)` — group search; a member's own groups are read through the membership index and sorted by the engine | — |
| `group_messages` | — | `(group_id, created_at, id)` — talk keyset, latest message, read cursor |
| `notifications` | — | `(notifiable_type, notifiable_id, created_at)` — the feed and the center window |
| comment tables | — | `(parent id, number)` — the thread pagers |

Lists bounded to one viewer's or one group's rows are left to the engine's sort: the mailbox boxes,
and the friend, block, friend-request and group-applicant pages, whose pivots carry no time-axis
index. The talk room list sorts on a correlated latest-message subquery that reads the
`group_messages` index above; the conversation list's subquery reads the mailbox rows; in both the
outer sort over the computed column is the engine's.

## SQLite foreign-key indexes

Laravel's `constrained()` creates an index on MySQL, where InnoDB requires one for the constraint,
and none on SQLite, where a join on the column or a cascade from its parent scans the table. One
migration adds the missing index to every foreign-key column no index already leads, by
introspection rather than a driver gate, so MySQL gains no duplicate; an architecture test reads the
live schema and fails on any foreign key that no index leads. It runs on both lanes but only SQLite
can fail it, since InnoDB indexes every foreign key itself. A new table declares `->index()` on its
foreign-key columns itself, or the test names the omission.

SQLite plans without statistics, so a single-column index is not always harmless: on
`group_message_mentions` a `member_id`-only index wins the correlated `EXISTS` of the room list over
the `(group_message_id, offset)` unique key and scans every mention of the viewer. On SQLite that
table, and `timeline_post_mentions` with it for the same shape, carry `member_id` followed by the post column instead;
on MySQL the foreign key's own single-column index stays, since InnoDB's statistics keep the plan on
the unique key.

## Guards

A same-second fixture alone stays green wherever the engine's natural order already matches the key
(an index scan, a pivot's primary key, MySQL's handling of a tie), and which engine that is differs
per query. Such a list also pins its `order by` clause through the query log
([`PinsOrderBy`](../../tests/Support/PinsOrderBy.php)); the pin is the assertion that bites on both
engines.
