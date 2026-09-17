<?php
/**
 * HYBRID EVENTS: THREE MODES, TWO CAPACITIES, AND NEVER BOTH HALVES.
 *
 *     php .claude/hybrid-test.php
 *     php .claude/hybrid-test.php --self-test
 *
 * THE LOAD-BEARING ASSERTION IS THE LAST ONE. A hybrid event has a street
 * address AND a meeting link, and the meeting link is a credential: anybody
 * holding it can join, and this calendar carries HIV, substance use and trans
 * health programming. So no message may carry both halves, and which half
 * somebody gets is decided by what THEY said when they registered.
 *
 * THAT IS TWO REFUSALS AT OPPOSITE ENDS, and they have to agree:
 *
 *     SFAF_Notifications::facts()      refuses the ADDRESS to an online registrant
 *     SFAF_Online::joining_html()      refuses the LINK to an in-person one
 *
 * Either one alone leaves a message carrying both, so both are asserted, in
 * both directions, and the plants take them out one at a time.
 *
 * THE GATE IS WRITTEN AS AN ALLOW, NOT A DENY. An empty format is what every
 * non-hybrid event stores, and it is NOT an in-person answer: it means the
 * event never asked. A purely online event's registrants must still be sent
 * the link, so "unless they said in person" would have broken every online
 * event on the site. That case is asserted here explicitly.
 *
 * MODE IS THREE WORDS, NOT TWO TICKS. Two booleans have four combinations and
 * only three mean anything; mode() answers with one of three and hybrid wins
 * the impossible fourth, because hybrid is the mode that KEEPS the address and
 * clearing an address is the change nothing can undo.
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
function delete_post_meta( $id, $key ) { unset( $GLOBALS['pmeta'][ $id ][ $key ] ); return true; }
function esc_url_raw( $u ) { return $u; }
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function sfaf_location_part_keys() {
    return array( '_uc_location_street', '_uc_location_city', '_uc_location_state', '_uc_location_zip' );
}
class SFAF_Venues {
    public static $cleared = array();
    public static function set_for_event( $post_id, $term_id ) {
        if ( ! $term_id ) { self::$cleared[] = (int) $post_id; }
    }
}
/* Enough of the mail builder for joining_html() to produce something readable. */
class SFAF_Email {
    public static function label( $t )       { return '[label]' . $t . "\n"; }
    public static function para( $t )        { return '[para]' . $t . "\n"; }
    public static function small_para( $t )  { return '[small]' . $t . "\n"; }
    public static function button( $u, $l, $v = '' ) { return '[button ' . $u . ']' . $l . "\n"; }
}

require_once $root . '/includes/class-sfaf-online.php';

$EVENT = 42;
$LINK  = 'https://zoom.us/j/98765432100';

$reset = function () use ( $EVENT ) {
    $GLOBALS['pmeta'] = array();
    SFAF_Venues::$cleared = array();
};

/* Give the event a place, so "does the place survive" is a real question. */
$give_place = function () use ( $EVENT ) {
    update_post_meta( $EVENT, '_uc_location', '470 Castro Street' );
    update_post_meta( $EVENT, '_uc_location_street', '470 Castro Street' );
};

/* =========================================================================
 * 1. THREE MODES, AND ONLY THREE.
 * ====================================================================== */
$reset();
check( SFAF_Online::MODE_IN_PERSON === SFAF_Online::mode( $EVENT ),
    'an event with nothing stored is not in person' );
check( ! SFAF_Online::is_online( $EVENT ), 'a fresh event reports as online' );
check( ! SFAF_Online::is_hybrid( $EVENT ), 'a fresh event reports as hybrid' );
check( ! SFAF_Online::has_online_format( $EVENT ), 'a fresh event has an online format' );
check( SFAF_Online::has_in_person_format( $EVENT ), 'a fresh event has no in-person format' );

/* ONLINE: no place, and the place is actually cleared. */
$reset();
$give_place();
SFAF_Online::set( $EVENT, SFAF_Online::MODE_ONLINE, $LINK, array( 'confirmation' ) );
check( SFAF_Online::MODE_ONLINE === SFAF_Online::mode( $EVENT ), 'set() did not store the online mode' );
check( SFAF_Online::is_online( $EVENT ), 'an online event does not report as online' );
check( ! SFAF_Online::is_hybrid( $EVENT ), 'an online event reports as hybrid' );
check( '' === get_post_meta( $EVENT, '_uc_location', true ),
    'an online event kept its location line' );
