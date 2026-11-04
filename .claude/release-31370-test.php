<?php
/**
 * 3.137.0: THE SECONDARY BUTTON IS R.
 *
 *     php .claude/release-31370-test.php
 *
 * Reads the shipped CSS, markup and script. R is the look of Set team: white,
 * a 1px #0E7680 edge, #0E7680 semibold ink, 8px corners, #E8F9FA on hover.
 *
 *   A. caladmin: the tokens and .uc-btn are R, the plain-button and file
 *      fallbacks too; the solid teal utility is R; the red outline; Media's
 *      quiet button; Remove this question; Check in and Checked in.
 *   B. The exceptions in the markup: Publish N drafts, Set team on N events
 *      and Add organizer yellow; Delete them anyway and Reset to default red
 *      outlines; Discard a red text link; a red-outline trigger confirms red.
 *   C. WP admin through admin.css: the plain button R, the red outline on
 *      Clear the log, a closure's Remove and Remove this question, the row x
 *      red ink, + Add Campaign teal.
 *   D. Every pair measured, and DESIGN.md says R.
 *   E. The public calendar's stylesheet and script are 3.136.0's.
 *
 * The browser half is release-31370-live.php; PLANTs in plant-31370.php.
 */

$root  = dirname( __DIR__ );
$fails = array();
$ran   = 0;
function rt( $label, $ok ) { global $fails, $ran; $ran++; if ( ! $ok ) { $fails[] = $label; } }
function rt_ratio( $a, $b ) {
    $l = function ( $h ) {
        $h = ltrim( $h, '#' );
        $c = array_map( function ( $x ) { $x = hexdec( $x ) / 255; return $x <= 0.03928 ? $x / 12.92 : pow( ( $x + 0.055 ) / 1.055, 2.4 ); }, str_split( $h, 2 ) );
        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    };
    $x = $l( $a ); $y = $l( $b );
    return ( max( $x, $y ) + 0.05 ) / ( min( $x, $y ) + 0.05 );
}
$css   = (string) file_get_contents( $root . '/public/css/portal.css' );
$admin = (string) file_get_contents( $root . '/admin/css/admin.css' );
$P     = (string) file_get_contents( $root . '/includes/class-sfaf-portal.php' );
$JS    = (string) file_get_contents( $root . '/public/js/portal.js' );
$SJ    = (string) file_get_contents( $root . '/public/js/caladmin-session.js' );
$AD    = (string) file_get_contents( $root . '/admin/class-sfaf-admin.php' );
$TF    = (string) file_get_contents( $root . '/includes/sfaf-template-functions.php' );
$D     = (string) file_get_contents( $root . '/DESIGN.md' );
function rt_rule( $src, $sel ) {
    // The body of the first rule whose selector list is exactly $sel.
    if ( preg_match( '/(^|\})\s*' . preg_quote( $sel, '/' ) . '\s*\{([^}]*)\}/m', $src, $m ) ) { return $m[2]; }
    return '';
}

