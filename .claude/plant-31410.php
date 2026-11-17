<?php
/**
 * THE 3.141.0 CHECKS, MADE TO FAIL ON PURPOSE.
 *
 *     php .claude/plant-31410.php [part of a plant's name]
 *
 * Plants, one at a time, a fault in each of the eight changes and runs the
 * check that must catch it. Every file is put back from memory. Commit first,
 * and never run this while run-all.sh is running.
 */

$root = dirname( __DIR__ );
$P  = 'includes/class-sfaf-portal.php';
$A  = 'includes/class-sfaf-access.php';
$N  = 'includes/class-sfaf-no-picture.php';
$M  = 'sfaf-calendar.php';
$R  = 'includes/class-sfaf-reminders.php';
$C  = 'public/css/portal.css';
$AC = 'admin/css/admin.css';
$J  = 'public/js/portal.js';
$T  = 'release-31410-test.php';
$L  = 'release-31410-live.php --run';

$plants = array(
    'New Event loses its icon' => array( $T, 'PLANT N: + New Event carries one calendar icon', array(
        array( $P, "<span class=\"uc-new-event-icon\" aria-hidden=\"true\"><?php echo sfaf_icon( 'calendar', array( 'size' => '20px' ) ); ?></span>", '' ),
    ) ),
    'reduced motion still lifts' => array( $T, 'PLANT N: reduced motion takes the lift', array(
        array( $C, "    .uc-portal .uc-btn-new-event:focus-visible { transform: none; }\n", "    .uc-portal .uc-btn-new-event:focus-visible { transform: translateY(-1px); }\n" ),
    ) ),
    'the primary back to yellow' => array( $T, 'PLANT G: control-standard-audit passes', array(
        array( $C, ".uc-form-links-open { background: var(--p-main); border-color: var(--p-main); color: #fff; }", ".uc-form-links-open { background: var(--uc-primary); border-color: #E0BE00; color: var(--sfaf-darkgray); }" ),
    ) ),
    'the add toggle back to yellow' => array( $T, 'PLANT G: control-standard-audit passes', array(
        array( $C, "    border: 1px solid var(--p-main);\n    border-radius: 8px;\n    background: var(--p-main);\n", "    border: 1px solid var(--sfaf-yellow, #FFD900);\n    border-radius: 8px;\n    background: var(--sfaf-yellow, #FFD900);\n" ),
    ) ),
    'WP admin primary back to yellow' => array( $T, 'PLANT G: control-standard-audit passes', array(
        array( $AC, "    background: #15803D; border-color: #15803D;\n    color: #fff; text-shadow: none;", "    background: #FFD900; border-color: #E0BE00;\n    color: #373433; text-shadow: none;" ),
    ) ),
    'the old WhatsApp label' => array( $T, 'PLANT W:', array(
        array( $P, '</span>What can I send by Text and WhatsApp?</summary>', '</span>What can I send by WhatsApp?</summary>' ),
    ) ),
    'Email registrants draws the form for nobody' => array( $T, 'PLANT E:', array(
        array( $P, "                <?php if ( ! \$rm_people ) : ?>\n                    <p class=\"uc-hint\" data-uc-rm-nobody>", "                <?php if ( false ) : ?>\n                    <p class=\"uc-hint\" data-uc-rm-nobody>" ),
    ) ),
    'the one team is not ticked' => array( $T, 'PLANT T: on one team, that team is ticked', array(
        array( $P, "        if ( \$team_rule['mine'] ) {\n            \$chosen_t = array_values( array_unique( array_merge( \$chosen_t, \$team_rule['mine'] ) ) );\n        }\n", '' ),
    ) ),
    'two teams ask for nothing' => array( $T, 'PLANT T: on two teams', array(
        array( $P, "            : array( 'mine' => array(), 'need' => true );", "            : array( 'mine' => array(), 'need' => false );" ),
    ) ),
    'a save without a team goes through' => array( $T, 'PLANT T: a save with no team', array(
        array( $P, "            if ( \$team_rule['need'] && empty( \$posted['teams'] ) ) {\n                \$team_held = true;", "            if ( false ) {\n                \$team_held = true;" ),
    ) ),
    'the script lets it go' => array( $T, 'PLANT T: portal.js stops the save', array(
        array( $J, "                    if (teamTicked()) { return; }\n                    e.preventDefault();\n                    e.stopPropagation();", "                    if (teamTicked()) { return; }\n                    e.stopPropagation();" ),
    ) ),
    'no creator recorded' => array( $T, 'PLANT C: a new event records who made it', array(
        array( $P, "                SFAF_Access::record_creator( (int) \$event_id, (int) \$user->ID );\n", '' ),
    ) ),
    'Team and access names the viewer' => array( $T, 'PLANT C: Team and access names the creator', array(
        array( $P, "            \$creator = \$event_id ? SFAF_Access::creator( \$event_id ) : array(", "            \$creator = false ? SFAF_Access::creator( \$event_id ) : array(" ),
    ) ),
    'an import is its fetcher\'s' => array( $T, 'PLANT C: an import before the record', array(
        array( $A, "        if ( self::made_without_an_account( \$event_id ) ) {\n            return 0;\n        }\n        return max( 0, (int) get_post_field( 'post_author', \$event_id ) );", "        return max( 0, (int) get_post_field( 'post_author', \$event_id ) );" ),
    ) ),
    'replies go to the site' => array( $T, 'PLANT R: replies go to the creator', array(
        array( $R, "        \$creator = self::creator_address( \$event_id );\n        if ( '' !== \$creator ) {\n            return \$creator;\n        }\n", '' ),
    ) ),
    'the picker names the viewer' => array( $T, 'PLANT R: Replies go to', array(
        array( $P, "                \$reply_c = \$event_id\n                    ? SFAF_Access::creator( \$event_id )", "                \$reply_c = false\n                    ? SFAF_Access::creator( \$event_id )" ),
        array( $P, "                \$reply_c_email = SFAF_Reminders::creator_address( \$event_id );\n                if ( ! \$event_id ) {", "                \$reply_c_email = '';\n                if ( true ) {" ),
    ) ),
    'a copy keeps the creator\'s address' => array( $T, 'PLANT D:', array(
        array( $P, "            if ( '_uc_email_replyto' === \$key && '' !== \$src_creator['email'] && 0 === strcasecmp( trim( (string) \$value ), \$src_creator['email'] ) ) {", "            if ( false ) {" ),
    ) ),
    'schema 19 records the fetcher' => array( $T, 'PLANT M: schema 19 records each creator', array(
        array( $M, "        SFAF_Access::record_creator( \$id, \$without ? 0 : \$author );", "        SFAF_Access::record_creator( \$id, \$author );" ),
    ) ),
    'schema 19 clears a chosen address' => array( $T, 'PLANT M: it clears', array(
        array( $M, "        \$report['replyto_kept'][] = \$id;\n", "        delete_post_meta( \$id, '_uc_email_replyto' );\n        \$report['replyto_kept'][] = \$id;\n" ),
    ) ),
    'one email per date' => array( $T, 'PLANT P: three dates', array(
        array( $N, "            \$sid = class_exists( 'SFAF_Series' ) ? (int) SFAF_Series::id_for_event( \$id ) : 0;", "            \$sid = 0;" ),
    ) ),
    'the series is not marked' => array( $T, 'PLANT P: a later date', array(
        array( $N, "                update_term_meta( \$sid, self::META_TOLD, time() );\n", '' ),
    ) ),
    'a series picture is not counted' => array( $T, 'PLANT P: an event with no picture of its own in a series with one', array(
        array( $N, "        return '' !== (string) sfaf_event_image_url( (int) \$event_id );", "        return sfaf_event_has_own_image( (int) \$event_id );" ),
    ) ),
    'drafts are told' => array( $T, 'PLANT P: a draft or a pending event', array(
        array( $N, "        if ( 'publish' !== \$new_status || 'publish' === \$old_status || ! \$post || 'uc_event' !== \$post->post_type ) {", "        if ( ! \$post || 'uc_event' !== \$post->post_type ) {" ),
        array( $N, "            if ( 'publish' !== get_post_status( \$id ) || self::has_picture( \$id )", "            if ( self::has_picture( \$id )" ),
    ) ),
    'no edit link' => array( $T, 'PLANT P: an event in no series', array(
        array( $N, "            \$link  = SFAF_Portal::link( 'events/edit/' . \$first );", "            \$link  = SFAF_Portal::link( 'events' );" ),
    ) ),
    'two arrows again' => array( $T, 'PLANT A:', array(
        array( $C, ":where(summary:has(> .uc-disclosure-chevron)) { list-style: none; }\n", '' ),
    ) ),
    'two arrows in Chrome' => array( $L, 'PLANT A:', array(
        array( $C, ":where(summary:has(> .uc-disclosure-chevron)) { list-style: none; }\n", '' ),
    ) ),
    'yellow in Chrome' => array( $L, 'PLANT G:', array(
        array( $C, ".uc-portal { --p-main: #15803D; --p-main-hover: #12652F; }\n", ".uc-portal { --p-main: #FFD900; --p-main-hover: #FFD900; }\n" ),
    ) ),
    'the team gate in Chrome' => array( $L, 'PLANT T:', array(
        array( $J, "                    if (teamTicked()) { return; }\n                    e.preventDefault();\n                    e.stopPropagation();", "                    if (teamTicked()) { return; }\n                    e.stopPropagation();" ),
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
