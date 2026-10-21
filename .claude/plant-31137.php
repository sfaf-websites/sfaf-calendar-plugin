<?php
/**
 * THE 3.113.7 CHECKS, EACH MADE TO FAIL ON PURPOSE.
 *
 *     php .claude/plant-31137.php [part of a plant name]
 *
 * One fault per plant, written into the real file; the check is run, and every
 * file is put back from memory, not from git. An anchor that is not there, or
 * is there twice, stops the run. Commit first, and never run this while
 * run-all.sh is running.
 *
 * E is the proof that the embed is unchanged; 1 is the log a fetch writes, run
 * in everyaction-import-test.php's section 10; 2 is the panel; 2L the panel in
 * Chrome.
 */

$root = dirname( __DIR__ );

$plants = array(
    'E: the log\'s styles go into calendar.css' => array( 'embed-identity-test.php', 'B: public/css/calendar.css differs', array(
        array( 'public/css/calendar.css', "/* SFAF Calendar Frontend Styles */\n", "/* SFAF Calendar Frontend Styles */\n.uc-everyaction-log { margin: 0; }\n" ),
    ) ),
    'E: an embed answer changes' => array( 'embed-identity-test.php', 'A: ', array(
        array( 'includes/class-sfaf-embed.php', "\$response->header( 'Cache-Control', 'public, max-age=' . \$browser_ttl );", "\$response->header( 'Cache-Control', 'public, max-age=' . ( \$browser_ttl + 1 ) );" ),
    ) ),
    '1: the log is never written' => array( 'everyaction-import-test.php', '10: one log row per row fetched', array(
        array( 'includes/class-sfaf-source-everyaction.php', "        SFAF_EveryAction::record_log( \$result, \$this->raw );\n", '' ),
    ) ),
    '1: trashed is logged as dismissed' => array( 'everyaction-import-test.php', '10: a trashed row', array(
        array( 'includes/class-sfaf-sources.php', "\$kind = ( 'trash' === \$status ) ? 'trashed' : 'dismissed';", "\$kind = 'dismissed';" ),
    ) ),
    '1: a row deleted for good leaves no line' => array( 'everyaction-import-test.php', '10: a row deleted here for good', array(
        array( 'includes/class-sfaf-sources.php', "                \$log( 'deleted_skip', \$event );\n", '' ),
    ) ),
    '1: the tracker\'s repeats are dropped unseen' => array( 'everyaction-import-test.php', '10: the second is a duplicate', array(
        array( 'includes/class-sfaf-everyaction.php', "            \$out['repeated'] = array_merge( \$out['repeated'], \$again );\n", '' ),
        array( 'includes/class-sfaf-everyaction.php', "                if ( ! ( 1 === \$page && 0 === \$new ) ) {\n                    \$out['repeated'] = array_merge( \$out['repeated'], \$again );\n                }\n", '' ),
    ) ),
    '1: a refusal loses its reason' => array( 'everyaction-import-test.php', '10: a past row is refused, and why', array(
        array( 'includes/class-sfaf-sources.php', "                \$log( 'refused', \$event, \$refusal );", "                \$log( 'refused', \$event );" ),
    ) ),
    '1: a row is kept only once it is read' => array( 'everyaction-import-test.php', '10: a Start_Time it cannot read', array(
        array( 'includes/class-sfaf-source-everyaction.php', "        if ( '' === \$uuid || ! \$start ) {\n            return null;\n        }", "        if ( '' === \$uuid || ! \$start ) {\n            array_pop( \$this->raw );\n            return null;\n        }" ),
    ) ),
    '1: the times are logged as read, not received' => array( 'everyaction-import-test.php', '10: the times as received', array(
        array( 'includes/class-sfaf-source-everyaction.php', "            'start_raw'   => self::field( \$item, 'Start_Time' ),", "            'start_raw'   => \$start ? \$start->format( 'm/d/Y h:i A' ) : ''," ),
    ) ),
    '2: the line says only what moved' => array( 'release-31137-test.php', '1: the Last fetch line counts the silent outcomes', array(
        array( 'includes/class-sfaf-everyaction.php', "            if ( \$n > 0 ) {\n                \$line .= ', ' . sprintf( 1 === \$n ? \$say[0] : \$say[1], \$n );", "            if ( false ) {\n                \$line .= ', ' . sprintf( 1 === \$n ? \$say[0] : \$say[1], \$n );" ),
    ) ),
    '2: a title from the tracker is printed raw' => array( 'release-31137-test.php', '2: a title from the tracker is escaped', array(
        array( 'admin/class-sfaf-admin.php', "<td><?php echo esc_html( \$ea_row['title'] ); ?></td>", "<td><?php echo \$ea_row['title']; ?></td>" ),
    ) ),
    '2: the log is autoloaded' => array( 'release-31137-test.php', '3: the log is stored without autoload', array(
        array( 'includes/class-sfaf-everyaction.php', "            'rows'  => \$rows,\n        ), false );", "            'rows'  => \$rows,\n        ) );" ),
    ) ),
    '2L: the log is open on arrival' => array( 'release-31137-live.php', 'the log is not folded', array(
        array( 'admin/class-sfaf-admin.php', '<details class="uc-everyaction-log">', '<details class="uc-everyaction-log" open>' ),
    ) ),
    '2L: the table is not boxed' => array( 'release-31137-live.php', 'scroll', array(
        array( 'admin/css/admin.css', '.uc-everyaction-log-scroll { max-height: 480px; overflow: auto;', '.uc-everyaction-log-scroll { max-height: none; overflow: visible;' ),
    ) ),
    '2L: the outcome is the last column' => array( 'release-31137-live.php', 'the outcome is not in view', array(
        array( 'admin/css/admin.css', '.uc-everyaction-log td:nth-child(2) { white-space: normal; min-width: 14em; }', '.uc-everyaction-log td:nth-child(2) { white-space: nowrap; }' ),
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
