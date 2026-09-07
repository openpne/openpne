# Upgrade from OpenPNE 3

Internals of `openpne:upgrade-from-3` / `openpne:verify-upgrade` (`app/Upgrade`). The operator
guide is [upgrading-from-openpne3.md](../upgrading-from-openpne3.md).

## Steps

A step (`App\Upgrade\UpgradeStep`) maps one OpenPNE 3 table onto one OpenPNE 4 table and compiles
to a single `INSERT ... SELECT` (`InsertSelectCompiler`). The mapping is typed PHP so a CASE reads
the runtime enum it must agree with. A table other than the step's FROM is read by correlated
subquery; its name is wrapped in `SourceRef::table()` so `--source-prefix` / `--source-database`
reach it, and the FROM table is aliased to its bare name so subqueries can correlate on it. Where
both sides have them, ids and timestamps copy verbatim, so the FK graph resolves without a remap and
post dates survive; a target column with no OpenPNE 3 source (a surrogate id, timestamps OpenPNE 3
never kept) relies on its default, as each step's `targetDefaults()` records (`gaps()` is the
reverse: source columns or tables with no target).
`FileUpgrade::ownedFileReferences()` drives both the owner CASE and the audit, so an owning table
cannot be wired into one without the other.

`StepRegistry::classes()` is the run order (FK order: `files` first, image join rows last).
`tests/Feature/Upgrade/UpgradeMatrixAuditTest.php` pins every source column to a mapping or a
`gaps()` entry, every target column to a mapping, `targetDefaults()` or `pendingTargets()`, and
every `file` / `member` FK to a treatment.

## Activity threads

OpenPNE 3 `activity_data` is the timeline: hand-written posts, their replies, community-scoped posts
(`foreign_table = 'community'`) and the template lines a plugin wrote when a diary, topic or event
was created. Every SQL that routes a row lives in
[`ActivityThread`](../../app/Upgrade/Steps/ActivityThread.php) and reads the row's **thread root**:

- A row with no parent, or whose parent the source no longer has, starts a thread and is its own
  root. The root's `foreign_table` decides where the whole thread lands — `NULL` on the timeline
  (`TimelinePostUpgrade` for the starters, `TimelineReplyUpgrade` for the replies, split because
  `timeline_posts.in_reply_to_id` is a cascading self-FK), `'community'` in that group's talk
  (`GroupMessageUpgrade`, starters and replies in one step: the lineage column has carried no FK
  since `2026_08_18_000001` dropped it). A reply's own scope is ignored; `ActivityPreflight` counts the ones that differ from their root.
- A reply attaches to the root, never to another reply, and takes the root's `public_flag`: the
  OpenPNE 4 thread is flat and gated as one audience, and OpenPNE 3 listed a reply under its root's
  flag as well. The root is walked up in fixed SQL to `ActivityThread::MAX_DEPTH` hops; a deeper
  chain is not migrated and is counted by the preflight.
- A community thread lands only when the community still exists and its root was for every member
  (`public_flag = 1`): talk has no per-message audience, so a friends-only or private community post
  has nowhere to land and is counted instead. A root with any other `foreign_table` is counted and
  not migrated.
- `public_flag` is the activity scale, `0` open / `1` members / `2` friends / `3` private — the
  identity onto `Visibility`, pinned by `visibilityCase()` — not the diary scale
  `Visibility::fromOpenPne3PublicFlag()` reads, where Open is `4`.
- `activity_image` rows follow their activity (`TimelinePostImageUpgrade` /
  `GroupMessageImageUpgrade`, both `ActivityImageUpgrade`), numbered 1..N by id among the file-backed rows; a URL-only image has
  no OpenPNE 4 form. `FileUpgrade` owns the file as `timelinePost` or `groupMessage` by the same
  routing, so the two arms of one source column are keyed `activity_image.file_id#<type>`.
- `activity_data.member_id` is `REFUSE`d over the union of what both landings copy (its ledger
  `scope`), since three steps select FROM the table under different filters.

Template rows (`template IS NOT NULL`) copy their stored body like any other and are rewritten
afterwards by the `ActivityTemplateTransform` pass (below).

## Members who never activated

