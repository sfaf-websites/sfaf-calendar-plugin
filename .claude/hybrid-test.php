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
 * 7. THE DISPLAY, THE ALERT AND THE SUMMARY.
 * ====================================================================== */

/* THE FORMAT LINE IS DISPLAY ONLY AND SEPARATE FROM THE ADDRESS.
 *
 * sfaf_event_location() is the single reader behind the Google Maps query
 * string, the JSON-LD PostalAddress and the .ics LOCATION. Prose appended to
 * what it returns goes into a maps lookup, into structured data and into
 * somebody's calendar entry, so the sentence beside a hybrid address has to be
 * a different function. This asserts they stay different. */
/* THE FUNCTION MUST STILL SAY SOMETHING, not merely exist: a body returning ''
 * matched "the function is there" perfectly, and the plant that emptied it went
 * straight past. */
check( false !== strpos( $tpl, "is_hybrid( (int) \$post_id ) ? 'In person and online' : ''" ),
    'the hybrid format line no longer says anything, so a hybrid event shows a street address and nothing about joining online' );
check( ! preg_match( '/function sfaf_event_location\([^)]*\)\s*\{[\s\S]{0,2500}?is_hybrid/', $tpl ),
    'sfaf_event_location() answers the hybrid question itself, and its answer goes into a maps query, the JSON-LD address and the .ics' );

/* AND IT NEVER NAMES THE LINK. It is a public surface. */
$fmt_fn = '';
$fn_at  = strpos( $tpl, 'function sfaf_event_format_line(' );
if ( false !== $fn_at ) {
    $open  = strpos( $tpl, '{', $fn_at );
    $depth = 0;
    for ( $i = $open; $i < strlen( $tpl ); $i++ ) {
        if ( '{' === $tpl[ $i ] ) { $depth++; }
        if ( '}' === $tpl[ $i ] ) {
            $depth--;
            if ( 0 === $depth ) { $fmt_fn = substr( $tpl, $fn_at, $i - $fn_at + 1 ); break; }
        }
    }
}
check( '' !== $fmt_fn, 'the format line could not be read' );
check( false === strpos( $fmt_fn, 'link' ) && false === strpos( $fmt_fn, 'META_LINK' ),
    'the public format line reaches for the meeting link, which is a credential' );

/* BOTH SURFACES DRAW IT: the event page and the list view's location column. */
$single = file_get_contents( $root . '/templates/single-uc_event.php' );
$short  = file_get_contents( $root . '/includes/class-sfaf-shortcodes.php' );
/* THE CALL, NOT THE NAME. The name survives in the comment above the call, so
 * checking for it passed while the call itself had been replaced with ''. */
check( false !== strpos( $single, '$format_line = sfaf_event_format_line(' ),
    'the event page no longer CALLS the format line, so a hybrid event says nothing about joining online' );
check( false !== strpos( $short, '$format_line = sfaf_event_format_line(' ),
    "the list view's location column no longer CALLS the format line" );

/* THE ALERT NAMES THE FORMAT, AND COUNTS THE ONE IT NAMED.
 *
 * A count that does not match the format is worse than no count: "11 of 12
 * places taken" beside "Online" reports the room at somebody joining by link,
 * and whoever reads it sets out a chair. */
/* THE VALUES, NOT THE KEY. Setting the row to '' left the key in place and the
 * check passed while the alert said nothing. */
check( false !== strpos( $notif, "\$rows['Attending'] = ( SFAF_Online::MODE_ONLINE === \$format ) ? 'Online' : 'In person';" ),
    'the registration alert no longer names which format the registrant picked; the key alone is not the answer' );
check( (bool) preg_match( '/\$count\s*=\s*\(int\) sfaf_get_rsvp_count_by_format\( \$event_id, \$format \)/', $notif ),
    'the alert reports the whole-event count beside a named format' );
check( (bool) preg_match( "/\\\$text \.= 'Attending: '/", $notif ),
    'the plain text half of the alert does not name the format, and a rule that holds in one half is not a rule' );

