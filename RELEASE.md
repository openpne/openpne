# Releasing

A release is a tag `v<version>` (`v4.0.0-alpha.3`) with a GitHub prerelease. Sites run releases,
not `main`, and move from one to the next ([docs/updating.md](docs/updating.md)), so a release has
to be an update of the one before it. The checks below hold it to that.

The tag name matters: the checks count a tag as a release only when it starts with `v` and a
digit. A release tagged any other way is invisible to them.

## The release branch

1. **Cut `release/<version>` from `main`** at the commit the release should contain. `main` moves
   on; the branch is what gets reviewed and released. One release is in progress at a time.
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
then copy the fix onto the renamed branch (the checks ask for it).

## Draft, then publish

1. **Run the Release workflow** (Actions, "Release", from `main`) with the version and the commit at
   the tip of the branch. It refuses anything but that tip, runs every CI lane on it, rehearses the
   update from the last release on SQLite and MySQL, and audits the dependencies the release ships.
   If a copy differs from its original — the fix did not apply as it was — name it in `differs`;
   the run fails until the list matches. `allow_advisories` accepts named advisories for one
   release.
2. **Publish the draft** it leaves under Releases. Publishing creates the tag. A check then compares
   the tag with what was drafted; if it fails, delete the release and the tag and draft again.
3. Delete the branch.

## By hand

When the workflow cannot draft the release, a maintainer can, after running the same checks
locally (`.github/scripts/release-validate.sh check <version> <sha>`). The body has to start with
the line the workflow would write, or the check after publishing fails:

```bash
gh release create v<version> --target <sha> --draft --prerelease --title v<version> \
  --notes '<!-- release: commit=<sha> baseline=<last tag, or none> baseline_commit=<its commit, or none> -->'
```
