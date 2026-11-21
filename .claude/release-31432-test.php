<?php
/**
 * 3.143.2: THE ANNOUNCEMENT BAR RESTYLED.
 *
 *     php .claude/release-31432-test.php
 *
 * REAL: calendar.css, read as text. The rendered bar is measured in Chrome by
 * release-31431-live.php (PLANT A and W).
 *
 *   B  the bar: no border on any side; a 1px line at the top and the bottom,
 *      each the gradient Light Gray #D1D3D4 to Dark Gray #373433 in the middle
 *      to Light Gray, as background images over the #FFFAE0 fill; corners
 *      rounded 3px; words weight 500 (Montserrat 500 is in the font request).
 *      The hover sets background-color, never the shorthand that would take
 *      the lines away.
 *   C  the cell's bar 3px in from each side: the td's 5px padding less 2px.
 *   S  both strip copies: no border, the same two lines over #FFFAE0, 6px.
 *   N  the banner, the sidebar's bar and the day panel's bar set no radius,
 *      border or background of their own, so they are the bar.
 *
 * PLANT in plant-31432.php.
 */

$fails = array();
$n     = 0;
function rt( $why, $ok ) { global $fails, $n; $n++; if ( ! $ok ) { $fails[] = $why; } }

$css  = (string) file_get_contents( dirname( __DIR__ ) . '/public/css/calendar.css' );
$rule = function ( $sel ) use ( $css ) { return preg_match( '/(?:^|\})\s*' . preg_quote( $sel, '/' ) . '\s*\{([^}]*)\}/m', $css, $x ) ? $x[1] : ''; };
$LINE = 'linear-gradient(90deg, #D1D3D4, #373433 50%, #D1D3D4)';

/* ---- B ---- */
$bar   = $rule( '.uc-calendar .uc-ann-bar, .uc-sidebar .uc-ann-bar' );
$hover = $rule( ".uc-calendar button.uc-ann-bar:hover, .uc-sidebar button.uc-ann-bar:hover,\n.uc-calendar button.uc-ann-bar[aria-expanded=\"true\"], .uc-sidebar button.uc-ann-bar[aria-expanded=\"true\"]" );
rt( 'B: the bar rule was not found', '' !== $bar );
rt( 'B: the bar has a border: ' . trim( $bar ), false !== strpos( $bar, 'border: 0;' ) && ! preg_match( '/border(-left|-right|-top|-bottom)?(-width|-color|-style)?:\s*(?!0;)/', str_replace( 'border: 0;', '', str_replace( 'border-radius', 'radius', $bar ) ) ) );
rt( 'B: the bar\'s line is not Light Gray to Dark Gray to Light Gray: ' . trim( $bar ), false !== strpos( $bar, '--uc-ann-line: ' . $LINE . ';' ) );
rt( 'B: the bar does not draw the line top and bottom, 1px, once each', false !== strpos( $bar, 'background-image: var(--uc-ann-line), var(--uc-ann-line);' )
    && false !== strpos( $bar, 'background-position: left top, left bottom;' ) && false !== strpos( $bar, 'background-size: 100% 1px;' ) && false !== strpos( $bar, 'background-repeat: no-repeat;' ) );
rt( 'B: the bar is not #FFFAE0', false !== strpos( $bar, 'background-color: #FFFAE0;' ) );
rt( 'B: the bar\'s corners are not 3px', false !== strpos( $bar, 'border-radius: 3px;' ) );
rt( 'B: the bar\'s words are not weight 500', (bool) preg_match( '/font-weight: 500;/', $bar ) && false === strpos( $bar, 'font-weight: 700;' ) );
rt( 'B: Montserrat 500 is not in the font request', (bool) preg_match( '/family=Montserrat:wght@[0-9;]*\b500\b/', $css ) );
rt( 'B: the hover uses the background shorthand, which takes the lines away: ' . trim( $hover ), '' !== $hover && false === strpos( $hover, 'background:' ) && false !== strpos( $hover, 'background-color: #FFF8D1;' ) );
rt( 'B: #705F00 is still drawn somewhere on an announcement', ! preg_match( '/uc-ann[^{]*\{[^}]*#705F00/', $css ) );

/* ---- C ---- */
rt( 'C: the cell\'s bars are not 2px into the td\'s 5px padding: ' . $rule( '.uc-calendar td .uc-ann-bars' ),
    false !== strpos( $rule( '.uc-calendar td .uc-ann-bars' ), 'margin: 0 -2px 4px;' ) && (bool) preg_match( '/\.uc-calendar \.uc-month-grid td \{[^}]*padding: 5px;/', $css ) );
$cell = $rule( '.uc-calendar td .uc-ann-bar' );
rt( 'C: the cell\'s bar sets its own radius or border: ' . $cell, '' !== $cell && false === strpos( $cell, 'radius' ) && false === strpos( $cell, 'border' ) );

/* ---- S ---- */
$want   = '.uc-calendar .uc-ann-strip { display: block; box-sizing: border-box; height: 6px; margin: 0 3px 3px; border: 0; border-radius: 2px; --uc-ann-line: ' . $LINE . '; background-color: #FFFAE0; background-image: var(--uc-ann-line), var(--uc-ann-line); background-position: left top, left bottom; background-size: 100% 1px; background-repeat: no-repeat; }';
$strips = substr_count( $css, $want );
rt( "S: $strips of the strip's two copies are the bar's style", 2 === $strips );
rt( 'S: a strip still has an edge', ! preg_match( '/\.uc-ann-strip \{ display: block;[^}]*border: 1px/', $css ) );

/* ---- N ---- */
foreach ( array(
    'the banner'            => '.uc-calendar .uc-ann-banner',
    'the sidebar\'s bar'    => '.uc-calendar .uc-ann-side .uc-ann-bar, .uc-sidebar .uc-ann-side .uc-ann-bar',
) as $what => $sel ) {
    $r = $rule( $sel );
    rt( "N: $what's rule was not found", '' !== $r );
    rt( "N: $what sets its own radius, border or background: $r", false === strpos( $r, 'radius' ) && false === strpos( $r, 'border' ) && false === strpos( $r, 'background' ) );
}
$panels = preg_match_all( '/\.uc-calendar \.uc-month-day-panel \.uc-ann-bar \{([^}]*)\}/', $css, $pm );
rt( "N: $panels day panel bar rules, not 2", 2 === $panels );
foreach ( (array) $pm[1] as $r ) { rt( "N: the day panel's bar sets its own radius, border or background: $r", false === strpos( $r, 'radius' ) && false === strpos( $r, 'border' ) && false === strpos( $r, 'background' ) ); }

echo "\n3.143.2: the announcement bar restyled\n" . str_repeat( '=', 72 ) . "\n";
if ( $fails ) { echo count( $fails ) . " of $n FAILED:\n  - " . implode( "\n  - ", $fails ) . "\n"; exit( 1 ); }
echo "$n checks passed.\n";
exit( 0 );
