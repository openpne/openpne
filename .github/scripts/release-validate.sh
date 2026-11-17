#!/usr/bin/env bash
# What a release has to be before it is drafted, and what a published one has to match
# (RELEASE.md).
#
#   check <version> <sha>   refuse anything but the tip of release/<version>, made of main up to
#                           where the branch left it plus copies of fixes main also has; prints
#                           sha=, baseline= and baseline_sha= for the workflow
#   published <tag>         refuse a published release that is not the one its draft was checked as
#                           (the release body in $BODY)
#
# `check` reads $DIFFERS: the copies the release notes list as differing from their original.
set -euo pipefail

here=$(cd "$(dirname "$0")" && pwd)
cd "$(git rev-parse --show-toplevel)"

die() { printf '::error::%s\n' "$*" >&2; exit 1; }
note() { printf '%s\n' "$*" >&2; }
newer() { [ "$1" != "$2" ] && [ "$(printf '%s\n%s\n' "$1" "$2" | sed 's/-/~/' | sort -V | tail -n 1 | sed 's/~/-/')" = "$1" ]; }

original_of() {
  local ids
  ids=$(git log -1 --format=%B "$1" | grep -oE '\(cherry picked from commit [0-9a-f]{7,40}\)' | grep -oE '[0-9a-f]{7,40}' | sort -u || true)
  [ "$(printf '%s' "$ids" | grep -c . || true)" = 1 ] \
    || die "$1 has to name exactly one \"(cherry picked from commit <sha>)\": a release branch holds copies of fixes on main and nothing else"
  git rev-parse --verify --quiet "$ids^{commit}" || die "$1 says it copies $ids, which is not a commit here"
}

# A copy is the same as its original when it is exactly what applying the original to the copy's
# parent gives; one whose conflict was resolved by hand never is, whatever the resolution.
same_as_original() { # same_as_original <copy> <original>
  local merged status=0
  merged=$(git merge-tree --write-tree --merge-base="$2^" "$1^" "$2" 2>/dev/null) || status=$?
  # 1 is a conflict; anything above is git failing, which is not "differs".
  [ "$status" -le 1 ] || die "git merge-tree could not compare $1 with $2 (it needs git 2.40 or later)"
  [ "$status" = 0 ] && [ "${merged%%$'\n'*}" = "$(git rev-parse "$1^{tree}")" ]
}

