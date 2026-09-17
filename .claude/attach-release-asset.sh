#!/usr/bin/env bash
# ATTACH THE ZIP TO A RELEASE THAT ALREADY EXISTS.
#
#     bash .claude/attach-release-asset.sh 3.96.0
#
# WHY THIS EXISTS. publish.sh creates the release and then uploads the asset,
# which is two API calls, and the second one can fail on its own. It did twice
# during the 3.96.0 release, with HTTP 500 and two different messages, "Error
# saving asset" and "Error creating asset temp dir". Both are GitHub's own
# infrastructure: the same zip uploaded on the third attempt, byte for byte.
#
# WHAT publish.sh SAYS WHEN THAT HAPPENS is "attach the zip by hand or delete
# the release", and it is right that this is the moment to stop: a release with
# no asset is what SFAF_Updater reads as no update at all, so every site sees
# nothing and nothing says why. What it did not have was the thing to run next.
# This is that, and it is IDEMPOTENT: it looks the release up, does nothing if
# an asset is already attached, and uploads only when there is none.
#
# RE-RUNNING publish.sh WOULD NOT WORK, which is the reason this is separate.
# It would try to CREATE a release whose tag already exists and stop at the
# first call with a 422, without ever reaching the upload.
#
# Same credential route and the same curl-config discipline as publish.sh: the
# token comes from the helper `git push` already uses and is written to a mode
# 600 config file, so it never reaches a command line or the process table.
set -eu

ROOT="/c/Users/msapoznikov/Documents/Apps/Calendar"
SLUG="sfaf-websites/sfaf-calendar-plugin"
VERSION="${1:?usage: attach-release-asset.sh X.Y.Z}"
ZIP="$ROOT/sfaf-calendar-$VERSION.zip"

CONF="$( mktemp )"
trap 'rm -f "$CONF" "$CONF.out"' EXIT
chmod 600 "$CONF"
printf 'protocol=https\nhost=github.com\n\n' | git -C "$ROOT" credential fill 2>/dev/null \
    | sed -n 's/^password=\(.*\)$/header = "Authorization: Bearer \1"/p' > "$CONF"
if [ ! -s "$CONF" ]; then
    echo "FAIL: no token."
    exit 1
fi
printf 'header = "Accept: application/vnd.github+json"\nsilent\n' >> "$CONF"

echo "== looking up release $VERSION"
code=$( curl --config "$CONF" -o "$CONF.out" -w '%{http_code}' \
    "https://api.github.com/repos/$SLUG/releases/tags/$VERSION" )
if [ "$code" != "200" ]; then
    echo "FAIL: looking up the release returned HTTP $code"
    head -c 300 "$CONF.out"; echo
    exit 1
fi

HAVE=$( php -r '$d=json_decode(file_get_contents($argv[1]),true); echo count($d["assets"]??array());' "$CONF.out" )
echo "   assets already attached: $HAVE"
if [ "$HAVE" != "0" ]; then
    echo "   nothing to do."
    exit 0
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
    exit 1
fi

echo
echo "Attached. Released: $HTML"
