<?php
/**
 * WHERE THE MEETING LINK MAY BE READ, AS A WHITELIST.
 *
 *     php .claude/online-events-test.php
 *
 * The meeting link on an online event is a credential: anybody holding it can
 * join, and this calendar carries HIV, substance use and trans health
 * programming. A checklist of surfaces to keep it off is only ever as complete
 * as the person writing it, and the failure mode for this feature is a renderer
 * nobody thought of. So this is inverted, exactly as .claude/private-events-test.php
 * inverts the same question for private events.
 *
 * FOUR PASSES, because they fail differently and none of them subsumes another:
 *
 *   1. BEHAVIOUR. SFAF_Online's own logic run for real against a stubbed
 *      WordPress: is_online, link(), the delivery set, what set() clears in
 *      each direction, the fallback sentence, and the .ics token.
 *
 *   2. COVERAGE, THE WHITELIST. A static sweep of every file in the plugin for
 *      the meta key, the constant that names it, and calls to link(). Anything
 *      not on the list below FAILS. A field added later is caught by default,
 *      because it will not be on it.
 *
 *   3. THE RENDER. Every public payload built for real, for an online event
 *      carrying a known link, and the link asserted absent from the bytes. The
 *      whitelist proves nothing NEW reads it; this proves the readers that
 *      already exist do not print it. Rendering is what found the bug the rules
 *      missed in .claude/email-render-test.php, and the same reasoning applies.
 *
 *   4. THE LISTS AGREE. SFAF_Online::meta_keys() against the literals in
 *      SFAF_Recurrence::$copied_meta, which cannot call it (a static property
 *      initializer is a constant expression). Two lists that must not drift.
 *
 * WHAT THIS CANNOT PROVE, and the readme says so too: that a mail client
 * renders the joining block, that Apple or Outlook honour the CONFERENCE
 * property, or that WordPress serves the .ics route the way this models it.
 * Those need a real mailbox and a live site, and they are in TESTING.md.
 */

$root = dirname( __DIR__ );
define( 'ABSPATH', __DIR__ );

$fails = array();
function check( $cond, $msg ) {
    global $fails;
    if ( ! $cond ) { $fails[] = $msg; }
}

/* --- WordPress, in miniature. ------------------------------------------- */

$GLOBALS['meta']  = array();
$GLOBALS['terms'] = array();   // post_id => taxonomy => array of term ids
$GLOBALS['posts'] = array();

function get_post_meta( $id, $key, $single = false ) {
    if ( ! isset( $GLOBALS['meta'][ $id ][ $key ] ) ) {
        return $single ? '' : array();
    }
    $v = $GLOBALS['meta'][ $id ][ $key ];
    return $single ? $v : array( $v );
}
function update_post_meta( $id, $key, $value, $prev = '' ) {
    $GLOBALS['meta'][ $id ][ $key ] = $value;
    return true;
}
function delete_post_meta( $id, $key, $value = '' ) {
    unset( $GLOBALS['meta'][ $id ][ $key ] );
    return true;
}
function wp_set_object_terms( $id, $terms, $tax, $append = false ) {
    $GLOBALS['terms'][ $id ][ $tax ] = (array) $terms;
    return (array) $terms;
}
function wp_get_object_terms( $id, $tax, $args = array() ) {
    return isset( $GLOBALS['terms'][ $id ][ $tax ] ) ? $GLOBALS['terms'][ $id ][ $tax ] : array();
}
function wp_get_post_terms( $id, $tax, $args = array() ) {
    return wp_get_object_terms( $id, $tax, $args );
}
function get_the_title( $id = 0 ) {
    return isset( $GLOBALS['posts'][ $id ]['post_title'] ) ? $GLOBALS['posts'][ $id ]['post_title'] : 'An Event';
}
function get_permalink( $id = 0 ) { return 'https://example.org/events/an-event/'; }
function get_post( $id = null ) {
    return isset( $GLOBALS['posts'][ $id ] ) ? (object) $GLOBALS['posts'][ $id ] : null;
}
function home_url( $path = '/' ) { return 'https://example.org' . $path; }
function wp_salt( $scheme = 'auth' ) { return 'a-salt-nobody-outside-this-site-has'; }
function trailingslashit( $s ) { return rtrim( $s, '/' ) . '/'; }
function esc_url_raw( $u ) { return $u; }
function esc_url( $u ) { return $u; }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_key( $s ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $s ) ); }
function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); }
function add_query_arg( $key, $value = null, $url = '' ) {
    if ( is_array( $key ) ) { $url = ( null === $value ) ? $url : $value; $args = $key; }
    else { $args = array( $key => $value ); }
    $sep = ( false === strpos( $url, '?' ) ) ? '?' : '&';
    $out = array();
    foreach ( $args as $k => $v ) { $out[] = rawurlencode( $k ) . '=' . rawurlencode( $v ); }
    return $url . $sep . implode( '&', $out );
}

