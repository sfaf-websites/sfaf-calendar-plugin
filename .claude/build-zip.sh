#!/usr/bin/env bash
# Stage and zip the plugin.
#
# bsdtar, not PowerShell: Compress-Archive writes backslash entries that break
# on a Linux host. The staged tree is what gets zipped and what gets audited,
# so the checks run against what actually ships rather than the working tree.
#
#     bash .claude/build-zip.sh 3.33.0
set -eu

VERSION="${1:?usage: build-zip.sh X.Y.Z}"
ROOT="$( cd "$( dirname "${BASH_SOURCE[0]}" )/.." && pwd )"
STAGE="$ROOT/.build-stage"
OUT="$ROOT/sfaf-calendar-$VERSION.zip"

# THE VERSION IS CHECKED, NOT TRUSTED.
#
# Version discipline bumps three places by hand and this script took a fourth
# value on the command line, so a zip could be named for a version the plugin
# did not claim. That mattered little while the zip was uploaded by hand. It
# matters now: the release tag is derived from this argument, and the updater
# compares the tag against SFAF_VERSION, so a mismatch means either an update
# nobody can install or one that installs and then offers itself again forever.
hdr=$( sed -n 's/^ \* Version: \(.*\)$/\1/p' "$ROOT/sfaf-calendar.php" | head -1 )
def=$( sed -n "s/^define( 'SFAF_VERSION', '\(.*\)' );$/\1/p" "$ROOT/sfaf-calendar.php" | head -1 )
tag=$( sed -n 's/^Stable tag: \(.*\)$/\1/p' "$ROOT/readme.txt" | head -1 )
fail=0
for pair in "plugin header:$hdr" "SFAF_VERSION:$def" "readme Stable tag:$tag"; do
    where="${pair%%:*}"; got="${pair#*:}"
    if [ "$got" != "$VERSION" ]; then
        echo "FAIL: $where says '$got', you asked for '$VERSION'"
        fail=1
    fi
done
if [ "$fail" -ne 0 ]; then
    echo "Nothing was built. Bump every place, or build the version they agree on."
    exit 1
fi
echo "version $VERSION agrees in all three places."

rm -rf "$STAGE"
mkdir -p "$STAGE/sfaf-calendar"

cp "$ROOT/sfaf-calendar.php" "$STAGE/sfaf-calendar/"
cp "$ROOT/readme.txt"        "$STAGE/sfaf-calendar/"
cp -r "$ROOT/admin"          "$STAGE/sfaf-calendar/"
cp -r "$ROOT/includes"       "$STAGE/sfaf-calendar/"
cp -r "$ROOT/public"         "$STAGE/sfaf-calendar/"
cp -r "$ROOT/templates"      "$STAGE/sfaf-calendar/"

rm -f "$OUT"
( cd "$STAGE" && /c/Windows/System32/tar.exe -a -c -f "$OUT" sfaf-calendar )

echo "built $OUT"
/c/Windows/System32/tar.exe -tf "$OUT" | wc -l | sed 's/^/entries: /'

if /c/Windows/System32/tar.exe -tf "$OUT" | grep -q '\\'; then
    echo "FAIL: the zip contains backslash entries"
    exit 1
fi
echo "no backslash entries."

# THE RELEASE, WHICH IS WHAT MAKES THE UPDATE APPEAR.
#
# The tag is this version and the asset is this zip. SFAF_Updater accepts only
# an asset named sfaf-calendar-X.Y.Z.zip, so publishing a release without
# attaching this file produces no update rather than a wrong one.
cat <<RELEASE

To publish this as an update, from the PUBLIC repository clone:

    gh release create $VERSION "$OUT" \\
        --repo sfaf-websites/sfaf-calendar-plugin \\
        --title "$VERSION" \\
        --notes-file <(sed -n '/^= $VERSION =\$/,/^= /p' "$ROOT/readme.txt" | sed '\$d')

Sites see it within twelve hours, or at once from Dashboard > Updates.
RELEASE
