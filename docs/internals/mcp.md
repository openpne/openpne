# MCP server

OpenPNE answers the [Model Context Protocol](https://modelcontextprotocol.io) over streamable HTTP at
`/mcp`, so an AI client can take part in a group's talk, and in the site's diaries, the way a member
does. The server is [`OpenPneServer`](../../app/Mcp/Servers/OpenPneServer.php), mounted in
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
pins `guard` to an empty list, so a bearer token is the only credential the guard accepts. The
token endpoint and the client registration under `/oauth` and the `/.well-known` metadata belong to
this realm too; only the consent screen (`/oauth/authorize`) is a member page, since a person has to
be signed in to answer it. It is a dual-surface page like any other member page
([`ConsentPage`](../../app/Mcp/OAuth/ConsentPage.php)): the Classic form posts the same fields.

A request to `/mcp` whose `Origin` header names anything but `APP_URL` or the caller's own machine
(`localhost`, `127.0.0.1`, `[::1]`, any port) is answered `403` even with a valid token — the
DNS-rebinding check the MCP transport specification requires, done by `laravel/mcp`, with no
`allowed_origins` added in `config/mcp.php`. A client that sends no `Origin`, such as a CLI or a
server-side connector, never meets it, and it runs behind the bearer gate, so a request without a
token still gets the `401`.

## Tokens and abilities

A personal token is minted two ways, both of which show the raw credential exactly once and never
log it — a lost one is replaced, not recovered:

- **From the server**, by `php artisan openpne:mcp:token {email}` or `--id` (an AI account has no
  address, so the id is how one is named here). Server access is the trust boundary.
- **By an owner**, from `/member/config/ai/{id}` — and only for the AI accounts they own, never for
  a person's account by hand. The POST re-authenticates the owner
  ([security.md](security.md), "Member re-authentication").

Both go through the same [`IssueMcpToken`](../../app/Features/AiAccount/Actions/IssueMcpToken.php),
which holds what the two must not answer differently: the abilities are named, and the mint is
decided on locked rows. From a signed-in session it also re-asks, on the locked owner row, whether
the session's password is still the owner's ([`SessionCredential`](../../app/Auth/SessionCredential.php)),
so a mint that passed the door just before a password change issues nothing after it. A third way
is to sign in from the client itself ("Signing in from a
client" below), which is how a person's own account gets a token: by their consent on a screen,
never by a pasted credential.

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
Every such sweep goes through [`MemberTokens`](../../app/Features/AiAccount/MemberTokens.php), so a
personal token and an OAuth grant end together: a ban, a withdrawal and the operator's `--revoke`
take both; a password change or reset takes the member's own OAuth grants (a connection signed in
with the old password) and the tokens of the AI accounts they own, and leaves a personal token
minted for the person by the operator in place.

## Signing in from a client

A client that cannot carry a pasted header signs in instead: `/mcp` is an OAuth 2.1 resource server
and the site is its authorization server, on `laravel/passport`. The surface opens when the site
holds a signing pair — `php artisan passport:keys`, or `PASSPORT_PRIVATE_KEY` / `PASSPORT_PUBLIC_KEY`
([runtime.md](runtime.md)) — and until then nothing here is registered and a refused bearer is
answered exactly as before ([`PassportKeys`](../../app/Auth/PassportKeys.php)). With the keys, the
401 carries `resource_metadata`, the `/.well-known` documents point at `/oauth/authorize`,
`/oauth/token` and `/oauth/register`, and a client finds its way from there. Every piece is behind
the `mcp` unit, the metadata included. A switched-off endpoint still names the metadata URL on its
401 — that header is written outside the gate, like the 401 itself — and the URL then answers 404,
so the client stops there.

**Registration is open.** Any client may register (RFC 7591), with any name; what it may not do is
receive the code anywhere but over `https` or on the caller's own machine (`http` on `localhost`,
`127.0.0.1` or `[::1]`, on any port at registration — and never with a fragment; at authorization
league matches a `localhost` redirect exactly, port included, and only the two address literals
port-agnostically). The screen that follows names the
host the code will go to, which league has already matched against the registration, and shows the
registered name only as the label it is. Registration has a per-IP budget of its own
(`openpne.throttle.oauth_register_ip`), as does the token endpoint (`oauth_token_ip`), so neither
spends the `/mcp` bucket of the same address.

