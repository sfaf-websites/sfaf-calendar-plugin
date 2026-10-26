<?php
/**
 * THE 3.122.0 CHECK, MADE TO FAIL ON PURPOSE.
 *
 *     php .claude/plant-31220.php
 *
 * Puts one fault in at a time and runs release-31220-test.php, which must fail
 * with the line named. Every file is put back from memory. Commit first, and
 * never run this while run-all.sh is running.
 */

$root = dirname( __DIR__ );
$P    = 'includes/class-sfaf-portal.php';
$V    = 'includes/class-sfaf-preview.php';
$T    = 'includes/sfaf-template-functions.php';

$plants = array(
    'A: a draft gets View event page and the copy icon' => array( 'A: draft: no copy icon and no View event page', array(
        array( $V, "        if ( 'publish' === \$post->post_status ) {", "        if ( in_array( \$post->post_status, array( 'publish', 'draft' ), true ) ) {" ),
    ) ),
    'A: the copy icon copies another link' => array( 'the copy icon copies the same link', array(
        array( $P, "data-uc-copy-text=\"<?php echo esc_attr( \$page['url'] ); ?>\"", "data-uc-copy-text=\"<?php echo esc_attr( home_url( '/' ) ); ?>\"" ),
    ) ),
    'A: View event page opens in the same tab' => array( 'A: published: it opens in a new tab', array(
        array( $P, "<a class=\"uc-btn\" href=\"<?php echo esc_url( \$page['url'] ); ?>\" target=\"_blank\" rel=\"noopener\" data-uc-saved-action=\"view-page\">", "<a class=\"uc-btn\" href=\"<?php echo esc_url( \$page['url'] ); ?>\" data-uc-saved-action=\"view-page\">" ),
    ) ),
    'B: the RSVP list on an event that takes none' => array( 'B: an event that does not take RSVPs here has no RSVP list link', array(
        array( $P, "        \$rsvps = sfaf_event_takes_rsvps( \$event_id ) && \$this->can_edit_event( \$user, \$event_id );", "        \$rsvps = \$this->can_edit_event( \$user, \$event_id );" ),
    ) ),
    'B: the RSVP list for somebody the list refuses' => array( 'B: and is not given the RSVP list link', array(
        array( $P, "        \$rsvps = sfaf_event_takes_rsvps( \$event_id ) && \$this->can_edit_event( \$user, \$event_id );", "        \$rsvps = sfaf_event_takes_rsvps( \$event_id );" ),
    ) ),
    'C: a card loses its caption' => array( 'every card on the page has a caption', array(
        array( $P, "            array( 'links', 'Links', ", "            array( 'links-gone', 'Links', " ),
    ) ),
    'C: the imported panel is not a step' => array( 'C: imported: every card on the page has a caption', array(
        array( $P, '<div class="uc-card uc-refresh-panel" data-uc-card="imported">', '<div class="uc-card uc-refresh-panel">' ),
    ) ),
    'C: the bar caption names Delete where there is none' => array( 'the bar draws "Delete" exactly when its caption names it', array(
        array( $P, "            if ( \$cancelled || ! SFAF_Announce::has_registrations( \$event_id ) ) {\n                \$out[] = 'Delete removes it for good.';", "            if ( true ) {\n                \$out[] = 'Delete removes it for good.';" ),
    ) ),
    'C: the old schedule caption is back' => array( 'Edit the schedule is captioned exactly when it is drawn', array(
        array( $P, "        if ( \$series && \$is_edit ) {\n            \$sched[] = 'Edit the schedule adds and removes dates in the series.';\n        }\n", '' ),
    ) ),
    'D: a visitor is given the draft' => array( 'D: not for a visitor', array(
        array( $V, "        return self::can_preview( \$post ) ? array( \$post ) : \$posts;", "        return array( \$post );" ),
    ) ),
    'D: an editor without the event is given it' => array( 'D: not for an editor who cannot edit it', array(
        array( $V, "        return SFAF_Portal::user_can_edit_event( \$uid, \$post );", "        return true;" ),
    ) ),
    'E: two volunteer buttons again' => array( 'E: a Galaxy need with a volunteer link: volunteer buttons', array(
        array( $T, "    if ( ! \$need_id || '' !== sfaf_volunteer_url( \$post_id ) ) {", "    if ( ! \$need_id ) {" ),
    ) ),
    'E: the count is back' => array( 'no count of spots', array(
        array( $T, "    return '<div class=\"uc-galaxy-block\">' . sfaf_action_button( array(", "    return '<div class=\"uc-galaxy-block\"><span class=\"uc-galaxy-spots\">' . esc_html( sfaf_volunteer_spots_text( \$post_id ) ) . '</span>' . sfaf_action_button( array(" ),
    ) ),
    'E: the Preview band is gone' => array( 'carries the Preview band above the title', array(
        array( 'templates/single-uc_event.php', "            echo SFAF_Preview::banner( \$post_id );\n", '' ),
    ) ),
    'F: the 3.118.0 hint is back' => array( 'F: the line under Who should get RSVP updates?', array(
        array( 'includes/class-sfaf-request.php', "\"They'll get the RSVP count the day before and the list of who's coming two hours before.\",", "'With yes, once it is published, these addresses get the count of who is coming the day before, and who is coming two hours before.'," ),
    ) ),
);

$missed = 0;
$only = isset( $argv[1] ) ? $argv[1] : '';
foreach ( $plants as $name => $p ) {
    if ( '' !== $only && false === strpos( $name, $only ) ) { continue; }
    list( $want, $edits ) = $p;
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
    $out = shell_exec( 'cd ' . escapeshellarg( $root ) . ' && php ' . escapeshellarg( __DIR__ . '/release-31220-test.php' ) . ' 2>&1' );
    foreach ( $saved as $sp => $orig ) { file_put_contents( $sp, $orig ); }
    $caught = ( false !== strpos( (string) $out, $want ) && false === strpos( (string) $out, 'assertions passed' ) );
    echo ( $caught ? 'caught  ' : 'MISSED  ' ) . $name . "\n";
    if ( ! $caught ) { $missed++; echo '        ' . str_replace( "\n", "\n        ", trim( substr( (string) $out, -1500 ) ) ) . "\n"; }
}
echo $missed ? "$missed PLANT(S) MISSED\n" : "every plant caught.\n";
exit( $missed ? 1 : 0 );