/* ======================================================================== A */
rt( 'A: the face is white, the hover the band, the edge #0E7680', false !== strpos( $css, '--p-btn-face: #ffffff;' ) && false !== strpos( $css, '--p-btn-face-hover: #E8F9FA;' ) && false !== strpos( $css, '--p-btn-edge: #0E7680;' ) );
rt( 'A: no Light Gray face left in the tokens', 0 === preg_match( '/--p-btn-face[a-z-]*:\s*#(D1D3D4|BFC2C4)/i', $css ) );
$btn = rt_rule( $css, ".uc-btn,\n.uc-form-links-open" );
rt( 'A: .uc-btn is R: white, teal edge, teal ink, 8px, semibold', false !== strpos( $btn, 'border: 1px solid var(--p-btn-edge); background: var(--p-btn-face); color: var(--p-btn-edge);' ) && false !== strpos( $btn, 'border-radius: 8px;' ) && false !== strpos( $btn, 'font-weight: 600;' ) );
rt( 'A: and the band on hover, teal kept', false !== strpos( $css, ".uc-form-links-open:hover { background: var(--p-btn-face-hover); border-color: var(--p-btn-edge); color: var(--p-btn-edge); text-decoration: none; }" ) );
rt( 'A: a button with no class of its own is R', false !== strpos( $css, "border: 1px solid var(--p-btn-edge); background: var(--p-btn-face); color: var(--p-btn-edge);\n    font-family: inherit; font-size: 14px;" ) );
rt( 'A: and the band on its hover, at zero specificity', false !== strpos( $css, ':where(.uc-portal) :where(button, input[type="submit"], input[type="button"], input[type="reset"]):hover {' ) );
rt( 'A: Choose file is R', false !== strpos( $css, "margin-right: 10px; padding: 6px 12px; border: 1px solid var(--p-btn-edge);\n    border-radius: 7px; background: var(--p-btn-face); color: var(--p-btn-edge);" ) );
rt( 'A: Fetch updates and Refresh from source are R, not solid teal', false !== strpos( $css, '.uc-btn-utility { background: var(--p-btn-face); border-color: var(--p-btn-edge); color: var(--p-btn-edge); }' ) && false === strpos( $css, '.uc-btn-utility { background: var(--uc-accent-text)' ) );
rt( 'A: the red outline', false !== strpos( $css, '.uc-btn-danger-outline { background: #fff; border-color: #c0392b; color: #c0392b; }' ) && false !== strpos( $css, '.uc-btn-danger-outline:hover { background: #FEF2F2; border-color: #c0392b; color: #c0392b; }' ) );
rt( 'A: Remove this question is the red outline', false !== strpos( $css, '.uc-faq-row .uc-faq-remove { color: var(--p-danger-text); background: #fff; border-color: var(--p-danger-text); }' ) );
rt( "A: Media's quiet button is white with #6B6764", false !== strpos( $css, '.uc-btn-quiet { background: #fff; border-color: #6B6764; color: #6B6764; }' ) );
rt( 'A: Checked in is solid teal with white ink', false !== strpos( $css, '.uc-rsvp-table .uc-checkin-btn[aria-pressed="true"] { border-color: var(--uc-accent-text); color: #fff; background: var(--uc-accent-text); }' ) );
rt( 'A: Check in before is R (no rule of its own for the face)', 0 === preg_match( '/\.uc-checkin-btn(?!\[aria-pressed)[^{]*\{[^}]*background/', $css ) );
rt( 'A: Set team, the reference, is unchanged', false !== strpos( $css, '.uc-portal .uc-outline-btn:hover { background-color: var(--uc-band); }' ) );

/* ======================================================================== B */
rt( 'B: Publish N drafts is yellow', 1 === preg_match( '/class="uc-btn uc-btn-sm uc-btn-primary"\s+name="uc_do" value="publish"/', $P ) );
rt( 'B: Set team on N events is yellow', 1 === preg_match( '/class="uc-btn uc-btn-sm uc-btn-primary"\s+name="uc_do" value="team"/', $P ) );
rt( 'B: Add to N events stays secondary', 1 === preg_match( '/class="uc-btn uc-btn-sm"\s+name="uc_do" value="categorize"/', $P ) );
rt( 'B: Add organizer is yellow', false !== strpos( $P, '<button type="submit" class="uc-btn uc-btn-primary">Add organizer</button>' ) );
rt( 'B: Delete them anyway is the red outline', false !== strpos( $P, 'value="delete" class="uc-btn uc-btn-danger-outline" data-uc-delete-anyway>' ) );
rt( 'B: Reset to default is the red outline', false !== strpos( $P, 'class="uc-btn uc-btn-danger-outline" name="tpl_do" value="reset" data-uc-tpl-reset' ) );
rt( 'B: Discard is a red text link, Restore R', false !== strpos( $SJ, "no.className = 'uc-link-danger';" ) && false !== strpos( $SJ, "yes.className = 'uc-btn';" ) );
rt( 'B: a red-outline trigger confirms in red', false !== strpos( $JS, "btn.classList.contains('uc-btn-danger') || btn.classList.contains('uc-btn-danger-outline');" ) );
rt( 'B: Put it back on and tell them is yellow (no class falls back to the primary)', false !== strpos( $JS, "sendClass: back ? '' : 'uc-btn-danger'," ) && false !== strpos( $JS, "send.className = 'uc-btn uc-btn-sm ' + (opts.sendClass || 'uc-btn-primary');" ) );
rt( 'B: Remove this question keeps the class the outline is drawn on', false !== strpos( $TF, 'uc-repeater-remove uc-faq-remove">Remove this question</button>' ) );

