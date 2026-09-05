# Group boards

A group's **board** is `group_topics` and `group_events`: a titled thread with a body and a comment
list, ordered by activity rather than by post date. The two are a deliberate parallel hierarchy —
OpenPNE 3's `communityTopic` and `communityEvent` plugins, ported as two feature modules whose
shared shapes are pinned by tests rather than by a common base class.

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
seat, all under that lock. [`AddAllMembers`](../../app/Features/Group/Actions/AddAllMembers.php) is
outside the protocol on purpose: it only inserts plain Member rows and touches neither a role nor
`pending_admin_member_id`.

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

[`DeleteGroup::purge()`](../../app/Features/Group/Actions/DeleteGroup.php) reclaims bytes no
cascade touches, across four kinds of content, without racing the one of them that is written
concurrently by design.

1. Each topic and event is deleted through its own `purge()` first, so each collects and purges its
   own and its comments' image bytes.
2. The group row is then X-locked. Under that lock the talk messages' image Files are collected and
   the messages' `reactions` rows deleted (`reactable_id` is polymorphic and carries no foreign
   key), and the group's own top-image File is read — `groups.file_id` is a mutable self-column, so
   a stale read would miss an edit that just replaced the image and orphan the new File.
3. The group is deleted, the cascade taking memberships, join requests, messages and every
   `*_image` link row with it.
4. Only after that transaction commits are the collected Files deleted, which is what purges the
   bytes.

The talk arm sits inside the lock while the topic and event sweeps do not, because talk is written
concurrently: a message committed after an earlier enumeration would slip past it, its join row
cascading away while the File row and its bytes stayed. The X-lock on the parent closes that window
— a new message's FK check takes a shared lock on the group row and waits. The order is the one
every reaction write takes ([group-talk.md](group-talk.md), "One lock order"), so a teardown and a
reaction queue rather than deadlock.

## Comment threads page by id

[`GroupTopicCommentThread`](../../app/Features/GroupTopic/GroupTopicCommentThread.php) and
[`GroupEventCommentThread`](../../app/Features/GroupEvent/GroupEventCommentThread.php) port
OpenPNE 3's `sfReversibleDoctrinePager`. Comments page at a fixed size of 20 (OpenPNE 3 offers no
size switch) with a reversible order: the default DESC fetches the newest page first but always
lists a page oldest-first, and `order=asc` walks from the first comment. "Older" and "Newer" follow
comment age, not page index.

Ordering is by `id` (OpenPNE 3 `setSqlOrderColumn('id')`), never by `number`. `number` is a racy
max+1 label that migrated data may carry out of order or duplicated, so paging by it would drift
the page boundaries away from OpenPNE 3's; `id` is the monotonic insertion order. Modern reuses the
same pager rather than shaping its own: the two surfaces must list a thread identically, and
neither may serialize an unbounded thread in one response.
