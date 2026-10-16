<?php
/**
 * THE 3.113.0 CHECKS, EACH MADE TO FAIL ON PURPOSE.
 *
 *     php .claude/plant-3113.php [part of a plant name]
 *
 * One fault per plant, written into the real file; the check is run, and every
 * file is put back from memory, not from git. An anchor that is not there, or
 * is there twice, stops the run. Commit first, and never run this while
 * run-all.sh is running.
 *
 * E is the one that matters most: embed-identity-test.php is the proof that
 * 3.113.0 leaves the public calendar as 3.111.0 had it, and a comparison that
 * cannot see a change would pass every tree.
 */

$root = dirname( __DIR__ );

$plants = array(
    'E: an embed header changes' => array( 'embed-identity-test.php', 'headers > Cache-Control differs from 3.111.0', array(
        array( 'includes/class-sfaf-embed.php', "\$response->header( 'Cache-Control', 'public, max-age=' . \$browser_ttl );", "\$response->header( 'Cache-Control', 'public, max-age=' . ( \$browser_ttl + 1 ) );" ),
    ) ),
    'E: the embed's body changes' => array( 'embed-identity-test.php', 'data > html differs from 3.111.0', array(
        array( 'includes/class-sfaf-embed.php', "        \$payload['css_url'] = self::style_url();", "        \$payload['css_url'] = self::style_url();\n        \$payload['html'] = (string) \$payload['html'] . ' ';" ),
    ) ),
    'E: a taxonomy registered differently' => array( 'embed-identity-test.php', 'taxonomies > uc_event_category', array(
        array( 'includes/class-sfaf-post-types.php', "'rewrite'      => array( 'slug' => 'event-category' ),", "'rewrite'      => array( 'slug' => 'event-categories' )," ),
    ) ),
    'E: a scheduled task renamed' => array( 'embed-identity-test.php', 'cron_tasks > reminders', array(
        array( 'includes/class-sfaf-cron.php', "'label'    => 'Reminder emails',", "'label'    => 'Reminder emails, sent',"),
    ) ),
    'E: the public stylesheet changes' => array( 'embed-identity-test.php', 'B: public/css/calendar.css differs from 3.111.0', array(
        array( 'public/css/calendar.css', "/* SFAF Calendar Frontend Styles */\n", "/* SFAF Calendar Frontend Styles. */\n" ),
    ) ),
    '4: Delete in the row submits again' => array( 'release-3113-test.php', '4: one Delete opener', array(
        array( 'includes/class-sfaf-portal.php', '<button type="button" data-uc-dialog-open="uc-delete-this" data-uc-saved-action="delete"', '<button type="submit" form="uc-delete-event-<?php echo (int) $event_id; ?>" data-uc-dialog-open="uc-delete-this" data-uc-saved-action="delete"' ),
    ) ),
    '4: the editor asks the scope again after a cancel' => array( 'release-3113-test.php', 'the redirect carries edit_scope', array(
        array( 'includes/class-sfaf-portal.php', "                    'n'          => count( \$cancel_ids ),\n                    // Answered: the editor does not ask how a save applies (3.112.1).\n                    'edit_scope' => 'this',\n", "                    'n'          => count( \$cancel_ids ),\n" ),
    ) ),
    '4: all upcoming reaches a past date' => array( 'release-3113-test.php', '4: never the past one', array(
        array( 'includes/class-sfaf-portal.php', "                    foreach ( SFAF_Recurrence::bulk_targets( \$event_id ) as \$tid ) {", "                    foreach ( SFAF_Recurrence::all_in_group( SFAF_Recurrence::group_of( \$event_id ) ) as \$tid ) {" ),
    ) ),
    '4L: the panels show before their buttons are pressed' => array( 'release-3113-live.php --run', 'shows before its button is pressed', array(
        array( 'public/js/portal.js', "    document.querySelectorAll('[data-uc-dialog-panel]').forEach(function (panel) {\n        panel.hidden = true;\n    });\n", '' ),
    ) ),
    'N: Choose people made the primary' => array( 'release-3113-test.php', 'N: neither is the primary', array(
        array( 'includes/class-sfaf-portal.php', '<summary class="uc-btn uc-btn-sm uc-notify-choose-btn" data-uc-picker-toggle>', '<summary class="uc-btn uc-btn-sm uc-notify-choose-btn uc-btn-primary" data-uc-picker-toggle>' ),
    ) ),
    'N: the people status line empty again' => array( 'release-3113-test.php', 'N: the people status line, nothing chosen', array(
        array( 'includes/class-sfaf-portal.php', "            return \$typed ? \$in_total : 'Nobody else yet.';", "            return '';" ),
    ) ),
    'N: an unset list counted as one individual' => array( 'release-3113-test.php', 'N: the people status line, nothing chosen', array(
        array( 'includes/class-sfaf-portal.php', "        \$chosen_users  = array_values( array_filter( array_map( 'intval', (array) get_post_meta( \$event_id, SFAF_Reminders::NOTIFY_USERS_META, true ) ) ) );", "        \$chosen_users  = array_map( 'intval', (array) get_post_meta( \$event_id, SFAF_Reminders::NOTIFY_USERS_META, true ) );" ),
    ) ),
    'NL: the emails status does not follow the ticks' => array( 'release-3113-live.php --run', 'the emails status line does not follow the ticks', array(
        array( 'public/js/portal.js', "if (!e.target || e.target.name !== 'notify_kinds[]') { return; }", "if (!e.target || e.target.name !== 'notify_kinds') { return; }" ),
    ) ),
    'NL: the buttons stretch across the card' => array( 'release-3113-live.php --run', 'is not sized to its label', array(
        array( 'public/css/portal.css', ".uc-notify-choose > .uc-notify-choose-btn { width: fit-content; list-style: none; }", ".uc-notify-choose > .uc-notify-choose-btn { display: flex; width: auto; list-style: none; }" ),
    ) ),
    '7: the Asana copy once per recipient' => array( 'asana-copy-test.php', 'request: exactly one copy to Asana', array(
        array( 'includes/class-sfaf-request.php', "            SFAF_Email::send( \$address, \$subject, \$mine ? \$shell : str_replace( esc_url( \$edit ), esc_url( \$row ), \$shell ), \$mine ? \$text : str_replace( \$edit, \$row, \$text ), \$email );\n        }", "            SFAF_Email::send( \$address, \$subject, \$mine ? \$shell : str_replace( esc_url( \$edit ), esc_url( \$row ), \$shell ), \$mine ? \$text : str_replace( \$edit, \$row, \$text ), \$email );\n            SFAF_Email::send( SFAF_Asana::address(), \$subject, \$shell, \$text, \$email );\n        }" ),
    ) ),
    '7: the copy sent with its tick off' => array( 'asana-copy-test.php', 'none with its tick off', array(
        array( 'includes/class-sfaf-asana.php', "        if ( ! isset( \$messages[ \$which ] ) || ! self::enabled( \$which ) ) {", "        if ( ! isset( \$messages[ \$which ] ) ) {" ),
    ) ),
    '8: free text on the event page' => array( 'age-range-test.php', 'event page reader', array(
        array( 'includes/sfaf-template-functions.php', "    return SFAF_Submit::age_value( (string) get_post_meta( (int) \$post_id, SFAF_Submit::META_AGE, true ) );", "    return trim( (string) get_post_meta( (int) \$post_id, SFAF_Submit::META_AGE, true ) );" ),
    ) ),
    '8: an old free-text value deleted by a save' => array( 'age-range-test.php', 'is KEPT', array(
        array( 'includes/class-sfaf-portal.php', "            } elseif ( '' === trim( \$age_was ) || '' !== SFAF_Submit::age_value( \$age_was ) ) {", "            } else {" ),
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
    $out = shell_exec( 'php ' . escapeshellarg( __DIR__ . '/' . strtok( $check, ' ' ) ) . ( false !== strpos( $check, '--run' ) ? ' --run' : '' ) . ' --verbose 2>&1' );
    foreach ( $saved as $sp => $orig ) { file_put_contents( $sp, $orig ); }
    $caught = ( false !== strpos( (string) $out, $want ) );
    echo ( $caught ? 'caught  ' : 'MISSED  ' ) . $name . "\n";
    if ( ! $caught ) { $missed++; echo '        ' . str_replace( "\n", "\n        ", trim( substr( (string) $out, -1500 ) ) ) . "\n"; }
}
echo $missed ? "$missed PLANT(S) MISSED\n" : "every plant caught.\n";
exit( $missed ? 1 : 0 );
