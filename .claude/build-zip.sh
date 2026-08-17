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
