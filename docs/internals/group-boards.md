# Group boards

A group's **board** is `group_topics` and `group_events`: a titled thread with a body and a comment
list, ordered by `bumped_at` — the last comment's time — rather than by post date. The two are a
deliberate parallel hierarchy — OpenPNE 3's `communityTopic` and `communityEvent` modules of
`opCommunityTopicPlugin`, ported as two feature modules whose shared shapes are pinned by tests
rather than by a common base class.

Talk is the third thing inside a group and has its own document
([group-talk.md](group-talk.md)); what all three share is the group's two access columns
`topic_read_access` and `topic_post_authority`, described there under "Access".

## The group row is the lock

Every action that changes a member's role or the pending-admin nominee opens a `DB::transaction`,
re-reads the group row with `->lockForUpdate()`, and runs **all** of its role guards against that
locked re-read — never against a role snapshot taken before the lock. The group row is the single
serialization point, so concurrent appoint / demote / drop / transfer / quit and admin withdrawal
cannot interleave: only one holds the lock at a time, and each sees the others' committed effects.
That is what keeps "exactly one admin per group" true when two members accept a transfer at once,
or a transfer is accepted while the old admin withdraws.

[`AcceptAdminTransfer`](../../app/Features/Group/Actions/AcceptAdminTransfer.php) promotes the
nominee from Member or Sub-admin, demotes the incumbent admin to Member and clears the pending
seat, all under that lock. The writes on a live group that stay outside the protocol do so on
purpose:
[`AddAllMembers`](../../app/Features/Group/Actions/AddAllMembers.php) and
[`JoinGroup`](../../app/Features/Group/Actions/JoinGroup.php) only insert plain Member rows and
touch neither a role nor `pending_admin_member_id`, and
[`RejectAdminTransfer`](../../app/Features/Group/Actions/RejectAdminTransfer.php) clears the seat
with a single conditional `UPDATE`, which is its own compare-and-set (zero rows changed means the
seat was not the actor's), so it takes no lock.

Two edges are accepted rather than closed, and OpenPNE 3 behaves the same way:

- A transfer pending across the old admin's withdrawal survives it.
  [`WithdrawMember`](../../app/Features/Member/Actions/WithdrawMember.php) auto-promotes the
  longest-tenured member, and a later accept then demotes that successor — the nominee wins.
- An ex-admin's in-flight `DeleteGroup` can complete after a transfer, because its irreversible byte
  purge runs outside this lock. The harm equals a delete done a moment before the transfer, so the
  purge is not folded into the lock.

Being the nominee is state, not a role, so accept and reject carry no policy ability and the
action's `NoTransferPending` check is authoritative. A nominee's role is frozen while the transfer
stands: `AppointSubAdmin` refuses a nominee target, as OpenPNE 3 refused an `admin_confirm` holder.
Demoting a nominee who is already a sub-admin is still allowed — a nominee need only be a non-admin
member — and a new request silently replaces a different pending nominee.

## Tearing a group down

[`DeleteGroup::purge()`](../../app/Features/Group/Actions/DeleteGroup.php) reclaims what no cascade
touches — File bytes and reactions — across four kinds of content, in one transaction:

1. The group row is X-locked, then every topic and event row under it (teardowns of one group are
   already serialised by its row, so no order among them is needed).
2. Under those locks the image Files of the talk, the topics, the events and their comments are
   collected and the reactions on the talk messages, the topics, the events and the board comments are deleted
   (`reactable_id` is polymorphic and carries no foreign key). Every row is reached from the group
   id: the comments and messages by subquery, the topics and events by a keyset over their ids. The
   reactions are found a page at a time — a thousand of one topic's or event's comments, a thousand
   messages in the room index's own order, or a thousand of the group's topics or events — and then a thousand of their
   reactions, and deleted by primary key in chunks: PHP holds a page, no statement grows with the
   group, no page sorts more than its thousand parents' reactions, and the sweep locks only the rows it deletes. The group's own top-image
   File id is read — `groups.file_id` is a mutable self-column, so a stale
   read would miss an edit that just replaced the image and orphan the new File.
3. The group is deleted, the cascade taking memberships, join requests, messages, topics, events,
   comments and every `*_image` link row with it.
4. Only after that transaction commits are the collected Files deleted, which is what purges the
   bytes.

The group row alone does not stabilise the boards: a talk writer takes the group row
([group-talk.md](group-talk.md), "One lock order"), and a new topic or event waits on it too through
its foreign key, but a comment or reaction writer takes the topic or event row and never the
group's, so a reaction arriving between the sweep and the cascade would take a free topic and
outlive its comment, or the topic itself. Holding every topic and event exclusively is what makes
such a writer wait and then find its target gone. A single topic or event goes the same way on its own
(`DeleteTopic::purge`, `DeleteEvent::purge`): its row X-locked, its comments read under it, their
reactions swept, its own with them, its Files purged after the commit.

## Comment threads page by id

[`GroupTopicCommentThread`](../../app/Features/GroupTopic/GroupTopicCommentThread.php) and
[`GroupEventCommentThread`](../../app/Features/GroupEvent/GroupEventCommentThread.php) port
OpenPNE 3's `sfReversibleDoctrinePager`. Comments page at a fixed size of 20 (OpenPNE 3 offers no
size switch) with a reversible order: the default DESC fetches the newest page first but always
lists a page oldest-first, and `order=asc` walks from the first comment. "Older" and "Newer" follow
comment age, not page index.

