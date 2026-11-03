<?php
/**
 * THE 3.135.0 CHECKS, MADE TO FAIL ON PURPOSE.
 *
 *     php .claude/plant-31350.php [part of a plant's name]
 *
 * Plants, one at a time, a fault in the yellow audit, the emails' one yellow,
 * the WhatsApp panel, the language tags, the Events list's RSVP button, the
 * 600 limit or the count, and runs the check that must catch it. Every file
 * is put back from memory. Commit first, and never run this while run-all.sh
 * is running.
 */

$root = dirname( __DIR__ );
$P  = 'includes/class-sfaf-portal.php';
$X  = 'includes/class-sfaf-texts.php';
$M  = 'includes/class-sfaf-messages.php';
$E  = 'includes/class-sfaf-email.php';
$J  = 'public/js/portal.js';
$S  = 'public/js/caladmin-session.js';
$A  = 'admin/class-sfaf-admin.php';
$T  = 'release-31350-test.php';
$L  = 'release-31350-live.php';

$plants = array(
    // A. The audit.
    'the series cancel-and-email is yellow' => array( $T, 'FAIL  A: the series "Cancel these and email the N people" is red', array(
        array( $P, '<button type="submit" name="notify_choice" value="send" class="uc-btn uc-btn-danger">', '<button type="submit" name="notify_choice" value="send" class="uc-btn uc-btn-primary">' ),
    ) ),
    'Reassign and remove is yellow' => array( $T, 'FAIL  A: Users "Reassign and remove" is red', array(
        array( $P, '<button type="submit" class="uc-btn uc-btn-danger">Reassign and remove</button>', '<button type="submit" class="uc-btn uc-btn-primary">Reassign and remove</button>' ),
    ) ),
    'Cancel and email them is yellow in the dialog' => array( $L, 'PLANT J:', array(
        array( $J, "            send.className = 'uc-btn uc-btn-sm ' + (opts.sendClass || 'uc-btn-primary');", "            send.className = 'uc-btn uc-btn-sm uc-btn-primary';" ),
    ) ),
    'the second step forgets the colour' => array( $T, 'FAIL  A: and on the second step, the same class', array(
        array( $J, "            confirmSend.className = 'uc-btn uc-btn-sm ' + (opts.sendClass || 'uc-btn-primary');", "            confirmSend.className = 'uc-btn uc-btn-sm uc-btn-primary';" ),
    ) ),
    'the cancellation asks for no red' => array( $T, 'FAIL  A: "Cancel and email them" is red on the first step', array(
        array( $J, "                    sendClass: back ? '' : 'uc-btn-danger',", "                    sendClass: ''," ),
    ) ),
    'Submit for Review is yellow' => array( $T, 'FAIL  A: Submit for Review is green', array(
        array( $P, 'value="review" class="uc-btn uc-btn-go uc-editor-publish">Submit for Review', 'value="review" class="uc-btn uc-btn-primary">Submit for Review' ),
    ) ),
    'Save and email them is yellow' => array( $T, 'FAIL  A: "Save and email them" is green', array(
        array( $J, "                sendClass: 'uc-btn-go',\n", '' ),
    ) ),
    'Approve confirms in yellow' => array( $T, 'FAIL  A: the Approve dialog confirms in green', array(
        array( $J, "(btn.hasAttribute('data-uc-ask-danger') ? 'uc-btn-danger' : 'uc-btn-go')", "(btn.hasAttribute('data-uc-ask-danger') ? 'uc-btn-danger' : 'uc-btn-primary')" ),
    ) ),
    'a green trigger confirms in yellow' => array( $L, 'PLANT K:', array(
        array( $J, "(danger ? 'uc-btn-danger' : (btn.classList.contains('uc-btn-go') ? 'uc-btn-go' : 'uc-btn-primary'))", "(danger ? 'uc-btn-danger' : 'uc-btn-primary')" ),
    ) ),
    'every venue row is yellow again' => array( $T, 'FAIL  A: Venues: Save venue on every row is grey', array(
        array( $P, '<button class="uc-btn uc-btn-sm" type="submit"><?php echo $venue ? \'Save venue\'', '<button class="uc-btn uc-btn-sm uc-btn-primary" type="submit"><?php echo $venue ? \'Save venue\'' ),
    ) ),
    'every user row is yellow again' => array( $T, 'FAIL  A: Users: Save on every row is grey', array(
        array( $P, 'class="uc-btn uc-btn-sm" type="submit" form="<?php echo esc_attr( $fid ); ?>" data-uc-user-save>', 'class="uc-btn uc-btn-sm uc-btn-primary" type="submit" form="<?php echo esc_attr( $fid ); ?>" data-uc-user-save>' ),
    ) ),
    'Export CSV is yellow' => array( $T, 'FAIL  A: RSVP list: Export CSV is grey', array(
        array( $P, '<a href="<?php echo esc_url( $export ); ?>" class="uc-btn">Export CSV</a>', '<a href="<?php echo esc_url( $export ); ?>" class="uc-btn uc-btn-primary">Export CSV</a>' ),
    ) ),
    'a bulk button is yellow' => array( $T, 'FAIL  A: the Events list bulk buttons are grey', array(
        array( $P, "<button type=\"submit\" class=\"uc-btn uc-btn-sm\"\n                            name=\"uc_do\" value=\"publish\"", "<button type=\"submit\" class=\"uc-btn uc-btn-sm uc-btn-primary\"\n                            name=\"uc_do\" value=\"publish\"" ),
    ) ),
    'Done is yellow' => array( $T, 'FAIL  A: the form-links Done is grey', array(
        array( $J, "done.className = 'uc-btn uc-btn-sm';", "done.className = 'uc-btn uc-btn-sm uc-btn-primary';" ),
    ) ),
    'Restore is yellow' => array( $T, 'FAIL  A: Restore is grey', array(
        array( $S, "yes.className = 'uc-btn';", "yes.className = 'uc-btn uc-btn-primary';" ),
    ) ),
    'Save Twilio settings is yellow' => array( $T, 'FAIL  A: WP admin: Save Twilio settings is grey', array(
        array( $X, '<button type="submit" class="button">Save Twilio settings</button>', '<button type="submit" class="button button-primary">Save Twilio settings</button>' ),
    ) ),
    'Run now is yellow' => array( $T, 'FAIL  A: WP admin: Run now is grey', array(
        array( $A, 'class="button" data-uc-busy="Running&hellip;">Run now', 'class="button button-primary" data-uc-busy="Running&hellip;">Run now' ),
    ) ),
    // B. Emails.
    'Add to Google stays yellow beside Join' => array( $T, 'FAIL  B: online confirmation: Add to Google is outlined', array(
        array( $M, "self::label( 'google', \$lang ), SFAF_Email::has_primary( \$html ) ? 'outline' : 'primary', true, true )", "self::label( 'google', \$lang ), 'primary', true, true )" ),
    ) ),
    'See the event page stays yellow beside Join' => array( $T, 'FAIL  B: online reminder: See the event page is outlined', array(
        array( $M, "self::label( 'see_event', \$lang ), SFAF_Email::has_primary( \$html ) ? 'outline' : 'primary' );", "self::label( 'see_event', \$lang ), 'primary' );" ),
    ) ),
    'Google loses its yellow in person' => array( $T, 'FAIL  B: in person: Add to Google keeps the yellow', array(
        array( $M, "self::label( 'google', \$lang ), SFAF_Email::has_primary( \$html ) ? 'outline' : 'primary', true, true )", "self::label( 'google', \$lang ), 'outline', true, true )" ),
    ) ),
    // C. What WhatsApp takes.
    'the panel shows with WhatsApp off' => array( $T, 'FAIL  C: with WhatsApp off there is no panel', array(
        array( $P, "                        <?php if ( SFAF_Texts::whatsapp_on() ) : ?>\n                            <details class=\"uc-tr-wa-help\"", "                        <?php if ( true ) : ?>\n                            <details class=\"uc-tr-wa-help\"" ),
    ) ),
    'a Not OK line is missing' => array( $T, 'FAIL  C: Not OK, the five', array(
        array( $P, "                                        <li>Donations or fundraising</li>\n", '' ),
    ) ),
    'the warning is not Mark\'s' => array( $T, 'FAIL  C: the two headings and the warning', array(
        array( $P, 'it may block it, and future messages too.', 'it may block it.' ),
    ) ),
    // D. EN or ES.
    'the RSVP list has no tag' => array( $T, 'FAIL  D: the RSVP list: EN or ES beside each name', array(
        array( $P, "                                echo self::lang_tag( SFAF_Texts::lang_of( \$r ) );\n", '' ),
    ) ),
    'the recipient lists have no tag' => array( $T, 'FAIL  D: Email registrants: EN or ES beside each', array(
        array( $P, "<?php echo isset( \$p['lang'] ) ? self::lang_tag( \$p['lang'] ) : ''; ?>", '' ),
    ) ),
    'a registration with no language reads EN on a Spanish event' => array( $T, 'FAIL  D: on a Spanish event, the one who chose nothing is ES', array(
        array( $P, "                'lang' => SFAF_Texts::lang_of( \$p['row'] ),", "                'lang' => (string) \$p['row']->lang," ),
    ) ),
    'the waitlist has no tag' => array( $T, 'FAIL  D: the waitlist on the same page', array(
        array( $P, "</strong><?php echo self::lang_tag( SFAF_Texts::lang_of( \$r ) ); ?></td>", '</strong></td>' ),
    ) ),
    // E. The RSVP button.
    'every event gets the button' => array( $T, 'FAIL  E: an event that does not take RSVPs here keeps the plain number', array(
        array( $P, 'if ( sfaf_event_takes_rsvps( $id ) && $this->can_edit_event( $user, $id ) ) {', 'if ( $this->can_edit_event( $user, $id ) ) {' ),
    ) ),
    'none yet reads 0 RSVPs' => array( $T, 'FAIL  E: none yet: a small secondary "View RSVPs"', array(
        array( $P, "esc_html( \$rsvp_n ? sprintf( '%d %s', \$rsvp_n, 1 === \$rsvp_n ? 'RSVP' : 'RSVPs' ) : 'View RSVPs' )", "esc_html( sprintf( '%d %s', \$rsvp_n, 1 === \$rsvp_n ? 'RSVP' : 'RSVPs' ) )" ),
    ) ),
    'the button is yellow' => array( $L, 'PLANT L:', array(
        array( $P, '<a class="uc-btn uc-btn-sm uc-rsvp-count-btn" data-uc-rsvp-count-btn', '<a class="uc-btn uc-btn-sm uc-btn-primary uc-rsvp-count-btn" data-uc-rsvp-count-btn' ),
    ) ),
    // F. 600.
    'the limit is still 300' => array( $T, 'FAIL  F: the limit is 600', array(
        array( $X, '    const MAX_MESSAGE    = 600;', '    const MAX_MESSAGE    = 300;' ),
    ) ),
    // G. The count.
    'several pieces are 160 each' => array( $T, 'FAIL  G: 306 are two, 307 are three', array(
        array( $X, "        return \$n <= 160 ? 1 : (int) ceil( \$n / 153 );", "        return \$n <= 160 ? 1 : (int) ceil( \$n / 160 );" ),
    ) ),
    'an accent costs nothing' => array( $T, 'FAIL  G: one á makes 70 the piece', array(
        array( $X, "        return \$u <= 70 ? 1 : (int) ceil( \$u / 67 );", "        \$n = \$u;\n        return \$n <= 160 ? 1 : (int) ceil( \$n / 153 );" ),
    ) ),
    'é is counted as an accent' => array( $T, 'FAIL  G: é and ñ are in the alphabet', array(
        array( $X, "\$basic = '@£\$¥èéùìòÇ", "\$basic = '@£\$¥èùìòÇ" ),
    ) ),
    'the script counts its own way' => array( $T, 'FAIL  G: the script counts as pieces() does', array(
        array( $J, '        return n <= 160 ? 1 : Math.ceil(n / 153);', '        return n <= 160 ? 1 : Math.ceil(n / 150);' ),
    ) ),
    'the Spanish words are not counted' => array( $T, 'FAIL  G: and the Spanish one', array(
        array( $X, "                \$langs[ self::lang_of( \$p['row'] ) ] = true;", "                \$langs['en'] = true;" ),
    ) ),
    'the count does not follow typing' => array( $L, 'PLANT K:', array(
        array( $J, "            box.addEventListener('input', update);\n", '' ),
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
