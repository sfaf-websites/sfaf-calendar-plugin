<?php
/**
 * PLANT A FAULT IN THE HYBRID PATH, RUN THE TEST, PUT IT BACK.
 *
 *     php .claude/plant-hybrid.php
 *
 * THE FIRST TWO ARE THE ONES THAT MATTER. A hybrid event has a street address
 * and a meeting link, and the link is a credential. The two refusals that keep
 * them apart sit in different files, so each is taken out on its own: a test
 * that only catches them together would pass while one of them was gone.
 *
 * Restores every file and compares byte for byte before exiting, because a
 * planted fault left in the tree is a planted fault that ships.
 */

$root = dirname( __DIR__ );

$plants = array(

    /* ---- THE CREDENTIAL. ------------------------------------------------ */
    'an in-person registrant of a hybrid event is sent the meeting link' => array(
        'file' => 'includes/class-sfaf-online.php',
        'from' => "        if ( self::is_hybrid( \$event_id ) && self::MODE_ONLINE !== (string) \$format ) {\n            return '';\n        }",
        'to'   => "        // gate removed",
        // Both copies, because html and text are two halves of one message.
        'all'  => true,
    ),

    'an online registrant of a hybrid event is sent the street address' => array(
        'file' => 'includes/class-sfaf-notifications.php',
        'from' => "        if ( '' !== (string) \$format\n            && SFAF_Online::MODE_ONLINE === (string) \$format\n            && SFAF_Online::is_hybrid( \$event_id ) ) {\n            \$location = SFAF_Online::LABEL;\n        }",
        'to'   => "        // address given to everybody",
    ),

    'the gate is written as a deny, so a purely online event loses its link' => array(
        'file' => 'includes/class-sfaf-online.php',
        'from' => "        if ( self::is_hybrid( \$event_id ) && self::MODE_ONLINE !== (string) \$format ) {",
        'to'   => "        if ( self::MODE_IN_PERSON === (string) \$format || '' === (string) \$format ) {",
        'all'  => true,
    ),

    'one of the four messages stops passing the recipient format' => array(
        'file' => 'includes/class-sfaf-notifications.php',
        'from' => "        \$f = self::facts( \$event_id, self::person_format( \$person ) );",
        'to'   => "        \$f = self::facts( \$event_id );",
    ),

    'one of the four joining blocks is no longer told who it is for' => array(
        'file' => 'includes/class-sfaf-notifications.php',
        'from' => "SFAF_Online::joining_html( \$event_id, 'confirmation', self::person_format( \$person ) )",
        'to'   => "SFAF_Online::joining_html( \$event_id, 'confirmation' )",
    ),

    'the calendar file hands the join copy to an in-person registrant' => array(
        'file' => 'includes/class-sfaf-notifications.php',
        'from' => "\$ics = SFAF_Online::ics_url_with_link( \$event_id, self::person_format( \$person ) );",
        'to'   => "\$ics = SFAF_Online::ics_url_with_link( \$event_id );",
    ),
    /* ---- THE MODEL. ----------------------------------------------------- */
    'a hybrid event has its address cleared, like an online one' => array(
        'file' => 'includes/class-sfaf-online.php',
        'from' => "        if ( self::MODE_ONLINE === \$mode ) {\n            SFAF_Venues::set_for_event( \$event_id, 0 );",
        'to'   => "        if ( true ) {\n            SFAF_Venues::set_for_event( \$event_id, 0 );",
    ),

    'a hybrid event carries the online key as well' => array(
        'file' => 'includes/class-sfaf-online.php',
        'from' => "            update_post_meta( \$event_id, self::META_HYBRID, '1' );\n            delete_post_meta( \$event_id, self::META );",
        'to'   => "            update_post_meta( \$event_id, self::META_HYBRID, '1' );\n            update_post_meta( \$event_id, self::META, '1' );",
    ),

    'a hybrid event answers yes to is_online(), which takes its address away everywhere' => array(
        'file' => 'includes/class-sfaf-online.php',
        'from' => "    public static function is_online( \$event_id ) {\n        return '1' === (string) get_post_meta( (int) \$event_id, self::META, true );",
        'to'   => "    public static function is_online( \$event_id ) {\n        return self::has_online_format( (int) \$event_id );",
    ),

    'the hybrid key no longer travels with a repeating event' => array(
        'file' => 'includes/class-sfaf-recurrence.php',
        'from' => "        '_uc_online', '_uc_hybrid', '_uc_meeting_url', '_uc_online_send',",
        'to'   => "        '_uc_online', '_uc_meeting_url', '_uc_online_send',",
    ),

    /* ---- CAPACITY. ------------------------------------------------------ */
    'one full format closes the whole event' => array(
        'file' => 'sfaf-calendar.php',
        'from' => "function sfaf_event_full( \$event_id ) {\n    foreach ( sfaf_event_formats( \$event_id ) as \$format ) {",
        'to'   => "function sfaf_event_full( \$event_id ) {\n    if ( true ) { return sfaf_format_full( \$event_id, sfaf_event_formats( \$event_id )[0] ); }\n    foreach ( sfaf_event_formats( \$event_id ) as \$format ) {",
    ),

    'online capacity counts against the in-person limit' => array(
        'file' => 'sfaf-calendar.php',
        'from' => "    return ( SFAF_Online::MODE_ONLINE === (string) \$format )\n        ? '_uc_capacity_online'\n        : '_uc_capacity';",
        'to'   => "    return '_uc_capacity';",
    ),

    'the per-format counts survive a cancellation' => array(
        'file' => 'sfaf-calendar.php',
        'from' => "    \$by_format =& sfaf_rsvp_format_count_store();",
        'to'   => "    \$by_format = array();",
    ),

    'the posted format is trusted' => array(
        'file' => 'includes/class-sfaf-rsvp.php',
        'from' => "            \$format = in_array( \$asked, \$formats, true ) ? \$asked : \$formats[0];",
        'to'   => "            \$format = \$asked;",
    ),

    /* ---- THE FORM. ------------------------------------------------------ */
    'the form decides for itself which formats an event has' => array(
        'file' => 'includes/sfaf-template-functions.php',
        'from' => "                'data-uc-formats' => implode( ',', sfaf_event_formats( \$post_id ) ),",
        'to'   => "",
    ),

    'the format question is asked after the name and email' => array(
        'file' => 'public/js/calendar.js',
        'from' => "'<div class=\"uc-rsvp-format\" id=\"uc-rsvp-format\" hidden></div>' +",
        'to'   => "",
    ),
);

