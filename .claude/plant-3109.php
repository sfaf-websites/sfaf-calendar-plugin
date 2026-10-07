<?php
/**
 * THE 3.109.0 CHECKS, EACH MADE TO FAIL ON PURPOSE.
 *
 *     php .claude/plant-3109.php [part of a plant name]
 *
 * A.2 a private event made on Add event public for its first write; A the tick
 * out of the Display card; B.3 a control on one editor only, an Edit-only
 * control that is not a saved-event action, and a marker CLAUDE.md does not
 * name; C.1 the page after Send telling who has an account; C.2 a wrong
 * current password accepted.
 *
 * A plant is one or more replacements, each written into the real file; the
 * check is run and every file is put back from memory, not from git, so an
 * uncommitted change cannot be lost. A replacement whose anchor is not there,
 * or is there twice, stops the run: a plant that did not apply proves nothing.
 * Never run this while run-all.sh is running.
 */

$root   = dirname( __DIR__ );
$php    = 'includes/class-sfaf-portal.php';
$first  = 'private-first-save-test.php';
$parity = 'notifications-card-test.php';
$pw     = 'password-pages-test.php';
$live   = 'release-3109-live.php --run';

$plants = array(
    'A.2: the insert is public, made private a moment later' => array( $first, 'PLANT A.2', array(
        array( $php, "                \$postarr = array_merge( \$postarr, SFAF_Privacy::born_private_args(", "                \$postarr = array_merge( \$postarr, array() ); // SFAF_Privacy::born_private_args(" ),
    ) ),
    'A: the tick is not in the Display card' => array( $live, 'PLANT A', array(
        array( $php, "                    <?php \$this->render_private_control( (int) \$event_id, '' !== \$prov['source'], false ); ?>", '' ),
    ) ),
    'B.3: the tick on Edit event only' => array( $parity, 'PLANT B.3', array(
        array( $php, "        \$is_private = \$event_id ? SFAF_Privacy::is_private( \$event_id ) : false;", "        \$is_private = \$event_id ? SFAF_Privacy::is_private( \$event_id ) : false;\n        if ( ! \$event_id ) { return; }" ),
    ) ),
    'B.3: Cancel event loses its saved-action mark' => array( $parity, 'PLANT B.3', array(
        array( $php, '        <details class="uc-cancel-inline" id="uc-cancel-this" data-uc-saved-action="cancel" data-uc-disclosure<?php', '        <details class="uc-cancel-inline" id="uc-cancel-this" data-uc-disclosure<?php' ),
    ) ),
    'B.3: a setting marked as a saved-event action' => array( $parity, 'PLANT B.3', array(
        array( $php, '<div class="uc-field uc-private-field" data-uc-private>', '<div class="uc-field uc-private-field" data-uc-private data-uc-saved-action="private">' ),
    ) ),
    'C.1: the page after Send depends on the address' => array( $pw, 'PLANT C.1', array(
        array( $php, "        \$this->redirect( 'forgot', array( 'sent' => 1 ) );", "        \$this->redirect( 'forgot', array( 'sent' => \$found ? 1 : 2 ) );" ),
    ) ),
    'C.2: a wrong current password is accepted' => array( $pw, 'PLANT C.2', array(
        array( $php, "                if ( ! \$stored || ! wp_check_password( \$pw_now, \$stored->user_pass, \$user->ID ) ) {", "                if ( ! \$stored ) {" ),
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
