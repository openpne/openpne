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
unknown-name scan and the member-reference counts read columns the structural check guards, so
they run only on a clean structural verdict.

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
| `EmojiTransform` | per-row PHP mapping; 16 carrier-logo ids stay literal | id cursor in `metadata.last_id`, because a "contains a code" predicate never drains |
| `SitePolicyMarkdownTransform` | Markdown rewrite of raw HTML | not idempotent (escapes double); the rewrite and its COMPLETED checkpoint commit in one transaction |
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

The table's four columns are frozen: adding or dropping one turns that metadata-only ALTER into a
full table rebuild. Every column is charset-neutral (INT / LONGBLOB / DATETIME), so the app's
utf8mb4 default against OpenPNE 3's utf8mb3 forces no rewrite either.

## Verify

`openpne:verify-upgrade` re-counts source and target without trusting the runner's report.
Check A, per step: source rows under `effectiveFilter()` == recorded `rows_affected` == target
rows under `targetFilter()`; a FROM or filter-subquery table that is an absent optional plugin
counts as 0. Check B: every `files` row has a `file_bin` row with `byte_size == LENGTH(bin)`, and
the FK is rewired. Check C: no bare MD5 remains, every `md5_bcrypt` row holds a bcrypt string, and
no unknown scheme exists.

## Site policy bodies

OpenPNE 3 printed `user_agreement` / `privacy_policy` as `nl2br()` of raw HTML with output escaping
off. `Op3PolicyMarkdown` rewrites them so `App\Support\MarkdownText` renders the same: a body
without tags has its Markdown constructs escaped (newlines stay, since a soft break renders as
`<br>`); a body with tags is `nl2br()`'d and converted with `league/html-to-markdown`, stripping
markup with no Markdown form. Deliberate difference: bare URLs, `www.` hosts and email addresses are
autolinked where OpenPNE 3 left them as text.