check( in_array( $EVENT, SFAF_Venues::$cleared, true ),
    'an online event kept its venue reference' );
check( $LINK === SFAF_Online::link( $EVENT ), 'an online event cannot read its own link' );

/* HYBRID: the place SURVIVES, and the link is still readable. This is the
 * whole reason hybrid is not a second tick on top of online. */
$reset();
$give_place();
SFAF_Online::set( $EVENT, SFAF_Online::MODE_HYBRID, $LINK, array( 'confirmation' ) );
check( SFAF_Online::MODE_HYBRID === SFAF_Online::mode( $EVENT ), 'set() did not store the hybrid mode' );
check( SFAF_Online::is_hybrid( $EVENT ), 'a hybrid event does not report as hybrid' );
check( ! SFAF_Online::is_online( $EVENT ),
    'a hybrid event reports as online, which is what takes its address away on every surface' );
check( '470 Castro Street' === get_post_meta( $EVENT, '_uc_location', true ),
    'a hybrid event lost its location line, and half its registrants are going there' );
check( '470 Castro Street' === get_post_meta( $EVENT, '_uc_location_street', true ),
    'a hybrid event lost its address parts' );
check( array() === SFAF_Venues::$cleared, 'a hybrid event had its venue reference cleared' );
check( $LINK === SFAF_Online::link( $EVENT ), 'a hybrid event cannot read its own link' );
check( SFAF_Online::has_online_format( $EVENT ), 'a hybrid event has no online format' );
check( SFAF_Online::has_in_person_format( $EVENT ), 'a hybrid event has no in-person format' );

/* THE TWO KEYS ARE NEVER BOTH SET. */
check( '1' === get_post_meta( $EVENT, SFAF_Online::META_HYBRID, true ), 'the hybrid key is not set' );
check( '' === get_post_meta( $EVENT, SFAF_Online::META, true ),
    'a hybrid event carries the online key too, and mode() then has two answers to choose between' );

/* AND SWITCHING BACK CLEARS THE OTHER ONE. */
SFAF_Online::set( $EVENT, SFAF_Online::MODE_ONLINE, $LINK, array() );
check( '' === get_post_meta( $EVENT, SFAF_Online::META_HYBRID, true ),
    'moving from hybrid to online left the hybrid key behind' );

/* IN PERSON CLEARS EVERYTHING THIS FEATURE OWNS, hybrid key included. */
$reset();
$give_place();
SFAF_Online::set( $EVENT, SFAF_Online::MODE_HYBRID, $LINK, array( 'confirmation' ) );
SFAF_Online::set( $EVENT, SFAF_Online::MODE_IN_PERSON );
check( '' === get_post_meta( $EVENT, SFAF_Online::META_HYBRID, true ), 'the hybrid key survived going in person' );
check( '' === SFAF_Online::link( $EVENT ), 'an in-person event still has a readable meeting link' );
check( '470 Castro Street' === get_post_meta( $EVENT, '_uc_location', true ),
    'going in person cleared the address, which it has no business touching' );

/* true AND false STILL MEAN WHAT THEY MEANT, because the import and the older
 * tests pass them. */
$reset();
SFAF_Online::set( $EVENT, true, $LINK, array() );
check( SFAF_Online::is_online( $EVENT ), 'set() with true no longer means online' );
SFAF_Online::set( $EVENT, false );
check( SFAF_Online::MODE_IN_PERSON === SFAF_Online::mode( $EVENT ), 'set() with false no longer means in person' );

/* ANYTHING UNRECOGNISED IS IN PERSON, the mode that grants nothing. */
check( SFAF_Online::MODE_IN_PERSON === SFAF_Online::normalize_mode( 'nonsense' ),
    'an unrecognised mode is not read as in person' );
check( SFAF_Online::MODE_HYBRID === SFAF_Online::normalize_mode( 'hybrid' ),
    'the hybrid mode string is not recognised' );

/* THE KEY TRAVELS WITH A REPEATING EVENT, like the format it replaces.
 *
 * online-events-test.php checks meta_keys() against $copied_meta and caught
 * this the moment the key was added, but the plant runner here runs THIS file,
 * so the assertion belongs in both: a check that lives only in another file is
 * a check this file's plants cannot exercise. The list holds literals because a
 * static property initializer is a constant expression. */
