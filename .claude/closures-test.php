<?php
/**
 * CLOSURES: THE MODEL, AND THE PROOF THEY CANNOT LEAK INTO EVENT MACHINERY.
 *
 * A closure is a day SFAF is shut. It has no page, nothing to click, nothing to
 * register for, and nothing scheduled about it. The brief for 3.38.0 asked for
 * an enumeration of everything checked to confirm it cannot reach the pending
 * queue, the ICS feed, search, RSVP, reminders, or anything else that assumes
 * an event post exists.
 *
 * THE ANSWER IS STRUCTURAL, WHICH IS WHY IT IS SHORT.
 *
 * Every one of those subsystems reaches events through a WP_Query over the
 * `uc_event` post type, or through a post id that must resolve to a `uc_event`
 * post. A closure is a row in an option: it has no post id, no post type, and no
 * row in wp_posts. None of them CAN see it, and none of them needed a new
 * exclusion.
 *
 * That is a property of the storage choice, so the load-bearing assertion in
 * this file is the one that fails if the storage choice is ever reversed: a
 * closure must never become a post type. Everything after it follows.
 *
 *     php .claude/closures-test.php
 */

$root = dirname( __DIR__ );

define( 'ABSPATH', __DIR__ );
date_default_timezone_set( 'America/Los_Angeles' );

$fails = array();

/* ---------------------------------------------------------------------------
 * WordPress, in miniature.
 * ------------------------------------------------------------------------ */
$GLOBALS['opt'] = array();

function get_option( $n, $d = false ) { return array_key_exists( $n, $GLOBALS['opt'] ) ? $GLOBALS['opt'][ $n ] : $d; }
function update_option( $n, $v, $a = null ) { $GLOBALS['opt'][ $n ] = $v; return true; }
function delete_option( $n ) { unset( $GLOBALS['opt'][ $n ] ); return true; }
function wp_strip_all_tags( $t, $b = false ) { return strip_tags( (string) $t ); }
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }

class WP_Error {
    public $code; public $message;
    public function __construct( $c = '', $m = '', $d = '' ) { $this->code = $c; $this->message = $m; }
    public function get_error_message() { return $this->message; }
    public function get_error_code() { return $this->code; }
}

/** The house formatter, stubbed without spelling out the house format. */
function sfaf_ap_date( $d, $f = 'full' ) {
    if ( '' === $d ) { return ''; }
    $ts = strtotime( $d . ' 12:00:00' );
    if ( ! $ts ) { return ''; }
    $months = array( 1 => 'January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December' );
    $p = explode( '-', gmdate( 'Y-m-d', $ts ) );
    return $months[ (int) $p[1] ] . ' ' . (int) $p[2] . ', ' . $p[0];
}

require_once $root . '/includes/class-sfaf-closures.php';

function expect( $label, $got, $want ) {
    global $fails;
    if ( $got !== $want ) {
        $fails[] = sprintf( '%s: got %s, expected %s', $label, var_export( $got, true ), var_export( $want, true ) );
    }
}

/* ===========================================================================
 * 1. THE MODEL.
 * ======================================================================== */
echo "The model\n";

$id = SFAF_Closures::save( '', 'Thanksgiving', '2026-11-26' );
expect( 'a single-day closure saves', is_wp_error( $id ), false );

$row = SFAF_Closures::get( $id );
expect( 'start', $row['start'], '2026-11-26' );
expect( 'a single day ends the day it starts', $row['end'], '2026-11-26' );
expect( 'the phrase', SFAF_Closures::text( $row ), 'Closed for Thanksgiving' );

// A closure with no label still reads, rather than trailing off.
$bare = SFAF_Closures::save( '', '', '2026-07-04' );
expect( 'an unlabelled closure reads', SFAF_Closures::text( SFAF_Closures::get( $bare ) ), 'Closed' );

expect( 'a closure with no date is refused', is_wp_error( SFAF_Closures::save( '', 'x', '' ) ), true );
expect( 'a backwards range is refused', is_wp_error( SFAF_Closures::save( '', 'x', '2026-12-27', '2026-12-24' ) ), true );

