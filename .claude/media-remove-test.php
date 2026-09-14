<?php
/**
 * WHAT REMOVE DOES TO A PICTURE, AND WHAT IT REFUSES TO DO (3.82.0).
 *
 * WHY. "Pressing Remove refreshes the page and the image is still there." A
 * refresh means something posted, so there were two candidates: the handler ran
 * and the removal did not take, or it never ran. This is the half that settles
 * the first one, by driving the class against a stubbed store; the browser half
 * is .claude/media-remove-live.php, which asks which form a press submits.
 *
 *     php .claude/media-remove-test.php
 *
 * WHAT IT ASSERTS:
 *   1. Removing writes the marker, and row() reports it.
 *   2. pictures() EXCLUDES removed pictures by default and asks for them only
 *      in the one view that wants them. This is what makes remove mean
 *      anything: it is the one builder the grid and both pickers go through.
 *   3. A picture something is using is REFUSED, and the refusal names what.
 *   4. Putting it back is never refused, and undoes exactly the marker.
 *   5. A removed picture is never a series' fallback picture.
 *   6. Nothing deletes a file or an attachment. Asserted over the source,
 *      because it is the promise the control's own confirmation makes.
 */

$root = dirname( __DIR__ );
define( 'ABSPATH', __DIR__ );

$GLOBALS['pm']    = array();   // post meta
$GLOBALS['uses']  = array();   // attachment id => event titles using it
$GLOBALS['sused'] = array();   // attachment id => series names using it
$GLOBALS['lastq'] = null;

function get_post_meta( $id, $key = '', $single = false ) {
    return isset( $GLOBALS['pm'][ $id ][ $key ] ) ? $GLOBALS['pm'][ $id ][ $key ] : '';
}
function update_post_meta( $id, $key, $val ) { $GLOBALS['pm'][ $id ][ $key ] = $val; return true; }
function delete_post_meta( $id, $key, $val = '' ) { unset( $GLOBALS['pm'][ $id ][ $key ] ); return true; }
function get_post_type( $id ) { return 'attachment'; }
function get_the_title( $id = 0 ) { return isset( $GLOBALS['titles'][ $id ] ) ? $GLOBALS['titles'][ $id ] : ''; }
function get_the_terms( $id, $tax ) { return array(); }
function wp_get_attachment_image_url( $id, $s = 'thumbnail', $i = false ) { return 'https://example.org/u/' . $id . '.jpg'; }
function is_wp_error( $t ) { return false; }
function wp_list_pluck( $l, $f, $k = null ) {
    $o = array();
    foreach ( (array) $l as $i ) { $o[] = is_object( $i ) ? $i->$f : $i; }
    return $o;
}
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function checked( $a, $b = true, $e = true ) { return ''; }
function sanitize_text_field( $t ) { return trim( strip_tags( (string) $t ) ); }
function sfaf_icon( $k, $a = array() ) { return ''; }
function wp_update_post( $a, $e = false ) { return (int) $a['ID']; }

/* uses_of() asks these two. The fixtures answer for one attachment id. */
function get_posts( $args ) {
    $want = 0;
    foreach ( $args['meta_query'] as $c ) {
        if ( '_thumbnail_id' === $c['key'] ) { $want = (int) $c['value']; }
    }
    $out = array();
    if ( isset( $GLOBALS['uses'][ $want ] ) ) {
        foreach ( $GLOBALS['uses'][ $want ] as $i => $title ) {
            $pid = 9000 + $want * 10 + $i;
            $GLOBALS['titles'][ $pid ] = $title;
            $out[] = $pid;
        }
    }
    return $out;
}
function get_terms( $args ) {
    $want = 0;
    if ( isset( $args['meta_query'] ) ) {
        foreach ( $args['meta_query'] as $c ) { $want = (int) $c['value']; }
    }
    $out = array();
    if ( isset( $GLOBALS['sused'][ $want ] ) ) {
        foreach ( $GLOBALS['sused'][ $want ] as $name ) {
            $t = new stdClass();
            $t->term_id = 1;
            $t->name    = $name;
            $out[] = $t;
        }
    }
    return $out;
}

