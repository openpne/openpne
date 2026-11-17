# Looks

A **look** is a named set of deviations from the standard Modern layout. It is not a theme
and not a second UI: a screen the look says nothing about renders standard, so the amount
of code a look owns is the size of its deviation, not the size of the product.

Looks are a Modern-surface concept. Which surface a request gets (Classic or Modern) is a
separate, earlier question — see [feature-modules.md](feature-modules.md).

A site does not choose among them and neither does a member: everyone is served `tabbed`
([Resolution](#resolution)). `standard` and `unified` are served to no one.

## The registry

A look is declared twice, once per side of the wire:

| Side | Where | Answers |
|---|---|---|
| Server | [`App\Support\Look`](../../app/Support/Look.php) | which page components the swapped routes render (`usesUnifiedPages()`) |
| Client | `LOOKS` in [`lib/member-chrome.ts`](../../resources/js/lib/member-chrome.ts) | one field per question the shell asks a look about the chrome around the page — its docblock states when a question earns a field |

`LookRegistryParityTest` asserts the two key sets are equal, because drift is silent and
asymmetric: a look present only on the server ships unified pages under standard chrome, a
look present only on the client the reverse. Each `LOOKS` row is complete by type
(`satisfies Record<string, LookSpec>`), so a look cannot answer some of the shell's
questions and leave the others reading `undefined` — falsy, and standard-ish rather than
standard. Fields whose value vectors coincide are indistinguishable to every test — registry
values and consumer renders alike — until some look splits them, so a consumer has to read the
field whose name answers the question being asked rather than one that happens to agree today.

The looks today:

- **`standard`** — deviates in nothing.
- **`unified`** — the experiment: `/member/{id}` renders `unified/member` instead of
  `member/show`, and `/groups/{group}` renders `unified/group` instead of
  `community/show`; the bars become the persistent header plus dive row, and the shell
  paints the unified ground. Those two pages are the only swap:
  `/` renders the same page under every look ([home-issues.md](home-issues.md#routes)).
- **`tabbed`** — the same swapped pages, different chrome. Every
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
  the crumb trail rather than beside it. The bar is one height on every screen that has
  one, and the shell publishes it there as `--modern-place-bar`, which whatever sticks
  below the bar adds to its own offset (`top-below-place-*`): a conversation's unread
  banner and day indicator, and the editor's toolbar. A screen with no place bar, in any
  look, leaves the variable unset.

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
the id as the `look` prop so no consumer resolves it a second time:

Everyone gets `tabbed`. Nothing a site or a member stores changes that: there is no setting
for it and no picker.

A signed-out visitor gets the same frame with the member-only parts left out:

- No bottom tabs and no menu. The way in to `/login` stands where the menu does: at the end
  of the phone header, and in the sidebar at desk width.
- The crumb never takes the place tier, whose target is a member's page that may sit behind
  the login wall. It is the trail's last item, and it is a link only where that target is
  open to a guest (an entry's crumb to its author's entries, which lists the web-public
  ones); anywhere else it is text.
- A web-public profile is the member page with the heading and the profile fields alone
  ([member-profile.md](member-profile.md), "Key invariants").

## What a look may not do

Every look inherits the same contract:

- **Read-only projection.** A look re-arranges what the viewer already reads. Same routes,
  same viewer-scoped queries, same clearance.
- **No new capability rides a look.** Where a look's page shows something the page it
  replaces did not, the rows come from a source the viewer already reads and each file is
  asked again through `FilePolicy` — a parent's read gate is not a permission on the bytes
  hanging off it.
- **Nothing is stored differently.** A look reads the rows every other look reads and writes
  none of its own.

## Stored values that are no longer read

`default_look` and `selectable_looks` in `sns_settings`, and a member's `preferred_look`
preference, were the site default, the looks offered to members and a member's own choice. A
row of any of them left on a site has no effect.
