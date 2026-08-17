# Looks

A **look** is a named set of deviations from the standard Modern layout. It is not a theme
and not a second UI: a screen the look says nothing about renders standard, so the amount
of code a look owns is the size of its deviation, not the size of the product.

Looks are a Modern-surface concept. Which surface a request gets (Classic or Modern) is a
separate, earlier question — see [feature-modules.md](feature-modules.md).

## The registry

A look is declared twice, once per side of the wire:

| Side | Where | Answers |
|---|---|---|
| Server | [`App\Support\Look`](../../app/Support/Look.php) | which page components the swapped routes render (`usesUnifiedPages()`), plus the look's label and description |
| Client | `LOOKS` in [`lib/member-chrome.ts`](../../resources/js/lib/member-chrome.ts) | which chrome the bars draw, which ground the shell paints, whether the right rail stands |

`LookRegistryParityTest` asserts the two key sets are equal, because drift is silent and
asymmetric: a look present only on the server ships unified pages under standard chrome, a
look present only on the client the reverse. Each `LOOKS` row is complete by type
(`satisfies Record<string, LookSpec>`), so a look cannot answer some of the shell's
questions and leave the others reading `undefined` — falsy, and standard-ish rather than
standard.

The looks today:

- **`standard`** — the layout the site has always shipped. Deviates in nothing.
- **`unified`** — the experiment: `/dashboard` renders `unified/home` instead of
  `dashboard`, `/member/{id}` renders `unified/member` instead of `member/show`, and
  `/groups/{group}` renders `unified/group` instead of `community/show`; the bars become
  the persistent header plus dive row, the shell paints the unified ground, and the right
  rail is dropped.

## Resolution

[`LookResolver::resolve()`](../../app/Support/LookResolver.php) answers once per request,
and [`HandleInertiaRequests`](../../app/Http/Middleware/HandleInertiaRequests.php) shares
the id as the `look` prop so no consumer resolves it a second time. The chain:

1. **Guest clamp** — no member on the request, `standard`. A look is a member's way around
   their own pages, and a signed-out visitor reaches none of them.
2. **Site default** — `SnsSettingKey::DefaultLook` in `sns_settings`, edited on the
   *UI layout settings* admin page. A stored value no registered look answers to decodes
   to `standard`.

A session preview and a durable member choice are planned between the two, in that order:
the preview is what a member is trying on right now, the preference what they settled on,
and the site default what an undecided member gets.

## What a look may not do

Every look inherits the same contract, which is what makes switching one on a decision
rather than a deploy:

- **Read-only projection.** A look re-arranges what the viewer already reads. Same routes,
  same viewer-scoped queries, same clearance.
- **No new capability rides a look.** Where a look's page shows something the page it
  replaces did not, the rows come from a source the viewer already reads and each file is
  asked again through `FilePolicy` — a parent's read gate is not a permission on the bytes
  hanging off it.
- **Nothing is stored differently.** `standard` renders the pre-look pages and chrome
  unchanged — the shared `look` prop replacing the retired `unifiedLayout` boolean is the
  one wire-format difference — so switching back restores the previous pages with no data
  to undo.

## Migration note

The mechanism replaces `modern_unified_home`, a site-wide bool. A site that had it on
converts to `default_look = unified`; every other site to the absent-row default,
`standard`.
