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
| `group_message_images` | join rows to `files`, shaped exactly like `timeline_post_images` |
| `group_message_mentions` | code-point ranges, shaped exactly like `timeline_post_mentions` |
| `group_members.talk_read_at` / `.talk_read_message_id` / `.is_talk_muted` | the read cursor and the per-group mute |

Only `group_messages` has app code behind it in this pass; the image and mention tables land now so
the transfer that brings the old timeline across has somewhere to put what it carries.

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
4. Talk stays switched off until the cutover; a change that makes it reachable by default is a change
   to that plan, not a bug fix.
