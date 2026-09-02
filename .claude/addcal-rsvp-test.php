<?php
/**
 * ADD TO CALENDAR AND RSVP (3.64.0).
 *
 * THE RULE: if an event accepts registrations, its page has no Add to Calendar
 * button. The calendar file goes out with the registration confirmation, which
 * it already did. If the event accepts no registrations the button shows as it
 * always has, because then it is the only route there is.
 *
 * WHAT THIS PROVES THAT READING THE CODE DOES NOT. Three of the four checks
 * are about a state nobody can produce by hand quickly: the tick in caladmin
 * is disabled, a disabled input posts NOTHING, and the save must therefore
 * not treat "nothing" as "unticked". That last one is the whole reason the
 * save has a test of its own rather than trusting the attribute. Get it wrong
 * and every save of an event with registrations quietly clears the manager's
 * Add to calendar setting, and nobody finds out until they switch
 * registrations off months later.
 *
 * Usage: php .claude/addcal-rsvp-test.php
 */

/* =====================================================================
 * A WordPress small enough to answer these four questions.
 *
 * THE STUB UPHOLDS THE RULE UNDER TEST ONLY WHERE IT MUST. 3.36.0 shipped a
 * stub of wp_update_post() that quietly kept the very behaviour whose removal
 * was being tested, so the suite passed over a change that had not happened.
 * Everything here is storage and nothing here is logic: get_post_meta and
 * update_post_meta read and write one array, and every decision under test is
 * made by the real functions loaded below.
 * ================================================================== */

$GLOBALS['meta'] = array();

function get_post_meta( $post_id, $key, $single = false ) {
    $v = isset( $GLOBALS['meta'][ $post_id ][ $key ] ) ? $GLOBALS['meta'][ $post_id ][ $key ] : '';
    return $single ? $v : array( $v );
}

function update_post_meta( $post_id, $key, $value ) {
    $GLOBALS['meta'][ $post_id ][ $key ] = (string) $value;
    return true;
}

function delete_post_meta( $post_id, $key ) {
    unset( $GLOBALS['meta'][ $post_id ][ $key ] );
    return true;
}

/* =====================================================================
 * The two functions under test, lifted from source rather than retyped.
 *
 * sfaf-template-functions.php is 3,000 lines and pulls in the whole plugin,
 * so the two definitions are read out of it by name. Reading them from the
 * file is what makes this a test of the shipped code: a copy here would pass
 * forever after somebody edited the original.
 * ================================================================== */

function lift( $file, $name ) {
    $src = file_get_contents( $file );
    $pos = strpos( $src, "\nfunction $name(" );
    if ( false === $pos ) {
        fwrite( STDERR, "cannot find function $name() in $file\n" );
        exit( 2 );
    }
    $open = strpos( $src, '{', $pos );
    $i    = $open;
    $d    = 0;
    $len  = strlen( $src );
    while ( $i < $len ) {
        if ( '{' === $src[ $i ] ) { $d++; }
        if ( '}' === $src[ $i ] ) { $d--; if ( 0 === $d ) { $i++; break; } }
        $i++;
    }
    return substr( $src, $pos, $i - $pos );
}

$tf = dirname( __DIR__ ) . '/includes/sfaf-template-functions.php';
eval( lift( $tf, 'sfaf_show_feature' ) );
eval( lift( $tf, 'sfaf_event_takes_rsvps' ) );

/*
 * THE SAVE'S TEST, TAKEN FROM THE SAVE.
 *
 * save_event_from_post() cannot be called here: it is a private method on a
 * 15,000-line class that wants $_POST, a nonce, a WP_User and a database. What
 * IS testable, and what the defect would live in, is the one line deciding
 * whether show_calendar is read at all. So the loop is reproduced with the
 * same order and the same condition, and the check below asserts that the
 * condition in the source still matches this one, character for character.
 */
