<?php
/**
 * THE RSVP TOGGLE DECIDES WHERE THE CALENDAR FILE COMES FROM.
 *
 *     php .claude/rsvp-toggle-calendar-test.php
 *     php .claude/rsvp-toggle-calendar-test.php --self-test
 *
 * WHY THIS EXISTS, AND WHY IT IS WRITTEN NOW. "Accept RSVPs" moved out of the
 * Capacity card and into the Location card in 3.96.0. The control, the meta and
 * the save path are unchanged, which is easy to say and easy to be wrong about,
 * and the thing that breaks quietly if it IS wrong is not the capacity. It is
 * the calendar file.
 *
 * THE RULE, WHICH IS A PAIR AND ONLY MAKES SENSE AS ONE:
 *
 *     RSVPs ON   the event page's Add to Calendar button is REMOVED, because
 *                the registration confirmation already carries both
 *                destinations at the moment the place is actually held
 *     RSVPs OFF  there is no confirmation, so the button is the only route
 *                and it stays
 *
 * So if the toggle stops being saved, an event with registrations on loses its
 * button AND its confirmation, and the calendar route disappears entirely with
 * nothing on any screen to show for it. That is the failure this file is for:
 * not "the capacity box moved", but "the one surviving calendar route was cut
 * by a layout change".
 *
 * BEFORE AND AFTER THE MOVE ARE THE SAME ASSERTIONS. There is nothing here
 * about which card draws the control, because that is exactly what must not
 * matter. What is asserted is that the marker still travels with the control,
 * that the save is still guarded by that marker, and that the confirmation
 * still carries the file. A test naming the Capacity card would have had to be
 * rewritten by the move and would have proved nothing about it.
 */

$root = dirname( __DIR__ );
define( 'ABSPATH', __DIR__ );

$self_test = in_array( '--self-test', $argv, true );
$fails     = array();

function check( $ok, $why ) {
    global $fails;
    if ( ! $ok ) { $fails[] = $why; }
}

/* ---------------------------------------------------------------------------
 * WordPress, in miniature.
 * ------------------------------------------------------------------------ */
$GLOBALS['pmeta'] = array();

function get_post_meta( $id, $key, $single = false ) {
    return isset( $GLOBALS['pmeta'][ $id ][ $key ] ) ? $GLOBALS['pmeta'][ $id ][ $key ] : '';
}
function update_post_meta( $id, $key, $v ) { $GLOBALS['pmeta'][ $id ][ $key ] = $v; return true; }

/*
 * sfaf_show_feature() IS THE SECOND HALF OF THE QUESTION and is stubbed at its
 * real default: absent meta means ON. Getting this backwards would make every
 * assertion below pass for the wrong reason, so it is written the way the
 * shipped function documents rather than the way that is convenient.
 */
function sfaf_show_feature( $post_id, $feature ) {
    $v = get_post_meta( $post_id, '_uc_show_' . $feature, true );
    return ( '' === $v ) ? true : ( '1' === (string) $v );
}
function sfaf_event_takes_rsvps( $post_id ) {
    return '1' === (string) get_post_meta( $post_id, '_uc_rsvp_enabled', true )
        && sfaf_show_feature( $post_id, 'rsvp' );
}

$EVENT = 42;

/* =========================================================================
 * 1. THE PAIR: THE BUTTON AND THE CONFIRMATION SWAP PLACES.
 * ====================================================================== */

/* RSVPs OFF: the page button is the only route and must be offered. */
$GLOBALS['pmeta'] = array();
check( ! sfaf_event_takes_rsvps( $EVENT ),
    'an event with nothing stored is taking registrations' );

/* RSVPs ON: the page button goes, so the confirmation has to carry the file. */
update_post_meta( $EVENT, '_uc_rsvp_enabled', '1' );
check( sfaf_event_takes_rsvps( $EVENT ),
    'the RSVP toggle is on and the event does not report as taking registrations' );

/* AND THE SHOW TICK IS THE OTHER HALF. Turning the RSVP control off puts the
 * button back, because there is no confirmation any more either. */
update_post_meta( $EVENT, '_uc_show_rsvp', '0' );
check( ! sfaf_event_takes_rsvps( $EVENT ),
    'hiding the RSVP control leaves the event still "taking registrations", so the page button stays hidden and nothing replaces it' );
update_post_meta( $EVENT, '_uc_show_rsvp', '1' );
check( sfaf_event_takes_rsvps( $EVENT ), 'showing the RSVP control again did not restore it' );

/* =========================================================================
 * 2. THE BUTTON IS REMOVED BY THAT ANSWER, AND ONLY BY IT.
 * ====================================================================== */
