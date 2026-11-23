# Releasing

A release is a tag `v<version>` (`v4.0.0-alpha.3`) with a GitHub prerelease. Sites run releases,
not `main`, and move from one to the next ([docs/updating.md](docs/updating.md)), so a release has
to be an update of the one before it. The checks below hold it to that.

The tag name matters: the checks count a tag as a release only when it starts with `v` and a
digit. A release tagged any other way is invisible to them.

## The release branch

1. **Cut `release/<version>` from `main`** at the commit the release should contain. `main` moves
   on; the branch is what gets reviewed and released. One release is in progress at a time. The
   branch takes changes only through pull requests: cutting it again means deleting it first.
2. **A fix found during review goes to `main` first**, then onto the branch as a copy:
   `git cherry-pick -x <commit on main>`, opened as a pull request against the branch. The
   `(cherry picked from commit …)` line has to survive into the commit that lands. A copy does not
   touch `database/migrations`: a migration added on the branch would run on updated sites in
   another order than on a fresh install. A fix that needs one means cutting the branch again.
3. **While the branch is open, leave `.github/workflows` on `main` alone**, or copy the change to
   the branch as well: a release cannot be drafted across a difference there.

An urgent fix to the last release is the same thing with another starting point: cut
`release/<next version>` from the last release's tag and copy the fix onto it. If a release is
already being reviewed under that version, rename its branch to the version after, release the fix,
then copy the fix from `main` onto the renamed branch as well (the checks ask for it).

## Draft, then publish

1. **Run the Release workflow** (Actions, "Release", from `main`) with the version and the commit at
   the tip of the branch. It refuses anything but that tip, runs every CI lane on it (the MySQL lane
   on 8.4 and again on 8.0, which CI otherwise leaves out), rehearses the update from the last
   release on SQLite, MySQL and MariaDB, and audits the dependencies the release ships.
   It also builds the release's archives (`openpne-<version>.tar.gz`, `.zip` and `SHA256SUMS`:
   the tree with its dependencies and the built frontend), installs each where Composer and Node
   fail when called, and, when the last release has archives, updates a site from them, as
   downloaded, to the new ones ([docs/updating.md](docs/updating.md), "From a release archive").
   The draft carries the three files.
   If a copy differs from its original — the fix did not apply as it was — name it in `differs`;
   the run fails until the list matches. `allow_advisories` accepts advisories for one release,
   each named by an id the audit reports for it (`PKSA-…` or `GHSA-…`; `CVE-…` too where Composer's
   report carries one).
2. **Publish the draft** it leaves under Releases, without changing its tag or its files, and
   keeping the `<!-- release: … -->` line when you edit the notes. Publishing creates the tag. A
   check then compares the tag and the attached files with what was drafted. If it fails, the
   last error it reports says what to do: delete the release and the tag and draft again, or, when
   GitHub did not answer, run the check again. A failure that reports no such error is not about
   the release: run the check again. The ruleset on `v*` tags refuses their deletion: set its
   enforcement status to Disabled to delete the tag, then back to Active.
3. Delete the branch.

## After publishing

A published release keeps its `<!-- release: … -->` line and the files attached to it as they
are, with none added. The run for the release after it reads both: it downloads the files, checks
them against the line, and updates a site from them, so a line or a file that changed stops that
run until it is put right.

Put back what changed. If a file is gone and cannot be put back (the archives are not built the
same twice), remove every file from that release and change `sums=<hash>` in its line to
`sums=none`. It is then read as a release without archives: the next run skips the update from
them, so the update that a site which took that release from its archive makes goes unrehearsed,
and that site can no longer download the archive it runs, which
[docs/updating.md](docs/updating.md) uses to carry its edits to the release's files.

## By hand

When the workflow cannot draft the release, a maintainer can. Nothing is checked on this path
except what is run by hand: `DIFFERS=<commits> .github/scripts/release-validate.sh check <version>
<sha>` checks the branch (after `git fetch --prune --prune-tags origin`), and CI, the update
rehearsal and the audit are not run at all. The body has to carry the line the workflow would
write, or the check after publishing fails:

```bash
gh release create v<version> --target <sha> --draft --prerelease --title v<version> \
  --notes '<!-- release: tag=v<version> commit=<sha> baseline=<last tag, or none> baseline_commit=<its commit, or none> sums=none -->'
```

A release made by hand has no archives: only the workflow builds and checks them, and `sums=none`
says so (the check after publishing refuses any file attached to it). A site installed from an
archive cannot move to such a release; it waits for the next one the workflow drafts.
