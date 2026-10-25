<?php
/**
 * THE 3.120.0 CHECKS, EACH MADE TO FAIL ON PURPOSE.
 *
 *     php .claude/plant-31200.php [part of a plant name]
 *
 * One fault per plant, written into the real file; the check is run, and every
 * file is put back from memory, not from git. An anchor that is not there, or
 * is there twice, stops the run. Commit first, and never run this while
 * run-all.sh is running.
 */

$root = dirname( __DIR__ );

$plants = array(
    'A: the map link names the place again' => array( 'release-31200-test.php', 'A: the map link shows the address alone', array(
        array( 'templates/single-uc_event.php', "<?php \$addr_line = sfaf_event_address_line( \$post_id ); echo esc_html( '' !== \$addr_line ? \$addr_line : \$location ); ?></a></span>", "<?php echo esc_html( \$location ); ?></a></span>" ),
    ) ),
    'B: the second register link is back' => array( 'release-31200-test.php', 'B: no second Register for this event link', array(
        array( 'templates/single-uc_event.php', "                            \$contact = sfaf_event_public_contact( \$post_id );\n", "                            \$contact = sfaf_event_public_contact( \$post_id );\n                            echo '<a href=\"' . esc_url( sfaf_event_rsvp_url( \$post_id ) ) . '\">Register for this event</a>';\n" ),
    ) ),
    'C: Spanish events stay English' => array( 'release-31200-test.php', 'C: the Spanish page reads', array(
        array( 'includes/sfaf-template-functions.php', "    if ( \$post_id && \$post_id === sfaf_bilingual_page() && 'es' === sfaf_event_language( \$post_id ) && \$en !== \$es ) {", "    if ( false ) {" ),
    ) ),
    'C: the page never clears its event' => array( 'release-31200-test.php', 'C: the page clears its event at the end', array(
        array( 'templates/single-uc_event.php', "    sfaf_bilingual_page( 0 );\nendwhile;\n", "endwhile;\n" ),
    ) ),
    'D: cards go bilingual too' => array( 'release-31200-test.php', 'D: a card says English for the same Spanish event', array(
        array( 'includes/sfaf-template-functions.php', "    if ( \$post_id && \$post_id === sfaf_bilingual_page() && 'es' === sfaf_event_language( \$post_id ) && \$en !== \$es ) {", "    if ( \$post_id && 'es' === sfaf_event_language( \$post_id ) && \$en !== \$es ) {" ),
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