/* THE SUMMARY GROUPS BY FORMAT, AND THE GROUPING IS A PARTITION.
 *
 * Every row lands in exactly one group. A row whose format was never recorded,
 * which is any registration taken before the event became hybrid, gets a group
 * of its own rather than being folded in or dropped: somebody is expecting
 * those people too. */
check( false !== strpos( $notif, "'Format not recorded'" ),
    'a registration taken before the event became hybrid vanishes from the summary' );
check( (bool) preg_match( '/if \( ! isset\( \$groups\[ \$rf \] \) \) \{\s*\n\s*\$rf = \'\';/', $notif ),
    'an unrecognised format in the summary is dropped rather than put in the group for it' );
/* NOBODY IS LISTED TWICE. The flat list is skipped entirely when the grouped
 * one has run, which is the "counting a format twice" fault in its real shape. */
check( (bool) preg_match( '/if \( ! \$hybrid \) \{\s*\n\s*foreach \( \$rows as \$row \) \{/', $notif ),
    'the summary writes the flat list as well as the grouped one, so every hybrid registrant appears twice' );
check( (bool) preg_match( '/sfaf_event_capacity\( \$event_id, SFAF_Online::MODE_IN_PERSON \)/', $notif )
    && (bool) preg_match( '/sfaf_event_capacity\( \$event_id, SFAF_Online::MODE_ONLINE \)/', $notif ),
    'the summary does not give both capacities' );

/* =========================================================================
 * 7b. ONLINE AND HYBRID ARE EXCLUSIVE, AND HYBRID SHOWS BOTH SIDES (3.97.2).
 * ====================================================================== */
/* THE TWO TICKS CANNOT BOTH BE TRUE. The save already folds a posted pair with
 * hybrid winning; what was missing was the live half, so both could be ticked
 * on screen and the form said something the model has no word for. */
/* Read here as well as further down: this block runs before the sections
 * that load them, and a test reading an undefined variable asserts
 * nothing at all. */
$portal = file_get_contents( $root . '/includes/class-sfaf-portal.php' );
$main   = file_get_contents( $root . '/sfaf-calendar.php' );
$js = file_get_contents( $root . '/public/js/portal.js' );

check( false !== strpos( $js, 'data-uc-hybrid-toggle' ),
    'the editor script does not read the hybrid tick at all, so ticking it changes nothing on screen' );
/* AND A POSTED PAIR FOLDS TO HYBRID. The script clears one tick as the other is
 * pressed, but a form sent with the script blocked, or by a back button, can
 * still carry both. Hybrid wins because it is the mode that KEEPS the address,
 * and clearing an address is the change nothing here can undo. Asserted as an
 * ORDER, because reading online first is the whole of the fault. */
check( false !== strpos( $portal, "if ( \$want_hybrid ) {\n                \$want_mode = SFAF_Online::MODE_HYBRID;\n            } elseif ( \$want_online ) {" ),
    'a posted pair of format ticks no longer folds to hybrid, so ticking hybrid with no script would clear the address' );
check( (bool) preg_match( '/function exclusive\(changed\)[\s\S]{0,400}?hybrid\.checked = false;[\s\S]{0,200}?online\.checked = false;/', $js ),
    'ticking one format no longer clears the other, so both can be ticked at once' );

/* HYBRID IS ONLINE AND IN PERSON, so it shows every control BOTH formats have:
 * the meeting link and the online capacity, and the venue and the address. */
check( (bool) preg_match( '/panel\.hidden = !\(isOnline \|\| isHybrid\);/', $js ),
    'the meeting link panel is hidden on a hybrid event, which has a meeting link' );
check( (bool) preg_match( '/place\.hidden = isOnline && !isHybrid;/', $js ),
    'the venue and address are hidden on a hybrid event, which has a place' );
check( (bool) preg_match( '/onlineCap\.hidden = !isHybrid;/', $js ),
    'the online capacity does not follow the hybrid tick' );

