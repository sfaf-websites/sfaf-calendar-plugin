<?php
/**
 * THE 3.126.0 CHECKS, MADE TO FAIL ON PURPOSE.
 *
 *     php .claude/plant-31260.php
 *
 * Puts back, one at a time, what 3.126.0 changed, and runs the check that must
 * catch it. Every file is put back from memory. Commit first, and never run
 * this while run-all.sh is running.
 */

$root = dirname( __DIR__ );

$plants = array(
    'the form opens on the event\'s language again' => array( 'release-31260-live.php', '4S: desktop: the starting language', array(
        array( 'public/js/calendar.js', "            currentLang = startLang(\$(this).attr('data-uc-lang'));", "            currentLang = (\$(this).attr('data-uc-lang') === 'es') ? 'es' : 'en';" ),
    ) ),
    'the pressed fill is the background shorthand, so it fills the phone button' => array( 'release-31260-live.php', '3P: phone: the fill is not clipped', array(
        array( 'public/js/calendar.js', '[aria-pressed="true"]{background-color:#0E7680;', '[aria-pressed="true"]{background:#0E7680;' ),
    ) ),
    'the series page drops the weekday' => array( 'release-31260-test.php', 'Upcoming: the en event', array(
        array( 'templates/taxonomy-uc_series.php', "sfaf_ap_date( \$d, 'short_weekday', sfaf_event_language( \$eid ) )", "sfaf_ap_date( \$d, 'short' )" ),
    ) ),
    'the series page dates every event in English' => array( 'release-31260-test.php', 'Previously: the es event', array(
        array( 'templates/taxonomy-uc_series.php', "sfaf_ap_date( \$d, 'short_weekday_year', sfaf_event_language( \$eid ) )", "sfaf_ap_date( \$d, 'short_weekday_year' )" ),
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
