<?php
/**
 * THE 3.125.0 CHECK, MADE TO FAIL ON PURPOSE.
 *
 *     php .claude/plant-31250.php            every plant
 *     php .claude/plant-31250.php "B:"       only those whose name has "B:"
 *
 * Puts one fault in at a time and runs release-31250-test.php, which must fail
 * with the line named. Every file is put back from memory. Commit first, and
 * never run this while run-all.sh is running.
 */

$root = dirname( __DIR__ );
$R    = 'includes/class-sfaf-request.php';
$S    = 'includes/class-sfaf-submissions.php';
$P    = 'includes/class-sfaf-portal.php';

$plants = array(
    'A: the connection is counted again' => array( 'PLANT A: the staff form still counts per connection', array(
        array( $R, "        \$wait = SFAF_Submissions::window_wait( 'request_addr', \$email, self::SUBMIT_LIMIT );", "        self::allow( 'submit_ip', self::client(), 10, self::TOKEN_TTL );\n        \$wait = SFAF_Submissions::window_wait( 'request_addr', \$email, self::SUBMIT_LIMIT );" ),
    ) ),
    'A: the limit is lower' => array( 'PLANT A: the staff limit is', array(
        array( $R, '    const SUBMIT_LIMIT = 30;', '    const SUBMIT_LIMIT = 10;' ),
    ) ),
    'A: counted before it is saved' => array( 'PLANT A: a request is counted before it is saved', array(
        array( $R, "        SFAF_Submissions::window_count( 'request_addr', \$email, HOUR_IN_SECONDS );\n\n        self::notify_admins(", "        self::notify_admins(" ),
        array( $R, "        \$checked = self::validate( \$_POST );\n\n        /*\n         * THE UPLOAD", "        \$checked = self::validate( \$_POST );\n        SFAF_Submissions::window_count( 'request_addr', \$email, HOUR_IN_SECONDS );\n\n        /*\n         * THE UPLOAD" ),
    ) ),
    'A: one counter for everybody' => array( 'PLANT A: one person\'s requests counted against a colleague', array(
        array( $S, "        \$v = get_transient( self::RATE_PREFIX . \$bucket . '_' . hash( 'sha256', (string) \$who ) );", "        \$v = get_transient( self::RATE_PREFIX . \$bucket . '_' . hash( 'sha256', '' ) );" ),
        array( $S, "        \$key = self::RATE_PREFIX . \$bucket . '_' . hash( 'sha256', (string) \$who );\n        \$now = time();", "        \$key = self::RATE_PREFIX . \$bucket . '_' . hash( 'sha256', '' );\n        \$now = time();" ),
    ) ),
    'A: the window slides' => array( 'PLANT A: counting moved the window', array(
        array( $S, "        set_transient( \$key, \$v, max( 1, (int) \$v['until'] - \$now ) );", "        \$v['until'] = \$now + (int) \$window;\n        set_transient( \$key, \$v, (int) \$window );" ),
    ) ),
    'A: the wait is not said' => array( 'PLANT A: the refusal does not say how long', array(
        array( $R, "                'form' => 'You have sent ' . self::SUBMIT_LIMIT . ' requests this hour. You can send this one in '\n                    . \$mins . ( 1 === \$mins ? ' minute.' : ' minutes.' ),", "                'form' => 'That is several requests in a short time. Give it a few minutes, then try again.'," ),
    ) ),
    'A: the refusal empties the form' => array( 'PLANT A: the refusal threw away what was typed', array(
        array( $R, "            self::render_form( \$token, \$email, self::validate( \$_POST )['clean'], array(", "            self::render_form( \$token, \$email, array(), array(" ),
    ) ),
    'B: no series dropdown' => array( 'PLANT B: the bar\'s dropdowns are', array(
        array( $P, "            <?php if ( ! is_wp_error( \$series_terms ) && \$series_terms ) : ?>\n                <select name=\"series\"", "            <?php if ( false ) : ?>\n                <select name=\"series\"" ),
    ) ),
    'B: the choice is not shown chosen' => array( 'PLANT B: the chosen series and organizer are not shown chosen', array(
        array( $P, "<?php selected( \$filters['org'], \$t->term_id ); ?>", '' ),
    ) ),
    'B: the category replaces the rest' => array( 'PLANT B: the query narrows by', array(
        array( $P, "                \$q['tax_query']   = isset( \$q['tax_query'] ) ? \$q['tax_query'] : array();\n                \$q['tax_query'][] = array( 'taxonomy' => \$tax,", "                \$q['tax_query']   = array();\n                \$q['tax_query'][] = array( 'taxonomy' => \$tax," ),
    ) ),
    'B: From is the first day only' => array( 'PLANT B: From compares the first day only', array(
        array( $P, "( meta_value >= %s OR post_id IN ( SELECT post_id FROM {\$wpdb->postmeta} WHERE meta_key = '_uc_end_date' AND meta_value >= %s ) )", "( meta_value >= %s OR %s = '' )" ),
    ) ),
    'B: the tab still holds the range' => array( 'PLANT B: a past date searched from Upcoming is still held to Upcoming', array(
        array( $P, "        \$view            = \$this->dated_view( \$view, \$filters['from'], \$filters['to'] );", "        \$view            = \$view;" ),
    ) ),
    'B: the tabs drop the series' => array( 'PLANT B: the', array(
        array( $P, "                \$carry = \$base;\n", "                \$carry = \$base;\n                unset( \$carry['series'], \$carry['org'] );\n" ),
    ) ),
    'B: the sort drops the organizer' => array( 'PLANT B: the sort drops', array(
        array( $P, "            'org'     => ! empty( \$filters['org'] ) ? \$filters['org'] : '',\n            'status'  => \$filters['status'],\n            'from'    => \$filters['from'],\n            'to'      => \$filters['to'],\n            // Sorting inside", "            'status'  => \$filters['status'],\n            'from'    => \$filters['from'],\n            'to'      => \$filters['to'],\n            // Sorting inside" ),
    ) ),
    'C: no way to all events' => array( 'PLANT C: an empty My events does not offer', array(
        array( $P, "                if ( ! \$ids && 'mine' === \$scope && \$asked ) {", "                if ( false ) {" ),
    ) ),
    'D: only the meta primed' => array( 'PLANT D: user_event_ids() primes only the meta', array(
        array( $P, "            if ( \$ids && function_exists( '_prime_post_caches' ) ) {\n                _prime_post_caches( \$ids, true, true );\n            } elseif", "            if ( false ) {\n            } elseif" ),
    ) ),
);

$missed = 0;
$only = isset( $argv[1] ) ? $argv[1] : '';
foreach ( $plants as $name => $p ) {
    if ( '' !== $only && false === strpos( $name, $only ) ) { continue; }
    list( $want, $edits ) = $p;
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
    $out = shell_exec( 'cd ' . escapeshellarg( $root ) . ' && php ' . escapeshellarg( __DIR__ . '/release-31250-test.php' ) . ' 2>&1' );
    foreach ( $saved as $sp => $orig ) { file_put_contents( $sp, $orig ); }
    $caught = ( false !== strpos( (string) $out, $want ) && false !== strpos( (string) $out, ' FAILED' ) );
    echo ( $caught ? 'caught  ' : 'MISSED  ' ) . $name . "\n";
    if ( ! $caught ) { $missed++; echo '        ' . str_replace( "\n", "\n        ", trim( substr( (string) $out, -1500 ) ) ) . "\n"; }
}
echo $missed ? "$missed PLANT(S) MISSED\n" : "every plant caught.\n";
exit( $missed ? 1 : 0 );
