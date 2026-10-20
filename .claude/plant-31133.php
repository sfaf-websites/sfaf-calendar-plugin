<?php
/**
 * THE 3.113.3 CHECKS, EACH MADE TO FAIL ON PURPOSE.
 *
 *     php .claude/plant-31133.php [part of a plant name]
 *
 * One fault per plant, written into the real file; the check is run, and every
 * file is put back from memory, not from git. An anchor that is not there, or
 * is there twice, stops the run. Commit first, and never run this while
 * run-all.sh is running.
 *
 * E is the proof that 3.113.3 leaves the embed as 3.113.2 had it.
 */

$root = dirname( __DIR__ );

$plants = array(
    'E: the stylesheet the embed loads changes' => array( 'embed-identity-test.php', 'B: public/css/calendar.css differs from 3.113.2', array(
        array( 'public/css/calendar.css', "/* SFAF Calendar Frontend Styles */\n", "/* SFAF Calendar Frontend Styles */\n.uc-single .uc-flyer { margin: 0; }\n" ),
    ) ),
    'E: the calendar script changes' => array( 'embed-identity-test.php', 'B: public/js/calendar.js differs from 3.113.2', array(
        array( 'public/js/calendar.js', " * SFAF Calendar Frontend JS\n", " * SFAF Calendar Frontend JS.\n" ),
    ) ),
    'E: an embed answer changes' => array( 'embed-identity-test.php', 'A: ', array(
        array( 'includes/class-sfaf-embed.php', "\$response->header( 'Cache-Control', 'public, max-age=' . \$browser_ttl );", "\$response->header( 'Cache-Control', 'public, max-age=' . ( \$browser_ttl + 1 ) );" ),
    ) ),
    '1: the card keeps its old name' => array( 'release-31133-test.php', '1: the card is titled RSVP', array(
        array( 'includes/class-sfaf-portal.php', '<h2 class="uc-bento-title">RSVP</h2>', '<h2 class="uc-bento-title">Registration</h2>' ),
    ) ),
    '1: the Accept RSVPs tick comes back through the catch-all' => array( 'release-31133-test.php', '1: no Accept RSVPs tick on Edit event either', array(
        array( 'includes/class-sfaf-portal.php', "                        \$rsvp_placed[] = 'rsvp_enabled';\n", '' ),
    ) ),
    '1: an existing event with RSVPs opens on No RSVP' => array( 'release-31133-test.php', '1: RSVPs on opens on RSVP Here', array(
        array( 'includes/class-sfaf-register-elsewhere.php', "        if ( '1' === (string) get_post_meta( \$event_id, '_uc_rsvp_enabled', true )\n            || ", "        if ( false\n            || " ),
    ) ),
    /* Not "External RSVP clears the stored answer": save_rsvp_settings_from_post()
       already returns while the event registers elsewhere, so that is no fault. */
    '1: External RSVP is saved as here' => array( 'release-31133-test.php', '1: External RSVP stores another site', array(
        array( 'includes/class-sfaf-register-elsewhere.php', "        \$post['reg_mode']               = ( 'elsewhere' === \$choice ) ? 'elsewhere' : 'here';", "        \$post['reg_mode']               = 'here';" ),
    ) ),
    '1: the choice is never read' => array( 'release-31133-test.php', '1: No RSVP switches RSVPs off', array(
        array( 'includes/class-sfaf-portal.php', "        SFAF_Register_Elsewhere::apply_choice_to_post( \$event_id, \$_POST );\n", '' ),
    ) ),
    '1: a hybrid event can be No RSVP' => array( 'release-31133-test.php', '1: with No RSVP disabled and saying why', array(
        array( 'includes/class-sfaf-register-elsewhere.php', "<?php echo ( 'none' === \$value && \$hybrid ) ? ' disabled' : ''; ?>", '' ),
    ) ),
    '1: the settings show on No RSVP' => array( 'release-31133-test.php', '1: the script shows the settings for RSVP Here only', array(
        array( 'public/js/portal.js', "if (here) { here.hidden = choice !== 'here'; }", 'if (here) { here.hidden = away; }' ),
    ) ),
    '2: Delete is a red button again' => array( 'release-31133-test.php', '2: Delete is a red text link', array(
        array( 'includes/class-sfaf-portal.php', 'class="uc-link-danger uc-editor-delete" aria-haspopup="dialog">Delete</button>', 'class="uc-btn uc-btn-stop uc-editor-delete" aria-haspopup="dialog">Delete</button>' ),
    ) ),
    '2: a second solid button' => array( 'release-31133-test.php', '2: one solid button on the bar', array(
        array( 'includes/class-sfaf-portal.php', '<button type="button" class="uc-btn uc-cancel-open"', '<button type="button" class="uc-btn uc-btn-caution uc-cancel-open"' ),
    ) ),
    '3: the outlined button comes back' => array( 'release-31133-test.php', '3: no outlined or white button', array(
        array( 'includes/class-sfaf-attachments.php', "            'variant'  => 'primary',\n            'external' => true,\n        ) );\n        \$out .= '</div>';", "            'variant'  => 'secondary',\n            'external' => true,\n        ) );\n        \$out .= '</div>';" ),
    ) ),
    '3: the preview is never used' => array( 'release-31133-test.php', '3: the first page as a picture', array(
        array( 'includes/class-sfaf-attachments.php', "        \$med = image_get_intermediate_size( \$att, 'medium' );", "        \$med = false;" ),
    ) ),
    '4: an email keeps its old name' => array( 'release-31133-test.php', '4: the four list emails, word for word', array(
        array( 'includes/class-sfaf-notifications.php', "'label' => 'Email when someone cancels',", "'label' => 'Alert to your notification list when somebody cancels'," ),
    ) ),
    '4: the creator line for a system account' => array( 'release-31133-test.php', '4: no creator line for a system account', array(
        array( 'includes/class-sfaf-portal.php', "<?php elseif ( ! \$post || (int) \$post->post_author > 0 ) : ?>", '<?php else : ?>' ),
    ) ),
    '5: the colleague card comes back' => array( 'release-31133-test.php', '5: one line, the name a mailto, the date', array(
        array( 'includes/class-sfaf-portal.php', "<p class=\"uc-request-who\">Requested by <a href=\"mailto:", "<p class=\"uc-request-who\">Requested by a colleague <a href=\"mailto:" ),
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
    $cmd = 'php ' . escapeshellarg( __DIR__ . '/' . $check ) . ' --verbose 2>&1';
    $out = shell_exec( 'cd ' . escapeshellarg( $root ) . ' && ' . $cmd );
    foreach ( $saved as $sp => $orig ) { file_put_contents( $sp, $orig ); }
    $caught = ( false !== strpos( (string) $out, $want ) );
    echo ( $caught ? 'caught  ' : 'MISSED  ' ) . $name . "\n";
    if ( ! $caught ) { $missed++; echo '        ' . str_replace( "\n", "\n        ", trim( substr( (string) $out, -1500 ) ) ) . "\n"; }
}
echo $missed ? "$missed PLANT(S) MISSED\n" : "every plant caught.\n";
exit( $missed ? 1 : 0 );
