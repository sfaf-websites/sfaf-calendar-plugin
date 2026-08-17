#!/usr/bin/env bash
# Prove the private-events test can actually SEE the slug faults.
#
# A checker is worth nothing until it has failed on purpose. The version of
# private-events-test.php before 3.33.0 passed every assertion while a private
# event kept answering on its public address, because it stubbed wp_update_post
# and so could never fire post_updated. So each fault below is planted in the
# real source, the test is run, and the run MUST fail.
#
#     bash .claude/private-slug-fault-check.sh
#
# Faults, one per direction plus the two guards:
#   1  randomize_slug() stops deleting the retained old slug  -> going private
#   2  set() clears old slugs on the way back to public       -> coming back
#   3  readable_base() hands out the seed's token             -> enumeration
#   4  the old_slug_redirect_post_id filter is not registered -> the guard
set -u

cd "$( dirname "${BASH_SOURCE[0]}" )/.." || exit 1

PRIV=includes/class-sfaf-privacy.php
BACKUP=.claude/.fault-backup-privacy.php
PASS=0
FAIL=0

cp "$PRIV" "$BACKUP" || exit 1
restore() { cp "$BACKUP" "$PRIV"; }
trap 'restore; rm -f "$BACKUP"' EXIT

# Run the test. Echo PLANTED-FAIL when it fails, PLANTED-PASS when it passes.
run_test() {
    if php .claude/private-events-test.php >/dev/null 2>&1; then
        echo "PLANTED-PASS"
    else
        echo "PLANTED-FAIL"
    fi
}

expect_fail() {
    local label="$1"
    local result
    result="$( run_test )"
    if [ "$result" = "PLANTED-FAIL" ]; then
        echo "  caught:     $label"
        PASS=$(( PASS + 1 ))
    else
        echo "  NOT CAUGHT: $label"
        FAIL=$(( FAIL + 1 ))
    fi
    restore
}

echo "Planting faults in $PRIV"
echo

# --- Baseline: the unmodified source must PASS, or nothing below means anything.
if [ "$( run_test )" = "PLANTED-PASS" ]; then
    echo "  baseline:   clean source passes"
else
    echo "  BASELINE BROKEN: the clean source already fails. Fix that first."
    exit 1
fi
echo

# --- 1. Going private: the readable address survives.
sed -i 's|^        delete_post_meta( \$post_id, self::OLD_SLUG_META );|        // FAULT: retained old slug left in place|' "$PRIV"
expect_fail "randomize_slug() keeps the old readable address alive"

# --- 2. Coming back: the token is destroyed, breaking every link already sent.
#
# PLANTED AFTER wp_update_post(), NOT BEFORE, and the difference is the whole
# lesson. The same line placed earlier in the branch is harmless: the update
# changes the slug and core records the token again a moment later, so the end
# state is right anyway. Only a delete that runs after the row is created can
# destroy it. The first attempt at this check planted it before and reported a
# fault it could not see, which is the failure this whole file exists to catch.
sed -i 's|^            delete_post_meta( \$post_id, self::PREV_SLUG_META );|            delete_post_meta( $post_id, self::PREV_SLUG_META );\n            delete_post_meta( $post_id, self::OLD_SLUG_META ); // FAULT|' "$PRIV"
expect_fail "set() clears old slugs after restoring the address, so donor links break"

# --- 3. Enumeration: occurrences named from the seed's token.
sed -i "s|            return ( '' !== \$prev ) ? \$prev : sanitize_title( \$post->post_title );|            return (string) \$post->post_name; // FAULT|" "$PRIV"
expect_fail "readable_base() hands out the seed's token as the occurrence base"

# --- 4. The independent guard is never registered.
sed -i "s|^        add_filter( 'old_slug_redirect_post_id'|        // FAULT: add_filter( 'old_slug_redirect_post_id'|" "$PRIV"
expect_fail "the old_slug_redirect_post_id guard is not registered"

echo
echo "caught $PASS of $(( PASS + FAIL )) planted faults"
[ "$FAIL" -eq 0 ] || exit 1