$caught = 0;
$missed = array();
$good   = array();

foreach ( $plants as $p ) {
    if ( ! isset( $good[ $p['file'] ] ) ) {
        $good[ $p['file'] ] = file_get_contents( $root . '/' . $p['file'] );
    }
}

foreach ( $plants as $name => $p ) {
    $path = $root . '/' . $p['file'];
    $src  = $good[ $p['file'] ];

    if ( false === strpos( $src, $p['from'] ) ) {
        $missed[] = $name . '  [the plant no longer matches the source, so it proved nothing]';
        continue;
    }
    if ( ! empty( $p['all'] ) ) {
        $broken = str_replace( $p['from'], $p['to'], $src );
    } else {
        $at     = strpos( $src, $p['from'] );
        $broken = substr( $src, 0, $at ) . $p['to'] . substr( $src, $at + strlen( $p['from'] ) );
    }
    if ( $broken === $src ) {
        $missed[] = $name . '  [the swap changed nothing]';
        continue;
    }
    file_put_contents( $path, $broken );

    $out  = array();
    $code = 0;
    exec( 'php ' . escapeshellarg( $root . '/.claude/hybrid-test.php' ) . ' 2>&1', $out, $code );

    file_put_contents( $path, $src );

    if ( 0 !== $code ) {
        $caught++;
        echo '  caught: ' . $name . "\n";
    } else {
        $missed[] = $name;
        echo '  MISSED: ' . $name . "\n";
    }
}

foreach ( $good as $rel => $src ) {
    file_put_contents( $root . '/' . $rel, $src );
    if ( file_get_contents( $root . '/' . $rel ) !== $src ) {
        echo 'RESTORE FAILED for ' . $rel . ". Do not build.\n";
        exit( 1 );
    }
}

echo "\n";
if ( $missed ) {
    echo 'PLANTS NOT CAUGHT: ' . count( $missed ) . ' of ' . count( $plants ) . "\n";
    foreach ( $missed as $m ) { echo '  . ' . $m . "\n"; }
    exit( 1 );
}
echo 'caught ' . $caught . ' of ' . count( $plants ) . " planted faults, and every file is restored.\n";
exit( 0 );
