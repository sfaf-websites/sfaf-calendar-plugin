<?php
/**
 * WHAT HAPPENS WHEN A SERIES POINTS AT AN ATTACHMENT THAT IS GONE (3.90.0).
 *
 *     php .claude/series-image-stale-test.php
 *
 * THE REPORTED SYMPTOM. The Cycle to Zero picture showed on the community
 * request form. Mark REPLACED it. Afterwards it showed nowhere: not the form,
 * not the events, not the series page. Meanwhile a correctly tagged picture sat
 * in the folder unused.
 *
 * THE PREVIOUS INVESTIGATION SAID "nothing is tagged", and that was wrong. A
 * picture IS tagged. So the question is why a tag that exists is not reached,
 * and the answer is an ORDER problem rather than an empty state.
 *
 * SFAF_Series::image_id() reads the explicitly set attachment id FIRST and only
 * falls back to the earliest tagged picture when that meta is ABSENT. It never
 * asks whether the id still resolves. So an id left behind by a deleted
 * attachment is truthy, wins, resolves to nothing, and the fallback never runs:
 * the series is pinned to a picture that does not exist and cannot reach the
 * one that does.
 *
 * TWO READERS HAVE THE SAME BLINDNESS, which is why every surface went dark at
 * once rather than one of them:
 *
 *   SFAF_Series::image_id()        returns the stale id, so image_url() ends at
 *                                  a dead attachment and the tag is skipped.
 *   render_series_edit()           reads the term meta directly and previews
 *                                  `$img_id ? wp_get_attachment_image_url(...)
 *                                  : $img_url`, so a dead id does not even fall
 *                                  through to the pasted URL beside it.
 *
 * This file pins the fix: a stored id that no longer resolves is treated as
 * absent, so the chain continues to the tag.
 */
$root  = dirname( __DIR__ );
$fails = array();

function expect( $label, $got, $want ) {
    global $fails;
    if ( $got !== $want ) {
        $fails[] = sprintf( '%s: got %s, expected %s', $label, var_export( $got, true ), var_export( $want, true ) );
    }
}

define( 'ABSPATH', __DIR__ );

/* ---------------------------------------------------------------------------
 * WordPress, in miniature. The attachment table is a list of ids that EXIST;
 * anything else is a picture somebody deleted.
 * ------------------------------------------------------------------------ */
$GLOBALS['attachments'] = array();   // id => uploads-relative path
$GLOBALS['term_meta']   = array();
$GLOBALS['tagged']      = array();   // term_id => array of attachment ids

function esc_url_raw( $u ) { return $u; }
function esc_attr( $t ) { return (string) $t; }
function esc_html( $t ) { return (string) $t; }
function add_action( $h, $c, $p = 10, $a = 1 ) {}
function add_filter( $h, $c, $p = 10, $a = 1 ) {}
function is_wp_error( $t ) { return false; }
function absint( $n ) { return abs( (int) $n ); }
function sanitize_text_field( $s ) { return trim( (string) $s ); }
function wp_list_pluck( $l, $f ) { return array(); }
function get_option( $n, $d = false ) { return $d; }

function get_term_meta( $id, $key = '', $single = false ) {
    return isset( $GLOBALS['term_meta'][ $id ][ $key ] ) ? $GLOBALS['term_meta'][ $id ][ $key ] : '';
}
function update_term_meta( $id, $key, $value ) { $GLOBALS['term_meta'][ $id ][ $key ] = $value; return true; }
function delete_term_meta( $id, $key ) { unset( $GLOBALS['term_meta'][ $id ][ $key ] ); return true; }

/* THE ONE STUB THAT DECIDES THIS TEST. Core returns false for an attachment
 * that is not there, which is exactly what a deleted picture looks like. */
function wp_get_attachment_image_url( $id, $size = 'full' ) {
    $id = (int) $id;
    return isset( $GLOBALS['attachments'][ $id ] )
        ? 'https://resources.sfaf.org/wp-content/uploads/' . $GLOBALS['attachments'][ $id ]
        : false;
}
function get_post_type( $id = 0 ) { return isset( $GLOBALS['attachments'][ (int) $id ] ) ? 'attachment' : ''; }
function get_post_meta( $id, $key, $single = false ) {
    if ( '_wp_attached_file' === $key ) {
        return isset( $GLOBALS['attachments'][ (int) $id ] ) ? $GLOBALS['attachments'][ (int) $id ] : '';
    }
    return '';
}

/* SFAF_Media::earliest_for_series(), modelled: the lowest tagged attachment id
 * that is in the calendar folder and still exists. */
class SFAF_Media {
    const TAXONOMY = 'uc_series';
    public static function earliest_for_series( $term_id ) {
        $ids = isset( $GLOBALS['tagged'][ $term_id ] ) ? $GLOBALS['tagged'][ $term_id ] : array();
        $ids = array_values( array_filter( $ids, function ( $id ) {
            return isset( $GLOBALS['attachments'][ $id ] )
                && 0 === strpos( $GLOBALS['attachments'][ $id ], 'calendar/' );
        } ) );
        sort( $ids );
        return empty( $ids ) ? 0 : (int) $ids[0];
    }
}
class SFAF_Media_Folder {
    const FOLDER = 'calendar';
    public static function prefix() { return 'calendar/'; }
    public static function holds( $id ) {
        return isset( $GLOBALS['attachments'][ (int) $id ] )
            && 0 === strpos( $GLOBALS['attachments'][ (int) $id ], 'calendar/' );
    }
}

require_once $root . '/includes/class-sfaf-series.php';

/* ===========================================================================
 * THE FIXTURE: Cycle to Zero, as Mark left it.
 * ======================================================================== */
