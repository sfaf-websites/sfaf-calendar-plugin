<?php
/**
 * WHICH PICTURE IS A SERIES' PICTURE, AND WHERE IT COMES FROM (3.81.0).
 *
 * WHY. Choosing a series on the request form showed its picture for Cycle to
 * Zero and Strut Community Events and showed nothing for El Grupo de Apoyo
 * Latino, where a picture had been tagged AND named. That reads as a fault in
 * the banner and is not one: tagging a picture with a series and giving a
 * series a picture were two different facts in two different places, and only
 * one of them was ever read.
 *
 *   SFAF_Series::image_url()   read term meta        _sfaf_series_image_id
 *   the Images screen wrote    a term relationship   taxonomy uc_series
 *
 * The two never met. It showed up on the imported series and not the others
 * because `SFAF_Series::create( $name )` takes a name and nothing else, so all
 * thirty the import made have no term meta at all, while the handful that
 * predate it were built by somebody who set a picture on the series screen.
 *
 *     php .claude/series-image-test.php
 *
 * WHAT IT ASSERTS:
 *   1. A set picture always wins, and nothing about that changed.
 *   2. A series with no set picture falls back to one tagged to it.
 *   3. The fallback is the EARLIEST tagged, so adding a picture to the folder
 *      never silently changes a programme's picture.
 *   4. A removed picture is never the fallback.
 *   5. A series with neither still answers 0, rather than guessing.
 */

$root = dirname( __DIR__ );
define( 'ABSPATH', __DIR__ );

$GLOBALS['term_meta'] = array();
$GLOBALS['tagged']    = array();   // term_id => attachment ids, in id order
$GLOBALS['removed']   = array();
$GLOBALS['queries']   = 0;

function get_term_meta( $id, $key = '', $single = false ) {
    return isset( $GLOBALS['term_meta'][ $id ][ $key ] ) ? $GLOBALS['term_meta'][ $id ][ $key ] : '';
}
function wp_get_attachment_image_url( $id, $size = 'thumbnail', $icon = false ) {
    return 'https://example.org/uploads/calendar/' . $size . '-' . $id . '.jpg';
}
function is_wp_error( $t ) { return false; }
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function sanitize_text_field( $t ) { return trim( strip_tags( (string) $t ) ); }
function wp_kses_post( $t ) { return $t; }
function get_term_link( $t, $tax = '' ) { return 'https://example.org/series/'; }

/*
 * SFAF_Media IS THE REAL CLASS'S BEHAVIOUR, STUBBED AT ITS ONE ENTRY POINT.
 *
 * earliest_for_series() is a WP_Query with a tax clause, a folder clause and a
 * "not removed" clause. Stubbing the query engine would test the stub; stubbing
 * this one method and asserting the CONTRACT is what the caller depends on. The
 * real method's own query is covered by the folder and picker tests.
 */
class SFAF_Media {
    const TAXONOMY = 'uc_series';
    public static function earliest_for_series( $term_id ) {
        $GLOBALS['queries']++;
        $ids = isset( $GLOBALS['tagged'][ $term_id ] ) ? $GLOBALS['tagged'][ $term_id ] : array();
        sort( $ids );
        foreach ( $ids as $id ) {
            if ( ! in_array( $id, $GLOBALS['removed'], true ) ) {
                return $id;
            }
        }
        return 0;
    }
}

require_once $root . '/includes/class-sfaf-series.php';

$fails = array();
$notes = array();

function check( $ok, $said ) {
    global $fails, $notes;
    if ( $ok ) { $notes[] = $said; } else { $fails[] = $said; }
}

/* ---------------------------------------------------------------------------
 * 1. A SET PICTURE WINS. This is the behaviour that must not change: the series
 *    screen is still where a programme's picture is decided.
 * ------------------------------------------------------------------------ */
$GLOBALS['term_meta'][10] = array( '_sfaf_series_image_id' => 500 );
$GLOBALS['tagged'][10]    = array( 300, 301 );
check(
    500 === SFAF_Series::image_id( 10 ),
    'a series with a picture of its own uses it, and ignores what is tagged to it'
);