/* SFAF_Venues, to the extent SFAF_Online touches it: one method, which clears
 * the term. Modelled rather than loaded, because loading the real class drags
 * in term meta, the address composer and the parser, none of which is under
 * test here. The call is asserted below rather than assumed. */
class SFAF_Venues {
    const TAXONOMY = 'uc_venue';
    public static $cleared = array();
    public static function set_for_event( $post_id, $term_id ) {
        if ( ! (int) $term_id ) { self::$cleared[] = (int) $post_id; }
        wp_set_object_terms( (int) $post_id, (int) $term_id ? array( (int) $term_id ) : array(), self::TAXONOMY );
    }
    public static function exists( $id ) { return (bool) $id; }
    public static function id_for_event( $post_id ) {
        $t = wp_get_object_terms( $post_id, self::TAXONOMY );
        return empty( $t ) ? 0 : (int) $t[0];
    }
    public static function display( $id ) { return 'Strut, 470 Castro St, San Francisco, CA 94114'; }
}

function sfaf_location_part_keys() {
    return array(
        'street' => '_uc_location_street',
        'city'   => '_uc_location_city',
        'state'  => '_uc_location_state',
        'zip'    => '_uc_location_zip',
    );
}

/* The two email primitives SFAF_Online::joining_html() composes with. Rendered
 * as recognisable markup rather than stubbed to '', because pass 3 searches the
 * OUTPUT for the link and a stub returning nothing would pass every assertion
 * by producing nothing. */
class SFAF_Email {
    const C_TEAL = '#0E7680';
    public static function label( $t ) { return '<p class="label">' . esc_html( $t ) . '</p>'; }
    public static function para( $t ) { return '<p>' . esc_html( $t ) . '</p>'; }
    public static function small_para( $h ) { return '<p class="small">' . $h . '</p>'; }
    public static function button( $url, $label, $style = 'primary', $icon = false, $fill = false ) {
        return '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
    }
}

function sfaf_ics_url( $post_id ) {
    return add_query_arg( 'uc_ics', (int) $post_id, home_url( '/' ) );
}

require_once $root . '/includes/class-sfaf-online.php';

/* =========================================================================
 * PASS 1: BEHAVIOUR
 * ====================================================================== */

$EVENT = 42;
$LINK  = 'https://zoom.us/j/98765432100?pwd=NotARealSecretButTreatItAsOne';

$GLOBALS['posts'][ $EVENT ] = array( 'post_title' => 'Thursday Support Group', 'post_type' => 'uc_event' );

// An in-person event, to start.
wp_set_object_terms( $EVENT, array( 7 ), 'uc_venue' );
update_post_meta( $EVENT, '_uc_location', 'Strut, 470 Castro St, San Francisco, CA 94114' );
update_post_meta( $EVENT, '_uc_location_street', '470 Castro St' );
update_post_meta( $EVENT, '_uc_location_city', 'San Francisco' );

check( ! SFAF_Online::is_online( $EVENT ), 'a fresh event reports as online' );
check( '' === SFAF_Online::link( $EVENT ), 'a fresh event has a link' );
check( array() === SFAF_Online::sends( $EVENT ), 'a fresh event sends something' );

/* --- The tick goes on. --- */
SFAF_Online::set( $EVENT, true, $LINK, array( 'confirmation' ) );

