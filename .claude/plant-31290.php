<?php
/**
 * THE 3.129.0 CHECKS, MADE TO FAIL ON PURPOSE.
 *
 *     php .claude/plant-31290.php
 *
 * Puts back, one at a time, what 3.129.0 changed, and runs the check that must
 * catch it. Every file is put back from memory. Commit first, and never run
 * this while run-all.sh is running.
 */

$root = dirname( __DIR__ );
$P    = 'includes/class-sfaf-portal.php';
$J    = 'public/js/portal.js';
$T    = 'release-31290-test.php';
$L    = 'release-31290-live.php';

$plants = array(
    'the creator does not start as a chip' => array( $T, 'PLANT A: the chips are', array(
        array( $P, "            if ( \$author_on ) { \$chips[] = \$box; } else { \$pool[] = \$box; }\n", "            \$pool[] = \$box;\n" ),
    ) ),
    'a chosen person who left the calendar is dropped' => array( $T, 'B: and keeps Dee', array(
        array( $P, "                if ( \$u ) { \$people[ \$uid ] = \$u; }\n", '' ),
    ) ),
    'an unticked box in the fold is posted' => array( $T, 'PLANT A: the fold holds', array(
        array( $P, "            if ( in_array( \$uid, \$chosen_users, true ) ) { \$chips[] = \$box; } else { \$pool[] = \$box; }", "            \$chips[] = \$box;" ),
    ) ),
    'the always-sent emails get boxes again' => array( $T, 'PLANT C: the emails read', array(
        array( $P, "                <?php foreach ( SFAF_Notifications::kinds() as \$key => \$kind ) : if ( SFAF_Notifications::is_always( \$key ) ) { continue; } ?>", "                <?php foreach ( SFAF_Notifications::kinds() as \$key => \$kind ) : ?>" ),
    ) ),
    'the Replies heading is back' => array( $T, 'PLANT D: a Replies heading is still drawn', array(
        array( $P, "                <div class=\"uc-field uc-replyto-field\" data-uc-replyto>\n", "                <h4 class=\"uc-notify-subhead\">Replies</h4>\n                <div class=\"uc-field uc-replyto-field\" data-uc-replyto>\n" ),
    ) ),
    'the line under Replies go to is reworded' => array( $T, 'PLANT D: the line under it is', array(
        array( $P, 'Email replies come here. Anyone who replies to a text or WhatsApp is asked to email this address.', 'Replies come here. Anyone who replies to a text is asked to email this address.' ),
    ) ),
    'choosing a person saves nothing' => array( $T, 'PLANT D: choosing Ben stores his address', array(
        array( $P, "                : \$reply_pick;\n", "                : '';\n" ),
    ) ),
    'the creator is not first' => array( $T, 'PLANT D: the options are', array(
        array( $P, "                    \$reply_options[''] = \$reply_creator->display_name . ' (creator), ' . \$reply_creator->user_email;", "                    \$reply_options['zz'] = 'x'; \$reply_options[''] = \$reply_creator->display_name . ' (creator), ' . \$reply_creator->user_email;" ),
    ) ),
    'the fold stays beside the box' => array( $L, 'PLANT L:', array(
        array( $J, "            if (more) { more.hidden = true; }\n", '' ),
    ) ),
    'a chip pressed stays on' => array( $L, 'PLANT L:', array(
        array( $J, "                } else {\n                    pool.appendChild(label);\n                }\n", "                }\n" ),
    ) ),
    'Enter does not choose' => array( $L, 'PLANT L:', array(
        array( $J, "                if (!list.hidden && active >= 0 && shown[active]) { shown[active].pick(); }", "                if (false) { shown[active].pick(); }" ),
    ) ),
    'the reply picker is not searchable' => array( $L, 'PLANT R:', array(
        array( $J, "            select.hidden = true;\n            // The label now names the box somebody types in.", "            return;\n            // The label now names the box somebody types in." ),
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
    $args = ( false !== strpos( $check, '-live' ) ) ? ' --run' : '';
    $out = shell_exec( 'cd ' . escapeshellarg( $root ) . ' && php ' . escapeshellarg( __DIR__ . '/' . $check ) . $args . ' 2>&1' );
    foreach ( $saved as $sp => $orig ) { file_put_contents( $sp, $orig ); }
    $caught = ( false !== strpos( (string) $out, $want ) );
    echo ( $caught ? 'caught  ' : 'MISSED  ' ) . $name . "\n";
    if ( ! $caught ) { $missed++; echo '        ' . str_replace( "\n", "\n        ", trim( substr( (string) $out, -1500 ) ) ) . "\n"; }
}
echo $missed ? "$missed PLANT(S) MISSED\n" : "every plant caught.\n";
exit( $missed ? 1 : 0 );
