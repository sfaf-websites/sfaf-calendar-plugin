<?php
/**
 * THE 3.119.0 CHECKS, EACH MADE TO FAIL ON PURPOSE.
 *
 *     php .claude/plant-31190.php [part of a plant name]
 *
 * One fault per plant, written into the real file; the check is run, and every
 * file is put back from memory, not from git. An anchor that is not there, or
 * is there twice, stops the run. Commit first, and never run this while
 * run-all.sh is running.
 */

$root = dirname( __DIR__ );

$plants = array(
    'B: the marker is ignored' => array( 'release-31190-test.php', 'B: a form that did not draw them changes nothing', array(
        array( 'includes/class-sfaf-series.php', "        if ( ! (int) \$term_id || empty( \$post['uc_series_event_defaults_present'] ) ) {\n            return;\n        }\n        \$txt = function", "        if ( ! (int) \$term_id ) {\n            return;\n        }\n        \$txt = function" ),
    ) ),
    'C: the last event\'s times win' => array( 'release-31190-test.php', "C: the series' times win over the last event's", array(
        array( 'includes/class-sfaf-series.php', "        if ( '' === \$out['start_time'] && '' === \$out['end_time'] ) {\n            \$out['start_time'] = (string) get_post_meta( \$id, '_uc_start_time', true );", "        if ( true ) {\n            \$out['start_time'] = (string) get_post_meta( \$id, '_uc_start_time', true );" ),
    ) ),
    'D: a form\'s cost is overwritten' => array( 'release-31190-test.php', 'D: a cost the form gave is kept', array(
        array( 'includes/class-sfaf-series.php', "            if ( '' !== \$d[ \$k ] && \$empty( \$key ) ) {", "            if ( '' !== \$d[ \$k ] ) {" ),
    ) ),
    'D: a form\'s emails are overwritten' => array( 'release-31190-test.php', 'D: emails a form set are kept', array(
        array( 'includes/class-sfaf-series.php', "        if ( null !== \$d['notify_off'] && '' === get_post_meta( \$event_id, SFAF_Notifications::OFF_META, true ) ) {", "        if ( null !== \$d['notify_off'] ) {" ),
    ) ),
    'D: the community form does not apply them' => array( 'release-31190-test.php', 'D: includes/class-sfaf-submit.php applies them', array(
        array( 'includes/class-sfaf-submit.php', "        SFAF_Series::apply_event_defaults( \$event_id, (int) \$series->term_id );\n", "" ),
    ) ),
    'L: Fill these in skips the display' => array( 'release-31190-live.php', 'Add to calendar not off as the series says', array(
        array( 'public/js/portal.js', "                        var b = form.querySelector('input[type=\"checkbox\"][name=\"' + k + '\"]');\n                        var want = d.display[k] === '1';", "                        var b = null;\n                        var want = d.display[k] === '1';" ),
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
