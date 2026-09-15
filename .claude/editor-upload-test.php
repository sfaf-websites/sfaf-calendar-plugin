<?php
/**
 * WHERE DOES AN IMAGE UPLOADED FROM THE EVENT EDITOR GO, AND WHY CAN THE PICKER
 * NOT SEE IT? (3.87.0)
 *
 *     php .claude/editor-upload-test.php
 *
 * WHAT WAS REPORTED. An image uploaded while editing an event "goes nowhere":
 * it cannot be used on that event. The obvious explanation, and the one the
 * brief proposed, is that it lands in the date directory because the upload
 * went through wp.media rather than the plugin's own form. That is NOT what
 * happens, and this file is here because the difference decides the fix.
 *
 * THE TWO REQUESTS, MODELLED AS wp.media ACTUALLY SENDS THEM.
 *
 *   1. THE UPLOAD. plupload POSTs to async-upload.php with the multipart params
 *      portal.js put on wp.Uploader.defaults, so the folder flag arrives at the
 *      TOP level of $_REQUEST. upload_dir fires, asked_for() is true, and the
 *      file lands in uploads/calendar/. It is in the right place.
 *
 *   2. THE LIBRARY REFRESH IMMEDIATELY AFTER. wp.media re-queries with the
 *      frame's `library` arguments nested under `query`, and for an event in a
 *      series that includes uc_series. restrict_query() then adds BOTH the path
 *      clause AND a uc_series tax_query. A picture uploaded seconds ago carries
 *      no series tag, so it is excluded by the second clause.
 *
 * SO THE FILE IS FINE AND THE PICKER IS ASKING A QUESTION THE NEW FILE CANNOT
 * ANSWER YET. That is why "upload it again" never helps and why the same
 * picture IS on the Images screen, which does not narrow by series.
 */
$root  = dirname( __DIR__ );
$fails = array();

function expect( $label, $got, $want ) {
    global $fails;
    if ( $got !== $want ) {
        $fails[] = sprintf( '%s: got %s, expected %s', $label, var_export( $got, true ), var_export( $want, true ) );
    }
}

/* ---------------------------------------------------------------------------
 * WordPress, in miniature. Only what SFAF_Media_Folder touches.
 * ------------------------------------------------------------------------ */
define( 'ABSPATH', __DIR__ );

