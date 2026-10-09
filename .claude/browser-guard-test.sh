#!/usr/bin/env bash
# The browser guard, made to fail on purpose. No Chrome: each case is a command
# that does the thing a browser check might, in a few seconds.
cd "$(dirname "$0")/.." || exit 1
fails=0

guard() { powershell.exe -NoProfile -ExecutionPolicy Bypass -File .claude/browser-guard.ps1 "$@"; }

expect() { # what, wanted exit code, wanted text, actual exit code, actual output
  if [ "$4" -ne "$2" ] || ! printf '%s' "$5" | grep -q "$3"; then
    echo "FAIL: $1: exit $4 (wanted $2)"; printf '%s\n' "$5" | sed 's/^/    /'
    fails=$((fails+1))
  fi
}

out=$(guard -CeilingMB 200 -ReserveMB 0 -Command 'php -r "echo \"its own line\n\"; exit(7);"'); rc=$?
expect "a failing check keeps its code and its last line" 7 "its own line" $rc "$out"
[ "$(printf '%s\n' "$out" | tail -1)" = "its own line" ] || { echo "FAIL: the check's line is not last"; fails=$((fails+1)); }

out=$(guard -CeilingMB 200 -ReserveMB 999999 -WaitSeconds 0 -Command 'php -v'); rc=$?
expect "no room to start" 4 "NOT STARTED" $rc "$out"

out=$(guard -CeilingMB 200 -ReserveMB 0 -TimeoutSeconds 3 -Command 'php -r "sleep(60);"'); rc=$?
expect "a hung check is ended" 6 "ENDED after 3s" $rc "$out"

out=$(guard -CeilingMB 100 -ReserveMB 0 -Command 'php -d memory_limit=-1 -r "$s = str_repeat(\"x\", 300 * 1024 * 1024); echo strlen($s);"'); rc=$?
expect "a check past the ceiling is refused, and fails even if it exits 0" 3 "reached the 100 MB ceiling" $rc "$out"

# A process the check starts and does not wait for, as Chrome left nine times.
out=$(guard -CeilingMB 200 -ReserveMB 0 -Command "php -r \"pclose(popen('start /b ping -n 60 127.0.0.1', 'r')); echo 'returned';\""); rc=$?
expect "a process left running is ended" 0 "[0-9] left running and ended" $rc "$out"

[ $fails -eq 0 ] || exit 1
echo "browser guard: a check's own failure, no room, a hang, the ceiling and a leftover process each caught."
