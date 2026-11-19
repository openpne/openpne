# The Modern frame

The chrome around every Modern page: the phone header, the bottom tabs, the menu drawer, and at
desk width the sidebar, the site-color line and the place bar. There is one frame. A site does
not choose among layouts and neither does a member, and nothing is stored about it.

Which surface a request gets (Classic or Modern) is a separate, earlier question — see
[feature-modules.md](feature-modules.md). What a screen *is* (a hub, a form, a compose sheet, a
conversation, the place it is inside) comes from the chrome registry in
[`lib/member-chrome.ts`](../../resources/js/lib/member-chrome.ts); this document is what the
frame draws from those answers.

## The phone header

Below `lg`, [`TopNav`](../../resources/js/components/top-nav.tsx) speaks one grammar on every
screen class: the site mark, then where the reader is, with the site color as a 4px line atop
the row.

| Screen | The header reads |
|---|---|
| Home, a member's page, a group's page | `[mark]` + the site name. These pages ARE a place: a member's and a group's name themselves in their own hero directly below |
| A hub | `[mark] › section`. The bar's copy of the title is hidden from assistive technology; the page's own h1 stays on screen and is its one announcement |
| A screen inside a place | `[mark] › place`, the place a pill that leads to its top |
| A screen inside no place, with a trail | `[mark] › trail`, the trail's last item a pill that leads to it |
| A form | `[mark] › trail`, as static text |
| A screen inside nothing, with no trail | `[mark]` alone |

The header ends in the menu. There is no back control: swipe, the browser and the site mark are
the ways out.

Two decisions here are deliberate rather than pending:

- **A form carries no way back.** The crumb beside an unsaved form is static text, not a pill. A
  pressable-looking crumb that navigates away from half-typed input is the thing being avoided,
  and painting one that does not navigate would lie instead.
- **A compose sheet keeps its own header.** A sheet is a mode rather than a screen class: its
  header is the ✕ that leaves it plus the page's own actions, and it draws no bottom tabs
  ([feature-modules.md](feature-modules.md), "Surface responsibilities").

## The bottom tabs

[`BottomNav`](../../resources/js/components/bottom-nav.tsx) is four labelled tabs — Home and the
three sections that survive their units being on — for a signed-in member, below `lg`. It is a
shortcut, not the whole nav: the same sections stay in the drawer.

- **One mark.** A dot on the notifications tab, and nothing on the rest of the row. A dot can be
  answered by emptying one screen, which is not true of the counts cleared a room at a time;
  those counts stay on the drawer's entries. The dot cannot print how many, so the count is in
  the tab's accessible name, after the word.
- **A conversation holds its chrome still.** The composer pins the screen's foot, so a bar hiding
  on scroll frees no space, and the header is what names the room, which matters most mid-scroll.
  The tabs stand while the room is *read*; only focusing the composer takes them away, over the
  same 200ms the composer takes to descend, and the bar stays mounted through that so it slides
  rather than blinks.

The shell reserves the row's height as `--modern-bottom-offset`, the home-indicator inset
included, and reserves the inset alone where no row stands.

## The menu drawer

[`NavDrawer`](../../resources/js/components/nav-drawer.tsx) is the header's mirror: full-bleed
from the trigger's edge, the color line and the brand holding their exact positions across open
and shut, and the close control the trigger's labelled twin — the same box in the same seat,
"close" where "menu" stood, first in the DOM as it is first on screen. Both controls carry their
word under the glyph, so neither takes a tooltip. The drawer's foot carries the profile and
sign-out rows, since the phone bars carry no account menu.

## Desk width

At `lg` and up no header stands. The site-color line is the only full-width element, over the
sidebar ([`LeftNav`](../../resources/js/components/left-nav.tsx)), and the sidebar plus the
content column center as one block.

On a screen deep enough to be inside somewhere, a sticky place bar
([`PlaceBar`](../../resources/js/components/place-bar.tsx)) stands at the head of the content
column: the phone header's crumb in the same pill, carrying the place's face. Home, a hub, a
member's or a group's own page and anything the sidebar's own active row already names draw none. The bar is one height on every
screen that has one, and the shell publishes it there as `--modern-place-bar`, which whatever
sticks below the bar adds to its own offset (`top-below-place-*`): a conversation's unread
banner and day indicator, and the editor's toolbar. A screen with no place bar leaves the
variable unset.

## Signed-out visitors

A guest gets the same frame with the member-only parts left out:

- No bottom tabs and no menu. The way in to `/login` stands where the menu does: at the end of
  the phone header, and in the sidebar at desk width.
- A hub keeps its heading and drops its tabs and its write action: past the list the guest is
  on, they lead behind the login wall.
- The crumb never takes the place tier, whose target is a member's page that may sit behind the
  login wall. It is the trail's last item, and it is a link only where that target is open to a
  guest (an entry's crumb to its author's entries, which lists the web-public ones); anywhere
  else it is text. The place bar draws no face for a guest. Out of room, the crumb cuts the
  name and keeps the words around it, so it still says whose entries it leads to.
- No link points at a member's page. An entry's byline leads to the author's entries, as its
  crumb does, and a commenter's name is text.
- A web-public profile is the member page with the heading and the profile fields alone
  ([member-profile.md](member-profile.md), "Key invariants").

## What the member and group pages may not do

`/member/{id}` renders `member/show` and `/groups/{group}` renders `group/show`. Both
gather several sources onto one page, under one contract:

- **Read-only projection.** The page re-arranges what the viewer already reads, through each
  source's own viewer-scoped query and at the same clearance.
- **No new capability rides the page.** Every row comes from a source the viewer already reads
  on that source's own screen, and each file is asked through
  `FilePolicy` when it is served ([`ImageController`](../../app/Http/Controllers/ImageController.php))
  — a parent's read gate is not a permission on the bytes hanging off it. The group page asks
  per file while it builds its picture rows as well.
- **Nothing is stored for it.** The page reads the rows every other screen reads and writes none
  of its own.