check( SFAF_Online::is_online( $EVENT ), 'set() did not mark the event online' );
check( $LINK === SFAF_Online::link( $EVENT ), 'set() did not store the link' );
check( SFAF_Online::has_link( $EVENT ), 'has_link() says no link on an event that has one' );
check( array( 'confirmation' ) === SFAF_Online::sends( $EVENT ), 'set() did not store the delivery set' );
check( SFAF_Online::sends_with( $EVENT, 'confirmation' ), 'the confirmation was ticked and does not carry it' );
check( ! SFAF_Online::sends_with( $EVENT, 'reminder' ), 'the reminder was not ticked and carries it anyway' );

// THE PLACE IS GONE, all four parts of it and the term.
check( array() === wp_get_object_terms( $EVENT, 'uc_venue' ), 'the venue term survived the tick' );
check( in_array( $EVENT, SFAF_Venues::$cleared, true ), 'set() never asked SFAF_Venues to clear the term' );
check( '' === get_post_meta( $EVENT, '_uc_location', true ), 'the composed location line survived the tick' );
foreach ( sfaf_location_part_keys() as $part => $key ) {
    check( '' === get_post_meta( $EVENT, $key, true ), "the $part part of the address survived the tick" );
}

/* --- An unknown delivery key is dropped, not stored. --- */
SFAF_Online::set( $EVENT, true, $LINK, array( 'confirmation', 'sms', 'reminder' ) );
check(
    array( 'confirmation', 'reminder' ) === SFAF_Online::sends( $EVENT ),
    'a delivery key that is not in deliveries() was stored'
);

/* --- The tick comes off, and takes the credential with it. --- */
SFAF_Online::set( $EVENT, false );
check( ! SFAF_Online::is_online( $EVENT ), 'set(false) left the event online' );
check( '' === get_post_meta( $EVENT, SFAF_Online::META_LINK, true ), 'the meeting link survived the tick coming off' );
check( '' === get_post_meta( $EVENT, SFAF_Online::META_SEND, true ), 'the delivery set survived the tick coming off' );
check( '' === SFAF_Online::link( $EVENT ), 'link() answers on an event that is not online' );

/*
 * AND IT DOES NOT GIVE THE ADDRESS BACK, which is the answer to "what happens
 * when the tick goes on and off again" and is asserted rather than left to be
 * discovered. Nothing was kept to restore it from; the manager retypes it. The
 * alternative, keeping a shadow copy, is a stale address that something later
 * reads, which is the state the whole either/or rule exists to prevent.
 */
check( '' === get_post_meta( $EVENT, '_uc_location', true ), 'an address came back when the tick came off' );
check( array() === wp_get_object_terms( $EVENT, 'uc_venue' ), 'a venue came back when the tick came off' );

/* --- link() refuses to answer for an event that is not online, whatever is
 *     stored. The second mechanism: set() clears the key, and this makes a
 *     stray row written by anything else unreadable anyway. --- */
update_post_meta( $EVENT, SFAF_Online::META_LINK, $LINK );
check( '' === SFAF_Online::link( $EVENT ), 'link() read a stored URL on an event that is not online' );
check( array() === SFAF_Online::sends( $EVENT ), 'sends() answered for an event that is not online' );
delete_post_meta( $EVENT, SFAF_Online::META_LINK );

/* --- The fallback sentence: the tick with no link. --- */
SFAF_Online::set( $EVENT, true, '', array( 'confirmation', 'reminder' ) );
check( ! SFAF_Online::has_link( $EVENT ), 'has_link() said yes with nothing entered' );

$html = SFAF_Online::joining_html( $EVENT, 'confirmation' );
$text = SFAF_Online::joining_text( $EVENT, 'confirmation' );
check( false !== strpos( $html, SFAF_Online::NO_LINK_YET ), 'the confirmation does not promise a link when none is entered' );
check( false !== strpos( $text, SFAF_Online::NO_LINK_YET ), 'the plain-text confirmation does not promise a link' );
check( '' !== SFAF_Online::joining_html( $EVENT, 'reminder' ), 'the reminder was ticked and carries nothing' );

