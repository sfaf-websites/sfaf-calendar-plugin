<?php
/**
 * THE 3.106.0 BROWSER CHECKS, EACH MADE TO FAIL ON PURPOSE.
 *
 *     php .claude/plant-3106.php [part of a plant name]
 *
 * Each fault is written into the real file, release-3106-live.php --run is
 * run against it, and the file is put back from memory, not from git, so an
 * uncommitted change cannot be lost. A plant whose anchor is not there, or is
 * there twice, stops the run: a plant that did not apply proves nothing.
 */

$root = dirname( __DIR__ );
$plants = array(
    'E: the Volunteer button loses its green' => array( 'public/css/calendar.css',
        "background: var(--uc-green-ink); color: #fff; border-color: var(--uc-green-ink);\n}",
        "}", 'not white on --uc-green-ink' ),
    'E: the Volunteer button is not Register\'s shape' => array( 'public/css/calendar.css',
        ".uc-single .uc-actionbtn-volunteer {\n",
        ".uc-single .uc-actionbtn-volunteer {\n    padding: 2px 6px;\n", 'not Register\'s shape' ),
    'E: the Volunteer button is never drawn' => array( 'includes/sfaf-template-functions.php',
        "    \$url = sfaf_volunteer_url( \$post_id );\n    if ( '' === \$url ) {\n        return '';",
        "    \$url = sfaf_volunteer_url( \$post_id );\n    if ( true ) {\n        return '';", 'no Volunteer button' ),
    'E: no address still draws the element' => array( 'includes/sfaf-template-functions.php',
        "    \$url = sfaf_volunteer_url( \$post_id );\n    if ( '' === \$url ) {\n        return '';",
        "    \$url = sfaf_volunteer_url( \$post_id );\n    if ( '' === \$url ) {\n        return '<div class=\"uc-volunteer\"></div>';", 'draws a Volunteer element' ),
    'A: a full event\'s button says RSVP' => array( 'includes/sfaf-template-functions.php',
        "\$rsvp_label = sfaf_event_full( \$post_id ) ? 'Join the waitlist' : 'RSVP';",
        "\$rsvp_label = 'RSVP';", 'a full event\'s button reads' ),
    'D: one Backspace removes a chip' => array( 'public/js/portal.js',
        "if (c.classList.contains('is-armed')) {",
        "if (true) {", 'one Backspace did not arm' ),
    /* NOT a reload. A page that reloads itself never lets --virtual-time-budget
       run out, so that plant hung Chrome for sixteen minutes. The redraw is what
       choosing a message depends on, so the redraw is what is taken away. */
    'D: choosing a message does not redraw it' => array( 'public/js/portal.js',
        "                state.variant = p[1];\n                show();",
        "                state.variant = p[1];", 'choosing Waitlist does not change' ),
);

$missed = 0;
$only = isset( $argv[1] ) ? $argv[1] : '';   // run the plants whose name contains this
foreach ( $plants as $name => $p ) {
    if ( '' !== $only && false === strpos( $name, $only ) ) { continue; }
    list( $file, $from, $to, $want ) = $p;
    $path = $root . '/' . $file;
    $orig = file_get_contents( $path );
    if ( 1 !== substr_count( $orig, $from ) ) {
        echo "ANCHOR NOT UNIQUE for \"$name\" in $file\n";
        exit( 1 );
    }
    file_put_contents( $path, str_replace( $from, $to, $orig ) );
    $out = shell_exec( 'php ' . escapeshellarg( __DIR__ . '/release-3106-live.php' ) . ' --run 2>&1' );
    file_put_contents( $path, $orig );
    $caught = (bool) preg_match( '/' . str_replace( array( '/', "'" ), array( '\/', "'" ), $want ) . '/', (string) $out );
    echo ( $caught ? 'caught  ' : 'MISSED  ' ) . $name . "\n";
    if ( ! $caught ) { $missed++; echo '        ' . str_replace( "\n", "\n        ", trim( (string) $out ) ) . "\n"; }
}
echo $missed ? "$missed PLANT(S) MISSED\n" : "every plant caught.\n";
exit( $missed ? 1 : 0 );
