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

/* ---- THE FREE TEXT NOTE (3.84.0). ----
 *
 * SFAF can be closed while one site stays open, so a closure can carry a
 * sentence saying so. The rule that matters is that a closure WITHOUT one is
 * unchanged, because every closure that exists today has none. */
expect( 'a closure with no note reads exactly as it did', SFAF_Closures::note( $row ), '' );
expect( 'and its sentence is untouched', SFAF_Closures::text( $row ), 'Closed for Thanksgiving' );

$noted = SFAF_Closures::save( '', 'Labor Day', '2026-09-07', '', 'The 6th Street Center is open as usual' );
$nrow  = SFAF_Closures::get( $noted );
expect( 'the note is stored', SFAF_Closures::note( $nrow ), 'The 6th Street Center is open as usual' );
expect( 'the note does NOT change the sentence', SFAF_Closures::text( $nrow ), 'Closed for Labor Day' );

/* A ROW SAVED BEFORE THE NOTE EXISTED has no 'note' key at all. Reading one is
 * the whole of the migration and it must not warn or fatal. */
$legacy = array( 'label' => 'Old', 'start' => '2026-01-01', 'end' => '2026-01-01' );
expect( 'a pre-3.84.0 row reads as an empty note', SFAF_Closures::note( $legacy ), '' );
expect( 'and shortens to nothing rather than an ellipsis', SFAF_Closures::note_short( $legacy ), '' );

/* THE GRID GETS A SHORTER ONE, cut on a word boundary and marked. */
$long  = SFAF_Closures::save( '', 'Spring break', '2026-04-06', '2026-04-08', 'The 6th Street Center and the Castro site are both open their usual hours' );
$lrow  = SFAF_Closures::get( $long );
$brief = SFAF_Closures::note_short( $lrow );
/* NO mb_* IN HERE. mbstring is not loaded in this environment, which is the
 * reason note_short() guards every call to it with function_exists(). A test
 * that reached for mb_substr would pass on a server and fatal here, so the
 * assertions below are byte-safe on purpose. */
expect( 'a long note is shortened for the cell', ( $brief !== SFAF_Closures::note( $lrow ) ), true );
expect( 'the cut is marked', str_ends_with( $brief, '…' ), true );
$stem = substr( $brief, 0, -3 ); // '…' is three bytes in UTF-8.
expect( 'the cut is on a word boundary', ( ' ' !== substr( $stem, -1 ) ), true );
expect( 'the shortened note is a prefix of the real one', ( 0 === strpos( SFAF_Closures::note( $lrow ), $stem ) ), true );
expect( 'the full note is still there to hover', ( strlen( SFAF_Closures::note( $lrow ) ) > strlen( $brief ) ), true );

/* A SHORT NOTE IS NOT TOUCHED, so an ellipsis never appears without a reason. */
$shorty = SFAF_Closures::save( '', 'Fourth', '2026-07-04', '', 'Castro open' );
expect( 'a short note is left alone', SFAF_Closures::note_short( SFAF_Closures::get( $shorty ) ), 'Castro open' );

/* TAGS ARE STRIPPED. It is typed by an administrator and rendered publicly. */
$tagged = SFAF_Closures::save( '', 'Tagged', '2026-03-03', '', 'Open <script>alert(1)</script> as usual' );
expect( 'markup is stripped from a note', ( false === strpos( SFAF_Closures::note( SFAF_Closures::get( $tagged ) ), '<' ) ), true );

/* BOTH RENDERERS MUST CARRY IT, and neither may be the only one that does.
 *
 * COMMENTS ARE STRIPPED FIRST, WITH THE TOKENIZER, and the first draft of these
 * three checks is the reason. They ran against the raw file, so "the grid cell
 * gets note_short()" written in a DOCBLOCK satisfied the note_short check, and
 * renaming the class to uc-closure-noteX still matched /uc-closure-note/ as a
 * substring. Both faults were planted and neither was caught: the checks were
 * reading prose and matching prefixes rather than asserting behaviour.
 *
 * This is PROJECT.md's rule about auditing with a tokenizer rather than grep,
 * met the hard way a second time. */
$sc_raw  = file_get_contents( $root . '/includes/class-sfaf-shortcodes.php' );
$sc_code = '';
foreach ( token_get_all( $sc_raw ) as $tok ) {
    if ( is_array( $tok ) ) {
        if ( T_COMMENT === $tok[0] || T_DOC_COMMENT === $tok[0] ) {
            continue;
        }
        $sc_code .= $tok[1];
    } else {
        $sc_code .= $tok;
    }
}

/* Quoted exactly, so a renamed class is a changed class rather than a prefix. */
if ( ! preg_match( '/"uc-closure-note"/', $sc_code ) ) {
    $fails[] = 'the list card no longer renders a closure note';
}
if ( ! preg_match( '/"uc-closed-note"/', $sc_code ) ) {
    $fails[] = 'the month grid no longer renders a closure note';
}
if ( ! preg_match( '/SFAF_Closures::note_short\(/', $sc_code ) ) {
    $fails[] = 'the month grid renders the full note rather than the shortened one, which overflows a cell';
}
/* The card is the surface with room, so it must NOT be the shortened one. */
if ( ! preg_match( '/\$note\s*=\s*SFAF_Closures::note\(/', $sc_code ) ) {
    $fails[] = 'the list card no longer reads the full note, so both surfaces now show the shortened one';
}

