<?php
/**
 * THE 3.111.0 CHECKS, EACH MADE TO FAIL ON PURPOSE.
 *
 *     php .claude/plant-31110.php [part of a plant name]
 *
 * A.1 a cancelled event still pinned; B a count in the embed payload; C.3 an
 * editor outside the review team approving; E.1 a non-PDF accepted; F.3 a
 * migrated value lost; G.2 the field still rendered; and in the browser, A.1
 * the Featured mark not drawn.
 *
 * Each replacement is written into the real file, the check is run, and every
 * file is put back from memory, not from git. An anchor that is not there, or
 * is there twice, stops the run. Never run this while run-all.sh is running.
 */

$root = dirname( __DIR__ );
$unit = 'release-31110-test.php';
$live = 'release-31110-live.php --run';

$plants = array(
    'A.1: a cancelled event still pinned' => array( $unit, 'PLANT A.1', array(
        array( 'includes/class-sfaf-shortcodes.php', 'if ( SFAF_Privacy::is_private( $id ) || SFAF_Cancellation::is_cancelled( $id ) ) {', 'if ( SFAF_Privacy::is_private( $id ) ) {' ),
    ) ),
    'B: a count in the embed payload' => array( $unit, 'PLANT B', array(
        array( 'includes/sfaf-template-functions.php', "    return sfaf_event_full( \$post_id ) ? 'Full' : '';", "    return sfaf_get_rsvp_count( \$post_id ) . ' of ' . sfaf_event_capacity( \$post_id ) . ' spots taken';" ),
    ) ),
    'C.3: an editor outside the review team approving' => array( $unit, 'PLANT C.3', array(
        array( 'includes/class-sfaf-submissions.php', "        return \$uid > 0 && in_array( \$uid, self::reviewers( (int) \$event_id ), true );", "        return \$uid > 0 && 'editor' === SFAF_Portal::get_role( \$uid );" ),
    ) ),
    'E.1: a non-PDF accepted' => array( $unit, 'PLANT E.1', array(
        array( 'includes/class-sfaf-uploads.php', "        if ( '%PDF-' !== (string) @file_get_contents( \$path, false, null, 0, 5 ) ) {", "        if ( false ) {" ),
    ) ),
    'F.3: a migrated value lost' => array( $unit, 'PLANT F.3', array(
        array( 'sfaf-calendar.php', "        update_post_meta( \$id, '_uc_contact_name', \$old );\n        if ( \$old === (string) get_post_meta( \$id, '_uc_contact_name', true ) ) {", "        if ( true ) {" ),
    ) ),
    'G.2: the field still rendered' => array( $unit, 'PLANT G.2', array(
        array( 'includes/class-sfaf-submissions.php', "            <input type=\"file\" name=\"uc_image\" accept=\"<?php echo esc_attr( SFAF_Uploads::picture_accept() ); ?>\" />\n", "            <input type=\"file\" name=\"uc_image\" accept=\"<?php echo esc_attr( SFAF_Uploads::picture_accept() ); ?>\" />\n            <span class=\"uc-field-label\">Any other pictures</span><input type=\"file\" name=\"uc_image_extra_1\" />\n" ),
    ) ),
    'A.1 live: the Featured mark not drawn' => array( $live, 'PLANT A.1 LIVE', array(
        array( 'public/css/calendar.css', ".uc-featured-mark {\n    display: block;", ".uc-featured-mark {\n    display: none;" ),
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