/*
 * A DATE THAT LOOKS LIKE ONE AND IS NOT. strtotime('2026-02-31') answers March
 * 3rd, so a closure entered for a day that does not exist would silently move to
 * one that does. checkdate() is why this refuses instead.
 */
expect( 'February 31st is refused', is_wp_error( SFAF_Closures::save( '', 'x', '2026-02-31' ) ), true );
expect( 'so is a non-date',         is_wp_error( SFAF_Closures::save( '', 'x', 'next tuesday' ) ), true );

/* ===========================================================================
 * 2. A MULTI-DAY CLOSURE IS ONE ENTRY.
 *
 * The brief asked which it would be. One entry spanning dates: somebody closing
 * for the winter break enters it once and edits it once, and four rows would be
 * four chances to type it differently. The two renderers then ask different
 * questions of that one entry, which is the part worth asserting.
 * ======================================================================== */
echo "A multi-day closure is one entry\n";

$winter = SFAF_Closures::save( '', 'the winter break', '2026-12-24', '2026-12-27' );

// ONE ROW FOR FOUR DAYS. Asked of December rather than of the whole option, so
// adding a case above this line cannot change the answer.
$in_december = array_filter( SFAF_Closures::all(), function ( $r ) {
    return 0 === strpos( $r['start'], '2026-12' );
} );
expect( 'a four-day closure is one row', count( $in_december ), 1 );

// THE GRID'S QUESTION: per day. All four days answer from the one entry.
foreach ( array( '2026-12-24', '2026-12-25', '2026-12-26', '2026-12-27' ) as $d ) {
    expect( "the grid marks $d", SFAF_Closures::is_closed( $d ), true );
}
expect( 'and not the day before', SFAF_Closures::is_closed( '2026-12-23' ), false );
expect( 'nor the day after',      SFAF_Closures::is_closed( '2026-12-28' ), false );

// A LIST'S QUESTION: per span. One card for the four days.
$spans = SFAF_Closures::spans( '2026-12-01', '2026-12-31' );
expect( 'a list shows one card for four days', count( $spans ), 1 );
expect( 'and reads the range',
    SFAF_Closures::when( $spans[0] ),
    sfaf_ap_date( '2026-12-24', 'full' ) . ' to ' . sfaf_ap_date( '2026-12-27', 'full' ) );

// A window that only clips the closure still finds it.
expect( 'a window overlapping the start finds it', count( SFAF_Closures::spans( '2026-12-26', '2027-01-05' ) ), 1 );
expect( 'a window entirely before finds nothing',  count( SFAF_Closures::spans( '2026-12-01', '2026-12-10' ) ), 0 );

/* ===========================================================================
 * 3. IT CANNOT LEAK INTO EVENT MACHINERY.
 *
 * THE ONE ASSERTION EVERYTHING ELSE RESTS ON. Every subsystem below reaches
 * events through a WP_Query over uc_event or through a post id, and a closure
 * has neither. If a closure ever becomes a post type, all of that stops being
 * true at once and every exclusion would have to be found and written by hand.
 * ======================================================================== */
echo "It cannot leak into event machinery\n";

/*
 * COMMENTS FIRST. The first cut of this matched the class's own docblock,
 * which explains why a closure is NOT a post type and uses the words
 * "register_post_type()" to say so. A sweep that matches its own prose is a
 * false positive this project has recorded twice.
 */
$closures_src = file_get_contents( $root . '/includes/class-sfaf-closures.php' );
$closures_src = preg_replace( '#/\*.*?\*/#s', '', $closures_src );
$closures_src = preg_replace( '#^\s*//.*$#m', '', $closures_src );

if ( false !== strpos( $closures_src, 'register_post_type' ) ) {
    $fails[] = 'SFAF_Closures registers a post type. Every "it cannot reach X" below depended on it not being one.';
}
if ( false !== strpos( $closures_src, 'register_taxonomy' ) ) {
    $fails[] = 'SFAF_Closures registers a taxonomy, which would put closures in term queries.';
}
if ( preg_match( '#\bwp_insert_post\b|\bwp_update_post\b#', $closures_src ) ) {
    $fails[] = 'SFAF_Closures writes posts. A closure must not exist in wp_posts.';
}

