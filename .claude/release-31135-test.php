<?php
/**
 * 3.113.5, THE PARTS A TEST CAN SETTLE WITHOUT A BROWSER.
 *
 *     php .claude/release-31135-test.php
 *
 *   2  the editor's bar is white --p-panel under a 1px Dark Gray #373433 rule,
 *      with a soft shadow cast upward; edge to edge of the window below 600px;
 *      Cancel event keeps its #6B6764 edge, Save changes green, Delete the red
 *      link at the far left, each measured on white
 *
 * The bar as drawn is release-31135-live.php's; the public calendar is
 * embed-identity-test.php's. PLANTs in plant-31135.php.
 */

$root  = dirname( __DIR__ );
$fails = array();
$ran   = 0;
function rt( $label, $got, $want ) {
    global $fails, $ran;
    $ran++;
    if ( $got !== $want ) { $fails[] = $label . ': got ' . json_encode( $got ) . ', wanted ' . json_encode( $want ); }
}

$css = (string) file_get_contents( $root . '/public/css/portal.css' );
$bar = preg_match( '#\n\.uc-portal \.uc-form-actions-primary\.uc-editor-bar \{([^}]*)\}#', $css, $bm ) ? $bm[1] : '';
rt( '2: the bar rule is there', '' !== $bar, true );
rt( '2: the bar is white --p-panel', false !== strpos( $bar, 'background: var(--p-panel);' ), true );
rt( '2: --p-panel is white', false !== strpos( $css, '--p-panel: #ffffff;' ), true );
rt( '2: under a 1px Dark Gray rule', false !== strpos( $bar, 'border-top: 1px solid #373433;' ), true );
rt( '2: with no side borders and no radius', false !== strpos( $bar, 'border: 0; border-top:' ) && false !== strpos( $bar, 'border-radius: 0;' ), true );
rt( '2: a soft shadow cast upward', (bool) preg_match( '#box-shadow: 0 -\d+px \d+px rgba\(55, 52, 51, 0\.1\d*\);#', $bar ), true );
rt( '2: still sticky to the foot', false !== strpos( $bar, 'position: sticky; bottom: 0;' ), true );
rt( '2: edge to edge below 600px', (bool) preg_match( '#@media \(max-width: 600px\) \{[^@]*?\.uc-portal \.uc-form-actions-primary\.uc-editor-bar \{ margin: 24px -18px 0; padding: 10px 18px; gap: 8px; \}#s', $css ), true );
rt( '2: through the 18px gutter it has there', (bool) preg_match( '#@media \(max-width: 720px\) \{[^}]*\}[^@]*?\.uc-portal-content \{ padding: 18px; \}#s', $css ), true );
rt( '2: Cancel event\'s edge is --p-muted', false !== strpos( $css, '.uc-editor-actions .uc-cancel-open { border-color: var(--p-muted); }' ), true );
rt( '2: --p-muted is #6B6764', false !== strpos( $css, '--p-muted: #6B6764;' ), true );
rt( '2: Cancel event has no face', false !== strpos( $css, ".uc-editor-actions .uc-cancel-open,\n.uc-editor-actions .uc-cancel-open:hover { background: transparent; }" ), true );
rt( '2: Delete stays at the far left', false !== strpos( $css, '.uc-editor-actions .uc-editor-delete  { order: -1; }' ), true );
rt( '2: Save changes stays green', false !== strpos( $css, '.uc-portal .uc-btn-go { background: #15803D;' ), true );

function rt_lum( $h ) { $c = array(); foreach ( array( 1, 3, 5 ) as $i ) { $v = hexdec( substr( $h, $i, 2 ) ) / 255; $c[] = $v <= 0.03928 ? $v / 12.92 : pow( ( $v + 0.055 ) / 1.055, 2.4 ); } return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2]; }
function rt_ratio( $a, $b ) { $x = rt_lum( $a ); $y = rt_lum( $b ); return ( max( $x, $y ) + 0.05 ) / ( min( $x, $y ) + 0.05 ); }
rt( '2: Cancel event\'s edge on white is 5.6:1', round( rt_ratio( '#6B6764', '#FFFFFF' ), 2 ), 5.6 );
rt( '2: Delete\'s red on white clears 4.5:1', rt_ratio( '#c0392b', '#FFFFFF' ) >= 4.5, true );
rt( '2: the Dark Gray rule against the grey page clears 3:1', rt_ratio( '#373433', '#F5F6F7' ) >= 3, true );
rt( '2: and against the white bar', rt_ratio( '#373433', '#FFFFFF' ) >= 3, true );

echo "\nRelease 3.113.5\n" . str_repeat( '=', 72 ) . "\nran $ran assertions.\n";
if ( $fails ) {
    echo count( $fails ) . " failure(s):\n";
    foreach ( $fails as $f ) { echo '  - ' . $f . "\n"; }
    exit( 1 );
}
echo "The editor's bar is white under a Dark Gray rule with a shadow cast upward; Cancel event's edge is 5.60:1 on it.\n";
