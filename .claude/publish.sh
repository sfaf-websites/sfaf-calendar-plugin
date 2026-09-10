#!/usr/bin/env bash
# PUBLISH: sync the public repository, and on request cut the release that makes
# WordPress offer an update.
#
#     bash .claude/publish.sh              sync the public repository only
#     bash .claude/publish.sh --release    sync, then release the current version
#
# RELEASING IS NOT PART OF BUILDING, AND THAT IS THE POINT. Several versions get
# built in a session. If every build cut a release, every one would put an update
# prompt in front of Mark for work in progress. So the sync is cheap and safe to
# run whenever, and --release is a separate word that has to be typed.
#
# WHAT MAKES THE PUBLIC REPOSITORY DIFFERENT, and it is only ever these two:
#
#   SFAF-CALENDAR-REFERENCE.md   names the sfaf.org vendor with commentary about
#                                terms violations, records that cPanel access
#                                exists on SFAF hosting, and lists a staff
#                                member's personal business domain.
#   apiv2-public-gfmp.json       GoFundMe Pro's own 2.2 MB spec. Their document,
#                                no licence granting redistribution.
#
# The brand guide needs no filtering: it lives only on the brand-guide and
# embed-system branches and is not an ancestor of production-2.0, so carrying
# that one branch leaves it behind. July29Transcript.txt was removed from the
# private history by the July rewrite and is in no object here.
#
# THE FILTER IS RE-RUN IN FULL EVERY TIME, WHICH TAKES ABOUT THREE MINUTES.
# That is deliberate. filter-branch is deterministic given the same input and
# the same filter, so every private commit always maps to the same public
# commit, the history grows as a strict extension, and the push fast-forwards.
# An incremental replay would be faster and would have to solve parent mapping,
# conflicts and committer dates; this solves none of them because it never
# creates the problem.
#
# THE EXCLUSION IS CHECKED AFTER FILTERING AND BEFORE PUSHING. A push is not
# reversible in any way that matters once somebody has cloned, so the gate is on
# the near side of it.
set -eu

RELEASE=0
if [ "${1:-}" = "--release" ]; then
    RELEASE=1
elif [ -n "${1:-}" ]; then
    echo "usage: publish.sh [--release]"
    exit 1
fi

ROOT="$( cd "$( dirname "${BASH_SOURCE[0]}" )/.." && pwd )"
PUB="$( dirname "$ROOT" )/sfaf-calendar-public"
REMOTE="https://github.com/sfaf-websites/sfaf-calendar-plugin.git"
SLUG="sfaf-websites/sfaf-calendar-plugin"
BRANCH="production-2.0"
EXCLUDE_A="SFAF-CALENDAR-REFERENCE.md"
EXCLUDE_B="apiv2-public-gfmp.json"

cd "$ROOT"

# ---------------------------------------------------------------------------
# 1. The private repository must be clean and pushed, or the public one would
#    be built from a state nothing else has seen.
# ---------------------------------------------------------------------------
dirty=$( git status --porcelain --untracked-files=no )
if [ -n "$dirty" ]; then
    echo "FAIL: the working tree has uncommitted changes. Commit them first."
    printf '%s\n' "$dirty"
    exit 1
fi
if [ -n "$( git log "origin/$BRANCH..$BRANCH" --oneline )" ]; then
    echo "FAIL: $BRANCH has commits that are not on origin. Push them first."
    exit 1
fi

VERSION=$( sed -n "s/^define( 'SFAF_VERSION', '\(.*\)' );$/\1/p" "$ROOT/sfaf-calendar.php" | head -1 )
ZIP="$ROOT/sfaf-calendar-$VERSION.zip"
echo "version:  $VERSION"
echo "private:  $( git rev-parse --short "$BRANCH" )"

if [ "$RELEASE" -eq 1 ] && [ ! -f "$ZIP" ]; then
    echo "FAIL: $ZIP does not exist. Build it first: bash .claude/build-zip.sh $VERSION"
    exit 1
fi

# ---------------------------------------------------------------------------
# 2. Rebuild the filtered clone.
# ---------------------------------------------------------------------------
echo
echo "== rebuilding the filtered clone at $PUB"
rm -rf "$PUB"
git clone --no-hardlinks --quiet --single-branch --branch "$BRANCH" "$ROOT" "$PUB"
cd "$PUB"
FILTER_BRANCH_SQUELCH_WARNING=1 git filter-branch --force \
    --index-filter "git rm --cached --ignore-unmatch \"$EXCLUDE_A\" \"$EXCLUDE_B\"" \
    --prune-empty -- --all >/dev/null 2>&1
git remote remove origin >/dev/null 2>&1 || true
git for-each-ref --format='%(refname)' refs/original refs/remotes | while read -r r; do
    git update-ref -d "$r"
done
git reflog expire --expire=now --all
git gc --prune=now --quiet
git branch -m "$BRANCH" main 2>/dev/null || true