$recur = file_get_contents( $root . '/includes/class-sfaf-recurrence.php' );
foreach ( SFAF_Online::meta_keys() as $key ) {
    check( false !== strpos( $recur, "'" . $key . "'" ),
        'a generated occurrence loses ' . $key . ', so a repeat of a hybrid series is not hybrid' );
}
check( false !== strpos( $recur, "'_uc_capacity_online'" ),
    'a generated occurrence of a hybrid series loses its online capacity' );

/* =========================================================================
 * 2. THE LINK GOES TO THE PEOPLE WHO ASKED FOR IT, AND TO NOBODY ELSE.
 * ====================================================================== */
$reset();
$give_place();
SFAF_Online::set( $EVENT, SFAF_Online::MODE_HYBRID, $LINK, array( 'confirmation', 'reminder' ) );

$to_online    = SFAF_Online::joining_html( $EVENT, 'confirmation', SFAF_Online::MODE_ONLINE );
$to_in_person = SFAF_Online::joining_html( $EVENT, 'confirmation', SFAF_Online::MODE_IN_PERSON );

check( false !== strpos( $to_online, $LINK ),
    'an online registrant of a hybrid event is not sent the meeting link' );
check( '' === $to_in_person,
    'AN IN-PERSON REGISTRANT OF A HYBRID EVENT IS SENT THE MEETING LINK. It is a credential.' );
check( false === strpos( $to_in_person, $LINK ),
    'the meeting link is in the block built for an in-person registrant' );

/* THE PLAIN TEXT PART TOO. A message has two halves and a rule that holds in
 * one of them is not a rule. */
$text_online    = SFAF_Online::joining_text( $EVENT, 'confirmation', SFAF_Online::MODE_ONLINE );
$text_in_person = SFAF_Online::joining_text( $EVENT, 'confirmation', SFAF_Online::MODE_IN_PERSON );
check( false !== strpos( $text_online, $LINK ), 'the text part omits the link for an online registrant' );
check( '' === $text_in_person, 'the text part sends the link to an in-person registrant' );

/* AN EMPTY FORMAT IS NOT AN IN-PERSON ANSWER. Every non-hybrid event stores ''
 * because it never asked, and a purely online event's registrants must still be
 * sent the link. Writing the gate as "unless they said in person" breaks this. */
$reset();
SFAF_Online::set( $EVENT, SFAF_Online::MODE_ONLINE, $LINK, array( 'confirmation' ) );
check( false !== strpos( SFAF_Online::joining_html( $EVENT, 'confirmation', '' ), $LINK ),
    'a registrant of a purely ONLINE event is no longer sent the link, because an empty format was read as "in person"' );
check( false !== strpos( SFAF_Online::joining_text( $EVENT, 'confirmation', '' ), $LINK ),
    'the text part of a purely online event no longer carries the link' );

/* THE CALENDAR FILE IS A SECOND WAY THE LINK LEAVES, AND A WIDER ONE.
 *
 * An .ics syncs to the person's phone, their laptop and any calendar they have
 * shared with a partner or a household, so anybody with sight of it can read
 * the link. Gating the email and not the file would have handed the credential
 * to every in-person registrant of every hybrid event in the copy that travels
 * furthest. Asserted by source, because building a real URL needs the .ics
 * route and the auth salt. */
$online_src = file_get_contents( $root . '/includes/class-sfaf-online.php' );
check( (bool) preg_match( '/function ics_url_with_link\(\s*\$event_id,\s*\$format/', $online_src ),
    'the calendar file no longer knows who it is for, so a hybrid in-person registrant gets the join copy' );
$ics_gates = preg_match_all( '/self::is_hybrid\( \$event_id \) && self::MODE_ONLINE !== \(string\) \$format/', $online_src );
check( 3 === $ics_gates,
    sprintf( 'the hybrid link gate appears %d times rather than on all three of the joining html, the joining text and the calendar file', $ics_gates ) );
check( (bool) preg_match(
        '/ics_url_with_link\( \$event_id, self::person_format\( \$person \) \)/',
        file_get_contents( $root . '/includes/class-sfaf-notifications.php' )
    ),
    'the confirmation builds its calendar file without saying who it is for' );

/* AND THE TICK STILL DECIDES WHICH MESSAGE. The format gate is on top of the
 * delivery gate, not instead of it. */
$reset();
$give_place();
SFAF_Online::set( $EVENT, SFAF_Online::MODE_HYBRID, $LINK, array( 'reminder' ) );
check( '' === SFAF_Online::joining_html( $EVENT, 'confirmation', SFAF_Online::MODE_ONLINE ),
    'the link went out with a message the manager did not tick' );
