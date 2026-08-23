# Group talk

A group's **talk** is one linear conversation: `group_messages` rows ordered oldest to newest, a
plain body of at most 5,000 code points, no editing, no threading. It is the successor of the group
timeline, and the structural difference from it is that a message carries **no audience of its own**.
A timeline post holds a `visibility`; a talk message holds nothing, because the group already
answers who may read it.

Modern only — talk has no OpenPNE 3 counterpart, so there is no Classic screen to be compatible
with. [`GroupTalkController`](../../app/Features/GroupTalk/GroupTalkController.php) renders Inertia
directly, as `/groups/recent` does.

The room is a chrome registry **conversation** screen
([feature-modules.md](feature-modules.md)): below lg it carries no bottom tab bar and its chrome does
not recede, so the composer is the last thing on the screen at every scroll position.

The screen is not the only wire in. The [MCP endpoint](mcp.md) reads and writes talk as a member,
through the same Actions and Queries — never a second write path. What the two wires must agree on is
[`TalkBody`](../../app/Features/GroupTalk/TalkBody.php): the LF newline rule and the 5,000-code-point
cap. The rest of what the form applies (trimming, the type check) is middleware a browser request
meets on its way in, so each wire states it where it applies.

## It shipped switched off (historical)

`groupTalk` is an ordinary feature unit now: absent row means on, and only a stored `'0'` takes it
away, like every other unit ([feature-toggles.md](feature-toggles.md)).

It was not, while it was being built. Talk **replaces** the community timeline rather than joining
it, and the two must never have been reachable at once — so the flag was the one fail-closed
availability switch in the registry, in two halves that had to agree: `default()` answered `false`
(what an absent row resolves to, since `decode()` returns the default before reaching any arm) and
the `decode()` arm accepted only a stored `'1'` (so a blank or garbled value stayed dark too).
Operators opted in per site from admin → Settings → Features to try it.

The cutover deploy flipped both halves at once and removed the community timeline in the same
commit, which is why neither half survives to be explained here. The reason to record it: if some
future unit has to ship dark, *both* declarations are the answer, and flipping only the decode arm
would silently change nothing for the sites that matter — the ones with no row at all.

## Schema

