#!/usr/bin/env bash
# chrome-run.js: a call that never exits is ended at the limit, tried again,
# logged, and leaves no Chrome behind; a call that finishes hands back its page.
#
#     bash .claude/chrome-run-test.sh
#
# Each call names its own profile folder, and only Chrome started with that
# folder is counted, so a suite running beside this one cannot fail it.
cd "$(dirname "$0")/.." || exit 1
fails=0
mark="uc-chrome-test-$$-$RANDOM"
prof="$(cygpath -w "$(mktemp -d)")\\$mark"
mine() { # chrome.exe processes started with this test's profile
  powershell.exe -NoProfile -Command "@(Get-CimInstance Win32_Process -Filter \"Name='chrome.exe'\" | Where-Object { \$_.CommandLine -like '*$mark*' }).Count" | tr -d '\r'
}
log="$(mktemp)"

# A headless Chrome with nothing to dump never exits on its own.
out="$(env CHROME_RUN_TIMEOUT=5 CHROME_RUN_TRIES=2 CHROME_RUN_LOG="$(cygpath -w "$log")" node .claude/chrome-run.js --headless --disable-gpu "--user-data-dir=$prof" "file:///C:/Windows/win.ini" 2>/dev/null)"
rc=$?
[ $rc -eq 124 ] || { echo "a call that never exits returned $rc, not 124"; fails=$((fails+1)); }
[ -z "$out" ] || { echo "a call that never exits printed something"; fails=$((fails+1)); }
[ "$(wc -l < "$log")" -eq 2 ] || { echo "the log has $(wc -l < "$log") lines, not one per try"; fails=$((fails+1)); }
[ "$(mine)" = 0 ] || { echo "Chrome was left running after the limit"; fails=$((fails+1)); }

# A call that finishes hands back exactly what Chrome printed.
out="$(env CHROME_RUN_TIMEOUT=60 node .claude/chrome-run.js --headless --disable-gpu "--user-data-dir=$prof" --dump-dom "data:text/html,<p id=x>ok</p>" 2>/dev/null)"
case "$out" in *'<p id="x">ok</p>'*) ;; *) echo "a finished call did not hand back its page: $out"; fails=$((fails+1)) ;; esac
[ "$(mine)" = 0 ] || { echo "Chrome was left running after a finished call"; fails=$((fails+1)); }

rm -f "$log"
[ $fails -eq 0 ] || exit 1
echo "a hung Chrome is ended at the limit and tried again, and nothing is left running; a finished call hands back its page."
