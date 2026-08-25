<?php
/**
 * Plant one fault in class-sfaf-follow.php, so follow-test.php can be made to
 * fail on purpose.
 *
 * A CHECK THAT HAS NEVER FAILED IS NOT KNOWN TO WORK. Three separate harnesses
 * in this project passed for weeks while proving nothing, so a new one is run
 * against deliberate breakage before it is trusted. This is the same job
 * plant-one.php does for the callable audit.
 *
 * PLAIN STRING REPLACEMENT, NOT REGEX, and not through a shell string: a `$var`
 * handed to perl or bash is eaten, and what came back was a file that no longer
 * parsed, which reads as "the test did not catch it" when nothing was tested at
 * all.
 *
 *     php .claude/plant-follow.php --list
 *     php .claude/plant-follow.php <key>     # writes the fault in place
 *     php .claude/plant-follow.php --restore # puts the file back
 *
 * It refuses to plant over a file it did not save first, so the original is
 * always recoverable without git.
 */

$root   = dirname( __DIR__ );
$target = $root . '/includes/class-sfaf-follow.php';
$backup = $root . '/.follow-original.bak';

$faults = array(
    'case' => array(
        'what' => 'case is not folded, so Sam@ and sam@ become two people',
        'from' => 'return strtolower( trim( (string) $email ) );',
        'to'   => 'return trim( (string) $email );',
    ),
    /*
     * THIS ONE IS EXPECTED NOT TO BE CAUGHT, AND THAT IS THE FINDING.
     *
     * An empty token is refused TWICE, independently: by this guard, and by
     * `confirm_token <> ''` in the SQL below it. Removing either leaves the
     * behaviour identical, so no behavioural test can see the difference. It is
     * kept in the list so that the next person to run the planter reads this
     * rather than concluding the harness has a hole. If a build ever removes
     * BOTH, the assertion `an empty confirm token matches nothing` fires.
     */
    'empty-token' => array(
        'what' => 'an empty confirm token is not rejected before the query (expected NOT CAUGHT: guarded twice)',
        'from' => "        \$token = trim( (string) \$token );\n        if ( '' === \$token ) {\n            return null;\n        }\n\n        \$row = \$wpdb->get_row(",
        'to'   => "        \$token = trim( (string) \$token );\n\n        \$row = \$wpdb->get_row(",
    ),
    'replay' => array(
        'what' => 'the confirm token is left in place, so the link can be replayed',
        'from' => "array( 'status' => 'active', 'confirmed_at' => current_time( 'mysql' ), 'confirm_token' => '' ),\n            array( 'id' => (int) \$row->id ),\n            array( '%s', '%s', '%s' ),",
        'to'   => "array( 'status' => 'active', 'confirmed_at' => current_time( 'mysql' ) ),\n            array( 'id' => (int) \$row->id ),\n            array( '%s', '%s' ),",
    ),
    'no-way-out' => array(
        'what' => 'the unsubscribe link is left out of the confirmation email',
        'from' => "        \$text .= 'To stop these emails at any time: ' . \$stop . \"\\n\";",
        'to'   => "        \$text .= \"Reply to this message to stop.\\n\";",
    ),
    'never-expires' => array(
        'what' => 'an out-of-time pending record is still confirmable',
        'from' => "        if ( strtotime( (string) \$row->created_at ) < self::expiry_cutoff() ) {\n            return null;\n        }\n        return \$row;",
        'to'   => "        return \$row;",
    ),
    'pending-counts' => array(
        'what' => 'a pending record is in the audience',
        'from' => "WHERE term_id = %d AND status = 'active' ORDER BY id ASC",
        'to'   => "WHERE term_id = %d ORDER BY id ASC",
    ),
    'get-acts' => array(
        'what' => 'a GET confirms, so a link previewer activates the record',
        'from' => "        if ( self::posted( \$token ) ) {\n            \$done = self::confirm( \$token );",
        'to'   => "        if ( true ) {\n            \$done = self::confirm( \$token );",
    ),
    'talks-to-rsvps' => array(
        'what' => 'the follower path reads the registrations table',
        'from' => "        return \$wpdb->prefix . 'uc_series_followers';",
        'to'   => "        \$also = \$wpdb->get_var( \"SELECT COUNT(*) FROM {\$wpdb->prefix}uc_rsvps\" );\n        return \$wpdb->prefix . 'uc_series_followers';",
    ),
    'records-consent' => array(
        'what' => 'following writes a marketing opt-in as a side effect',
        'from' => "        return self::send_confirmation( \$term, \$row );",
        'to'   => "        SFAF_Optins::record( \$email, '', 0, 'follow' );\n        return self::send_confirmation( \$term, \$row );",
    ),
);

$arg = isset( $argv[1] ) ? $argv[1] : '--list';

if ( '--list' === $arg ) {
    echo "Faults this can plant in class-sfaf-follow.php:\n";
    foreach ( $faults as $key => $f ) {
        printf( "  %-16s %s\n", $key, $f['what'] );
    }
    exit( 0 );
}

if ( '--restore' === $arg ) {
    if ( ! file_exists( $backup ) ) {
        echo "nothing to restore: no saved original.\n";
        exit( 1 );
    }
    file_put_contents( $target, file_get_contents( $backup ) );
    unlink( $backup );
    echo "restored.\n";
    exit( 0 );
}

if ( ! isset( $faults[ $arg ] ) ) {
    echo "unknown fault: {$arg}. Try --list.\n";
    exit( 2 );
}

$src = file_get_contents( $target );

// Save the original ONCE. A second plant over an already-planted file would
// otherwise save the broken one as the thing to restore to.
if ( ! file_exists( $backup ) ) {
    file_put_contents( $backup, $src );
}
$original = file_get_contents( $backup );

$fault = $faults[ $arg ];
$count = substr_count( $original, $fault['from'] );
if ( 1 !== $count ) {
    printf( "REFUSED: the text to replace appears %d times, not once. The fault has drifted from the file.\n", $count );
    exit( 3 );
}

file_put_contents( $target, str_replace( $fault['from'], $fault['to'], $original ) );
printf( "planted %s: %s\n", $arg, $fault['what'] );