check( false !== strpos( SFAF_Online::joining_html( $EVENT, 'reminder', SFAF_Online::MODE_ONLINE ), $LINK ),
    'the ticked message does not carry the link' );

/* =========================================================================
 * 3. THE OTHER HALF OF THE PAIR, BY SOURCE.
 * ====================================================================== */
/* SFAF_Notifications::facts() IS THE ADDRESS HALF, and it cannot be loaded here
 * without the whole mail stack. What is checked is that it asks the question at
 * all and that the four messages the brief names hand it the recipient. A
 * message that does not pass a format gets the address, which is right for
 * every non-hybrid event and wrong for exactly the case this release adds. */
$notif = file_get_contents( $root . '/includes/class-sfaf-notifications.php' );

check( (bool) preg_match( '/private static function facts\(\s*\$event_id,\s*\$format/', $notif ),
    'facts() no longer takes the recipient format, so every registrant gets the address' );
check( (bool) preg_match( '/SFAF_Online::MODE_ONLINE === \(string\) \$format\s*\n\s*&& SFAF_Online::is_hybrid\( \$event_id \)/', $notif ),
    'facts() no longer replaces the address for an online registrant of a hybrid event' );

/* THE FOUR MESSAGES THE BRIEF NAMES. Counted, not found: three of four passing
 * the format and one not is exactly the shape that ships a leak. */
$with_format = preg_match_all( '/self::facts\( \$event_id, self::person_format\( \$person \) \)/', $notif );
check( 4 === $with_format,
    sprintf( 'only %d of the four messages pass the recipient format to facts()', $with_format ) );

/* AND BOTH HALVES OF BOTH MESSAGES THAT CARRY A JOINING BLOCK. */
$joining_calls = preg_match_all( '/SFAF_Online::joining_(?:html|text)\( \$event_id, \'[a-z]+\', self::person_format\( \$person \) \)/', $notif );
check( 4 === $joining_calls,
    sprintf( 'only %d of the four joining blocks are told who they are for', $joining_calls ) );

/* =========================================================================
 * 4. CAPACITY IS PER FORMAT.
 * ====================================================================== */
/* The resolver lives in the plugin's main file, which cannot be loaded here. It
 * is read for the rules that decide a count, because those are the ones a later
 * edit gets wrong: "full" must be per format, and an event is only full when
 * every format it offers is. */
$main = file_get_contents( $root . '/sfaf-calendar.php' );

check( false !== strpos( $main, "'_uc_capacity_online'" ),
    'the online capacity key is gone, so both formats share one limit' );
check( (bool) preg_match( '/function sfaf_format_full\(/', $main ), 'sfaf_format_full() is gone' );
check( (bool) preg_match( '/function sfaf_event_full\(/', $main ), 'sfaf_event_full() is gone' );
/* A HYBRID EVENT COUNTS PER FORMAT AND EVERYTHING ELSE COUNTS THE WHOLE EVENT,
 * because a non-hybrid event's rows carry no format and counting by one would
 * count nothing at all. */
check( (bool) preg_match( '/SFAF_Online::is_hybrid\( \$event_id \)\s*\n\s*\?\s*sfaf_get_rsvp_count_by_format/', $main ),
    'sfaf_format_full() no longer counts per format on a hybrid event, or no longer counts the whole event on the others' );
/* ONE FORMAT FULL IS NOT THE EVENT FULL. The form still has somewhere to send
 * the next person. */
check( (bool) preg_match( '/function sfaf_event_full\([^)]*\)\s*\{\s*foreach \( sfaf_event_formats/', $main ),
    'sfaf_event_full() no longer asks every format, so one full format closes the event' );
/* THE FORMAT COLUMN AND ITS INDEX. */
check( false !== strpos( $main, "format varchar(20) NOT NULL DEFAULT ''" ),
    'the registrations table no longer records which format somebody chose' );
check( false !== strpos( $main, 'KEY event_format (event_id, format)' ),
    'the per-format count has no index behind it' );
check( (bool) preg_match( "/define\( 'SFAF_DB_VERSION', '([7-9]|\d\d+)' \)/", $main ),
    'the schema version was not bumped, so no existing site gets the format column' );

/* AND THE COUNT CACHE IS CLEARED PER FORMAT TOO. The fault this guards is
 * documented on sfaf_clear_rsvp_count_cache(): a cancellation releases a place
 * and the next read in the same request serves the number from before it. */
check( (bool) preg_match( '/function sfaf_clear_rsvp_count_cache[\s\S]{0,900}sfaf_rsvp_format_count_store/', $main ),
    'the per-format counts survive a cancellation, so the form goes on saying a format is full after a place was released' );

