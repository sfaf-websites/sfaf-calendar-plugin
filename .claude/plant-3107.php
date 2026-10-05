<?php
/**
 * THE 3.107.0 CHECKS, EACH MADE TO FAIL ON PURPOSE.
 *
 *     php .claude/plant-3107.php [part of a plant name]
 *
 * Each fault is written into the real file, the check that should catch it is
 * run, and the file is put back from memory, not from git, so an uncommitted
 * change cannot be lost. A plant whose anchor is not there, or is there twice,
 * stops the run: a plant that did not apply proves nothing. Never run this
 * while run-all.sh is running.
 */

$root  = dirname( __DIR__ );
$live  = 'release-3107-live.php --run';
$cap   = 'capacity-zero-test.php';
$plants = array(
    'B.2: Registration never hides its body' => array( 'public/js/portal.js',
        "        body.hidden = !box.checked;\n", "        body.hidden = false;\n", $live, 'PLANT B.2' ),
    'B.2: the tick does not reopen it' => array( 'public/js/portal.js',
        "        if (box) { box.addEventListener('change', applyRegistrationBody); }", "", $live, 'PLANT B.2' ),
    'B.3: 0 is unlimited again' => array( 'sfaf-calendar.php',
        "    return '' !== \$raw && is_numeric( \$raw );", "    return '' !== \$raw && is_numeric( \$raw ) && (int) \$raw > 0;", $cap, 'PLANT B.3' ),
    'B.3: the save stores 0 for an empty box' => array( 'includes/class-sfaf-portal.php',
        "return ( '' !== \$raw && is_numeric( \$raw ) ) ? (string) max( 0, (int) \$raw ) : '';", "return (string) max( 0, (int) \$raw );", $cap, 'PLANT B.3' ),
    'B.3: the migration runs twice' => array( 'sfaf-calendar.php',
        "    if ( false !== get_option( 'sfaf_capacity_zero_migration', false ) ) {\n        return 0;\n    }", "", $cap, 'PLANT B.3' ),
    /* The two additions Mark made to the brief. */
    'FORMS: the staff form stores nothing for 0' => array( 'includes/class-sfaf-request.php',
        "            if ( '' !== (string) \$c['capacity'] ) {", "            if ( \$c['capacity'] > 0 ) {", $cap, 'PLANT FORMS' ),
    'FORMS: the community form reads blank as 0' => array( 'includes/class-sfaf-submit.php',
        "        \$clean['capacity'] = '';", "        \$clean['capacity'] = 0;", $cap, 'PLANT FORMS' ),
    'LIMIT OFF: no limit offers nobody' => array( 'includes/class-sfaf-waitlist.php',
        "            return PHP_INT_MAX;", "            return 0;", 'waitlist-test.php', 'PLANT LIMIT OFF' ),
    'LIMIT OFF: offers still need a limit' => array( 'includes/class-sfaf-waitlist.php',
        "        if ( ! self::serves( \$event_id ) || SFAF_Cancellation::is_cancelled( \$event_id ) ) {", "        if ( ! self::applies( \$event_id, \$format ) || SFAF_Cancellation::is_cancelled( \$event_id ) ) {", 'waitlist-test.php', 'PLANT LIMIT OFF' ),
    'LIMIT OFF: the editor does not ask when the box is emptied' => array( 'includes/class-sfaf-portal.php',
        "( null === \$caps_after[ \$i ] || \$caps_after[ \$i ] > \$caps_before[ \$i ] )", "( null !== \$caps_after[ \$i ] && \$caps_after[ \$i ] > \$caps_before[ \$i ] )", $cap, 'PLANT LIMIT OFF' ),
    'F: the series dropdown does not re-filter' => array( 'public/js/portal.js',
        "            select.addEventListener('change', function () { apply(true); });", "", $live, 'PLANT F' ),
    'F: a chosen picture outside the series is hidden' => array( 'public/js/portal.js',
        "                if (row && row.hasAttribute('data-uc-off-series')) {\n                    row.removeAttribute('data-uc-off-series');\n                    shown++;\n                }\n            }\n            if (note) { note.hidden = !(narrow && 0 === shown); }",
        "            }\n            if (note) { note.hidden = !(narrow && 0 === shown); }", $live, 'PLANT F' ),
    'G: the weekday stays put on Add event' => array( 'public/js/portal.js',
        "                dateInput.addEventListener('change', function () {\n                    followWeekday();", "                dateInput.addEventListener('change', function () {", $live, 'PLANT G' ),
    'G: a touched tick is overwritten' => array( 'public/js/portal.js',
        "b.addEventListener('change', function () { followDate = false; });", "b.addEventListener('change', function () {});", $live, 'PLANT G' ),
    'G: Edit event follows the date too' => array( 'includes/class-sfaf-portal.php',
        "array( 'follow_date' => ! (int) \$event_id )", "array( 'follow_date' => true )", $live, 'PLANT G' ),
    'E: the bar is not sticky' => array( 'public/css/portal.css',
        "    position: sticky; bottom: 0; z-index: 15;\n", "    z-index: 15;\n", $live, 'PLANT E' ),
    /* In the script, not the server's first paint: the script rewrites the
       summary on load, so a fault planted in PHP never reaches the screen. */
    'D.7: two empty lines in Notifications' => array( 'public/js/portal.js',
        "                if (!picked.length) {\n                    countEl.textContent = '';", "                if (!picked.length) {\n                    countEl.textContent = 'Nobody chosen yet';", $live, 'PLANT D.7' ),
    'D.7: the address box keeps its own empty line' => array( 'includes/class-sfaf-portal.php',
        "                    <?php // No empty line of its own (3.107.0): \"Nobody else yet.\" under the picker covers it. ?>\n",
        "                    <p class=\"uc-muted uc-emails-empty\" data-uc-emails-empty>Nobody outside the calendar yet.</p>\n", $live, 'PLANT D.7' ),
    'A: Registration back inside Location' => array( 'includes/class-sfaf-portal.php',
        "<section class=\"uc-bento-card uc-registration-card\" data-uc-card=\"registration\" data-uc-registration>", "<section class=\"uc-bento-card uc-registration-card\" data-uc-registration>", $live, 'PLANT H' ),
);

$missed = 0;
$only = isset( $argv[1] ) ? $argv[1] : '';
foreach ( $plants as $name => $p ) {
    if ( '' !== $only && false === strpos( $name, $only ) ) { continue; }
    list( $file, $from, $to, $check, $want ) = $p;
    $path = $root . '/' . $file;
    $orig = file_get_contents( $path );
    if ( 1 !== substr_count( $orig, $from ) ) {
        echo "ANCHOR NOT UNIQUE for \"$name\" in $file\n";
        exit( 1 );
    }
    file_put_contents( $path, str_replace( $from, $to, $orig ) );
    $out = shell_exec( 'php ' . escapeshellarg( __DIR__ . '/' . strtok( $check, ' ' ) ) . ( false !== strpos( $check, '--run' ) ? ' --run' : '' ) . ' 2>&1' );
    file_put_contents( $path, $orig );
    $caught = ( false !== strpos( (string) $out, $want ) );
    echo ( $caught ? 'caught  ' : 'MISSED  ' ) . $name . "\n";
    if ( ! $caught ) { $missed++; echo '        ' . str_replace( "\n", "\n        ", trim( (string) $out ) ) . "\n"; }
}
echo $missed ? "$missed PLANT(S) MISSED\n" : "every plant caught.\n";
exit( $missed ? 1 : 0 );
