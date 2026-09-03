#!/usr/bin/env bash
# Feed commands through the guard and report which ones it asks about.
# Usage: bash guard-test.sh <path-to-guard>
GUARD="$1"

check() {
  # $1 = expectation: ASK or PASS ; $2 = command
  want="$1"; cmd="$2"
  esc=$(printf '%s' "$cmd" | sed 's/\\/\\\\/g; s/"/\\"/g')
  out=$(printf '{"tool_input":{"command":"%s"}}' "$esc" | bash "$GUARD")
  if [ -n "$out" ]; then got="ASK"; else got="PASS"; fi
  if [ "$want" = "$got" ]; then
    printf '  ok   %-4s  %s\n' "$got" "$(printf '%s' "$cmd" | cut -c1-96)"
  else
    printf '  FAIL want=%s got=%s  %s\n' "$want" "$got" "$(printf '%s' "$cmd" | cut -c1-96)"
    FAILS=$((FAILS+1))
  fi
}

FAILS=0
SCRATCH='C:\Users\DEV~1\AppData\Local\Temp\claude\proj\sess\scratchpad'

echo "== must still ASK: the things this guard exists for =="
check ASK 'git push --force origin main'
check ASK 'git push origin --force'
check ASK 'git push -f origin production-2.0'
check ASK 'git push origin +main:main'
check ASK 'git -C ../other push --force'
check ASK 'git filter-branch --tree-filter rm -f x -- --all'
check ASK 'git filter-repo --path secrets --invert-paths'
check ASK 'bfg --delete-files id_rsa'
check ASK 'git reset --hard origin/main'
check ASK 'git clean -fd'
check ASK 'rm -rf includes'
check ASK 'rm -rf /'
check ASK 'rm -rf "C:/Users/dev/Documents/Apps/Calendar/public"'
check ASK 'cat .env'
check ASK 'Get-Content .env.production'
check ASK 'grep -r "" ~/.aws/credentials.json'
check ASK 'rm -rf $SOMETHING'

echo
echo "== must PASS: ordinary work on this machine =="
check PASS 'cd "C:/Users/dev/Documents/Apps/Calendar" && php -l includes/class-sfaf-portal.php'
check PASS 'bash .claude/lint-php.sh .'
check PASS 'git status --short'
check PASS 'git push origin production-2.0'
check PASS 'git log --oneline -3'
check PASS 'grep -n "uc-bento" public/css/portal.css'
check PASS 'node --check public/js/portal.js'
check PASS "php \"$SCRATCH/audit.php\" ."
check PASS "cd \"C:/Users/dev/Documents/Apps/Calendar\" && php \"$SCRATCH/test-3120.php\" ."
check PASS 'ls -la "Old Calendar Files"'
check PASS 'sed -i "s/3.11.0/3.12.0/" readme.txt'
check PASS 'awk "NR>=10 && NR<=20" includes/class-sfaf-portal.php'

echo
echo "== the false positives being hunted: recursive deletes in a real scratch dir =="
check PASS "rm -rf \"$SCRATCH/staged\""
check PASS "rm -rf $SCRATCH/staged-3120"
check PASS 'rm -rf /tmp/bento.txt'
check PASS 'rm -rf "C:\Users\DEV~1\AppData\Local\Temp\claude\proj\sess\scratchpad\staged"'
check PASS 'Remove-Item -Recurse -Force "C:\Users\DEV~1\AppData\Local\Temp\claude\p\s\scratchpad\staged"'
check PASS 'rm -rf C:/Users/DEV~1/AppData/Local/Temp/claude/p/s/scratchpad/x'

echo
echo "failures: $FAILS"
exit $((FAILS > 0))