# ---------------------------------------------------------------------------
# 3. THE GATE. Every excluded file, by hash, across every object.
# ---------------------------------------------------------------------------
echo
echo "== checking the filtered history"
fail=0
for f in "$EXCLUDE_A" "$EXCLUDE_B" "July29Transcript.txt" "SFAF-brandguide-2026-v3.0.pdf"; do
    # The hash the file would have, taken from the private repo's own history
    # where the file still exists, or from disk when it is only on disk.
    id=$( git --git-dir="$ROOT/.git" rev-list --objects --all \
            | grep -F " $f" | head -1 | cut -d' ' -f1 || true )
    if [ -z "$id" ] && [ -f "$ROOT/$f" ]; then
        id=$( git hash-object "$ROOT/$f" )
    fi
    if [ -z "$id" ]; then
        printf '  %-32s no such object anywhere, nothing to exclude\n' "$f"
        continue
    fi
    if git cat-file -e "$id" 2>/dev/null; then
        printf '  %-32s STILL PRESENT as %s\n' "$f" "${id:0:10}"
        fail=1
    else
        printf '  %-32s absent\n' "$f"
    fi
done
if [ "$fail" -ne 0 ]; then
    echo
    echo "FAIL: something excluded survived the filter. Nothing was pushed."
    exit 1
fi

# ---------------------------------------------------------------------------
# 4. Push. No force: a non-fast-forward means the filter changed or somebody
#    pushed by hand, and either is a thing to look at rather than overwrite.
# ---------------------------------------------------------------------------
echo
echo "== pushing to $SLUG"
git remote add origin "$REMOTE"
git push --quiet origin main
echo "  public tip is now $( git rev-parse --short main )"

if [ "$RELEASE" -eq 0 ]; then
    echo
    echo "Synced. No release was created; add --release when Mark wants sites to see it."
    exit 0
fi

# ---------------------------------------------------------------------------
# 5. The release, through the REST API. gh is not installed on this machine and
#    is not needed: curl is, and a release is two calls.
#
#    THE TOKEN COMES FROM THE SAME CREDENTIAL HELPER `git push` USES, and it is
#    written to a curl config file rather than a command line, so it is not in
#    the process table and not in any log this prints.
# ---------------------------------------------------------------------------
CONF="$( mktemp )"
trap 'rm -f "$CONF" "$CONF.notes" "$CONF.body"' EXIT
chmod 600 "$CONF"
printf 'protocol=https\nhost=github.com\n\n' | git credential fill 2>/dev/null \
    | sed -n 's/^password=\(.*\)$/header = "Authorization: Bearer \1"/p' > "$CONF"
if [ ! -s "$CONF" ]; then
    echo "FAIL: the credential helper returned no token for github.com."
    exit 1
fi
printf 'header = "Accept: application/vnd.github+json"\nsilent\n' >> "$CONF"

# The notes are this version's changelog section, so the release says what the
# readme says and there is no second place to keep in step.
sed -n "/^= $VERSION =\$/,/^= [0-9]/p" "$ROOT/readme.txt" | sed '1d;$d' > "$CONF.notes"

php -r '
$notes = file_get_contents( $argv[2] );
echo json_encode( array(
    "tag_name" => $argv[1],
    "name"     => $argv[1],
    "body"     => trim( $notes ),
    "draft"    => false,
    "prerelease" => false,
) );
' "$VERSION" "$CONF.notes" > "$CONF.body"

echo
echo "== creating release $VERSION"
code=$( curl --config "$CONF" -o "$CONF.out" -w '%{http_code}' \
    -X POST "https://api.github.com/repos/$SLUG/releases" -d "@$CONF.body" )
if [ "$code" != "201" ]; then
    echo "FAIL: creating the release returned HTTP $code"
    head -c 400 "$CONF.out"; echo
    exit 1
fi
UPLOAD=$( php -r '$d=json_decode(file_get_contents($argv[1]),true); echo preg_replace("/\{.*\}$/","",$d["upload_url"]??"");' "$CONF.out" )
HTML=$( php -r '$d=json_decode(file_get_contents($argv[1]),true); echo $d["html_url"]??"";' "$CONF.out" )

echo "== uploading $( basename "$ZIP" )"
code=$( curl --config "$CONF" -o "$CONF.out" -w '%{http_code}' \
    -X POST "$UPLOAD?name=$( basename "$ZIP" )" \
    -H "Content-Type: application/zip" --data-binary "@$ZIP" )
if [ "$code" != "201" ]; then
    echo "FAIL: uploading the asset returned HTTP $code"
    head -c 400 "$CONF.out"; echo
    echo "The release exists but has no asset, which SFAF_Updater treats as no"
    echo "update at all. Attach the zip by hand or delete the release."
    exit 1
fi

echo
echo "Released: $HTML"
echo
# WHAT THIS LINE USED TO SAY WAS FALSE, and somebody acted on it (3.73.0).
# "or at once from Dashboard > Updates" was wrong: WordPress's own Check again
# calls wp_clean_update_cache(), which deletes update_core, update_plugins and
# update_themes and does NOT touch sfaf_updater_release. So the forced check
# fired our filter and our filter answered from its own twelve-hour cache.
# Pressing it could never work. The plugin has a control that does now, and
# this says where it is rather than pointing at WordPress's.
echo "Sites see it within twelve hours on their own."
echo "To install it now: Plugins > SFAF Calendar > Check for updates."
