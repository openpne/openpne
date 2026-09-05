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

## Notifications

Five catalog kinds, all decided from the two posting events
([`TimelinePostPosted`](../../app/Features/Timeline/Events/TimelinePostPosted.php) /
[`TimelineReplyPosted`](../../app/Features/Timeline/Events/TimelineReplyPosted.php)):

| what happened | who hears | kind |
|---|---|---|
| a top-level post | everyone who can see it | `timelineNewPost`, or `timelineNewPostOnlyFriends` for the author's friends |
| a reply | the thread root's author | `timelineReplyPost` |
| a reply | the root's other repliers | `timelineRelatedPost` |
| either, naming a member | that member | `timelineMention` (OpenPNE-4-only) |

The new-post audience follows the post's visibility, exactly as OpenPNE 3's `notifyNewActivity` did:
Open/Members → every active member, Friends → the author's friends, Private → nobody. The two
new-post kinds compose as a union, the same `dependOnNot` shape as the diary broadcast
([notifications.md](notifications.md#broadcast-fan-out)) — OpenPNE 3 wrote it as an `elseif`, which
is the same set. A reply never broadcasts; it only notifies the root's author and its other
repliers, deduplicated so the root's author gets Reply and not also Related.

**Precedence is Mention > Reply > Related > NewPost, with one notification per member.** Its only
input is the mention snapshot the events carry, taken inside the writing transaction: the mention
notification sends to that set, and the two other paths subtract that same set rather than
re-deriving one — including the broadcast, which runs later in a queued job, where a re-derived set
could no longer be the one that was actually notified.

Every recipient is judged by one predicate
([`TimelineNotificationEligibility`](../../app/Features/Timeline/TimelineNotificationEligibility.php)):
not the author, not banned, able to view the **thread root**, and no block in either direction with
the author. It is evaluated twice — when the listener or fan-out enqueues, and again in each
notification's `shouldSend()` immediately before a channel delivers — because these notifications
queue, and a mail carries the post body to whoever is eligible at *delivery* time, not at post time.

`timelineNewPostCommunity` stays dormant (`isWired: false`) — see the replacement note below.

## Posting switch

`SnsSettingKey::TimelinePostingEnabled` (OpenPNE 3 `is_allow_post_activity`, on by default) is the
site-wide "may members write" switch. Off, [`EnsureTimelinePostingEnabled`](../../app/Http/Middleware/EnsureTimelinePostingEnabled.php)
answers the compose page, the post route and the reply route with 404 — OpenPNE 3 forwarded its
posting action to 404 and refused the API's activity POST, which its timeline plugin used for
comments as well, so replies are gated with posts. It sits after the auth gate and before the
throttle in the middleware priority list, so a refused POST consumes no limiter. Every compose and
reply affordance on both surfaces reads [`TimelinePosting`](../../app/Features/Timeline/TimelinePosting.php)
and disappears; what is already posted stays readable, and deleting is untouched. This is a policy
on top of the timeline unit, not the unit's own toggle ([feature-toggles](feature-toggles.md)).

## Key invariants

- Offsets and lengths are Unicode code points, half-open, ascending, non-overlapping. The write path
  is the only place that establishes this; renderers assume it.
- The stored body is what was typed. A mention adds a row, never a token, a placeholder or markup.
- A mention row exists only where the picker produced one — no body is ever parsed for `@`.
- A structural payload violation fails the whole post; a row that no longer matches is dropped alone.
- A range with no row renders as plain text, which is what makes the member cascade safe.
- An entity's own range is escaped but never autolinked.
- `EntityText` and `entity-split.ts` are a lockstep pair, like `BodyText` and `linkify.ts`.
- A reply inherits its parent's visibility; the thread is one audience — which is also what a
  notification's viewability and its feed row's link are judged against.
- The events' mention snapshot is the only input to notification precedence; no path re-derives it.

## Classic inline replies

A Classic feed row carries the **last ten replies by id** and a box to add one, as OpenPNE 3's
`timelineTemplate` did. Ten is [`RecentReplies::LIMIT`](../../app/Features/Timeline/Queries/RecentReplies.php),
which also decides whether the row offers to fetch the rest — one number, or a row draws a control
over a list that is already complete. The tail is one query for the page (`limit()` on an
eager-loaded `HasMany` compiles to a window-function partition), attached by the Classic responders
and the three gadget components: Modern's feed carries a reply count, not the replies. A reply row's
avatar is the 48px thumbnail drawn at 36, as OpenPNE 3 did — 36 is not an [allowed size](images.md).

Two contracts, because they fail differently:

| what | how | why |
|---|---|---|
| the whole reply list | `GET timeline.replies`, HTML fragment, `private, no-store` | the rows the page would have drawn, so the script inserts server markup rather than assembling any |
| posting a reply | `POST timeline.reply.store`, `wantsJson()` → `201 {html}` | 422 / 419 / 429 then arrive as Laravel's own JSON, and the answer is the row to insert |
| the next page of rows | `GET timeline.{index,member,tag}.rows`, HTML fragment, `private, no-store`, next page in `Link: <…>; rel="next"` | the もっと読む button appends what the pager's next page would have drawn; the script follows a `Link` only on its own origin |

Without the script the Classic pager stands in for もっと読む, and it comes back when a fetch fails.
The gadgets fetch one row past their limit to know whether to offer the button (timelineAll → the
home feed's rows at the gadget's own `per_page`, timelineProfile → the member's at the default 20;
timelineFriend has no page of its own to fetch from). Paging is by offset where OpenPNE 3 keyed on
`max_id`: a post made meanwhile shifts the next page by one. Classic posts from the home gadget's
box (its standalone page without the script); a site whose home draws no timeline gadget links to no
Classic way to post, as OpenPNE 3 linked none — the standalone page stays routable.

Three more things the row does in place, each a working link without the script:

- **Delete.** An own row carries OpenPNE 3's confirm block in a `<dialog>`; confirmed, it posts as
  JSON (`POST timeline.delete`, `wantsJson()` → `{ok: true}`) and every element drawn with that
  `data-timeline-id` leaves the page — two home gadgets can both hold it. The thread root on its own
  page posts as the page, since the page is what goes. A fetch that got no answer at all falls back
  to the plain submit; a refusal stays in the dialog, in words.
- **Timeago.** The stamp is `span.timestamp.timeago` with the absolute datetime as text and title
  and `data-datetime` (ISO 8601) for [`classic-timeago.js`](../../public/js/classic-timeago.js) —
  see [datetime](datetime.md#how-long-ago) for why this surface keeps OpenPNE 3's ladder.
- **Lightbox.** An attached thumbnail sits in OpenPNE 3's `rel="lightbox"` link to the full-size
  file (FilePolicy-gated); the page's one `dialog[data-timeline-lightbox]` shows it.

Every script is resident from `timeline/_scripts.blade.php` on the screens and the gadget wrappers;
a fragment never carries one, and what a fragment inserts announces itself with the
`classic-timeline:inserted` event the resident handlers listen for.

Both are gated exactly as the thread page is (`ShowTimelinePost`), and the fragment answers for a
**root id only** — a reply's id would re-center, and answering there would say which thread it is on.

The layer is an enhancement over markup that already works: every control ships as a real link and
the form as a real POST, so a browser that never runs
[`classic-timeline-replies.js`](../../public/js/classic-timeline-replies.js) reaches the thread page
by ordinary navigation. Three things it deliberately does not do:

- **No @mention picker in the row's box** — a plain body input, as OpenPNE 3 had it. The picker is
  on the thread page's form.
- **Reading a reply inline does not mark its notification read.** The thread page is what spends
  that row, and it still is.
- **Load-more replaces the whole list** rather than appending a window of it. OpenPNE 3 asked for
  "what I have plus twenty"; this asks for all of them, as the thread page already shows all of them.
  A long thread therefore answers with a long fragment, with no cap — an L3 difference, and the same
  unbounded response the thread page has always served.

## The community timeline was replaced by group talk

The timeline was once two audiences: SNS-wide posts, and posts scoped to one community by
`timeline_posts.community_id` (OpenPNE 3's `activity_data.foreign_table='community'` + `foreign_id`).
That scope is gone — [group talk](group-talk.md) took its place, and the cutover deleted the
community-scoped rows and dropped the column. **The table holds one audience again**, which is why
no feed here carries an exclusion any more.

What survives is the URL lineage, because members and OpenPNE 3 mail hold these links:
`/community/{id}/timeline` (the OpenPNE 3 `community_timeline` route), its global-fallback spelling
`/timeline/community/id/{id}`, and OpenPNE 4's own `/groups/{id}/timeline` all **redirect to
`group.talk.show`**, query preserved, path id winning over a stray `?group=`. They are gated on
`groupTalk`, not on `timeline`: the destination is a talk screen, so a site running talk with the
timeline unit switched off must still honour them. The compose and POST routes are simply gone — a
redirect that dropped a member's draft would be worse than a 404.

`timeline_new_post_community` stays registered as a **dormant** kind (`isWired: false`). Talk's own
per-message kind, [`group_talk_new_message`](group-talk.md#what-talk-notifies), is not read as its
successor — it is an administrator-defaulted kind about a room, and an OpenPNE 3 choice about
community timeline posts is not migrated into it — and the imported rows are kept because a member's
stored choice is a record rather than a value to discard. OpenPNE 3 registered the kind and never sent
it, and OpenPNE 4 wired it for the community timeline only, so nothing that ever reached a member is
withdrawn by leaving it dormant.

### Why `linkableTags()` still exists

A community post carried hashtags like any other, but the tag page is SNS-wide and excluded those
posts, so linking one handed the reader a page that did not contain the post they clicked from.
`TimelinePost::linkableTags()` was the seam that answered it, and both surfaces read it rather than
`tags()`.

Every post is SNS-wide now, so it returns them all — and talk parses no hashtags at all, so the
question cannot come back through that door. It is kept as a method anyway: it is the one place a
future scoped surface would answer, instead of each renderer deciding again and one of them
forgetting.

## A hashtag is a range too, but nobody picks it

`#tag` in a body becomes a `timeline_post_tags` row: the same half-open code-point range a mention
records, plus the tag itself. The shape is shared; the source is not. A mention exists because a
member chose someone from the picker, so the client sends the range. Nothing picks a hashtag — there
is no entity to pick — so [`HashtagParser`](../../app/Features/Timeline/HashtagParser.php) reads the
body at save time, and that is the only place a row comes from.

What it takes: a `#` or `＃` at the start of the body or after whitespace, then 1–30 code points of
letters, marks, numbers or `_`. A longer run yields **no** tag rather than its first 30 characters, a
tag of digits only is a number and not a topic (`#2026`), and a body contributes at most ten. Where a
candidate would overlap a stored mention **the mention wins**: a marker inside a display name is part
of that name.

### The stored tag is normalized; the range is not

`tag` is the matched text put through NFKC and then lowercased, so `#Tag`, `#ＴＡＧ` and `#tag` are
one topic and a lookup is an equality test. `offset`/`length` still describe the *raw* body, so a
reader sees the text that was typed and the tag page finds it anyway.

That normalization is a stored format, not a display choice: every row was written by the rule in
force when it was written, so changing the rule means re-normalizing all of them rather than parsing
differently from then on. NFKC is also why the length cap is checked twice — one code point can
expand into several (`ﬁ` into `fi`), so a run that fit before normalization can overrun after it.

### Bodies the parser never saw

`openpne:timeline-backfill-hashtags` re-derives every post's rows from its body, dropping and
rewriting one post's set per transaction. It covers posts written before the feature and posts the
OpenPNE 3 upgrade brings in — run it after an upgrade — and re-running it is how a site adopts a
parser change.

Mentions are deliberately not backfilled, for the reason above: a mention exists because someone
picked a member, never because a body was scanned.

### The page a tag opens

`/timeline/tag/{tag}` ([`TagFeed`](../../app/Features/Timeline/Queries/TagFeed.php)) is the home feed
narrowed to the top-level posts carrying the tag — **`TimelineFeedScope` unchanged**: collecting posts
under a tag must not widen who may read them, so a friends-only post reaches the same friends there
as anywhere else.

The URL carries the tag as it was typed, so the action puts it through `HashtagParser::normalize`
before looking it up. That is not a convenience: the column is byte-equal on every engine (utf8mb4_bin
on MySQL), so an un-normalized `#Tag` or `#ＴＡＧ` would find nothing rather than the topic it names.
A tag nobody used renders an empty feed, not a 404 — zero results is an answer, and a tag is not an
entity that can be missing.

### Linking the ranges

A tag range is an `EntityText` entity like a mention, with `hashtag` as its kind (and so its anchor
class) and the tag page as its href. Classic merges the two sets by offset in `<x-timeline-body>` and
Modern in `splitEntities`; nothing has to resolve an overlap, because the parser never stored one.
The anchor's text is the raw range, so a body written with `＃ＴＡＧ` shows `＃ＴＡＧ` and links to
`/timeline/tag/tag`.

Where mentions link, tags link — with the same exception, and for the same reason: the Modern
dashboard's activity digest stays a plain clamped preview whose row is itself one link.

### Hashtag invariants

- A tag row is derived from the body and nothing else; having one leaves the body unchanged.
- `tag` is NFKC-lowercased and at most 30 code points. `offset`/`length` are code points over the raw
  body, as a mention's are.
- A candidate overlapping a mention is dropped, so the two range sets never intersect.
- Re-running the backfill over an unchanged body and mention set changes nothing.
- Every tag lookup normalizes its term with `HashtagParser::normalize`; the stored form is the only
  form the column compares.
- The tag page applies the home feed's audience scope; a tag is a lens on the feed, never a way past it.