$CTZ = 42;

// The picture that WAS set on the series, and has since been deleted.
$GLOBALS['term_meta'][ $CTZ ][ SFAF_Series::META_IMAGE_ID ] = 901;
// 901 is deliberately NOT in $attachments: that is what "replaced" means.

// The replacement, uploaded into the folder and tagged to the series.
$GLOBALS['attachments'][ 950 ] = 'calendar/cycle-to-zero-2026.jpg';
$GLOBALS['tagged'][ $CTZ ]     = array( 950 );

echo "The state Mark is in\n";
expect( 'the series still points at the deleted picture', (int) get_term_meta( $CTZ, SFAF_Series::META_IMAGE_ID, true ), 901 );
expect( 'the old attachment is gone', wp_get_attachment_image_url( 901 ), false );
expect( 'the replacement exists and is tagged', SFAF_Media::earliest_for_series( $CTZ ), 950 );

/* ===========================================================================
 * THE FAULT, AND THE FIX THAT PINS IT.
 *
 * A STORED ID THAT NO LONGER RESOLVES IS TREATED AS ABSENT. The alternative was
 * to CLEAR the stale meta on read, and that is rejected: a read that writes
 * turns opening a page into an edit, it would run on the public calendar for
 * every visitor, and it destroys the only record of what the series was pinned
 * to before somebody can look at it. Falling through costs one existence check
 * and leaves the evidence in place.
 * ======================================================================== */
echo "What the readers do with it\n";

expect(
    'image_id() falls through the dead id to the tagged picture',
    SFAF_Series::image_id( $CTZ ),
    950
);
expect(
    'so image_url() resolves, which is what every surface asks',
    SFAF_Series::image_url( $CTZ ),
    'https://resources.sfaf.org/wp-content/uploads/calendar/cycle-to-zero-2026.jpg'
);

/* A LIVE SETTING STILL WINS, ALWAYS. The fallback answers the case that used to
 * answer nothing; it must not start overriding a picture somebody chose. */
echo "A picture that is still there still wins\n";
$GLOBALS['attachments'][ 901 ] = 'calendar/the-original.jpg';
expect(
    'the explicitly set picture beats the tagged one',
    SFAF_Series::image_id( $CTZ ),
    901
);
unset( $GLOBALS['attachments'][ 901 ] );

/* A FRESH TERM PER SCENARIO FROM HERE, because image_id() MEMOIZES its fallback
 * for the life of the request. That memo is deliberate: a page of forty cards
 * asks the same series the same question forty times, and nothing changes a tag
 * mid-request. Reusing one term id here would be the test fighting a decision
 * rather than checking one. */

/* WITH NOTHING TAGGED, A PASTED URL IS STILL THE LAST RESORT. */
echo "The pasted URL is still the last resort\n";
$TYPED = 43;
$GLOBALS['term_meta'][ $TYPED ][ SFAF_Series::META_IMAGE_ID ]  = 902;   // also gone
$GLOBALS['term_meta'][ $TYPED ][ SFAF_Series::META_IMAGE_URL ] = 'https://example.org/typed.jpg';
expect( 'a dead id and no tag falls to the pasted URL', SFAF_Series::image_url( $TYPED ), 'https://example.org/typed.jpg' );

/* AND NOTHING AT ALL IS STILL NOTHING, rather than an error. */
$EMPTY = 44;
expect( 'a series with nothing set and nothing tagged resolves to nothing', SFAF_Series::image_url( $EMPTY ), '' );

/* ---- The second reader, asserted against the source. ----
 *
 * render_series_edit() reads the term meta directly rather than through
 * image_id(), and its preview was `$img_id ? url($img_id) : $img_url`, so a
 * dead id did not even fall through to the pasted URL beside it. It has to ask
 * the same question the chain asks or the screen disagrees with the calendar. */
$portal = '';
foreach ( token_get_all( file_get_contents( $root . '/includes/class-sfaf-portal.php' ) ) as $tok ) {
    if ( is_array( $tok ) ) {
        if ( T_COMMENT === $tok[0] || T_DOC_COMMENT === $tok[0] ) { continue; }
        $portal .= $tok[1];
    } else {
        $portal .= $tok;
    }
}
if ( preg_match( '#\$preview\s*=\s*\$img_id\s*\?\s*wp_get_attachment_image_url\(\s*\$img_id#', $portal ) ) {
    $fails[] = 'the series screen still previews a raw stored id, so a deleted picture shows as nothing and hides the pasted URL beside it';
}
if ( false === strpos( $portal, 'SFAF_Series::image_url( $term_id )' ) ) {
    $fails[] = 'the series screen does not resolve its preview through the same chain the calendar uses';
}

/* ------------------------------------------------------------------------ */
echo "\nA series pointing at a picture that is gone\n";
echo str_repeat( '=', 72 ) . "\n";
echo "checked by running:   that a stored id for a DELETED attachment no longer wins and is\n";
echo "                      treated as absent, so the chain reaches the tagged picture; that a\n";
echo "                      picture still present still beats the tag; that a pasted URL is\n";
echo "                      still the last resort; and that nothing set and nothing tagged is\n";
echo "                      still nothing rather than an error\n";
echo "checked by reading:   that the series screen resolves its preview through the same chain\n";
echo "                      rather than previewing a raw id, which is the second reader that\n";
echo "                      went dark for the same reason\n\n";

if ( empty( $fails ) ) {
    echo "a deleted picture no longer pins a series to nothing.\n";
    exit( 0 );
}
echo count( $fails ) . " problem(s):\n";
foreach ( $fails as $f ) { echo '  - ' . $f . "\n"; }
exit( 1 );