class SFAF_Media_Folder {
    public static function prefix() { return 'calendar/'; }
    public static function holds( $id ) { return true; }
    public static function has_any() { return true; }
}
class SFAF_Series {
    const TAXONOMY      = 'uc_series';
    const META_IMAGE_ID = '_sfaf_series_image_id';
    public static function image_url( $t, $s = 'large' ) { return ''; }
}
class WP_Query {
    public $posts = array();
    public $found_posts = 0;
    public $max_num_pages = 1;
    public function __construct( $args = array() ) {
        $GLOBALS['lastq'] = $args;
        /* earliest_for_series() asks this. Answer with whatever the fixture
         * says is tagged, minus anything the meta clauses exclude. */
        $ids = isset( $GLOBALS['tagged'] ) ? $GLOBALS['tagged'] : array();
        sort( $ids );
        foreach ( $ids as $id ) {
            $skip = false;
            foreach ( (array) ( isset( $args['meta_query'] ) ? $args['meta_query'] : array() ) as $c ) {
                if ( ! is_array( $c ) || ! isset( $c['key'] ) ) { continue; }
                if ( SFAF_Media::META_REMOVED === $c['key'] && 'NOT EXISTS' === $c['compare'] ) {
                    if ( '' !== get_post_meta( $id, SFAF_Media::META_REMOVED, true ) ) { $skip = true; }
                }
            }
            if ( ! $skip ) { $this->posts[] = $id; }
        }
        if ( isset( $args['posts_per_page'] ) && $args['posts_per_page'] > 0 ) {
            $this->posts = array_slice( $this->posts, 0, (int) $args['posts_per_page'] );
        }
        $this->found_posts = count( $this->posts );
    }
}

require_once $root . '/includes/class-sfaf-media.php';

$fails = array();
$notes = array();
function check( $ok, $said ) {
    global $fails, $notes;
    if ( $ok ) { $notes[] = $said; } else { $fails[] = $said; }
}

/* ---------------------------------------------------------------------------
 * 1. REMOVING WRITES THE MARKER, AND THE ROW REPORTS IT.
 * ------------------------------------------------------------------------ */
check( true === SFAF_Media::set_removed( 101, true ), 'removing a picture nothing uses succeeds' );
check( '' !== get_post_meta( 101, SFAF_Media::META_REMOVED, true ), 'the marker is written' );
$row = SFAF_Media::row( 101 );
check( true === $row['removed'], 'row() reports it as removed, so the card can offer Put back' );

/* ---------------------------------------------------------------------------
 * 2. pictures() EXCLUDES IT, AND ASKS FOR IT IN ONE VIEW.
 *
 * THIS IS WHAT MAKES REMOVE MEAN ANYTHING. Every screen that shows these goes
 * through this one builder, so excluding here excludes from the grid and from
 * both public pickers at once.
 * ------------------------------------------------------------------------ */
SFAF_Media::pictures( array() );
$mq = $GLOBALS['lastq']['meta_query'];
$found_not_exists = false;
$found_exists     = false;
foreach ( $mq as $c ) {
    if ( ! is_array( $c ) || ! isset( $c['key'] ) ) { continue; }
    if ( SFAF_Media::META_REMOVED === $c['key'] && 'NOT EXISTS' === $c['compare'] ) { $found_not_exists = true; }
}
check( $found_not_exists, 'the default view excludes removed pictures' );

SFAF_Media::pictures( array( 'removed' => true ) );
foreach ( $GLOBALS['lastq']['meta_query'] as $c ) {
    if ( ! is_array( $c ) || ! isset( $c['key'] ) ) { continue; }
    if ( SFAF_Media::META_REMOVED === $c['key'] && 'EXISTS' === $c['compare'] ) { $found_exists = true; }
}
check( $found_exists, 'the Removed view asks for exactly those, so there is a way back' );

