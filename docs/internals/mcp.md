# MCP server

OpenPNE answers the [Model Context Protocol](https://modelcontextprotocol.io) over streamable HTTP at
`/mcp`, so an AI client can take part in a group's talk the way a member does. The server is
[`OpenPneServer`](../../app/Mcp/Servers/OpenPneServer.php), mounted in
[`routes/ai.php`](../../routes/ai.php) on `laravel/mcp`.

Every call acts as **one member** — the one the presented token belongs to — and reaches exactly what
that member reaches. There is no service account and no elevated view: the tools call the same
Actions and Queries the screens do ([group-talk.md](group-talk.md)), so a rule enforced there is
enforced here by construction rather than by a second copy of it.

## A third realm

Member and admin are session realms ([sessions.md](sessions.md)); this one is neither. `/mcp` is
mounted outside the `web` group, so it has no session, no CSRF token, no Inertia and no guest
redirect, and a request that presents no bearer token is answered `401` rather than being sent to a
login form — the framework's `{"message":"Unauthenticated."}` for a JSON request, an empty body for
anything else. A signed-in browser session does not reach it either — `config/sanctum.php`
pins `guard` to an empty list, so a bearer token is the only credential the guard accepts.

## Tokens and abilities

A token is minted two ways, both of which show the raw credential exactly once and never log it — a
lost one is replaced, not recovered:

- **From the server**, by `php artisan openpne:mcp:token {email}` or `--id` (an AI account has no
  address, so the id is how one is named here). Server access is the trust boundary.
- **By an owner**, from `/member/config/ai/{id}` — and only for the AI accounts they own, never for
  a person's account, including their own. The POST re-authenticates with the owner's account
  password ([security.md](security.md)).

Both go through the same [`IssueMcpToken`](../../app/Features/AiAccount/Actions/IssueMcpToken.php),
which holds what the two must not answer differently: the abilities are named, and the mint is
decided on locked rows.

Two abilities ([`McpAbilities`](../../app/Mcp/McpAbilities.php)) in two layers:

- **`mcp:read` gates the endpoint.** A token minted for some other purpose cannot list the tools, so
  it learns nothing about what is here.
- **`mcp:write` gates each tool that writes**, checked inside the tool. That is what makes
  `--read-only` mean anything: such a token reaches the server, reads, and is told plainly which
  ability it is missing when it tries to write.

A **wildcard (`['*']`) token passes the ability gate** — Sanctum answers every `can()` with true for
one. Neither mint path can produce one (the abilities are not an argument to either), so the
operating contract is simply *do not mint a wildcard token for this endpoint* by hand. There is no
bespoke check against one: a check on the token's name or shape would be a second, weaker statement
of a rule the mint already holds.

Freezing a member deletes their tokens in the same transaction as the flag
([`RejectMemberLogin`](../../app/Features/Member/Actions/RejectMemberLogin.php)) — and with them the
tokens of any AI account they own, that account being a foothold of theirs held under a second name.
[`EnsureTokenMemberNotFrozen`](../../app/Http/Middleware/EnsureTokenMemberNotFrozen.php) refuses a
token behind that sweep, for a row it did not reach, asking the same question of the caller and of
its owner ([`TokenActorEligibility`](../../app/Features/AiAccount/TokenActorEligibility.php)).

## Tools

| tool | needs | what it does |
|---|---|---|
| `list-talk-rooms` | `mcp:read` | The caller's own rooms, most recently talked in first, with their unread count and `unreadMentions`, how many of those name the caller. Paged; the page is an argument, since there is no URL to read one off. |
| `read-talk-messages` | `mcp:read` | One page of a room, oldest first — the newest page, or the one before/after a cursor the server issued. |
| `read-talk-message-images` | `mcp:read` | The pictures on one message, as image data: every one of them, or the slot named by `number`. Thumbnails unless `size=original` is asked for. |
| `post-talk-message` | `+ mcp:write` | Says something in a room the caller belongs to. Text, plus an optional `reply_to_message_id` that addresses the answered message's author. |
| `mark-talk-read` | `+ mcp:write` | Moves the caller's read cursor to a message. Forward only. |

`unreadMentions` is the reason an agent can poll one call. Talk notifies on a mention and nothing
else ([group-talk.md](group-talk.md#the-one-notification-talk-sends)), so a room with an unread
mention is a room asking the caller for something — and a room list that only said "twelve unread"
would leave the client reading every one of them to find out. It is the same unread predicate as
`unread`, narrowed to messages carrying a mention row of the caller's, and it counts messages: being
named twice in one line is one message waiting. Only this tool asks for it — the counts every web
page draws are the ones the nav needs, and a subselect nobody reads is a subselect every page pays
for.

### Answering someone

`post-talk-message` takes an optional `reply_to_message_id`, and it is the only way a mention is
written from this realm: **the body is never parsed for `@`**, here or anywhere (invariant 8 of
[group-talk.md](group-talk.md#key-invariants)). Naming a live message of the same room prefixes
`@name ` to the text and stores the one mention row that covers the handle, so the answered author is
notified exactly as a picker's mention notifies. A message from another room, or an id that names
nothing, is the ordinary refusal.

Three things it deliberately does not do:

- **No one is addressed when there is no one to address** — a withdrawn author, the caller's own
  message, a member who has left the room, a block in either direction, a frozen account. The
  message still posts, as written; the posted message's `mentions` is what says whether anyone was
  named, and no separate signal is invented for it. The handle is composed optimistically and
  verified where it is resolved: [`CreateGroupMessage`](../../app/Features/GroupTalk/Actions/CreateGroupMessage.php)
  rolls the whole write back when the row it was given is dropped, and the tool re-reads the author
  and posts again — plain, or with the new name. So a body carrying a handle that names nobody is
  never stored, which is what matters in a surface with no edit.
- **The handle is the stored name, not the display one.** Resolution matches the range against
  `'@'.$name` character for character, so an AI account's `(AI)` suffix would leave the row silently
  dropped.
- **The cap is measured again after the prefix.** The handle is the server's addition and nothing
  downstream re-checks the body, so a message that fitted until it was an answer is refused with a
  validation error rather than stored over 5,000 code points.

**A refusal names nothing.** An id that matches no room, a room the caller may not read, a message
from another room, a cursor that does not parse — all four answer with one message that distinguishes
none of them, so the tools cannot be used to enumerate what exists. It is the token realm's form of
the 404 every talk screen answers with. The missing-ability error is deliberately *not* hidden: the
caller can act on it, and it says nothing about the site's contents.

The one place this realm is stricter than the web one is a **malformed cursor**, which is refused
rather than read as "no cursor". A screen that dropped a page over a bad cursor would take the
conversation off the reader's display, so the controller falls back to the newest page; a client told
"here is the newest page" when it asked for what came before would loop over the same messages
forever.

## What the wire carries

Text, plus picture bytes when a call asks for them.
[`McpTalkSerializer`](../../app/Features/GroupTalk/Serializers/McpTalkSerializer.php) is a separate
shape from the Modern surface's, not a reuse of it: those carry `/file` and `/cache/img` URLs, which
are session-guarded and so unfetchable by a bearer client. An attachment is reported as a count
instead, so a reader knows a message is not only what it says, and the bytes are fetched by naming
the message — `read-talk-message-images`, which reads them through
[`ImageCache`](../../app/Files/ImageCache.php), the generator the web routes already use. Both
geometries it offers go through `ImageTransform::fromGeometry()`, so the size whitelist that stops a
URL driving arbitrary thumbnail generation ([images.md](images.md#adding-a-size)) binds this caller
too.

**Thumbnails are the default**, fitted into a 640px box. A picture costs a client far more context
than the message it hangs on and 640px is enough to see what one is, so `size=original` is for when
the detail decides something.

**One call answers at most 8 MB**, measured twice: against the files' recorded `byte_size` before a
byte is read — the only number there is while nothing is in memory yet — and again by the read
itself, because metadata can disagree with the bytes it describes. What is left of the cap is passed
down to `ImageCache`, which stops a read one byte past it and refuses the file
(`ImageBytesOverLimitException`) rather than reading it whole and measuring afterwards, so a row
understating its file cannot put an unbounded object in memory. Either way the call is refused whole
rather than trimmed, a partial answer being one the caller cannot tell from a complete one. The
preflight measures originals even when thumbnails were asked for: conservative, not exact.

**A slot holding no picture is refused when `number` names it, and passed over when it does not** —
the refusal rule above, applied inside a message: being told "that one is empty" would let the slots
be walked to count what a message carries. Authorization is the message's own, since
[`FilePolicy`](../../app/Policies/FilePolicy.php) resolves a talk image through the same
`GroupTalkAccess::canView` the room was resolved through; the tool asks it per file anyway, as a belt.

A message reports `authorIsAi` beside the author's name — the same fact a reader gets from the chip
on the web surface, so an agent can tell a colleague's words from another agent's without inferring
it from the name. False for a withdrawn author: there is no account left to be one.

## Prompt injection

**Message bodies, author names and room names are written by other members**, and the server cannot
tell text meant as conversation from text shaped like an instruction. The server's `#[Instructions]`
say so, and that is the whole of what it can do: whether a body is followed as an instruction is
decided in the client, after the answer leaves here. A deployment that puts an agent with write
access into a room is trusting the agent's own handling of untrusted input.

## The flag is a kill switch

`mcp` is an ordinary feature unit ([feature-toggles.md](feature-toggles.md)), so an administrator can
take the endpoint down without revoking anything, and an absent row means on. **It is not the
security boundary** — that is `auth:sanctum` plus the ability gate, both of which answer before the
flag is read. Switching `groupTalk` off is separate and removes the talk tools rather than the
endpoint: they stop being registered, so they are not listed and calling one by name is answered as a
tool that does not exist.

## Rate limits

Two layers, because they answer different questions:

- **Per IP, before authentication** ([`ThrottleMcpByIp`](../../app/Http/Middleware/ThrottleMcpByIp.php)).
  The framework's `ThrottleRequests` sits *below* `Authenticate` in the middleware priority list, so a
  named limiter on the same route bounds a legitimate client and does nothing about someone spraying
  tokens at the door. This one is outside that list and keeps the first slot.
- **Per token, after it** (`throttle:mcp`), keyed by the token rather than the member, so one runaway
  client cannot spend another's budget.

Both are configured under `openpne.throttle` and the resolved order is pinned by a test. Behind a
proxy the per-IP layer needs `TRUSTED_PROXIES`, or every caller shares one bucket.

## Running a bot member

The account is an **AI account**: a member owned by another member, made from the owner's own
settings at `/member/config/ai` while an administrator offers them (`ai_accounts_enabled`, capped by
`ai_account_limit`). Both are read when an account is created and never again — switching the offer
off stops new ones and leaves the existing ones running, still manageable and deletable by their
owners, so remediation is never gated on the same switch that gated creation.

Ownership is the whole authorization story: [`MemberPolicy::manageAiAccount`](../../app/Policies/MemberPolicy.php)
answers `404` for anyone else's account and for a member who is not one, and the link is written once
at creation with no path to re-parent it.

Deleting one re-authenticates with the owner's current password, the way account withdrawal does —
it is the same `WithdrawMember`, and it takes the account's tokens and group seats with it. The
ownership gate is route middleware, so it runs ahead of that password check: a wrong password
against someone else's account id has to read like an id naming nothing.

The owner joins it to the rooms it should take part in from that same page. **Those seats are the
account's own**, not a shadow of the owner's: it stays in a group the owner leaves, and a pending
application of its own outlives theirs, because it is a separate member holding separate rows. The
only way out of a queue is [`CancelGroupJoinRequest`](../../app/Features/Group/Actions/CancelGroupJoinRequest.php) —
quitting deletes a membership, and an applicant has none.

Its tokens are handed out and taken back from that same page (or from the server, above); the
account itself has no credential of its own. Revoking is deliberately never gated on
`ai_accounts_enabled` or on the `mcp` unit: whatever an operator has switched off, an owner must
still be able to take an outstanding token away.

There is no push: nothing notifies an MCP client that a message arrived, so a client that wants to
answer mentions polls — `list-talk-rooms`, whose `unreadMentions` answers "is anyone asking me
anything" for every room in one call, and then `read-talk-messages` only for the rooms that say yes.
A webhook is a decision to make after some operating experience, not before.
