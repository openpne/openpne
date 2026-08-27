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
| talk burst | the group's `topic_read_access` is `Everyone`, and at least three messages in the window | messages + authors + reactions |
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

## The window, and 休刊

An issue covers **`(previous issue's published_at, now]`** — open at the start, closed at the end.
Consecutive issues share their boundary instant, and a row written exactly on it belongs to the issue
that closed on it, not to both. The lower bound is read from the stored row rather than assumed from
the schedule, so an issue that ran late still covers exactly what the one before it did not, and the
first issue ever reaches back seven days because there is no row to read.

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

**There is no `--date`.** A window chains from the previous issue's `published_at`, so an issue dated
into the past would either overlap the one after it or claim a stretch that has already been
reported. `--dry-run` reports what an issue would hold without writing it.

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

**A withdrawn author is not a refusal.** The record stands and both serializers already draw the
byline as a withdrawn member; among a burst's faces the author is skipped instead, because a blank
face in a row of faces reads as somebody rather than as nobody. **A past event stays** on a back
issue's calendar: an issue is a snapshot of the morning it went out, and one that quietly shed its
calendar as the week went by would misreport that morning.

**A burst is resolved live, and only its window is remembered.** The count is the stretch's current
count; the way in is `?m=` on the first message still there, which is also the instant the card says
it started; the faces and pictures come from the **last day** of the stretch rather than its start —
the first issue ever reaches back a week, and a week-old glimpse of a room talking today is worse
than no glimpse.

The count-adaptive shape is decided **after** the gate, from what survived: one story is a lead
drawn whole, two or three stand abreast, four or more become a lead over rows. An issue of eight
that lost seven is an issue of one, and drawing it as a lead over an empty list would report the
seven. Every optional section key is absent rather than empty, so nothing on the page has to decide
what `[]` means.

## Routes

`/home/issues` is the run of them and `/home/{y}/{m}/{d}` is one day's, both Modern-only: OpenPNE 3
had no such page, so they render Inertia whatever surface the reader is on. The day is validated
before it is looked for — a route pattern admits `2026/02/30`, and a day that never happened must
read as nothing rather than as a query. `/` still lands on the dashboard; the cutover follows.

## Later

- **The pin as an admin setting.** The publisher already takes one; nothing writes it yet.
- **A per-viewer catch-up cursor**, so a member returning after a week sees which issues are new to
  them. A copied-value column with no foreign key, the shape `group_members.talk_read_*` already
  uses ([group-talk.md](group-talk.md)) — not a [`PreferenceKey`](member-preferences.md), which is
  for choices a member makes rather than a position they leave behind.
- **Birthdays**, which are a calendar band with a privacy question of their own.
- **A photo of the day**, once picture eligibility can be answered per file rather than per post.
