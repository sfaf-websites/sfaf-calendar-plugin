<?php
/**
 * THE 3.142.0 CHECKS, MADE TO FAIL ON PURPOSE.
 *
 *     php .claude/plant-31420.php [part of a plant's name]
 *
 * Plants, one at a time, a fault in each part of the announcements and runs the
 * check that must catch it. Every file is put back from memory. Commit first,
 * and never run this while run-all.sh is running.
 */

$root = dirname( __DIR__ );
$AN = 'includes/class-sfaf-announcements.php';
$SC = 'includes/class-sfaf-shortcodes.php';
$AD = 'admin/class-sfaf-admin.php';
$EM = 'includes/class-sfaf-embed.php';
$CS = 'public/css/calendar.css';
$CJ = 'public/js/calendar.js';
$EJ = 'public/js/embed.js';
$T  = 'release-31420-test.php';
$L  = 'release-31420-live.php --run';
$I  = 'embed-identity-test.php';

/* An edit to the card's block, made in both copies so the copies still agree. */
function both( $from, $to ) {
    return array( array( 'public/js/calendar.js', $from, $to ), array( 'public/js/embed.js', $from, $to ) );
}

$plants = array(
    'no bar in the cell' => array( $T, 'has no Pride week bar', array(
        array( $SC, "                                        echo SFAF_Announcements::bar( \$ann_row, 'cell' );\n", '' ),
    ) ),
    'the day marked closed' => array( $T, 'is marked closed', array(
        array( $SC, "                            if ( \$ann_rows )    { \$classes[] = 'uc-day-ann'; }", "                            if ( \$ann_rows )    { \$classes[] = 'uc-day-ann'; \$classes[] = 'uc-day-closed'; }" ),
    ) ),
    'a bar with nothing to open is a button' => array( $T, 'G: a title-only announcement is a button with nothing to open', array(
        array( $AN, "        \$card  = ( '' !== \$img || '' !== \$row['desc'] || '' !== \$row['link'] );", "        \$card  = true;" ),
    ) ),
    'no icon' => array( $T, 'the icon is not first', array(
        array( $AN, "        if ( '' !== \$icon ) {\n            \$inner .= '<img class=\"uc-ann-icon\"", "        if ( false ) {\n            \$inner .= '<img class=\"uc-ann-icon\"" ),
    ) ),
    'the banner at the foot of the list' => array( $T, 'L: the Pride week banner is not above the first event', array(
        array( $SC, "                        echo SFAF_Announcements::bar( array_shift( \$ann_due ), 'list' );", "                        break;" ),
    ) ),
    'the sidebar shows every announcement' => array( $T, 'D: the sidebar shows an announcement that is not on today', array(
        array( $SC, "            \$ann_today = SFAF_Announcements::covering( current_time( 'Y-m-d' ) );", "            \$ann_today = SFAF_Announcements::all();" ),
    ) ),
    'an end before the start' => array( $T, 'S: an end before the start is not refused', array(
        array( $AN, "        if ( '' !== \$end && \$end < \$start ) {\n            return 'The last day is before the first day.';", "        if ( false ) {\n            return 'The last day is before the first day.';" ),
    ) ),
    'a javascript: link' => array( $T, 'S: a javascript: link is not refused', array(
        array( $AN, "        if ( '' !== \$link && '' === self::clean_link( \$link ) ) {\n            return 'The link", "        if ( false ) {\n            return 'The link" ),
    ) ),
    'an SVG with a script' => array( $T, 'I: an SVG with a script is let through', array(
        array( $AN, "<\\s*(script|foreignObject|", "<\\s*(xscript|foreignObject|" ),
    ) ),
    'a wide PNG' => array( $T, 'I: a PNG that is not square is let through', array(
        array( $AN, "        if ( \$w < 1 || \$w !== \$h || \$w > self::ICON_MAX_PX ) {", "        if ( \$w < 1 || \$w > self::ICON_MAX_PX ) {" ),
    ) ),
    'the two copies differ' => array( $T, 'J: the card\'s block differs between calendar.js and embed.js', array(
        array( $CJ, "    var ANN_OPEN_DELAY  = 200;", "    var ANN_OPEN_DELAY  = 250;" ),
    ) ),
    'the menu still Closures' => array( $T, 'A: the menu is not "Closures and announcements"', array(
        array( $AD, "            'Closures and announcements',\n            'Closures and announcements',\n            'manage_options',", "            'Closures',\n            'Closures',\n            'manage_options'," ),
    ) ),
    'an edit drops the image' => array( $T, 'A: an edit did not keep the icon and image', array(
        array( $AD, "            'image_id' => ( \$was && empty( \$_POST['ann_image_remove'] ) ) ? \$was['image_id'] : 0,", "            'image_id' => 0," ),
    ) ),
    'no cache flush' => array( $T, 'E: the embed cache is not flushed on update_option', array(
        array( $EM, "        add_action( 'update_option_' . SFAF_Announcements::OPTION, array( \$this, 'flush_cache' ) );\n", '' ),
    ) ),
    'a byte more with none saved' => array( $I, 'differs from 3.141.0', array(
        array( $SC, "                                ?>\n\n                                <?php if ( \$closed_row ) : ?>", "                                ?>\n\n\n                                <?php if ( \$closed_row ) : ?>" ),
    ) ),
    'the bar is teal' => array( $L, 'PLANT B:', array(
        array( $CS, "    background: var(--uc-dark); color: var(--uc-yellow);\n    font-family: var(--uc-font-heading); font-size: 11px;", "    background: var(--uc-teal-text); color: var(--uc-yellow);\n    font-family: var(--uc-font-heading); font-size: 11px;" ),
    ) ),
    'no strip on a phone' => array( $L, 'PLANT B:', array(
        array( $CS, "@container uc-calendar (max-width: 560px) {\n    .uc-calendar td .uc-ann-bars { display: none; }\n    .uc-calendar .uc-ann-strip { display: block;", "@container uc-calendar (max-width: 560px) {\n    .uc-calendar td .uc-ann-bars { display: none; }\n    .uc-calendar .uc-ann-strip { display: none;" ),
        array( $CS, "@media (max-width: 640px) {\n    .uc-calendar td .uc-ann-bars { display: none; }\n    .uc-calendar .uc-ann-strip { display: block;", "@media (max-width: 640px) {\n    .uc-calendar td .uc-ann-bars { display: none; }\n    .uc-calendar .uc-ann-strip { display: none;" ),
    ) ),
    'a click opens nothing' => array( $L, 'PLANT C:', both( "                show(bar, true);\n                /* detail 0", "                /* detail 0" ) ),
    'hovering opens nothing' => array( $L, 'PLANT H:', both( "        if (canHover) {\n            document.addEventListener('mouseover'", "        if (false) {\n            document.addEventListener('mouseover'" ) ),
    'the card in the corner' => array( $L, 'PLANT P:', both( "            place(bar);\n            card.classList.remove('is-measuring');", "            card.classList.remove('is-measuring');" ) ),
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
