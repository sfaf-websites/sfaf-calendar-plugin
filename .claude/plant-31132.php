<?php
/**
 * THE 3.113.2 CHECKS, EACH MADE TO FAIL ON PURPOSE.
 *
 *     php .claude/plant-31132.php [part of a plant name]
 *
 * One fault per plant, written into the real file; the check is run, and every
 * file is put back from memory, not from git. An anchor that is not there, or
 * is there twice, stops the run. Commit first, and never run this while
 * run-all.sh is running.
 *
 * E is the proof that 3.113.2 leaves the embed as 3.113.1 had it.
 */

$root = dirname( __DIR__ );

$plants = array(
    'E: the event page stylesheet changes' => array( 'embed-identity-test.php', 'B: public/css/calendar.css differs from 3.113.1', array(
        array( 'public/css/calendar.css', "/* SFAF Calendar Frontend Styles */\n", "/* SFAF Calendar Frontend Styles. */\n" ),
    ) ),
    'E: the event template changes' => array( 'embed-identity-test.php', 'B: templates/single-uc_event.php differs from 3.113.1', array(
        array( 'templates/single-uc_event.php', '<?php echo sfaf_event_public_contact_html( $post_id ); ?>', '<?php echo sfaf_event_public_contact_html( $post_id ); ?> ' ),
    ) ),
    '1: a past event is reported again' => array( 'release-31132-test.php', '1: a past event is left out', array(
        array( 'includes/class-sfaf-portal.php', "            if ( '' !== \$last && \$last < \$today ) {\n                continue;\n            }\n            if ( ! self::event_is_orphaned( \$id ) ) {", "            if ( ! self::event_is_orphaned( \$id ) ) {" ),
    ) ),
    '1: an undated event is dropped' => array( 'release-31132-test.php', '1: upcoming events only', array(
        array( 'includes/class-sfaf-portal.php', "            if ( '' !== \$last && \$last < \$today ) {", "            if ( '' === \$last || \$last < \$today ) {" ),
    ) ),
    '1: the all-clear says they were given somebody' => array( 'release-31132-test.php', '1: the all-clear does not say', array(
        array( 'includes/class-sfaf-orphans.php', "'None of the %d event%s reported earlier needs a new organizer now.',", "'The %d event%s reported earlier have been given an organizer.'," ),
    ) ),
    '2: no leave mark on the event form' => array( 'release-31132-test.php', '2: Add event\'s form carries data-uc-leave-warn', array(
        array( 'includes/class-sfaf-portal.php', ' enctype="multipart/form-data" data-uc-leave-warn>', ' enctype="multipart/form-data">' ),
    ) ),
    'L: a save does not clear the warning' => array( 'release-31132-live.php --run', 'L: still warns after the event form was submitted', array(
        array( 'public/js/caladmin-session.js', "            if (leaveForms.indexOf(form) !== -1 || actionOf(form) === 'trash_event') { leaving = true; }\n", "            if (actionOf(form) === 'trash_event') { leaving = true; }\n" ),
    ) ),
    'L: any form clears it' => array( 'release-31132-live.php --run', 'L: submitting another form', array(
        array( 'public/js/caladmin-session.js', "            if (leaveForms.indexOf(form) !== -1 || actionOf(form) === 'trash_event') { leaving = true; }\n", "            leaving = true;\n" ),
    ) ),
    'L: a put-back field still warns' => array( 'release-31132-live.php --run', 'L: still unsaved once the title was put back', array(
        array( 'public/js/caladmin-session.js', "        return !leaveBase || !same(leaveSnap(), leaveBase);\n", "        return true;\n" ),
    ) ),
    'L: nothing is ever unsaved' => array( 'release-31132-live.php --run', 'L: a changed title is not unsaved', array(
        array( 'public/js/caladmin-session.js', "                if (form && leaveForms.indexOf(form) !== -1) { byHand = true; }\n", "" ),
    ) ),
    '3: the marker only on a locked block' => array( 'release-31132-test.php', '3: caladmin: every FAQ deleted and saved stays deleted', array(
        array( 'includes/class-sfaf-portal.php', "            if ( 'uc_faqs' === \$name ) : ?>\n                <input type=\"hidden\" name=\"uc_faq_has_manual\" value=\"1\" />", "            if ( \$locked ) : ?>\n                <input type=\"hidden\" name=\"uc_faq_has_manual\" value=\"1\" />" ),
    ) ),
    '3: No FAQs keeps the posted rows' => array( 'release-31132-test.php', '3: No FAQs stores no rows', array(
        array( 'includes/class-sfaf-portal.php', "\$merge_faq( sfaf_faq_meta_key(), \$faq_none ? array() : \$rows ) );\n        }\n        if ( \$faq_default_from ) {", "\$merge_faq( sfaf_faq_meta_key(), \$rows ) );\n        }\n        if ( \$faq_default_from ) {" ),
    ) ),
    '3: the default ignores No FAQs' => array( 'release-31132-test.php', '3: with No FAQs, moving into it adds nothing', array(
        array( 'includes/class-sfaf-faq-sets.php', "        if ( '' === \$set_id || self::is_none( \$post_id ) ) {", "        if ( '' === \$set_id ) {" ),
    ) ),
    '3: the default before the rows again' => array( 'release-31132-test.php', '3: moving into a series with no FAQs of its own takes the default', array(
        array( 'includes/class-sfaf-portal.php', "        if ( \$faq_default_from ) {\n            SFAF_FAQ_Sets::apply_series_default( \$event_id, \$faq_default_from );\n        }\n", "" ),
    ) ),
    '3: WP admin marker only when locked' => array( 'release-31132-test.php', '3: WP admin: every FAQ deleted and saved stays deleted', array(
        array( 'includes/class-sfaf-post-types.php', "        echo '<input type=\"hidden\" name=\"uc_faq_has_manual\" value=\"1\" />';\n        ?>", "        ?>" ),
    ) ),
    'F: the tick hides nothing' => array( 'release-31132-live.php --run', 'ticked, the rows still show', array(
        array( 'public/js/portal.js', "            body.hidden = tick.checked;\n", "" ),
    ) ),
    '4: Cancel and Delete on a submission' => array( 'release-31132-test.php', '4: no Cancel event or Delete on the page', array(
        array( 'includes/class-sfaf-portal.php', "                if ( ! ( \$is_pending && \$is_sub ) ) {\n                    \$this->render_cancel_card( \$user, \$event_id, 'opener' );", "                if ( true ) {\n                    \$this->render_cancel_card( \$user, \$event_id, 'opener' );" ),
    ) ),
    '4: Save stays green beside Approve' => array( 'release-31132-test.php', '4: Save changes is plain', array(
        array( 'includes/class-sfaf-portal.php', "                if ( \$can_decide ) {\n                    \$save_main = '';\n                }\n", "" ),
    ) ),
    'B: Approve wraps to its own line' => array( 'release-31132-live.php --run', 'the three are not on one line', array(
        array( 'public/css/portal.css', "    .uc-portal .uc-editor-bar:has(.uc-editor-approve) .uc-form-actions-note { flex: 1 1 0; min-width: 0; }\n", "" ),
    ) ),
    'B: Approve before Save' => array( 'release-31132-live.php --run', 'not Reject, Save changes, Approve left to right', array(
        array( 'public/css/portal.css', ".uc-editor-actions .uc-editor-approve { order: 4; }\n", ".uc-editor-actions .uc-editor-approve { order: 0; }\n" ),
    ) ),
    '4: purge takes a published event' => array( 'release-31132-test.php', '4: refused on a published event', array(
        array( 'includes/class-sfaf-portal.php', "                if ( ! \$closed ) {\n                    \$this->redirect( 'pending', array( 'view' => 'closed', 'msg' => 'purge_refused' ) );\n                }\n", "" ),
    ) ),
    '4: no record of a deleted import' => array( 'release-31132-test.php', '4: and its id is kept', array(
        array( 'includes/class-sfaf-sources.php', "        add_action( 'before_delete_post', array( __CLASS__, 'record_deleted' ) );\n", "" ),
    ) ),
    '4: the fetch ignores the record' => array( 'everyaction-import-test.php', 'one deleted for good is not made again', array(
        array( 'includes/class-sfaf-sources.php', "            if ( ! \$existing && self::was_deleted( \$event['external_source'], \$event['external_id'] ) ) {", "            if ( false ) {" ),
    ) ),
    '4: dismissed rows back under the work list' => array( 'pending-queue-test.php', 'the Rejected and dismissed tab also draws the work list', array(
        array( 'includes/class-sfaf-portal.php', "        <?php if ( \$closed_view ) : ?>\n            <?php \$this->render_closed_queue(); ?>\n        <?php else : ?>", "        <?php if ( \$closed_view ) : ?>\n            <?php \$this->render_closed_queue(); ?>\n        <?php endif; if ( true ) : ?>" ),
    ) ),
    'T: Delete permanently asks nothing' => array( 'release-31132-test.php', '4: each row has Delete permanently, asked first', array(
        array( 'includes/class-sfaf-portal.php', "<button class=\"uc-link-danger\" type=\"submit\" data-uc-purge\n                                                data-uc-confirm=", "<button class=\"uc-link-danger\" type=\"submit\" data-uc-purge\n                                                data-uc-said=" ),
    ) ),
    '5: the team question back' => array( 'request-form-test.php', 'case 0: a posted team is not read', array(
        array( 'includes/class-sfaf-request.php', "        /* ---- The video. ----", "        \$clean['teams'] = array_values( array_filter( (array) ( isset( \$post['request_teams'] ) ? \$post['request_teams'] : array() ), 'is_string' ) );\n\n        /* ---- The video. ----" ),
    ) ),
    '5: no MarCom team on a request' => array( 'release-31132-test.php', '5: a request goes on the MarCom team', array(
        array( 'includes/class-sfaf-request.php', "            SFAF_Teams::set_access_for_event( \$event_id, array( \$marcom ) );\n", "" ),
    ) ),
    '5: MarCom matched exactly' => array( 'release-31132-test.php', '5: MarCom is found by name', array(
        array( 'includes/class-sfaf-request.php', "strtolower( (string) \$team['name'] ) ), 'marcom' ) ) {", "strtolower( (string) \$team['name'] ) ), 'marcom' ) && 'marcom' === strtolower( (string) \$team['name'] ) ) {" ),
    ) ),
    '5: the alerts left on' => array( 'release-31132-test.php', '5: the two emails on, the alerts off', array(
        array( 'includes/class-sfaf-request.php', "            SFAF_Notifications::set_off( \$event_id, array_diff( SFAF_Notifications::choosable(), self::RSVP_EMAILS ) );\n", "" ),
    ) ),
    '5: the requester not listed' => array( 'release-31132-test.php', '5: the requester is on the notification list', array(
        array( 'includes/class-sfaf-request.php', "            SFAF_Submissions::add_to_notify_list( \$event_id, \$email );\n", "" ),
    ) ),
    '5: Approve offers the tick again' => array( 'release-31132-test.php', '5: no registrations tick for somebody already listed', array(
        array( 'includes/class-sfaf-portal.php', "                if ( sfaf_event_takes_rsvps( \$event_id ) && ! \$listed_now ) :", "                if ( sfaf_event_takes_rsvps( \$event_id ) ) :" ),
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
    $cmd = 'php ' . escapeshellarg( __DIR__ . '/' . strtok( $check, ' ' ) ) . ( false !== strpos( $check, '--run' ) ? ' --run' : '' ) . ' --verbose 2>&1';
    if ( false !== strpos( $check, '--run' ) ) {
        $cmd = 'powershell.exe -NoProfile -ExecutionPolicy Bypass -File ' . escapeshellarg( __DIR__ . '/browser-guard.ps1' ) . ' -CeilingMB 768 -ReserveMB 256 -WaitSeconds 600 -Command ' . escapeshellarg( 'php .claude/' . strtok( $check, ' ' ) . ' --run --verbose' ) . ' 2>&1';
    }
    $out = shell_exec( 'cd ' . escapeshellarg( $root ) . ' && ' . $cmd );
    foreach ( $saved as $sp => $orig ) { file_put_contents( $sp, $orig ); }
    $caught = ( false !== strpos( (string) $out, $want ) );
    echo ( $caught ? 'caught  ' : 'MISSED  ' ) . $name . "\n";
    if ( ! $caught ) { $missed++; echo '        ' . str_replace( "\n", "\n        ", trim( substr( (string) $out, -1500 ) ) ) . "\n"; }
}
echo $missed ? "$missed PLANT(S) MISSED\n" : "every plant caught.\n";
exit( $missed ? 1 : 0 );