/*
 * AND THE SUBSYSTEMS, ENUMERATED. Each is named with the mechanism that makes
 * it blind to a closure, and each is checked for not having grown a reference.
 * A file that starts mentioning SFAF_Closures is either a renderer (fine, and
 * listed) or a leak (not fine, and caught).
 */
$RENDERERS = array(
    // Allowed to know about closures: they draw them.
    'class-sfaf-shortcodes.php' => 'the month grid marks the day and the card list shows a flat card',
    'class-sfaf-admin.php'      => 'the screen closures are entered on',
    'class-sfaf-closures.php'   => 'itself',
);

$MUST_NOT_SEE = array(
    'class-sfaf-sources.php'       => 'the import queue: WP_Query over uc_event, and a closure has no post id to match',
    'class-sfaf-rsvp.php'          => 'registrations: every path starts from an event_id that must resolve to a uc_event post',
    'class-sfaf-reminders.php'     => 'the morning-of reminder: WP_Query over uc_event with post_status publish',
    'class-sfaf-notifications.php' => 'the pre-event summary: the same query, and the message builders read post meta',
    'class-sfaf-search.php'        => 'search: a whitelist of post meta and taxonomies on uc_event',
    'class-sfaf-privacy.php'       => 'privacy: hooks on post queries and sitemaps, which a closure is not in',
    'class-sfaf-seo.php'           => 'schema and meta tags: hooked to the singular event template',
    'class-sfaf-recurrence.php'    => 'recurrence groups: post meta on uc_event',
    'class-sfaf-series.php'        => 'series membership: a taxonomy relationship on uc_event',
    'class-sfaf-teams.php'         => 'team access: post meta on uc_event',
    'class-sfaf-organizers.php'    => 'organizers: a taxonomy relationship on uc_event',
    'class-sfaf-cancellation.php'  => 'cancellation: post meta on uc_event',
    'class-sfaf-announce.php'      => 'the cancelled and changed emails: registrations, keyed by event_id',
    'class-sfaf-cron.php'          => 'the scheduled runner: it runs the three jobs above and nothing else',
    'class-sfaf-embed.php'         => 'the embed endpoint: it renders through the shortcode class, which draws them correctly',
    'class-sfaf-sync.php'          => 'the satellite feed: WP_Query over uc_event',
    'class-sfaf-venues.php'        => 'venues: a taxonomy relationship on uc_event',
    'class-sfaf-faq-sets.php'      => 'FAQ sets: post meta on uc_event',
    'class-sfaf-post-types.php'    => 'registration: a closure is not registered as anything',
);

foreach ( $MUST_NOT_SEE as $file => $why ) {
    $path = $root . '/includes/' . $file;
    if ( ! file_exists( $path ) ) {
        $fails[] = "$file is on the leak list and does not exist. The list is stale.";
        continue;
    }
    $code = preg_replace( '#/\*.*?\*/#s', '', file_get_contents( $path ) );
    $code = preg_replace( '#^\s*//.*$#m', '', $code );

    if ( false !== strpos( $code, 'SFAF_Closures' ) ) {
        $fails[] = "$file now references SFAF_Closures. It is on the list because $why, so either the leak is real or this file has become a renderer and belongs on the other list.";
    }
}

// The .ics feed lives in the main plugin file.
$main = preg_replace( '#/\*.*?\*/#s', '', file_get_contents( $root . '/sfaf-calendar.php' ) );
if ( false !== strpos( $main, 'SFAF_Closures::' ) ) {
    $fails[] = 'sfaf-calendar.php calls SFAF_Closures. The .ics feed and the REST feed are built there from uc_event ids, and a closure has none.';
}

