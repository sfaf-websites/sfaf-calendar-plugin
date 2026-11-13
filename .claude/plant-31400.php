<?php
/**
 * THE 3.140.0 CHECKS, MADE TO FAIL ON PURPOSE.
 *
 *     php .claude/plant-31400.php [part of a plant's name]
 *
 * Plants, one at a time, a fault in the change prompt, its buttons, the
 * change email's meeting link, Text registrants' waitlist, Series &
 * Categories or the series People fold, and runs the check that must catch
 * it. Every file is put back from memory. Commit first, and never run this
 * while run-all.sh is running.
 */

$root = dirname( __DIR__ );
$P = 'includes/class-sfaf-portal.php';
$N = 'includes/class-sfaf-notifications.php';
$X = 'includes/class-sfaf-texts.php';
$J = 'public/js/portal.js';
$T = 'release-31400-test.php';
$L = 'release-31400-live.php';

$plants = array(
    'the prompt reads start_time again (the 3.98.0 to 3.139.0 fault)' => array( $L, 'PLANT T:', array(
        array( $J, "            Time: apTimeRange(timeNow(form, 'start_time'), timeNow(form, 'end_time')),", "            Time: apTimeRange(val('start_time'), val('end_time'))," ),
    ) ),
    'the prompt does not see the link' => array( $L, 'PLANT L:', array(
        array( $J, "            Location: locationNow(form),\n            'Meeting link': meetingLink(form, false)", "            Location: locationNow(form)" ),
    ) ),
    'Back to the form a button again' => array( $L, 'PLANT B:', array(
        array( $J, "                cancelLabel: 'Back to the form',\n                /* A way back", "                cancelLabel: 'Back to the form', cancelQuiet: false,\n                /* A way back" ),
        array( $J, "                cancelQuiet: true\n            }, function (answer) {", "            }, function (answer) {" ),
    ) ),
    'no meeting link fact' => array( $T, 'FAIL  M: there is a Meeting link fact', array(
        array( $P, "            'Meeting link' => '' !== \$link ? substr( hash( 'sha256', \$link ), 0, 16 ) : '',\n", "" ),
    ) ),
    'the link to everybody' => array( $T, 'FAIL  E: reminder only', array(
        array( $N, "                        'html' => SFAF_Online::joining_html( \$event_id, 'confirmation', \$format, \$lang ),", "                        'html' => SFAF_Messages::joining_html( SFAF_Online::link( \$event_id ), \$lang )," ),
    ) ),
    'the waitlist always texted' => array( $T, 'FAIL  W: without the tick', array(
        array( $X, "        foreach ( self::plan( \$event_id, \$waitlist ) as \$p ) {", "        foreach ( self::plan( \$event_id, true ) as \$p ) {" ),
    ) ),
    'no waitlist tick' => array( $T, 'FAIL  W: Include the waitlist is on the form', array(
        array( $P, '<input type="checkbox" name="tr_waitlist" value="1" data-uc-rm-waitlist />', '<input type="checkbox" name="tr_waitlist_off" value="1" />' ),
    ) ),
    'one tab only' => array( $L, 'PLANT S:', array(
        array( $P, "        \$tab = ( isset( \$_GET['tab'] ) && 'categories' === \$_GET['tab'] ) ? 'categories' : 'series';", "        \$tab = 'series';" ),
    ) ),
    'the search does nothing' => array( $L, 'PLANT S:', array(
        array( $P, 'placeholder="Search series" autocomplete="off" data-uc-filter />', 'placeholder="Search series" autocomplete="off" />' ),
    ) ),
    'the word icon back' => array( $L, 'PLANT C:', array(
        array( $P, "( isset( \$icons[ \$icon ] ) ? strtolower( \$icons[ \$icon ] ) : \$icon ) . ' · '", "( isset( \$icons[ \$icon ] ) ? strtolower( \$icons[ \$icon ] ) : \$icon ) . ' icon · '" ),
    ) ),
    'People open' => array( $L, 'PLANT P:', array(
        array( $P, '<details class="uc-rcpt-more uc-people-fold" data-uc-people-fold>', '<details class="uc-rcpt-more uc-people-fold" data-uc-people-fold open>' ),
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
    $args = ( false !== strpos( $check, '-live' ) ) ? ' --run' : '';
    $out = shell_exec( 'cd ' . escapeshellarg( $root ) . ' && php ' . escapeshellarg( __DIR__ . '/' . $check ) . $args . ' 2>&1' );
    foreach ( $saved as $sp => $orig ) { file_put_contents( $sp, $orig ); }
    $caught = ( false !== strpos( (string) $out, $want ) );
    echo ( $caught ? 'caught  ' : 'MISSED  ' ) . $name . "\n";
    if ( ! $caught ) { $missed++; echo '        ' . str_replace( "\n", "\n        ", trim( substr( (string) $out, -1500 ) ) ) . "\n"; }
}
echo $missed ? "$missed PLANT(S) MISSED\n" : "every plant caught.\n";
exit( $missed ? 1 : 0 );
