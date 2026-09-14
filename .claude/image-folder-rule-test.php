<?php
/**
 * A PICTURE OUTSIDE THE CALENDAR FOLDER REACHES NO SURFACE (3.83.0).
 *
 * Mark's rule, in full: a picture that is not in the designated calendar folder
 * is not used. It is enforced at RESOLUTION rather than by clearing what is
 * stored, so the 270 references the 2026-09-03 import left simply stop
 * mattering, no file is touched, and the next import cannot undo it.
 *
 *     php .claude/image-folder-rule-test.php
 *
 * TWO HALVES, AND THE SECOND IS THE ONE THAT MATTERS.
 *
 *   BEHAVIOUR. Drive the chain against a stubbed library and assert the rung it
 *   lands on for every combination: in the folder, outside it, a local URL
 *   inside, a local URL outside, an external URL, a series picture behind it,
 *   nothing at all.
 *
 *   COVERAGE. Sweep the plugin for any OTHER way a picture could be resolved.
 *   One function holding the rule is worth nothing if a renderer calls
 *   has_post_thumbnail() and draws whatever it finds, which is exactly what
 *   sfaf_event_thumbnail() did until this release: it short-circuited on the
 *   featured image before the chain was ever consulted.
 *
 * THE PLANT THE BRIEF ASKED FOR IS THE COVERAGE HALF. Put a direct
 * has_post_thumbnail() back into any renderer and this fails, because that is
 * the shape of an out-of-folder picture reaching a surface.
 */

$root = dirname( __DIR__ );
define( 'ABSPATH', __DIR__ );

$GLOBALS['files']  = array();   // attachment id => _wp_attached_file
$GLOBALS['pm']     = array();   // post id => meta
$GLOBALS['series'] = array();   // post id => series picture URL

function get_post_meta( $id, $key = '', $single = false ) {
    return isset( $GLOBALS['pm'][ $id ][ $key ] ) ? $GLOBALS['pm'][ $id ][ $key ] : '';
}
function get_post_thumbnail_id( $id = null ) {
    return isset( $GLOBALS['pm'][ $id ]['_thumbnail_id'] ) ? (int) $GLOBALS['pm'][ $id ]['_thumbnail_id'] : 0;
}
function has_post_thumbnail( $id = null ) { return (bool) get_post_thumbnail_id( $id ); }
function wp_get_attachment_image_url( $id, $size = 'thumbnail', $icon = false ) {
    return isset( $GLOBALS['files'][ $id ] )
        ? 'https://resources.sfaf.org/wp-content/uploads/' . $GLOBALS['files'][ $id ]
        : false;
}
function get_the_title( $id = 0 ) { return 'An event'; }
function esc_url( $u ) { return $u; }
function esc_attr( $t ) { return $t; }
function esc_html( $t ) { return $t; }
function is_wp_error( $t ) { return false; }

class SFAF_Media_Folder {
    const FOLDER = 'calendar';
    public static function prefix() { return self::FOLDER . '/'; }
    public static function holds( $id ) {
        $f = isset( $GLOBALS['files'][ $id ] ) ? $GLOBALS['files'][ $id ] : '';
        return 0 === strpos( $f, self::prefix() );
    }
}
class SFAF_Series {
    public static function id_for_event( $id ) { return isset( $GLOBALS['series'][ $id ] ) ? 1 : 0; }
}
function sfaf_series_image_url( $term_id ) {
    foreach ( $GLOBALS['series'] as $url ) { return $term_id ? $url : ''; }
    return '';
}

/* The two functions under test, sliced out rather than loading the whole file:
 * sfaf-template-functions.php pulls in a great deal that has nothing to do with
 * this question, and a stub for each of those would be a bigger surface than
 * the one being tested. */
