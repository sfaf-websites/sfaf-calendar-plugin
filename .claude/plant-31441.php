<?php
/**
 * THE 3.144.1 CHECKS, MADE TO FAIL ON PURPOSE.
 *
 *     php .claude/plant-31441.php [part of a plant's name]
 *
 * Plants, one at a time, a fault in each part of 3.144.1 (the first reply
 * at once, the window and its follow-up, the badges and who has seen what,
 * the error look, the embed) and runs the check that must catch it. Every
 * file is put back from memory. Commit first, and never run this while
 * run-all.sh is running.
 */

$root = dirname( __DIR__ );
$IB = 'includes/class-sfaf-text-inbox.php';
$PT = 'includes/class-sfaf-portal.php';
$PC = 'public/css/portal.css';
$CJ = 'public/js/calendar.js';
$T  = 'release-31441-test.php';
$L  = 'release-31441-live.php --run';
$I  = 'embed-identity-test.php';

$plants = array(
    'the first reply waits like the rest' => array( $T, 'T: the first reply is emailed in the request that brought it', array(
        array( $IB, "        // The first reply: emailed now, and the window opened behind it.\n        self::alert( \$phone );\n", "        // The first reply: emailed now, and the window opened behind it.\n" ),
    ) ),
    'every reply emailed at once' => array( $T, 'T: replies inside the window are not emailed yet', array(
        array( $IB, "        if ( \$due ) {\n            // Inside the window: it waits for the follow-up.\n            return \$id;\n        }\n", '' ),
    ) ),
    'the follow-up waits for a scheduled run' => array( $T, 'T: every request closes the windows that are due', array(
        array( $IB, "        add_action( 'wp_loaded', array( __CLASS__, 'send_due' ) );\n", '' ),
    ) ),
    'a due window never closed by a request' => array( $T, 'T: the first request after them sends the follow-up', array(
        array( $IB, "            if ( (int) \$at <= \$now ) {", "            if ( (int) \$at < 0 ) {" ),
    ) ),
    'the backstop run left behind' => array( $T, 'T: the window is closed and its backstop run cleared', array(
        array( $IB, "        wp_clear_scheduled_hook( self::ALERT_HOOK, array( \$phone ) );\n", '' ),
    ) ),
    'the follow-up not said to be more' => array( $T, 'T: "2 more replies"', array(
        array( $IB, "        return self::alert( \$phone, true );", "        return self::alert( \$phone );" ),
    ) ),
    'the badge counts events they cannot edit' => array( $T, 'B: nothing for somebody who may edit none of them', array(
        array( $PT, "            if ( \$post && 'uc_event' === \$post->post_type && \$this->can_edit_event( \$user, \$post ) ) {\n                \$count += (int) \$n;", "            if ( \$post && 'uc_event' === \$post->post_type ) {\n                \$count += (int) \$n;" ),
    ) ),
    'opening Replies sees nothing' => array( $T, 'B: then seen, by this person', array(
        array( $PT, "                SFAF_Text_Inbox::mark_seen( \$user->ID, \$event_id, max( \$ids ) );\n", '' ),
    ) ),
    'one person seeing clears everybody' => array( $T, 'B: and nobody else: they still have theirs', array(
        array( $IB, "        \$seen = self::seen( \$user_id );\n        \$rows = (array) \$wpdb->get_results(", "        \$seen = self::seen( 1 );\n        \$rows = (array) \$wpdb->get_results(" ),
    ) ),
    'seen after the sidebar is drawn' => array( $T, 'B: the RSVP page sees them before the sidebar is drawn', array(
        array( $PT, "        if ( \$event_id ) {\n            \$this->take_new_replies( \$user, \$event_id );\n        }\n", '' ),
    ) ),
    'no badge on the sidebar' => array( $T, "B: the sidebar's Events carries the red badge", array(
        array( $PT, "                            <?php elseif ( 'events' === \$key && \$replies_count > 0 ) : ?>", "                            <?php elseif ( false ) : ?>" ),
    ) ),
    'no badge on the Events list' => array( $T, 'B: the Events list has one beside each event', array(
        array( $PT, "                        if ( \$reply_n > 0 ) {\n                            \$rsvp_cell", "                        if ( false ) {\n                            \$rsvp_cell" ),
    ) ),
    'new replies not highlighted' => array( $T, 'B: each new reply highlighted', array(
        array( $PT, "<?php \$fresh = \$in && isset( \$new[ (int) \$r->id ] ); ?>", "<?php \$fresh = false; ?>" ),
    ) ),
    'a failure flash drawn green' => array( $T, 'E: "category_failed" is drawn as', array(
        array( $PT, "        'series_picture_refused', 'category_failed', 'series_failed',", "        'series_picture_refused', 'series_failed'," ),
    ) ),
    'an error with no edge, in Chrome' => array( $L, 'PLANT E:', array(
        array( $PC, "    background: var(--p-error-tint);\n    border: 1px solid var(--p-danger);\n    color: var(--p-text);\n}", "    background: var(--p-error-tint);\n    color: var(--p-text);\n}" ),
    ) ),
    'the field error left in red words, in Chrome' => array( $L, 'PLANT E:', array(
        array( $PC, ".uc-portal:not(.uc-request-page) .uc-field-error,\n.uc-portal:not(.uc-request-page) .uc-hint-error,\n.uc-portal:not(.uc-request-page) .uc-fetch-fail,", ".uc-portal:not(.uc-request-page) .uc-hint-error,\n.uc-portal:not(.uc-request-page) .uc-fetch-fail," ),
    ) ),
    'the public form restyled, in Chrome' => array( $L, "PLANT E: public-390", array(
        array( $PC, ".uc-portal:not(.uc-request-page) .uc-field-error,\n.uc-portal:not(.uc-request-page) .uc-hint-error { padding", ".uc-portal .uc-field-error { background: var(--p-error-tint); }\n.uc-portal:not(.uc-request-page) .uc-field-error,\n.uc-portal:not(.uc-request-page) .uc-hint-error { padding" ),
    ) ),
    'the badge in brand red under white, in Chrome' => array( $L, 'PLANT B:', array(
        array( $PC, "    background: var(--p-error-ink); color: #fff;\n    font-size: 12px; font-weight: 700;", "    background: var(--p-danger); color: #fff;\n    font-size: 12px; font-weight: 700;" ),
    ) ),
    'the badge wraps under the RSVP count, in Chrome' => array( $L, 'PLANT B:', array(
        // As first drafted, and seen wrapping at 1280: a space between them, no row.
        array( $PC, ".uc-rsvp-with-replies { display: inline-flex; align-items: center; gap: 6px; white-space: nowrap; }\n", '' ),
        array( $PT, "\$rsvp_cell = '<span class=\"uc-rsvp-with-replies\">' . \$rsvp_cell\n", "\$rsvp_cell = '<span class=\"uc-rsvp-with-replies\">' . \$rsvp_cell . ' '\n" ),
    ) ),
    'the new reply not tinted, in Chrome' => array( $L, 'PLANT N:', array(
        array( $PC, ".uc-text-replies .uc-reply-msg.is-new { padding: 8px 10px; border: 1px solid var(--uc-primary); border-radius: 8px; background: #FFFAE0; }\n", '' ),
    ) ),
    'a public file changes' => array( $I, 'differs from 3.144.0', array(
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
