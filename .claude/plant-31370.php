<?php
/**
 * THE 3.137.0 CHECKS, MADE TO FAIL ON PURPOSE.
 *
 *     php .claude/plant-31370.php [part of a plant's name]
 *
 * Plants, one at a time, a fault in R, its relatives or the exceptions, and
 * runs the check that must catch it. Every file is put back from memory.
 * Commit first, and never run this while run-all.sh is running.
 */

$root = dirname( __DIR__ );
$C  = 'public/css/portal.css';
$A  = 'admin/css/admin.css';
$P  = 'includes/class-sfaf-portal.php';
$J  = 'public/js/portal.js';
$S  = 'public/js/caladmin-session.js';
$AD = 'admin/class-sfaf-admin.php';
$T  = 'release-31370-test.php';
$L  = 'release-31370-live.php';
$K  = 'control-standard-audit.php';

$plants = array(
    'the face goes back to Light Gray' => array( $L, 'PLANT N:', 'caladmin-events', array(
        array( $C, '    --p-btn-face: #ffffff;', '    --p-btn-face: #D1D3D4;' ),
    ) ),
    'the control standard lets Light Gray through' => array( $K, 'R: --p-btn-face is #d1d3d4', array(
        array( $C, '    --p-btn-face: #ffffff;', '    --p-btn-face: #D1D3D4;' ),
    ) ),
    'the secondary ink goes Dark Gray' => array( $L, 'PLANT P:', 'caladmin-events', array(
        array( $C, ".uc-form-links-open { display: inline-flex; align-items: center; gap: 6px; padding: 9px 16px; border-radius: 8px; border: 1px solid var(--p-btn-edge); background: var(--p-btn-face); color: var(--p-btn-edge);",
                   ".uc-form-links-open { display: inline-flex; align-items: center; gap: 6px; padding: 9px 16px; border-radius: 8px; border: 1px solid var(--p-btn-edge); background: var(--p-btn-face); color: var(--p-text);" ),
    ) ),
    'the edge goes grey, like a field' => array( $K, 'is not R', array(
        array( $C, ".uc-form-links-open { display: inline-flex; align-items: center; gap: 6px; padding: 9px 16px; border-radius: 8px; border: 1px solid var(--p-btn-edge);",
                   ".uc-form-links-open { display: inline-flex; align-items: center; gap: 6px; padding: 9px 16px; border-radius: 8px; border: 1px solid var(--p-border-strong);" ),
    ) ),
    'the edge goes pale and fails 3:1' => array( $L, 'PLANT O:', 'caladmin-events', array(
        array( $C, '    --p-btn-edge: #0E7680;', '    --p-btn-edge: #9FD3D8;' ),
    ) ),
    'the hover loses the band' => array( $K, 'does not fill with --p-btn-face-hover', array(
        array( $C, ".uc-form-links-open:hover { background: var(--p-btn-face-hover);", ".uc-form-links-open:hover { background: #ffffff;" ),
    ) ),
    'Fetch updates is solid teal again' => array( $L, 'PLANT P:', 'caladmin-pending', array(
        array( $C, '.uc-btn-utility { background: var(--p-btn-face); border-color: var(--p-btn-edge); color: var(--p-btn-edge); }', '.uc-btn-utility { background: var(--uc-accent-text); border-color: var(--uc-accent-text); color: #fff; }' ),
    ) ),
    'Remove this question is grey' => array( $L, 'PLANT P:', 'public-request-form', array(
        array( $C, '.uc-faq-row .uc-faq-remove { color: var(--p-danger-text); background: #fff; border-color: var(--p-danger-text); }', '.uc-faq-row .uc-faq-remove { color: var(--p-danger-text); background: #D1D3D4; }' ),
    ) ),
    'the red outline is filled red' => array( $L, 'PLANT P:', 'dialog-outline', array(
        array( $C, '.uc-btn-danger-outline { background: #fff; border-color: #c0392b; color: #c0392b; }', '.uc-btn-danger-outline { background: #c0392b; border-color: #c0392b; color: #fff; }' ),
    ) ),
    'the quiet button is grey on grey' => array( $T, "FAIL  A: Media's quiet button", array(
        array( $C, '.uc-btn-quiet { background: #fff; border-color: #6B6764; color: #6B6764; }', '.uc-btn-quiet { color: #6B6764; }' ),
    ) ),
    'Checked in is pale' => array( $T, 'FAIL  A: Checked in is solid teal', array(
        array( $C, '.uc-rsvp-table .uc-checkin-btn[aria-pressed="true"] { border-color: var(--uc-accent-text); color: #fff; background: var(--uc-accent-text); }', '.uc-rsvp-table .uc-checkin-btn[aria-pressed="true"] { border-color: var(--uc-accent-text); color: var(--uc-accent-text); background: var(--uc-band); }' ),
    ) ),
    'Publish N drafts is secondary' => array( $T, 'FAIL  B: Publish N drafts is yellow', array(
        array( $P, "<button type=\"submit\" class=\"uc-btn uc-btn-sm uc-btn-primary\"\n                            name=\"uc_do\" value=\"publish\"", "<button type=\"submit\" class=\"uc-btn uc-btn-sm\"\n                            name=\"uc_do\" value=\"publish\"" ),
    ) ),
    'Set team on N events is secondary' => array( $T, 'FAIL  B: Set team on N events is yellow', array(
        array( $P, "<button type=\"submit\" class=\"uc-btn uc-btn-sm uc-btn-primary\"\n                                    name=\"uc_do\" value=\"team\"", "<button type=\"submit\" class=\"uc-btn uc-btn-sm\"\n                                    name=\"uc_do\" value=\"team\"" ),
    ) ),
    'Add organizer is secondary' => array( $L, 'PLANT P:', 'caladmin-organizers', array(
        array( $P, '<button type="submit" class="uc-btn uc-btn-primary">Add organizer</button>', '<button type="submit" class="uc-btn">Add organizer</button>' ),
    ) ),
    'Reset to default is R' => array( $T, 'FAIL  B: Reset to default is the red outline', array(
        array( $P, 'class="uc-btn uc-btn-danger-outline" name="tpl_do" value="reset"', 'class="uc-btn" name="tpl_do" value="reset"' ),
    ) ),
    'Delete them anyway is R' => array( $T, 'FAIL  B: Delete them anyway is the red outline', array(
        array( $P, 'value="delete" class="uc-btn uc-btn-danger-outline" data-uc-delete-anyway>', 'value="delete" class="uc-btn" data-uc-delete-anyway>' ),
    ) ),
    'a red outline confirms in yellow' => array( $L, 'PLANT P:', 'dialog-outline', array(
        array( $J, " || btn.classList.contains('uc-btn-danger-outline');", ';' ),
    ) ),
    'Discard is a button' => array( $T, 'FAIL  B: Discard is a red text link', array(
        array( $S, "no.className = 'uc-link-danger';", "no.className = 'uc-btn';" ),
    ) ),
    "WP admin's button keeps core's grey" => array( $L, 'PLANT P:', 'wp-settings', array(
        array( $A, "    border-radius: 8px; border: 1px solid var(--uc-teal-text); background: #fff;\n    color: var(--uc-teal-text);", "    border-radius: 8px; border: 1px solid var(--uc-border-strong); background: #f6f7f7;\n    color: var(--uc-text);" ),
    ) ),
    'Clear the log is R' => array( $T, 'FAIL  C: Clear the log is the red outline', array(
        array( $AD, '<button type="submit" class="button uc-button-danger-outline">Clear the log</button>', '<button type="submit" class="button">Clear the log</button>' ),
    ) ),
    'the row x is warm again' => array( $T, 'FAIL  C: the row x is red ink', array(
        array( $A, '.uc-repeater-remove { color: #c0392b !important;', '.uc-repeater-remove { color: #E8573A !important;' ),
    ) ),
    'the public stylesheet changes' => array( $T, 'FAIL  E: public/css/calendar.css', array(
        array( 'public/css/calendar.css', "    background: var(--uc-teal); color: var(--uc-dark); border-color: var(--uc-teal-text);\n}", "    background: #E8F9FA; color: var(--uc-teal-text); border-color: var(--uc-teal-text);\n}" ),
    ) ),
);
$missed = 0;
$only = isset( $argv[1] ) ? $argv[1] : '';
foreach ( $plants as $name => $p ) {
    if ( '' !== $only && false === strpos( $name, $only ) ) { continue; }
    $pagesfor = ( 4 === count( $p ) ) ? $p[2] : '';
    list( $check, $want ) = $p;
    $edits = end( $p );
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
    $args = ( false !== strpos( $check, '-live' ) ) ? ' --run' . ( '' !== $pagesfor ? ' --only=' . $pagesfor : '' ) : '';
    $out = shell_exec( 'cd ' . escapeshellarg( $root ) . ' && php ' . escapeshellarg( __DIR__ . '/' . $check ) . $args . ' 2>&1' );
    foreach ( $saved as $sp => $orig ) { file_put_contents( $sp, $orig ); }
    $caught = ( false !== strpos( (string) $out, $want ) );
    echo ( $caught ? 'caught  ' : 'MISSED  ' ) . $name . "\n";
    if ( ! $caught ) { $missed++; echo '        ' . str_replace( "\n", "\n        ", trim( substr( (string) $out, -1500 ) ) ) . "\n"; }
}
echo $missed ? "$missed PLANT(S) MISSED\n" : "every plant caught.\n";
exit( $missed ? 1 : 0 );
