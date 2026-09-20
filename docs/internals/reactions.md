# Reactions

An emoji a member puts on a piece of content, in the one `reactions` table
([`app/Features/Reactions`](../../app/Features/Reactions)). Group talk was the first surface, the
timeline the second, the diary the third and the group boards the fourth; each surface
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
| group boards ([group-boards.md](group-boards.md#reactions)) | topic or event row → comment (a topic or event is its own container) | nothing: the page does not poll |

A board's teardown is the one place the container is two rows deep: the group row, then every topic
and event under it, since a board writer takes the topic or event row and never the group's.

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

Who reacted is exactly that part, so the names come from `GET .../reactions` when a dialog is opened
or a chip's tip has stayed open a moment, and nowhere else. That read is bounded too
([`Reactors`](../../app/Features/Reactions/Queries/Reactors.php)): an emoji's count is exact and the
first hundred reactors travel with it, in the order they reacted. Past that the dialog has the number
and no more — the list is read by a person.

## The row

Every surface draws a reacted-to thing one of two ways
([`components/reactions/reaction-bar.tsx`](../../resources/js/components/reactions/reaction-bar.tsx)).

- **A list row** — a feed post, a reply, a diary or board comment, a talk message — stands at rest
  with no control on it: the chips are drawn only once someone has reacted, so a row nobody has
  reacted to costs the list no height. A feed or comment row then keeps its add button at the end of
  the chips and out of the bar; a talk row offers it in its bar and sheet instead. What can be done about the row
  is reached by pointer: where a cursor can point, a bar floats over the row's top-right
  ([`components/row/reveal-bar.tsx`](../../resources/js/components/row/reveal-bar.tsx)) on hover or
  when a keyboard reaches into the row; where the primary pointer is a finger, a long press raises
  the row's sheet ([`components/row/row-sheet.tsx`](../../resources/js/components/row/row-sheet.tsx)).
- **A detail item** — the diary, topic, event or post a page is about — keeps its add button with or
  without chips, since nothing else on the page offers it. What a viewer may do to it — edit and
  delete where the surface says the viewer may (a board's admin as well as the author), delete alone
  on a post — sits behind a kebab at the header's right end
  ([`components/row/row-menu.tsx`](../../resources/js/components/row/row-menu.tsx)): a menu for a
  cursor, a sheet for a finger, nothing at all for a reader who may do neither.

The press is offered only where `(pointer: coarse)` holds
([`lib/use-long-press.ts`](../../resources/js/lib/use-long-press.ts)), the same query the row's
`select-none` and `-webkit-touch-callout: none` key on: a laptop with a touch screen keeps the OS
selection lens and the cursor's bar. A held row cannot be part-selected, so the sheet offers
**Select text**: the row drawn again under that heading, its body selected as it appears, in a sheet
of its own where the lens works. That sheet does not slide in: iOS paints a selection made during
the slide where the content stood at that instant, and one made after it comes late. The body is
capped at 40vh so the copy item under it stays on the screen; a unit test cannot see that height, so
the UX drive measures it. Every choice on the sheet
closes it before what it opens arrives — the reactor list, the confirmation, the selectable body — and
focus returns to the row that was pressed.

A member is told once how a row is reached: one line above a list with rows the member may act on
([`components/row/first-use-hint.tsx`](../../resources/js/components/row/first-use-hint.tsx)),
worded for the pointer in hand, gone for every page and device once closed, once a row's sheet has been
opened or once a row has been reacted to (`PreferenceKey::RowActionsHint`,
[member-preferences.md](member-preferences.md)).

A chip is its own toggle. Held, it opens the reactor list led by its own emoji; reached by a keyboard
or hovered, it names its reactors in a tip. The tip is read once it has stayed open a moment, since
focus and a passing pointer open it too, and read again only once the chip's count or the viewer's
own mark has changed, so a toggle of one's own is never answered with the room as it was. The tip is
in the document from the moment it opens, so a screen reader's description of the chip says the
names are loading, but nothing is drawn until they arrive; a read that fails (the site's read cap
included), or finds the chip's reaction gone or its members withdrawn, leaves the tip undrawn and
the description empty, and only names are kept, so the next open reads again. The names are offered
only to a reader the reactor route admits — a guest on a web-public diary is not. A press
starting on a chip belongs to the chip: the row's own hook lets a press that began inside a
`data-press-own` element pass, since a pointerdown bubbles and every hook on the way up would
otherwise arm its own timer.

The bar is one class string reaching the controls by two lanes. Where a cursor can point it is
revealed by `:hover` and by `:focus-visible` — never `:focus-within`, since a click leaves focus on
what was clicked and would keep the bar out over a row nobody is on. Where there is no cursor the
controls are `sr-only` rather than hidden: a screen reader on a touch screen cannot hold a press, and
these buttons are that reader's only way to what the sheet offers. `pointer-events` is what keeps an
invisible control from answering a finger on a hybrid machine, and the revealing states beat
`pointer-fine:pointer-events-none` by selector specificity (0,2,0 against 0,1,0), not by source
order: a bare `pointer-events-auto` would tie it and leave the controls dead to every click. Nothing
in the coarse lane writes `pointer-events` at all, so a touch screen reader's activation path is
untouched. The trailing `pointer-coarse:focus-within:absolute` re-floats the bar when a hardware keyboard tabs into
the coarse lane, where `not-sr-only`'s `position: static` would otherwise drop it into the flow. The
row lifts above its siblings (`z-10`) for as long as the bar is out, since the bar overhangs the row's
edges and the next row would otherwise take its hits.

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
   row first) and reads the content under it — plain reads where the hold came first, as a delete's
   do, locking reads where the transaction's snapshot came first, as a withdrawal's do. That is the
   one order. The member cascade (which locks the rows it deletes, as any delete does, and sweeps
   nothing) and the OpenPNE 3 transfer are the writes outside it — and the transfer may carry a row
   by a member who no longer holds the surface's write permission, which they can then see but not
   remove.
5. Nothing about a chip row grows with the content's audience: the counts are aggregated in SQL
   rather than hydrated, and the reactor list ships an exact count with at most a hundred names.
6. A reaction notifies nobody and moves no unread state.