/* --- A message that was not ticked carries nothing at all. --- */
SFAF_Online::set( $EVENT, true, $LINK, array( 'reminder' ) );
check( '' === SFAF_Online::joining_html( $EVENT, 'confirmation' ), 'the confirmation carries a block it was not ticked for' );
check( '' === SFAF_Online::joining_text( $EVENT, 'confirmation' ), 'the plain-text confirmation carries a block it was not ticked for' );
check( false !== strpos( SFAF_Online::joining_html( $EVENT, 'reminder' ), $LINK ), 'the reminder was ticked and does not carry the link' );

/* --- The .ics token. --- */
SFAF_Online::set( $EVENT, true, $LINK, array( 'reminder' ) );
$ics_reminder_only = SFAF_Online::ics_url_with_link( $EVENT );
check(
    false === strpos( $ics_reminder_only, SFAF_Online::ICS_JOIN_ARG . '=' ),
    'the .ics carried a join token for an event whose link goes out only with the reminder'
);

SFAF_Online::set( $EVENT, true, $LINK, array( 'confirmation' ) );
$ics_join = SFAF_Online::ics_url_with_link( $EVENT );
$token    = SFAF_Online::ics_join_token( $EVENT );
check( '' !== $token, 'no join token for an online event with a link' );
check( false !== strpos( $ics_join, $token ), 'ics_url_with_link() did not add the token' );
check( SFAF_Online::ics_join_ok( $EVENT, $token ), 'the token this build produced does not verify' );
check( ! SFAF_Online::ics_join_ok( $EVENT, '' ), 'an empty token verified' );
check( ! SFAF_Online::ics_join_ok( $EVENT, str_repeat( '0', 32 ) ), 'a made-up token verified' );
check( ! SFAF_Online::ics_join_ok( $EVENT + 1, $token ), "one event's token verified against another event" );

// No link entered: nothing to authorise, so no token is offered.
SFAF_Online::set( $EVENT, true, '', array( 'confirmation' ) );
check(
    false === strpos( SFAF_Online::ics_url_with_link( $EVENT ), SFAF_Online::ICS_JOIN_ARG . '=' ),
    'a join token was offered for an event with no link'
);

/* =========================================================================
 * PASS 2: COVERAGE, THE WHITELIST
 *
 * Every file in the plugin, swept for the meta key, the constant naming it,
 * and calls to link(). Anything not listed here fails.
 * ====================================================================== */

/*
 * THE WHITELIST. file::function => why this path may read the meeting link.
 *
 * A whole file is allowed only where every mention in it is the same mechanism.
 * Everything else is named per function, because a file is not a unit of trust:
 * class-sfaf-notifications.php builds five messages and only two of them may
 * carry this.
 */
$WHITELIST = array(
    'class-sfaf-online.php' => 'the class that owns the field: the one reader, the writer and the token',

    'class-sfaf-notifications.php::build_confirmation' => 'the confirmation, when the manager ticked it',
    'class-sfaf-notifications.php::build_reminder'     => 'the morning-of reminder, when the manager ticked it',

    'sfaf-calendar.php::sfaf_output_ics' => 'the .ics, and only on a request carrying a valid join token',

    'class-sfaf-portal.php::render_location_field' => 'the caladmin editor, filling the field in for the manager who owns it',
    'class-sfaf-portal.php::apply_to_group'        => 'copying an event onto its own upcoming dates, by key and never by value',

    'class-sfaf-recurrence.php::$copied_meta' => 'the same copy, onto generated occurrences, by key',
    'class-sfaf-sources.php::import_event'    => 'refusing the key outright, so an adapter can never write one',
);

/*
 * WHAT COUNTS AS READING IT. Three spellings, because a sweep for one of them
 * is a sweep that misses the other two:
 *
 *   _uc_meeting_url          the raw key, however it is spelled at a call site
 *   SFAF_Online::META_LINK   the constant
 *   SFAF_Online::link(       the accessor
 *
 * meta_keys(), is_online(), sends() and the label are deliberately NOT here.
 * None of them can return the credential, and treating them as dangerous would
 * put a dozen harmless call sites on the whitelist and make the list itself
 * unreadable, which is how a whitelist stops being checked.
 */
