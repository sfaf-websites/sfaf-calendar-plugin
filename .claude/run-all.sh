#!/usr/bin/env bash
# Run every check in .claude and report one line each. Non-zero exit if any fail.
cd "$(dirname "$0")/.." || exit 1
fails=0
pass=0

run() { # label, command...
  local label="$1"; shift
  local out rc
  out="$("$@" 2>&1)"; rc=$?
  if [ $rc -eq 0 ]; then
    printf 'PASS  %-32s %s\n' "$label" "$(printf '%s' "$out" | tail -1 | cut -c1-72)"
    pass=$((pass+1))
  else
    printf 'FAIL  %-32s rc=%s\n' "$label" "$rc"
    # HEAD AND TAIL, BECAUSE NEITHER ALONE IS THE FAILURE. This showed only the
    # tail, and the checks here disagree about where they put the bad news: the
    # test harnesses print their problems last, date-callsite-sweep prints its
    # count FIRST and then two long benign listings. So a tail of that sweep is
    # a page of things that are fine, which is how 3.57.0 came to report its own
    # regression as a pre-existing failure in files it had never touched.
    lines=$( printf '%s\n' "$out" | wc -l )
    if [ "$lines" -le 26 ]; then
      printf '%s\n' "$out" | sed 's/^/        /'
    else
      printf '%s\n' "$out" | head -10 | sed 's/^/        /'
      printf '        ... %s more lines ...\n' "$(( lines - 24 ))"
      printf '%s\n' "$out" | tail -14 | sed 's/^/        /'
    fi
    fails=$((fails+1))
  fi
}

echo "=== PHP harnesses ==="
for f in .claude/*.php; do
  case "$(basename "$f")" in
    audit-callables.php) continue ;;   # run separately, with its self-test
    plant-one.php) continue ;;         # a fault planter, not a check
    plant-follow.php) continue ;;      # likewise, for follow-test.php
  esac
  run "$(basename "$f" .php)" php "$f"
done

echo
echo "=== callable audit ==="
run "audit-callables --self-test" php .claude/audit-callables.php --self-test
run "audit-callables ."          php .claude/audit-callables.php .

echo
# The glob above already ran this one against the real sources. This is its
# self-test, which is the half that proves the specificity arithmetic under it,
# and it caught a wrong expectation of mine the first time it ran.
echo "=== cascade arithmetic ==="
run "filter-bar-test --self-test" php .claude/filter-bar-test.php --self-test

echo
# The DOM reader under request-picture-picker-test, for the same reason: the
# picker is decided by parsing what was rendered, so a parser that cannot see a
# control would report its absence rather than its own mistake.
echo "=== the picker's reader ==="
run "picture-picker --self-test" php .claude/request-picture-picker-test.php --self-test

echo
echo "=== PHP lint ==="
run "lint-php" bash .claude/lint-php.sh .

echo
echo "=== JS ==="
for f in .claude/*.js; do
  case "$(basename "$f")" in
    build-email-icons.js) continue ;;  # a generator, not a test
    build-favicon.js) continue ;;      # likewise; its check is --preview
  esac
  run "$(basename "$f")" node "$f"
done
for f in public/js/*.js admin/js/*.js; do
  run "node --check $(basename "$f")" node --check "$f"
done
# The DOM stub and the selector matcher under prefill-image-test.js, proved
# before the assertions above are allowed to rest on them. Same reason
# filter-bar-test carries one: a reader that cannot see the thing it is
# checking reports its absence rather than its own mistake.
run "prefill-image-test --self-test" node .claude/prefill-image-test.js --self-test

echo
echo "=== shell guards ==="
run "guard-test" bash .claude/guard-test.sh .claude/guard-destructive.sh
run "private-slug-fault-check" bash .claude/private-slug-fault-check.sh

echo
echo "-------------------------------------------"
echo "passed: $pass   failed: $fails"
[ $fails -eq 0 ] || exit 1