| table | what it holds |
|---|---|
| `group_messages` | `group_id`, `member_id` (nullable), `in_reply_to_id` (nullable, **no FK** — see [Replies](#replies)), `body`, `link_card_id` / `link_card_synced_at` (see [Link cards](#link-cards)), timestamps |
| `group_message_images` | join rows to `files`, numbered slots, shaped exactly like `timeline_post_images` — see [Images](#images) |
| `group_message_mentions` | code-point ranges, shaped exactly like `timeline_post_mentions` |
| `group_members.talk_read_at` / `.talk_read_message_id` / `.is_talk_muted` | the read cursor (a copied `(created_at, id)` tuple, no FK) and the per-group mute — see [Unread](#unread) |
| `reactions` (+ `groups.talk_reaction_seq`, `group_messages.reactions_version`) | the emoji on a message, keyed polymorphically so the surfaces after talk share the table — see [Reactions](#reactions) |

`group_message_mentions` and `group_message_images` are both written by the composer — see
[Mentions](#mentions) and [Images](#images).

**`member_id` is `nullOnDelete`.** A withdrawn author leaves the message in place and reads as
`Withdrawn member`, matching the other group tables (`group_topics`, `group_events` and their
comments). The repository splits this by axis rather than by table: personal content (diaries,
timeline posts) goes with its author, content that belongs to a place stays in the place. A
`group_message_mentions` row is the opposite — `cascadeOnDelete`, so deleting the member takes the
mention away and the range renders as the plain text it already is.

**`in_reply_to_id` is the reply reference** — see [Replies](#replies). It carries **no foreign key**,
which is the one structural thing to know about it here: an id pointing at a message that has been
deleted stays, and reads as such. Every writer therefore checks what the engine no longer does — a
live message of the same group — and deleting a message never touches the messages answering it.

## Ordering is the `(created_at, id)` tuple

Never `id` alone: migrated rows are inserted in transfer order, so their ids are not monotonic in
time. Never `created_at` alone either: a MySQL timestamp is second-precise, so it is not a total
order, and a page boundary landing inside one second would drop or repeat a message.

[`GroupTalkMessages`](../../app/Features/GroupTalk/Queries/GroupTalkMessages.php) pages by keyset on
that tuple rather than by offset — a conversation is written to while it is being read, and an offset
page would shift under the reader every time a message arrived. The comparison is written out as
`created_at > ? OR (created_at = ? AND id > ?)`, not as SQL's row constructor, which SQLite has no
support for. `(group_id, created_at, id)` is the index that serves it.

Each serialized message carries its own **cursor** (`{iso8601}|{id}`, opaque). The client hands one
back to ask for the page before it (`?before=`) or what has arrived after it (`?after=`), so the
tuple encoding never leaves the server and a boundary keeps working after the message it was taken
from is deleted. A cursor that does not parse is read as no cursor: pagination is a position, not a
permission.

The page is capped at `GroupTalkMessages::PER_PAGE` in both directions and is not client-controlled.
Live updates are a **poll** — a visible tab asks for anything after its newest message every ~8s.
SSE or WebSockets would mean a resident process, which the single-site hosting contract does not
have. A conversation restored from history polls immediately instead of waiting out the first
tick: it reopens on messages as old as the visit that left it.

The client keeps the same order. A send and a poll are in flight together by design and complete in
whatever order the network gives them, so
[`lib/chat/stream-state.ts`](../../resources/js/lib/chat/stream-state.ts) re-sorts by the
tuple on **every** merge rather than trusting arrival order — appending as responses land would put
the list out of order permanently, and the poll's next watermark would then be read off a row that
is not the newest. A locally deleted id is a **session-lifetime tombstone** for the same reason: a
poll already in flight answers from a snapshot that still holds the row, and nothing afterwards
would remove it again.

### Two windows, never mixed

The list on screen is either of two stretches, and `stream-state.ts` names which:

| window | what it is | poll |
|---|---|---|
| `latest` | ends at the newest message | yes |
| `history` | a slice behind it — what the unread jump opens on, and what a `?m=` link is rendered with | no |

The window is read off the page's own `hasNewer`, including the one the server rendered: a deep
link's landing starts in `history` and the poll never runs on it, while an ordinary visit and a link
into the newest page alike start `latest`.

A poll in the history window would append messages that do not follow the last row on screen, so it
stops; the reader steps forward with **"load newer"** (`?after=`, the poll's own read) until the
server reports nothing beyond the page, at which point the list runs to the newest message and the
window is `latest` again. `hasNewer` on a page answers exactly that — *rows follow this that you were
not given* — so the two backwards reads say false: what follows the page "load older" returned is
already on the client's screen.

Only a page contiguous with what is held is ever merged. **Every window change replaces the list**
(`enterHistory` / `enterLatest`), because a list assembled from both stretches would draw the hole
between them as if it were conversation. Sending from the history window therefore re-reads the
newest page rather than appending: your own words must not land under a message they do not answer.
The session's tombstones are the one thing carried across a replacement.

Reads are in flight while that happens — the poll is on the wire when the reader taps the banner, and
either answer can land first. So the state carries a **generation**: every read is issued against the
one the list stood at, every window change moves it on, and `applied()` is the single door a response
comes through. One that arrives against a generation that has passed is dropped rather than merged,
which is what stops a live row being spliced across the gap (and the next watermark being taken from
beyond it, where "load newer" would never ask). Two things are deliberately outside it: a deletion,
which is a fact about the conversation rather than a page of it, and the composer's own message,
which is held to the live window instead — it belongs at the foot of any list that ends at the
newest, including the one just re-read to get back there.

The generation only moves when such a read is *applied*, so it cannot order the reads that ask for a
move against each other. A second count does that, and its rule is **the last intent wins**: a jump
retires the jump before it, and a send retires a jump outright. The composer stays live while a jump
is out, so a reader can ask to be taken back through history and then write instead — and writing
puts them at the live end, which a page fetched for the move they abandoned must not undo.

## Unread

The read cursor is the `(talk_read_at, talk_read_message_id)` pair on the **membership row**, not a
table of its own. That placement is the design: **membership implies cursor** by the row's existence,
so a non-member reader — an Everyone group is readable by anyone signed in — cannot accumulate unread
state at all, and leaving takes the cursor with it. Rejoining creates a fresh row, which is why time
away counts as read rather than as a backlog.

The message id is a **copied value, not a foreign key**. Deleting the message a cursor names is a
no-op; the count simply falls as the row stops existing.

### The cursor is snapshotted, not defaulted

[`TalkReadCursor::snapshot()`](../../app/Features/GroupTalk/TalkReadCursor.php) reads the group's
newest live message and every membership-creating path writes it: group creation, open join,
join-request approval, and the bulk add-all. (Registration does not auto-join default groups, so
there is no fifth path; the OpenPNE 3 upgrade is exempt by
[`GroupMemberUpgrade::targetDefaults()`](../../app/Upgrade/Steps/GroupMemberUpgrade.php), since an
upgraded site has no talk yet and the history transfer re-establishes cursors afterwards.)

The columns' DB defaults (`useCurrent()`, `0`) are a **backstop for paths this helper cannot reach,
not the initialization**. `(now(), 0)` is not the same boundary as the real latest tuple: a MySQL
timestamp is second-precise, so a message written in the same second as the join has the tuple
`(t, id > 0)`, which compares **greater** than `(t, 0)` and would show up unread the instant someone
joined. Reading the actual latest tuple is what closes that second, and the discriminating test says
so.

### Mark-read is client-named, server-resolved, and monotonic

`POST /groups/{group}/talk/read` takes the id of the last message the client **rendered**. Three
rules, each answering a way of getting it wrong:

- the server takes the group's newest at POST time → messages that arrived between the page loading
  and the call would be marked read unseen. So the client names the id.
- the client sends a timestamp or a whole tuple → a bad one could erase future unread. So the server
  resolves the tuple itself, from a **live row of this group** (anything else is refused).
- two tabs, or a retry, report out of order → the update carries the comparison in its `WHERE`
  (`stored < resolved`, expanded), so it can only move forward and replaying is free.

The same endpoint with **no id** means "read through the latest" — the catch-up on the absence digest
below. The server reads the group's own newest tuple inside that operation rather than taking one the
client fetched: a client-fetched latest leaves a window in which a message can land and be marked read
having been on nobody's screen. The monotonic rule is unchanged, so a request whose "latest" is
already behind the cursor is a no-op. A `messageId` that is present but unusable is still a 422 —
falling through to marking everything read is exactly what an unusable id must not do.

The client reports while it is visible and at the foot of the **live** window: reading back through
history is not reading what has arrived below, and the foot of a history window is not the foot of
the conversation at all. Opening a room therefore clears its badge, backlog and all — the badge
answers "is there anything here", and a room you have been into is one you have seen.

Sending advances the cursor to the new row's tuple **inside the insert's transaction**: writing is
reading, and a cursor left behind your own message would show your own words arriving as unread.

An accepted report also asks the shell to re-read the shared badge counts
([`lib/unread-refresh.ts`](../../resources/js/lib/unread-refresh.ts)) instead of leaving them on the
minute clock, which reads as the screen the member is looking at not having counted. It rings rather
than patches: `unread` keeps one writer, so a page's guess can never outlive the authoritative read.

### The divider is a snapshot, and the banner is what it cannot draw

The page ships `talkUnreadSnapshot` — the count, and the boundary in two shapes — and it is **fixed
for the visit**. That is the whole design: mark-read fires seconds after the page opens and takes the
stored cursor to the foot of the conversation, so a divider recomputed from it would vanish, and a
jump that asked the server "where have I read to" would answer with the end of the group. The
snapshot is the render-time position, held on the client, and nothing that happens afterwards moves
it. The count is `UnreadTalkScope`, so the number that sent the reader here and the number they are
shown cannot disagree. A non-member holds no membership row and so gets null, not zero.

| field | what reads it |
|---|---|
| `readThrough` (`{at, id}`) | the divider — compared against each message's own `createdAt`/`id` |
| `cursor` (opaque) | the jump — handed straight back as `?context=` |

`readThrough.at` goes out through the same conversion as a message's `createdAt`
(`GroupMessageSerializer::instant()`) because the client compares them directly to find the first row
past the boundary. The tuple encoding still never leaves the server: `cursor` is the same position
already encoded, so the client echoes rather than assembles.

The boundary is not always on screen. When the first loaded row is itself unread **and** older rows
remain, the true line is further back than the page reaches, and drawing one at the top would mark
where pagination stopped rather than where reading did. That is the state the "N unread" banner
offers to fix.

### Date headings, and what stands above a row

The rows of one calendar day sit under a date heading, which is what lets a row carry only a time
([datetime.md](datetime.md)). A heading is drawn above the first loaded row as well, unlike the unread
divider: a heading claims only that the rows below it were said on that day, which stays true however
far back the history reaches — and when "load older" prepends more of the same day, the row that
carried it stops opening the day and the heading moves up with them.

The two meet on an ordinary morning, when the first message a reader has not seen is also the first of
that day. `separatorsAbove` (`lib/chat/separators.ts`) owns the order so that it is stated once rather
than left to the order the markup happens to be written in: **the heading is outside the unread line**,
because the day is true for every reader while the line is only this reader's place among them.

**Both are the same shape — a label between two rules — and what tells them apart is that each carries
its own label.** Colour reinforces it (the heading neutral, the unread line in the accent) but is not
what does the work: in greyscale the two are still unmistakable, and the rules themselves are nearly
the same weight. The load-older strip proves the same point the other way — its rule is the *identical*
colour to a date heading's and sits 16px above one on every visit with history, and the pair reads
fine because only one of them is labelled.

So the thing to protect is the labels. A change that drops one ("the date is enough", "the unread line
needs no words") is what breaks this; a theme that brings the two colours together does not.

The heading was a pill for a while so that meeting the unread line could not read as one divider; that
made its label float, and a floating label does not say which side of it the day belongs to. A rule
says it by being a boundary. Mattermost, whose rows are shaped like these, draws one separator and
overrides only its colours for the unread one.

**Those two are the only rules in the list.** Rows are told apart by the space above them: a rule
between two people speaking is the vocabulary of a board, and this is a conversation. Space also says
how far apart two rows are, which a hairline cannot — a run's follow-ups close up to a hair, while a
new turn opens with room above it.

**The unread line is drawn where the heading is not**, and that follows from the same difference. A
heading says the rows under it were said on that day: true for every reader. The unread line says where
*this* reader stopped, a claim so tied to its position that `dividerBeforeId` withdraws it rather than
draw it where the position cannot be shown honestly.

### The absence digest

Past [`TalkAbsenceDigest::THRESHOLD`](../../app/Features/GroupTalk/Queries/TalkAbsenceDigest.php) the
page also ships `unreadDigest`, and the boundary's affordance becomes a card: what was missed, and one
tap to spend it. Below the threshold the key is **absent** rather than null, and no digest query runs.

The count is the snapshot's own, never a recount — the card and the divider beside it name one
backlog. Everything after it comes from a bounded sample: the first `SAMPLE` unread messages from the
boundary, read through `UnreadTalkScope` so the sample and the count cannot describe different sets.
So the faces are who has been talking **at the boundary**, busiest first, rather than a ranking over
the whole backlog — and a picture on the message after the sample is not the card's to show. Each
candidate picture passes both gates the group page's picture strip runs, the parent-ownership check
and `FilePolicy`, and a refusal leaves no trace in the payload.

Muting does not hide it: mute silences the nav badge and the notifications, and opening a room still
says what was missed.

One card is drawn, at the separator or in the banner's place, with the same payload and the same
catch-up either way; which of the two is
[`digestPlacement()`](../../resources/js/lib/chat/unread.ts). Spending the catch-up withdraws the card
and leaves the banner's jump behind it — nothing re-reads the snapshot mid-visit, for the reason the
divider does not.

### `?context=` — the page a position sits in

`GroupTalkMessages::around()` returns `CONTEXT` messages up to and including the cursor, then
everything after it up to `PER_PAGE`, as one contiguous page for the history window. Two callers ask
for it: the unread jump, over `?context=`, and `show` when a `?m=` link names a message — the same
window, around a cursor taken from that message rather than from the read boundary.

A cursor here is a **position, not a permission** — the same trust `before` and `after` already
carry, since the group's read gate decided the audience before any of them are read. So it takes one
the client hands back, an unparseable one is no cursor at all (the newest page), and one taken from
another group names an instant rather than a row: the query is bound to this group, which is what
keeps the answer this conversation's.

### Two different numbers

| where | what it counts |
|---|---|
| a room's row on the joined-group list ([`JoinedTalkRooms`](../../app/Features/GroupTalk/Queries/JoinedTalkRooms.php)) and the group page's talk card ([`UnreadTalkCounts`](../../app/Features/GroupTalk/Queries/UnreadTalkCounts.php)) | unread **messages** in that group |
| nav badge, `groupTalks` ([`CountGroupsWithUnreadTalk`](../../app/Features/GroupTalk/Queries/CountGroupsWithUnreadTalk.php)) | **groups** with anything unread, muted ones excluded |

The badge counts rooms rather than messages because a message count is dominated by whichever group
is busiest, which says nothing about where to go next. It joins the shared props through
`App\Features\Home\UnreadCounts` and reports zero, unqueried, while the unit is off — like every
other badge there.

All of them read the same predicate ([`UnreadTalkScope`](../../app/Features/GroupTalk/UnreadTalkScope.php)):
newer than the cursor by the tuple, and **`member_id IS NULL OR member_id != viewer`**. That NULL arm
is load-bearing, not defensive — `member_id != ?` is UNKNOWN for a withdrawn author's row, so without
it the count would silently skip exactly the messages the page still shows as "Withdrawn member". The
visible set for counting is the same one the page renders, per the no-per-row-filter contract above.

### Mute

`group_members.is_talk_muted`, set through `POST /groups/{group}/talk/mute` with the state to move
to (not a blind flip, so a double tap settles). Muting takes the group out of the nav badge and
nothing else: its own per-group count keeps showing, de-emphasized, because the member asked for
quiet rather than to lose track of the conversation. Leaving clears it with the row.

Mentions **pierce mute** — a message addressed to you outranks the room's quiet — see
[Mentions](#mentions) below. The per-message broadcast does not: muting takes the room out of it
([What talk notifies](#what-talk-notifies)).

While it is on, the room says so: a line under the toggle (its `aria-describedby`) states that the
broadcast is off and the room is out of the badge, and that a mention still arrives. It lasts as long
as the mute does, so muting is its own confirmation; unmuting leaves nothing to read and gets a
spoken line instead. The member's mutes are also listed together on the notification settings page
([notifications.md](notifications.md#the-per-member-catalog)).

## The joined-group list is a room list

`/groups/mine` serves two shapes from one component and the server names which one it sent (`view`).
The viewer's own list under Modern with talk on is
[`JoinedTalkRooms`](../../app/Features/GroupTalk/Queries/JoinedTalkRooms.php): rooms in the order
they were last spoken in, each row opening the conversation rather than the group. Every other
reading of the route — another member's memberships, Classic, talk switched off — keeps the
membership grid (`ListMemberGroups`) and reads no message at all. A room list is the viewer's own by
construction: the order is their conversations and the pills are their unread, neither of which
another member's membership list can answer.

The order is, all descending, *has a message* → the newest message's `created_at` → its `id` → the
membership's `created_at` → the group id. It is **decided in SQL before the page is cut**: paging
the groups first and then looking up their newest messages sorts the page rather than the
membership, and a group talked in an hour ago belongs at the top whether or not it falls in the
first twenty by id. So the newest `(created_at, id)` rides along as two correlated subselects, one
per column — the tuple cannot be read in one statement without a row constructor or a lateral join,
and SQLite has neither. Each is a single seek on `(group_id, created_at, id)`. Whether a room has
anything at all is spelled as a `CASE` rather than left to where the engine collates NULL in a
descending sort, which is not a portable answer.

The bodies follow in one lookup by primary key, since the ordering has already named the exact rows.
A page therefore costs the same whether it holds one room or twenty. That lookup also asks whether
the message has pictures, because a message may have nothing else: the preview
([`ChatPreview`](../../app/Support/ChatPreview.php)) falls back to a stand-in for it rather than
letting the row's "author: " trail into nothing. Whether, not how many — the stand-in never counts
them. The group page's own talk card previews the same way through
[`LatestGroupMessage`](../../app/Features/GroupTalk/Queries/LatestGroupMessage.php), and the
conversation list of direct messages is the third caller of the same helper.

The Modern dashboard leads with the same rooms — the first five, and the same row component, so what
the digest says and what the list says cannot drift. It reads them through `take()` rather than the
paginator: a screen with no pager has no total to count and no page number to honour.

The desktop sidebar draws the head of the same list ([`NavTalkRooms`](../../app/Features/GroupTalk/Queries/NavTalkRooms.php)),
which shares the ordered query and stops there: no preview is drawn, so the bodies and their authors
are not read, and the slice costs two queries — the rooms and their images — on a prop every Inertia
page evaluates. It holds ten and hands the rest to `/groups/mine`; `hasMore` is one row read past
that limit rather than a `count(*)` over the membership.

That list rides `GET /unread-counts` beside the badge counts, so every path that keeps the shell live
— the minute clock, returning to the tab, the service worker, and the bell a page rings after a
mark-read or a mute ([notifications.md](notifications.md#liveness)) — moves the badge and the rooms
it counts from one answer. Reading them apart is what let the nav show no groups waiting above a row
still claiming five.

## Mentions

Talk parses `@mentions` and nothing else — no hashtags: a chat has no tag culture, and a per-group
tag index would be a screen nobody asked for. URLs are linkified at render, as everywhere, and the
first of them is previewed as a card (below).

The mechanics are the timeline's, reused rather than re-implemented — the range storage, the resolver
and the composer state machine are all shared code. [timeline.md](timeline.md#a-mention-is-a-range-not-text)
is the reference for how a range works and how offsets are counted in code points. What follows is
only what talk does differently.

**A body is never read for `@`.** A mention row is written by whoever names a member deliberately,
and talk has two such producers: the composer's picker, and the MCP `reply_to_message_id`, where the
server writes the handle and the range that covers it ([mcp.md](mcp.md#answering-someone)). Both hand
[`ResolveMentions`](../../app/Features/Timeline/Actions/ResolveMentions.php) ranges over a body they
composed, and both are checked against the same mentionable set. The MCP side writes its handle into
the body, so it asks the write to verify it (`mentionsRequired`): a row the resolve drops rolls the
message back with it rather than leaving a handle nobody can edit out.

**The room is the mentionable set.** [`GroupTalkMentionCandidates`](../../app/Features/GroupTalk/Queries/GroupTalkMentionCandidates.php)
offers the group's own members and nobody else: a name from outside could not read the message the
mention appears in. There is deliberately **no friend tier** — the timeline's picker ranks friends
first because its candidate set is the whole SNS, but here the set is already the room, and ranking a
friend above the person you are talking to would order the list by the wrong relationship.

What the picker offers is exactly what [`ResolveMentions`](../../app/Features/Timeline/Actions/ResolveMentions.php)
accepts for the same group — it takes the group and narrows mentionability to its members, which is
why talk reuses it rather than growing a second copy of the rule. A test walks the offered set and
resolves every name, so the two cannot drift into a picker that suggests members the submit drops.

**Two layers of failure**, as the timeline has them: a payload the picker could not have produced is a
broken client, so the whole message is refused (422); a row that merely stopped describing reality —
a rename, a fresh block, a member who left — is dropped alone and the message still posts. The bounds
differ only in scale: talk's body caps at 5,000 code points, so `MentionRules::rules()` takes the cap
rather than hardcoding the timeline's 140.

**No message screen, but an anchor in the conversation.** A mention notification links to
`/groups/{group}/talk?m={message}`: talk has one screen, and the link names a place in it rather than
a permalink to a message of its own. `show` opens on the page that message sits in — the same
[`around()`](#context--the-page-a-position-sits-in) read the unread jump uses — and hands the client
an `anchor` prop, which lands the scroll on the row and picks it out for a couple of seconds.

The anchor is **best-effort**. `?m=` is honoured only for a live message of *this* group; a deleted
one, another group's id, or anything that does not parse opens the newest page with no anchor at all,
because a link that outlived its message still leads to a conversation worth arriving in. The gate is
unmoved by it: a reader the group refuses gets the same 404 with or without an anchor. The feed keeps
its own click-time re-check (message alive, reader may still read the group) and resolves to nowhere
when either has changed — mail and a pasted URL never pass through that, which is why `show` validates
in its own right.

**An anchor is not a reading.** Landing on it does not clear the room's badge: mark-read still fires
only from the foot of the *live* window, so a reader taken back into history marks nothing until they
return to the newest message. The unread snapshot is read exactly as it is for an ordinary visit —
where the reader had got to, not where the link sent them.

### What talk notifies

Two kinds, both OpenPNE-4-native (no OpenPNE 3 ancestor) under the `GroupTalk` category, each with a
configurable mail template.

**`group_talk_mention`** — sent by every site, in-request, to the members a message named.

**`group_talk_new_message`** — one notification per message to the whole room, sent only where an
administrator asked for it: `group_talk_notify_default` (**Talk settings**, read through
[`GroupTalkNotifyDefault`](../../app/Features/GroupTalk/GroupTalkNotifyDefault.php)) is the kind's
**web** default, and its mail default is off whatever the site says. A member's own row overrides
both, and for this kind a row is an override rather than a copy of the default
([notifications.md](notifications.md#the-per-member-catalog)). The audience
([`GroupTalkBroadcastRecipients`](../../app/Features/GroupTalk/Queries/GroupTalkBroadcastRecipients.php))
is the room minus the author, banned members, blocks, the members the message mentioned — they are
getting the mention instead — and **anyone who muted the room**. On a mentions-only site with nobody
opted in, the queued job exits on two indexed probes without touching the membership.

[`GroupTalkNotificationEligibility`](../../app/Features/GroupTalk/GroupTalkNotificationEligibility.php)
answers who may receive one: a current group member, not banned, still able to read the group, with no
block in either direction with the author, and never the author. It is asked **twice** — when the
sender enqueues and again in `shouldSend()` immediately before each channel — because a talk mail
carries the message body and a queued job can outlive the facts it was enqueued under
([notifications.md](notifications.md#delivery-time-re-checks)).

Three asymmetries are deliberate:

- **Mute does not gate a mention, and does gate the broadcast.** `is_talk_muted` silences the room's
  badge; being named is addressed to one person and outranks having asked the room for quiet, while a
  message addressed to the room is exactly what the quiet answers. A member who wants no mention mail
  turns the catalog kind off, which is a different question and is honoured.
- **Blocking gates both**, though talk history does not filter by block at all. History is the
  record of what was said; a notification is putting two people in front of each other, which is
  exactly what a block refuses.
- **An already-read message is not broadcast.** `BroadcastGroupMessagePosted` is dispatched with a
  `GRACE_SECONDS` delay so a member sitting in the room marks it read first, and the job then drops
  anyone whose cursor has passed it (`TalkReadCursor::isBehind`, the same tuple `advance()` moves).
  It is a **race, not a guarantee**: a read landing after the check still leaves the notification sent.

**One feed row per room.** The broadcast writes the room's row, not the message's: once the new row is
written, [`KeepOneGroupTalkRoomRow`](../../app/Listeners/GroupTalk/KeepOneGroupTalkRoomRow.php) deletes
the room's other rows of that kind, read ones included, so the feed holds one line per conversation
however much is said in it while the device is still nudged for each message (push follows the
`database` send). Reading the room marks that row read — every path through
[`MarkTalkRead`](../../app/Features/GroupTalk/Actions/MarkTalkRead.php), the page's report, the digest
catch-up and MCP alike, plus posting into the room — so on an `all` site the bell and the rooms badge
light and clear together. Deleting only after the insert is what makes a lost listener or a vetoed
send harmless; two messages landing at once can briefly leave two rows, and the next one settles it.

An author who withdraws before delivery takes the queued notification with them
(`deleteWhenMissingModels`): "Withdrawn member mentioned you" is a notification nobody can act on.

## Replies

A message may answer another one in the same room. The reference is drawn as a header above the
reply, one tap from the message it names — a reference, not a thread: the conversation stays
linear, and a reply is an ordinary message for ordering, unread, the room list and the poll.

**A reference, not a snapshot.** Nothing about the parent is stored on the reply. The header's
author, excerpt and thumbnail are read off the parent row every time it is serialized, so a header
cannot go on describing a message that is no longer there. The excerpt is
[`ChatPreview`](../../app/Support/ChatPreview.php) — the same line every conversation list previews a
message by, a payload bound with the visible truncation left to the client's clip.

**A deleted parent is a state, not a hole.** The column carries no foreign key, deliberately:
`nullOnDelete` would erase the reference and leave an answer indistinguishable from a message that
answered nothing. So the id dangles and the wire says `{deleted: true}` where a live parent would
have said `{deleted: false, …}`. Unlike the [reactions](#reactions) rows that also live without a
foreign key, nothing collects it — dangling is the meaning.

**The lookup is bound to the group.** [`ReplyReferences`](../../app/Features/GroupTalk/Queries/ReplyReferences.php)
reads the parents of a whole page in one query, `where group_id = …`, so an id from another
conversation comes back missing and renders as deleted. Structural scoping rather than a defensive
branch, and the reason no `belongsTo` eager load may stand in for it: an unscoped one would answer
with the foreign row.

**Writers validate, and the race is accepted.** `reply_to_message_id` is resolved to a live message
of this group — a 422 when it is not, so the composer keeps the draft and says why — and the
same-group half is re-asserted at the single write chokepoint
([`CreateGroupMessage`](../../app/Features/GroupTalk/Actions/CreateGroupMessage.php)). Nothing is
locked between the check and the insert: a parent deleted in that window leaves a dangling reference,
which is the state deleting it produces anyway.

**One level.** A reply describes its own parent and never that parent's parent. The header carries
the parent's cursor, so following it opens the page that message sits in
([`around()`](#context--the-page-a-position-sits-in)), exactly as the unread jump does.

**Nothing notifies**, for the reason a [reaction](#reactions) does not: the room's unread badge is
what says something happened here, and anyone who needs to be sure of reaching a person @mentions
them. The one count that a reply reaches is `unreadMentions` on the MCP room list — unread messages
that name the caller **or** answer something they said, the poll that decides whether a room is
waiting on an agent with no screen to look at ([mcp.md](mcp.md#tools)).

[`post-talk-message`](mcp.md#answering-someone) writes the same column, and addresses the answered
author on top of it: the reference is what the room draws, the @mention is what notifies.

## Images

Up to three images per message, numbered `1..N` in the order the sender picked them. The composer
offers three rather than the timeline's one because a chat is where pictures are sent — three is the
cap the boards, diaries and direct messages already carry (`PostImages::MAX_IMAGES`), so talk reuses
[`PostImageRules`](../../app/Http/Requests/Concerns/PostImageRules.php)' `images[]` shape and nothing
about the cap is talk's own. The schema's slots go past three because the transfer that brings the
old community timeline across may carry content with more.

The write goes through [`PostImages`](../../app/Files/PostImages.php), and **its `attach()` owns the
outermost transaction**. This is not incidental. A byte write is not transactional: `compensating()`
tracks every File it stored and deletes those bytes when the transaction throws. Wrapping another
`DB::transaction` around it would let the inner one roll back and commit while the bytes stayed on
disk, and the compensation would never run — which is the mistake the event-comment flow had to
merge its way out of. So `CreateGroupMessage` puts everything else — the message insert, mention
resolution, the cursor advance, the posted event — inside `attach`'s persist callback rather than
around it.

A refused file — one over the cap, one of the wrong type — is a 422 on `images`/`images.N` that
takes the **whole message** down, and the composer keeps the whole draft: body, mention rows and
every picked file. Nothing is cleared until the message is actually written, so a retry carries what
the first attempt had.

### A picture is a message

[`StoreGroupMessageRequest`](../../app/Http/Requests/GroupTalk/StoreGroupMessageRequest.php) requires
words **or** at least one attachment, on either upload wire — a message with neither is the only one
it refuses. This is that endpoint's authoring contract, not a constraint on `group_messages`: the
transfer writes rows the form never sees.

The body such a message stores is the empty string. It arrives as null — the global `TrimStrings`
then `ConvertEmptyStringsToNull` have already made a blank or whitespace-only field one — and
`prepareForValidation` maps only that null back to a string, so `body === ''` is the single shape "no
words" takes downstream. A body that is neither null nor a string is left untouched for the `string`
rule to refuse; coercing it would post a value the member never wrote. An empty body carries no
mentions either: a range over no text resolves to nothing, so a picture-only message notifies nobody
however its `mentions` payload was forged.

### Who may see one

[`FilePolicy`](../../app/Policies/FilePolicy.php) gains a `groupMessage` arm: an attachment is
viewable exactly when its message's group is
([`GroupTalkAccess::canView`](../../app/Features/GroupTalk/GroupTalkAccess.php)), and the file's
owning unit is `groupTalk`, so switching talk off takes the bytes with the screen. A file is fetched
by URL with no page mediating it, so an unmatched morph stays unviewable — the arm has to exist or
every talk attachment 404s.

Like the conversation itself, this applies **no per-row filter**: an image posted by someone who has
since left the group, or who is in a block relationship with the viewer, stays as visible as the
message it hangs on.

### Reclaiming the bytes

A cascade drops join rows and never File bytes, so both deletion paths collect the Files first and
purge after:

- [`DeleteGroupMessage::purge()`](../../app/Features/GroupTalk/Actions/DeleteGroupMessage.php) — one
  message, the `DeleteTimelinePost` shape.
- [`DeleteGroup::purge()`](../../app/Features/Group/Actions/DeleteGroup.php) — every message in the
  group, before the group row goes. Talk is flat, so there is no parent whose purge would reach the
  rest; the arm walks them all, beside the existing topic, event and timeline arms.

## Link cards

The first URL in a message is previewed, on the same two columns and through the same two jobs as
every other body that carries a card ([link-cards.md](link-cards.md)). Two things here are talk's
own:

- **Nothing invalidates.** A message cannot be edited, so a card belongs to the only body its row
  will ever have, and the shared trait's `clearLinkCardIfBodyChanged` has no call site here.
- **The conversation page is the read trigger**, because talk has no detail page for one to hang off.
  What bounds that, and what a reader who already has the room open does *not* see, is in
  [link-cards.md](link-cards.md#the-conversation-page-is-talks-detail-page).

Direct messages carry no card. That difference between the two chat surfaces is deliberate: a
private message's URL is not one this site announces to the far end.

## Reactions

An emoji on a message, in the `reactions` table — polymorphic from the first row, because talk is
where reactions land first and not where they stop. `reactable_id` therefore carries no foreign key,
which is the whole reason two of the three deletion paths below have to sweep by hand.

**One member may hold several emoji on one message**: the unique key is `(reactable_type,
reactable_id, member_id, emoji)`. Narrowing it to one emoji per member later would have to throw rows
away, which is why the wide key is a decision rather than the shape that fell out. On
MySQL `emoji` is `utf8mb4_bin`, since the default collation equates a code point with its
VS16-qualified form (`U+2764` = `U+2764 U+FE0F`) and SQLite's binary TEXT does not — the two engines
would otherwise disagree about what counts as one reaction.

[`ReactionVocabulary`](../../app/Features/Reactions/ReactionVocabulary.php) is the only place the set
is written down, its size included: the add rule reads it, the page ships it to the picker as
`reactionVocabulary`, and the tests pin its bytes. Nothing in the bundle holds a copy, so one render
draws its picker from one set. A prop is fixed at render time, though — a tab left open across a
deploy goes on offering what it was rendered with, and what closes that skew is the server: the add
rule refuses a retired emoji with a 422 and the client's optimistic chip reverts. What may be
**added** is that set; what may be **removed** is whatever the member is holding, unchecked —
otherwise narrowing the vocabulary would strand every reaction already written with a retired emoji.

Add and remove are two URLs rather than one toggle, for the reason [mute](#mute) is a state rather
than a flip: a tap that is retried, doubled, or racing the poll has to settle where the member
pointed. Both are idempotent at the row level — the insert leans on the unique key, the delete on a
`WHERE` — and both answer with the message's **whole** chip row, so a reaction someone else added in
the meantime arrives in the same response. Writing is `canPost` (reacting is speaking in the room),
reading the reactor list is `canView`, and a refusal is the usual 404.

That chip row is counted in SQL and never hydrated
([`MessageReactionAggregates`](../../app/Features/GroupTalk/Queries/MessageReactionAggregates.php)):
one grouped read serves a whole page, and a write answers from the same query. The chips are a
handful of numbers, but the rows behind them are one per reactor per emoji — reading them per page
and per poll would cost the size of the room for something the payload never names.

Who reacted is exactly that part, so the names come from `GET .../reactions` when a dialog is opened,
and nowhere else. That read is bounded too
([`MessageReactors`](../../app/Features/GroupTalk/Queries/MessageReactors.php)): an emoji's count is
exact and the first hundred reactors travel with it, in the order they reacted. Past that the dialog
has the number and no more — the list is read by a person.

Nothing notifies. The room's unread badge is what tells a member something happened here, and a
reaction did not happen *to* them in the sense a mention does.

### The version is the second watermark

The poll reads forward from a `(created_at, id)` position, and a reaction moves neither — so a
thumbs-up on a message already on screen is invisible to it. `groups.talk_reaction_seq` issues a
number per change and the touched `group_messages.reactions_version` records it
([`TalkReactionVersion`](../../app/Features/GroupTalk/TalkReactionVersion.php)); the `UPDATE` on the
group row is the serialization point, so a version is unique and monotonic **within the group** by
construction. A timestamp was the obvious alternative and is wrong for the reason the read cursor
does not use one either: at one-second resolution a strict `>` drops everything sharing its second.

`GET .../talk/messages` therefore takes an optional `reactionsAfter`, and answers with two extra
fields when it does — `touched` (whole messages, serialized exactly as a page's are, in version
order) and `reactionsVersion` (the watermark to come back with). Three rules make that safe:

- Only a state change bumps. Re-adding what is already there, or removing what is not, moves nothing
  — a no-op that bumped would wake every open tab in the group to re-read a row that reads the same.
- The watermark is read **before** the page query, never after: a reaction landing between the two
  would otherwise sit below the position the client starts from and never be asked for again.
- A capped page reports the **last row it returned**, not that snapshot. Taking the snapshot with
  rows left behind would step over them; only an exhausted read may move to it.

A client that sends no watermark is answered in the shape it has always had, down to the absence of
both keys — a tab predating this, and the direct-message conversation that shares the poll and has no
reactions. An unparseable value is read as none, as an unparseable cursor is.

Live agreement is the **latest window's** only, like the poll itself: the history window does not
poll, so it catches up when the reader returns to the newest page. And one write is exempt on
purpose — a withdrawing member's rows go by FK cascade, which bumps nothing, so an open tab keeps
showing them until it is reloaded. Advancing every group's counter on a withdrawal is not worth what
it buys.

Reactions are invisible to unread. They create no message row, and no unread read looks at a version,
so cursors, badges and the room list's order cannot move because someone reacted.

### One lock order

A gate is answered before the write it guards runs, and `reactable_id` carries no foreign key — so
nothing at the engine level would refuse a reaction onto a message, or a group, deleted in between,
and what it left would be a row nothing ever collects. Every reaction write therefore takes
[`TalkWriteLock`](../../app/Features/GroupTalk/TalkWriteLock.php) first: the group's row
exclusively, then the message re-read under it, both as locking reads so they see what is committed
rather than the transaction's snapshot. A message that is gone is the talk's usual 404.

The order is the same everywhere, which is what keeps these paths from deadlocking as well as from
racing: the add, the remove, a message's own purge and the group teardown that sweeps a whole
conversation all take the group row before they touch a reaction. Holding it is also what makes the
version's increment the group's serialization point. The single order is a property of the code
rather than something a single-connection test can show; the tests pin the refusal and the rollback.

### Reclaiming the rows

The reverse of the polymorphic column: nothing cascades a reaction away with the thing it is on.

- [`DeleteGroupMessage::purge()`](../../app/Features/GroupTalk/Actions/DeleteGroupMessage.php) —
  beside the image bytes, and for the same reason. Sweep and delete are one transaction under the
  lock above, so a reaction arriving mid-purge cannot outlive the message it is on; only the File
  bytes go after the commit.
- [`DeleteGroup::purge()`](../../app/Features/Group/Actions/DeleteGroup.php) — every reaction in the
  group, inside the same lock transaction as the image sweep.
- A withdrawing member's own, which *is* a cascade: `member_id` is a real foreign key.

## Access

[`GroupTalkAccess`](../../app/Features/GroupTalk/GroupTalkAccess.php), succeeding
`CommunityTimelineAccess`:

- **Read = the group's `topic_read_access`** — the same column the board and events read, so one
  group answers "who may read this" the same way everywhere, and an Everyone group whose timeline
  any member could read does not lose that audience when its history becomes talk.
- **Post = membership**, and `topic_post_authority` is deliberately not consulted: an admins-only
  board must not also silence the group's chat. Open to read, joined to speak.
- **Delete = the author, or anyone who manages the group** (admin or sub-admin) — a linear chat needs
  the moderation reach the boards already give. Deletion is physical.

A refusal is always 404, so whether a group has a conversation is not observable.

### History carries no per-row filter

This is a contract, not an omission. `TimelineFeedScope::applyGroup()` filters a group feed row by
row: the author must still be a member, and must not have blocked the viewer. **Talk applies
neither**, and shows every surviving row.

Two reasons. The precedent for content that belongs to a place is topic and event comments, which
have never filtered on either count. And a conversation with holes in it is not the conversation
that happened — removing one side of an exchange leaves the other side answering nobody.

Blocking keeps working where a block is about people rather than about a room: mention delivery,
mention candidates, and member pages. Talk therefore shows two classes of row the community feed it
replaced used to hide — posts by authors who have since left the group, and posts by authors in a
block relationship with the viewer. That is the contract, not an oversight.

Because a page renders a whole conversation, the per-message questions ("is this mine", "may I delete
it") must not each cost a query:
[`GroupTalkPermissions`](../../app/Features/GroupTalk/GroupTalkPermissions.php) resolves the viewer's
role once per request and the serializer asks it per row.

## Key invariants

1. A message has no audience column; the group is the audience. Any read path that needs to narrow
   further is answering a different question and should say so.
2. `(created_at, id)` is the order, everywhere — reads, cursors, and the unread cursor that follows.
3. `in_reply_to_id` is written only after its target has been checked to be a live message of the
   same group, and no foreign key backs it. So a dangling id means one thing — the answered message
   was deleted — and nothing else may produce one.
4. Every path that creates a membership row snapshots the cursor; the DB defaults are a backstop for
   the ones that cannot, never the initialization.
5. The cursor only ever moves forward, and the guard is in the `WHERE` clause rather than in a
   read-then-write. What it is set to is never a value the client chose — but where a *page* is read
   from always is, and the two must not be confused.
6. The list on screen is one contiguous stretch. A window change replaces it and moves the
   generation on; only a page that follows what is held, and was asked for by the list it is landing
   in, is merged.
7. The unread divider, its jump and the absence digest all come from the render-time snapshot, so
   nothing that happens afterwards — mark-read included — moves any of them.
8. No body is ever parsed for `@`. Two producers write mention rows and no third may appear: the
   composer's picker, and the MCP reply, where the server itself builds the prefix and its range.
   What a producer may name and what the write will accept are the same set, by construction.
9. A mention pierces mute; the per-message broadcast does not, and a block stops both. Talk history
   does neither, and neither do its images. At most one broadcast row per room stands at a time, and
   reading the room reads it.
10. `PostImages::attach()` is the outermost transaction of the write; nothing wraps it.
11. Talk is the group's conversation surface; nothing else scopes posts to a group. A second one
   would be re-creating the split the cutover removed.
12. The room list's order is settled in SQL before the page is cut. A query that pages first and
    then asks for the newest messages has ordered the page, not the membership.
13. A link may name a message (`?m=`), and naming one is neither a permission nor a reading: the
    group's gate still answers first, an unusable id falls back to the newest page, and the cursor
    only moves from the foot of the live window.
14. A reaction is not a message. It writes no `group_messages` row and no unread read looks at a
    version, so cursors, badges and the room list's order cannot move because of one — and nothing
    notifies, since the room's badge is what says something happened here.
15. `reactable_type` is written through the model's morph alias, never as a literal — the OpenPNE 3
    `nice` transfer included, when it arrives.
16. At most one row per (content, member, emoji), so a member may hold several emoji on one message.
    Narrowing that to one is lossy, which is why the wide key is a decision rather than a default.
17. `ReactionVocabulary` is the only place the set is written down, its size included. It bounds what
    may be added; what may be removed is whatever the member holds, and the column takes any short
    utf8mb4 string.
18. Every state change through the app bumps the version and a no-op does not, so a watermark only
    moves for something worth re-reading. The withdrawal cascade is the one exempt write; the client
    merges a touched row only over an id it already holds.
19. Reading a message's reactions is `canView` and writing one is `canPost`, with no per-row filter
    either way and 404 for every refusal — the conversation's own rules.
20. Three paths take reactions away: the message's delete, the group's teardown, and the member's
    withdrawal. Only the last is a cascade, because `reactable_id` carries no foreign key.
21. Live agreement is the latest window's alone. A history window catches up on return, and a poll
    that carries no watermark is answered as one that never asked.
22. Every write that touches a reaction takes the group row's exclusive lock first and re-reads the
    message under it — the one order, shared with the message's purge and the group's teardown.
23. Nothing about a chip row grows with the room: the counts are aggregated in SQL rather than
    hydrated, and the reactor list ships an exact count with at most a hundred names.
24. A reply is an ordinary message. It orders, counts as unread and leads a room like any other, and
    the reference it carries adds no notification of its own.
25. The parent lookup is bound to the group, so an id from another conversation reads as deleted, and
    only one level is ever serialized.
26. A message's link card is attached once and never invalidated, because a message is never edited.
    The conversation page is what asks for one on read; the rows it decorates itself with are not
    asked about.