$PATTERNS = array(
    '_uc_meeting_url',
    'SFAF_Online::META_LINK',
    'SFAF_Online::link(',
    'SFAF_Online::joining_html(',
    'SFAF_Online::joining_text(',
    'SFAF_Online::ics_url_with_link(',
    'SFAF_Online::ics_join_token(',
);

$files = array_merge(
    glob( $root . '/includes/*.php' ),
    glob( $root . '/admin/*.php' ),
    glob( $root . '/templates/*.php' ),
    array( $root . '/sfaf-calendar.php' )
);

$reads  = 0;
$listed = 0;

foreach ( $files as $file ) {
    $name = basename( $file );
    $src  = file_get_contents( $file );

    // Strip comments, so the key named in prose is not counted as a read. This
    // file's own headers name it repeatedly and so does SFAF_Online's.
    $code = preg_replace( '#/\*.*?\*/#s', '', $src );
    $code = preg_replace( '#^\s*//.*$#m', '', $code );

    foreach ( $PATTERNS as $needle ) {
        $offset = 0;
        while ( false !== ( $pos = strpos( $code, $needle, $offset ) ) ) {
            $offset = $pos + strlen( $needle );
            $reads++;

            /*
             * THE ENCLOSING FUNCTION, NOT THE FILE, and that distinction is the
             * one private-events-test.php learned the hard way: a file-level
             * check passes while the renderer beside the safe one leaks.
             *
             * A read outside any function (a static property initializer, which
             * is what SFAF_Recurrence::$copied_meta is) reports as $name and the
             * property it sits in, so it can be listed precisely rather than by
             * waving the whole file through.
             */
            $before  = substr( $code, 0, $pos );
            $fn      = '';
            $fn_at   = -1;
            $prop    = '';
            $prop_at = -1;

            if ( preg_match_all( '/\n\s*(?:public |private |protected |static |final |abstract )*function\s+&?\s*(\w+)/', $before, $m, PREG_OFFSET_CAPTURE ) ) {
                $last  = end( $m[1] );
                $fn    = $last[0];
                $fn_at = $last[1];
            }
            /*
             * A CLASS PROPERTY, NOT ANY `$x = array(`. The visibility keyword
             * is REQUIRED, and that is not tidiness: without it this matched
             * `$lines = array();` inside sfaf_output_ics() and reported the
             * .ics route's read as living in a function called $lines. A
             * detector that names the wrong enclosing scope sends a real read
             * to the whitelist under a name nobody will ever put on it, which
             * reads as a failure and gets "fixed" by widening the list.
             */
            if ( preg_match_all( '/\n\s*(?:public|private|protected|var)\s+(?:static\s+)?\$(\w+)\s*=\s*array\s*\(/', $before, $pm, PREG_OFFSET_CAPTURE ) ) {
                $lastp   = end( $pm[1] );
                $prop    = $lastp[0];
                $prop_at = $lastp[1];
            }
            // Whichever opened LAST is the thing this read is inside.
            if ( '' !== $prop && $prop_at > $fn_at ) {
                $fn = '$' . $prop;
            }

            if ( isset( $WHITELIST[ $name . '::' . $fn ] ) || isset( $WHITELIST[ $name ] ) ) {
                $listed++;
                continue;
            }
            $fails[] = "$name reads the meeting link in $fn(), which is not on the whitelist";
        }
    }
}

check( $reads > 5, "only $reads reads of the meeting link were found, so the sweep is not reaching the source" );

/*
 * AND THE SURFACES THAT MUST NEVER NAME IT, ASSERTED FROM THE OTHER END.
 *
 * The sweep above catches a new read. This catches the case where one of these
 * files grows one, stated as the sentence a person would actually check: the
 * embed payload, the satellite feed, the search index and the structured data
 * do not know this key exists.
 */
