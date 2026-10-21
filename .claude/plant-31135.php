<?php
/**
 * THE 3.113.5 CHECKS, EACH MADE TO FAIL ON PURPOSE.
 *
 *     php .claude/plant-31135.php [part of a plant name]
 *
 * One fault per plant, written into the real file; the check is run, and every
 * file is put back from memory, not from git. An anchor that is not there, or
 * is there twice, stops the run. Commit first, and never run this while
 * run-all.sh is running.
 *
 * E is the proof that 3.113.5 leaves the embed as 3.113.4 had it. 2L is the bar
 * as drawn in Chrome, so those plants take a minute each.
 */

$root = dirname( __DIR__ );
$bar  = "    border: 0; border-top: 1px solid #373433; border-radius: 0;\n    background: var(--p-panel); box-shadow: 0 -4px 12px rgba(55, 52, 51, 0.12);";

$plants = array(
    'E: the stylesheet the embed loads changes' => array( 'embed-identity-test.php', 'B: public/css/calendar.css differs from 3.113.4', array(
        array( 'public/css/calendar.css', "/* SFAF Calendar Frontend Styles */\n", "/* SFAF Calendar Frontend Styles */\n.uc-editor-bar { background: #fff; }\n" ),
    ) ),
    'E: an embed answer changes' => array( 'embed-identity-test.php', 'A: ', array(
        array( 'includes/class-sfaf-embed.php', "\$response->header( 'Cache-Control', 'public, max-age=' . \$browser_ttl );", "\$response->header( 'Cache-Control', 'public, max-age=' . ( \$browser_ttl + 1 ) );" ),
    ) ),
    '2: the bar is grey again' => array( 'release-31135-test.php', '2: the bar is white --p-panel', array(
        array( 'public/css/portal.css', $bar, str_replace( 'var(--p-panel)', 'var(--p-bg)', $bar ) ),
    ) ),
    '2: the faint Light Gray rule again' => array( 'release-31135-test.php', '2: under a 1px Dark Gray rule', array(
        array( 'public/css/portal.css', $bar, str_replace( '#373433', '#D1D3D4', $bar ) ),
    ) ),
    '2: no shadow' => array( 'release-31135-test.php', '2: a soft shadow cast upward', array(
        array( 'public/css/portal.css', $bar, str_replace( 'box-shadow: 0 -4px 12px rgba(55, 52, 51, 0.12);', 'box-shadow: none;', $bar ) ),
    ) ),
    '2: the shadow cast downward' => array( 'release-31135-test.php', '2: a soft shadow cast upward', array(
        array( 'public/css/portal.css', $bar, str_replace( '0 -4px 12px', '0 4px 12px', $bar ) ),
    ) ),
    '2: the phone bar inside the gutter' => array( 'release-31135-test.php', '2: edge to edge below 600px', array(
        array( 'public/css/portal.css', '{ margin: 24px -18px 0; padding: 10px 18px; gap: 8px; }', '{ padding: 10px 0; gap: 8px; }' ),
    ) ),
    '2: Cancel event keeps the pale edge' => array( 'release-31135-test.php', "2: Cancel event's edge is --p-muted", array(
        array( 'public/css/portal.css', ".uc-editor-actions .uc-cancel-open { border-color: var(--p-muted); }\n", '' ),
    ) ),
    '2L: the bar is grey again, in Chrome' => array( 'release-31135-live.php', 'not white', array(
        array( 'public/css/portal.css', $bar, str_replace( 'var(--p-panel)', 'var(--p-bg)', $bar ) ),
    ) ),
    '2L: no shadow, in Chrome' => array( 'release-31135-live.php', 'no soft shadow cast upward', array(
        array( 'public/css/portal.css', $bar, str_replace( 'box-shadow: 0 -4px 12px rgba(55, 52, 51, 0.12);', 'box-shadow: none;', $bar ) ),
    ) ),
    '2L: the bar not sticky, in Chrome' => array( 'release-31135-live.php', 'the bar is not sticky', array(
        array( 'public/css/portal.css', "    position: sticky; bottom: 0; z-index: 15;\n    margin: 24px 0 0; padding: 12px 20px;", "    position: static; bottom: 0; z-index: 15;\n    margin: 24px 0 0; padding: 12px 20px;" ),
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
    $args = ( false !== strpos( $check, '-live' ) ) ? ' --run' : ' --verbose';
    $out = shell_exec( 'cd ' . escapeshellarg( $root ) . ' && php ' . escapeshellarg( __DIR__ . '/' . $check ) . $args . ' 2>&1' );
    foreach ( $saved as $sp => $orig ) { file_put_contents( $sp, $orig ); }
    $caught = ( false !== strpos( (string) $out, $want ) );
    echo ( $caught ? 'caught  ' : 'MISSED  ' ) . $name . "\n";
    if ( ! $caught ) { $missed++; echo '        ' . str_replace( "\n", "\n        ", trim( substr( (string) $out, -1500 ) ) ) . "\n"; }
}
echo $missed ? "$missed PLANT(S) MISSED\n" : "every plant caught.\n";
exit( $missed ? 1 : 0 );
