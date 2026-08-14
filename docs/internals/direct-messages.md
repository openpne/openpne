# Direct messages

A direct message is stored as OpenPNE 3 stored it: the authored row in `direct_messages`, one receipt
per recipient in `direct_message_recipients`, and each side's read and trash state on its own side's
columns. The mailbox screens (`/message/*`) read it as four boxes.

The chat screen is a **second reading of the same rows** — no new table, no new column. A conversation
is the pair *viewer ⟷ counterpart*, and both directions of it are composed back out of the storage on
every read: `/messages/{member}` renders it, `/messages/{member}/messages` serves the pages after the
first, and both are read-only. Writing, marking read and restoring from the trash are the mailbox's,
and a message written there appears here on the next read because there is only one store.

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

## What the mailbox holds and a conversation has to place

Nothing about the storage is dropped or rewritten to make it read as chat, so every field the mailbox
has needs an answer here.

| what | how the conversation reads it |
|---|---|
| `subject` | shown above the body when it is non-null, left out otherwise. A message written as chat has none. |
| null `body` | serialized as the empty string; a subject-only message is still a message. |
| draft | never in a conversation, and not by a rule of its own: a draft has no receipt, so neither arm reaches it. It stays the drafts box's. |
| `sender_deleted_at` / `recipient_deleted_at` (trash) | hidden from that side's conversation only. Restoring is the mailbox's trash screen. |
| `sender_purged_at` / `recipient_purged_at` | the same, permanently — the row survives for the other side. |
| `parent_id` / `thread_id` | lineage the reply flow writes; a conversation is linear and reads neither. |
| withdrawn member | the bucket above, with `author` serialized as null and drawn as "Withdrawn member". |
| several recipients (upgraded OpenPNE 3 sends) | the row appears in each recipient's conversation, and `read` is **that** conversation's receipt. |

`read` is the sender's own view of delivery: true once the counterpart's receipt carries a `read_at`.
A received message reports null — reading it is what the reader is doing — and so does anything in the
withdrawn bucket, where no receipt names a member.

## Separate from group talk

The two conversation surfaces share their **frontend stream** ([`lib/chat/`](../../resources/js/lib/chat/):
the window state machine, the merge rules, the poll) and nothing else. Tables, queries and serializers
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
   both readings stay correct at once.
