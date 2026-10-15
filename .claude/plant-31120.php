<?php
/**
 * THE 3.112.0 CHECKS, EACH MADE TO FAIL ON PURPOSE.
 *
 *     php .claude/plant-31120.php [part of a plant name]
 *
 * A.3 a retired tag in the filter; B a venue dropped from See all events; C.2
 * a day in the range missing from the grid; D.1 a Keep link acting on GET; E
 * a draft of another person's in Drafts; and in the browser, G the keepalive
 * firing with no activity.
 *
 * Each replacement is written into the real file, the check is run, and every
 * file is put back from memory, not from git. An anchor that is not there, or
 * is there twice, stops the run. Never run this while run-all.sh is running.
 */

$root = dirname( __DIR__ );
$unit = 'release-31120-test.php';
$live = 'release-31120-live.php --run';

$plants = array(
    'A.3: a retired tag in the filter' => array( $unit, 'PLANT A.3', array(
        array( 'includes/class-sfaf-shortcodes.php', "        \$active = wp_list_pluck( SFAF_Tags::active(), 'slug' );", "        \$active = wp_list_pluck( SFAF_Tags::all(), 'slug' );" ),
        array( 'includes/class-sfaf-shortcodes.php', "        \$tags = SFAF_Tags::active();\n        if ( '' !== \$scope_tag ) {", "        \$tags = SFAF_Tags::all();\n        if ( '' !== \$scope_tag ) {" ),
    ) ),
    'B: a venue dropped from See all events' => array( $unit, 'PLANT B', array(
        array( 'includes/class-sfaf-shortcodes.php', "                'uc_loc'   => \$this->see_all_locations( \$filters ),\n", '' ),
    ) ),
    'C.2: a day in the range missing from the grid' => array( $unit, 'PLANT C.2', array(
        array( 'includes/class-sfaf-shortcodes.php', "            foreach ( sfaf_event_days( \$pid ) as \$day ) {\n                if ( \$day < \$grid['start']", "            foreach ( array_slice( sfaf_event_days( \$pid ), 0, 1 ) as \$day ) {\n                if ( \$day < \$grid['start']" ),
    ) ),
    'D.1: a Keep link acting on GET' => array( $unit, 'PLANT D.1', array(
        array( 'includes/class-sfaf-portal.php', "        \$item = SFAF_Closure_Review::item( \$event_id, \$closure_id );\n        \$this->chrome_open( \$user, 'pending' );", "        \$item = SFAF_Closure_Review::item( \$event_id, \$closure_id );\n        if ( \$item && 'keep' === \$do ) { SFAF_Closure_Review::keep( \$event_id, \$closure_id ); }\n        \$this->chrome_open( \$user, 'pending' );" ),
    ) ),
    'E: a draft of another person\'s in Drafts' => array( $unit, 'PLANT E', array(
        array( 'includes/class-sfaf-portal.php', "            \$q['author']      = \$user->ID;\n            \$q['post_status'] = \$own_tabs[ \$scope ]['status'];", "            \$q['post_status'] = \$own_tabs[ \$scope ]['status'];" ),
    ) ),
    'G: keepalive firing with no activity' => array( $live, 'PLANT G', array(
        array( 'public/js/caladmin-session.js', "        if (Date.now() - lastActive > ACTIVE_MS) {\n            return;\n        }\n", '' ),
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
            echo "ANCHOR NOT UNIQUE for \"$name\" in $file\n";
            exit( 1 );
        }
        file_put_contents( $path, str_replace( $from, $to, $now ) );
    }
    $out = shell_exec( 'php ' . escapeshellarg( __DIR__ . '/' . strtok( $check, ' ' ) ) . ( false !== strpos( $check, '--run' ) ? ' --run' : '' ) . ' 2>&1' );
    foreach ( $saved as $sp => $orig ) { file_put_contents( $sp, $orig ); }
    $caught = ( false !== strpos( (string) $out, $want ) );
    echo ( $caught ? 'caught  ' : 'MISSED  ' ) . $name . "\n";
    if ( ! $caught ) { $missed++; echo '        ' . str_replace( "\n", "\n        ", trim( (string) $out ) ) . "\n"; }
}
echo $missed ? "$missed PLANT(S) MISSED\n" : "every plant caught.\n";
exit( $missed ? 1 : 0 );