$tpl = file_get_contents( $root . '/includes/sfaf-template-functions.php' );

check( (bool) preg_match( '/function sfaf_add_to_calendar\([^)]*\)\s*\{\s*\n\s*if \( sfaf_event_takes_rsvps\( \$post_id \) \) \{\s*\n\s*return \'\';/', $tpl ),
    'the event page button no longer stands down when registrations are on, so an event offers two calendar routes' );

/* =========================================================================
 * 3. SO THE CONFIRMATION MUST CARRY IT.
 * ====================================================================== */
$notif = file_get_contents( $root . '/includes/class-sfaf-notifications.php' );

/* THE .ics IS BUILT IN THE CONFIRMATION, and through the one builder that knows
 * whether this recipient may have the join copy. See PROJECT.md 2. */
check( (bool) preg_match( '/\$ics\s*=\s*SFAF_Online::ics_url_with_link\( \$event_id, self::person_format\( \$person \) \);/', $notif ),
    'the confirmation no longer builds a calendar file, so an event with registrations on has no calendar route at all' );

/* AND IT IS ACTUALLY OFFERED, in both halves of the message. */
$conf = '';
$at   = strpos( $notif, 'private static function build_confirmation(' );
if ( false !== $at ) {
    $end  = strpos( $notif, 'private static function build_', $at + 40 );
    $conf = ( false === $end ) ? substr( $notif, $at ) : substr( $notif, $at, $end - $at );
}
check( '' !== $conf, 'the confirmation builder could not be read, so nothing below was checked' );
check( substr_count( $conf, '$ics' ) >= 2,
    'the confirmation builds a calendar file and then does not use it' );

/* =========================================================================
 * 4. THE TOGGLE IS STILL SAVED, WHATEVER CARD DREW IT.
 * ====================================================================== */
$portal = file_get_contents( $root . '/includes/class-sfaf-portal.php' );

/* THE MARKER TRAVELS WITH THE CONTROL. An unticked checkbox submits nothing at
 * all, so without this "nobody ticked it" and "this form did not ask" are the
 * same POST, and saving from a screen that did not show the control would clear
 * it. This is the mechanism the move had to leave alone. */
/* MATCHED WITHOUT A CHARACTER BUDGET. This looked for the marker within 600
 * characters of the case label, and 3.97.0 put a comment between them that
 * pushed it past the window, so the check failed on a control that was
 * perfectly correct. A distance in characters is not the relationship being
 * asserted; the relationship is that the marker and the checkbox are in the
 * same case, in that order. */
if ( preg_match( '/case \'rsvp_enabled\':([\s\S]*?)\n\s*break;/', $portal, $m ) ) {
    $case = $m[1];
    $at_marker = strpos( $case, 'name="uc_rsvp_toggle_present"' );
    $at_box    = strpos( $case, 'name="rsvp_enabled"' );
    check( false !== $at_marker && false !== $at_box && $at_marker < $at_box,
        'the "I was on the form" marker no longer travels with the Accept RSVPs control, so a screen that did not draw it can clear it' );
} else {
    check( false, 'the rsvp_enabled case could not be read at all' );
}

/* AND THE SAVE IS GUARDED BY THAT MARKER, not by the checkbox's presence. */
/* THE GUARD, NOT THE LINE THAT FOLLOWS IT. This required the write to be the
 * very next statement after the marker check, and 3.97.0 put the third-party
 * refusal between them, so it failed on a save that is more correct than the
 * one it was written against. What must hold is that EVERY write of the switch
 * is inside the marker's guard. */
if ( preg_match( '/if \( isset\( \$_POST\[\'uc_rsvp_toggle_present\'\] \) \) \{([\s\S]*?)\n        \}/', $portal, $m ) ) {
    $guarded = $m[1];
    $writes_in_guard = substr_count( $guarded, "update_post_meta( \$event_id, '_uc_rsvp_enabled'" );
    $writes_total    = substr_count( $portal, "update_post_meta( \$event_id, '_uc_rsvp_enabled'" );
    check( $writes_in_guard > 0 && $writes_in_guard === $writes_total,
        sprintf(
            'the RSVP toggle is written in %d places and only %d are inside the marker guard, so a form that never showed the control can turn registrations off',
            $writes_total,
            $writes_in_guard
        ) );
} else {
    check( false, 'the RSVP toggle save could not be read at all' );
}

/* =========================================================================
 * 5. AND THE CONTROL IS STILL DRAWN, ON EVERY SHAPE OF EVENT.
 * ====================================================================== */