/* AND THE ONLINE CAPACITY IS IN THE DOM TO BE SHOWN. It used to be rendered
 * only when the event was ALREADY hybrid, so ticking the box revealed a control
 * that did not exist yet and it only appeared after a save. */
check( (bool) preg_match( '/data-uc-online-capacity<\?php echo \$hybrid_on \? \'\' : \' hidden\'; \?>/', $portal ),
    'the online capacity is not rendered hidden, so ticking hybrid cannot reveal it' );
/* WITHOUT A CHARACTER WINDOW. The first version allowed 700 characters between
 * the case label and the guard, and the comment explaining the change is longer
 * than that, so the plant restoring the old guard sat outside the window and
 * went straight past. The guard's own text is the thing being asserted. */
check( false === strpos( $portal, "! SFAF_Online::is_hybrid( \$event_id )\n                    || SFAF_Sources::takes_rsvps_at_source" ),
    'the online capacity is again rendered only when the event is ALREADY hybrid, so ticking the box reveals nothing until a save' );

/* =========================================================================
 * 7c. A HYBRID EVENT ALWAYS TAKES RSVPS (3.97.2).
 * ====================================================================== */
/* THE FORMAT CHOICE LIVES ON THE REGISTRATION FORM. An event with RSVPs off has
 * no way for anybody to say which format they are in, and the two capacities
 * are limits on a question nobody is asked. */
check( (bool) preg_match( "/\\\$rsvp_forced = \( ! \\\$at_source && \\\$event_id && SFAF_Online::is_hybrid\( \\\$event_id \) \);/", $portal ),
    'the editor no longer forces Accept RSVPs on for a hybrid event' );
check( (bool) preg_match( '/disabled\( \$at_source \|\| \$rsvp_forced \)/', $portal ),
    'Accept RSVPs is not locked on a hybrid event, so it can be turned off' );

/* THE SAVE IS WHAT MAKES IT TRUE, not the disabled attribute: a disabled input
 * is absent from a hand-edited POST and from anything that did not come out of
 * a browser. Written as a WRITE of '1', not a skip. */
check( (bool) preg_match( "/\} elseif \( SFAF_Online::is_hybrid\( \\\$event_id \) \) \{\s*\n\s*update_post_meta\( \\\$event_id, '_uc_rsvp_enabled', '1' \);/", $portal ),
    'the save no longer forces RSVPs on for a hybrid event, so a posted form can turn them off' );
/* AND THE DISPLAY TICK ONE STEP LATER, for the same reason: an event with no
 * RSVP button has nowhere to ask the format question. */
check( (bool) preg_match( "/if \( 'show_rsvp' === \\\$field && SFAF_Online::is_hybrid\( \\\$event_id \) \) \{\s*\n\s*update_post_meta\( \\\$event_id, \\\$key, '1' \);/", $portal ),
    'the RSVP button can be hidden on a hybrid event, which leaves the format question unaskable' );
check( (bool) preg_match( '/\$hybrid_show = \( \$event_id && SFAF_Online::is_hybrid\( \$event_id \) \);/', $portal ),
    'the Display card does not lock its RSVP tick for a hybrid event' );

/* BOTH COME BACK WHEN HYBRID IS UNTICKED, and what comes back is THE VALUE THE
 * BOX ARRIVED WITH.
 *
 * THIS WAS WRONG UNTIL A BROWSER RAN IT. The lock set `checked = true` on the
 * way in and moved only `disabled` on the way out, so the box came back ENABLED
 * AND TICKED: a manager whose event took no registrations, who tried hybrid and
 * changed their mind, was left with a registration form they never asked for,
 * and the save writes whatever the box says once hybrid is gone. Every
 * source-reading assertion here passed while that was true, because the
 * handlers were all correctly wired; see .claude/hybrid-live.php, which drives
 * the real script and reads the box back. */
check( (bool) preg_match( '/box\.disabled = isHybrid;/', $js ),
    'the lock is not lifted when hybrid is unticked' );