function save_toggles( $event_id, $post ) {
    $toggles = array(
        'show_rsvp'      => '_uc_show_rsvp',
        'show_donate'    => '_uc_show_donate',
        'show_social'    => '_uc_show_social',
        'show_calendar'  => '_uc_show_calendar',
        'show_reminders' => '_uc_show_reminders',
    );
    foreach ( $toggles as $field => $key ) {
        if ( 'show_calendar' === $field && sfaf_event_takes_rsvps( $event_id ) ) {
            continue;
        }
        update_post_meta( $event_id, $key, isset( $post[ $field ] ) ? '1' : '0' );
    }
}

/* ================================================================== */

$tests  = 0;
$failed = 0;

function check( $label, $got, $want ) {
    global $tests, $failed;
    $tests++;
    $ok = $got === $want;
    if ( ! $ok ) { $failed++; }
    printf( "  %s  %-64s got %s want %s\n", $ok ? 'ok  ' : 'FAIL', $label,
        var_export( $got, true ), var_export( $want, true ) );
}

function reset_event( $id, $meta ) {
    $GLOBALS['meta'][ $id ] = $meta;
}

echo "THE PREDICATE\n";

reset_event( 1, array() );
check( 'a new event with nothing stored takes no registrations',
    sfaf_event_takes_rsvps( 1 ), false );

reset_event( 1, array( '_uc_rsvp_enabled' => '1' ) );
check( 'registration on, Display never saved: takes registrations',
    sfaf_event_takes_rsvps( 1 ), true );

reset_event( 1, array( '_uc_rsvp_enabled' => '1', '_uc_show_rsvp' => '0' ) );
check( 'registration on but the RSVP control is hidden: it does not',
    sfaf_event_takes_rsvps( 1 ), false );

reset_event( 1, array( '_uc_rsvp_enabled' => '0', '_uc_show_rsvp' => '1' ) );
check( 'the control is shown but registration is off: it does not',
    sfaf_event_takes_rsvps( 1 ), false );

echo "\nTHE SAVE\n";

/*
 * THE ONE THAT MATTERS. The tick is disabled in the browser, so the POST
 * carries no show_calendar at all. Without the skip that reads as "unticked"
 * and overwrites a setting the manager never touched.
 */
reset_event( 2, array( '_uc_rsvp_enabled' => '1', '_uc_show_calendar' => '1' ) );
save_toggles( 2, array( 'show_rsvp' => '1', 'show_donate' => '1' ) );
check( 'a disabled tick does not clear the stored value',
    get_post_meta( 2, '_uc_show_calendar', true ), '1' );

reset_event( 2, array( '_uc_rsvp_enabled' => '1', '_uc_show_calendar' => '0' ) );
save_toggles( 2, array( 'show_rsvp' => '1', 'show_calendar' => '1' ) );
check( 'a tick posted anyway is not read while registrations are on',
    get_post_meta( 2, '_uc_show_calendar', true ), '0' );

reset_event( 2, array( '_uc_rsvp_enabled' => '0', '_uc_show_calendar' => '1' ) );
save_toggles( 2, array( 'show_rsvp' => '1' ) );
check( 'with registrations off the tick is read normally, unticked',
    get_post_meta( 2, '_uc_show_calendar', true ), '0' );

reset_event( 2, array( '_uc_rsvp_enabled' => '0', '_uc_show_calendar' => '0' ) );
save_toggles( 2, array( 'show_calendar' => '1' ) );
check( 'with registrations off the tick is read normally, ticked',
    get_post_meta( 2, '_uc_show_calendar', true ), '1' );

/*
 * SWITCHING REGISTRATION ON IN THE SAME SAVE. save_rsvp_settings_from_post()
 * runs before this loop, so _uc_rsvp_enabled is already the new value by the
 * time show_calendar is reached. The event was not taking registrations when
 * the form was drawn, so the tick was live and posted; it must not be read,
 * because by the time the save finishes the event IS taking them.
 */
reset_event( 3, array( '_uc_rsvp_enabled' => '1', '_uc_show_calendar' => '1' ) );
save_toggles( 3, array( 'show_rsvp' => '1', 'show_calendar' => '1' ) );
check( 'turning registration on in the same save keeps the stored value',
    get_post_meta( 3, '_uc_show_calendar', true ), '1' );