// And the renderers that ARE allowed to know must still be drawing, not
// scheduling: a renderer that started sending mail would be the real leak.
foreach ( array_keys( $RENDERERS ) as $file ) {
    $path = file_exists( $root . '/includes/' . $file ) ? $root . '/includes/' . $file : $root . '/admin/' . $file;
    if ( ! file_exists( $path ) ) {
        continue;
    }
    $code = file_get_contents( $path );
    if ( preg_match( '#SFAF_Closures::[a-z_]+\s*\([^)]*\)\s*;?\s*\n?\s*(SFAF_Email|SFAF_Announce|wp_mail)#', $code ) ) {
        $fails[] = "$file uses a closure to send something. Closures notify nobody.";
    }
}

/* ===========================================================================
 * 4. NOTHING CLICKABLE.
 * ======================================================================== */
echo "Nothing clickable\n";

$short_src = file_get_contents( $root . '/includes/class-sfaf-shortcodes.php' );

if ( ! preg_match( '#function render_closure_card\s*\(.*?\)\s*\{#s', $short_src, $m, PREG_OFFSET_CAPTURE ) ) {
    $fails[] = 'render_closure_card() not found';
} else {
    $start = $m[0][1];
    $depth = 0;
    $end   = $start;
    for ( $i = strpos( $short_src, '{', $start ), $n = strlen( $short_src ); $i < $n; $i++ ) {
        if ( '{' === $short_src[ $i ] ) { $depth++; }
        if ( '}' === $short_src[ $i ] ) { $depth--; if ( 0 === $depth ) { $end = $i; break; } }
    }
    $card = substr( $short_src, $start, $end - $start );

    /*
     * A SEPARATE RENDERER WITH NO CODE PATH TO A LINK, which is the standing
     * rule from PROJECT.md section 5 applied to a new surface: a method that
     * cannot emit an anchor cannot grow one by being edited carelessly.
     */
    foreach ( array(
        '<a '            => 'an anchor',
        'get_permalink'  => 'a permalink',
        'sfaf_event_link'=> 'an event link',
        'href'           => 'an href',
        'rsvp'           => 'anything about registrations',
    ) as $needle => $what ) {
        if ( false !== stripos( $card, $needle ) ) {
            $fails[] = "render_closure_card() contains $what. A closure has no page and nothing to register for.";
        }
    }
}

// The grid cell's mark, likewise.
if ( preg_match( '#uc-day-closed-mark.*?</span>#s', $short_src, $m ) ) {
    if ( false !== stripos( $m[0], '<a ' ) || false !== stripos( $m[0], 'href' ) ) {
        $fails[] = 'the month grid closure mark is a link. It must not be clickable.';
    }
} else {
    $fails[] = 'the month grid closure mark was not found';
}

/* ===========================================================================
 * 5. IT REACHES THE EMBED.
 *
 * Not by anything of its own: the embed payload is built by calling the same
 * shortcode renderers, so a closure drawn by those is drawn in the embed. That
 * is asserted rather than assumed, because "the embed serves what the shortcode
 * renders" is the property the whole embed rests on.
 * ======================================================================== */
echo "It reaches the embed\n";

$embed_src = file_get_contents( $root . '/includes/class-sfaf-embed.php' );
if ( ! preg_match( '#render_events|render_calendar_block|build_payload#', $embed_src ) ) {
    $fails[] = 'the embed no longer builds its payload from the shortcode renderers, so closures may not reach it';
}

/* ------------------------------------------------------------------------ */
echo "\nClosures test\n";
printf( "checked: the model and its date validation; that a multi-day closure is ONE entry which the\n" );
printf( "         grid expands per day and a list shows as one card; that %d subsystems cannot see a\n", count( $MUST_NOT_SEE ) );
printf( "         closure and none has grown a reference; that the storage choice which makes that true\n" );
printf( "         is still in place; that neither the card nor the grid mark is clickable or mentions\n" );
printf( "         registration; and that the embed still renders through the shortcode class\n\n" );

if ( $fails ) {
    echo 'FAIL: ' . count( $fails ) . "\n";
    foreach ( $fails as $f ) { echo "  . $f\n"; }
    exit( 1 );
}
echo "a closure is a day on a calendar and nothing else can see it.\n";
