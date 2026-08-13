# Group talk

A group's **talk** is one linear conversation: `group_messages` rows ordered oldest to newest, a
plain body of at most 5,000 code points, no editing, no threading. It is the successor of the group
timeline, and the structural difference from it is that a message carries **no audience of its own**.
A timeline post holds a `visibility`; a talk message holds nothing, because the group already
answers who may read it.

Modern only — talk has no OpenPNE 3 counterpart, so there is no Classic screen to be compatible
with. [`GroupTalkController`](../../app/Features/GroupTalk/GroupTalkController.php) renders Inertia
directly, as `/groups/recent` does.

## It ships switched off

`groupTalk` is the one [feature unit](feature-toggles.md) that is dark until an operator asks for
it, and it takes **two** declarations in [`SnsSettingKey`](../../app/Support/SnsSettingKey.php) to
say so:

- `default()` answers **`false`** for `feature_group_talk_enabled`. This is what an absent row
  resolves to — `decode()` returns the default for a null value *before* it reaches any arm — and an
  absent row is the state every install starts in.
- the `decode()` arm for the key is **fail-closed** (`$value === '1'`), so a stored blank or garbled
  value reads as off too, where every other unit reads anything but `'0'` as on.

Every other unit reads absent as enabled. With that reading talk would have gone live on every site
the moment its schema landed, next to the group timeline it is meant to replace, with the same
conversations in two places.

Until the cutover deploy (which stops the old writes and moves the history across) an operator
reaches talk by switching it on per site from admin → Settings → Features. Its routes 404 and its
entrance stays off the group page until they do.

The cutover is a pure code change, and it is **both** halves or neither:

1. `default()` → `true`. This is the half that actually flips the sites in question, since a site
   that never opened the Features page has no row at all.
2. the `decode()` arm → the fail-open family, so a stored value reads like every other unit's.

Doing only (2) would change nothing for those sites: `decode()` never reaches the arm for an absent
row. A stored `'0'` or `'1'` is an operator's explicit choice and keeps meaning what it says either
way.

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

Sending advances the cursor to the new row's tuple **inside the insert's transaction**: writing is
reading, and a cursor left behind your own message would show your own words arriving as unread.

### Two different numbers

| where | what it counts |
|---|---|
| group list, per row ([`UnreadTalkCounts`](../../app/Features/GroupTalk/Queries/UnreadTalkCounts.php)) | unread **messages** in that group, one query for every membership |
| nav badge, `groupTalks` ([`CountGroupsWithUnreadTalk`](../../app/Features/GroupTalk/Queries/CountGroupsWithUnreadTalk.php)) | **groups** with anything unread, muted ones excluded |

The badge counts rooms rather than messages because a message count is dominated by whichever group
is busiest, which says nothing about where to go next. It joins the shared props through
`App\Features\Home\UnreadCounts` and reports zero, unqueried, while the unit is off — like every
other badge there.

Both read the same predicate ([`UnreadTalkScope`](../../app/Features/GroupTalk/UnreadTalkScope.php)):
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
mention candidates, and member pages. The cutover therefore makes two classes of row visible that the
old feed hid — posts by authors who have since left the group, and posts by authors in a block
relationship with the viewer — which is a migration note, not a regression.

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
   read-then-write.
6. A mention row exists only where the picker produced one; no body is ever parsed for `@`. What the
   picker may offer and what the write will accept are the same set, by construction.
7. A mention pierces mute; a block stops it. Talk history does neither, and neither do its images.
8. `PostImages::attach()` is the outermost transaction of the write; nothing wraps it.
9. Talk stays switched off until the cutover; a change that makes it reachable by default is a change
   to that plan, not a bug fix.
