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
    printf '%s\n' "$out" | tail -15 | sed 's/^/        /'
    fails=$((fails+1))
  fi
}

echo "=== PHP harnesses ==="
for f in .claude/*.php; do
  case "$(basename "$f")" in
    audit-callables.php) continue ;;   # run separately, with its self-test
    plant-one.php) continue ;;         # a fault planter, not a check
  esac
  run "$(basename "$f" .php)" php "$f"
done

echo
echo "=== callable audit ==="
run "audit-callables --self-test" php .claude/audit-callables.php --self-test
run "audit-callables ."          php .claude/audit-callables.php .

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

echo
echo "=== shell guards ==="
run "guard-test" bash .claude/guard-test.sh .claude/guard-destructive.sh
run "private-slug-fault-check" bash .claude/private-slug-fault-check.sh

echo
echo "-------------------------------------------"
echo "passed: $pass   failed: $fails"
[ $fails -eq 0 ] || exit 1
