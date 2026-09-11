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

The keyset comparison is written out, SQLite having no row-value comparison, and a stream writes it
as `t <= ? AND (t < ? OR id < ?)`: SQLite cannot see that the two bound times are equal, so the
familiar `t < ? OR (t = ? AND id < ?)` becomes an index scan whose cost grows with the depth of the
page (16 ms for a page 190k rows into the list), while the leading range keeps every page at a few
index reads on both engines. A cursor is `{iso8601}|{id}`, opaque to the client, and names a
position rather than a permission; the id is the row's primary key, an integer or a UUID, the time
is the ATOM form the server emitted, normalized to the site timezone on parse, and a URL carries the
whole cursor percent-encoded as a query value. A web surface reads a malformed cursor as no cursor;
the MCP realm refuses one it did not hand out ([mcp.md](mcp.md)).

Feeds share one implementation, `App\Support\Stream`: `StreamQuery::older` applies the predicate and
the `(time, id)` order to an Eloquent builder or a has-many relation, replacing an order the query
carried, leaving out a row whose time is NULL, and reading one row past the page to learn whether
older rows exist; `StreamPage` holds the rows newest first and names its last row as the older
cursor; `StreamProps::scroll` hands Inertia's `InfiniteScroll` the cursor in the scroll metadata
under `before`, with no previous page, so the payload is rows only; `StreamRequest` reads `?before=`
and answers a bookmarked `?page=` other than 1 from the OFFSET days with a redirect to the same URL
without `page`. The query handed in carries the list's own scope, visibility and ownership included;
a top-level `orWhere` in it is refused, since the predicate would land in one of its arms. One
stream per page: the client writes `before` back into the URL, so two on one page would read each
other's cursor. Group talk and direct-message conversations keep their own cursors: they page in
both directions and around an anchor, which a feed never does.

Two client contracts follow from Inertia's data manager keeping the next cursor in its own state,
which it drops only for a prop the request named in `reset`, a header only the client can send. The
restore revalidation in `resources/js/lib/revalidate-on-restore.ts`, the one full reload the app
issues, names every scroll prop of the current page there, or the next "Older" would skip the rows
the reload replaced. A Classic tab from before a list became a stream still holds a `?page=2`
load-more URL; its rows route answers 400 rather than serve the head twice, and the no-JS pager takes
over.

A time column from `timestamps()` is nullable, and a row with no time has no place in the order:
the prev / next queries answer "no neighbours" for it rather than compare against NULL, a stream
leaves it out, and no cursor reaches it. The lists here rely on every write path filling the column,
which the upgrade tool does as well.

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
1553 on a drop that leaves the key unbacked). `timeline_posts` is the exception on the site-wide
side: its axis leads with the reply flag, a foreign-key column, and is treated as adopted.

| Table | Site-wide axis | Scoped axis |
|---|---|---|
| `diaries` | `(created_at, id)` — recent feed (search pays for it, above) | `(member_id, created_at)` — archive, recent five, prev / next |
| `timeline_posts` | `(in_reply_to_id, created_at, id)` — the home, friend, all-member and tag feeds and the story candidates, all of them top-level posts; it backs the self-referencing foreign key | `(member_id, in_reply_to_id, created_at)` — a member's timeline and post count, also top-level only; it backs the member key |
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
table, and `timeline_post_mentions` with it for the same shape, carry `member_id` followed by the post
column instead; on MySQL the foreign key's own single-column index stays, since InnoDB's statistics
keep the plan on the unique key. `IS NULL` counts as an equality for the same purpose: a
`timeline_posts.in_reply_to_id` index alone made SQLite pick it for every feed's top-level filter and
sort the whole table per page (100k rows: 0.02 ms → 20 ms, with or without `ANALYZE`), so the feed
axis there is `(in_reply_to_id, created_at, id)` and the member axis `(member_id, in_reply_to_id,
created_at)`, each an equality on every filter the readers apply, so neither engine has an index
that looks cheaper; on MySQL the composite replaces InnoDB's own index for the key.

## Guards

A same-second fixture alone stays green wherever the engine's natural order already matches the key
(an index scan, a pivot's primary key, MySQL's handling of a tie), and which engine that is differs
per query. Such a list also pins its `order by` clause through the query log
([`PinsOrderBy`](../../tests/Support/PinsOrderBy.php)); the pin is the assertion that bites on both
engines.

An architecture test reads every literal time-column order in `app/`, raw orders naming a time
column included, and requires a tiebreak before the call that executes or caps the query: a primary
key, bare or table-qualified, or, for the few queries whose unique tail is composite, that tail
named per file (a pivot's other key column, the mailbox union's `(role, row_id)`, the conversation
list's counterpart after a shared latest message). An order on a column unique in its own query is
listed per line with its reason. The guard sees the width of the code base and no deeper than one
chain: an order built from a variable, or spread across statements, is invisible to it, and every
stream and paged list also pins its SQL as above.
