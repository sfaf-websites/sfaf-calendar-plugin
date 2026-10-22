<?php
/**
 * THE 3.113.8 CHECKS, EACH MADE TO FAIL ON PURPOSE.
 *
 *     php .claude/plant-31138.php [part of a plant name]
 *
 * One fault per plant, written into the real file; the check is run, and every
 * file is put back from memory, not from git. An anchor that is not there, or
 * is there twice, stops the run. Commit first, and never run this while
 * run-all.sh is running.
 *
 * 1 is the RSVP form's one height (release-31138-live.php); 2 Pacific time
 * (pacific-time-test.php); 3 to 7 release-31138-test.php, with 4L and 7L in
 * release-31138-pages-live.php.
 */

$root = dirname( __DIR__ );

$plants = array(
    '1: the other language holds no room' => array( 'release-31138-live.php', '1: phone busy: the form is', array(
        array( 'public/js/calendar.js', "    function WW(key, fallback) { return twin(W(key, fallback), Wl(otherLang(), key, fallback)); }", "    function WW(key, fallback) { return esc(W(key, fallback)); }" ),
    ) ),
    '1: the held words can be seen' => array( 'release-31138-live.php', "the other language's words can be seen or heard", array(
        array( 'public/js/calendar.js', "'.uc-rsvp-modal-overlay .uc-w2-off{visibility:hidden;-webkit-user-select:none;user-select:none}' +", "'.uc-rsvp-modal-overlay .uc-w2-off{-webkit-user-select:none;user-select:none}' +" ),
    ) ),
    '1: the button keeps one language' => array( 'release-31138-live.php', 'holds no room for', array(
        array( 'public/js/calendar.js', "        return waitlistChosen() ? WW('wait_heading', 'Join the waitlist') : WW('submit', 'Register Now');", "        return waitlistChosen() ? W('wait_heading', 'Join the waitlist') : W('submit', 'Register Now');" ),
    ) ),
    '2: a moment is formatted as UTC again' => array( 'pacific-time-test.php', "1: Mark's \"Last run\"", array(
        array( 'includes/sfaf-template-functions.php', "    \$date  = sfaf_ap_date( wp_date( 'Y-m-d', \$when ), \$style, \$lang );\n    \$clock = sfaf_ap_time( wp_date( 'H:i', \$when ), true, \$lang );", "    \$date  = sfaf_ap_date( \$when, \$style, \$lang );\n    \$clock = ( '00' === date_i18n( 'i', \$when ) ) ? date_i18n( 'g', \$when ) : date_i18n( 'g:i', \$when );\n    \$clock .= ' ' . sfaf_ap_meridiem( date_i18n( 'A', \$when ), \$lang );" ),
    ) ),
    '2: Received is the UTC day' => array( 'pacific-time-test.php', '4: Received is the site', array(
        array( 'includes/class-sfaf-portal.php', "                        <span class=\"uc-muted\"><?php echo esc_html( 'Received ' . sfaf_ap_date( wp_date( 'Y-m-d', \$this->pending_received( \$id ) ), 'short_year' ) ); ?></span>", "                        <span class=\"uc-muted\"><?php echo esc_html( 'Received ' . sfaf_ap_date( \$this->pending_received( \$id ), 'short_year' ) ); ?></span>" ),
    ) ),
    '3: "Online" is mapped' => array( 'release-31138-test.php', '3: "Online" has no Getting there', array(
        array( 'includes/sfaf-template-functions.php', "    if ( '' === \$location || sfaf_location_is_online_only( \$location ) ) {", "    if ( '' === \$location ) {" ),
    ) ),
    '3: a place with Online in its name loses its map' => array( 'release-31138-test.php', '3: a place with "Online" in its name keeps it', array(
        array( 'includes/sfaf-template-functions.php', "    return ! array_diff( \$words, \$joining ) && array_intersect(", "    return array_intersect(" ),
    ) ),
    '4: every picture at its own shape' => array( 'release-31138-test.php', '4: the event page asks where the picture came from', array(
        array( 'templates/single-uc_event.php', "\$own_shape = in_array( sfaf_event_image_source( \$post_id ), array( 'source', 'remote' ), true );", "\$own_shape = true;" ),
    ) ),
    '4L: an imported picture is still cropped' => array( 'release-31138-pages-live.php', '4L: ', array(
        array( 'public/css/calendar.css', ".uc-single .uc-single-image.uc-single-image-own-shape { aspect-ratio: auto; background: none; }", ".uc-single .uc-single-image.uc-single-image-own-shape { background: none; }" ),
    ) ),
    '5: Public_URL is not read' => array( 'release-31138-test.php', "5: then the row's own form", array(
        array( 'includes/class-sfaf-source-everyaction.php', "? SFAF_EveryAction::matched_url( \$uuid ) : \$form,", "? SFAF_EveryAction::matched_url( \$uuid ) : ''," ),
    ) ),
    '5: every day goes to 50-Plus' => array( 'release-31138-test.php', "5: and its day is on HAN's list", array(
        array( 'includes/class-sfaf-everyaction.php', "        \$list = self::list_on( \$host );", "        \$list = self::LIST_URL;" ),
    ) ),
    '5: HAN\'s list is taken for a page' => array( 'release-31138-test.php', "5: a day on HAN's list is still a list", array(
        array( 'includes/class-sfaf-everyaction.php', "            if ( 0 === strpos( (string) \$url, self::list_on( \$host ) ) ) {", "            if ( 0 === strpos( (string) \$url, self::LIST_URL ) ) {" ),
    ) ),
    '6: Bluehost is back' => array( 'release-31138-test.php', '6: nothing about Bluehost', array(
        array( 'admin/class-sfaf-admin.php', "                            not constants for exactly that reason. Nothing in the plugin hardcodes a provider.\n", "                            not constants for exactly that reason. Nothing in the plugin hardcodes a provider.\n                            WordPress mail through Bluehost has poor deliverability.\n" ),
    ) ),
    '7: the month buttons are plain buttons' => array( 'release-31138-test.php', '7: each is the public secondary button', array(
        array( 'includes/class-sfaf-shortcodes.php', " uc-month-tab-<?php echo esc_attr( \$tab['dir'] ); ?> uc-actionbtn uc-actionbtn-secondary\"", " uc-month-tab-<?php echo esc_attr( \$tab['dir'] ); ?>\"" ),
    ) ),
    '7E: the embed changes beyond the month buttons' => array( 'embed-identity-test.php', 'A: embed', array(
        array( 'includes/class-sfaf-shortcodes.php', '<a class="uc-sidebar-all" href="<?php echo esc_url( $all_url ); ?>">See all events &rarr;</a>', '<a class="uc-sidebar-all uc-actionbtn" href="<?php echo esc_url( $all_url ); ?>">See all events &rarr;</a>' ),
    ) ),
    '7L: the sidebar keeps its own card' => array( 'release-31138-pages-live.php', '7L: ', array(
        array( 'public/css/calendar.css', "    box-shadow: none; border-radius: 0;\n}", "}" ),
    ) ),
    '7L: the count stays grey' => array( 'release-31138-pages-live.php', "label is not the button's teal", array(
        array( 'public/css/calendar.css', ".uc-month-tab-count { font-size: 12.5px; font-weight: 400; color: inherit; flex: 0 0 auto; }", ".uc-month-tab-count { font-size: 12.5px; font-weight: 400; color: var(--uc-secondary); flex: 0 0 auto; }" ),
    ) ),
    '7L: the buttons stop sharing the column' => array( 'release-31138-pages-live.php', "do not share the column's width", array(
        array( 'public/css/calendar.css', ".uc-sidebar .uc-month-tab {\n    flex: 1 1 0; min-width: 0;\n", ".uc-sidebar .uc-month-tab {\n" ),
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
    $args = ( false !== strpos( $check, '-live' ) ) ? ' --run' : ' --verbose';
    $out = shell_exec( 'cd ' . escapeshellarg( $root ) . ' && php ' . escapeshellarg( __DIR__ . '/' . $check ) . $args . ' 2>&1' );
    foreach ( $saved as $sp => $orig ) { file_put_contents( $sp, $orig ); }
    $caught = ( false !== strpos( (string) $out, $want ) );
    echo ( $caught ? 'caught  ' : 'MISSED  ' ) . $name . "\n";
    if ( ! $caught ) { $missed++; echo '        ' . str_replace( "\n", "\n        ", trim( substr( (string) $out, -1500 ) ) ) . "\n"; }
}
echo $missed ? "$missed PLANT(S) MISSED\n" : "every plant caught.\n";
exit( $missed ? 1 : 0 );
