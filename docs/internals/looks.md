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
| Client | `LOOKS` in [`lib/member-chrome.ts`](../../resources/js/lib/member-chrome.ts) | one field per question the shell asks a look about the chrome around the page — its docblock states when a question earns a field |

`LookRegistryParityTest` asserts the two key sets are equal, because drift is silent and
asymmetric: a look present only on the server ships unified pages under standard chrome, a
look present only on the client the reverse. Each `LOOKS` row is complete by type
(`satisfies Record<string, LookSpec>`), so a look cannot answer some of the shell's
questions and leave the others reading `undefined` — falsy, and standard-ish rather than
standard.

The looks today:

- **`standard`** — the layout the site has always shipped. Deviates in nothing.
- **`unified`** — the experiment: `/member/{id}` renders `unified/member` instead of
  `member/show`, and `/groups/{group}` renders `unified/group` instead of
  `community/show`; the bars become the persistent header plus dive row, the shell paints
  the unified ground, and the right rail is dropped. Those two pages are the only swap:
  `/` renders `home/issue` under every look.
- **`tabbed`** — the same swapped pages, different chrome, phone scope for now. Every
  screen class speaks one header grammar: the site mark, then where you are — `[mark]` +
  the site name on the three pages that ARE a place (home, a member's, a group's — a
  member's and a group's name themselves again in their own hero directly below),
  `[mark] › section` on a hub, `[mark] › place` on screens *inside* one, with the
  site color as a 4px line atop the row. The tab row under the page is the shipped one,
  marked its own way: a dot on the notifications tab and nothing on the rest of the row,
  where the shipped look prints each count — a dot can be answered by emptying one
  screen, which is not true of the counts cleared a room at a time. The menu drawer is
  the header's mirror:
  full-bleed from the trigger's edge with the slide visible, the color line and the brand
  holding their exact positions across open and shut, and the close control the trigger's
  labelled twin — the same box in the same seat, "close" where "menu" stood, first in the
  DOM as it is first on screen. At desk width it is headerless: the same
  color line, now the only full-width element the chrome has, over the standard sidebar,
  and on a screen deep enough to be inside somewhere a sticky place bar at the head of the
  content column — the same crumb in the same pill, carrying the place's face — in place of
  the crumb trail rather than beside it.

Two decisions inside `tabbed` are deliberate rather than pending:

- **A form carries no way back.** Swipe, the browser and the site mark are the exits; the
  crumb beside an unsaved form is static text, not a pill, which is the same rule the
  shipped detail bar states at its scope gate. A pressable-looking crumb that navigates
  away from half-typed input is the thing being avoided, and painting one that does not
  navigate would lie instead.
- **A conversation holds its chrome still.** Dogfooding reversed the recede that first
  shipped here: the composer pins the screen's foot, so a bar hiding on scroll frees no
  space — a loss with no gain — and the header is what names the room, which matters most
  mid-scroll. `bottomBarInConversation` is what keeps the bar standing while the room is
  *read*; only focusing the composer takes it away, over the same 200ms the composer takes
  to descend, and the bar stays mounted through that so it slides rather than blinks.

## Resolution

[`LookResolver::resolve()`](../../app/Support/LookResolver.php) answers once per request,
and [`HandleInertiaRequests`](../../app/Http/Middleware/HandleInertiaRequests.php) shares
the id as the `look` prop so no consumer resolves it a second time. The chain:

1. **Guest clamp** — no member on the request, `standard`. A look is a member's way around
   their own pages, and a signed-out visitor reaches none of them; the pages a guest does
   reach (a web-public profile) have no viewer for a look's serializer to render against.
2. **Member choice** — `PreferenceKey::PreferredLook`, while the site still offers it; a
   row outside the selectable set is ignored rather than honoured.
3. **Site default** — `SnsSettingKey::DefaultLook` in `sns_settings`, edited on the
   *UI layout settings* admin page. A stored value no registered look answers to decodes
   to `standard`.

Layer 2 reads `member_preferences` once per Inertia navigation; the relation is
instance-cached, so a request that also reads another preference shares the query.

### Choosing a look

The picker is a member-config detail page, `GET /member/config/look`
(`member.config.look.edit`), that *describes* the looks: a table comparing them dimension
by dimension, then one card per selectable look, then an explicit save. The settings hub
keeps a row naming the current choice and linking here, the same shape as email and
password.

A live try-on was built and retired. It rendered the chosen look with a bar offering to
keep or drop it, on the reasoning that a look is best judged by being seen — but seeing a
look without knowing what to look at communicated nothing, so the differences are spelled
out instead. The session tier that carried it is gone from the resolver.

`POST /member/config/look` (`choice`: a look id, or the literal `default`) writes the
preference or clears it, back to following the site's, and answers with a full page load
back to the picker — the whole shell re-drawing in the chosen look is the confirmation.
`UpdateLookRequest::authorize()` refuses an id outside the selectable set (403) rather than
relying on the picker's absence, and the GET redirects to the settings page while fewer
than two looks are selectable.

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