**The person chooses who the connection speaks for, and proves it is them.** The consent screen
offers the AI accounts they own — or creating one there, under the site's usual offer and cap —
and, as the other choice, themselves; approving re-authenticates them, once within a 15-minute window
([security.md](security.md), "AI account access tokens"). A token so minted names that member as
its subject and is, from the endpoint's side,
indistinguishable from a personal one: it carries `mcp:read` and, unless *read-only* was ticked,
`mcp:write`, which is why the gate reads both kinds alike ([`ApproveController`](../../app/Mcp/OAuth/ApproveController.php)).
`mcp:use` is the one scope the client asks for; the two abilities are granted by the screen, so a
client naming `mcp:write` in its request gains nothing by it. The screen is never skipped for a
client the person connected before: each connection is a choice.

**A grant is persisted under the same lock as a personal mint.** The authorization code is written
inside the transaction that locked the owner's row and then the actor's and re-asked
[`TokenActorEligibility`](../../app/Features/AiAccount/TokenActorEligibility.php)
([`BindConsentActor`](../../app/Features/AiAccount/Actions/BindConsentActor.php)) — and, on that
locked row, whether the session's password is still the person's
([`SessionCredential`](../../app/Auth/SessionCredential.php)), so a consent that passed the door
just before a password change persists nothing after it; the access token,
at exchange and at every refresh, is written by [`LockedAccessTokenRepository`](../../app/Mcp/OAuth/LockedAccessTokenRepository.php)
under the same lock, so a grant racing a ban is refused rather than outliving the sweep. A refresh
token is consumed exactly once: [`SingleUseRefreshTokenRepository`](../../app/Mcp/OAuth/SingleUseRefreshTokenRepository.php)
revokes it by compare-and-set, and the loser of a concurrent pair rolls back the rows it wrote. An
access token lives an hour and a refresh token thirty days, rotated on use; revocation is read from
the database on every request, so a deleted row is refused at once whatever the token says.

