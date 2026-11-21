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
screen class: the site mark, which leads home, then the name of the part of the site the reader
stands in, with the site color as a 4px line atop the row. Every name, the site's included, stands in
one column at the middle of the row in one type. Only a place's name can be pressed.

| Screen | The header reads |
|---|---|
| Home (the latest issue, or What's new where it is home) | `[mark]`, then the site name |
| A member's page, a group's page | `[mark]` alone. These pages ARE a place, and name themselves in their own hero directly below |
| A screen inside a group or a member's place | `[mark]`, then the place's name, which opens its sections ("Place sections"). A conversation stands in its counterpart's place |
| A hub, a month of issues and a past day's issue included | `[mark]`, then the hub's name. That copy is hidden from assistive technology, and the page's h1 is kept for it alone at every width: the h1 is the one announcement |
| A screen inside no place, with a context, a form included | `[mark]`, then the context's last item |
| A screen inside nothing, with no context | `[mark]` alone |

[`headerOf`](../../resources/js/lib/member-chrome.ts) makes this choice for both the header and the
place bar, and [`HeaderName`](../../resources/js/components/header-name.tsx) draws the name in both.
The header ends in the menu. There is no back control: swipe, the browser and the site
mark are the ways out.

Two decisions here are deliberate rather than pending:

- **A form's name opens nothing.** Even inside a group, the middle of a form's header is text, so
  no section list opens beside unsaved input.
- **A compose sheet keeps its own header.** A sheet is a mode rather than a screen class: its
  header is the ✕ that leaves it plus the page's own actions, and it draws no bottom tabs
  ([feature-modules.md](feature-modules.md), "Surface responsibilities").

## Place sections

A group's and a member's sections are listed in [`lib/place-sections.ts`](../../resources/js/lib/place-sections.ts),
and the registry entry of every screen inside a place, its forms aside, names the place and the
section it stands in; the page itself declares nothing. Pressing the place's name opens its sections as tiles, the
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
- **A member's sections follow the site's switches.** Diary, activity, groups and friends each
  appear while their unit is on, and the top always does. A conversation stands in the
  counterpart's place but in none of its sections, so no tile is marked.
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
from the trigger's edge, the color line and the mark holding their exact positions across open
and shut, and the close control the trigger's labelled twin — the same box in the same seat,
"close" where "menu" stood, first in the DOM as it is first on screen. Both controls carry their
word under the glyph, so neither takes a tooltip. The drawer's foot carries the profile and
sign-out rows, since the phone bars carry no account menu.

## Desk width

At `lg` and up no header stands. The site-color line is the only full-width element, over the
sidebar ([`LeftNav`](../../resources/js/components/left-nav.tsx)), and the sidebar plus the
content column center as one block. A page under `pages/` takes no `lg:` or `max-lg:` class of its own,
apart from the few listed in `eslint.config.js` that held one already: the frame and the shared
components answer to the width.

The name the phone header's middle draws stands again at the head of the content column as a sticky
place bar ([`PlaceBar`](../../resources/js/components/place-bar.tsx)) wherever `headerOf` names
something other than the site — a place, a hub or a context's last item ("The phone header") — a
compose sheet included, though its phone header is its own. A place a member can open adds its face.
Home, a member's or a group's own page and a screen inside nothing with no context draw none. A
hub's action stands at the end of the bar, as its h1 is not drawn; a screen whose h1 is drawn keeps
its action beside the h1.

The bar stands right under the site-color line from the first paint, which is where it sticks, so
scrolling never moves it, and it always draws its hairline. Its row stands where the sidebar's site
row does and its hairline runs along that row's foot, so the name, at the `bar` size on every
screen, is level with the site's name. Content starts 32px below the bar on every screen that has
one; a screen with none keeps the frame's own 32px above its content.

The bar is one height on every screen that has one, and the shell publishes it there as
`--modern-place-bar`. The bar takes its height from it; whatever sticks below the bar adds it to its
own offset (`top-below-place-*`: a conversation's unread banner and day indicator, and the editor's
toolbar); an anchor adds it to its scroll margin (`scroll-mt-below-place-*`: a month page's days);
and a focused control in `<main>` takes it as its scroll margin, so keyboard focus is never scrolled
under the bar (WCAG 2.2 2.4.11). A screen with no place bar leaves the variable unset.

## Content kinds

These take a kind's glyph from [`CONTENT_KINDS`](../../resources/js/lib/content-kinds.ts): the nav
sections, a group page's section headings, a member page's group and people headings and its diary
cards, the mark over a story in a day's issue, the rows of its month page, the notifications, and a
place's section tiles. The mark takes the kind's word from there as well, and a new kind, or a new
list that mixes kinds, takes both from there too. The mark ([`KindMark`](../../resources/js/components/kind-mark.tsx)), in a dense row as on a
card, always prints the kind's word beside the glyph, which is decorative; it is phrasing text and
never a link, so it can sit inside a row that is one link or one button. A glyph that does not
name a kind, such as a member count or a button's camera, is not read from the table.

## Signed-out visitors

A guest gets the same frame with the member-only parts left out:

- No bottom tabs and no menu. The way in to `/login` stands where the menu does: at the end of
  the phone header, and in the sidebar at desk width.
- A hub drops its write action and every tab a guest cannot open. The diary hub keeps All, the
  way back from a search.
- Inside a place the header and the place bar name it as text, with no face and no sections to
  open, since most of a member's sections, and their page unless it is web-public, sit behind
  the login wall.
- No link points at a member's page. An entry's byline leads to the author's entries, and a
  commenter's name is text.
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
