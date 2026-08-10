# Date and time display

Which shape a date takes on screen, and why. The clock itself — `APP_TIMEZONE`, how it reaches the
client, how instants are stored — is [runtime.md](runtime.md).

Applies to the Modern surface. Classic keeps OpenPNE 3's formats: its value to a migrating site is
that screens do not move under them. The admin panel still renders Filament's own defaults — down to
seconds in places — and is not yet held to this document.

## Rules

1. **Seconds never appear in a display.** They are in `dateTime` and in the hover title of an
   abbreviated shape; nothing a reader acts on needs them.
2. **The hour is always two digits** (`00:05`, not `0:05`). `H:mm` is CLDR's Japanese default and is
   not wrong, but padding makes a column of times line up, and it is what Classic already renders.
3. **A year the reader is already in is not shown.** The test is calendar-year equality on the site's
   clock, not "within twelve months" — on 2 January, last 31 December must still carry its year.
4. **One unit of meaning per stamp.** A list row shows what distinguishes it from its neighbours, not
   everything known about it.
5. **Machines get the whole value.** Every stamp renders as `<time dateTime="…">` carrying the
   offset-bearing ISO, whatever the visible text says.

## Shapes

| Shape | Japanese | Used for |
|-------|----------|----------|
| `absolute` | `2026年8月10日 00:05` | The one thing a page is about — a diary entry, a message, a topic, an event, a timeline post — and any row with no page of its own, which is every comment and reply. It gets read out of context, so the year stays. |
| `listStamp` | today `00:05` · this year `8月10日` · older `2025年12月31日` | A row that links to a page naming its date in full: list entries, notifications, feed cards. |
| civil date | `2026年8月10日`, with weekday `2026年8月10日(月)` | A calendar day with no instant behind it. The weekday is on wherever an event's own dates appear, so the same datum never shows in two shapes. |

`listStamp` carries the full value as a `title`; `absolute` does not, since it already names the day
and the minute and a title differing only in seconds is noise.

**That title is a mouse-only convenience and must stay non-essential.** `title` on a non-interactive
element reaches neither the keyboard nor assistive technology, so it can only ever be a shortcut to
something already reachable another way.

What makes that acceptable is a condition on where `listStamp` may be used, not a general licence:
**only on a row that links to a page naming its date in full.** The reader's task in such a list is to
pick a row; the abbreviated stamp orders the rows, and the page behind it holds the whole value for
anyone — keyboard and screen-reader users included, not only those who can hover.

That condition is what puts comments and replies on `absolute` instead. They have no page of their own,
so an abbreviated stamp there would leave the full date reachable by mouse alone.

A shape whose visible text stops naming the date on a row with nowhere to go would need a real
disclosure. Relative time is the case that forces that question, and it does not ship here.

## Key invariants

1. **Instants and civil dates are different types with different components.** `<Timestamp>` takes an
   offset-bearing ISO; `<CivilDate>` takes `Y-m-d`. Rendering a civil date as an instant shifts it a
   day for viewers west of the site, so the two are separate rather than one component with a flag,
   and a value handed to the wrong one renders verbatim rather than shifted.
2. **Every boundary is the site's, not the viewer's.** "Today" and "this year" are computed on the
   site's calendar, so one row reads the same for every reader wherever they are.
3. **A shape that depends on the clock re-reads it.** `listStamp` is relative to today, so one shared
   timer per page wakes at the site's next midnight and on return to a backgrounded tab
   (`use-site-day.ts`). Without it a page left open past midnight would keep yesterday's rows showing a
   bare time, and one left open over New Year would keep last year's rows without their year. Any future
   now-dependent shape has to subscribe to the same clock.
4. **Formatting lives in one module.** eslint keeps `Intl` and `toLocale*` inside
   `resources/js/lib/date.ts` and the raw formatters importable only by `useDateFormat`, so a new call
   site cannot reintroduce per-viewer drift.
