<?php
/**
 * THE 3.117.0 CHECKS, EACH MADE TO FAIL ON PURPOSE.
 *
 *     php .claude/plant-31170.php [part of a plant name]
 *
 * One fault per plant, written into the real file; the check is run, and every
 * file is put back from memory, not from git. An anchor that is not there, or
 * is there twice, stops the run. Commit first, and never run this while
 * run-all.sh is running.
 */

$root = dirname( __DIR__ );

$plants = array(
    'A: the box filled with the fallback again' => array( 'release-31170-test.php', 'A: with no address of its own the box is empty', array(
        array( 'includes/class-sfaf-portal.php', '                           value="<?php echo esc_attr( $reply_current ); ?>"', '                           value="<?php echo esc_attr( \'\' !== $reply_current ? $reply_current : $reply_resolved ); ?>"' ),
    ) ),
    'B: Follow shown with no series' => array( 'release-31170-test.php', 'B: Follow the series is hidden on an event in no series', array(
        array( 'includes/class-sfaf-portal.php', "' data-uc-follow-check' . ( (int) \$cur_series ? '' : ' hidden' )", "' data-uc-follow-check'" ),
    ) ),
    'C: two green buttons again' => array( 'release-31170-test.php', "C: a scheduled event's Save changes is plain", array(
        array( 'includes/class-sfaf-portal.php', "                if ( \$keep_status && 'future' === \$live && \$show_publish ) {\n                    \$save_main = '';", "                if ( false ) {\n                    \$save_main = '';" ),
    ) ),
    'D: Organizer again' => array( 'release-31170-test.php', 'D: Organizers', array(
        array( 'includes/class-sfaf-portal.php', '<span class="uc-field-label">Organizers<?php echo $this->publish_mark( \'organizer\'', '<span class="uc-field-label">Organizer<?php echo $this->publish_mark( \'organizer\'' ),
    ) ),
    'L: the script does not follow the dropdown' => array( 'release-31170-live.php', 'choosing a series does not show Follow the series', array(
        array( 'public/js/portal.js', "        select.addEventListener('change', apply);\n        apply();\n    }\n    function initRegisterWhere", "        apply();\n    }\n    function initRegisterWhere" ),
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
