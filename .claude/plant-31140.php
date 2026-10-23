<?php
/**
 * THE 3.114.0 CHECKS, EACH MADE TO FAIL ON PURPOSE.
 *
 *     php .claude/plant-31140.php [part of a plant name]
 *
 * One fault per plant, written into the real file; the check is run, and every
 * file is put back from memory, not from git. An anchor that is not there, or
 * is there twice, stops the run. Commit first, and never run this while
 * run-all.sh is running.
 */

$root = dirname( __DIR__ );

$plants = array(
    'A: the Location box posts again' => array( 'release-31140-test.php', 'A: and posts nothing', array(
        array( 'includes/class-sfaf-post-types.php', '<input type="text" id="uc_location" value="<?php echo esc_attr( $location ); ?>" readonly />', '<input type="text" id="uc_location" name="uc_location" value="<?php echo esc_attr( $location ); ?>" />' ),
        array( 'includes/class-sfaf-post-types.php', "            'uc_capacity'    => '_uc_capacity',\n", "            'uc_location'    => '_uc_location',\n            'uc_capacity'    => '_uc_capacity',\n" ),
    ) ),
    'A: the repair strips a name inside a word' => array( 'release-31140-test.php', 'A: "Strut Hall, 1 Main" under "Strut"', array(
        array( 'sfaf-calendar.php', "        if ( \$lower( \$head ) !== \$lower( \$name . ',' ) ) {", "        if ( \$lower( substr( \$line, 0, strlen( \$name ) ) ) !== \$lower( \$name ) ) {" ),
    ) ),
    'A: the repair is not run' => array( 'release-31140-test.php', 'A: the installer runs the pass', array(
        array( 'sfaf-calendar.php', "    sfaf_migrate_location_name_repeat();\n\n    update_option( 'sfaf_db_version', SFAF_DB_VERSION );", "\n    update_option( 'sfaf_db_version', SFAF_DB_VERSION );" ),
    ) ),
    'B: FAQ answers stripped again' => array( 'release-31140-test.php', 'B: the answer keeps its link', array(
        array( 'includes/class-sfaf-post-types.php', "trim( SFAF_Rich_Text::sanitize( (string) \$row['answer'] ) )", "sanitize_textarea_field( \$row['answer'] )" ),
    ) ),
    'C: editors stay in the WordPress editor' => array( 'release-31140-test.php', 'C: post.php sends an editor to caladmin', array(
        array( 'includes/class-sfaf-post-types.php', "        return current_user_can( 'manage_options' );", "        return current_user_can( 'edit_posts' );" ),
    ) ),
    'C: the REST route lets editors write' => array( 'release-31140-test.php', 'C: a REST write is refused for an editor', array(
        array( 'includes/class-sfaf-post-types.php', "        if ( null !== \$result || 'GET' === \$request->get_method() || self::wp_editor_allowed() ) {", "        if ( true ) {" ),
    ) ),
    'D: the summary only, as before' => array( 'release-31140-test.php', 'D: the full description, and the summary dropped', array(
        array( 'includes/class-sfaf-source-eventbrite.php', "        \$body    = isset( \$item['structured_html'] ) ? trim( (string) \$item['structured_html'] ) : '';", "        \$body    = '';" ),
    ) ),
    'D: the summary always leads' => array( 'release-31140-test.php', 'D: the full description, and the summary dropped', array(
        array( 'includes/class-sfaf-source-eventbrite.php', "        if ( '' !== \$summary && ! self::opens_with( \$body, \$summary ) ) {", "        if ( '' !== \$summary ) {" ),
    ) ),
    'E: a person\'s edit is overwritten' => array( 'release-31140-test.php', 'E: an edit after that is kept', array(
        array( 'includes/class-sfaf-sources.php', "        if ( '' !== trim( \$description ) && self::description_edited( \$post, \$event ) ) {", "        if ( false ) {" ),
    ) ),
    'E: an edit from before is taken for the fetch\'s' => array( 'release-31140-test.php', 'E: an edit from before 3.114.0 is kept', array(
        array( 'includes/class-sfaf-sources.php', "        \$mine = \$text( \$now );\n        if ( '' === \$mine ) {", "        \$mine = \$text( \$now );\n        if ( true ) {" ),
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
    $args = ( false !== strpos( $check, '-live' ) ) ? ' --run' : ' --verbose';
    $out = shell_exec( 'cd ' . escapeshellarg( $root ) . ' && php ' . escapeshellarg( __DIR__ . '/' . $check ) . $args . ' 2>&1' );
    foreach ( $saved as $sp => $orig ) { file_put_contents( $sp, $orig ); }
    $caught = ( false !== strpos( (string) $out, $want ) );
    echo ( $caught ? 'caught  ' : 'MISSED  ' ) . $name . "\n";
    if ( ! $caught ) { $missed++; echo '        ' . str_replace( "\n", "\n        ", trim( substr( (string) $out, -1500 ) ) ) . "\n"; }
}
echo $missed ? "$missed PLANT(S) MISSED\n" : "every plant caught.\n";
exit( $missed ? 1 : 0 );
