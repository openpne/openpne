# Date and time display

Which shape a date takes on screen, and why. The clock itself — `APP_TIMEZONE`, how it reaches the
client, how instants are stored — is [runtime.md](runtime.md).

Applies to the Modern surface and the admin panel, which have different readers and so different
shapes — see [Admin](#admin). Classic keeps OpenPNE 3's formats: its value to a migrating site is that
screens do not move under them.

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
6. **The weekday is shown where the reader is placing the day in their own week** — planning an event,
   or finding their way back through a conversation. Not on a list row, whose reader is only telling it
   from the rows around it.

## Shapes

| Shape | Japanese | Used for |
|-------|----------|----------|
| `absolute` | `2026年8月10日 00:05` | The one thing a page is about — a diary entry, a topic, an event, a timeline post. It gets linked to and read out of context, so the year stays. |
| `listStamp` | today `00:05` · this year `8月10日` · older `2025年12月31日` | A row whose reader is placing it on a calendar: diary and topic lists, the message inbox, community activity. |
| `relative` | `たったいま` · `3分前` · `5時間前` · `2日前`, then `listStamp` | A row whose reader is asking what is new: notifications, timeline posts and replies, comments. |
| `clockTime` | `00:05` | A message in a conversation, whose day is named by the heading above it rather than by the row. |
| civil date | `2026年8月10日`, with weekday `2026年8月10日(月)` | A calendar day with no instant behind it. Wherever an event's own dates appear they appear with the weekday, so the same datum never shows in two shapes. |

Every shape but `absolute` carries the full value as a `title`; `absolute` does not, since it already
names the day and the minute and a title differing only in seconds is noise.

### A conversation

A conversation is the one place a date is split across two elements. The rows of a day sit under a
**date heading** — `今日`, `昨日`, then the day written out with its weekday (`8月12日(水)`,
`2025年12月31日(水)`) — and each row carries `clockTime` alone. That is rule 4 taken as far as it goes:
the day is one unit of meaning and it is said once for the stretch it covers, so a row is left with
only the part that tells it from the row above.

**The heading carries the weekday**, which no shape in the table above does. Rule 6 is why: a reader
scrolling back through a conversation is placing the day in their own week. It costs nothing per row,
because the day is said once for the whole run under the heading. Today and yesterday stay words —
`今日` is a more precise answer than a weekday, not a less precise one.

**The year follows rule 3**, so a heading in the current year reads `8月12日(水)` and an older one
`2025年12月31日(水)`. An event's civil date always carries its year instead, because planning crosses
years; the two are different questions about the same-looking value.


`clockTime` rather than `listStamp`, whose today branch renders the same string: a list stamp's shape
depends on the clock, so a conversation left open across the site's midnight would see every row turn
from `00:05` into `8月12日` — the shared day clock in invariant 3 exists to do exactly that. A
conversation's rows are placed by their heading and never change shape.

### How long ago

```
under a minute   たったいま
under an hour    N分前
under a day      N時間前
under a week     N日前
beyond that      the listStamp shape
```

Two things about that are deliberate:

- **Days are calendar days on the site's clock, not elapsed time divided by 24.** Saturday 11:00 read on
  Monday 10:00 is 47 hours; floor division calls that one day ago, but Saturday is two days before
  Monday. Hours below a day stay on elapsed time, so yesterday 23:00 read at 01:00 is still `2時間前`.
  Where the two disagree — a day longer than 24 hours, so still the same date after 24 hours elapsed —
  hours win and read `24時間前`, because `0日前` is not a reading of anything.
- **One unit, no "about".** `3日4時間前` and `約3時間前` both say less than `3日前` does.

A week is where it stops. Past that the reader is no longer placing the row against now, and a count of
days becomes work to decode rather than a shortcut.

The Classic timeline is the one exception, because it keeps what OpenPNE 3 showed: `jquery.timeago`'s
ladder and words (1分前 … N年前, no cap), counted in the browser by
[`classic-timeago.js`](../../public/js/classic-timeago.js) from a `data-datetime` and counted again
each minute. Without the script the absolute datetime stands — it is the span's text and its title.

**That title is a convenience with no contract on it.** `title` on a non-interactive element reaches
neither the keyboard nor assistive technology, so nothing may depend on it.

Which is fine here, because neither shape withholds anything: **in a time-ordered list the abbreviated
stamp is the whole content.** The reader's task is to place a row relative to the others, and what the
stamp leaves out is what their position in the list already tells them. `dateTime` carries the exact
instant for machines. Nobody — hovering or not — needs the second a row was written.

`relative` is the same call taken one step further, and the window is what makes it safe: it only ever
replaces a date that is less than a week old, and past a week the date comes back. Reaching for the
exact day of something from three days ago is not a task the design owes a keyboard path.

## Admin

| | Format |
|---|---|
| An instant | `2026-08-10 09:05` |
| A civil date | `2026-08-14` |

Sortable digits rather than the locale's narrative form, because the reader is different: an operator
scans and compares rows, and `2026-08-10 09:05` lines up in a column where `2026年8月10日` does not.
The two rules the member surface sets still hold — no seconds, and a civil date never grows a time.

**No relative time anywhere in admin.** "3 days ago" cannot be filtered by period or compared between
two rows, which is most of what an operator does with a timestamp. Cloudscape names the same reason.

The format is Filament's panel-wide default (`AdminPanelProvider::boot`), not an argument per column, so
a screen added later inherits it; a column passing its own is a test failure. Timezone needs no wiring —
Filament resolves it through `FilamentTimezone`, which falls back to `config('app.timezone')`, the same
site clock the member surfaces render in.

## Key invariants

1. **Instants and civil dates are different types with different components.** `<Timestamp>` takes an
   offset-bearing ISO; `<CivilDate>` takes `Y-m-d`. Rendering a civil date as an instant shifts it a
   day for viewers west of the site, so the two are separate rather than one component with a flag,
   and a value handed to the wrong one renders verbatim rather than shifted. A conversation's date
   heading is the third element that emits a civil date, and has to be its own: it derives the day
   from an instant, and its visible text is `今日` where the machine value stays that day.
2. **Every boundary is the site's, not the viewer's.** "Today" and "this year" are computed on the
   site's calendar, so one row reads the same for every reader wherever they are.
3. **A shape that depends on the clock re-reads it**, on two timers with different shapes.
   - **The site's day boundary is shared.** One timer per page (`use-site-day.ts`) wakes at the site's
     next midnight and on return to a backgrounded tab, whose timers are throttled or suspended. It is
     what turns today's `00:05` into `8月10日`, last year's rows into dated ones, and `6日前` into a
     date. Shared because every stamp crosses that boundary at the same instant. That next boundary is
     found by bisection rather than arithmetic, because neither shortcut survives a DST transition:
     counting the remaining wall-clock seconds out of 86400 lands an hour late on a 23-hour day, and
     converting the next local `00:00` to an instant fails where that wall time does not exist at all
     (in America/Santiago on 2026-09-06 the clock goes 23:59 → 01:00). What is always well defined is
     the first instant whose site date differs from now's, so that is what is bracketed and bisected.
   - **A relative stamp's own boundary is per stamp** (`use-relative-refresh.ts`), because each one
     reaches its next minute or hour at its own moment. Only the one boundary ahead is armed, so a
     twenty-row list holds twenty pending timers and fires each once, rather than everything waking on a
     common tick. Nothing is armed for the other presets, and nothing past a day — the day clock has it.

   Two details keep those two honest. The component reads the clock **once** per render and uses that
   one reading for the text and for the boundary: two readings can straddle a boundary, and then the
   timer waits for the one *after* the text has already changed. And what it hands the hook is a
   **deadline, not a delay** — a delay measured after commit has the same problem, so the hook arms
   `deadline - now` and refreshes immediately when that has already gone by.
4. **Formatting lives in one module.** eslint keeps `Intl` and `toLocale*` inside
   `resources/js/lib/date.ts` and the raw formatters importable only by `useDateFormat`, so a new call
   site cannot reintroduce per-viewer drift.