function esc_attr( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function add_filter( $h, $c, $p = 10, $a = 1 ) {}
function add_action( $h, $c, $p = 10, $a = 1 ) {}
function wp_get_current_user() { return (object) array( 'ID' => 1 ); }
function get_post_meta( $id, $k, $single = false ) {
    return isset( $GLOBALS['meta'][ $id ][ $k ] ) ? $GLOBALS['meta'][ $id ][ $k ] : '';
}
function get_option( $n, $d = false ) { return $d; }
function wp_list_pluck( $l, $f ) { return array(); }

require_once $root . '/includes/class-sfaf-media-folder.php';

$ref = function ( $name ) {
    $m = new ReflectionMethod( 'SFAF_Media_Folder', $name );
    $m->setAccessible( true );
    return $m;
};

/* ===========================================================================
 * 1. THE UPLOAD REQUEST. The file lands in the folder.
 * ======================================================================== */
echo "The upload request\n";

$_REQUEST = array(
    'action'                    => 'upload-attachment',
    SFAF_Media_Folder::FLAG     => '1',   // from wp.Uploader.defaults.multipart_params
);

expect( 'the folder flag is recognised on an upload', $ref( 'asked_for' )->invoke( null ), true );

$dirs = SFAF_Media_Folder::upload_to_folder( array(
    'basedir' => '/srv/uploads',
    'baseurl' => 'https://resources.sfaf.org/wp-content/uploads',
    'subdir'  => '/2026/09',
    'path'    => '/srv/uploads/2026/09',
    'url'     => 'https://resources.sfaf.org/wp-content/uploads/2026/09',
) );
expect( 'the upload is routed into the calendar folder', $dirs['subdir'], '/calendar' );
expect( 'and not into the date directory', $dirs['path'], '/srv/uploads/calendar' );

/* AND THE STORED PATH THEN SATISFIES THE FOLDER RULE, which is what every
 * display surface and the Images screen read. */
expect( 'the stored path is inside the folder', SFAF_Media_Folder::path_is_inside( 'calendar/strut-by-sfaf.jpg' ), true );

/* ===========================================================================
 * 2. THE LIBRARY REFRESH. This is where it disappears.
 * ======================================================================== */
echo "The library refresh that follows it\n";

/* An event with NO series: the picker asks for the folder and nothing else. */
$_REQUEST = array(
    'action' => 'query-attachments',
    'query'  => array( 'type' => 'image', SFAF_Media_Folder::FLAG => '1' ),
);
$args = SFAF_Media_Folder::restrict_query( array() );
expect( 'the folder clause is applied', isset( $args['meta_query'] ), true );
expect( 'and no series clause is added when none was asked for', isset( $args['tax_query'] ), false );

/* An event IN A SERIES: the picker also asks for that series, which is 3.74.0
 * working exactly as designed and is what the new picture cannot answer. */
$_REQUEST = array(
    'action' => 'query-attachments',
    'query'  => array( 'type' => 'image', SFAF_Media_Folder::FLAG => '1', 'uc_series' => '42' ),
);
$args = SFAF_Media_Folder::restrict_query( array() );
expect( 'the series clause IS added when the event has one', isset( $args['tax_query'] ), true );

$tax = $args['tax_query'];
$found_series = false;
array_walk_recursive( $tax, function ( $v, $k ) use ( &$found_series ) {
    if ( 'taxonomy' === $k && 'uc_series' === $v ) { $found_series = true; }
} );
expect( 'and it narrows on uc_series', $found_series, true );

/*
 * THE WHOLE FAULT IN ONE SENTENCE: a picture that has just been uploaded has no
 * series term, so a query narrowed to uc_series cannot return it, however
 * correctly it was filed.
 */

/* ===========================================================================
 * 3. THE FIX: THE UPLOAD CARRIES THE SERIES AND IS TAGGED WITH IT.
 *
 * WHY TAGGING RATHER THAN WIDENING THE PICKER. Widening after an upload would
 * answer a different question from the one asked and would undo 3.80.0, which
 * hides by series on purpose. The picture was uploaded WHILE EDITING AN EVENT
 * IN THAT SERIES, so the series is a fact about it rather than a guess, and
 * tagging it makes it visible to the picker that is open, to the Images screen,
 * and to every other event in the same series.
 * ======================================================================== */
echo "The fix: an upload made for an event is tagged with its series\n";

/* The series rides the SAME multipart params the flag does, so it arrives at
 * the top level on an upload rather than nested under `query`. */
$_REQUEST = array(
    'action'                => 'upload-attachment',
    SFAF_Media_Folder::FLAG => '1',
    'uc_series'             => '42',
);
expect( 'the series is read off an upload request', $ref( 'asked_series' )->invoke( null ), 42 );

/* AND IT IS STILL READ OFF A LIBRARY QUERY, nested, which is the older route
 * and must not have been broken by adding the other one. */
$_REQUEST = array(
    'action' => 'query-attachments',
    'query'  => array( 'uc_series' => '7' ),
);
expect( 'and off a library query, nested', $ref( 'asked_series' )->invoke( null ), 7 );

/* NOTHING IS TAGGED WITHOUT BOTH. An upload from somewhere else in WordPress
 * must not be filed into a calendar series because a stale request key was
 * lying around. */
$_REQUEST = array( 'action' => 'upload-attachment', 'uc_series' => '42' );
expect( 'no folder flag means this is not ours to tag', $ref( 'asked_for' )->invoke( null ), false );

/* ---- The wiring, asserted against the source. ---- */
$js  = file_get_contents( $root . '/public/js/portal.js' );
$php = file_get_contents( $root . '/includes/class-sfaf-media-folder.php' );

/* Either spelling, because `.uc_series` and `['uc_series']` are the same thing
 * and a check that insists on one is checking how it was typed. */
/* THE ASSIGNMENT, NOT THE IDENTIFIER. The first draft matched the name alone,
 * so deleting the line that SETS it still passed: the `delete` statement two
 * lines below mentions the same property. Caught by planting it. */
if ( ! preg_match( '#multipart_params(?:\.uc_series|\[\s*[\x27"]uc_series[\x27"]\s*\])\s*=#', $js ) ) {
    $fails[] = 'the uploader does not send the series, so an upload cannot be tagged with it';
}
/* AND IT IS CLEARED WHEN THERE IS NO SERIES. These defaults are global and
 * outlive the frame, so a stale id would file the next upload from any picker
 * on the page into a series nobody chose. */
if ( ! preg_match( '#delete\s+wp\.Uploader\.defaults\.multipart_params\.uc_series#', $js ) ) {
    $fails[] = 'the series is never cleared from the global uploader defaults, so a later upload can inherit it';
}
if ( false === strpos( $php, 'add_attachment' ) ) {
    $fails[] = 'nothing hooks the upload to tag it, so a picture uploaded for an event in a series is still invisible to that picker';
}

/* ------------------------------------------------------------------------ */
echo "\nAn image uploaded from the event editor\n";
echo str_repeat( '=', 72 ) . "\n";
echo "checked: the upload IS routed into the calendar folder and its stored path satisfies\n";
echo "         the folder rule, so it was never lost and never in a date directory; that\n";
echo "         the library refresh straight afterwards narrows on uc_series when the event\n";
echo "         has one, which a picture uploaded seconds ago cannot answer; and that the\n";
echo "         upload now carries the series and is tagged with it, on the same route the\n";
echo "         flag takes, with neither half acting without the other\n\n";

if ( empty( $fails ) ) {
    echo "an image uploaded for an event is usable on that event.\n";
    exit( 0 );
}
echo count( $fails ) . " problem(s):\n";
foreach ( $fails as $f ) { echo '  - ' . $f . "\n"; }
exit( 1 );
