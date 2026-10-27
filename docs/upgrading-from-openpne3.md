# Upgrading from OpenPNE 3

Migrates one OpenPNE 3 site's data into a fresh OpenPNE 4 install: members and their profiles,
diaries, groups, messages, files, and the site's own settings.

You will do this twice. First as a **rehearsal** against a dump, while OpenPNE 3 keeps serving — that
is where you find out what your source needs fixed, and it costs nothing to repeat. Then as the
**cutover** (stage 6), against a source no one is writing to any more. The rehearsal is not the
migration: anything members write to OpenPNE 3 after you take its dump is not in that dump.

Two words this document uses in a specific way:

- **the source** — the restored copy of your OpenPNE 3 database that the upgrade reads. Never the
  database your live OpenPNE 3 site is serving from.
- **a step** — the upgrade copies the data table by table, and each of those units is a step. The
  commands name them in their output (`FileUpgrade`, `MemberUpgrade`, …). The numbered *stages*
  below are this document's own; they are not the same thing.

Read [Requirements](#requirements) before dumping anything — some of them decide whether the upgrade
can run at all, and one decides how to take the dump.

## Requirements

- **MySQL**, for both the source and the target. The upgrade is a set-based `INSERT...SELECT` over
  the OpenPNE 3 DDL; it does not run on SQLite. (An upgraded site is not obliged to stay on MySQL,
  but [changing engine](changing-database-engine.md) is a separate operation and no part of this
  one.)
- **OpenPNE 3 core 3.6.x or newer.** An older source is missing tables and columns the upgrade
  reads. It refuses to start and names each one rather than failing partway through.
- **Database file storage** (OpenPNE 3's default), where every uploaded file has its bytes in a
  `file_bin` row. A site converted to filesystem storage is not supported: the upgrade refuses to
  run while a `file` row has no `file_bin` row or vice versa, instead of migrating file metadata
  without the bytes, and names the ids on either side so you can restore a complete dump or delete
  them in the source.
- **A schema-and-data dump** — `mysqldump` of the OpenPNE 3 database as it writes one by default,
  `DROP TABLE` statements included. A data-only dump (`--no-create-info`) carries nothing to create
  the source tables from, and the target has no OpenPNE 3 tables of its own to receive the rows.
- The OpenPNE 3 tables must be readable from OpenPNE 4's own database connection — restored into the
  same database, or into another database on the same MySQL instance.
- For the prefixed and separate-database layouts in stage 2, the database user additionally needs to
  drop a foreign key on the source's `file_bin` and rename that table. Check this during the
  rehearsal; a privilege error is a bad thing to meet mid-cutover.

## 1. Install OpenPNE 4 on a fresh database

Install it the ordinary way — [with Docker](../README.md#getting-started) or
[without](../README.md#without-docker) — with one thing set before you start it: `.env` must point at
a **fresh, empty MySQL database**, not the SQLite default. These are the settings the rest of this
document refers to as `DB_*`:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=openpne4
DB_USERNAME=openpne
DB_PASSWORD=your-password
```

Create the file first (`cp .env.example .env`) and edit it, then install. Both paths create the
schema as part of that — `php artisan migrate` on the host, or the first container start with the
Docker setup, which also installs dependencies and generates `APP_KEY`.

Compose ships no database of its own, so with Docker `DB_HOST` has to name a MySQL server the
*container* can reach: `127.0.0.1` there means the container itself, not your machine.

**Where to run the commands.** Every `php artisan …` below runs from the application root, on the
machine holding that `.env`. With the Docker setup, prefix them:
`docker compose exec app php artisan …`.

## 2. Restore the OpenPNE 3 dump

```console
$ mysql -u openpne -p openpne4 < openpne3-dump.sql
```

`file_bin` is the one table OpenPNE 3 and OpenPNE 4 both have, and restoring over OpenPNE 4's empty
one is exactly what should happen. That table holds the uploaded files' bytes — commonly gigabytes —
and the upgrade never copies them: it re-points them at the new `files` table, which is a bookkeeping
change no matter how large the table is. What arrives with the dump stays where it lands.

That works because `mysqldump` drops each table before recreating it. Restoring into the same
database from a dump taken with `--skip-add-drop-table` will collide on `file_bin` instead: drop
OpenPNE 4's empty one first, or restore before creating the schema in stage 1 — OpenPNE 4 leaves a
`file_bin` alone when it is already there. The layouts below sidestep this by keeping the source's
tables under their own names.

Where you restore it decides an option you will pass to the commands in stages 3 to 5:

| Source layout | Option | |
|---|---|---|
| Same database as OpenPNE 4 | *(none needed)* | The default. Nothing else collides — `file_bin` is the only shared name. |
| Same database, prefixed tables | `--source-prefix=op3_` | Use the prefix your dump actually has. |
| Another database, same MySQL server | `--source-database=openpne3` | For a customised source whose table names would clash. |

Every command in stages 3 to 5 takes it the same way, and they all need it — each one has to find the
source the same way the others did:

```console
$ php artisan openpne:upgrade-from-3 --dry-run --source-database=openpne3
```

The two non-default layouts also **consume the source's `file_bin`**: that table is moved onto
OpenPNE 4's rather than copied, so the bytes leave the source entirely.

## 3. Dry-run, and read the report

```console
$ php artisan openpne:upgrade-from-3 --dry-run
```

Writes nothing. It prints the SQL each step would run, and — the part worth reading — what its
**preflight** found: a read-only inspection of your source that runs before anything is written.

The lines to act on start with one of these words, apart from the two unprefixed instructions a
first completion ends with (the surface switch, and warming the pictures: §5); the rest is the
`Site timezone` line to check, the dry run's `PLAN` lines and their SQL, progress, `Reset:` under
`--force-restart`, and the closing `Finished in …`:

| Line | Meaning |
|---|---|
| `ERROR …` | The source cannot be migrated as it is; nothing is written. Exit code 1. |
| `WARN …` | Never stops the run; the code says what happened to the row (the table below). Exit code 0 on its own. |
| `ENV_HINT KEY=value` | A line to put in `.env` to keep an OpenPNE 3 setting the database does not carry. |
| `Aborted: …` | Why nothing ran: a failed preflight, or upgrade records this version cannot resume. Exit code 1. |
| `RUN <step>` / `DONE <step>: … (1.2s)` / `SKIP <step>: …` | One step, with how long it took, or why it was skipped (already completed on a re-run). |
| `FAIL <step>: …` | The step that stopped the run, on one line; the run exits 1 and a re-run resumes here. The whole exception follows under `-v`, and is in the log either way. |
| `NOTE …` | Something to look at once the upgrade is done. |

`--json` prints the same report as one JSON document instead — on failure too, with the exit
code unchanged, even under `-q` — for a script that migrates many sites. `-v` prints the exception
after the document, on the same stream, so a script parsing stdout must not pass `-v`.

**`ERROR` aborts the run**, before anything is written. These are states the upgrade cannot survive:
a required table or column missing (an older or customised OpenPNE 3), a plugin whose tables are
only partly present, files whose bytes are missing, two mail translations that would land on the same
template and locale, a diary, message, topic, event or timeline post belonging to a member who never
finished registering (see [Members who never finished registering](#members-who-never-finished-registering)),
a timeline post whose audience flag is not one OpenPNE 3 could write, an activity with more than 255
attached files, a file that two records point at (OpenPNE 4 gives a file one owner, so one
record's readers would see it from the other's page), a member who liked one post, talk message,
diary or comment twice (OpenPNE 4 keeps one 👍 per member), or a date column holding `0000-00-00`
(or a zero month or day) on a row the upgrade copies — MySQL's strict mode, which OpenPNE 4 runs
under, refuses the value, so the line names the table, the column, the count and the first ids;
set a real time there (or NULL where the column allows it). Fix the source and dry-run again.

**`WARN` never stops the run**; its code says what happened to the row. Each line is
`WARN <code>: …`, and the JSON entry carries the `code`, its `category` and a `count` in the
code's own unit (rows, threads, files, likes — never add counts across codes). The categories:
`dropped` — the source value (or a whole absent table) is not copied; `rewritten` — carried, changed on the way;
`review` — carried, but you may need to act; `env` — a `.env` line keeps it; `unknown` — source
data the upgrade does not recognise, not carried.

| Code | What it means |
|---|---|
| `W-UNKNOWN-NAME` | A `name` the upgrade does not recognise, with a row count; names in `member_config` / `community_config` that differ only in a trailing number are reported once as a family. A third-party plugin or a local customisation put it there. OpenPNE 4 has no home for it, so those rows are not migrated. |
| `W-ABSENT-PLUGIN` | A plugin's tables are not in the source at all: its step migrates nothing. |
| `W-SETTING-IN-ENV` / `W-SETTING-UNREADABLE` | `image_max_filesize` lives in `.env` here; the `ENV_HINT` line gives the value, or the size could not be read and you set it yourself. |
| `W-SETTING-NULL` | An `sns_config` row with a NULL value: OpenPNE 3 read its default there, and OpenPNE 4 keeps its own. |
| `W-MAIL-BROKEN` / `W-MAIL-DEGRADED` | A mail template that does not render: broken means the mail cannot be sent until the template is fixed; degraded means it sends with the failing reference as empty text. An admin customised it into something OpenPNE 4 cannot render, or it links through an OpenPNE 3 address that no longer exists. |
| `W-MAIL-INERT-LOCALE` | A mail translation in a locale OpenPNE 4 never reads. It migrates but is never sent: OpenPNE 4 sends each recipient `ja` or `en`. |
| `W-ACTIVITY-DEEP-REPLY` / `W-ACTIVITY-UNRESOLVABLE-REPLY` | Timeline replies answering another reply, or nested deeper than 4. OpenPNE 4 threads are one level deep. A reply to a reply is attached to the thread's first post (where OpenPNE 3 showed it too); one nested deeper than 4 is not migrated — point its `in_reply_to_activity_id` at the first post in the source to carry it. |
| `W-ACTIVITY-DANGLING-REPLY` | Timeline replies whose parent row is missing: an incomplete dump (OpenPNE 3 deletes a reply with its parent). Each is migrated as a post of its own. |
| `W-ACTIVITY-ORPHAN-GROUP-THREAD` / `W-ACTIVITY-NON-MEMBERS-GROUP-THREAD` | Community timeline threads whose community is gone, or that were not posted to every member. Not migrated: a group's talk belongs to a group, and holds no per-message audience. The friends-only and private community posts OpenPNE 3 allowed have nowhere to land. |
| `W-ACTIVITY-OTHER-SCOPE` / `W-ACTIVITY-CROSS-SCOPE-REPLY` | Timeline threads scoped to something other than a community (not migrated), or replies scoped differently from their thread (a thread lands where its first post does). |
| `W-ACTIVITY-URI-ONLY-IMAGE` | Activity images held only as a URL. OpenPNE 4 keeps an image as a file, so these are not migrated. |
| `W-LIKE-UNMIGRATED-ACTIVITY` | Likes (`opLikePlugin`) on activities that are not migrated. A like lands where its activity does; one on an activity left behind is left behind with it. |
| `W-LIKE-GONE-TARGET` | Likes on a diary, diary comment, topic comment or event comment that no longer exists, counted per kind. Nothing to land on. |
| `W-LIKE-UNINSTALLED-TARGET` | Likes on a kind of record whose plugin this source does not have, counted per kind. Nothing to land on. |
| `W-LIKE-UNKNOWN-TABLE` | Likes under a `foreign_table` letter opLikePlugin never writes. A third-party plugin or a local customisation put them there; not migrated. |
| `W-ACTIVITY-TEMPLATE-RENDERED` / `W-ACTIVITY-TEMPLATE-DROPPED` / `W-ACTIVITY-TEMPLATE-UNKNOWN` | Template activities (`diary` / `community_topic` / `community_event`), with where they land. The lines OpenPNE 3 wrote when a diary, topic or event was created. They are rewritten into the OpenPNE 4 wording, in the site's locale, with a link to the record; one in a thread that is not migrated goes with it; a template OpenPNE 4 does not know keeps its stored text. |
| `W-ACTIVITY-TEMPLATE-KEPT-UNKNOWN` / `W-ACTIVITY-TEMPLATE-KEPT-BAD-PARAMS` / `W-ACTIVITY-TEMPLATE-KEPT-NO-LINK` / `W-ACTIVITY-TEMPLATE-KEPT-UNFIT` | After the copy, template rows whose line could not be rendered keep their stored text, counted by reason: a template OpenPNE 4 does not know, parameters that are not what OpenPNE 3 wrote, a link that names no OpenPNE 4 page, or a link alone longer than the body allows. |
| `W-UPLOAD-OWNERLESS` | Upload-plugin files whose member or community the upgrade does not carry. They arrive with no owner, so their `/f/show` links answer 404; delete them in the source, or afterwards in the admin file list. |
| `W-THEME-UNKNOWN-PACKAGE` / `W-THEME-MISSING-PACKAGE` | The skin OpenPNE 3 showed cannot be reproduced yet: the site wore the first package on its disk (which the run cannot see), or `Theme_used` names a package not under `public/opSkinThemePlugin`. The default skin shows until the package is copied in and chosen (see [Bringing an OpenPNE 3 theme](#bringing-an-openpne-3-theme)). |

The mail-template warnings say which kind each one is, because it changes what happens after the
cutover. A template OpenPNE 4 cannot parse, one using something it does not allow, or one linking to
an address that cannot be mapped means the mail **fails instead of sending** until someone fixes it.
A missing variable is milder: the mail sends with that piece of text empty.

**Where you fix them decides whether the fix survives.** Editing the template in **OpenPNE 3**, in
its own notification mail editor, puts the fix in the final dump — so it arrives already working at
the cutover. Editing it in the **OpenPNE 4** admin (*Settings → Mail template settings*) only changes
the database in front of you, and a rehearsal database is thrown away: the cutover restores a fresh
dump into a fresh one. So either port the fix back to OpenPNE 3, or write down the exact edit and
reapply it to the real target once its upgrade finishes and **before traffic is switched** — that is
the point where the site starts sending registration and email-change mails for real.

Each template is reported by its first fault, because that is where rendering stopped, and fixing one
can reveal the next. After editing in OpenPNE 3, re-run the dry run to see what is left. The
OpenPNE 4 editor answers that itself: it renders what you typed when you save and refuses the change
if it still fails — the dry run would not show it, since that reads your OpenPNE 3 source.

## 4. Run the upgrade

```console
$ php artisan openpne:upgrade-from-3
```

Each step is committed as it finishes, so an interrupted run picks up from the first step that did
not complete; re-running is safe. A step that fails says so on one line (`FAIL <step>: …`) and
the command exits 1; run it again with `-v` to see the whole exception.

The run also records the set of steps it was started under. If you update OpenPNE 4 partway
through a migration and the update changed that set — a step added, removed or reordered, the
SQL of a step changed, or one of the later passes revised — the run refuses to resume rather
than mix two versions, names what changed, and points at `--force-restart`, which empties the
tables the upgrade owns along with its records of what ran. The same refusal covers an update
that renames the steps or the tables they write.

Once a site has completed, a re-run changes nothing: it answers `Completed: … nothing to do`,
whatever has changed in OpenPNE 4 since, and never suggests `--force-restart`. A site that
completed before this version recorded the set of steps is adopted by its first re-run —
nothing runs and the completion is recorded as unverified. `openpne:upgrade-status` then names
any step this version expects that has no record; a site in that state cannot be resumed and
needs `--force-restart` from a fresh restore. Only a site whose older run left a failed step
behind, or never reached the end, resumes, and then a step this version added does run. That
makes `--force-restart` a rehearsal tool. Once OpenPNE 4 is taking traffic, those tables are
the live site.

### Where does a site stand?

```console
$ php artisan openpne:upgrade-status
```

Read-only. `Status:` is one of `not_started`, `in_progress` (with the next phase and any
failed one), `blocked` (a resume this version refuses, and why), `completed` (with
`upgrade_completed_at` and `upgrade_release`, the build that started the run), or
`completed_unverified` — the site finished, but under a set of steps this version cannot vouch
for: it was migrated before this version recorded the set, or OpenPNE 4 has changed a step
since. Then one line per phase. `--json` gives the same with the site, the current build
(`release`) and manifest, and every phase's checkpoint; it exits 0 whatever the status.

## 5. Verify before switching over

```console
$ php artisan openpne:verify-upgrade
```

Read-only, and takes the same option you upgraded with. It does not trust what the upgrade reported:
it re-counts the source and the target independently, and fails if any check fails. It checks that
each step's source rows, the number it recorded, and the rows it owns in the target all agree; that
every file has its bytes at the right length and pointing at the right place, and no stored bytes
belong to a file that is not there; that every migrated
template activity holds the line the upgrade renders for it; that every group membership is read up to
its group's migrated talk (a message read since counts, the untouched default does not); and that no OpenPNE 3 password hash is left behind. The template
lines are compared against a fresh render, so leave the site's terms as they were until verification
passes (the language and address it was upgraded under are remembered); a term renamed later makes
this check report the lines it touched.

**Row counts agree trivially when both sides are empty.** A step that migrated nothing — because a
source table was not what you assumed, or because it matched no rows — reports the same pass as one
that migrated correctly. List those steps and account for each:

```console
$ php artisan openpne:verify-upgrade | grep ': 0 rows'
```

(`--json` emits the same report as JSON if you would rather consume it from a script, led by the
site, the build, and what the completion marker recorded.) Verify is the gate for the build you
are about to serve: the `manifest` check fails when the upgrade never finished, and when the
site was migrated by a different set of steps than this version has — then the answer is to run
the upgrade again on this version from a fresh restore. A site migrated before this version
recorded the set passes that check with a note once a re-run has recorded its completion.

Some are expected: a step carries an OpenPNE 3 plugin your site never installed, or a setting whose
absence already means what you want. The rest are the ones to look into. That judgement is yours —
the command cannot know which features your site had.

Then index the hashtags of the migrated timeline posts, which the copy carries as plain text:

```console
$ php artisan openpne:timeline-backfill-hashtags
```

It is safe to run again, and only the timeline is indexed — a group's talk never parses hashtags.

Pictures need nothing before the cutover: each migrated one is made on its first view, and its size
and whether it animates (for a GIF or WebP, only under the `imgproxy` processor) are recorded then.
Optionally, after the cutover and in the background, make the newest ones ahead of time so their
first viewer does not wait:

```console
$ php artisan openpne:image-cache warm --limit 2000
```

It goes newest first, stops after that many, and the next run goes on from there — a nightly run
with a limit works through the archive at whatever pace the disk allows. Without `--limit` it
makes every picture, which takes about as much disk again as the pictures themselves. Safe to run
again, and safe to interrupt. Pictures OpenPNE 3 accepted but this version's limits refuse are listed by
`php artisan openpne:image-cache status`; the site shows nothing for those until the limits are
raised and `warm --retry-failed` is run. It also lists rows stored under an image type this version
does not show as a picture; the upgrade folds the two OpenPNE 3 aliases into shown types (below), so
what remains came in through OpenPNE 3's unvalidated API upload with whatever type the client sent.

## What the upgrade changed

Nothing to perform here — this is what the migrated site looks like, so you can tell an intended
change from a problem when you go through it.

- **Passwords** — OpenPNE 3 passwords keep working, and each one is quietly re-secured on its owner's
  first sign-in. Nobody has to reset anything.
- **File types** — every file's type is lower-cased, and the two aliases OpenPNE 3's validator
  admitted, `image/pjpeg` and `image/x-png`, become `image/jpeg` and `image/png`, so those pictures
  are shown as pictures.
- **Upload-plugin files** — a file shared through opUploadFilePlugin keeps answering at the
  `/f/show/{id}.{ext}` link pasted into bodies, for any signed-in member as before (the owner's
  blocks apply). The upload box itself is gone: a file is attached to a post instead. A link to a
  previous host name is not rewritten.
- **Surface** — the site looks like OpenPNE 3 (the "Classic" surface), matching what its members
  knew. Moving to the new one is a separate decision you can take whenever you like, before or long
  after the cutover: `php artisan openpne:surface-mode modern_only`. A phone browser gets the
  Modern surface regardless, as OpenPNE 3's phone layout was its own, which is why the cutover
  below starts with the Modern branding.
- **Site settings** — an `sns_config` value is copied only where this version has a matching
  setting, and **the security settings are never copied**: CAPTCHA starts on and registration starts
  invite-only whatever the OpenPNE 3 site had, so set them yourself before the cutover
  (*Settings → Registration settings*). The dry-run report lists what the settings step copies. Values with no
  counterpart are dropped; for example the PC / mobile split (`enable_pc`, `enable_mobile`), the
  feature-phone options (`is_check_mobile_ip`, `retrieve_uid`), the OpenID / external-connection /
  JSON API options (including the diary API limits `op_diary_plugin_diary_api_limit` and
  `op_diary_plugin_diary_comment_api_limit`), the Google Maps options, `daily_news_day`, the
  feature-phone mail-posting option (`op_diary_plugin_use_email_post`) and colour slots
  (`mobile_frontend_core_color_*`), `richtextarea_*` (the rich-text editor is frozen),
  `pc_home_information` (OpenPNE 3 itself
  rendered the information box from the gadget's own config, never from this row, which the
  gadget upgrade carries). `image_max_filesize` is not a site setting here but an environment
  variable, `OPENPNE_IMAGE_MAX_UPLOAD_KB`; the report (dry run and real run alike) prints the value
  to set, or tells you the OpenPNE 3 value could not be read as a size. A copied setting whose
  OpenPNE 3 row holds NULL (the admin screens wrote an empty string, so only a hand-edited row does)
  is left out where OpenPNE 3 itself read its default, and the report names it. The Classic theme is
  not a copied row either: a pass after the walk reads which skin plugins OpenPNE 3 had enabled (from
  its `plugin` table; an activation written in `data/config/plugin.yml` is not seen), and only when the
  theme plugin was the one enabled skin does `Theme_used` become `classic_theme`; change it afterwards
  on *Appearance (Classic) → Theme*. A site that never saved that page has no `Theme_used`, and
  OpenPNE 3 wore the first theme package on its disk, which the upgrade cannot see: the default skin is
  set and the report says to copy that package in and choose it. A theme of your own, one
  not among the vendored packages, is kept by name with a warning and shows the default skin until you
  copy its directory into `public/opSkinThemePlugin/` (`openpne:verify-upgrade` fails until then);
  see "Bringing an OpenPNE 3 theme" below.
- **Profile page audience** — OpenPNE 3's two layers both carry over: the site setting
  (`is_allow_config_public_flag_profile_page`) becomes *Settings → Member privacy settings*, and each
  member's own choice stays on their account, applying whenever the site setting lets members choose.
  A site that never had the setting starts members-only.
- **Likes** — an `opLikePlugin` like becomes a 👍 reaction by the same member at the same time, on
  the post or group talk message an activity became, or on the diary, diary comment, topic comment
  or event comment it was on. A like on something that is gone is left behind; the dry run counts
  each kind.
- **Emoji** — old carrier emoji codes become real emoji. Sixteen carrier logos have no modern
  equivalent and stay as literal text like `[i:108]`.
- **Site policy** — the imported terms and privacy pages are reformatted as Markdown, which is how
  OpenPNE 4 renders them. Worth reading once to see how they came out.
- **Mail templates** — any warning you have not resolved yet is still unresolved here; stage 3 covers
  where to fix it so the fix is still there after the cutover.
- **Timeline** — OpenPNE 3's activities arrive as timeline posts and replies with their dates and
  audiences, a reply attached to its thread's first post with that post's audience. The lines the
  diary and community-topic plugins wrote on creation are rewritten into the OpenPNE 4 wording with
  a link to the record — so run the upgrade with `APP_URL` already set to the address the site will
  live at, since those links are stored. Whether new diaries, topics and events keep announcing
  themselves follows the OpenPNE 3 settings (*Settings → Timeline settings → Automatic posts*).
- **Group talk** — a community's timeline becomes that group's talk. Talk shows every message in
  the group to everyone who may open the group today, including messages by people who have since
  left it and by people who have blocked the reader (OpenPNE 3 hid both); each member starts with the
  talk read up to its latest message, so nothing arrives marked unread. Messages the old
  community timeline showed to friends only, to their author alone or to everyone on the web, and
  the timelines of communities already deleted, are not carried (the dry run counts them).
- **Member count** — expect it to be lower than the number of rows in OpenPNE 3's `member` table, and
  to match what OpenPNE 3's own member list showed. The difference is the registrations below.

### Members who never finished registering

OpenPNE 3 creates a member row the moment someone requests a signup link or an admin sends an
invite, and marks it active only when registration completes. Until then the row is hidden from
every page and search in OpenPNE 3, and the account cannot use the site. Sites accumulate these for
years — abandoned signups, invitations never accepted — and nothing cleans them up.

OpenPNE 4 has no equivalent state: a member row *is* a member. So the upgrade does not carry those
rows, or anything hanging off them (their profile, their default-group memberships, the
friendship an invitation created). This is not optional, and it is the safe reading rather than the
conservative one: OpenPNE 3's registration form saves the nickname, password and address one request
*before* it activates the account, so an abandoned signup that reached that point would otherwise
arrive in OpenPNE 4 as a member who can sign in.

What the upgrade will not decide for you is content — a diary, message, topic, event or like — belonging
to one of those members. Stock OpenPNE 3 cannot produce it, so its presence means something wrote to
your database outside OpenPNE 3, and dropping it would take its comments and attachments too. The
preflight names the table and the row count and stops. Delete or reassign those rows in the source
and run again.

### Bringing an OpenPNE 3 theme

Only `bootstrap`, `cerulean` and `cerulean-light` ship here. To keep any other `opSkinThemePlugin`
theme your site wore, copy its whole directory from OpenPNE 3's `web/opSkinThemePlugin/<name>/` to
`public/opSkinThemePlugin/<name>/` on the new site, then run `openpne:verify-upgrade` again (or
pick it on *Appearance (Classic) → Theme*). A directory counts as a theme when its `css/main.css`
opens with the `Theme Name:` / `Author:` comment header, and its `css/*.css` are linked in name
order; its `js/` is not loaded. What the package carries is yours to check before you publish it.

## 6. Cutover

Rehearse until the dry run holds no surprises and verification passes. Set what phone visitors
will see, since they get the Modern surface and none of the Classic-only customisations (custom CSS
and HTML, gadgets, navigation, banners, theme): the brand color and logo under *Branding settings*,
the login message under *Login page settings*. Then migrate the data people will actually keep
using:

1. Stop writes to OpenPNE 3 — maintenance mode, or however that site takes traffic. From here on,
   nothing new is being written that the dump could miss.
2. Take the final dump.
3. Create another fresh, empty MySQL database, point `DB_*` at it, and create the schema there
   (`php artisan migrate --force`). Do not reuse the rehearsal's database: it still holds the
   rehearsal's rows and the upgrade's record of what already ran.
4. Restore the final dump in the same layout you rehearsed with (stage 2). With `--source-database`,
   that means a fresh database for the source too — the rehearsal moved the last one's `file_bin`
   away.
5. Repeat stages 3 through 5 against it, with the same option you rehearsed with.
6. Reapply anything you had fixed only inside OpenPNE 4 during the rehearsal. This site is built from
   a different dump into a different database, so none of it is here. Mail templates are the usual
   case — the editor re-renders each one as you save it, so a fix that no longer works is refused
   rather than carried into a live site.
7. Point traffic at OpenPNE 4.

Step 6 is before step 7 on purpose: once traffic is on OpenPNE 4, a mail template that still fails is
a registration or password reset that does not arrive. Stage 3 covers how to avoid the redo entirely
by fixing such templates in OpenPNE 3 instead.

Keep the rehearsal databases until you are satisfied with the new site. They cost nothing and answer
"was it already like that before?" without touching production.

## Rolling back

Before the cutover there is nothing to undo: OpenPNE 3 never stopped serving, and OpenPNE 4 lives in
its own database. Drop it and start again.

After the cutover, rolling back means going back to a site frozen at the moment you stopped its
writes — so the decision point is stage 6's traffic switch, not the migration. Anything written to
OpenPNE 4 after that is only in OpenPNE 4.