check( (bool) preg_match( "/box\.setAttribute\('data-uc-was-checked', box\.checked \? '1' : '0'\);/", $js ),
    'the RSVP box no longer remembers the value it arrived with, so unticking hybrid cannot give it back' );
check( (bool) preg_match( "/box\.checked = \('1' === box\.getAttribute\('data-uc-was-checked'\)\);/", $js ),
    'unticking hybrid leaves Accept RSVPs ticked, so an event that took no registrations now takes them' );
/* WRITTEN ONCE, on the FIRST lock. apply() runs on every change of either tick,
 * so an unconditional store overwrites the real answer with `true` the second
 * time hybrid is seen ticked, and the restore then gives back the lock's own
 * value. The guard is the assertion. */
check( (bool) preg_match( "/if \(null === box\.getAttribute\('data-uc-was-checked'\)\) \{/", $js ),
    'the remembered value is stored on every pass, so it is overwritten by the lock and the restore gives back a tick nobody asked for' );
/* AND IT MUST NOT UNLOCK WHAT THE THIRD-PARTY RULE LOCKED. */
check( (bool) preg_match( '/if \(box\.disabled && !box\.checked && !isHybrid\) \{ continue; \}/', $js ),
    "the hybrid lock reaches into a third-party event's Accept RSVPs, which the server locked OFF" );

/* =========================================================================
 * 7d. THE CALENDAR FILE CARRIES THE EVENT, AND ITS LINK (3.97.2).
 * ====================================================================== */
/* EVERY FIELD WAS THE EVENT'S ALREADY. Title, description, location, dates,
 * times and URL all read event meta with no series fallback anywhere, and a
 * run of the shipped builder with the series given different values for all of
 * them produced none of those values. What was actually wrong is the LINK.
 *
 * is_online() IS THE NARROW QUESTION, "has no place", which a hybrid event
 * answers no to by design so it keeps its address. The file was asking that one
 * and should have been asking "does anybody join by link", so an online
 * registrant of a hybrid event got a confirmation carrying the link and a
 * calendar file that silently dropped it. */
check( (bool) preg_match( '/if \( SFAF_Online::has_online_format\( \$post_id \) && SFAF_Online::has_link\( \$post_id \) \)/', $main ),
    'the calendar file asks is_online() again, so a hybrid event never gets its meeting link' );
check( ! preg_match( '/if \( SFAF_Online::is_online\( \$post_id \) && SFAF_Online::has_link/', $main ),
    'the narrow gate is back on the calendar file' );

/* THE TOKEN STILL DECIDES. Widening which events CAN carry a link must not
 * widen who gets one: ics_url_with_link() mints a token only for a registrant
 * whose format is online, so a hybrid event's in-person registrant is handed
 * none and this still gives them nothing. */
check( (bool) preg_match( '/SFAF_Online::sends_with\( \$post_id, \'confirmation\' \) && SFAF_Online::ics_join_ok\( \$post_id, \$asked \)/', $main ),
    'the calendar file no longer requires the join token, so the endpoint hands the link to anybody who asks' );

/* AND THE FILE SAYS WHAT THE PAGE SAYS ABOUT THE FORMAT, from the same
 * function, so the two cannot drift. In the DESCRIPTION and never in LOCATION:
 * a client hands LOCATION to a map. */
check( (bool) preg_match( '/\$format_line = sfaf_event_format_line\( \$post_id \);/', $main ),
    'the calendar file no longer says a hybrid event is also online' );
$loc_at = strpos( $main, "\$lines[] = 'LOCATION:'" );
$fmt_at = strpos( $main, '$format_line = sfaf_event_format_line( $post_id );' );
check( false !== $loc_at && false !== $fmt_at,
    'the LOCATION line or the format line could not be found' );
if ( false !== $loc_at ) {
    $loc_line = substr( $main, $loc_at, 120 );
    check( false === strpos( $loc_line, 'format_line' ),
        'the format sentence was put into LOCATION, which a calendar client hands to a map' );
}

