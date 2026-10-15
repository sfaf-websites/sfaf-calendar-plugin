<?php
/**
 * THE 3.112.1 CHECKS, EACH MADE TO FAIL ON PURPOSE.
 *
 *     php .claude/plant-31121.php [part of a plant name]
 *
 * One fault per item, written into the real file; the check is run, and every
 * file is put back from memory, not from git. An anchor that is not there, or
 * is there twice, stops the run. Commit first, and never run this while
 * run-all.sh is running.
 */

$root = dirname( __DIR__ );

$plants = array(
    '1: the loader carries a version' => array( 'embed-filters-test.php', 'the loader carries a version number', array(
        array( 'public/js/embed.js', "    var PATTERN = /\\/js\\/embed\\.js(\\?.*)?$/;", "    var VERSION = '3.112.1';\n    var PATTERN = /\\/js\\/embed\\.js(\\?.*)?$/;" ),
    ) ),
    '1: the loader reloads the page' => array( 'embed-filters-test.php', 'the loader reloads something', array(
        array( 'public/js/embed.js', "        started = true;\n", "        started = true;\n        if (!version) { window.location.reload(); }\n" ),
    ) ),
    '1: the version answer kept by a browser' => array( 'embed-filters-test.php', 'may be kept by a browser or a proxy', array(
        array( 'includes/class-sfaf-embed.php', "'no-cache, no-store, max-age=0, must-revalidate'", "'public, max-age=86400'" ),
    ) ),
    '1L: the loader never asks, so a cached app is used' => array( 'embed-loader-live.php --run', 'embed-app.js?ver=', array(
        array( 'public/js/embed.js', "\n    ask(0);\n", "\n    start('');\n" ),
    ) ),
    '2a: the address widens a tag-scoped block' => array( 'tag-scope-test.php', 'giveaways chosen (outside)', array(
        array( 'includes/class-sfaf-embed.php', "\$resolved['tag']      = \$this->shortcodes->effective_organizer( \$params['tag'], \$params['active_tags'] );", "\$resolved['tag']      = ( '' !== \$params['active_tags'] ) ? \$params['active_tags'] : \$params['tag'];" ),
    ) ),
    '2b: the feed sends the first day as the last' => array( 'feed-multiday-test.php', 'multi: last_date', array(
        array( 'includes/class-sfaf-sync.php', "'last_date'       => sfaf_event_last_date( \$post_id ) ?: (string) get_post_meta( \$post_id, '_uc_event_date', true ),", "'last_date'       => (string) get_post_meta( \$post_id, '_uc_event_date', true )," ),
    ) ),
    '3: cancelling leaves the embed cache' => array( 'cache-flush-test.php', 'cancel, from the editor: the embed cache was left in place', array(
        array( 'includes/class-sfaf-embed.php', "                SFAF_Cancellation::META, SFAF_Cancellation::VISIBILITY_META, SFAF_Privacy::META,\n", "                SFAF_Privacy::META,\n" ),
    ) ),
    '4: Delete in the row submits again' => array( 'release-31121-test.php', '4: one Delete opener', array(
        array( 'includes/class-sfaf-portal.php', '<button type="button" data-uc-dialog-open="uc-delete-this" data-uc-saved-action="delete"', '<button type="submit" form="uc-delete-event-<?php echo (int) $event_id; ?>" data-uc-dialog-open="uc-delete-this" data-uc-saved-action="delete"' ),
    ) ),
    '4: the editor asks the scope again after a cancel' => array( 'release-31121-test.php', 'the redirect carries edit_scope', array(
        array( 'includes/class-sfaf-portal.php', "                    'n'          => count( \$cancel_ids ),\n                    // Answered: the editor does not ask how a save applies (3.112.1).\n                    'edit_scope' => 'this',\n", "                    'n'          => count( \$cancel_ids ),\n" ),
    ) ),
    '4: all upcoming reaches a past date' => array( 'release-31121-test.php', '4: never the past one', array(
        array( 'includes/class-sfaf-portal.php', "                    foreach ( SFAF_Recurrence::bulk_targets( \$event_id ) as \$tid ) {", "                    foreach ( SFAF_Recurrence::all_in_group( SFAF_Recurrence::group_of( \$event_id ) ) as \$tid ) {" ),
    ) ),
    '4L: the panels show before their buttons are pressed' => array( 'release-31121-live.php --run', 'shows before its button is pressed', array(
        array( 'public/js/portal.js', "    document.querySelectorAll('[data-uc-dialog-panel]').forEach(function (panel) {\n        panel.hidden = true;\n    });\n", '' ),
    ) ),
    '5: a deleted tag stays a series default' => array( 'release-31121-test.php', 'off every series default', array(
        array( 'includes/class-sfaf-tags.php', "        \$series = get_terms( array( 'taxonomy' => SFAF_Series::TAXONOMY, 'hide_empty' => false ) );", "        \$series = array();" ),
    ) ),
    '5: Delete counts published events only' => array( 'release-31121-test.php', 'Delete counts every status', array(
        array( 'includes/class-sfaf-tags.php', "        \$stati = array( 'publish', 'future', 'draft', 'pending', 'private', 'trash' );", "        \$stati = array( 'publish' );" ),
    ) ),
    '6: Add tag against its field again' => array( 'component-sweep.php', 'D: <button', array(
        array( 'includes/class-sfaf-portal.php', "                            <div class=\"uc-cat-form-actions\">\n                                <button type=\"submit\" class=\"uc-btn uc-btn-sm uc-btn-primary\" form=\"uc-tag-new\">Add tag</button>\n                            </div>", "                            <button type=\"submit\" class=\"uc-btn\" form=\"uc-tag-new\">Add tag</button>" ),
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
    '9: What\'s included back on the pills' => array( 'release-31121-test.php', '9: the public string, English', array(
        array( 'includes/class-sfaf-messages.php', "'whats_included'   => array( 'Tags', 'Etiquetas' ),", "'whats_included'   => array( \"What's included\", 'Qué incluye' )," ),
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
    $out = shell_exec( 'php ' . escapeshellarg( __DIR__ . '/' . strtok( $check, ' ' ) ) . ( false !== strpos( $check, '--run' ) ? ' --run' : '' ) . ' 2>&1' );
    foreach ( $saved as $sp => $orig ) { file_put_contents( $sp, $orig ); }
    $caught = ( false !== strpos( (string) $out, $want ) );
    echo ( $caught ? 'caught  ' : 'MISSED  ' ) . $name . "\n";
    if ( ! $caught ) { $missed++; echo '        ' . str_replace( "\n", "\n        ", trim( substr( (string) $out, -1500 ) ) ) . "\n"; }
}
echo $missed ? "$missed PLANT(S) MISSED\n" : "every plant caught.\n";
exit( $missed ? 1 : 0 );
