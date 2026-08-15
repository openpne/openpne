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

A token is issued from the server, by `php artisan openpne:mcp:token {email}` — an act of server
access, not something a member can do from a screen. The raw credential is printed once and never
logged; a lost one is replaced.

Two abilities ([`McpAbilities`](../../app/Mcp/McpAbilities.php)) in two layers:

- **`mcp:read` gates the endpoint.** A token minted for some other purpose cannot list the tools, so
  it learns nothing about what is here.
- **`mcp:write` gates each tool that writes**, checked inside the tool. That is what makes
  `--read-only` mean anything: such a token reaches the server, reads, and is told plainly which
  ability it is missing when it tries to write.

A **wildcard (`['*']`) token passes the ability gate** — Sanctum answers every `can()` with true for
one. The command above is the first-party way to mint a token and always issues named abilities, so
the operating contract is simply *do not mint a wildcard token for this endpoint*. There is no
bespoke check against one: a check on the token's name or shape would be a second, weaker statement
of a rule the mint already holds.

Freezing a member deletes their tokens in the same transaction as the flag
([`RejectMemberLogin`](../../app/Features/Member/Actions/RejectMemberLogin.php)).
[`EnsureTokenMemberNotFrozen`](../../app/Http/Middleware/EnsureTokenMemberNotFrozen.php) refuses a
frozen member's token behind that, for a row the sweep did not reach.

## Tools

| tool | needs | what it does |
|---|---|---|
| `list-talk-rooms` | `mcp:read` | The caller's own rooms, most recently talked in first, with their unread count. Paged; the page is an argument, since there is no URL to read one off. |
| `read-talk-messages` | `mcp:read` | One page of a room, oldest first — the newest page, or the one before/after a cursor the server issued. |
| `post-talk-message` | `+ mcp:write` | Says something in a room the caller belongs to. Text only, no mentions. |
| `mark-talk-read` | `+ mcp:write` | Moves the caller's read cursor to a message. Forward only. |

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

Text. [`McpTalkSerializer`](../../app/Features/GroupTalk/Serializers/McpTalkSerializer.php) is a
separate shape from the Modern surface's, not a reuse of it: those carry `/file` and `/cache/img`
URLs, which are session-guarded and so unfetchable by a bearer client. An attachment is reported as a
count instead, so a reader knows a message is not only what it says. Serving the bytes to this realm
is a later decision, and needs the file routes to speak it.

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

The account is an ordinary member, created through the existing invite flow — there is no bot model
and no bot flag. Say in its name and profile that it is an AI, since everyone in the room will see it
speak as a member. Then join it to the rooms it should take part in and issue its token.

There is no push: nothing notifies an MCP client that a message arrived, so a client that wants to
answer mentions polls `read-talk-messages`. A webhook is a decision to make after some operating
experience, not before.
