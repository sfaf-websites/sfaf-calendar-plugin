<?php
/**
 * THE 3.123.0 CHECK, MADE TO FAIL ON PURPOSE.
 *
 *     php .claude/plant-31230.php
 *
 * Puts one fault in at a time and runs release-31230-live.php --run, which
 * must fail with the line named. Every file is put back from memory. Commit
 * first, and never run this while run-all.sh is running.
 */

$root = dirname( __DIR__ );
$P    = 'includes/class-sfaf-portal.php';
$C    = 'public/css/portal.css';

$plants = array(
    'A: Back is the text arrow again' => array( 'PLANT A: ', array(
        array( $P, "class=\"uc-btn uc-back-btn\"><?php echo sfaf_icon( 'chevron-left', array( 'size' => '16px' ) ); ?>Back</a>", "class=\"uc-btn uc-back-btn\">&larr; Back</a>" ),
    ) ),
    'A: the chevron is drawn smaller' => array( 'is not the 16px icon at stroke 2', array(
        array( $P, "sfaf_icon( 'chevron-left', array( 'size' => '16px' ) )", "sfaf_icon( 'chevron-left', array( 'size' => '12px' ) )" ),
    ) ),
    'B: View RSVPs is a text link again' => array( 'is not drawn as View event page is', array(
        array( $P, "<a class=\"uc-btn\" href=\"<?php echo esc_url( add_query_arg( 'event_id', \$event_id, \$this->url( 'rsvps' ) ) ); ?>\"", "<a class=\"uc-tour-link\" href=\"<?php echo esc_url( add_query_arg( 'event_id', \$event_id, \$this->url( 'rsvps' ) ) ); ?>\"" ),
    ) ),
    'B: the count is not there' => array( 'the RSVP button reads', array(
        array( $P, 'View RSVPs (<?php echo (int) sfaf_get_rsvp_count( $event_id ); ?>)', 'View RSVPs' ),
    ) ),
    'C: the phone header is the old wrap' => array( 'PLANT C: pub-390', array(
        array( $C, '    .uc-editor-head .uc-page-head-actions { display: contents; }', '' ),
    ) ),
    'C: the page buttons wrap' => array( 'the page\'s buttons wrap', array(
        array( $C, '    .uc-editor-head .uc-page-links { grid-row: 2; grid-column: 1 / -1; display: flex; flex-wrap: nowrap; }', '    .uc-editor-head .uc-page-links { grid-row: 2; grid-column: 1 / -1; display: flex; flex-wrap: wrap; }' ),
        array( $C, '    .uc-editor-head .uc-page-links .uc-btn { flex: 1 1 auto; justify-content: center; min-width: 0; padding-left: 10px; padding-right: 10px; white-space: nowrap; }', '    .uc-editor-head .uc-page-links .uc-btn { flex: 0 0 auto; padding-left: 24px; padding-right: 24px; }' ),
    ) ),
    'C: Copied pushes the buttons' => array( 'showing Copied moved the header\'s buttons', array(
        array( $C, '.uc-page-head .uc-page-links .uc-copy-said { position: absolute; top: 100%; right: 0; margin-top: 2px; white-space: nowrap; }', '' ),
    ) ),
    'C: the copy icon is 32px on a phone' => array( 'is under 44px', array(
        array( $C, '    .uc-questions .uc-icon-btn, .uc-page-head .uc-icon-btn { min-width: 44px; }', '    .uc-questions .uc-icon-btn { min-width: 44px; }' ),
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
    $out = shell_exec( 'cd ' . escapeshellarg( $root ) . ' && php ' . escapeshellarg( __DIR__ . '/release-31230-live.php' ) . ' --run 2>&1' );
    foreach ( $saved as $sp => $orig ) { file_put_contents( $sp, $orig ); }
    $caught = ( false !== strpos( (string) $out, $want ) && false !== strpos( (string) $out, ' failed:' ) );
    echo ( $caught ? 'caught  ' : 'MISSED  ' ) . $name . "\n";
    if ( ! $caught ) { $missed++; echo '        ' . str_replace( "\n", "\n        ", trim( substr( (string) $out, -1500 ) ) ) . "\n"; }
}
echo $missed ? "$missed PLANT(S) MISSED\n" : "every plant caught.\n";
exit( $missed ? 1 : 0 );