$src = file_get_contents( $root . '/includes/sfaf-template-functions.php' );
foreach ( array( 'sfaf_event_own_image_url', 'sfaf_event_has_own_image', 'sfaf_event_image_url', 'sfaf_event_image_source' ) as $fn ) {
    if ( ! preg_match( '/\nfunction ' . $fn . '\(.*?\n\}/s', $src, $m ) ) {
        echo "FAIL: could not slice $fn() out of sfaf-template-functions.php\n";
        exit( 1 );
    }
    eval( $m[0] );
}

$fails = array();
$notes = array();
function check( $ok, $said ) {
    global $fails, $notes;
    if ( $ok ) { $notes[] = $said; } else { $fails[] = $said; }
}

function scenario( $id, $file_for_thumb, $own_url, $series_url ) {
    $GLOBALS['pm'][ $id ]    = array();
    $GLOBALS['series']       = array();
    if ( null !== $file_for_thumb ) {
        $GLOBALS['files'][ 900 + $id ] = $file_for_thumb;
        $GLOBALS['pm'][ $id ]['_thumbnail_id'] = 900 + $id;
    }
    if ( '' !== $own_url ) { $GLOBALS['pm'][ $id ]['_uc_image_url'] = $own_url; }
    if ( '' !== $series_url ) { $GLOBALS['series'][ $id ] = $series_url; }
}

/* ---------------------------------------------------------------------------
 * BEHAVIOUR
 * ------------------------------------------------------------------------ */
scenario( 1, 'calendar/strut-clinic.jpg', '', '' );
check(
    false !== strpos( sfaf_event_image_url( 1 ), 'calendar/strut-clinic.jpg' ),
    'a featured image INSIDE the folder is used'
);
check( 'event' === sfaf_event_image_source( 1 ), 'and the editor calls it the event\'s own' );

scenario( 2, 'programs/Damn-Daddy.jpg', '', 'https://x/series.jpg' );
check(
    'https://x/series.jpg' === sfaf_event_image_url( 2 ),
    'a featured image OUTSIDE the folder is ignored and the series picture is used'
);
check(
    'series' === sfaf_event_image_source( 2 ),
    'and the editor says series rather than claiming the event has its own'
);

scenario( 3, 'programs/Damn-Daddy.jpg', '', '' );
check( '' === sfaf_event_image_url( 3 ), 'with no series behind it, nothing is resolved and the placeholder shows' );
check( 'none' === sfaf_event_image_source( 3 ), 'and the editor says none' );

scenario( 4, null, 'https://resources.sfaf.org/wp-content/uploads/calendar/hand.jpg', '' );
check(
    false !== strpos( sfaf_event_image_url( 4 ), 'calendar/hand.jpg' ),
    'a local image URL pointing INTO the folder is used'
);

scenario( 5, null, 'https://resources.sfaf.org/wp-content/uploads/programs/hand.jpg', 'https://x/series.jpg' );
check(
    'https://x/series.jpg' === sfaf_event_image_url( 5 ),
    'a local image URL pointing OUTSIDE the folder is ignored'
);

/* AN ADDRESS ON ANOTHER SITE IS NOT AN ATTACHMENT AND IS NOT WHAT THE RULE IS
 * ABOUT. It is the rung a fetch maintains, and taking it away would blank every
 * imported campaign, which is a different decision nobody has made. */
scenario( 6, null, 'https://eventbrite.com/promo.jpg', 'https://x/series.jpg' );
check(
    'https://eventbrite.com/promo.jpg' === sfaf_event_image_url( 6 ),
    'an address on another site is left alone: the folder rule is about this site\'s uploads'
);

/* The folder wins over a series picture, which is the order that must not
 * invert: a picture somebody chose from the folder beats the programme's. */
scenario( 7, 'calendar/chosen.jpg', '', 'https://x/series.jpg' );
check(
    false !== strpos( sfaf_event_image_url( 7 ), 'calendar/chosen.jpg' ),
    'a folder picture still beats the series picture'
);

