<?php
/**
 * THE 3.127.0 CHECKS, MADE TO FAIL ON PURPOSE.
 *
 *     php .claude/plant-31270.php
 *
 * Puts back, one at a time, what 3.127.0 changed, and runs the check that must
 * catch it. Every file is put back from memory. Commit first, and never run
 * this while run-all.sh is running.
 */

$root = dirname( __DIR__ );

$plants = array(
    'flush_cache() no longer tells LiteSpeed' => array( 'release-31270-test.php', 'a closure added: the tags LiteSpeed drops', array(
        array( 'includes/class-sfaf-embed.php', "        SFAF_LiteSpeed::purge_embed();\n", '' ),
    ) ),
    'a save no longer names the event\'s page' => array( 'release-31270-test.php', 'a save that changes only the title: the tags', array(
        array( 'includes/class-sfaf-embed.php', "        SFAF_LiteSpeed::purge_event( \$post_id );\n        \$this->flush_cache();\n    }\n\n    /** Flush when an event is deleted for good. */", "        \$this->flush_cache();\n    }\n\n    /** Flush when an event is deleted for good. */" ),
    ) ),
    'back to draft no longer purges the page it leaves' => array( 'release-31270-test.php', 'back to draft: the tags', array(
        array( 'includes/class-sfaf-embed.php', "SFAF_LiteSpeed::purge_event( \$post->ID, 'publish' === \$old_status );", "SFAF_LiteSpeed::purge_event( \$post->ID );" ),
    ) ),
    'every tag is sent again on every flush' => array( 'release-31270-test.php', 'never twice for one', array(
        array( 'includes/class-sfaf-litespeed.php', "            if ( ! isset( self::\$sent[ \$t ] ) ) {", "            if ( true ) {" ),
    ) ),
    'the event page through litespeed_purge_post' => array( 'release-31270-test.php', 'never litespeed_purge_post', array(
        array( 'includes/class-sfaf-litespeed.php', "        self::send( array( self::TAG_POST . \$post_id ) );", "        do_action( 'litespeed_purge_post', \$post_id );" ),
    ) ),
    'cancelling is not watched again' => array( 'release-31270-test.php', 'cancel, stay on the calendar: the embed cache moved', array(
        array( 'includes/class-sfaf-embed.php', "                SFAF_Cancellation::META, SFAF_Cancellation::VISIBILITY_META,\n", '' ),
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
