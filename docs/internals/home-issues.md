# Home issues

Once a day the site publishes an **issue**: a front page of what has happened since the last one.
It is built by a scheduled command, stored as a numbered row plus a ledger of what it featured, and
re-resolved from its sources every time it is read.

## An issue is a ledger, never a copy

[`home_issue_items`](../../database/migrations/2026_08_27_000002_create_home_issue_items_table.php)
holds a section, a rank inside it, and a reference to the source — a morph alias and an id, no title,
no body, no excerpt. **Derived text is never shown without its source.** The page resolves every row
back through that source's own gate at render time, so an issue shows what the reader may see now
rather than what was true the night it was built: a diary made private after publication renders as
nothing, and so does a deleted one.

That is also why `source_id` carries no foreign key and why these rows are **not** swept when their
source goes. A dangling reference renders as nothing, and it is exactly what the never-again rule has
to remember — sweeping it would let a deleted-and-reposted item lead the page a second time.

## Eligibility: every member may read it

An issue has no viewer. It is one page, the same for everybody, so the only content it may carry is
content **every signed-in member may read**. Each source answers that with the rule its own feature
already uses, minus the per-viewer half (a block is a relation between two members, and the publisher
is neither of them — the page applies the reader's blocks when it resolves the row).

| Source | Eligible when | Score |
|---|---|---|
| [`TimelinePost`](../../app/Models/TimelinePost.php) | top-level (`in_reply_to_id` null) and `visibility <= Members` — [`TimelineFeedScope::applyMembersOnly`](../../app/Features/Timeline/TimelineFeedScope.php) | replies |
| [`Diary`](../../app/Models/Diary.php) | `visibility <= Members` — [`DiaryVisibilityScope::applyFeed`](../../app/Features/Diary/DiaryVisibilityScope.php) | comments |
| [`GroupTopic`](../../app/Models/GroupTopic.php) | its group's `topic_read_access` is `Everyone` | comments |
| [`GroupEvent`](../../app/Models/GroupEvent.php) | same | comments + RSVPs |
| talk burst | the group's `topic_read_access` is `Everyone`, and a message in the window — one is enough, the score ranks rooms rather than admitting them | messages + authors + reactions |
| [`Member`](../../app/Models/Member.php) | joined in the window | — |
| [`Group`](../../app/Models/Group.php) | founded in the window | — |

Two of those rows are worth stating plainly.

**An event scores its RSVPs as well as its comments**, because a gathering draws interest in two ways
and one of them is silent: fifty people signing up without saying a word is the bigger news.

**A new group is eligible whatever its read access.** `topic_read_access` gates a group's *contents*,
and this section shows none of them — the group page itself is open to any signed-in member
([`GroupPolicy::view`](../../app/Policies/GroupPolicy.php) is unconditionally true), so a new
members-only group is a door to knock on rather than something withheld.

**`is_default` is not the predicate.** It marks a group OpenPNE 3 enrolled every new member into at
registration, and in OpenPNE 4 nothing but the admin table reads it. Either way it says who is *in* a
group, never who may *read* one: a default group can be members-only and an ordinary one can be open
to the whole site. Using it here would answer a question nobody asked.

Newcomers include AI accounts and members an administrator has banned from logging in, matching the
member lists an issue sits beside — `is_login_rejected` gates logging in and receiving, not being
seen ([`RandomMembers`](../../app/Features/Member/Queries/RandomMembers.php)). `members.created_at`
is nullable, and a null instant is in no window, so a row from before the column was stamped stays
out on the range alone.

## A day runs 06:00 → 06:00

**A day of happenings is not a calendar day.** It starts when the issue goes out and runs to the
next one, so what a reader is handed at 06:00 is everything since 06:00 the morning before.
[`HomeIssueDay::of()`](../../app/Features/Home/Data/HomeIssueDay.php) is that rule as arithmetic —
the instant, less the publishing hour, to its own midnight — and a window `(start, end]` covers the
days `of(start)` … `of(end − 1s)`.

**`issue_date` is the last day the window covers**, which is what the page is titled and addressed
by. The scheduled 06:00 run on the 28th covers `(27th 06:00, 28th 06:00]` and is therefore **the
27th's issue**; the first issue ever spans seven days and takes the last of them. Dating either by
the day the run happened would headline yesterday evening's posts with today's date, which is the
one thing a dateline may not do.

**A window closes on a boundary, never on the clock.** The scheduled run lands a second or two past
06:00, and an issue closed at 06:00:01 would be dated the day it went out and leave the next window a
second short of a day; so the scheduled window closes on the last 06:00 at or before the run
([`PublishHomeIssue::window`](../../app/Features/Home/Actions/PublishHomeIssue.php)), and
`published_at` is always a boundary. A run by hand at 18:00 therefore publishes the same issue the
schedule would have — the day that closed at 06:00 — and finds it already published if the schedule
ran; what has happened since waits for the next issue.

Idempotency is unchanged and still reads "one issue per `issue_date`": the publisher looks for that
day before planning, and the unique settles a race.

The page reads the same rule twice more. Its masthead names the days the window covers — one day
usually, a range when it reached further back — and its colophon states the two instants behind
them, because a reader seeing last evening under yesterday's date needs to see where the day was
cut. And "current" means the issue is the last one that could have come out (`HomeIssueDay::latest`),
not that it is dated today: no fresh issue ever is, and comparing against the calendar would have
every front page announce itself as stale.

## The window, and 休刊

An issue covers **`(previous issue's published_at, the last 06:00 boundary]`** — open at the start,
closed at the end. Consecutive issues share their boundary instant, and a row written exactly on it
belongs to the issue that closed on it, not to both. The lower bound is read from the stored row
rather than assumed from the schedule, so an issue that ran late still covers exactly what the one
before it did not, and the first issue ever reaches back seven days because there is no row to read.

What is eligible is what was **born** in the window: the source's own `created_at`. An old post that
drew comments this week is not news again — the comments are, and they are not what the ledger
features. The score is then the cumulative count at publish time, so an old-but-still-busy thread
cannot win a place it is not eligible for.

**A quiet day gets no issue** (休刊). If stories, talk, newcomers and new groups are all empty, the
publisher writes nothing and the next run's window simply spans the gap. Upcoming events do not
count: the calendar is not news, and the same gathering is worth listing every day until it happens,
so an issue it could trigger would come out every day of a quiet month saying nothing new.

## Never again — per section

A non-recurring section never features a source twice, across every issue there has ever been. The
memory is the ledger itself, read by
[`HomeIssueLedger::excludeFeatured`](../../app/Features/Home/HomeIssueLedger.php), and every
candidate query of such a section applies it.

**The rule is scoped to the section, always**, because the bands ask different questions about the
same row. A group featured for being new is still news for what was said in it; an event that led a
story is still worth listing on the calendar. [`HomeIssueSection::recurs()`](../../app/Features/Home/HomeIssueSection.php)
says which sections keep no memory at all — talk and the calendar — and asking the ledger about one
of those is an error rather than a question with an answer.

## Ranking, caps and the tiebreak

Each section has a cap (`HomeIssueSection::cap()`): 8 stories, 3 talk, 12 newcomers, 6 new groups,
6 upcoming events. It is a cap, not a target — a quiet day publishes fewer and the page never pads.

Stories are four separate queries merged into one band. Each kind is asked for the **whole** cap, so
the merged top-8 is exact; asking for a share each would cap a quiet kind's best story out to make
room for a busier kind's eighth. The merged order is **score descending, then the newer one, then the
higher id**, and rank 1 is the lead. Newcomers and new groups are newest-first and carry no score.

The calendar is soonest-first and runs from the **publish day's own midnight** to seven days out.
`open_date` is a date, not an instant, so bounding it at the 06:00 publishing time would drop the
day's own events — and an event today is still ahead of the reader, whose join window runs to the day
after it ([`GroupEvent::isClosed`](../../app/Models/GroupEvent.php)).

An operator may **pin** one story to the lead. A pin ignores the window and the ledger — that is what
pinning is for — but not eligibility: it must exist, its unit must be on, and every member must be
able to read it, or it is dropped and the plan reports it. The algorithmic stories shift down under
it, the cap still holds, and a pin the algorithm also chose is not featured twice.

## Frozen stats are provenance

`score` and `stats` record why a row outranked another on the night it was chosen. They are **never
displayed and never re-read as current truth** — the page reads live counts from the source, like
every other screen. The shape follows the source: `{replies}`, `{comments, images}`, `{comments}`,
`{comments, participants}`, `{messages, authors, reactions, since, until}` for a burst, `{}` for a
member, `{members}` for a group.

A talk burst stores no message id at all, only the window it describes. The page re-resolves the
stretch live through [`TalkSampleDigest`](../../app/Features/GroupTalk/Queries/TalkSampleDigest.php),
which is what lets a deleted message simply not be there instead of leaving a hole.

## Units gate twice, from one map

[`HomeIssueSection::unit()`](../../app/Features/Home/HomeIssueSection.php) maps each (section, source)
pair to the [feature unit](feature-toggles.md) that owns it — the *pair*, because a group is gated by
group talk when it is here for what was said in it and by groups themselves when it is here for being
new. A switched-off unit contributes nothing **and runs no query**, the same shape the dashboard uses.

The map is read again at render time, so an administrator switching a unit off after publication
hides its rows without touching the ledger — and switching it back on brings them back, which is what
a feature toggle promises everywhere else.

## Schedule and idempotency

[`openpne:publish-home-issue`](../../app/Console/Commands/PublishHomeIssueCommand.php) runs daily at
06:00 on the site's clock ([`routes/console.php`](../../routes/console.php),
[runtime.md](runtime.md#scheduled-tasks)). It runs inline and in the foreground: a handful of capped
reads and one insert, seconds rather than minutes.

Nothing guards a double run but the database. `home_issues.issue_date` is unique, and that uniqueness
**is** the guarantee: a second run the same day finds the issue and writes nothing, and two runs that
overlap resolve by one of them failing its write and reporting what the other wrote. There is no lock
to hold, no job to deduplicate, and nothing to unwind. The issue and its whole ledger go in one
transaction, because an issue with half a ledger is not an issue.

The two engines lose that race differently. MySQL's loser reaches the insert and violates the unique;
SQLite compiles `lockForUpdate` away, serializes the writers itself, and refuses the loser with
`SQLITE_BUSY` — so the write is retried, which re-reads the number and turns the second shape into the
first. Whichever arrives, the loser answers with the issue the winner published, and a failed write
with no issue for the day stays loud.

`--date=YYYY-MM-DD` publishes a past day from **its own** window — `(D 06:00, D+1 06:00]`, with
`published_at` at the end of it — rather than chaining from the last issue. It refuses a day that is
not over (today included: today's 06:00 boundary lies ahead), a day that already has an issue, and a
date that is not one, each with a non-zero exit so a script can read the answer. It also refuses a day
already inside a published issue's window: a backfill names every day it wants, so it cannot re-report
a stretch an issue already covers — and a chained window is as long as the gap it closed, the first
one a week. `--dry-run` reports what an issue would hold without writing it, with or without a date.

Backfilling an archive therefore runs **oldest day first, on an empty ledger**: the never-again rule
is not date-aware, so a day filled in after a later one can only feature what that later one left.

[`openpne:rebuild-home-issues`](../../app/Console/Commands/RebuildHomeIssuesCommand.php) is that
backfill applied to days already published, for when what qualifies has changed: an issue is a ledger
of what the rules admitted on the day it was written, and it does not re-read them. From `--from`
(by default the day the earliest issue's window opens in) to the last day that has closed, it drops
every issue and republishes each day over its own window, oldest first, so the ledger is rebuilt in
the order it would have been written and the numbers count on from the issues left standing — a day
that was blank and now is not takes the number its date falls on, and every later issue moves up
one. A chained issue inside the stretch becomes one issue per day; an issue reaching back past
`--from` is refused with the day to name instead — which, for an archive whose issues closed off the
boundary (published before windows snapped to it), is every day back to the first: rebuild such an
archive whole. The whole rebuild is one transaction, which is what makes `--dry-run` exact: it runs
the rebuild, numbers included, and rolls it back. Nothing locks out the schedule, so run it away
from 06:00.

This is not OpenPNE 3's `daily_news_day`, which was a digest **mailed** to members on administrator-
chosen weekdays; that is [not ported](../../app/Support/SnsSettingKey.php). An issue is a page on the
site, published every day there is something to say.

## Rendering

Reading an issue is the ledger asked again. [`ShowHomeIssue`](../../app/Features/Home/Queries/ShowHomeIssue.php)
loads the rows, resolves the sources one read per table, and puts every row through
[`HomeItemGate`](../../app/Features/Home/HomeItemGate.php) — the source's own rule, for the member in
front of it. A row that does not answer is **dropped in silence**: no placeholder, no gap, and no
count of what was withheld, because any of those would report the existence of something the reader
may not know exists.

| Dropped when | Because |
|---|---|
| the section does not hold the row's alias | a bad row is a bug in whatever wrote it, and the front page is not where to raise it — [`unit()`](../../app/Features/Home/HomeIssueSection.php) would throw on the pair |
| the source is gone | the row outlives it by design (above); a dangling reference renders as nothing |
| the unit is off | the same map, read a second time |
| a post is a reply, or is above `Members` | it is not what the section promised |
| a diary is above `Members` | same |
| the author blocks the reader | `TimelineAccess` / `DiaryAccess`, which is the half the publisher could not apply |
| a board's group is no longer `Everyone` | "every member may read it" — so it drops for a member of that group too |
| a newcomer blocks the reader | [`MemberPolicy::access`](../../app/Policies/MemberPolicy.php), 404-shaped everywhere else |
| a burst's group is no longer `Everyone` | the one gate: an Everyone group's talk is readable by any member ([`GroupTalkAccess`](../../app/Features/GroupTalk/GroupTalkAccess.php)), so nothing follows it |
| a burst has no surviving message | the stretch is what the row is about, and an empty one is nothing to report |

**A withdrawn author is not a refusal.** The record stands and every shape here draws the byline as a
withdrawn member, a message in a talk excerpt included. **A past event stays** on a back
issue's calendar: an issue is a snapshot of the morning it went out, and one that quietly shed its
calendar as the week went by would misreport that morning.

**A story is a headline, a dek and a picture — never a body.** A front page is where a reader
chooses; the place to read a story is its own page. So nothing a body would need travels with a
story: no rendered HTML, no entity ranges, and no [link card](link-cards.md), which previews a link
the reader has not been shown and has no body here to be shown in. The dek is the body's opening as
plain text, cut server-side to a display width of 180 — a URL in it reads as the text it is.

What ranks a story is the **room it gets**, not how much of it is printed: rank 1 leads across the
width over a 16:9 picture, ranks 2 and 3 are a pair of cards at 4:3, and the rest are rows with the
picture as a square beside the words. A story with no picture keeps its rank and reads a line
further. The payload is still one ranked list in one shape — the placement is the page's decision.

**Each block is one link**, stretched over the whole block ([`stretchedLink`](../../resources/js/components/ui/surface.tsx))
and named by its headline, so nothing inside it may be a link of its own: a byline names rather than
navigates. A post has no title, so its opening line is the headline and the dek is what is left after
it — and a post that opens on a blank line is headlined by its words instead, because a link named by
nothing cannot be announced.

**A burst is resolved live, and only its window is remembered.** The count is the stretch's current
count and the way in is `?m=` on the first message still there. What is printed is the **last six
messages of the window**, oldest first, each drawn as the room draws it — author, time, body with
its mentions, and the pictures the reader may have, gated per file the same way a glimpse is
([`TalkSampleDigest`](../../app/Features/GroupTalk/Queries/TalkSampleDigest.php)). The tail and not
the head: the first issue ever reaches back a week, and a week-old opening describes a room that has
since moved on. There is no separate row of faces and no picture grid — an excerpt carries both.

Every optional section key is absent rather than empty, so nothing on the page has to decide what
`[]` means, and an issue of eight stories that lost seven is simply an issue of one.

## Routes

`/` is the latest issue under a Modern surface — the front page is the newest one, whatever day it
covers, and its pager only goes back. `/home/issues` is the run of them and `/home/{y}/{m}/{d}` is
one day's, both Modern-only: OpenPNE 3 had no such page, so they render Inertia whatever surface the
reader is on. The day is validated before it is looked for — a route pattern admits `2026/02/30`,
and a day that never happened must read as nothing rather than as a query.

## Later

- **The pin as an admin setting.** The publisher already takes one; nothing writes it yet.
- **A per-viewer catch-up cursor**, so a member returning after a week sees which issues are new to
  them. A copied-value column with no foreign key, the shape `group_members.talk_read_*` already
  uses ([group-talk.md](group-talk.md)) — not a [`PreferenceKey`](member-preferences.md), which is
  for choices a member makes rather than a position they leave behind.
- **Birthdays**, which are a calendar band with a privacy question of their own.
- **A photo of the day**, once picture eligibility can be answered per file rather than per post.
