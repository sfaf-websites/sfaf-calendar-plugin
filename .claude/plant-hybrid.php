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

    /* ---- THE DISPLAY, THE ALERT AND THE SUMMARY (3.96.0). --------------- */
    'the hybrid line is folded into the address, where it reaches the maps query' => array(
        'file' => 'includes/sfaf-template-functions.php',
        'from' => "function sfaf_event_format_line( \$post_id ) {\n    return SFAF_Online::is_hybrid( (int) \$post_id ) ? 'In person and online' : '';",
        'to'   => "function sfaf_event_format_line( \$post_id ) {\n    return '';",
    ),

    'the event page stops saying a hybrid event is also online' => array(
        'file' => 'templates/single-uc_event.php',
        'from' => "\$format_line = sfaf_event_format_line( \$post_id );",
        'to'   => "\$format_line = '';",
    ),

    'the list location column stops saying a hybrid event is also online' => array(
        'file' => 'includes/class-sfaf-shortcodes.php',
        'from' => "\$format_line = sfaf_event_format_line( \$post_id );",
        'to'   => "\$format_line = '';",
    ),

    'the alert reports the whole-event count beside a named format' => array(
        'file' => 'includes/class-sfaf-notifications.php',
        'from' => "\$count  = (int) sfaf_get_rsvp_count_by_format( \$event_id, \$format );",
        'to'   => "\$count  = (int) sfaf_get_rsvp_count( \$event_id );",
    ),

    'the alert stops naming the format' => array(
        'file' => 'includes/class-sfaf-notifications.php',
        'from' => "            \$rows['Attending'] = ( SFAF_Online::MODE_ONLINE === \$format ) ? 'Online' : 'In person';",
        'to'   => "            \$rows['Attending'] = '';",
    ),

    'the summary lists every hybrid registrant twice' => array(
        'file' => 'includes/class-sfaf-notifications.php',
        'from' => "        if ( ! \$hybrid ) {\n            foreach ( \$rows as \$row ) {",
        'to'   => "        if ( true ) {\n            foreach ( \$rows as \$row ) {",
    ),

    'a registration with no recorded format vanishes from the summary' => array(
        'file' => 'includes/class-sfaf-notifications.php',
        'from' => "                if ( ! isset( \$groups[ \$rf ] ) ) {\n                    \$rf = '';\n                }",
        'to'   => "                if ( ! isset( \$groups[ \$rf ] ) ) {\n                    continue;\n                }",
    ),

    'an online event reads its capacity from a key nothing writes' => array(
        'file' => 'sfaf-calendar.php',
        'from' => "    if ( ! SFAF_Online::is_hybrid( \$event_id ) ) {\n        return max( 0, (int) get_post_meta( \$event_id, '_uc_capacity', true ) );\n    }",
        'to'   => "    if ( '' === \$format ) {\n        \$format = SFAF_Online::is_online( \$event_id ) ? SFAF_Online::MODE_ONLINE : SFAF_Online::MODE_IN_PERSON;\n    }",
    ),
    /* ---- THE THREE FAULTS 3.97.2 FIXED. --------------------------------- */
    'both format ticks can be saved at once' => array(
        'file' => 'includes/class-sfaf-portal.php',
        'from' => "            if ( \$want_hybrid ) {\n                \$want_mode = SFAF_Online::MODE_HYBRID;\n            } elseif ( \$want_online ) {",
        'to'   => "            if ( \$want_online ) {\n                \$want_mode = SFAF_Online::MODE_ONLINE;\n            } elseif ( \$want_hybrid ) {",
    ),

    'the two ticks stop clearing each other on screen' => array(
        'file' => 'public/js/portal.js',
        'from' => "                if (changed === online && online.checked) { hybrid.checked = false; }\n                if (changed === hybrid && hybrid.checked) { online.checked = false; }",
        'to'   => "                return;",
    ),

    'a hybrid event hides the meeting link' => array(
        'file' => 'public/js/portal.js',
        'from' => "                if (panel) { panel.hidden = !(isOnline || isHybrid); }",
        'to'   => "                if (panel) { panel.hidden = !isOnline; }",
    ),

    'a hybrid event hides its venue and address' => array(
        'file' => 'public/js/portal.js',
        'from' => "                if (place) { place.hidden = isOnline && !isHybrid; }",
        'to'   => "                if (place) { place.hidden = isOnline; }",
    ),

    'the online capacity goes back to appearing only after a save' => array(
        'file' => 'includes/class-sfaf-portal.php',
        'from' => "                if ( ! \$event_id || SFAF_Sources::takes_rsvps_at_source( \$event_id ) ) {\n                    break;\n                }\n                \$s_cap_on  = \$this->field_state",
        'to'   => "                if ( ! \$event_id || ! SFAF_Online::is_hybrid( \$event_id )\n                    || SFAF_Sources::takes_rsvps_at_source( \$event_id ) ) {\n                    break;\n                }\n                \$s_cap_on  = \$this->field_state",
    ),

    'HYBRID SAVES WITH RSVPS OFF' => array(
        'file' => 'includes/class-sfaf-portal.php',
        'from' => "            } elseif ( SFAF_Online::is_hybrid( \$event_id ) ) {\n                update_post_meta( \$event_id, '_uc_rsvp_enabled', '1' );",
        'to'   => "            } elseif ( false ) {\n                update_post_meta( \$event_id, '_uc_rsvp_enabled', '1' );",
    ),

    'a hybrid event can hide its RSVP button, leaving the format unaskable' => array(
        'file' => 'includes/class-sfaf-portal.php',
        'from' => "            if ( 'show_rsvp' === \$field && SFAF_Online::is_hybrid( \$event_id ) ) {\n                update_post_meta( \$event_id, \$key, '1' );\n                continue;\n            }",
        'to'   => "",
    ),

    'the editor stops locking Accept RSVPs on for hybrid' => array(
        'file' => 'includes/class-sfaf-portal.php',
        'from' => "<?php disabled( \$at_source || \$rsvp_forced ); ?>",
        'to'   => "<?php disabled( \$at_source ); ?>",
    ),

    'THE CALENDAR FILE DROPS A HYBRID EVENT\'S LINK' => array(
        'file' => 'sfaf-calendar.php',
        'from' => "    if ( SFAF_Online::has_online_format( \$post_id ) && SFAF_Online::has_link( \$post_id ) ) {",
        'to'   => "    if ( SFAF_Online::is_online( \$post_id ) && SFAF_Online::has_link( \$post_id ) ) {",
    ),

    'the calendar file hands out the link with no token' => array(
        'file' => 'sfaf-calendar.php',
        'from' => "        if ( SFAF_Online::sends_with( \$post_id, 'confirmation' ) && SFAF_Online::ics_join_ok( \$post_id, \$asked ) ) {",
        'to'   => "        if ( true ) {",
    ),

    'the calendar file stops saying a hybrid event is also online' => array(
        'file' => 'sfaf-calendar.php',
        'from' => "    \$format_line = sfaf_event_format_line( \$post_id );",
        'to'   => "    \$format_line = '';",
    ),

    'the format sentence is put into LOCATION, which a client hands to a map' => array(
        'file' => 'sfaf-calendar.php',
        'from' => "    \$lines[] = 'LOCATION:' . sfaf_ics_escape( sfaf_event_location( \$post_id ) );",
        'to'   => "    \$lines[] = 'LOCATION:' . sfaf_ics_escape( sfaf_event_location( \$post_id ) . ' ' . \$format_line );",
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
