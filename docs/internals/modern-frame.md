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
| Home, a member's page | `[mark]` + the site name. A member's page IS a place, and names itself in its own hero directly below |
| A day of できごと, latest or past | `[mark]` + the site name. Its band names the day and steps to the days either side, and its foot leads to the month, so a crumb above it would be a third way to the same places |
| A hub | `[mark] › section`. The bar's copy of the title is hidden from assistive technology; the page's own h1 stays on screen and is its one announcement |
| A month of できごと | `[mark] › できごと`, as a hub reads, with no crumb: its band steps to the months either side and each day leads to its own page. The page opens on that band, so its h1 is kept for assistive technology and not drawn (`Chrome.deskHeading`) |
| A screen inside a group, its top included | `[mark]`, then the group's name in the middle of the row, which opens the group's sections ("Place sections") |
| A screen inside a member's place | `[mark] › place`, the place a pill that leads to its top |
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

## Place sections

A group's sections are listed in [`lib/place-sections.ts`](../../resources/js/lib/place-sections.ts),
and the registry entry of every screen inside a group names the group and the section it stands
in; the page itself declares nothing. Pressing the group's name opens its sections as tiles, the
current one marked: `aria-current="page"` on the section's own list, `"true"` on a screen deeper
inside it. The panel is modal: pressing outside it only closes it, even on a control the page
keeps pressable over its content, and while it is open Tab stays inside and the page does not
scroll.

- **One panel, two triggers.** The phone header's name and the desk place bar's are both mounted
  at every width, so each holds its own open state. What differs by width is the trigger and
  where the panel hangs — across the header's full width on a phone, a card under the name at
  desk width — never what it lists or how it closes. A panel open while the width crosses `lg`
  closes, and focus moves to the trigger now on screen.
- **Which of a group's boards to list is the server's.** Talk, topics and events appear only where
  the viewer may open them with their unit on (`GroupPlace::openSections`, the same answer the
  group page's own boxes take); the top and the members list always appear. A screen whose
  props say nothing lists no board.
- **A screen with no section of its own** marks the nearest: the pending and management lists
  mark Members, an event's participants mark Events. The participants page leads to its event
  from the head of its body, the one level above it that the tiles do not offer.

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
content column center as one block. With no header to carry it, the h1 a month of できごと keeps
off the phone's screen is drawn here.

On a screen deep enough to be inside somewhere, a sticky place bar
([`PlaceBar`](../../resources/js/components/place-bar.tsx)) stands at the head of the content
column: the phone header's crumb in the same pill, carrying the place's face. Inside a group, its
top included, the bar is the group's face and name, which open its sections. Home, a hub, a
member's own page and anything the sidebar's own active row already names draw none. The bar is one height on every
screen that has one, and the shell publishes it there as `--modern-place-bar`, which whatever
sticks below the bar adds to its own offset (`top-below-place-*`): a conversation's unread
banner and day indicator, and the editor's toolbar. A screen with no place bar leaves the
variable unset.

## Content kinds

These take a kind's glyph from [`CONTENT_KINDS`](../../resources/js/lib/content-kinds.ts): the nav
sections, a group page's section headings, a member page's group and people headings and its diary
cards, the mark over a story in できごと, the rows of its month page, the notifications, and a
place's section tiles. The mark takes the kind's word from there as well, and a new kind, or a new
list that mixes kinds, takes both from there too. The mark ([`KindMark`](../../resources/js/components/kind-mark.tsx)), in a dense row as on a
card, always prints the kind's word beside the glyph, which is decorative; it is phrasing text and
never a link, so it can sit inside a row that is one link or one button. A glyph that does not
name a kind, such as a member count or a button's camera, is not read from the table.

## Signed-out visitors

A guest gets the same frame with the member-only parts left out:

- No bottom tabs and no menu. The way in to `/login` stands where the menu does: at the end of
  the phone header, and in the sidebar at desk width.
- A hub keeps its heading and drops its write action and every tab a guest cannot open. The
  diary hub keeps All, the way back from a search.
- The crumb never takes the place tier, whose target is a member's page that may sit behind the
  login wall. It is the trail's last item, and it is a link only where that target is open to a
  guest (an entry's crumb to its author's entries, which lists the web-public ones); anywhere
  else it is text. The place bar draws no face for a guest. Out of room, the crumb cuts the
  name rather than the words around it, which say where it leads.
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