Ordering is by `id` (OpenPNE 3 `setSqlOrderColumn('id')`), never by `number`; the diary thread
pages by `number`, for the reasons recorded in
[diary.md](diary.md#the-thread-pages-by-number). `number` is a racy
max+1 label that migrated data may carry out of order or duplicated, so paging by it would drift
the page boundaries away from OpenPNE 3's; `id` is the monotonic insertion order. Modern reuses the
same pager rather than shaping its own: the two surfaces must list a thread identically, and
neither may serialize an unbounded thread in one response.

## The board key is bumped_at

A thread's `bumped_at` always equals `COALESCE(MAX(comments.created_at), created_at)`: it starts at
creation, a comment lifts it, and deleting a comment settles it back to the last surviving one — a
departure from OpenPNE 3, which left the stamp where the deleted comment put it. Nothing else moves
it: not a name or body edit (that sets `edited_at`, which the detail serializer exposes as
`editedAt`), not an RSVP, not a link-card sync; an administrator deleting a comment settles it like
anyone else. `updated_at` is Laravel's and means nothing to the board, which is why
[`BoardBumpedAt`](../../app/Features/Group/BoardBumpedAt.php) writes through the query builder: a
model save, even a quiet one, bumps `updated_at`. The column has no default, so a write path that
forgets it fails rather than storing the engine's clock. Deleting a comment locks its thread first,
in the order a new comment takes it; a thread deleted meanwhile ends the request with "not found",
its comments already gone with it.

A Modern list row shows `bumped_at` under the label its count decides, "Last comment" with comments
and "Posted" without, since after the last comment goes the two are the same instant; the Modern
detail shows `created_at` with an "Edited" mark when `edited_at` is set. The two screens name
different instants on purpose, and the labels are what keep them from reading as one; the mark
follows `edited_at`, so a name or body change earns it and an image swap alone does not. Classic
draws the bare datetimes OpenPNE 3 drew.

The lists order by `(bumped_at, id)` ([ordering.md](ordering.md#axes)), on `(group_id, bumped_at)`
within a group and `(bumped_at, id)` across the site. OpenPNE 3 ordered its board by `updated_at`,
which its cascade-save moved on every comment and every edit; its `topic_updated_at` /
`event_updated_at` moved on the same two events and are not carried. The upgrade writes `created_at`
as a placeholder and a post-walk pass settles the definition once the comments exist
([upgrade.md](upgrade.md#post-walk-passes)); the verifier recomputes it rather than trust the pass.

The migration that introduced the column backfills it from the comments and must run with no old
code writing: the old comment path bumped `updated_at` only, and the new one needs the column.

## Reactions

A topic, an event and each of their comments take the emoji reactions of [reactions.md](reactions.md),
on `group.topics.reactions.*` / `group.topics.comment.reactions.*` and the event pair
([`GroupTopicReactionController`](../../app/Features/GroupTopic/GroupTopicReactionController.php),
[`GroupEventReactionController`](../../app/Features/GroupEvent/GroupEventReactionController.php)).
Two things are the boards' own:

- **Reacting is the group's write permission** (`canComment`: membership), as commenting is; the
  reactor list is the board's read permission on the route, though the page offers it to members
  only. A non-member reading an open board sees the chips as counts, with no way to change them.
- **The lock is the topic or event row**
  ([`BoardLock`](../../app/Features/Group/BoardLock.php)): the parent exclusively, then the comment
  re-read under it when the target is one, the order a comment delete already took. A withdrawing
  member's topics, events and comments stay with a null author, and so do the reactions on them;
  the group teardown above is what sweeps a board.

## Key invariants

1. A board reaction is gated by the group's membership and locked at its topic or event: the
   parent row, then the comment row when the target is a comment.
2. A comment's delete, a topic's or event's delete and the group teardown take the parent rows
   before they sweep reactions, inside the transaction that deletes the rows; File bytes are purged
   after it. The teardown holds every topic and event, since a board writer never takes the group
   row.