/* =========================================================================
 * 5. THE REGISTRATION TAKES THE ANSWER, AND DOES NOT TRUST IT.
 * ====================================================================== */
$rsvp = file_get_contents( $root . '/includes/class-sfaf-rsvp.php' );
check( (bool) preg_match( '/in_array\( \$asked, \$formats, true \) \? \$asked : \$formats\[0\]/', $rsvp ),
    'the posted format is trusted, so a posted "online" on an in-person event counts against a limit that event never set' );
check( (bool) preg_match( "/'format'\s*=>\s*\\\$data\['format'\]/", $rsvp ),
    'the chosen format is not written with the registration row' );
check( false !== strpos( $rsvp, 'sfaf_format_full(' ),
    'registration no longer checks the capacity of the format somebody picked' );
/* A FULL FORMAT ON AN EVENT WITH ROOM IN THE OTHER IS NOT "THIS EVENT IS FULL". */
check( false !== strpos( $rsvp, 'sfaf_event_full(' ),
    'registration cannot tell one full format from a full event, so it turns away people the other format could take' );

/* =========================================================================
 * 6. THE FORM ASKS, AND THE SERVER DECIDED WHAT IT MAY OFFER.
 * ====================================================================== */
$js = file_get_contents( $root . '/public/js/calendar.js' );
check( false !== strpos( $js, 'renderFormatChoice' ),
    'the registration form no longer asks a hybrid registrant how they will attend' );
check( false !== strpos( $js, 'data-uc-formats' ) && false !== strpos( $js, 'data-uc-full' ),
    'the form works out for itself which formats an event has, rather than reading what the server decided' );
check( (bool) preg_match( '/if \(formats\.length < 2\)/', $js ),
    'the question is asked on events with only one format, where there is nothing to choose' );
/* THE QUESTION COMES FIRST, because what it answers changes what the rest of
 * the form means. Asserted as an order, not as presence. */
$at_format = strpos( $js, 'id="uc-rsvp-format"' );
$at_first  = strpos( $js, 'uc-rsvp-first-name' );
check( false !== $at_format && false !== $at_first && $at_format < $at_first,
    'the format question is no longer the first thing on the form' );

$tpl = file_get_contents( $root . '/includes/sfaf-template-functions.php' );
check( false !== strpos( $tpl, "'data-uc-formats'" ),
    'the RSVP button no longer tells the form which formats this event offers' );
check( false !== strpos( $tpl, "'data-uc-full'" ),
    'the RSVP button no longer says which formats are full' );

/* =========================================================================
 * SELF-TEST.
 * ====================================================================== */
if ( $self_test ) {
    $bad = array();

    $before = count( $fails );
    check( SFAF_Online::MODE_HYBRID === SFAF_Online::mode( 999999 ), 'deliberate: an unknown event reported hybrid' );
    if ( count( $fails ) === $before ) {
        $bad[] = 'check() does not record a failure, so every assertion above is decorative';
    } else {
        array_pop( $fails );
    }

    /* The link gate must be a real gate: proving it by construction rather than
     * by trusting the assertion above. */
    $GLOBALS['pmeta'] = array();
    SFAF_Online::set( 7, SFAF_Online::MODE_HYBRID, 'https://example.test/j/1', array( 'confirmation' ) );
    $in  = SFAF_Online::joining_html( 7, 'confirmation', SFAF_Online::MODE_IN_PERSON );
    $on  = SFAF_Online::joining_html( 7, 'confirmation', SFAF_Online::MODE_ONLINE );
    if ( '' !== $in || '' === $on ) {
        $bad[] = 'the joining gate does not distinguish the two formats, so section 2 proves nothing';
    }

    if ( $bad ) {
        echo "SELF-TEST FAILED\n";
        foreach ( $bad as $b ) { echo '  . ' . $b . "\n"; }
        exit( 1 );
    }
    echo "PASS  hybrid --self-test             self-test passed: the reader reports faults and the joining gate really gates.\n";
    exit( 0 );
}

if ( $fails ) {
    echo 'FAIL: ' . count( $fails ) . "\n";
    foreach ( array_unique( $fails ) as $f ) { echo '  . ' . $f . "\n"; }
    exit( 1 );
}

echo "an event is in person, online or hybrid; a hybrid one keeps its address AND takes a link,\n";
echo "and no message carries both halves: the address goes to the people coming to it.\n";
exit( 0 );