OpenPNE 3 `member.is_active = 0` is a registration that never completed: `MemberTable::createPre()`
writes the row when a signup link or admin invite is issued, and `opAuthAdapter::activate()` flips
it on the final step. `opActivateBehavior` appends `is_active = 1 OR is_active IS NULL` to every
Member DQL SELECT and `isSNSMember()` is the same flag, so the row is invisible and unusable in
OpenPNE 3. The register form saves nickname, password and address one request before activation,
so an abandoned signup holds working credentials, and OpenPNE 4 gates login on the password and
`is_login_rejected` alone. The upgrade carries only rows matching `ActiveMember::predicate()` (the
listener's condition verbatim, NULL included for a pre-3.6 schema), and no target row may point at
a skipped member.

Every OpenPNE 3 FK onto `member` has one of three treatments:

- **drop** — the step lists the column in `memberRefs()` and the guard filters the row out. Only
  for registration artifacts (`member_config`, `member_profile`, `member_image`,
  `member_relationship`, `community_member`), where stock OpenPNE 3 produces such a row.
- **refuse** — `ActiveMember::references()` marks it `REFUSE`; `SourcePreflight` counts the rows
  before the first write and aborts on a non-zero count. For content tables: an inactive account
  has no SNSMember credential and cannot post in stock OpenPNE 3, and dropping a post would silently
  drop its comments and attachments.
- **unused** — `ActiveMember::references()` marks it `UNUSED` with a reason; no member id reaches a
  target column through it.

A `REFUSE` entry's `scope` replaces (not extends) the FROM step's filter and must describe the whole
set of rows whose member id reaches a target column; where a step's subquery picks one row out of
several, the scope calls the step's own selector so the two cannot disagree. `scopeColumns` are the
extra source columns the scope reads, so the structural check can require them. The preflight also
counts guarded rows whose member is missing from the source altogether (`danglingReference`),
because the drop guard would otherwise swallow a broken dump.

## KV config tables

`member_config` and `community_config` have no `(owner, name)` unique, so every read takes the
latest row per name (`ORDER BY id DESC LIMIT 1`, or `MAX(id)` in a filter). They are read by
subquery rather than as a step's FROM, so the per-step column audit cannot show which names migrate;
`StepRegistry::memberConfigDispositions()` / `communityConfigDispositions()` /
`notificationMailDispositions()` are that per-name coverage, and `knownMemberConfigNames()` /
`knownCommunityConfigNames()` / `knownNotificationMailNames()` are the sets the preflight's
unknown-name scan subtracts from (a warning, not an abort).

## Source preflight

Runs before any write, on the dry run too. Introspection goes through `information_schema`
qualified by the source database and prefix, because `Schema::hasTable()` sees only the
connection's own database. A missing core table or consumed FROM column, or a partially present
optional plugin group (`StepRegistry::optionalPluginSources()`), aborts; a fully absent optional
group is created empty from the DDL fixture so its steps no-op, and dropped after the run. The
unknown-name scan, the member-reference counts and the `UncopiedSettingsNotice` (the
`sns_config` values that live in `.env` here, printed with the value to set) read columns the
structural check guards, so they run only on a clean structural verdict; the notice also runs only
when a step has `sns_config` as its source table.

`ActivityPreflight` counts what the activity routing ("Activity threads" above) drops, re-parents or
refuses, one WARN per class with the first ids, so no disposition is silent; a timeline thread starter whose
`public_flag` is outside the activity scale or a migrated activity with more than 255 file-backed
images is an ERROR, because the step would fail on the row mid-run (a row no step selects is left to
its WARN). `FileOwnerPreflight` counts files that
more than one owning row points at, across every `FileUpgrade::ownedFileReferences()` arm: OpenPNE 3
never made the file columns unique, and a file with two owners would be read under one owner's
audience from the other's page, so it is an ERROR.

`MailTemplatePreflight` render-tests every template the translation step will carry, because the
step copies bodies without parsing them. Two passes per row: a lenient render reports what
production would throw, then a strict render (`strict_variables`) reports a referenced-but-absent
variable. Names and locales are resolved through the steps' own SQL
(`MailTemplateUpgrade::keyCase()`, `MailTemplateTranslationUpgrade::localeExpr()`): the source
collation is case-insensitive and PAD SPACE, so a PHP comparison would cover a different row set
than the INSERT.

## Checkpoints and resume

Each step runs in one transaction wrapping its `INSERT ... SELECT` and its
`openpne4_upgrade_state` checkpoint, so completed ⟺ committed and a re-run resumes from the first
incomplete step. A checkpoint records that a step ran, not its definition;
`UpgradeRunner::NAMING_EPOCH` is bumped when step classes or target tables are renamed and stamped
on the first run, so a resume under another epoch aborts instead of re-copying under new keys.
`reset()` (`--force-restart`) DELETEs the upgrade-owned targets (TRUNCATE fails on an FK-referenced
table, error 1701) after dropping the `file_bin` FK so the BLOBs cannot cascade.

## Post-walk passes

Work an `INSERT ... SELECT` cannot express runs after the walk, in this order, each under its own
checkpoint except the `surface_mode` stamp, which writes no `openpne4_upgrade_state` row:

| Pass | Why after the walk | Resume model |
|---|---|---|
| `PasswordWrap` | bcrypt is not computable in SQL | the bare-MD5 predicate never matches a wrapped row, so rescanning is idempotent |
| `ActivityTemplateTransform` | the OpenPNE 3 template lines carry PHP-serialized parameters; the body is re-derived from them and `uri` by `ActivityTemplateRenderer` (the same `Announcement` a new record gets), in the site's base locale, in `timeline_posts` and `group_messages` alike | id cursor plus the per-reason kept counts, committed with each chunk; the body is a function of the source row, so a restart from 0 rewrites the same text. Runs before the emoji pass because the parameters carry carrier codes, which `Announcement` converts itself |
| `EmojiTransform` | per-row PHP mapping; 16 carrier-logo ids stay literal | id cursor in `metadata.last_id`, because a "contains a code" predicate never drains |
| `SitePolicyMarkdownTransform` | Markdown rewrite of raw HTML | not idempotent (escapes double); the rewrite and its COMPLETED checkpoint commit in one transaction |
| `TalkReadCursorBackfill` | `GroupMemberUpgrade` runs before the messages exist, so the walk leaves the read cursor at the schema default, a wall-clock stamp; the pass writes `TalkReadCursor::snapshot()` per group once they have landed | a function of the migrated rows, so a rescan writes the same tuple; every group and the COMPLETED checkpoint commit in one transaction |
| `FileBinMigration` move + rewire | `files` must exist for the FK | `information_schema` state (source table presence, FK target) |
| `surface_mode` stamp | no OpenPNE 3 source column | insert-if-absent, only after full success |

`PasswordWrap` writes `bcrypt(md5hex)` at cost 10 with `password_scheme = md5_bcrypt`; login
verifies `Hash::check(md5($attempt), $hash)` and rehashes to plain bcrypt on the first success.
Its checkpoint's `rows_affected` counts the completing run's rows only; verify reads the terminal
state, not the count. `EmojiTransform` aborts on a non-utf8mb4 connection, which would mangle non-BMP emoji to `?`.

## file_bin

OpenPNE 3 stores upload bytes in `file_bin`, one row per `file` row under DB-blob storage; a count
mismatch is rejected by `FileBinMigration::preflight()`. The bytes are never copied: `FileUpgrade`
keeps `file.id`, so the migration re-points the `file_bin.file_id` FK from `file` onto `files`. A
`--source-prefix` / `--source-database` run first RENAMEs the source `file_bin` onto the app's (an
`.ibd` move) after dropping the source FK. `snapshot()` records `MAX(file.id)` as the bound for a
post-switchover rollback.

