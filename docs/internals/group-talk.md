# Group talk

A group's **talk** is one linear conversation: `group_messages` rows ordered oldest to newest, a
plain body of at most 5,000 code points, no editing, no threading. It is the successor of the group
timeline, and the structural difference from it is that a message carries **no audience of its own**.
A timeline post holds a `visibility`; a talk message holds nothing, because the group already
answers who may read it.

Modern only — talk has no OpenPNE 3 counterpart, so there is no Classic screen to be compatible
with. [`GroupTalkController`](../../app/Features/GroupTalk/GroupTalkController.php) renders Inertia
directly, as `/groups/recent` does.

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
| `group_messages` | `group_id`, `member_id` (nullable), `in_reply_to_id` (nullable), `body`, timestamps |
| `group_message_images` | join rows to `files`, numbered slots, shaped exactly like `timeline_post_images` — see [Images](#images) |
| `group_message_mentions` | code-point ranges, shaped exactly like `timeline_post_mentions` |
| `group_members.talk_read_at` / `.talk_read_message_id` / `.is_talk_muted` | the read cursor (a copied `(created_at, id)` tuple, no FK) and the per-group mute — see [Unread](#unread) |

`group_message_mentions` and `group_message_images` are both written by the composer — see
[Mentions](#mentions) and [Images](#images).

**`member_id` is `nullOnDelete`.** A withdrawn author leaves the message in place and reads as
`Withdrawn member`, matching the other group tables (`group_topics`, `group_events` and their
comments). The repository splits this by axis rather than by table: personal content (diaries,
timeline posts) goes with its author, content that belongs to a place stays in the place. A
`group_message_mentions` row is the opposite — `cascadeOnDelete`, so deleting the member takes the
mention away and the range renders as the plain text it already is.

**`in_reply_to_id` is lineage, never a feature.** Talk has no reply UI and the composer never writes
it; it exists so a migrated OpenPNE 3 activity reply or OpenPNE 4 timeline reply can record what it
pointed at. Its on-delete is `nullOnDelete`, deliberately unlike `timeline_posts`' cascade: deleting
a message must not take unrelated messages with it, because lineage records where something came
from, not whose life it shares.

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
have.

The client keeps the same order. A send and a poll are in flight together by design and complete in
whatever order the network gives them, so
[`talk-stream-state.ts`](../../resources/js/pages/group/talk/talk-stream-state.ts) re-sorts by the
tuple on **every** merge rather than trusting arrival order — appending as responses land would put
the list out of order permanently, and the poll's next watermark would then be read off a row that
is not the newest. A locally deleted id is a **session-lifetime tombstone** for the same reason: a
poll already in flight answers from a snapshot that still holds the row, and nothing afterwards
would remove it again.

### Two windows, never mixed

The list on screen is either of two stretches, and `talk-stream-state.ts` names which:

| window | what it is | poll |
|---|---|---|
| `latest` | ends at the newest message | yes |
| `history` | the slice the unread jump opened on, somewhere behind it | no |

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

### `?context=` — the page a position sits in

`GroupTalkMessages::around()` returns `CONTEXT` messages up to and including the cursor, then
everything after it up to `PER_PAGE`, as one contiguous page for the history window. It is
deliberately general: the unread boundary is only the first thing worth opening on, and a link to a
single message wants the same window around a message's own cursor.

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
[Mentions](#mentions) below.

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
A page therefore costs the same whether it holds one room or twenty.

The Modern dashboard leads with the same rooms — the first five, and the same row component, so what
the digest says and what the list says cannot drift. It reads them through `take()` rather than the
paginator: a screen with no pager has no total to count and no page number to honour.

## Mentions

Talk parses `@mentions` and nothing else: no hashtags (a chat has no tag culture, and a per-group tag
index would be a screen nobody asked for) and no link cards. URLs are linkified at render, as
everywhere.

The mechanics are the timeline's, reused rather than re-implemented — the range storage, the resolver
and the composer state machine are all shared code. [timeline.md](timeline.md#a-mention-is-a-range-not-text)
is the reference for how a range works, how offsets are counted in code points, and why the picker's
selection is the only thing that ever becomes a mention. What follows is only what talk does
differently.

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

**No per-message permalink.** A mention notification links to the conversation
(`/groups/{group}/talk`), because that is the only screen talk has. The feed re-checks at click time
that the message still exists and that the reader may still read the group, and resolves to nothing
when either has changed.

### The one notification talk sends

`group_talk_mention` (OpenPNE-4-native, no OpenPNE 3 ancestor) under a new `GroupTalk` category, with
a configurable `group-talk-mention` mail template. There is deliberately **no per-message broadcast**:
a chat that notified on every line would empty the feed of meaning, so the room's unread badge carries
that job instead.

[`GroupTalkNotificationEligibility`](../../app/Features/GroupTalk/GroupTalkNotificationEligibility.php)
answers who may receive one: a current group member, not banned, still able to read the group, with no
block in either direction with the author, and never the author. It is asked **twice** — when the
listener enqueues and again in `shouldSend()` immediately before each channel — because a mention mail
carries the message body and a queued job can outlive the facts it was enqueued under
([notifications.md](notifications.md#delivery-time-re-checks)).

Two asymmetries are deliberate:

- **Mute does not gate a mention.** `is_talk_muted` silences the room's badge; being named is
  addressed to one person and outranks having asked the room for quiet. A member who wants no mention
  mail turns the catalog kind off, which is a different question and is honoured.
- **Blocking does gate it**, though talk history does not filter by block at all. History is the
  record of what was said; a notification is putting two people in front of each other, which is
  exactly what a block refuses.

An author who withdraws before delivery takes the queued notification with them
(`deleteWhenMissingModels`): "Withdrawn member mentioned you" is a notification nobody can act on.

## Images

One image per message. The schema numbers slots (`number` 1..N) because the transfer that brings the
old community timeline across may carry content with several, but the composer offers one — the same
cap the timeline has, fixed for the MVP.

The write goes through [`PostImages`](../../app/Files/PostImages.php), and **its `attach()` owns the
outermost transaction**. This is not incidental. A byte write is not transactional: `compensating()`
tracks every File it stored and deletes those bytes when the transaction throws. Wrapping another
`DB::transaction` around it would let the inner one roll back and commit while the bytes stayed on
disk, and the compensation would never run — which is the mistake the event-comment flow had to
merge its way out of. So `CreateGroupMessage` puts everything else — the message insert, mention
resolution, the cursor advance, the posted event — inside `attach`'s persist callback rather than
around it.

Validation reuses [`PostImageRules`](../../app/Http/Requests/Concerns/PostImageRules.php), so a
refused file is a 422 on the `image` field and the composer keeps the whole draft: body, mention
rows and the picked file. Nothing is cleared until the message is actually written.

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

## Access

[`GroupTalkAccess`](../../app/Features/GroupTalk/GroupTalkAccess.php), succeeding
`CommunityTimelineAccess`:

- **Read = the group's `topic_read_access`** — the same column the board and events read, so one
  group answers "who may read this" the same way everywhere, and an Everyone group whose timeline
  any member could read does not lose that audience when its history becomes talk.
- **Post = membership**, and `topic_post_authority` is deliberately not consulted: an admins-only
  board must not also silence the group's chat. Read-public and write-members is the Slack public
  channel shape.
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
3. The composer never writes `in_reply_to_id`.
4. Every path that creates a membership row snapshots the cursor; the DB defaults are a backstop for
   the ones that cannot, never the initialization.
5. The cursor only ever moves forward, and the guard is in the `WHERE` clause rather than in a
   read-then-write. What it is set to is never a value the client chose — but where a *page* is read
   from always is, and the two must not be confused.
6. The list on screen is one contiguous stretch. A window change replaces it and moves the
   generation on; only a page that follows what is held, and was asked for by the list it is landing
   in, is merged.
7. The unread divider and its jump both come from the render-time snapshot, so nothing that happens
   afterwards — mark-read included — moves either.
8. A mention row exists only where the picker produced one; no body is ever parsed for `@`. What the
   picker may offer and what the write will accept are the same set, by construction.
9. A mention pierces mute; a block stops it. Talk history does neither, and neither do its images.
10. `PostImages::attach()` is the outermost transaction of the write; nothing wraps it.
11. Talk is the group's conversation surface; nothing else scopes posts to a group. A second one
   would be re-creating the split the cutover removed.
10. The room list's order is settled in SQL before the page is cut. A query that pages first and
    then asks for the newest messages has ordered the page, not the membership.
