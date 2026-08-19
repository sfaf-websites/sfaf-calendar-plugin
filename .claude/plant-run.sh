#!/usr/bin/env bash
# Plant each fault 3.41.0 fixes and confirm the named check FAILS on it.
# Requires a clean tree: restores with git checkout between plants.
cd "$(dirname "$0")/.." || exit 1

if [ -n "$(git status --porcelain includes public)" ]; then
  echo "REFUSING: includes/ or public/ has uncommitted changes. Commit first."
  exit 2
fi

caught=0; missed=0; unplanted=0
restore() { git checkout -- includes public 2>/dev/null; }
trap restore EXIT

try() { # plant-name, label, check-script
  local plant="$1" label="$2" check="$3"
  restore
  if ! php .claude/plant-one.php "$plant" >/dev/null 2>&1; then
    printf 'NOT PLANTED  %-42s (the planter could not apply it)\n' "$label"
    unplanted=$((unplanted+1)); return
  fi
  if php "$check" >/dev/null 2>&1; then
    printf 'MISSED       %-42s\n' "$label"
    missed=$((missed+1))
  else
    printf 'caught       %-42s\n' "$label"
    caught=$((caught+1))
  fi
}

try nested-form     "nested form: form-nesting-test"   .claude/form-nesting-test.php
try nested-form     "nested form: save-outcome-test"   .claude/save-outcome-test.php
try draft-fallback  "draft fallback unpublishes"       .claude/save-outcome-test.php
try drop-scope      "scope answer not carried"         .claude/save-outcome-test.php
try off-ladder      "size/weight off the type ladder"  .claude/type-scale-sweep.php
try organizer-field "inline organizer field returns"   .claude/organizers-test.php
try organizer-save  "inline organizer save returns"    .claude/organizers-test.php

restore
echo "-------------------------------------------"
echo "caught: $caught   missed: $missed   not planted: $unplanted"
[ $missed -eq 0 ] && [ $unplanted -eq 0 ] || exit 1