/* THIS IS THE HALF THE MOVE COULD BREAK. The controls come off one shared list
 * and the guarantee is that every one is rendered exactly once. An imported
 * event leaves the location field early and the catch-all that would otherwise
 * have caught it is inside the Notifications card, which native events only, so
 * an imported event that stopped drawing them here would simply lose them. */
check( (bool) preg_match( "/\\\$draw_rsvp\( array\( 'rsvp_enabled' \) \);/", $portal ),
    'the Location card no longer draws Accept RSVPs' );
check( (bool) preg_match( "/\\\$draw_rsvp\( array\( 'rsvp_enabled', 'capacity' \) \);\s*\n\s*return \\\$rsvp_placed;/", $portal ),
    'an imported event leaves the location field without its RSVP controls, and nothing downstream draws them for it' );
check( (bool) preg_match( "/\\\$draw_rsvp\( array\( 'capacity' \) \);/", $portal ),
    'the Location card no longer draws the in-person capacity' );
/* ONE ROW DRAWS BOTH BOXES (3.98.0). `capacity_online` is still in the shared
 * field list, because that list is what the SAVE reads to decide which fields
 * the form spoke for, but its case renders nothing and the `capacity` case
 * draws the pair. So what is asserted is the markup, not a second draw call. */
check( (bool) preg_match( '/data-uc-online-capacity/', $portal ),
    'the Location card no longer draws the online capacity box' );
check( (bool) preg_match( '/name="capacity_online"/', $portal ),
    'the online capacity has no input, so a hybrid event cannot be given one' );

/* THE CATCH-ALL STILL RUNS AND IS STILL FED WHAT THIS CARD PLACED, which is
 * what keeps "exactly once" true rather than "at least once". */
check( (bool) preg_match( '/render_rsvp_settings\( \$rsvp_ctx, null, \$rsvp_placed \)/', $portal ),
    'the catch-all no longer receives what the Location card already drew, so a setting appears twice or not at all' );
check( (bool) preg_match( '/\$rsvp_placed = array_merge\(\s*\n\s*\$rsvp_placed,\s*\n\s*\(array\) \$this->render_location_field\(/', $portal ),
    'what the Location card placed is thrown away, so the catch-all draws those controls a second time' );

/* AND THE CAPACITY CARD IS GONE. */
check( false === strpos( $portal, '<h2 class="uc-bento-title">Capacity</h2>' ),
    'the Capacity card is back, so the same controls are drawn in two places' );

/* =========================================================================
 * 6. "RSVP" UNDER DISPLAY STAYS DEPENDENT, THROUGH THE SAME SCRIPT.
 * ====================================================================== */
$js = file_get_contents( $root . '/public/js/portal.js' );
/* DOCUMENT WIDE, NOT SCOPED TO A CARD. That is what makes the move invisible to
 * it: the control changed card and the selector did not have to change. */
check( false !== strpos( $js, "document.querySelector('input[name=\"rsvp_enabled\"]')" ),
    'the live dependency looks for Accept RSVPs somewhere other than the whole document, so moving the control broke it' );

/* =========================================================================
 * SELF-TEST.
 * ====================================================================== */
if ( $self_test ) {
    $bad = array();

    $before = count( $fails );
    check( sfaf_event_takes_rsvps( 999999 ), 'deliberate: an untouched event reported as taking registrations' );
    if ( count( $fails ) === $before ) {
        $bad[] = 'check() does not record a failure, so every assertion above is decorative';
    } else {
        array_pop( $fails );
    }

    /* The stub must model BOTH halves, or section 1 proves nothing. */
    $GLOBALS['pmeta'] = array();
    update_post_meta( 7, '_uc_rsvp_enabled', '1' );
    update_post_meta( 7, '_uc_show_rsvp', '0' );
    if ( sfaf_event_takes_rsvps( 7 ) ) {
        $bad[] = 'the stub ignores the show tick, so "takes registrations" is a one-sided question here';
    }

    if ( $bad ) {
        echo "SELF-TEST FAILED\n";
        foreach ( $bad as $b ) { echo '  . ' . $b . "\n"; }
        exit( 1 );
    }
    echo "PASS  rsvp-toggle-calendar --self-test  self-test passed: the reader reports faults and models both halves.\n";
    exit( 0 );
}

if ( $fails ) {
    echo 'FAIL: ' . count( $fails ) . "\n";
    foreach ( array_unique( $fails ) as $f ) { echo '  . ' . $f . "\n"; }
    exit( 1 );
}

echo "the RSVP toggle still decides which of the two calendar routes an event has,\n";
echo "and the controls moved card without changing the marker, the save or the script.\n";
exit( 0 );