/* =========================================================================
 * 7e. THE CALENDAR FILE CARRIES THE EVENT'S OWN DESCRIPTION (3.97.2).
 * ====================================================================== */
/* THE ONE FIELD THAT REALLY DID COME FROM SOMEWHERE ELSE.
 *
 * Title, location, dates, times and URL all read the event and only the event,
 * and none of them has a series fallback anywhere. DESCRIPTION read
 * get_the_excerpt(), and on this post type `post_excerpt` is NEVER the event's
 * own text: the editor writes `post_content` from the description field and
 * touches the excerpt nowhere, so the only values it ever holds are COPIES from
 * another post. SFAF_Recurrence hands every generated occurrence the SEED's
 * excerpt, which is the text the whole series shares.
 *
 * So an occurrence whose description had been edited showed the edited text on
 * the page, from the_content(), and the seed's on the calendar entry. */
$tpl = file_get_contents( $root . '/includes/sfaf-template-functions.php' );

check( (bool) preg_match( '/function sfaf_event_calendar_description\( \$post_id \) \{/', $tpl ),
    'the one calendar description resolver is gone' );
check( (bool) preg_match( '/return sfaf_flatten_html\( \$post->post_content \);/', $tpl ),
    "the calendar description no longer reads the event's own post_content" );
check( false === strpos( $tpl, 'sfaf_flatten_html( get_the_excerpt( $post_id ) )' ),
    'a calendar surface is reading get_the_excerpt() again, which on a generated occurrence is the seed event\'s' );

/* BOTH CALENDAR SURFACES ASK THE SAME FUNCTION. The .ics and the Google
 * Calendar URL sit in the same button row, so a fix to one that left the other
 * reading the excerpt would have them describing one event two ways. */
check( (bool) preg_match( '/\$description = sfaf_event_calendar_description\( \$post_id \);/', $main ),
    'the .ics no longer asks the calendar description resolver' );
check( (bool) preg_match( "/'details'  => sfaf_event_calendar_description\( \\\$post_id \),/", $tpl ),
    'the Google Calendar URL still carries the excerpt, so the two calendar buttons disagree' );
check( false === strpos( $main, 'get_the_excerpt' ) || false !== strpos( $main, 'This read get_the_excerpt()' ),
    'the .ics reads the excerpt again' );

/* AND IT DOES NOT FALL BACK TO THE EXCERPT. The event PAGE renders
 * the_content() with nothing behind it, so an event with no description of its
 * own shows none; reaching for the excerpt here would put back exactly the
 * copied text this removes, on exactly the events that have one. */
$fn_at = strpos( $tpl, 'function sfaf_event_calendar_description(' );
check( false !== $fn_at, 'the calendar description resolver could not be found' );
if ( false !== $fn_at ) {
    $body = substr( $tpl, $fn_at, 260 );
    check( false === strpos( $body, 'get_the_excerpt' ),
        'the calendar description falls back to the excerpt, which is the copied value it exists to stop reading' );
}

/* =========================================================================
 * 8. A NON-HYBRID EVENT HAS ONE CAPACITY AND IT IS `_uc_capacity`.
 * ====================================================================== */
/* THIS WAS WRONG FOR ONE RELEASE. sfaf_event_capacity() chose the key by the
 * event's FORMAT, so a purely ONLINE event's capacity was written to
 * `_uc_capacity` by the editor and read back from `_uc_capacity_online`, which
 * nothing had ever written. Every online event with a limit reported as
 * unlimited and the RSVP button never said full.
 *
 * The split belongs to HYBRID, not to online, which is also what makes the
 * change need no migration. */
check( (bool) preg_match( '/if \( ! SFAF_Online::is_hybrid\( \$event_id \) \) \{\s*\n\s*return max\( 0, \(int\) get_post_meta\( \$event_id, \'_uc_capacity\', true \) \);/', $main ),
    'a non-hybrid event reads its capacity from a key that depends on its format again, so an online event with a limit reports as unlimited' );

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