/* The folder clause survives both, or remove would quietly widen the library. */
$folder = false;
foreach ( $GLOBALS['lastq']['meta_query'] as $c ) {
    if ( is_array( $c ) && isset( $c['key'] ) && '_wp_attached_file' === $c['key'] ) { $folder = true; }
}
check( $folder, 'the calendar folder clause is still there in the Removed view' );

/* ---------------------------------------------------------------------------
 * 3. IN USE IS REFUSED, AND THE REFUSAL NAMES WHAT.
 * ------------------------------------------------------------------------ */
$GLOBALS['uses'][202]  = array( 'Cycle to Zero Training Ride' );
$GLOBALS['sused'][203] = array( 'Strut Community Events' );

$r = SFAF_Media::set_removed( 202, true );
check( true !== $r, 'a picture an event is using is refused' );
check(
    is_array( $r ) && false !== strpos( implode( ' ', $r ), 'Cycle to Zero Training Ride' ),
    'and the refusal names the event, rather than saying it could not be removed'
);
check( '' === get_post_meta( 202, SFAF_Media::META_REMOVED, true ), 'a refused removal writes nothing' );

$r = SFAF_Media::set_removed( 203, true );
check( true !== $r, "a picture a series has been given is refused" );
check(
    is_array( $r ) && false !== strpos( implode( ' ', $r ), 'Strut Community Events' ),
    'and the refusal names the series'
);

/* ---------------------------------------------------------------------------
 * 4. PUTTING IT BACK IS NEVER REFUSED, and undoes exactly the marker.
 * ------------------------------------------------------------------------ */
$GLOBALS['uses'][101] = array( 'Some event that appeared later' );
check( true === SFAF_Media::set_removed( 101, false ), 'putting one back is never refused' );
check( '' === get_post_meta( 101, SFAF_Media::META_REMOVED, true ), 'and the marker is gone' );
unset( $GLOBALS['uses'][101] );

/* ---------------------------------------------------------------------------
 * 5. A REMOVED PICTURE IS NEVER A SERIES' FALLBACK.
 *
 * A picture the calendar does not offer must not become a programme's picture
 * by a route nobody can see.
 * ------------------------------------------------------------------------ */
$GLOBALS['tagged'] = array( 700, 710 );
check( 700 === SFAF_Media::earliest_for_series( 12 ), 'the earliest tagged picture is the fallback' );
SFAF_Media::set_removed( 700, true );
check( 710 === SFAF_Media::earliest_for_series( 12 ), 'removing it moves the fallback on rather than leaving a gap' );

/* ---------------------------------------------------------------------------
 * 6. NOTHING DELETES ANYTHING.
 *
 * The confirmation on the button promises the file is not deleted, and that is
 * the one promise on this screen nothing can put back if it is broken.
 * ------------------------------------------------------------------------ */
$src = file_get_contents( $root . '/includes/class-sfaf-media.php' );
$src = preg_replace( '#/\*.*?\*/#s', '', $src );
$src = preg_replace( '#^\s*//.*$#m', '', $src );
$deleters = array();
foreach ( array( 'wp_delete_attachment', 'wp_delete_post', 'unlink(', 'wp_delete_file' ) as $bad ) {
    if ( false !== strpos( $src, $bad ) ) { $deleters[] = $bad; }
}
check(
    empty( $deleters ),
    empty( $deleters )
        ? 'SFAF_Media deletes no file and no attachment, which is what the Remove confirmation promises'
        : 'SFAF_Media calls ' . implode( ' and ', $deleters ) . ', and the Remove control promises the file is not deleted'
);

/* ---------------------------------------------------------------------------
 * Report.
 * ------------------------------------------------------------------------ */
echo "Media remove test\n";
foreach ( $notes as $n ) { echo '  . ' . $n . "\n"; }
echo "\n";
if ( $fails ) {
    echo 'FAIL: ' . count( $fails ) . "\n";
    foreach ( $fails as $f ) { echo '  . ' . $f . "\n"; }
    exit( 1 );
}
echo "remove takes a picture out of every list and deletes nothing, and it is\n";
echo "refused, by name, while an event or a series is relying on it.\n";