The table's four columns are frozen because the fresh-install schema must equal the upgrade
target: neither upgrade path keeps the table the app's `CREATE TABLE` makes (a same-database dump
keeps its own `file_bin`, a source-database run drops the empty app table and RENAMEs the source in),
so a column added to the migration would exist on fresh installs only. Every column is charset-neutral (INT / LONGBLOB / DATETIME), so
the app's utf8mb4 default against OpenPNE 3's utf8mb3 forces no rewrite either.

## Verify

`openpne:verify-upgrade` re-counts source and target without trusting the runner's report.
Check A, per step: source rows under `effectiveFilter()` == recorded `rows_affected` == target
rows under `targetFilter()`; a FROM or filter-subquery table that is an absent optional plugin
counts as 0. `ActivityTemplateCheck` re-derives every migrated template row from the source and
compares it with the stored body (a rendered row must hold the render, a kept row its stored body
after the emoji pass), and fails when the template pass has no completed checkpoint. Check B: every `files` row has a `file_bin` row with `byte_size == LENGTH(bin)`, and
the FK is rewired. Check C: no bare MD5 remains, every `md5_bcrypt` row holds a bcrypt string, and
no unknown scheme exists.

## Site policy bodies

OpenPNE 3 printed `user_agreement` / `privacy_policy` as `nl2br()` of raw HTML with output escaping
off. `Op3PolicyMarkdown` rewrites them so `App\Support\MarkdownText` renders the same: a body
without tags has its Markdown constructs escaped (newlines stay, since a soft break renders as
`<br>`); a body with tags is `nl2br()`'d and converted with `league/html-to-markdown`, stripping
markup with no Markdown form. Deliberate difference: bare URLs, `www.` hosts and email addresses are
autolinked where OpenPNE 3 left them as text.
