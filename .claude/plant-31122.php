<?php
/**
 * THE 3.112.2 CHECKS, EACH MADE TO FAIL ON PURPOSE.
 *
 *     php .claude/plant-31122.php [part of a plant name]
 *
 * Each of the 3.112.2 fixes put back, one at a time, into the real file; the
 * check is run, and every file is put back from memory, not from git. Commit
 * first, and never run this while run-all.sh is running.
 */

$root = dirname( __DIR__ );

$plants = array(
    '1: the Location row reads each event one at a time again' => array( 'embed-budget-test.php', 'a block that asks for every row took', array(
        array( 'includes/class-sfaf-shortcodes.php', "        if ( \$ids ) {\n            update_meta_cache( 'post', \$ids );\n            update_object_term_cache( \$ids, 'uc_event' );\n        }\n        foreach ( \$ids as \$id ) {\n            \$key = self::location_key_for_event( \$id );", "        foreach ( \$ids as \$id ) {\n            \$key = self::location_key_for_event( \$id );" ),
    ) ),
    '1: the Location index is not kept' => array( 'embed-budget-test.php', 'the Location index is not kept', array(
        array( 'includes/class-sfaf-shortcodes.php', "        set_transient( \$key, \$index, DAY_IN_SECONDS );\n", '' ),
    ) ),
    '2: a block with no filters attribute shows every row again' => array( 'embed-budget-test.php', 'B: a block with no filters attribute shows Tags or Location', array(
        array( 'includes/class-sfaf-shortcodes.php', "                \$rows[ \$key ] = \$all && in_array( \$key, self::filter_rows_default(), true );", "                \$rows[ \$key ] = \$all;" ),
    ) ),
    '3: the loader asks the embed route again' => array( 'embed-budget-test.php', 'D: the loader asks PHP something again', array(
        array( 'public/js/embed.js', "    var started = false;\n", "    var started = false;\n    var xhr = new XMLHttpRequest();\n" ),
    ) ),
    '3: the version file without a bucket' => array( 'embed-filters-test.php', 'the loader does not read embed-version.js under a bucket', array(
        array( 'public/js/embed.js', "    addScript(beside('embed-version.js') + '?t=' + bucket, null, start);", "    addScript(beside('embed-version.js'), null, start);" ),
    ) ),
    '3: embed-version.js names another release' => array( 'embed-filters-test.php', 'embed-version.js does not name the release', array(
        array( 'public/js/embed-version.js', "window.sfafCalendarEmbedVersion = '", "window.sfafCalendarEmbedVersion = '0." ),
    ) ),
    '4: the term flush on every write again' => array( 'cache-flush-test.php', 'the embed cache moved on a term write that changed nothing', array(
        array( 'includes/class-sfaf-embed.php', "        if ( \$now === \$was ) {\n            return;\n        }\n        \$this->flush_cache();", "        \$this->flush_cache();" ),
    ) ),
    '5: the closed-day task reads each event one at a time again' => array( 'embed-budget-test.php', 'the closed-day task took', array(
        array( 'includes/class-sfaf-closure-review.php', "        if ( \$q->posts ) {\n            update_meta_cache( 'post', array_map( 'intval', (array) \$q->posts ) );\n        }\n", '' ),
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
    $out = shell_exec( 'php ' . escapeshellarg( __DIR__ . '/' . $check ) . ' 2>&1' );
    foreach ( $saved as $sp => $orig ) { file_put_contents( $sp, $orig ); }
    $caught = ( false !== strpos( (string) $out, $want ) );
    echo ( $caught ? 'caught  ' : 'MISSED  ' ) . $name . "\n";
    if ( ! $caught ) { $missed++; echo '        ' . str_replace( "\n", "\n        ", trim( substr( (string) $out, -1200 ) ) ) . "\n"; }
}
echo $missed ? "$missed PLANT(S) MISSED\n" : "every plant caught.\n";
exit( $missed ? 1 : 0 );
