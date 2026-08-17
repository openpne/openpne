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
   their own pages, and a signed-out visitor reaches none of them. It is first, not merely
   present: a signed-out request can still carry the previous member's preview session, and
   the pages a guest does reach (a web-public profile) have no viewer for a look's
   serializer to render against.
2. **Session preview** — what the member is trying on right now (below), while the site
   still offers it.
3. **Member choice** — `PreferenceKey::PreferredLook`, while the site still offers it; a
   row outside the selectable set is ignored rather than honoured.
4. **Site default** — `SnsSettingKey::DefaultLook` in `sns_settings`, edited on the
   *UI layout settings* admin page. A stored value no registered look answers to decodes
   to `standard`.

The preview outranking the durable choice is the inverse of the session tier in
[`SetLocale`](../../app/Http/Middleware/SetLocale.php), where the durable value wins: a
preview exists precisely to be temporary, and it is cleared on confirm, on cancel, on a
surface switch, and with the rest of the session on logout.

Layer 3 reads `member_preferences` once per Inertia navigation; the relation is
instance-cached, so a request that also reads another preference shares the query.

### Trying a look on

A look changes the whole shell, so a member is shown one before they keep it, rather than
being asked to pick from descriptions. `POST /member/config/look/preview` parks the choice
in the member-realm session under `look_preview` — `['look' => <id>, 'pin' => <bool>]`,
`pin` false meaning "follow the site default", previewing whatever that currently is — and
answers with a full page load, because what changes is the shell the SPA is running inside.
Every Modern page then carries the preview bar (the `lookPreview` prop), which is the only
way out of that state:

- `POST /member/config/look` keeps it. **Parameter-free**: the intent comes from the same
  session the bar was rendered from, so what gets saved cannot disagree with what is on
  screen. `pin` true writes the preference; false clears it, back to following the default.
- `POST /member/config/look/preview/stop` drops it.

### Which looks a member may pick

`SnsSettingKey::SelectableLooks` — the registry's one list-valued key, stored as a CSV of
ids — is what the administrator offers. The effective set is that ∪ the site default (which
is always pickable, being what "follow the site default" follows), derived in exactly one
place, `LookResolver::selectable()`: the resolver, the config serializer, the preview
request and the admin cleanup all read it from there rather than re-deriving the union.
Fewer than two, and the member config page serves no layout section at all.

Narrowing the set does not strand the members who chose what was removed. Inside the same
transaction as the save, the admin page deletes every `preferred_look` row outside the set
*it is establishing* — computed from the posted values, since the settings cache still
holds the old ones — and the success notification reports how many members were returned to
the default. A row that outlives that (a look dropped from the registry, a hand-written
value) is ignored on read.

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
