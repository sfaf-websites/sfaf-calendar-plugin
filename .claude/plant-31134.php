<?php
/**
 * THE 3.113.4 CHECKS, EACH MADE TO FAIL ON PURPOSE.
 *
 *     php .claude/plant-31134.php [part of a plant name]
 *
 * One fault per plant, written into the real file; the check is run, and every
 * file is put back from memory, not from git. An anchor that is not there, or
 * is there twice, stops the run. Commit first, and never run this while
 * run-all.sh is running.
 *
 * E is the proof that 3.113.4 leaves the embed as 3.113.3 had it. The first
 * plant under 1 is 3.113.3's own lookup, the defect this release fixes.
 */

$root = dirname( __DIR__ );

$plants = array(
    'E: the stylesheet the embed loads changes' => array( 'embed-identity-test.php', 'B: public/css/calendar.css differs from 3.113.3', array(
        array( 'public/css/calendar.css', "/* SFAF Calendar Frontend Styles */\n", "/* SFAF Calendar Frontend Styles */\n.uc-single .uc-flyer { margin: 0; }\n" ),
    ) ),
    'E: the event template changes' => array( 'embed-identity-test.php', 'B: templates/single-uc_event.php differs from 3.113.3', array(
        array( 'templates/single-uc_event.php', 'echo SFAF_Attachments::flyer_html( $post_id );', 'echo SFAF_Attachments::flyer_html( (int) $post_id );' ),
    ) ),
    'E: an embed answer changes' => array( 'embed-identity-test.php', 'A: ', array(
        array( 'includes/class-sfaf-embed.php', "\$response->header( 'Cache-Control', 'public, max-age=' . \$browser_ttl );", "\$response->header( 'Cache-Control', 'public, max-age=' . ( \$browser_ttl + 1 ) );" ),
    ) ),
    '1: back to 3.113.3\'s lookup' => array( 'release-31134-test.php', '1: the live PDF shows its first page', array(
        array( 'includes/class-sfaf-attachments.php', "        \$med = wp_get_attachment_image_src( \$att, 'medium' );\n        if ( empty( \$med[0] ) ) {", "        \$med = image_get_intermediate_size( \$att, 'medium' );\n        \$med = empty( \$med['url'] ) ? false : array( \$med['url'], \$med['width'], \$med['height'] );\n        if ( empty( \$med[0] ) ) {" ),
    ) ),
    '1: a missing preview is never made' => array( 'release-31134-test.php', '1: a missing preview is made the first time the page asks', array(
        array( 'includes/class-sfaf-attachments.php', "        self::ensure_preview( \$att );\n", '' ),
    ) ),
    '1: the attempt is not recorded' => array( 'release-31134-test.php', '1: and is tried once, not on every view', array(
        array( 'includes/class-sfaf-attachments.php', "        update_post_meta( \$att, self::META_PREVIEW_TRIED, time() );\n", '' ),
    ) ),
    '1: a failed attempt is written over the metadata' => array( 'release-31134-test.php', '1: nothing it could not make is written over the metadata', array(
        array( 'includes/class-sfaf-attachments.php', "if ( is_array( \$made ) && ! empty( \$made['sizes']['full'] ) ) {", 'if ( is_array( $made ) ) {' ),
    ) ),
    '1: any file type is sent to be made' => array( 'release-31134-test.php', '1: only a PDF is sent to be made', array(
        array( 'includes/class-sfaf-attachments.php', " || 'application/pdf' !== get_post_mime_type( \$att ) ) {", ' ) {' ),
    ) ),
    '1: a file not on disk is sent to be made' => array( 'release-31134-test.php', '1: a file not on disk keeps the card and is not sent to be made', array(
        array( 'includes/class-sfaf-attachments.php', "if ( ! \$file || ! file_exists( \$file ) ) {", 'if ( false ) {' ),
    ) ),
    '1: a preview that is there is made again' => array( 'release-31134-test.php', '1: a preview that is there is never made again', array(
        array( 'includes/class-sfaf-attachments.php', "if ( ! empty( \$meta['sizes']['full'] ) || 'application/pdf'", "if ( 'application/pdf'" ),
        array( 'includes/class-sfaf-attachments.php', "        if ( get_post_meta( \$att, self::META_PREVIEW_TRIED, true ) ) {\n            return;\n        }\n", '' ),
    ) ),
    '2: the bar is white again' => array( 'release-31134-test.php', '2: the bar is the grey --p-bg', array(
        array( 'public/css/portal.css', "    background: var(--p-bg); box-shadow: none;", "    background: var(--p-panel); box-shadow: none;" ),
    ) ),
    '2: the line is the faint hairline again' => array( 'release-31134-test.php', '2: under a thin Light Gray line', array(
        array( 'public/css/portal.css', "    border: 0; border-top: 1px solid #D1D3D4; border-radius: 0;", "    border: 0; border-top: 1px solid var(--p-border); border-radius: 0;" ),
    ) ),
    '2: Cancel event keeps the pale edge' => array( 'release-31134-test.php', "2: Cancel event's edge is --p-muted", array(
        array( 'public/css/portal.css', ".uc-editor-actions .uc-cancel-open { border-color: var(--p-muted); }\n", '' ),
    ) ),
);

$missed = 0;
$only = isset( $argv[1] ) ? $argv[1] : '';
foreach ( $plants as $name => $p ) {
    if ( '' !== $only && false === strpos( $name, $only ) ) { continue; }
    list( $check, $want, $edits ) = $p;
    $saved = array();
    foreach ( $edits as $e ) {
        list( $file, $from, $to ) = $e;
        $path = $root . '/' . $file;
        if ( ! isset( $saved[ $path ] ) ) { $saved[ $path ] = file_get_contents( $path ); }
        $now = file_get_contents( $path );
        if ( 1 !== substr_count( $now, $from ) ) {
            foreach ( $saved as $sp => $orig ) { file_put_contents( $sp, $orig ); }
            echo "ANCHOR NOT UNIQUE for \"$name\" in $file (" . substr_count( $now, $from ) . ")\n";
            exit( 1 );
        }
        file_put_contents( $path, str_replace( $from, $to, $now ) );
    }
    $cmd = 'php ' . escapeshellarg( __DIR__ . '/' . $check ) . ' --verbose 2>&1';
    $out = shell_exec( 'cd ' . escapeshellarg( $root ) . ' && ' . $cmd );
    foreach ( $saved as $sp => $orig ) { file_put_contents( $sp, $orig ); }
    $caught = ( false !== strpos( (string) $out, $want ) );
    echo ( $caught ? 'caught  ' : 'MISSED  ' ) . $name . "\n";
    if ( ! $caught ) { $missed++; echo '        ' . str_replace( "\n", "\n        ", trim( substr( (string) $out, -1500 ) ) ) . "\n"; }
}
echo $missed ? "$missed PLANT(S) MISSED\n" : "every plant caught.\n";
exit( $missed ? 1 : 0 );
