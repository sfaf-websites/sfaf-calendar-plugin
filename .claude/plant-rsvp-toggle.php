<?php
/**
 * PLANT A FAULT IN THE MOVED RSVP CONTROLS, RUN THE TEST, PUT IT BACK.
 */
$root = dirname( dirname( dirname( dirname( dirname( dirname( __DIR__ ) ) ) ) ) );
$root = 'C:/Users/msapoznikov/Documents/Apps/Calendar';

$plants = array(
    'the calendar file goes missing from the confirmation after the toggle moved' => array(
        'file' => 'includes/class-sfaf-notifications.php',
        'from' => "\$ics = SFAF_Online::ics_url_with_link( \$event_id, self::person_format( \$person ) );",
        'to'   => "\$ics = '';",
    ),
    /*
     * THE WRITE MOVES OUTSIDE THE GUARD, which is the shape this fault actually
     * has now that 3.97.0 put a branch inside it. Replacing the guard's
     * condition with `true` no longer matches the source, and a plant that does
     * not match proves nothing.
     */
    'the RSVP toggle is saved without its marker' => array(
        'file' => 'includes/class-sfaf-portal.php',
        'from' => "        if ( isset( \$_POST['uc_rsvp_toggle_present'] ) ) {",
        'to'   => "        update_post_meta( \$event_id, '_uc_rsvp_enabled', isset( \$_POST['rsvp_enabled'] ) ? '1' : '0' );\n        if ( isset( \$_POST['uc_rsvp_toggle_present'] ) ) {",
    ),
    'the marker stops travelling with the control' => array(
        'file' => 'includes/class-sfaf-portal.php',
        'from' => "                <input type=\"hidden\" name=\"uc_rsvp_toggle_present\" value=\"1\" />",
        'to'   => "",
    ),
    'an imported event loses its RSVP controls' => array(
        'file' => 'includes/class-sfaf-portal.php',
        'from' => "            \$draw_rsvp( array( 'rsvp_enabled', 'capacity' ) );\n            return \$rsvp_placed;",
        'to'   => "            return \$rsvp_placed;",
    ),
    'the Location card stops drawing Accept RSVPs' => array(
        'file' => 'includes/class-sfaf-portal.php',
        'from' => "            \$draw_rsvp( array( 'rsvp_enabled' ) );",
        'to'   => "",
    ),
    'the online capacity is no longer drawn under the meeting link' => array(
        'file' => 'includes/class-sfaf-portal.php',
        'from' => "                \$draw_rsvp( array( 'capacity_online' ) );",
        'to'   => "",
    ),
    'what the Location card placed is thrown away, so the catch-all draws it twice' => array(
        'file' => 'includes/class-sfaf-portal.php',
        'from' => "                    \$rsvp_placed = array_merge(\n                        \$rsvp_placed,\n                        (array) \$this->render_location_field( \$event_id, \$s_loc, \$prov, \$rsvp_ctx )\n                    );",
        'to'   => "                    \$this->render_location_field( \$event_id, \$s_loc, \$prov, \$rsvp_ctx );",
    ),
    'the page button no longer stands down when registrations are on' => array(
        'file' => 'includes/sfaf-template-functions.php',
        'from' => "function sfaf_add_to_calendar( \$post_id ) {\n    if ( sfaf_event_takes_rsvps( \$post_id ) ) {\n        return '';\n    }",
        'to'   => "function sfaf_add_to_calendar( \$post_id ) {",
    ),
    'the live dependency is scoped to a card again' => array(
        'file' => 'public/js/portal.js',
        'from' => "var rsvp = document.querySelector('input[name=\"rsvp_enabled\"]');",
        'to'   => "var rsvp = label.querySelector('input[name=\"rsvp_enabled\"]');",
    ),
);

$caught = 0; $missed = array(); $good = array();
foreach ( $plants as $p ) {
    if ( ! isset( $good[ $p['file'] ] ) ) { $good[ $p['file'] ] = file_get_contents( $root . '/' . $p['file'] ); }
}
foreach ( $plants as $name => $p ) {
    $path = $root . '/' . $p['file'];
    $src  = $good[ $p['file'] ];
    if ( false === strpos( $src, $p['from'] ) ) {
        $missed[] = $name . '  [the plant no longer matches the source]';
        continue;
    }
    $at     = strpos( $src, $p['from'] );
    $broken = substr( $src, 0, $at ) . $p['to'] . substr( $src, $at + strlen( $p['from'] ) );
    file_put_contents( $path, $broken );
    $out = array(); $code = 0;
    exec( 'php ' . escapeshellarg( $root . '/.claude/rsvp-toggle-calendar-test.php' ) . ' 2>&1', $out, $code );
    file_put_contents( $path, $src );
    if ( 0 !== $code ) { $caught++; echo '  caught: ' . $name . "\n"; }
    else { $missed[] = $name; echo '  MISSED: ' . $name . "\n"; }
}
foreach ( $good as $rel => $src ) {
    file_put_contents( $root . '/' . $rel, $src );
    if ( file_get_contents( $root . '/' . $rel ) !== $src ) { echo "RESTORE FAILED $rel\n"; exit( 1 ); }
}
echo "\n";
if ( $missed ) {
    echo 'PLANTS NOT CAUGHT: ' . count( $missed ) . ' of ' . count( $plants ) . "\n";
    foreach ( $missed as $m ) { echo '  . ' . $m . "\n"; }
    exit( 1 );
}
echo 'caught ' . $caught . ' of ' . count( $plants ) . " planted faults, and every file is restored.\n";
