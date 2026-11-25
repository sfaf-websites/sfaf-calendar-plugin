<?php
/**
 * THE 3.144.2 CHECKS, MADE TO FAIL ON PURPOSE.
 *
 *     php .claude/plant-31442.php [part of a plant's name]
 *
 * Plants, one at a time, a fault in each part of 3.144.2 (the segmented
 * toggle's teal edge and seams, the message a reply answered, the embed) and
 * runs the check that must catch it. Every file is put back from memory.
 * Commit first, and never run this while run-all.sh is running.
 */

$root = dirname( __DIR__ );
$IB = 'includes/class-sfaf-text-inbox.php';
$PT = 'includes/class-sfaf-portal.php';
$PC = 'public/css/portal.css';
$CJ = 'public/js/calendar.js';
$T  = 'release-31442-test.php';
$L  = 'release-31442-live.php --run';
$I  = 'embed-identity-test.php';

$plants = array(
    'the group edge left grey' => array( $T, "S: the group's boundary is 1px --uc-accent-text", array(
        array( $PC, ".uc-seg { display: inline-flex; flex-wrap: wrap; border: 1px solid var(--uc-accent-text);", ".uc-seg { display: inline-flex; flex-wrap: wrap; border: 1px solid var(--p-border-strong);" ),
    ) ),
    'the seams left grey' => array( $T, 'S: the seams between options are 1px --uc-accent-text', array(
        array( $PC, "    color: var(--p-text); background: var(--p-control-face);\n    border-left: 1px solid var(--uc-accent-text);", "    color: var(--p-text); background: var(--p-control-face);\n    border-left: 1px solid var(--p-border);" ),
    ) ),
    'the check-in seams left grey' => array( $T, "S: the check-in bar's seams are 1px --uc-accent-text", array(
        array( $PC, "padding: 8px 16px; border: 0; border-left: 1px solid var(--uc-accent-text); border-radius: 0;", "padding: 8px 16px; border: 0; border-left: 1px solid var(--p-border); border-radius: 0;" ),
    ) ),
    'the Events toggle given its own grey edge, in Chrome' => array( $L, 'PLANT S: admin-1280: the scope toggle', array(
        array( $PC, ".uc-scope-switch { align-self: center; }", ".uc-scope-switch { align-self: center; border-color: #8C8D8E; }" ),
    ) ),
    'the seams drawn grey by a later rule, in Chrome' => array( $L, "PLANT S: admin-390: the rsvp toggle's seam", array(
        array( $PC, ".uc-seg-opt { position: relative; }", ".uc-seg-opt { position: relative; }\n.uc-portal .uc-seg-opt span { border-left-color: #E2E5EA; }" ),
    ) ),
    'nothing shown above a reply' => array( $T, "R: Ana's thread: each message replied to above the first reply to it, once", array(
        array( $IB, "                'answers' => self::answered( \$in ),\n", "                'answers' => array(),\n" ),
    ) ),
    'the message repeated above every reply to it' => array( $T, "R: Ana's thread: each message replied to above the first reply to it, once", array(
        array( $PT, "if ( \$to && isset( \$t['answers'][ \$to ] ) && ! isset( \$shown[ \$to ] ) ) :", "if ( \$to && isset( \$t['answers'][ \$to ] ) ) :" ),
    ) ),
    'an answer from the RSVP page shown twice' => array( $T, "R: Ana's thread: each message replied to above the first reply to it, once", array(
        array( $IB, "    const ANSWERED_KINDS = array( 'confirmation', 'reminder', 'update' );", "    const ANSWERED_KINDS = array( 'confirmation', 'reminder', 'update', 'organizer' );" ),
    ) ),
    'a reply to nothing given a message it answered' => array( $T, 'R: threads() names nothing for Dee', array(
        array( $IB, "            \$id = (int) \$r->reply_to;\n            if ( ! \$id ||", "            \$id = (int) \$r->reply_to ?: 1;\n            if ( ! \$id ||" ),
    ) ),
    'no sender on a Text registrants message' => array( $T, 'R: a Text registrants message names who sent it', array(
        array( $PT, "                                            \$what .= ' from ' . \$au->display_name;\n", '' ),
    ) ),
    'the text not escaped' => array( $T, 'R: the text is escaped', array(
        array( $PT, "<span class=\"uc-reply-body\"><?php echo esc_html( (string) \$a->body ); ?></span>", "<span class=\"uc-reply-body\"><?php echo (string) \$a->body; ?></span>" ),
    ) ),
    'drawn as a reply, in Chrome' => array( $L, 'PLANT R: admin-1280: the message replied to is not set in', array(
        array( $PT, "<li class=\"uc-reply-msg uc-reply-out\" data-uc-reply-msg=\"sent\"", "<li class=\"uc-reply-msg uc-reply-in\" data-uc-reply-msg=\"sent\"" ),
    ) ),
    'a public file changes' => array( $I, 'differs from 3.144.1', array(
        array( $CJ, " * SFAF Calendar Frontend JS\n", " * SFAF Calendar Frontend JS \n" ),
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
    $parts = explode( ' ', $check, 2 );
    $out = shell_exec( 'cd ' . escapeshellarg( $root ) . ' && php ' . escapeshellarg( __DIR__ . '/' . $parts[0] ) . ( isset( $parts[1] ) ? ' ' . $parts[1] : '' ) . ' 2>&1' );
    foreach ( $saved as $sp => $orig ) { file_put_contents( $sp, $orig ); }
    $caught = ( false !== strpos( (string) $out, $want ) );
    echo ( $caught ? 'caught  ' : 'MISSED  ' ) . $name . "\n";
    if ( ! $caught ) { $missed++; echo '        ' . str_replace( "\n", "\n        ", trim( substr( (string) $out, -1500 ) ) ) . "\n"; }
}
echo $missed ? "$missed PLANT(S) MISSED\n" : "every plant caught.\n";
exit( $missed ? 1 : 0 );