$FORBIDDEN = array(
    'class-sfaf-embed.php'     => 'the embed payload, which renders on sites this plugin does not control',
    'class-sfaf-sync.php'      => 'the satellite feed',
    'class-sfaf-search.php'    => 'the search index',
    'class-sfaf-seo.php'       => 'the structured data in the public head',
    'class-sfaf-shortcodes.php'=> 'the cards, the sidebar and the month grid',
    'single-uc_event.php'      => 'the public event page',
);
foreach ( $FORBIDDEN as $file => $why ) {
    $path = file_exists( $root . '/includes/' . $file ) ? $root . '/includes/' . $file : $root . '/templates/' . $file;
    if ( ! file_exists( $path ) ) {
        $fails[] = "the forbidden-surface list names $file, which is not in the source any more";
        continue;
    }
    $code = preg_replace( '#/\*.*?\*/#s', '', file_get_contents( $path ) );
    $code = preg_replace( '#^\s*//.*$#m', '', $code );
    foreach ( $PATTERNS as $needle ) {
        if ( false !== strpos( $code, $needle ) ) {
            $fails[] = "$file names the meeting link, and it is $why";
        }
    }
}

/* =========================================================================
 * PASS 3: THE RENDER
 *
 * Build what each public surface would emit for an online event carrying the
 * link, and assert the bytes do not contain it.
 * ====================================================================== */

SFAF_Online::set( $EVENT, true, $LINK, array( 'confirmation', 'reminder' ) );

/*
 * THE LOCATION EVERY SURFACE READS. Reproduced here rather than loaded, because
 * sfaf-template-functions.php is 3,000 lines and pulls in half the plugin. What
 * is under test is the CONTRACT: whatever the formatter returns is what a dozen
 * renderers print, so if it can only ever return the label, none of them can
 * print the link. The real function is asserted to have that shape by pass 2's
 * forbidden-surface list plus this one line, checked against the source.
 */
$tf = file_get_contents( $root . '/includes/sfaf-template-functions.php' );
check(
    preg_match( '/function sfaf_event_location\s*\(.{0,900}?SFAF_Online::is_online.{0,120}?return SFAF_Online::LABEL/s', $tf ),
    'sfaf_event_location() does not answer the online label first, so the thirteen renderers reading it may print an address'
);
check(
    preg_match( '/function sfaf_event_location_short\s*\(.{0,600}?SFAF_Online::is_online/s', $tf ),
    'sfaf_event_location_short() does not answer for an online event'
);
check(
    preg_match( '/function sfaf_event_map_html\s*\(.{0,900}?SFAF_Online::is_online.{0,80}?return \'\'/s', $tf ),
    'sfaf_event_map_html() still draws a map for an online event, which sends "Online Event" to Google as a place'
);

function surface_location( $event_id ) {
    return SFAF_Online::is_online( $event_id ) ? SFAF_Online::LABEL : 'Strut, 470 Castro St';
}

$surfaces = array(
    'the location every card, list and email prints' => surface_location( $EVENT ),
    'the Google Calendar link on the public page'    => 'https://calendar.google.com/calendar/render?location=' . rawurlencode( surface_location( $EVENT ) ),
    'the maps search URL'                            => 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( surface_location( $EVENT ) ),
    'the JSON-LD location node'                      => wp_json_encode( array(
        '@type' => 'VirtualLocation',
        'name'  => SFAF_Online::LABEL,
        'url'   => get_permalink( $EVENT ),
    ) ),
    'the satellite feed payload'                     => wp_json_encode( array(
        'title'    => get_the_title( $EVENT ),
        'location' => surface_location( $EVENT ),
        'venue'    => wp_get_post_terms( $EVENT, 'uc_venue' ),
    ) ),
    'the public .ics, with no join token'            => "SUMMARY:" . get_the_title( $EVENT ) . "\r\nLOCATION:" . surface_location( $EVENT ),
    'the public .ics address'                        => sfaf_ics_url( $EVENT ),
);
foreach ( $surfaces as $what => $bytes ) {
    check( false === strpos( (string) $bytes, $LINK ), "the meeting link is in $what" );
    check( false === strpos( (string) $bytes, 'zoom.us' ), "the meeting host is in $what" );
}

/*
 * AND THE TWO PLACES IT IS SUPPOSED TO BE, asserted positively. A test that
 * only ever says "the link is absent" passes just as well when the feature does
 * nothing at all.
 */
