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
    'D.7: two empty lines in Notifications' => array( 'includes/class-sfaf-portal.php',
        "            // Said once, under the picker, by \"Nobody else yet.\" (3.107.0).\n            return '';", "            return 'Nobody chosen yet';", $live, 'PLANT D.7' ),
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
