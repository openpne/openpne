# Timeline

The short-post feed ported from OpenPNE 3's `opTimelinePlugin`: a 140-code-point plain body, at most
one image, and a **flat** thread — a reply is a row in the same table with `in_reply_to_id` set, a
reply always attaches to the thread root (a permalink to a reply re-centers there), and it inherits
its parent's `visibility` so a thread is gated as one audience. The feeds
([`app/Features/Timeline/Queries`](../../app/Features/Timeline/Queries)) list top-level rows only.

## A mention is a range, not text

A member chosen in the compose form's mention picker becomes a `timeline_post_mentions` row: the
half-open range `[offset, offset + length)` over the body, plus the member's id. The body itself is
stored exactly as it was typed — the handle in it is text, and nothing in it identifies anyone.

Offsets are counted in **Unicode code points**, the unit PHP's `mb_substr` and JavaScript's
`Array.from` agree on. UTF-16 code units would be the natural JavaScript count and the wrong one:
a range would sit one position further along for each astral emoji before it. The two renderers are
pinned against each other case by case
([`EntityTextTest`](../../tests/Unit/Support/EntityTextTest.php) /
[`entity-split.test.ts`](../../resources/js/lib/entity-split.test.ts)).

Linking by id rather than by the name in the body is what makes a rename harmless: the display text
stays as it was written, and the link still reaches the person who was meant.

### Composing one

Modern's picker is [`MentionTextarea`](../../resources/js/components/compose/mention-textarea.tsx)
over the pure draft in [`mention-draft.ts`](../../resources/js/lib/mention-draft.ts). The draft
positions a mention by UTF-16 offset — the unit the DOM reports a caret in — and converts to code
points once, at submit, over the value being sent. Edits carry a mention along, and an edit that
reaches into a handle drops it: only text the member picked stays a mention, which is the same
judgment the server makes again on arrival.

### Two layers of failure

The payload is only ever produced by the picker, so the two kinds of wrong input are answered
differently ([`MentionRules`](../../app/Http/Requests/Concerns/MentionRules.php) /
[`ResolveMentions`](../../app/Features/Timeline/Actions/ResolveMentions.php)):

| what happened | answer |
|---|---|
| structurally impossible payload — missing key, negative offset, more rows than the cap | **422**, the whole post is rejected |
| a row that stopped describing reality — the member was renamed, blocked, banned, deleted | **that row is dropped**, the post is stored |

The second is the one worth stating: a member wrote a message, not a mention list, and losing the
message over a decoration nobody re-checked would be the wrong trade. Resolution re-reads the body
and accepts a range only if it still reads as `@` + the member's current name, which is also where
mentionability is enforced — a member who may not be mentioned has no name to match against.

### Why a stored range keeps describing its body

Two properties, and everything downstream leans on them:

- **A post is never edited.** There is no update path for a timeline body, so a range recorded at
  post time describes the body forever. Nothing has to re-anchor or re-validate at render time.
- **Deleting a member cascades their mention rows away.** The range is then simply not passed to the
  renderer, and it renders as the plain text it always was. No renderer needs a case for a dangling
  mention, and there is nothing to clean up.

## Rendering

[`EntityText`](../../app/Support/EntityText.php) walks the ranges with a cursor and alternates: text
between entities goes through [`BodyText`](../../app/Support/BodyText.php) (escape, autolink,
`nl2br`), and an entity's range becomes an anchor around its escaped text. Its architecture is
`Op3Text`'s — cut the entities out first, send only what is between them through the shared plain
renderer, so no escaping or autolink rule is restated ([body-text.md](body-text.md)). **An entity's
own text is never autolinked**: a member named `www.example.com` would otherwise nest an anchor
inside the mention's own. With no entities the output is `BodyText`'s exactly, pinned by test.

Classic renders it through `<x-timeline-body>`; Modern receives the ranges as
`mentions: [{memberId, offset, length}]` and splits them client-side
([`entity-split.ts`](../../resources/js/lib/entity-split.ts) +
[`entity-text.tsx`](../../resources/js/components/entity-text.tsx)), handing each text segment to the
existing `UserText` so the URL and line-break behavior has one implementation per surface. The
timeline deliberately introduces **no `bodyHtml`**: `RichBody` stays the app's sole
`dangerouslySetInnerHTML` sink.

The ranges are eager-loaded by every feed query and by the thread load, because the Classic row
partial is shared by the feeds, the profile and three gadgets — a read per row would multiply across
all of them.

Mentions link wherever the full body renders. The Modern dashboard's activity digest is not such a
place: its rows are one link each to the thread and render the body as a plain clamped preview —
URLs are not linkified there either, and an inline anchor inside a row-wide link would be nested
interactive content. The mention reads as text in the preview and links in the thread the row opens.

## OpenPNE 3 mentions are not migrated

The upgrade carries a timeline body over verbatim and writes no mention rows. The feature was
removed from OpenPNE 3 itself: the structured storage lost its callers in 2012, the
screen-name settings UI and the mentions gadget went in 2013, and what remains renders `@text` as
plain text — the list templates never linkify it, and the one index that would have fed a mentions
view is no longer written. So a verbatim body *is* the faithful port; recognising `@name` in old
bodies would invent links OpenPNE 3 never drew, against display names that were never unique.

Mentions are therefore prospective only, and by design: a mention exists because someone picked a
member, never because a body was scanned for text that looks like one.

## Key invariants

- Offsets and lengths are Unicode code points, half-open, ascending, non-overlapping. The write path
  is the only place that establishes this; renderers assume it.
- The stored body is what was typed. A mention adds a row, never a token, a placeholder or markup.
- A mention row exists only where the picker produced one — no body is ever parsed for `@`.
- A structural payload violation fails the whole post; a row that no longer matches is dropped alone.
- A range with no row renders as plain text, which is what makes the member cascade safe.
- An entity's own range is escaped but never autolinked.
- `EntityText` and `entity-split.ts` are a lockstep pair, like `BodyText` and `linkify.ts`.
- A reply inherits its parent's visibility; the thread is one audience.
