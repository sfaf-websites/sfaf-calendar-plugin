<?php
/**
 * THE 3.136.0 CHECKS, MADE TO FAIL ON PURPOSE.
 *
 *     php .claude/plant-31360.php [part of a plant's name]
 *
 * Plants, one at a time, a fault in the Events list's RSVP pill and runs the
 * check that must catch it. Every file is put back from memory. Commit first,
 * and never run this while run-all.sh is running.
 */

$root = dirname( __DIR__ );
$P = 'includes/class-sfaf-portal.php';
$C = 'public/css/portal.css';
$F = 'includes/sfaf-template-functions.php';
$T = 'release-31360-test.php';
$L = 'release-31360-live.php';

$plants = array(
    '"View RSVPs" at none' => array( $T, 'FAIL  none yet: "0 RSVPs", never "View RSVPs"', array(
        array( $P, "esc_html( sprintf( '%d %s', \$rsvp_n, 1 === \$rsvp_n ? 'RSVP' : 'RSVPs' ) )", "esc_html( \$rsvp_n ? sprintf( '%d %s', \$rsvp_n, 1 === \$rsvp_n ? 'RSVP' : 'RSVPs' ) : 'View RSVPs' )" ),
    ) ),
    '"1 RSVPs"' => array( $T, 'FAIL  "1 RSVP"', array(
        array( $P, "esc_html( sprintf( '%d %s', \$rsvp_n, 1 === \$rsvp_n ? 'RSVP' : 'RSVPs' ) )", "esc_html( sprintf( '%d %s', \$rsvp_n, 'RSVPs' ) )" ),
    ) ),
    'anyone can click it' => array( $T, 'FAIL  not theirs: the same pill and number, not a link', array(
        array( $P, "                            if ( \$this->can_edit_event( \$user, \$id ) ) {\n                                echo '<a class=\"uc-rsvp-pill\"", "                            if ( true ) {\n                                echo '<a class=\"uc-rsvp-pill\"" ),
    ) ),
    'nobody else sees a number' => array( $T, 'FAIL  not theirs: the same pill and number, not a link', array(
        array( $P, "                                echo '<span class=\"uc-rsvp-pill\" data-uc-rsvp-pill>' . \$pill . '</span>';", "                                echo (int) \$rsvp_n;" ),
    ) ),
    'a number where none are taken' => array( $T, 'FAIL  switched off: a dash', array(
        array( $P, "                            echo '<span class=\"uc-muted\" data-uc-rsvp-none>&ndash;</span>';", "                            echo (int) \$rsvp_n;" ),
    ) ),
    'no person before the number' => array( $L, 'PLANT M:', array(
        array( $P, "\$pill = sfaf_icon( 'person', array( 'size' => '14px' ) )\n                                . '<span>'", "\$pill = '<span>'" ),
    ) ),
    'the pill is grey' => array( $L, 'PLANT M:', array(
        array( $C, "    background: var(--uc-band); color: var(--uc-accent-text);\n}\n.uc-rsvp-pill .sfaf-icon", "    background: #eef2f7; color: var(--p-muted);\n}\n.uc-rsvp-pill .sfaf-icon" ),
    ) ),
    'the link turns link-coloured' => array( $L, 'PLANT M:', array(
        array( $C, ".uc-portal a.uc-rsvp-pill { color: var(--uc-accent-text); text-decoration: none;", ".uc-portal a.uc-rsvp-pill { color: #0000EE; text-decoration: underline;" ),
    ) ),
    'the hover goes lighter and fails' => array( $L, 'PLANT M:', array(
        array( $C, ".uc-portal a.uc-rsvp-pill:hover { background: #D9F2F4; color: #0C666F;", ".uc-portal a.uc-rsvp-pill:hover { background: #F5FDFD; color: #3FA9B3;" ),
    ) ),
    'the pill wraps' => array( $L, 'PLANT M:', array(
        array( $C, "font-size: 12px; font-weight: 600; line-height: 1.5; white-space: nowrap;\n    background: var(--uc-band)", "font-size: 12px; font-weight: 600; line-height: 1.5; white-space: normal; width: 30px;\n    background: var(--uc-band)" ),
    ) ),
    'the person icon is missing' => array( $T, 'FAIL  the person icon exists', array(
        array( $F, "        'person'    => '<circle cx=\"12\" cy=\"8\" r=\"3.5\"/><path d=\"M5 20a7 7 0 0 1 14 0\"/>',\n", '' ),
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