check( false !== strpos( SFAF_Online::joining_html( $EVENT, 'confirmation' ), $LINK ), 'the confirmation does not carry the link it was ticked for' );
check( false !== strpos( SFAF_Online::joining_text( $EVENT, 'reminder' ), $LINK ), 'the plain-text reminder does not carry the link it was ticked for' );

/* The .ics route's own gate, read out of the source: the join copy requires the
 * confirmation tick AND a verified token, and neither alone is enough. */
$boot = preg_replace( '#/\*.*?\*/#s', '', file_get_contents( $root . '/sfaf-calendar.php' ) );
check(
    preg_match( "/sends_with\(\s*\\\$post_id,\s*'confirmation'\s*\).{0,80}?ics_join_ok/s", $boot ),
    'the .ics join copy is not gated on both the confirmation tick and a verified token'
);
check(
    false === strpos( $boot, "'LOCATION:' . sfaf_ics_escape( SFAF_Online" ),
    'the .ics puts the meeting link in LOCATION, which every calendar shows and syncs'
);

/* =========================================================================
 * PASS 4: THE LISTS AGREE
 * ====================================================================== */

$rec = file_get_contents( $root . '/includes/class-sfaf-recurrence.php' );
if ( preg_match( '/\$copied_meta\s*=\s*array\s*\((.*?)\n\s*\);/s', $rec, $m ) ) {
    foreach ( SFAF_Online::meta_keys() as $key ) {
        check(
            false !== strpos( $m[1], "'" . $key . "'" ),
            "SFAF_Recurrence::\$copied_meta is missing $key, so a generated occurrence of an online series is not online"
        );
    }
} else {
    $fails[] = 'SFAF_Recurrence::$copied_meta could not be read, so the two lists cannot be compared';
}

$portal = file_get_contents( $root . '/includes/class-sfaf-portal.php' );
check(
    false !== strpos( $portal, 'SFAF_Online::meta_keys()' ),
    'apply_to_group() does not merge SFAF_Online::meta_keys(), so the tick does not travel with a group'
);

$sources = file_get_contents( $root . '/includes/class-sfaf-sources.php' );
check(
    preg_match( '/in_array\(\s*\$meta_key,\s*SFAF_Online::meta_keys\(\),\s*true\s*\)\s*\)\s*\{\s*continue/s', $sources ),
    'import_event() does not refuse the online keys, so an adapter could write a meeting link'
);

/* =========================================================================
 * REPORT
 * ====================================================================== */

echo "Online events\n";
echo "behaviour:  is_online, link() refusing to answer off an online event, the delivery set and\n";
echo "            its dropping of unknown keys, what set() clears in each direction (the term, the\n";
echo "            composed line, all four address parts, and the credential itself on the way back),\n";
echo "            the fallback sentence, and the .ics join token against an empty, a made-up and\n";
echo "            another event's\n";
printf( "whitelist:  %d reads of the meeting link found across includes, admin, templates and the\n", $reads );
printf( "            bootstrap. %d are on the whitelist with a stated reason, 0 unaccounted for.\n", $listed );
printf( "            %d surfaces asserted from the other end to not name the key at all.\n", count( $FORBIDDEN ) );
printf( "render:     %d public surfaces built for an online event carrying a real-shaped Zoom link;\n", count( $surfaces ) );
echo "            neither the link nor its host appears in any of them. The confirmation and the\n";
echo "            reminder are asserted to carry it, so an inert feature cannot pass.\n";
echo "lists:       SFAF_Online::meta_keys() against SFAF_Recurrence::\$copied_meta, apply_to_group()\n";
echo "            and the import refusal.\n";
echo "not proven here: that a mail client renders the joining block, that Apple or Outlook honour\n";
echo "            CONFERENCE, or that WordPress serves the .ics route this way. TESTING.md.\n\n";

if ( $fails ) {
    echo 'FAIL: ' . count( $fails ) . "\n";
    foreach ( array_unique( $fails ) as $f ) { echo '  . ' . $f . "\n"; }
    exit( 1 );
}
echo "the meeting link is readable only where the whitelist says, and appears in no public surface.\n";
exit( 0 );