/*
 * AND THE OTHER ORDER, which is the one that would break if somebody sorted
 * $toggles alphabetically: show_rsvp has to be written before show_calendar is
 * tested, or the predicate reads the PREVIOUS save's visibility.
 */
reset_event( 4, array( '_uc_rsvp_enabled' => '1', '_uc_show_rsvp' => '0', '_uc_show_calendar' => '1' ) );
save_toggles( 4, array( 'show_rsvp' => '1', 'show_calendar' => '0' ) );
check( 'show_rsvp is written before show_calendar is tested',
    get_post_meta( 4, '_uc_show_calendar', true ), '1' );

echo "\nTHE SOURCE STILL SAYS WHAT THIS TEST SAYS\n";

$portal = file_get_contents( dirname( __DIR__ ) . '/includes/class-sfaf-portal.php' );
check( 'save_event_from_post() skips show_calendar on the predicate',
    (bool) strpos( $portal, "if ( 'show_calendar' === \$field && sfaf_event_takes_rsvps( \$event_id ) ) {" ), true );
check( 'show_rsvp is still listed before show_calendar',
    strpos( $portal, "'show_rsvp'       => '_uc_show_rsvp'" ) < strpos( $portal, "'show_calendar'   => '_uc_show_calendar'" ), true );

$tfsrc = file_get_contents( $tf );
check( 'sfaf_add_to_calendar() returns nothing when the event takes RSVPs',
    (bool) preg_match(
        '/function sfaf_add_to_calendar\([^)]*\)\s*\{\s*if \( sfaf_event_takes_rsvps\( \$post_id \) \) \{\s*return \'\';/',
        $tfsrc
    ), true );
check( 'the predicate is not written out longhand anywhere any more',
    substr_count( $tfsrc, "_uc_rsvp_enabled', true ) !== '1'" ), 0 );

/*
 * THE CONFIRMATION IS THE OTHER ROUTE, and the removal is only safe while it
 * exists. If the calendar links ever leave the registration email, this test
 * is the thing that should stop the build.
 */
$notify = file_get_contents( dirname( __DIR__ ) . '/includes/class-sfaf-notifications.php' );
check( 'the registration confirmation still carries Add to calendar',
    (bool) strpos( $notify, "SFAF_Email::label( 'Add to calendar' )" ), true );

echo "\nTHE EDITOR SAYS SO RATHER THAN LOOKING SETTABLE\n";

check( 'the Display card asks the same question the save asks',
    (bool) strpos( $portal, '$takes_rsvps = $event_id ? sfaf_event_takes_rsvps( $event_id ) : false;' ), true );
check( 'the tick is disabled, not hidden',
    (bool) strpos( $portal, "echo \$lock ? ' disabled' : '';" ), true );
check( 'and a line says where the calendar link goes instead',
    (bool) strpos( $portal, 'the calendar link goes out with the registration confirmation instead.' ), true );

/*
 * THE SENTENCE NAMES THE CAUSE BEFORE THE CONSEQUENCE (3.64.2). It read
 * "The calendar link goes out with the registration confirmation instead",
 * which is only half of what the reader needs: a greyed tick and a sentence
 * about email, and the connection between them left to them.
 */
check( 'and it says WHY before it says what happens instead',
    (bool) strpos( $portal, 'Because this event takes RSVPs, the calendar link goes out' ), true );

/*
 * AND THE TICK SITS UNDER THE ONE THAT GREYS IT (3.64.2). Order in $feat is
 * order on the screen, so this is the whole of the assertion.
 */
check( 'Add to calendar is the row directly under RSVP',
    (bool) strpos( $portal, "'show_rsvp' => 'RSVP', 'show_calendar' => 'Add to calendar'" ), true );

$js = file_get_contents( dirname( __DIR__ ) . '/public/js/portal.js' );
check( 'portal.js keeps the two in step without a save',
    (bool) strpos( $js, "run('calendarTick', initCalendarTick);" ), true );
check( 'and it asks BOTH halves, not just the RSVP switch',
    (bool) strpos( $js, 'rsvp.checked && (!show || show.checked)' ), true );

echo "\n$tests checks, $failed failed\n";
exit( $failed ? 1 : 0 );
