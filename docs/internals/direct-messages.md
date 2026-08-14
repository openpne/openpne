# Direct messages

A direct message is stored as OpenPNE 3 stored it: the authored row in `direct_messages`, one receipt
per recipient in `direct_message_recipients`, and each side's read and trash state on its own side's
columns. The mailbox screens (`/message/*`) read it as four boxes, and are what Classic renders.

Chat is a **second reading of the same rows** — no new table, no new column. A conversation is the
pair *viewer ⟷ counterpart*, and both directions of it are composed back out of the storage on every
read: `/messages` lists them, `/messages/{member}` renders one, and `/messages/{member}/messages`
serves the pages after the first. Under Modern this reading replaces the boxes outright —
[Modern reads the store as chat](#modern-reads-the-store-as-chat) — while the trash and the draft
form stay the mailbox's, and a message written on either surface appears on the other because there
is only one store.

## A conversation is two arms over one table

[`ConversationScope`](../../app/Features/DirectMessage/ConversationScope.php) is the only place that
says what a conversation contains:

| arm | the row | the receipt |
|---|---|---|
| sent | `sender_id` = viewer, not a draft, neither `sender_deleted_at` nor `sender_purged_at` set | one exists for the counterpart |
| received | `sender_id` = counterpart, not a draft | one exists for the viewer, neither `recipient_deleted_at` nor `recipient_purged_at` set |

Both arms select from `direct_messages` and test the receipts with `EXISTS`, rather than unioning two
derived tables in the `FROM` clause: the composed set has to be ordered and sliced by keyset, and a
`UNION` subquery cannot be indexed for that on SQLite and MySQL alike.

**Per-side visibility is the only filter.** The sent arm reads none of the recipient's columns and the
received arm none of the sender's, so trashing your copy takes it out of your conversation and leaves
theirs whole. Nothing else narrows a row — in particular **a block does not**, for the reason talk's
history does not filter either ([group-talk.md](group-talk.md#history-carries-no-per-row-filter)): a
conversation with holes in it is not the conversation that happened. What a block stops is putting two
people in front of each other, which is `DirectMessageAccess::canSend`'s job.

**A null counterpart is the withdrawn bucket.** Both member FKs are `nullOnDelete`, so a departed
member leaves no id to key a conversation by; every one of them collapses into the single conversation
at `/messages/withdrawn`, and each arm's comparison switches to `IS NULL` rather than binding a null
that would make every row UNKNOWN.

## Ordering and paging

`(created_at, id)`, keyset, opaque `{iso8601}|{id}` cursors, capped pages, a poll for what has
arrived: exactly the design [group-talk.md](group-talk.md#ordering-is-the-created_at-id-tuple)
describes, down to the expanded tuple comparison SQLite forces and the `latest` / `history` windows
the client keeps.

The code is **not** shared, and that is deliberate — see [Separate from group talk](#separate-from-group-talk).
[`ConversationMessages`](../../app/Features/DirectMessage/Queries/ConversationMessages.php) and
[`ConversationCursor`](../../app/Features/DirectMessage/ConversationCursor.php) are the direct-message
side of it. A `?m=` link is best-effort in the same way: honoured only for a message this conversation
can see, and any other id opens the newest page with no anchor.

## The conversation list

`/messages` is the room list of the same correspondence: one row per counterpart, most recently
written in first, each carrying what it leads with and the viewer's unread.
[`ConversationList`](../../app/Features/DirectMessage/Queries/ConversationList.php) follows the rule
[group-talk.md](group-talk.md#the-joined-group-list-is-a-room-list) sets out — **the order is decided
in SQL, before the page is cut** — for the same reason and by the same means: two correlated
subselects for the newest `(created_at, id)`, since the tuple cannot be read in one statement without
a row constructor or a lateral join, then one lookup by key for the bodies the ordering has already
named.

The counterparts themselves are a `UNION` of the two arms in the `FROM` clause. That is what
`ConversationScope` refuses for *reading* a conversation, and it is right here because this set is
never ordered or sliced — it is deduplicated and nothing else. Deduplication is also what collapses
the withdrawn bucket: `UNION` treats NULL as equal to NULL, so every departed member arrives as the
single row they are read as.

What each row leads with is `ConversationScope`'s own two arms, correlated to the row's counterpart
instead of a bound member — a shape the scope's Eloquent builder cannot be reused in, and where the
counterpart comparison has to be written out as `= it OR (both IS NULL)` (MySQL's `<=>` is not SQL
SQLite speaks). So the two readings are held together by test rather than by shared code:
`ConversationListBeltTest` seeds one matrix and asserts that every row says what opening that
conversation says, and that the rows are exactly the conversations with anything in them.

**Drafts ride under the list.** A draft has no receipt, so it is in neither arm of any conversation
and has nowhere else to be found; the mailbox's drafts box is a section of this screen, paged by its
own `draft_page` parameter so moving one list never moves the other.

## What the mailbox holds and a conversation has to place

Nothing about the storage is dropped or rewritten to make it read as chat, so every field the mailbox
has needs an answer here.

| what | how the conversation reads it |
|---|---|
| `subject` | shown above the body when it is non-empty. Null and the empty string alike draw nothing — the storage keeps that distinction, the screen has nothing to show for either. A message written as chat has none. |
| null `body` | serialized as the empty string; a subject-only message is still a message. |
| draft | never in a conversation: a draft has no receipt, so neither arm reaches it — and the scope still states `is_draft = false` as a belt against a stray receipt. It stays the drafts box's. |
| `sender_deleted_at` / `recipient_deleted_at` (trash) | hidden from that side's conversation only. Restoring is the mailbox's trash screen. |
| `sender_purged_at` / `recipient_purged_at` | the same, permanently — the row survives for the other side. |
| `parent_id` / `thread_id` | lineage the reply flow writes; a conversation is linear and neither reads nor writes it. |
| withdrawn member | the bucket above, with `author` serialized as null and drawn as "Withdrawn member". |
| several recipients (upgraded OpenPNE 3 sends) | the row appears in each recipient's conversation, and `read` is **that** conversation's receipt. |

`read` is the sender's own view of delivery: true once the counterpart's receipt carries a `read_at`.
A received message reports null — reading it is what the reader is doing — and so does anything in the
withdrawn bucket, where no receipt names a member.

## Writing

`POST /messages/{member}` writes through
[`SendDirectMessage`](../../app/Features/DirectMessage/Actions/SendDirectMessage.php) — the mailbox's
own send, not a second write path: one authored row, one receipt, the attachments inside
`PostImages::attach()`'s transaction, and the recipient notified after it commits. What a chat send
fixes are the fields a conversation has no place for — **no subject, no `parent_id`/`thread_id`** — so
the row it writes is exactly the one the table above reads back.

Who may write is [`DirectMessageAccess::canSend`](../../app/Features/DirectMessage/DirectMessageAccess.php),
which `show` ships as `canSend`: a refused pair gets **no composer** rather than a disabled one, and
the withdrawn bucket has no store route at all, naming no member to deliver to. A refusal that lands
anyway — the block arrived while the screen was open — is a 422 on `body`, so the composer keeps the
whole draft, body and picked files alike.

[`StoreChatMessageRequest`](../../app/Http/Requests/DirectMessage/StoreChatMessageRequest.php) caps the
body at 5,000 code points and normalizes CRLF to LF before measuring it, exactly as talk's bar does.
**The cap is this screen's alone**: the mailbox's compose and draft forms are OpenPNE 3 screens with
no length limit and keep it. The reply is the message in the paging endpoint's own shape, so the
composer appends what it wrote instead of re-reading the page.

The notification the send raises re-runs its eligibility immediately before each channel delivers
([notifications.md](notifications.md#delivery-time-re-checks)): its mail carries the body, and a queued
job can outlive a ban, a block, or the recipient purging their receipt (trash does not revoke reading,
so a trashed message still arrives). The URL that mail carries is unchanged — the mailbox's
`/message/read/{id}`, durable and already in members' mail — and under Modern it now arrives in the
conversation, on that message ([Modern reads the store as chat](#modern-reads-the-store-as-chat)).

## Unread

There is no read cursor. A conversation is composed at read time out of rows the mailbox owns, and
the only read state in the store is `read_at` on each receipt — which the mailbox sets one message at
a time, from any box, in any order. So the boundary is **the first unread message**, not a
read-through position: an older message left unread under newer read ones is an ordinary state here,
and a "you have read up to here" line would be a lie the moment it met one of those holes.

Unread means the **received** arm and nothing else: a message written by the counterpart, visible in
this conversation, whose receipt the viewer has not opened. The viewer's own messages carry no unread
state — `read` on them is the counterpart's delivery, not the viewer's reading — and a receipt the
viewer has trashed is not on the screen to be read either.

[`ConversationUnreadSnapshot`](../../app/Features/DirectMessage/Queries/ConversationUnreadSnapshot.php)
answers the count and that first message, or null when nothing is waiting, and `show` ships it as
`unreadSnapshot`. It is **fixed for the visit**, for the reason
[group-talk.md](group-talk.md#the-divider-is-a-snapshot-and-the-banner-is-what-it-cannot-draw)
sets out, and the page draws the same two things from it: the divider, and the "N unread" banner for
when the boundary lies further back than the loaded page reaches. `?m=` is independent — a link names
a message, the boundary names what has not been read — so a deep link changes the slice and not the
line.

| field | what reads it |
|---|---|
| `firstUnread` (`{at, id}`) | the divider, which goes above **this** row rather than the one after it (talk's `readThrough` is the other way round — [`lib/chat/unread.ts`](../../resources/js/lib/chat/unread.ts) takes the two as one discriminated boundary) |
| `cursor` (opaque) | the jump — handed straight back as `?context=`, which opens the position with its context above it |

`firstUnread.at` goes out through `ConversationMessageSerializer::instant()`, the same conversion a
message's `createdAt` takes, because the client compares them directly.

### Mark-read

`POST /messages/{member}/read` (and `/messages/withdrawn/read`) takes the id of the last message the
client **rendered**, and the client reports only while it is visible and at the foot of the live
window. Three rules:

- the named row is resolved through `ConversationScope`, so it may sit in **either** arm — the foot
  of a conversation is often the reader's own message — and anything this conversation cannot see
  (another conversation's, a draft, one the viewer has trashed) resolves to no position at all: 404.
- what moves is a set: every **live** receipt of the viewer's, on a received row of this
  conversation, at or before that position. One statement, so a page's worth of backlog is one write.
- monotonic by construction rather than by a guard, since `read_at` only ever goes from null to a
  time: replaying an older report marks nothing, and two tabs reporting out of order cannot walk the
  boundary backwards.

A trashed receipt is deliberately left alone. It is not on the chat screen, so nobody has read it,
and restoring it from the mailbox's trash has to hand back a message that has never been opened.

An accepted report asks the shell to re-read its badge counts
([`lib/unread-refresh.ts`](../../resources/js/lib/unread-refresh.ts)).

### Two counts, two questions

The Modern badge is
[`CountUnreadConversations`](../../app/Features/DirectMessage/Queries/CountUnreadConversations.php):
**how many conversations have something new**, not how many messages — a message count is dominated
by whoever wrote most, which says nothing about where to go next, and it is the reading the group
badge already takes ([group-talk.md](group-talk.md#two-different-numbers)). `COUNT(DISTINCT sender_id)` skips the null
sender, so the withdrawn bucket is added back as the single conversation it is read as.

The Classic home caution keeps
[`CountUnreadDirectMessages`](../../app/Features/DirectMessage/Queries/CountUnreadDirectMessages.php),
the inbox's own message count: it links into the mailbox, where the number it announces is the rows
waiting there. Same receipts, different question — which is why the two are separate queries reaching
the screen as separate props rather than one number two screens disagree about.

## Modern reads the store as chat

The mailbox URLs are OpenPNE 3's, durable, and already in members' mail and bookmarks, so **none of
them moves**. What changes under Modern is what they answer: every reading page has a chat equivalent,
so [`DirectMessageController`](../../app/Features/DirectMessage/DirectMessageController.php) redirects
into it rather than rendering a second reading of the same rows. Classic renders every one of them
unchanged.

| the OpenPNE 3 URL | where a Modern viewer lands |
|---|---|
| `/message`, `/message/index`, and the four boxes | `/messages` — the drafts box is a section of it |
| `/message/read/{id}`, `/check/{id}`, `/checkDelete/{id}` | the conversation the message is in, `?m={id}` |
| `/message/deleteConfirm/{id}` | the same conversation (Modern confirms a purge inline) |
| `/message/reply/{id}` | the same conversation, with no anchor — answering is writing in it |
| `/message/sendToFriend?id={member}` | that member's conversation, or `/messages` when the id names none |
| `/message/edit/{id}` | nothing: the draft form is the one mailbox screen Modern still renders |

The redirect resolves the counterpart from the viewer's side — the recipient of what they sent, the
sender of what they received — and **404s unless the viewer is a party to the message**. The ids are
sequential and the URLs are public, so a redirect that named the other side would answer "who is
member N corresponding with" for any id, which the boxes' own gates never let through. A draft is a
404 here too: it belongs to no conversation, only to the form still writing it.

The resolution happens **before** the box query, which marks a received message read as a side effect
— that is how the mail's own `/message/read/{id}` still lands on an unread boundary rather than
erasing the line it is sending the reader to. The `?m=` it carries is best-effort like any other
([Ordering and paging](#ordering-and-paging)).

The submits are untouched and surface-agnostic: they redirect to the box they always did, which
forwards on from there — and the forward reflashes, so the answer the write wrote survives the extra
hop instead of being aged out on a page nobody sees.

## Separate from group talk

The two conversation surfaces share their **frontend stream** ([`lib/chat/`](../../resources/js/lib/chat/):
the window state machine, the merge rules, the poll, and the divider and mark-read reporting drawn
over them) and nothing else. Tables, queries and serializers
are each feature's own, and that separation is a contract rather than an accident: a private message
between two people and a message in a room are the same shape on screen and different things in the
system. Concretely, a direct message parses no `@mentions` and no `#hashtags` — so it creates no rows
in any other feature's tables — and no admin screen reads one: the moderation panel covers the site's
published content, and a private message is not that.

## Key invariants

1. `ConversationScope` is the single definition of what a conversation contains. A read path that
   narrows further is answering a different question and should say so.
2. Visibility is per-side and nothing else. Neither arm may read the other side's columns, and no
   relationship between the two members may hide a row.
3. A null counterpart means the withdrawn bucket, and every comparison against it is `IS NULL` — never
   a bound null.
4. `(created_at, id)` is the order, everywhere — reads and cursors alike.
5. `read` is answered by the receipt of the conversation being read, never by whichever receipt the
   relation holds first.
6. The chat screens add no column and no table: everything they show is the mailbox's own rows, and
   both readings stay correct at once — unread included, which is `read_at` and nothing besides.
7. The unread boundary is the first unread received message. Only the received arm has unread state,
   and only a live receipt has any at all.
8. Marking read moves `read_at` from null to a time and never the other way, so every report is
   idempotent and order between reports does not matter.
9. A message written as chat carries no subject and no lineage, and every send — from either screen —
   goes through `SendDirectMessage`.
10. No mailbox URL moves. What a Modern viewer gets from one is a redirect into the chat reading, and
    only ever for a message they are a party to.
