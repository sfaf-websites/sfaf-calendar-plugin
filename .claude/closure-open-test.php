<?php
/**
 * WHAT STAYS OPEN ON A CLOSED DAY: THE MODEL AND THE THREE RENDERERS (3.105.0).
 *
 *     php .claude/closure-open-test.php
 *
 * Through wp-kit.php, so SFAF_Closures, SFAF_Venues, the AP time formatter and
 * the shortcode renderers are the real ones. The venues are wp-kit's two terms,
 * Alpha (11) and Beta (12).
 *
 *   the model     rows survive all(), which rebuilds every closure from a fixed
 *                 list of keys (the note was silently dropped by exactly that
 *                 for its first draft); an incomplete row is not stored; a
 *                 venue that does not exist is refused on the way in and a
 *                 venue deleted later drops its line rather than naming nothing.
 *   the phrase    "Alpha open 10 am–2 pm", en dash, through the formatter.
 *   the renders   the grid cell (which the day panel clones), its spoken label,
 *                 and the list card each carry every line, under the word.
 *   the colour    the line is set in --uc-open-ink, which is the green family's
 *                 ink from the category ramp and measures past 4.5:1 on white;
 *                 never the brand green. The rendered colour is read again in
 *                 Chrome by closure-live.php.
 */

require __DIR__ . '/wp-kit.php';

$fails = array();
function co( $label, $got, $want ) {
    global $fails;
    if ( $got !== $want ) { $fails[] = $label . ': got ' . var_export( $got, true ) . ', wanted ' . var_export( $want, true ); }
}

/* ---- The model. -------------------------------------------------------- */
$id = SFAF_Closures::save( '', 'Labor Day', '2026-09-07', '', 'Call ahead', array(
    array( 'venue' => 11, 'from' => '10:00', 'to' => '14:00' ),
    array( 'venue' => 12, 'from' => '09:30', 'to' => '17:00' ),
    array( 'venue' => 11, 'from' => '10:00', 'to' => '' ),        // incomplete
    array( 'venue' => 0, 'from' => '10:00', 'to' => '12:00' ),     // no venue
    array( 'venue' => 999, 'from' => '10:00', 'to' => '12:00' ),   // no such venue
) );
co( 'a closure with still-open rows saves', is_string( $id ), true );
$row = SFAF_Closures::get( $id );
co( 'the complete rows survive all(), and only those', $row['open'], array(
    array( 'venue' => 11, 'from' => '10:00', 'to' => '14:00' ),
    array( 'venue' => 12, 'from' => '09:30', 'to' => '17:00' ),
) );
co( 'the note is still its own field', SFAF_Closures::note( $row ), 'Call ahead' );

$en = "\xE2\x80\x93";
co( 'each row reads as one line, through the formatter', SFAF_Closures::open_lines( $row ), array(
    'Alpha open 10 am' . $en . '2 pm',
    'Beta open 9:30 am' . $en . '5 pm',
) );

/* A row stored before 3.105.0 has no key at all. */
$raw = get_option( SFAF_Closures::OPTION );
$raw['old'] = array( 'label' => 'Old', 'start' => '2026-01-01', 'end' => '2026-01-01', 'note' => '' );
update_option( SFAF_Closures::OPTION, $raw );
co( 'a closure from before 3.105.0 has nothing open', SFAF_Closures::open_lines( SFAF_Closures::get( 'old' ) ), array() );

/* A venue deleted after the closure was saved drops its line. */
$raw = get_option( SFAF_Closures::OPTION );
$raw[ $id ]['open'][] = array( 'venue' => 777, 'from' => '08:00', 'to' => '09:00' );
update_option( SFAF_Closures::OPTION, $raw );
co( 'a venue that no longer exists names nothing', count( SFAF_Closures::open_lines( SFAF_Closures::get( $id ) ) ), 2 );

/* ---- The renders. ------------------------------------------------------ */
$sc = ( new ReflectionClass( 'SFAF_Shortcodes' ) )->newInstanceWithoutConstructor();
$card = $sc->render_closure_card( SFAF_Closures::get( $id ) );
co( 'the list card carries both lines', substr_count( $card, 'class="uc-closed-open"' ), 2 );
co( 'under the word Closed', strpos( $card, 'uc-closed-word' ) < strpos( $card, 'uc-closed-open' ), true );
co( 'and before the note', strpos( $card, 'uc-closed-open' ) < strpos( $card, 'uc-closure-note' ), true );

$src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-sfaf-shortcodes.php' );
co( 'the grid cell draws the lines inside the mark the day panel clones',
    (bool) preg_match( '#<span class="uc-day-closed-mark"[^>]*>.*?SFAF_Closures::open_lines\( \$closed_row \).*?</span>\s*<\?php endif; \?>#s', $src ), true );
co( 'and the spoken label carries them',
    (bool) preg_match( '#foreach \( SFAF_Closures::open_lines\( \$closed_row \) as \$open_line \) \{\s*\$closed_said \.=#', $src ), true );

/* ---- The colour. ------------------------------------------------------- */
$css = (string) file_get_contents( dirname( __DIR__ ) . '/public/css/calendar.css' );
preg_match( '/--uc-open-ink:\s*(#[0-9A-Fa-f]{6})/', $css, $m );
$ink = isset( $m[1] ) ? strtoupper( $m[1] ) : '';
co( 'the line\'s ink is the green family\'s, from the category ramp', $ink, strtoupper( sfaf_category_shades( '#8CC745' )['ink'] ) );
co( 'the line is set in that token', (bool) preg_match( '/\.uc-closed-open \{[^}]*color: var\( --uc-open-ink \)/', $css ), true );
co( 'and in no other colour anywhere', preg_match_all( '/\.uc-closed-open[^{]*\{[^}]*\bcolor:/', $css ), 1 );

function co_lum( $hex ) {
    $c = array();
    foreach ( array( 1, 3, 5 ) as $i ) {
        $v = hexdec( substr( $hex, $i, 2 ) ) / 255;
        $c[] = $v <= 0.03928 ? $v / 12.92 : pow( ( $v + 0.055 ) / 1.055, 2.4 );
    }
    return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
}
function co_ratio( $a, $b ) { $x = co_lum( $a ); $y = co_lum( $b ); return ( max( $x, $y ) + 0.05 ) / ( min( $x, $y ) + 0.05 ); }
$r = '' !== $ink ? co_ratio( $ink, '#FFFFFF' ) : 0;
co( 'the ink clears 4.5:1 on the white panel (' . round( $r, 2 ) . ':1)', $r >= 4.5, true );
co( 'the brand green would not (' . round( co_ratio( '#8CC745', '#FFFFFF' ), 2 ) . ':1)', co_ratio( '#8CC745', '#FFFFFF' ) < 4.5, true );

if ( $fails ) {
    echo 'CLOSURE OPEN: ' . count( $fails ) . " FAILURE(S)\n  - " . implode( "\n  - ", $fails ) . "\n";
    exit( 1 );
}
printf( "closure open: rows survive the read, each is a line under the word in every renderer, in the green ink at %.2f:1 on white.\n", $r );
