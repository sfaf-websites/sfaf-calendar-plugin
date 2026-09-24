<?php
/**
 * PLANT THE 3.97.3 FAULT, RUN THE CHECK, PUT IT BACK.
 *
 *     php .claude/plant-link-delivery.php
 *
 * THE FAULT WAS A MISSING FIELD, WHICH IS WHY IT NEEDS ITS OWN PLANTER. Every
 * plant here takes one field off a person object, or narrows one question back
 * to the one it used to ask. None of them touches the gate, because the gate
 * was never wrong: a planter that broke the gate would prove the suite catches
 * a fault nobody had.
 *
 * Restores every file and compares byte for byte before exiting, because a
 * planted fault left in the tree is a planted fault that ships.
 */

$root = dirname( __DIR__ );

$plants = array(

    /* ---- THE JOIN: the confirmation and the alert. ---------------------- */
    /* THE EXACT FAULT THAT SHIPPED IN 3.96.0. The person object carried five
     * fields and the format was not one of them, so person_format() read
     * nothing and the gate refused the link to the one person it is for. */
    'THE PERSON OBJECT LOSES ITS FORMAT AGAIN (the 3.96.0 fault)' => array(
        'file' => 'includes/class-sfaf-rsvp.php',
        'from' => "            'format'     => isset( \$data['format'] ) ? (string) \$data['format'] : '',\n",
        'to'   => "",
    ),

    /* AND THE SAME FIELD CARRYING THE WRONG THING. A person object that has a
     * format but the wrong one passes every "is it set" check and fails the
     * only question that matters. */
    'the person object carries the name where the format goes' => array(
        'file' => 'includes/class-sfaf-rsvp.php',
        'from' => "            'format'     => isset( \$data['format'] ) ? (string) \$data['format'] : '',",
        'to'   => "            'format'     => isset( \$data['name'] ) ? (string) \$data['name'] : '',",
    ),

    /* ---- THE JOIN: the morning-of reminder. ----------------------------- */
    /* TWO SEPARATE PLANTS, because the query and the person object are two
     * places the format can be dropped and either alone is the whole fault. */
    'the reminder query stops selecting the format' => array(
        'file' => 'includes/class-sfaf-reminders.php',
        'from' => "\"SELECT id, email, format FROM \$table WHERE event_id = %d AND status = 'confirmed'\"",
        'to'   => "\"SELECT id, email FROM \$table WHERE event_id = %d AND status = 'confirmed'\"",
    ),

    'the reminder builds a person with no format' => array(
        'file' => 'includes/class-sfaf-reminders.php',
        'from' => "            'format'   => (string) \$format,\n",
        'to'   => "",
    ),

    /* AND THE LOOP FORGETTING TO PASS IT. The parameter has a default, so a
     * caller that omits it is accepted silently and the empty string wins,
     * which is the shape that fails quietly rather than loudly. */
    'the reminder loop stops passing the format to send_one()' => array(
        'file' => 'includes/class-sfaf-reminders.php',
        'from' => "self::send_one( \$event_id, \$email, \$type, \$claim['token'], \$format )",
        'to'   => "self::send_one( \$event_id, \$email, \$type, \$claim['token'] )",
    ),

    /* ---- THE TOKEN: the calendar file's half. --------------------------- */
    /* is_online() IS THE NARROW QUESTION and a hybrid event answers no to it,
     * so this one line stopped a token being minted OR verified for any hybrid
     * event. 3.97.2 widened the reader in sfaf_output_ics() and left this. */
    'THE JOIN TOKEN GOES BACK TO THE NARROW QUESTION (the 3.97.2 miss)' => array(
        'file' => 'includes/class-sfaf-online.php',
        'from' => "        if ( ! \$event_id || ! self::has_online_format( \$event_id ) ) {\n            return '';\n        }\n        return substr( hash_hmac( 'sha256', 'sfaf-ics-join|' . \$event_id, wp_salt( 'auth' ) ), 0, 32 );",
        'to'   => "        if ( ! \$event_id || ! self::is_online( \$event_id ) ) {\n            return '';\n        }\n        return substr( hash_hmac( 'sha256', 'sfaf-ics-join|' . \$event_id, wp_salt( 'auth' ) ), 0, 32 );",
    ),

    /* ---- AND THE GATES THEMSELVES, both directions. --------------------- */
    /* THE LINK GOING TO SOMEBODY WHO SAID IN PERSON. The half of the rule that
     * hands a credential to the wrong person, which is the expensive direction. */
    'the link gate stops asking the recipient format at all' => array(
        'file' => 'includes/class-sfaf-online.php',
        'from' => "        if ( self::is_hybrid( \$event_id ) && self::MODE_ONLINE !== (string) \$format ) {\n            return '';\n        }",
        'to'   => "        // gate removed",
        'all'  => true,
    ),

    /* THE GATE WRITTEN AS A DENY, which is the shape that breaks every purely
     * online event: '' means "the event never asked", not "in person". */
    'the gate is written as a deny, so a plain online event loses its link' => array(
        'file' => 'includes/class-sfaf-online.php',
        'from' => "        if ( self::is_hybrid( \$event_id ) && self::MODE_ONLINE !== (string) \$format ) {",
        'to'   => "        if ( self::MODE_IN_PERSON === (string) \$format || '' === (string) \$format ) {",
        'all'  => true,
    ),

    /* THE CALENDAR FILE'S GATE, separately from the email's. The .ics is the
     * wider way the link leaves: it syncs to a phone and to shared calendars. */
    'the calendar file stops asking the recipient format' => array(
        'file' => 'includes/class-sfaf-online.php',
        'from' => "        if ( self::is_hybrid( \$event_id ) && self::MODE_ONLINE !== (string) \$format ) {\n            return \$url;\n        }",
        'to'   => "        // gate removed",
    ),

    /* ---- THE ADDRESS, the other half of the same rule. ------------------ */
    'an online registrant of a hybrid event is sent the street address' => array(
        'file' => 'includes/class-sfaf-notifications.php',
        'from' => "        if ( '' !== (string) \$format\n            && SFAF_Online::MODE_ONLINE === (string) \$format\n            && SFAF_Online::is_hybrid( \$event_id ) ) {\n            \$location = SFAF_Online::LABEL;\n        }",
        'to'   => "        // address given to everybody",
    ),

    /* ---- AND THE READER OF THE FIELD. ---------------------------------- */
    'person_format() stops reading the object' => array(
        'file' => 'includes/class-sfaf-notifications.php',
        'from' => "        return isset( \$person->format ) ? (string) \$person->format : '';",
        'to'   => "        return '';",
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
        echo '  MISSED: ' . $name . "\n";
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
        echo '  MISSED: ' . $name . "\n";
        continue;
    }
    file_put_contents( $path, $broken );

    $out  = array();
    $code = 0;
    exec( 'php ' . escapeshellarg( $root . '/.claude/hybrid-link-delivery-test.php' ) . ' 2>&1', $out, $code );

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