check() {
  local version=$1 given=$2 last tip sha point copies c orig touched originals="" differing="" expected last_point listed=${DIFFERS:-}
  export LC_ALL=C

  [[ "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+-(alpha|beta|rc)\.[0-9]+$ ]] \
    || die "\"$version\" is not a prerelease version like 4.0.0-alpha.1 (no leading v)"
  last=$("$here/release-compat.sh" last-release)
  ! git rev-parse --verify --quiet "refs/tags/v$version" >/dev/null || die "the tag v$version already exists"
  [ -z "$last" ] || newer "$version" "${last#v}" || die "$version is not newer than $last"

  tip=$(git rev-parse --verify --quiet "refs/remotes/origin/release/$version^{commit}") \
    || die "origin has no release/$version branch"
  # A branch name would follow the branch, and say nothing about what the notes were written for.
  [[ "$given" =~ ^[0-9a-f]{40}$ ]] || die "\"$given\" is not a full commit id"
  sha=$(git rev-parse --verify --quiet "$given^{commit}") || die "$given is not a commit in this repository"
  [ "$sha" = "$tip" ] \
    || die "release/$version is at $tip, not $sha: the branch moved after the notes were written, or this is the wrong commit"

  git diff --quiet "$sha" origin/main -- .github/workflows \
    || die "release/$version and main differ under .github/workflows, and a release cannot be drafted from here across that difference: copy the workflow change onto the release branch, or cut the branch again"

  point=$(git merge-base "$sha" origin/main)
  copies=$(git rev-list --reverse "$point..$sha")
  [ -z "$(git rev-list --merges "$point..$sha")" ] || die "release/$version holds a merge commit: a copy is one commit with one parent"
  for c in $copies; do
    orig=$(original_of "$c")
    git merge-base --is-ancestor "$orig" origin/main || die "$c says it copies $orig, which is not on main"
    ! git merge-base --is-ancestor "$orig" "$point" \
      || die "$c says it copies $orig, which main had before the branch left it: the branch holds it already, or main has taken it back"
    touched=$(git diff-tree --no-commit-id --name-only -r "$c"; git diff-tree --no-commit-id --name-only -r "$orig")
    if grep -q '^database/migrations/' <<<"$touched"; then
      die "$c, or the fix it copies ($orig), touches database/migrations: a migration added here would run on updated sites in another order than on a fresh install. Cut the release from main instead."
    fi
    originals+="$orig"$'\n'
    # A copy the last release already carried was compared, and declared if it differed, then.
    if [ -n "$last" ] && git merge-base --is-ancestor "$c" "$last"; then continue; fi
    same_as_original "$c" "$orig" || differing+="$c"$'\n'
  done

  expected=""
  for c in ${listed//,/ }; do
    expected+="$(git rev-parse --verify --quiet "$c^{commit}" || die "$c, given as a differing copy, is not a commit here")"$'\n'
  done
  [ "$(printf '%s' "$differing" | sort)" = "$(printf '%s' "$expected" | sort)" ] \
    || die "the copies that differ from their original are [$(printf '%s' "$differing" | tr '\n' ' ')] but the release was started with [$(printf '%s' "$expected" | tr '\n' ' ')]: start it with the list the release notes give"

  if [ -n "$last" ]; then
    last_point=$(git merge-base "$last" origin/main)
    git merge-base --is-ancestor "$last_point" "$point" \
      || die "release/$version leaves main at $point, before $last did ($last_point): a site on $last would go back in time"
    for c in $(git rev-list --reverse "$last_point..$last"); do
      orig=$(original_of "$c")
      git merge-base --is-ancestor "$orig" "$sha" || grep -qx "$orig" <<<"$originals" \
        || die "$last carries a fix (main's $orig) that release/$version does not: copy it onto the branch"
    done
  fi

  note "release/$version at $sha: $(printf '%s' "$copies" | grep -c . || true) copied fix(es) on top of main at $point, updating from ${last:-nothing}"
  printf 'sha=%s\nbaseline=%s\nbaseline_sha=%s\n' "$sha" "$last" "$([ -z "$last" ] || git rev-parse "$last^{commit}")"
}

published() {
  local tag=$1 marker drafted commit baseline baseline_sha at top
  export LC_ALL=C
  marker=$(printf '%s' "${BODY:-}" | grep -oE '<!-- release: tag=[^ ]+ commit=[0-9a-f]{40} baseline=[^ ]+ baseline_commit=[^ ]+ -->' | head -n 1 || true)
  [ -n "$marker" ] || die "$tag was published without the line its draft carries (RELEASE.md, \"By hand\"): nothing says which commit was checked. Delete the release and the tag, and draft it again."
  drafted=$(printf '%s' "$marker" | sed -E 's/.* tag=([^ ]+) .*/\1/')
  commit=$(printf '%s' "$marker" | sed -E 's/.* commit=([0-9a-f]{40}) .*/\1/')
  baseline=$(printf '%s' "$marker" | sed -E 's/.* baseline=([^ ]+) .*/\1/')
  baseline_sha=$(printf '%s' "$marker" | sed -E 's/.* baseline_commit=([^ ]+) .*/\1/')

  [ "$tag" = "$drafted" ] \
    || die "the release was drafted as $drafted and published as $tag: the checks find a release by its tag name. Delete the release and the tag, and draft it again."
  at=$(git rev-parse --verify --quiet "refs/tags/$tag^{commit}") || die "the tag $tag is not here"
  [ "$at" = "$commit" ] \
    || die "$tag points at $at, but the draft was checked as $commit: the release holds something that was not checked. Delete the release and the tag, and draft it again."

  # Every other tag counts, a higher one too: this runs when the release is published, and a
  # draft is checked as an update from the highest tag there was.
  top=$("$here/release-compat.sh" last-release "$tag")
  if [ -z "$top" ]; then
    [ "$baseline" = none ] || die "the draft of $tag was checked as an update from $baseline, which is not a tag here"
  else
    if [ "$baseline" != "$top" ] || [ "$baseline_sha" != "$(git rev-parse "$top^{commit}")" ]; then
      die "the draft of $tag was checked as an update from $baseline, but the highest other release is $top: another release was published in between. Delete this release and its tag, and draft it again."
    fi
  fi
  note "$tag is at $commit, the commit its draft was checked as, updating from $baseline"
}

case "${1:-}" in
  check) [ $# = 3 ] || die "usage: release-validate.sh check <version> <sha>"; check "$2" "$3" ;;
  published) [ $# = 2 ] || die "usage: release-validate.sh published <tag>"; published "$2" ;;
  *) die "usage: release-validate.sh check <version> <sha> | published <tag>" ;;
esac