/* ======================================================================== C */
rt( "C: WP admin's plain button is R", false !== strpos( $admin, "    border-radius: 8px; border: 1px solid var(--uc-teal-text); background: #fff;\n    color: var(--uc-teal-text); font-weight: 600; box-shadow: none;" ) );
rt( 'C: and the band on hover', false !== strpos( $admin, "border-color: var(--uc-teal-text); color: var(--uc-teal-text); background: #E8F9FA;" ) );
rt( 'C: the red outline, Remove this question in the meta box too', false !== strpos( $admin, '.uc-admin-wrap .button.uc-button-danger-outline, .uc-meta-box .button.uc-faq-remove,' ) && false !== strpos( $admin, 'border-color: #c0392b; background: #fff; color: #c0392b !important;' ) );
rt( 'C: Clear the log is the red outline', false !== strpos( $AD, '<button type="submit" class="button uc-button-danger-outline">Clear the log</button>' ) );
rt( "C: a closure's Remove is the red outline", false !== strpos( $AD, '<button type="submit" class="button button-small uc-button-danger-outline">Remove</button>' ) );
rt( 'C: the row x is red ink and no box', false !== strpos( $admin, '.uc-repeater-remove { color: #c0392b !important;' ) && false !== strpos( $admin, '.uc-admin-wrap .button.uc-repeater-remove:not(.uc-faq-remove) { border-color: transparent; background: transparent; }' ) );
rt( 'C: four x buttons wear it', 4 === substr_count( $AD, 'class="button uc-repeater-remove" aria-label="Remove">&times;</button>' ) );
rt( '+ Add Campaign is teal ink, not the brand teal', false !== strpos( $admin, '.uc-repeater-add { color: var(--uc-teal-text) !important; }' ) );

/* ======================================================================== D */
foreach ( array(
    array( 'R words on white', '0E7680', 'FFFFFF', 4.5 ),
    array( 'R words on the band', '0E7680', 'E8F9FA', 4.5 ),
    array( 'R edge on the page', '0E7680', 'F5F6F7', 3.0 ),
    array( 'red outline on white', 'C0392B', 'FFFFFF', 4.5 ),
    array( 'red outline on its hover', 'C0392B', 'FEF2F2', 4.5 ),
    array( 'quiet on white', '6B6764', 'FFFFFF', 4.5 ),
    array( 'quiet hover, Dark Gray on the band', '373433', 'E8F9FA', 4.5 ),
    array( 'Checked in, white on teal', 'FFFFFF', '0E7680', 4.5 ),
    array( 'Checked in hover, white on #0C666F', 'FFFFFF', '0C666F', 4.5 ),
    array( 'the row x, red on white', 'C0392B', 'FFFFFF', 4.5 ),
    array( '+ Add Campaign, teal on white', '0E7680', 'FFFFFF', 4.5 ),
) as $m ) {
    rt( "D: {$m[0]} is at least {$m[3]}:1", rt_ratio( $m[1], $m[2] ) + 0.005 >= $m[3] );
}
rt( 'D: DESIGN.md says the secondary button is R', false !== strpos( $D, "**THE SECONDARY BUTTON IS \"R\" (3.137.0), MARK'S DECISION**" ) && false !== strpos( $D, '| Secondary button, "R" | white, ink `#0E7680` |' ) );
rt( 'D: and no longer that the outlined button is not the secondary button', false === strpos( $D, '**The outlined button is not the secondary button**' ) && false !== strpos( $D, '**The outlined button is the secondary button** from 3.137.0' ) );
rt( 'D: and no longer that the secondary face is Light Gray', false === strpos( $D, '| Secondary button | Light Gray `#D1D3D4`' ) );
$audit = (string) shell_exec( 'php ' . escapeshellarg( __DIR__ . '/control-standard-audit.php' ) . ' 2>&1' );
rt( 'D: the control-standard check passes and asks for R', false !== strpos( $audit, 'PASS.' ) && false !== strpos( $audit, 'R: teal ink on white' ) );

/* ======================================================================== E */
foreach ( array( 'public/css/calendar.css', 'public/js/calendar.js' ) as $f ) {
    $was = (string) shell_exec( 'git -C ' . escapeshellarg( $root ) . ' show ceb126a:' . $f . ' 2>&1' );
    rt( "E: $f is 3.136.0's", '' !== $was && str_replace( "\r\n", "\n", $was ) === str_replace( "\r\n", "\n", (string) file_get_contents( $root . '/' . $f ) ) );
}

echo "\n3.137.0: the secondary button is R\n" . str_repeat( '=', 72 ) . "\n";
echo "release-31370: $ran checks\n";
if ( $fails ) { foreach ( $fails as $f ) { echo "FAIL  $f\n"; } exit( 1 ); }
echo "every check passed.\n";
exit( 0 );