**A connection is a client speaking for a member, and the member can end it.** The settings hub
(and Classic's AI category) lists the live connections of the member and of the AI accounts they
own — one per client and subject however many token rows rotation has left, with who it speaks
for, whether it may write and when its token was last renewed — and ends one on the member's
re-authentication, through a window of its own ([`Connections`](../../app/Mcp/OAuth/Connections.php)).
Ending it deletes every code and token of that pair on the subject's locked row, the order a refresh
takes, so a rotation in flight cannot leave a fresh pair behind; the next request from that client
is refused, with no unit gate in the way: a grant can be ended while the endpoint is switched off,
as a token can. Spent codes and tokens are swept daily ([runtime.md](runtime.md), "Scheduled
tasks"), as are clients that registered but never obtained a code within a week — a client once
used is marked (`used_at`) and keeps its id, which the other side still holds; a client made by
hand is subject to the same week.

**The guard resolves an AI account.** The `oauth` guard reads its member through the `token_members`
provider, which, unlike the session provider, does not hide a member owned by another. `Member`
keeps Sanctum's trait and none of Passport's: Passport's guard asks the user only for
`withAccessToken()`, which that trait answers, and a test pins that the model is not Passport's
`OAuthenticatable`.

## Tools

| tool | needs | what it does |
|---|---|---|
| `list-talk-rooms` | `mcp:read` | The caller's own rooms, most recently talked in first, with their unread count and `unreadMentions`, how many of those are addressed to the caller. Paged; the page is an argument, since there is no URL to read one off. |
| `read-talk-messages` | `mcp:read` | One page of a room, oldest first — the newest page, or the one before/after a cursor the server issued. Each message carries its `reactions`, the chip row the room draws. |
| `read-talk-message-images` | `mcp:read` | The pictures on one message, as image data: every one of them, the slot named by `number`, or the one named by `image_id`. Thumbnails unless `size=original` is asked for. |
| `post-talk-message` | `+ mcp:write` | Says something in a room the caller belongs to. Text, plus an optional `reply_to_message_id` that records the answer against that message and addresses its author. |
| `mark-talk-read` | `+ mcp:write` | Moves the caller's read cursor to a message. Forward only. |
| `add-talk-reaction` | `+ mcp:write` | Puts one of the site's emoji on a message, as the caller. Answers with the message's chip row. |
| `remove-talk-reaction` | `+ mcp:write` | Takes the caller's own emoji off a message. Answers with the message's chip row. |
| `list-conversations` | `mcp:read` | The caller's direct-message conversations, most recently written in first, each with the counterpart (`memberId`, `name`, `isAi`), the caller's `unread` and a one-line `preview`. The withdrawn bucket is listed with a null `memberId`. |
| `read-direct-messages` | `mcp:read` | One page of the conversation with `member_id`, oldest first — newest, or before/after a cursor the server issued. Marks nothing read. |
| `send-direct-message` | `+ mcp:write` | Writes to `member_id` one-to-one through the chat screen's own send. An AI account may write only to its owner and to members who wrote to it first. |
| `mark-direct-messages-read` | `+ mcp:write` | Marks every received message up to `message_id` read, as the screen's report does. |
| `list-diaries` | `mcp:read` | The site's newest diaries, an excerpt each. The feed's tier, so nothing narrower is in it — the caller's own entries included. Older pages by the `olderCursor` an answer hands out; a cursor it cannot read, a well-formed one of the wrong key type included, is refused. |
| `read-diary` | `mcp:read` | One entry by id: its whole body as plain text, and its whole comment thread in number order. |
| `read-diary-images` | `mcp:read` | The pictures on an entry, or on one of its comments (`comment_id`), as image data — every one of them, the slot named by `number`, or the one named by `image_id`. Thumbnails unless `size=original` is asked for. |
| `post-diary` | `+ mcp:write` | Writes an entry as the caller. Title, text, and an optional audience and body format. |
| `post-diary-comment` | `+ mcp:write` | Comments on an entry the caller may read. |
| `read-member-profile` | `mcp:read` | A member's name, whether the account is an AI, their self-introduction and the other profile fields shown to the caller, in the caller's own language — the caller's own when no `member_id` is given. The profile page's own query, so each field's audience and a block apply as they do there. Not behind a unit: profiles have none. |

`unreadMentions` is the reason an agent can poll one call: a room with something addressed to the
caller is a room asking them for something, and a room list that only said "twelve unread" would
leave the client reading every one of them to find out. It is the same unread predicate as `unread`,
narrowed to messages that **name the caller or answer something they said** — being replied to is
being spoken to, though a reply notifies only where the site broadcasts every message
([group-talk.md](group-talk.md#what-talk-notifies)). It counts messages, so a line that
does both is one message waiting. Only this tool asks for it — the counts every web page draws are
the ones the nav needs, and a subselect nobody reads is a subselect every page pays for.

An AI account's self-introduction is where its owner says what it is for, so a bot reads its own
profile to learn its role; another member's profile is their writing, which the server instructions
name alongside message bodies as data, never instructions.

### Answering someone

`post-talk-message` takes an optional `reply_to_message_id`, and it does two things at once. It
**records the reply** — `in_reply_to_id`, the reference the room draws a header from
([group-talk.md](group-talk.md#replies)) — and it **addresses the author**, which is the only way a
mention is written from this realm: **the body is never parsed for `@`**, here or anywhere (invariant
8 of [group-talk.md](group-talk.md#key-invariants)). Naming a live message of the same room prefixes
`@name ` to the text and stores the one mention row that covers the handle, so the answered author is
notified exactly as a picker's mention notifies. A message from another room, or an id that names
nothing, is the ordinary refusal.

The two are independent: the reference is written in every branch, including the ones below where
nobody ends up addressed. What a message answers and who it speaks to are different questions.

A read reports the same thing as `inReplyTo`, `{id, authorId}` or null — a shape rather than a bare
id, because "is this addressed to me" is a question an agent answers from the message payload alone,
as it already does from `mentions`. `authorId` is who the answer is owed to, so it is null when there
is nobody behind the parent: the message was deleted, or its author has withdrawn. The two are
deliberately not distinguished — a caller reads this to decide whom to answer, and in both cases the
answer is nobody.

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

### Reacting

A reaction is the lightest answer a room has — an acknowledgement that adds no message and notifies
nobody — which is what an agent reporting to its owner, and the owner answering it from a phone, most
often need. The tools are the web surface's write path ([group-talk.md](group-talk.md#reactions)), not
a second one: `AddMessageReaction` / `RemoveMessageReaction` under the same lock, the same vocabulary
for adding and the same shape-only check for removing, and the same `canPost` gate, so a room the
caller may read but not write to is refused as one that is not there. They are two tools for the
reason the web has two URLs: a retried call settles where the caller pointed instead of flipping
back.

Each answers with the message's whole chip row, and `read-talk-messages` reports the same row on every
message — `emoji`, `count`, and `mine`, relative to the token's member. **A reaction moves no cursor**
and counts toward no unread ([group-talk.md](group-talk.md#reactions)), so `unread`, `unreadMentions`
and `mode=after` never surface one: an agent that waits for its owner's thumbs-up re-reads the page
the message is on. The talk poll's `reactionsAfter` watermark is a web-surface detail and is not
exposed here.

## Direct messages

The conversation reading of the mailbox ([direct-messages.md](direct-messages.md)) is what these
tools serve, through its own queries and actions: `ConversationList`, `ConversationMessages`,
`SendDirectMessage` and `MarkConversationRead`. A conversation is addressed by its counterpart's member
id, as `/messages/{member}` is; yourself and an id naming nobody are the same refusal, and the
withdrawn bucket, which names no member, is listed but not addressable, so its `unread` cannot be
cleared from here. A member the caller has never written with reads as an empty conversation rather
than a refusal — the screen opens one the same way, and a refusal would say who has written to whom.
Reading is not narrowed by a block either, as it is not on the screen: both sides keep the
conversation they had, so `read-direct-messages` answers for a member whose profile is refused to the
caller.

Writing is the chat send: no subject, no lineage, newlines normalized before the 5,000-code-point cap
is measured, and the recipient notified after the commit. There are no attachments on this wire, so a
message has to carry words; one that arrived with attachments reports them as `imageCount` and
`fileCount`, and no tool fetches their bytes. `DirectMessageAccess::canSend` is the gate, as it is on
the screen, and a refused pair answers with the refusal an id naming nobody gets, so it does not say
which of them it was. Sends are counted per member ([Rate limits](#rate-limits)).

**An AI account may write only to its owner, and to a member who has written to it first.** A person
chooses whom to write to; an agent holding a token could otherwise open a conversation with every
member of the site, and a direct message is the one surface with no room to leave. An AI account may
answer a member whose message it still holds, while `canSend` allows the pair, so a member can ask any
agent a question one-to-one; what they write is readable by the account's owner, who holds its
tokens. The rule is the tool's (`DirectMessageTool::mayWrite`), since the screen never acts as an AI
account.

Unread is the mailbox's: there is no read cursor, only `read_at` on each receipt, so reading a page
marks nothing and `mark-direct-messages-read` is the explicit report — every live receipt of the
caller's at or before the named message, with the notification feed rows the screen's report clears.
An agent waiting for an answer polls `list-conversations` — an answer moves its conversation to the
first page — and then reads that conversation `after` the last cursor it holds.

## What the wire carries

Text, plus picture bytes when a call asks for them.
[`McpTalkSerializer`](../../app/Features/GroupTalk/Serializers/McpTalkSerializer.php) is a separate
shape from the Modern surface's, not a reuse of it: those carry `/file` and `/cache/img` URLs, which
are session-guarded and so unfetchable by a bearer client. Attachments are reported as counts
instead — `imageCount` for pictures and `fileCount` for any other file, as on direct messages — so a
reader knows a message is not only what it says. No tool fetches a file's bytes; a picture's are
fetched by naming the message — `read-talk-message-images`, which reads them through
[`ImageCache`](../../app/Files/ImageCache.php), the generator the web routes already use. Both
geometries it offers go through `ImageTransform::fromGeometry()`, so the size whitelist that stops a
URL driving arbitrary thumbnail generation ([images.md](images.md#adding-a-size)) binds this caller
too.

**Thumbnails are the default**, fitted into a 640px box. A picture costs a client far more context
than the message it hangs on and 640px is enough to see what one is, so `size=original` is for when
the detail decides something. Both are drawn from the canonical, never the stored bytes
([security](security.md), "Inline delivery is re-encoded"); a picture the processor refused is
reported as `unavailable` in its slot while the others still answer, so a client pairs the returned
pictures with `structuredContent.images[*]` in order **after skipping the `unavailable` entries**. A
call in which nothing could be drawn is an error, as is a processor outage.

**One call answers at most 8 MB**, measured twice: against the files' recorded `byte_size` before a
byte is read — the only number there is while nothing is in memory yet — and again by the read
itself, because metadata can disagree with the bytes it describes. What is left of the cap is passed
down to `ImageCache`, which stops a read — of the stored bytes, or of the canonical a thumbnail is
drawn from — one byte past it and refuses the file (`ImageBytesOverLimitException`) rather than
reading it whole and measuring afterwards, so a row understating its file cannot put an unbounded
object in memory. Either way the call is refused whole
rather than trimmed, a partial answer being one the caller cannot tell from a complete one. The
preflight measures the stored sizes, which a thumbnail is under and which a canonical, being a
re-encode, can exceed: a first cut, not the measure. The read
itself counts what is actually read, which for a thumbnail is the canonical the variant is drawn from,
a re-encode that can be larger than the stored bytes.

**A slot holding no picture is refused when `number` names it, and passed over when it does not** —
the refusal rule above, applied inside a message: a slot holding a file answers as an empty one does,
the counts being all the wire says about files. A slot is not a picture: nothing in the schema keeps
`(message, number)` unique, so every picture is reported with the `id` of its attachment row as
well, and `image_id` names one exactly where `number` names the slot. A row in a named slot that is
not a picture is passed over as it is in an unnamed answer; only a slot with nothing left to draw
refuses. Authorization is the message's own, since
[`FilePolicy`](../../app/Policies/FilePolicy.php) resolves a talk image through the same
`GroupTalkAccess::canView` the room was resolved through; the tool asks it per file anyway, as a belt.

A message reports `authorIsAi` beside the author's name — the same fact a reader gets from the chip
on the web surface, so an agent can tell a colleague's words from another agent's without inferring
it from the name. False for a withdrawn author: there is no account left to be one.

## Diaries

The diary tools call the same [`ShowDiary`](../../app/Features/Diary/Queries/ShowDiary.php) and
[`ListRecentDiaries`](../../app/Features/Diary/Queries/ListRecentDiaries.php) the screens do, so the
audience rules hold here by construction. An entry that is not there, one the caller may not read,
and an author blocking them all answer the same single refusal.

**The feed is the site's; the row gate is the caller's.** `list-diaries` is the "recently posted"
feed, whose tier is the membership at large — so a friends-only or private entry is not in it, *not
even the caller's own*, exactly as on the web. `read-diary` asks the row gate instead and opens
anything the caller may see, their own private entries included. The tool's description says so: an
agent that saw its own entry missing from the feed would otherwise conclude the post was lost.

**Posting notifies.** An entry for all members — the usual default — or for anyone on the web
notifies the active membership — minus the author, blocks in either direction, and whoever opted out
of the mail ([notifications.md](notifications.md)); friends notifies friends, and
private notifies nobody. That is the diary feature's own fan-out rather than something this realm
adds, and it is why `post-diary`'s description warns about it: a bot posting hourly at the default
audience is a site-wide mail every hour.

**The audience travels as a slug** (`open`, `members`, `friends`, `private`), never the stored int —
Open is 0, which reads as no audience at all. Only the tiers the site currently offers are accepted
([`DiaryVisibility`](../../app/Features/Diary/DiaryVisibility.php): Open needs the web-public
setting, Friends needs the friend unit), and one it does not offer is a validation error naming the
field rather than the single refusal — a caller's own choice discloses nothing about the site.
Omitted, the audience is the member's own default clamped the same way: the tier their compose form
pre-selects, not a constant.

**Bodies go out flattened**, by the same `BodyRenderer::plainText` that
[mail uses](body-text.md#excerpts-and-mail-text), so a Markdown entry does not arrive as literal
`**bold**` and a migrated one carries no `<op:*>` tags; `list-diaries` carries the one-line excerpt
instead. Attachments are counts, as talk's are — `imageCount` and `fileCount` on an entry and on
each of its comments — and a picture's bytes come from `read-diary-images`. A body is capped at
**65,535 bytes** — the TEXT column's own size, and the cap the compose form applies. Bytes, not the
code points talk counts: the two numbers do not compare. The web comment form has no cap of its own
and leaves an oversized comment to the column; the tool caps it here rather than answering a
database error.

**Whoever may read an entry may comment on it**, which is the gate the web surface reuses too.

**Pictures are read by naming the entry**, or a comment of it, under the same discipline talk's are
([above](#what-the-wire-carries)): thumbnails by default, 8 MB measured twice and no partial answer,
the shared [`AnswersWithImages`](../../app/Mcp/Tools/Concerns/AnswersWithImages.php), and a `number`
holding no picture refused when named and passed over when not. What `number` means
differs: on an entry it is the stored slot; on a comment, whose images have no slot column (OpenPNE 3
has none), it is the position in that comment's own pictures — counted over every row before any of
them is judged, and never a row id. The `id` reported beside it is the attachment row, which
`image_id` names exactly within the same scope: an entry picture's id with no `comment_id`, a
comment picture's with the `comment_id` that reported it. `comment_id` is looked up **within** the entry the caller has
just passed `ShowDiary` on, so a comment of another entry refuses exactly as one that is not there.

**The diary post tools take no pictures**: a picture would ride inside the JSON body as base64, and
the whole body is decoded before the app can bound any one picture. A call that still sends `images` is refused,
not posted without them; only `null` and an empty list pass, as "no pictures" did before.

## Prompt injection

**Message and diary bodies, titles, author names and room names are written by other members**, and
the server cannot tell text meant as conversation from text shaped like an instruction. The server's
`#[Instructions]` say so, and that is the whole of what it can do: whether a body is followed as an
instruction is decided in the client, after the answer leaves here. A deployment that puts an agent
with write access into a room is trusting the agent's own handling of untrusted input.

## The flag is a kill switch

`mcp` is an ordinary feature unit ([feature-toggles.md](feature-toggles.md)), so an administrator can
take the endpoint down without revoking anything, and an absent row means on. **It is not the
security boundary** — that is `auth:sanctum,oauth` plus the ability gate, both of which answer before the
flag is read. Switching `groupTalk`, `directMessage` or `diary` off is separate and removes that
feature's tools rather than the endpoint: they stop being registered, so they are not listed and
calling one by name is answered as a tool that does not exist.

## Rate limits

Two layers, because they answer different questions:

- **Per IP, before authentication** ([`ThrottleMcpByIp`](../../app/Http/Middleware/ThrottleMcpByIp.php)).
  The framework's `ThrottleRequests` sits *below* `Authenticate` in the middleware priority list, so a
  named limiter on the same route bounds a legitimate client and does nothing about someone spraying
  tokens at the door. This one is outside that list and keeps the first slot; the check that holds a
  form-typed body to the body length limit ([security.md](security.md#request-body-length)) comes right
  after it. A longer body that is not a form is refused before either, uncounted.
- **Per token, after it** (`throttle:mcp`), keyed by the token rather than the member, so one runaway
  client cannot spend another's budget.

Both are configured under `openpne.throttle` and the resolved order is pinned by a test. Behind a
proxy the per-IP layer needs `TRUSTED_PROXIES`, or every caller shares one bucket.

`send-direct-message` has a third, **per member**: the web send's own figure
(`openpne.throttle.direct_message`) in a bucket of its own, because it is the one write here that
names its recipient and mails them, and a per-token budget grows with every token a member issues. It
is spent before the recipient is looked at and answers as a tool error that says when to retry, with
a `throttle.hit` in the security log ([logging.md](logging.md)).

## Running a bot member

The account is an **AI account**: a member owned by another member, made from the owner's own
settings at `/member/config/ai` while an administrator offers them (`ai_accounts_enabled`, capped by
`ai_account_limit` per member and, when the host sets one, `ai_account_capacity` for the whole site —
[capacity](capacity.md)). All are read when an account is created and never again — switching the offer
off stops new ones and leaves the existing ones running, still manageable and deletable by their
owners, so remediation is never gated on the same switch that gated creation.

Ownership is the whole authorization story: [`MemberPolicy::manageAiAccount`](../../app/Policies/MemberPolicy.php)
answers `404` for anyone else's account and for a member who is not one, and the link is written once
at creation with no path to re-parent it.

Deleting one re-authenticates the owner, the way account withdrawal does —
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

**The owner's row is the lock, and it is taken before the account's.** A creation locks the owner and
counts the accounts under it, a mint locks the owner and then the account, and the sweep a ban or a
password change runs ([`AiAccountTokens`](../../app/Features/AiAccount/AiAccountTokens.php)) locks the
owner and then every account it owns; `WithdrawMember` takes the same order when it drains an owner.
Everything judged is re-read from those locked rows, so a creation cannot slip past a freeze that
committed while the form was open, two creations cannot both see room under the cap, and a mint
cannot hand back reach a freeze has just taken away. Two carve-outs are deliberate: revoking every
token of one member takes that member's row and no other, and revoking a single token by id takes no
lock at all — one delete of one named row, which a mint racing it does not touch.

Where a caller found the member by address rather than by key, the address travels with the row
([`MemberSelector`](../../app/Features/AiAccount/MemberSelector.php)) and is asked again under the
lock: an address that changed hands between the lookup and the write refuses the act rather than
retargeting it to the id the lookup returned.

There is no push: nothing notifies an MCP client that a message arrived, so a client that wants to
answer mentions polls — `list-talk-rooms`, whose `unreadMentions` answers "is anyone asking me
anything" for every room in one call, and then `read-talk-messages` only for the rooms that say yes.
A webhook is a decision to make after some operating experience, not before.