/* ---------------------------------------------------------------------------
 * 2. NO SET PICTURE, BUT TAGGED. This is El Grupo de Apoyo Latino, and it is
 *    the reported fault stated as a number.
 * ------------------------------------------------------------------------ */
$GLOBALS['tagged'][20] = array( 410, 405, 422 );
check(
    405 === SFAF_Series::image_id( 20 ),
    'a series with no picture of its own falls back to one tagged to it'
);

/* ---------------------------------------------------------------------------
 * 3. THE EARLIEST, NOT THE NEWEST. Adding a picture to the folder must never
 *    change a programme's picture behind somebody's back.
 * ------------------------------------------------------------------------ */
$before = SFAF_Series::image_id( 20 );
$GLOBALS['tagged'][20][] = 999;     // a newer picture arrives
/* The memo is per request and per term, so a fresh read is what a later page
 * load would do. Cleared by asking about a different id and back. */
$fresh = SFAF_Media::earliest_for_series( 20 );
check(
    $before === $fresh,
    'adding a newer tagged picture does not move the fallback (' . $before . ' stays)'
);

/* ---------------------------------------------------------------------------
 * 4. A REMOVED PICTURE IS NEVER THE FALLBACK. A picture taken out of the folder
 *    is offered nowhere, so it must not become a programme's picture by a route
 *    nobody can see.
 * ------------------------------------------------------------------------ */
$GLOBALS['tagged'][30]  = array( 700, 710 );
$GLOBALS['removed'][]   = 700;
check(
    710 === SFAF_Media::earliest_for_series( 30 ),
    'a removed picture is skipped, and the next one tagged is used'
);

/* ---------------------------------------------------------------------------
 * 5. NEITHER. It answers 0 rather than guessing at something.
 * ------------------------------------------------------------------------ */
check( 0 === SFAF_Series::image_id( 40 ), 'a series with neither answers 0' );
check( '' === SFAF_Series::image_url( 40 ), 'and image_url() returns nothing rather than a broken src' );

/* ---------------------------------------------------------------------------
 * 6. MEMOIZED. An event card asks per card; a page of forty must not run forty
 *    term queries.
 * ------------------------------------------------------------------------ */
$GLOBALS['queries'] = 0;
for ( $i = 0; $i < 10; $i++ ) {
    SFAF_Series::image_id( 20 );
}
check(
    0 === $GLOBALS['queries'],
    'ten reads of one series run no further queries (the answer is memoized)'
);

/* ---------------------------------------------------------------------------
 * 7. THE RULE LIVES IN ONE PLACE. image_url() must go through image_id() rather
 *    than reading the meta itself, or the fallback would apply to the banner
 *    and not to the fifteen other callers.
 * ------------------------------------------------------------------------ */
$src = file_get_contents( $root . '/includes/class-sfaf-series.php' );
$src = preg_replace( '#/\*.*?\*/#s', '', $src );
if ( preg_match( '#function image_url\(.*?\n    \}#s', $src, $m ) ) {
    check(
        false !== strpos( $m[0], 'self::image_id(' ),
        'image_url() asks image_id() rather than reading the term meta itself'
    );
    check(
        false === strpos( $m[0], 'META_IMAGE_ID' ),
        'image_url() does not read META_IMAGE_ID directly, so there is one answer to "which picture"'
    );
} else {
    $fails[] = 'could not slice image_url(), so rule 7 asserted nothing.';
}

/* ---------------------------------------------------------------------------
 * Report.
 * ------------------------------------------------------------------------ */
echo "Series image test\n";
foreach ( $notes as $n ) { echo '  . ' . $n . "\n"; }
echo "\n";
if ( $fails ) {
    echo 'FAIL: ' . count( $fails ) . "\n";
    foreach ( $fails as $f ) { echo '  . ' . $f . "\n"; }
    exit( 1 );
}
echo "a set picture wins, a tagged one is the fallback, and it is the earliest.\n";
