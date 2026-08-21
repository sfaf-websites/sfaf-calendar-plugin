#!/usr/bin/env bash
# Parse EVERY .php file with a real PHP parser and fail on the first error.
#
# WHY THIS EXISTS. 3.2.0 shipped a parse error and could not be activated. Every
# pre-build check in use at the time proved BALANCE (braces, parens, PHP tags,
# declaration depth, callable existence) and none of them proved VALIDITY, so a
# line reading `->save_manager_fields_from_post( , ,  );` passed all of them:
# it is perfectly balanced and correctly nested, and invalid only at the
# expression level. Nothing had ever parsed the file, because there was no PHP
# binary on the machine.
#
# There is one now. Run this before every build, alongside the callable audit.
#
# Usage:  bash .claude/lint-php.sh [directory]
#         defaults to the repo root, skipping the archived zips folder.

set -uo pipefail

PHP="${PHP_BIN:-$LOCALAPPDATA/Programs/php/php.exe}"
if ! command -v "$PHP" >/dev/null 2>&1; then
    if command -v php >/dev/null 2>&1; then
        PHP=php
    else
        echo "FAIL: no PHP binary. Install one before building; do not fall back to brace counting."
        exit 2
    fi
fi

ROOT="${1:-.}"
echo "Linting with: $("$PHP" -v | head -1)"
echo "Root: $ROOT"

fails=0
count=0
while IFS= read -r f; do
    count=$((count + 1))
    if ! out=$("$PHP" -l "$f" 2>&1); then
        echo "PARSE ERROR  $f"
        echo "    $out"
        fails=$((fails + 1))
    fi
done < <(find "$ROOT" -type f -name '*.php' \
            -not -path '*/Old Calendar Files/*' \
            -not -path '*/.build-stage/*' \
            -not -path '*/.git/*' | sort)

echo
echo "parsed: $count    failures: $fails"
if [ "$fails" -ne 0 ]; then
    echo "BUILD MUST NOT PROCEED."
    exit 1
fi
echo "every PHP file parses."
