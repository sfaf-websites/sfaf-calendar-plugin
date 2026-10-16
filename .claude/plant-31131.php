<?php
/**
 * THE 3.113.1 CHECKS, EACH MADE TO FAIL ON PURPOSE.
 *
 *     php .claude/plant-31131.php [part of a plant name]
 *
 * One fault per plant, written into the real file; the check is run, and every
 * file is put back from memory, not from git. An anchor that is not there, or
 * is there twice, stops the run. Commit first, and never run this while
 * run-all.sh is running.
 *
 * E is the proof that 3.113.1 leaves the embed as 3.113.0 had it: the one
 * template named in ALLOWED_FILES must not open the door to another.
 */

$root = dirname( __DIR__ );

$plants = array(
    'E: the series page template changes' => array( 'embed-identity-test.php', 'B: templates/taxonomy-uc_series.php differs from 3.113.0', array(
        array( 'templates/taxonomy-uc_series.php', ' * The public page for one series.', ' * The public page for one series, changed.' ),
    ) ),
    'E: the event page stylesheet changes' => array( 'embed-identity-test.php', 'B: public/css/calendar.css differs from 3.113.0', array(
        array( 'public/css/calendar.css', "/* SFAF Calendar Frontend Styles */\n", "/* SFAF Calendar Frontend Styles. */\n" ),
    ) ),
    '1: Dismissed opens on load' => array( 'release-31131-test.php', '1: it is a <details> with no open attribute', array(
        array( 'includes/class-sfaf-portal.php', '<details class="uc-dismissed-fold" data-uc-dismissed-fold>', '<details class="uc-dismissed-fold" data-uc-dismissed-fold open>' ),
    ) ),
    '1: the count left off the button' => array( 'release-31131-test.php', '1: the button carries the count', array(
        array( 'includes/class-sfaf-portal.php', '<span class="uc-dismissed-show">Show dismissed (<?php echo (int) $n; ?>)</span>', '<span class="uc-dismissed-show">Show dismissed</span>' ),
    ) ),
    '1L: the open button still says Show' => array( 'release-31131-live.php --run', 'the open button reads', array(
        array( 'public/css/portal.css', ".uc-dismissed-fold[open] > .uc-dismissed-toggle .uc-dismissed-hide { display: inline; }\n", '' ),
    ) ),
    '2: the registrations tick with RSVP off' => array( 'release-31131-test.php', '2: RSVP off: no registrations tick', array(
        array( 'includes/class-sfaf-portal.php', "                if ( sfaf_event_takes_rsvps( \$event_id ) ) :\n                ?>", "                if ( true ) :\n                ?>" ),
    ) ),
    '2: the route lists the submitter with RSVP off' => array( 'release-31131-test.php', 'the posted tick lists nobody', array(
        array( 'includes/class-sfaf-portal.php', "if ( ! empty( \$_POST['notify_submitter'] ) && sfaf_event_takes_rsvps( \$event_id ) ) {", "if ( ! empty( \$_POST['notify_submitter'] ) ) {" ),
    ) ),
    '3: an old off stops the confirmation' => array( 'release-31131-test.php', 'an old "off" on the confirmation is ignored', array(
        array( 'includes/class-sfaf-notifications.php', "        if ( self::is_always( \$kind ) ) {\n            return true;\n        }\n", '' ),
    ) ),
    '3: set_off stores the reminder' => array( 'release-31131-test.php', 'set_off stores only a choosable kind', array(
        array( 'includes/class-sfaf-notifications.php', "        \$valid = self::choosable();", "        \$valid = array_keys( self::kinds() );" ),
    ) ),
    '3: an old off counted' => array( 'release-31131-test.php', '3: and not counted', array(
        array( 'includes/class-sfaf-notifications.php', "return is_array( \$off ) ? count( array_intersect( self::choosable(), \$off ) ) : 0;", "return is_array( \$off ) ? count( \$off ) : 0;" ),
    ) ),
    '3: the always two get ticks' => array( 'release-31131-test.php', '3: four ticks', array(
        array( 'includes/class-sfaf-portal.php', '<?php continue; endif; ?>', '<?php endif; ?>' ),
    ) ),
    '3: the status counts the old way' => array( 'release-31131-test.php', 'the status line counts one off of six', array(
        array( 'includes/class-sfaf-portal.php', "return (int) \$off_count . ' switched off, ' . ( (int) \$kinds_n - (int) \$off_count ) . ' on.';", "return (int) \$off_count . ' of ' . (int) \$kinds_n . ' switched off.';" ),
    ) ),
    '4: no chevron on + Add category' => array( 'release-31131-test.php', '4: + Add category carries a chevron', array(
        array( 'includes/class-sfaf-portal.php', "aria-expanded=\"false\"><span>+ Add category</span>\n                            <span class=\"uc-disclosure-chevron\" aria-hidden=\"true\"><?php echo sfaf_icon( 'chevron', array( 'size' => '14px' ) ); ?></span></button>", "aria-expanded=\"false\"><span>+ Add category</span></button>" ),
    ) ),
    '4L: the open button not pressed' => array( 'release-31131-live.php --run', 'open is not chevron up and pressed', array(
        array( 'public/css/portal.css', "    box-shadow: inset 0 2px 3px rgba(55, 52, 51, 0.22); }", "    }" ),
    ) ),
    '4L: the chevron does not turn' => array( 'release-31131-live.php --run', 'open is not chevron up and pressed', array(
        array( 'public/css/portal.css', ".uc-chips-add .uc-btn[aria-expanded=\"true\"] .uc-disclosure-chevron,\n", '' ),
    ) ),
    '5: the contact name unescaped' => array( 'release-31131-test.php', '5: the three fields, the email linked, the rest escaped', array(
        array( 'includes/sfaf-template-functions.php', "    return esc_html( substr( \$line, 0, \$at ) )", "    return substr( \$line, 0, \$at )" ),
    ) ),
    '5: the event page prints plain text' => array( 'release-31131-test.php', '5: the event page prints it', array(
        array( 'templates/single-uc_event.php', '<?php echo sfaf_event_public_contact_html( $post_id ); ?>', '<?php echo esc_html( $contact ); ?>' ),
    ) ),
    '6: two teams again' => array( 'release-31131-test.php', '6: three teams are kept', array(
        array( 'includes/class-sfaf-teams.php', "            if ( isset( \$known[ \$id ] ) && ! in_array( \$id, \$keep, true ) ) {\n                \$keep[] = \$id;\n            }\n", "            if ( isset( \$known[ \$id ] ) && ! in_array( \$id, \$keep, true ) ) {\n                \$keep[] = \$id;\n            }\n            if ( count( \$keep ) >= 2 ) {\n                break;\n            }\n" ),
    ) ),
    '6: Pick up to back' => array( 'release-31131-test.php', '6: no "Pick up to"', array(
        array( 'includes/class-sfaf-portal.php', "            <?php else : ?>\n                <div class=\"uc-access-teams\" data-uc-access-teams>", "            <?php else : ?>\n                <p class=\"uc-hint\">Pick up to 2.</p>\n                <div class=\"uc-access-teams\" data-uc-access-teams>" ),
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