/* ---- EDITING (3.86.0). ----
 *
 * THE MODEL COULD ALWAYS DO THIS AND THE SCREEN NEVER OFFERED IT. save() takes
 * an existing id and updates that row; what was missing was any way to send
 * one, because the admin form hardcoded `closure_id` to the empty string and
 * the table offered Remove and nothing else. So a closure could only be changed
 * by deleting and retyping it, which made the note added in 3.84.0 unreachable
 * on every closure that already existed.
 *
 * The model half is asserted here. The half that actually broke was the FORM,
 * so that is asserted against the source below. */
$ed = SFAF_Closures::save( '', 'Staff day', '2026-05-04', '2026-05-05', 'Castro open' );
$before = SFAF_Closures::get( $ed );
expect( 'a closure to edit exists', is_array( $before ), true );

$again = SFAF_Closures::save( $ed, 'Staff training day', '2026-05-04', '2026-05-06', 'Castro and 6th Street open' );
expect( 'editing returns the SAME id rather than making a second closure', $again, $ed );

$after = SFAF_Closures::get( $ed );
expect( 'the name changed',  SFAF_Closures::name( $after ), 'Staff training day' );
expect( 'the last day changed', $after['end'], '2026-05-06' );
expect( 'the note changed',  SFAF_Closures::note( $after ), 'Castro and 6th Street open' );
expect( 'the first day is unchanged', $after['start'], '2026-05-04' );

/* AND IT IS STILL ONE ENTRY. An edit that quietly added a row would leave the
 * grid marking both the old span and the new one. */
$count_now = 0;
foreach ( SFAF_Closures::all() as $r ) {
    if ( 'Staff training day' === SFAF_Closures::name( $r ) ) { $count_now++; }
}
expect( 'a multi-day closure stays ONE entry after an edit', $count_now, 1 );

/* A NOTE CAN BE CLEARED, which "edit the note" has to mean both ways. */
SFAF_Closures::save( $ed, 'Staff training day', '2026-05-04', '2026-05-06', '' );
expect( 'the note can be emptied again', SFAF_Closures::note( SFAF_Closures::get( $ed ) ), '' );

/* ---- THE FORM MUST BE ABLE TO SEND AN ID. ----
 *
 * This is the assertion that would have failed for every release since closures
 * shipped. Comments stripped, because the docblock beside it names the field. */
$admin_raw  = file_get_contents( $root . '/admin/class-sfaf-admin.php' );
$admin_code = '';
foreach ( token_get_all( $admin_raw ) as $tok ) {
    if ( is_array( $tok ) ) {
        if ( T_COMMENT === $tok[0] || T_DOC_COMMENT === $tok[0] ) { continue; }
        $admin_code .= $tok[1];
    } else {
        $admin_code .= $tok;
    }
}
if ( preg_match( '#name="closure_id"\s+value=""#', $admin_code ) ) {
    $fails[] = 'the closure form hardcodes an empty closure_id again, so every save creates a new closure and nothing can be edited';
}
if ( ! preg_match( '#name="closure_id"\s+value="<\?php echo esc_attr\(\s*\$e_id#', $admin_code ) ) {
    $fails[] = 'the closure form no longer carries the id of the closure being edited';
}
foreach ( array( 'e_label', 'e_start', 'e_end', 'e_note' ) as $field ) {
    if ( false === strpos( $admin_code, '$' . $field ) ) {
        $fails[] = 'the closure form does not fill in $' . $field . ', so editing would blank that field';
    }
}
if ( false === strpos( $admin_code, "add_query_arg( 'edit'" ) ) {
    $fails[] = 'the closures table offers no way to reach the edit form';
}

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

/* ---------------------------------------------------------------------------
 * THE TWO RENDERERS AGREE ABOUT WHAT A CLOSURE LOOKS LIKE (3.60.0).
 *
 * They answer different questions on purpose — the grid marks a square per DAY,
 * the list draws one card per SPAN — and that difference is asserted above. What
 * must NOT differ is the treatment: both draw the same two-line label from the
 * same classes, so a reader moving between them sees one thing. Two renderers
 * with two vocabularies for one fact is how the yellow-on-yellow chip happened.
 * ------------------------------------------------------------------------ */
foreach ( array( 'uc-closed-word', 'uc-closed-name' ) as $shared ) {
    if ( ! preg_match( '#uc-day-closed-mark.*?' . preg_quote( $shared, '#' ) . '#s', $short_src ) ) {
        $fails[] = "the month grid's closure mark does not use $shared, so the two renderers have drifted";
    }
    if ( ! preg_match( '#uc-closure-card.*?' . preg_quote( $shared, '#' ) . '#s', $short_src ) ) {
        $fails[] = "the closure card does not use $shared, so the two renderers have drifted";
    }
}

// And the hatch is declared once, not copied. The grid cell needs its own rule
// to out-specify the white td background, which is exactly how a second copy of
// the pattern would get in.
$css_src = file_get_contents( $root . '/public/css/calendar.css' );
if ( substr_count( $css_src, 'repeating-linear-gradient' ) !== substr_count( $css_src, '--uc-closed-hatch-image: repeating-linear-gradient' ) ) {
    $fails[] = 'calendar.css declares a repeating gradient outside --uc-closed-hatch-image; the closure hatch must be defined once and referenced';
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
printf( "         registration; that a closure can carry a free text note which the list card shows in\n" );
printf( "         full and the month grid shortens on a word boundary with the cut marked, that a\n" );
printf( "         closure saved before the note existed reads and renders exactly as it did, and that\n" );
printf( "         all() rebuilds the note rather than dropping it; and that the embed still renders\n" );
printf( "         through the shortcode class\n\n" );

if ( $fails ) {
    echo 'FAIL: ' . count( $fails ) . "\n";
    foreach ( $fails as $f ) { echo "  . $f\n"; }
    exit( 1 );
}
echo "a closure is a day on a calendar and nothing else can see it.\n";
