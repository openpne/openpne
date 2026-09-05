# Diaries

A diary is a member's titled entry with a numbered comment thread, ported from OpenPNE 3's
`opDiaryPlugin`. Who may read one is the reference case for
[feature-modules.md](feature-modules.md#authorization-and-visibility): the query scope
([`DiaryVisibilityScope`](../../app/Features/Diary/DiaryVisibilityScope.php)) and its row-level twin
([`DiaryAccess`](../../app/Features/Diary/DiaryAccess.php)) answer the same rule, and the web-public
switch is read by both rather than by the controller.

## Comment numbering

`number` is the per-diary sequence a reader cites. [`CreateComment`](../../app/Features/Diary/Actions/CreateComment.php)
takes `max(number) + 1` after locking the parent **diary** row: an empty thread has no comment row to
serialize on, so two commenters would both claim 1.

That lock is the only guard. There is no unique index on `(diary_id, number)` — OpenPNE 3's own index
is not unique and migrated data may already carry duplicates, so a constraint would refuse them at
upgrade time. The writers to keep that true are `CreateComment` (which every surface goes through,
the MCP tool included) and [`DiaryCommentUpgrade`](../../app/Upgrade/Steps/DiaryCommentUpgrade.php),
which copies OpenPNE 3's numbers verbatim rather than re-deriving them.

Deleting a comment leaves the surviving numbers alone, as OpenPNE 3 did: a number is what was cited,
not a position in a dense sequence.

## The thread pages by number

[`DiaryCommentThread`](../../app/Features/Diary/DiaryCommentThread.php) ports
`sfReversibleDoctrinePager` as OpenPNE 3's `diaryComment` list component configured it: paging by
**`number`** (`setSqlOrderColumn('number')`), at a size the reader picks from 20 or 100, with a
reversible order. The default (DESC) fetches the newest page first but always lists a page
oldest-first, and `order=asc` walks from the first comment; "Older" and "Newer" therefore follow
comment age rather than page index, so they read the same in either order.

The boards page by `id` instead, and for their own reason
([group-boards.md](group-boards.md#comment-threads-page-by-id)) — the two pagers are ports of two
different OpenPNE 3 configurations, not one shape that drifted.

Modern's show page does not use this pager: it serializes the whole thread in `number` order.

## The comment-history box

OpenPNE 3 kept a `diary_comment_update` subscription table, written by `PluginDiaryComment::postSave`
only when the commenter is not the diary's owner. That table is not ported, so
[`DiaryCommentHistory`](../../app/Features/Diary/Queries/DiaryCommentHistory.php) derives the same set
from `diary_comments`: the candidates are diaries the viewer commented on that are not their own, and
`last_comment_time` is the `MAX` over **non-owner** comments only, so an owner's follow-up does not
lift their own entry in someone else's box.

A withdrawn commenter's `member_id` is NULL, and on a surviving diary such a row is necessarily a
non-owner comment (an owner's withdrawal takes their diaries with it). The `MAX` therefore tests
`member_id IS NULL OR member_id != diaries.member_id`: a bare `!=` would drop the NULL row under SQL
three-valued logic and rewind the box's time.

**Divergence from OpenPNE 3:** the subscription table never rewound when a comment was deleted. The
derived form recomputes from the surviving rows, so a diary drops out once the viewer has no comment
left on it, and deleting whichever non-owner comment was latest moves the box's time back to the one
before it.

## The archive

[`ArchivePeriod`](../../app/Features/Diary/ArchivePeriod.php) is a whole month or a single day as a
half-open `[start, end)` range, which is the shape OpenPNE 3's `addDateQuery` used
(`created_at >= begin AND created_at < end`). A date a calendar does not have (`2026-02-30`) yields
null so the caller can 404.

Two things that follow the author rather than the page:

- **A guest's eligibility is the author's**, not the window's:
  [`HasWebPublicDiary`](../../app/Features/Diary/Queries/HasWebPublicDiary.php) (OpenPNE 3
  `hasOpenDiary`) is asked before the date is read, so an empty month on an author who does publish
  still renders instead of bouncing the visitor to login.
- **The month counts are extracted from the stored `created_at`** with the engine's own year-month
  function, so a diary counted in a month also falls inside that month's `ArchivePeriod` window — the
  Modern grid's cell count and the month archive's list length cannot disagree.

The sidemenu calendar ([`DiaryCalendar`](../../app/Features/Diary/DiaryCalendar.php)) is Sunday-first
with null padding cells, and its previous/next navigation is unbounded, as OpenPNE 3's was.
