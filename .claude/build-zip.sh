#!/usr/bin/env bash
# Stage and zip the plugin.
#
# bsdtar, not PowerShell: Compress-Archive writes backslash entries that break
# on a Linux host. The staged tree is what gets zipped and what gets audited,
# so the checks run against what actually ships rather than the working tree.
#
#     bash .claude/build-zip.sh 3.33.0
set -eu

VERSION="${1:?usage: build-zip.sh X.Y.Z [--allow-shrink=<path> ...]}"
shift
ROOT="$( cd "$( dirname "${BASH_SOURCE[0]}" )/.." && pwd )"
STAGE="$ROOT/.build-stage"
OUT="$ROOT/sfaf-calendar-$VERSION.zip"

# THE VERSION IS CHECKED, NOT TRUSTED.
#
# Version discipline bumps four places by hand and this script took one more
# value on the command line, so a zip could be named for a version the plugin
# did not claim. That mattered little while the zip was uploaded by hand. It
# matters now: the release tag is derived from this argument, and the updater
# compares the tag against SFAF_VERSION, so a mismatch means either an update
# nobody can install or one that installs and then offers itself again forever.
# Four places since 3.101.0: embed.js carries the version too. The check is its
# own script so a test can run it against a tree that disagrees.
php "$ROOT/.claude/version-check.php" "$ROOT" "$VERSION" || exit 1

# AN EMPTY FILE PARSES (3.105.0). The linter and the callable audit both pass a
# class that has been emptied, so this refuses a build when a shipped file has
# lost more than half its lines since the last commit, or in it. A deliberate
# cut is let through by naming the file: --allow-shrink=includes/class-x.php.
php "$ROOT/.claude/shrink-check.php" "$ROOT" "$@" || exit 1

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

Sites see it within twelve hours on their own.
To install it now: Plugins > SFAF Calendar > Check for updates.
RELEASE