/* ---------------------------------------------------------------------------
 * COVERAGE: no surface resolves a picture any other way.
 *
 * THE RULE IS ONLY WORTH THE PLACES THAT ASK IT. A renderer calling
 * has_post_thumbnail() and drawing what it finds is an out-of-folder picture on
 * a surface, which is the whole fault.
 * ------------------------------------------------------------------------ */
$allowed = array(
    /* The rule itself, and the two functions that are the rule. */
    'includes/sfaf-template-functions.php' => array( 'sfaf_event_own_image_url', 'sfaf_event_has_own_image' ),
);

$banned = array( 'has_post_thumbnail', 'get_the_post_thumbnail_url', 'get_the_post_thumbnail' );
$hits   = array();

foreach ( array_merge(
    glob( $root . '/includes/*.php' ),
    glob( $root . '/templates/*.php' ),
    glob( $root . '/admin/*.php' )
) as $file ) {
    $rel  = str_replace( $root . '/', '', str_replace( '\\', '/', $file ) );
    $code = file_get_contents( $file );
    $code = preg_replace( '#/\*.*?\*/#s', '', $code );
    $code = preg_replace( '#^\s*//.*$#m', '', $code );

    foreach ( $banned as $fn ) {
        if ( ! preg_match_all( '/\b' . $fn . '\s*\(/', $code, $m, PREG_OFFSET_CAPTURE ) ) {
            continue;
        }
        foreach ( $m[0] as $one ) {
            /* Which function is this call inside? The nearest declaration
             * above it is close enough here and is what the allow-list names. */
            $before = substr( $code, 0, $one[1] );
            $owner  = preg_match_all( '/\nfunction\s+([A-Za-z0-9_]+)\s*\(/', $before, $fm )
                ? end( $fm[1] ) : '(top level)';
            if ( isset( $allowed[ $rel ] ) && in_array( $owner, $allowed[ $rel ], true ) ) {
                continue;
            }
            $hits[] = $rel . '  ' . $owner . '()  calls ' . $fn . '()';
        }
    }
}

check(
    empty( $hits ),
    empty( $hits )
        ? 'no surface reads the featured image directly: every one goes through the rule'
        : count( $hits ) . ' place(s) read the featured image without the folder rule: ' . implode( ' | ', $hits )
);

/* And the chain itself has no short cut back in.
 *
 * COMMENTS OFF FIRST. The note inside that function explains that the
 * has_post_thumbnail() short cut was removed, so a sweep of the raw file finds
 * the name it is looking for inside the sentence saying it is gone. That is the
 * fifth time a checker on this project has read its own explanation as
 * evidence, and the first four are in PROJECT.md. */
$src_code = preg_replace( '#/\*.*?\*/#s', '', $src );
$src_code = preg_replace( '#^\s*//.*$#m', '', $src_code );
if ( preg_match( '/\nfunction sfaf_event_thumbnail\(.*?\n\}/s', $src_code, $m ) ) {
    $short = ( false !== strpos( $m[0], 'has_post_thumbnail' ) );
    check(
        ! $short,
        $short
            ? 'sfaf_event_thumbnail() short-circuits on the featured image again, which is the one path the rule cannot reach'
            : 'sfaf_event_thumbnail() has no featured-image short cut, so the chain decides'
    );
} else {
    check( false, 'could not slice sfaf_event_thumbnail(), so its short cut was not asserted about' );
}

/* ---------------------------------------------------------------------------
 * Report.
 * ------------------------------------------------------------------------ */
echo "Image folder rule\n";
foreach ( $notes as $n ) { echo '  . ' . $n . "\n"; }
echo "\n";
if ( $fails ) {
    echo 'FAIL: ' . count( $fails ) . "\n";
    foreach ( $fails as $f ) { echo '  . ' . $f . "\n"; }
    exit( 1 );
}
echo "a picture outside the calendar folder is resolved by nothing and drawn by\n";
echo "no surface, and the chain falls through to the series picture as it should.\n";
