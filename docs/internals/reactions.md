# Reactions

An emoji a member puts on a piece of content, in the one `reactions` table
([`app/Features/Reactions`](../../app/Features/Reactions)). Group talk was the first surface, the
timeline the second, the diary the third and the group boards' comments the fourth; each surface
owns its screens, its routes and its authorization, and shares everything below.

## One table, no foreign key to the content

`reactable_type` / `reactable_id` are a morph pair, written through the model's alias and never as
a literal, so a rename stays a `morphMap` edit. `reactable_id` carries **no** foreign key — a
polymorphic column cannot — which is why nothing cascades a reaction away with the content it is on,
and every surface has to sweep by hand (below).

**One member may hold several emoji on one piece of content**: the unique key is `(reactable_type,
reactable_id, member_id, emoji)`. Narrowing it to one emoji per member later would have to throw rows
away, which is why the wide key is a decision rather than the shape that fell out. On MySQL `emoji`
is `utf8mb4_bin`, since the default collation equates a code point with its VS16-qualified form
(`U+2764` = `U+2764 U+FE0F`) and SQLite's binary TEXT does not — the two engines would otherwise
disagree about what counts as one reaction.

## The vocabulary

[`ReactionVocabulary`](../../app/Features/Reactions/ReactionVocabulary.php) is the only place the set
is written down, its size included: the add rule reads it, every page ships it to its picker as
`reactionVocabulary`, and the tests pin its bytes. Nothing in the bundle holds a copy, so one render
draws its picker from one set. A prop is fixed at render time, though — a tab left open across a
deploy goes on offering what it was rendered with, and what closes that skew is the server: the add
rule refuses a retired emoji with a 422 and the client's optimistic chip reverts. What may be
**added** is that set; what may be **removed** is whatever the member is holding, unchecked —
otherwise narrowing the vocabulary would strand every reaction already written with a retired emoji.

## One write path, one lock per surface

Add and remove are two URLs rather than one toggle: a tap that is retried, doubled, or racing a poll
has to settle where the member pointed. Both are idempotent at the row level — the insert leans on
the unique key, the delete on a `WHERE` — and both answer with the content's **whole** chip row, so a
reaction someone else added in the meantime arrives in the same response.

A gate is answered before the write it guards runs, and nothing at the engine level would refuse a
reaction onto content deleted in between — what it left would be a row nothing ever collects. So
[`AddReaction`](../../app/Features/Reactions/Actions/AddReaction.php) and
[`RemoveReaction`](../../app/Features/Reactions/Actions/RemoveReaction.php) run inside a transaction
and take the surface's locks first, through its
[`ReactionSurface`](../../app/Features/Reactions/ReactionSurface.php): `hold()` locks the surface's
container row exclusively and re-reads the content under it, both as locking reads so they see what
is committed rather than the transaction's snapshot, and answers false when the content is gone
(`ReactionRefused`, which every surface turns into its usual 404). `touched()` runs only after a row
actually changed, for a surface that keeps a watermark.

Before the surface's locks, the write takes the **reactor's own member row, shared**. The insert's
foreign-key check would take that lock anyway, only last — and a withdrawal holds the member row
exclusively while it sweeps the member's content under the surface's locks, so a reactor reacting to
their own content mid-withdrawal would otherwise close a cycle. The one order is therefore
**member → container → content**:

| surface | container → content | `touched()` |
|---|---|---|
| group talk ([group-talk.md](group-talk.md#one-lock-order)) | group row → message | bumps the group's reaction version |
| timeline ([timeline.md](timeline.md#reactions)) | thread root → reply (a root is its own container) | nothing: feeds do not poll |
| diary ([diary.md](diary.md#reactions)) | diary row → comment (a diary is its own container) | nothing: the page does not poll |
| group boards ([group-boards.md](group-boards.md#reactions)) | topic or event row → comment | nothing: the page does not poll |

A surface's own delete and teardown take the same order before they sweep, which is what keeps the
paths from deadlocking as well as from racing. The single order is a property of the code; the
MySQL-only lock-order tests hold a row from a second connection and pin which locking read the write
waits on.

## Reading

The chip row is counted in SQL and never hydrated
([`ReactionAggregates`](../../app/Features/Reactions/Queries/ReactionAggregates.php)): one grouped
read serves a whole page, and a write answers from the same query. The chips are a handful of
numbers, but the rows behind them are one per reactor per emoji. Groups are ordered by their earliest
row, so the chips read in the order the emoji first appeared. Chips are **passed** into a serializer,
never read off the model, so a page cannot cost a query per row by accident. The viewer may be
absent — a guest on a web-public diary — and then no chip is `mine`.

Who reacted is exactly that part, so the names come from `GET .../reactions` when a dialog is opened,
and nowhere else. That read is bounded too
([`Reactors`](../../app/Features/Reactions/Queries/Reactors.php)): an emoji's count is exact and the
first hundred reactors travel with it, in the order they reacted. Past that the dialog has the number
and no more — the list is read by a person.

## Reclaiming the rows

Three paths take reactions away, and only the last is a cascade:

- the content's own delete, sweeping under the surface's lock in the same transaction;
- the container's teardown (a group's purge, a member's withdrawal for the posts, replies, diaries
  and those diaries' comments that go with the member row), likewise under the lock;
- the reacting member's withdrawal — `member_id` is a real foreign key.

## Key invariants

1. `reactable_type` is written through the model's morph alias, never as a literal — the OpenPNE 3
   `nice` transfer included, which reads the alias off the model it lands on.
2. At most one row per (content, member, emoji), so a member may hold several emoji on one piece of
   content. Narrowing that to one is lossy, which is why the wide key is a decision rather than a
   default.
3. `ReactionVocabulary` is the only place the set is written down, its size included. It bounds what
   may be added; what may be removed is whatever the member holds, and the column takes any short
   utf8mb4 string.
4. Add and remove take the reactor's member row shared, then the surface's container row, then
   re-read the content under it; a sweep takes the container row (a withdrawal, its author's member
   row first) and reads the thread under it with locking reads. That is the one order. The member
   cascade (which locks the rows it deletes, as any delete does, and sweeps nothing) and the
   OpenPNE 3 transfer are the writes outside it — and the transfer may carry a row by a member who
   no longer holds the surface's write permission, which they can then see but not remove.
5. Nothing about a chip row grows with the content's audience: the counts are aggregated in SQL
   rather than hydrated, and the reactor list ships an exact count with at most a hundred names.
6. A reaction notifies nobody and moves no unread state.
