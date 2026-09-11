<?php
/**
 * EVERY CATEGORY COLOUR CAN CARRY ITS OWN ICON, AND NO TWO LOOK ALIKE.
 *
 *     php .claude/palette-audit.php --self-test
 *     php .claude/palette-audit.php
 *     php .claude/palette-audit.php --propose        (candidate shades, ranked)
 *
 * WHY THIS EXISTS. The palette grew in 3.75.0 from ten colours to sixteen, and
 * both things that can go wrong when a palette grows have already gone wrong
 * here once. Six of the original ten measure under 3:1 on white, which is why
 * the category ring came off thumbnails in 3.31.0; and a shade that is only
 * slightly different from its parent is a choice nobody can make in a swatch
 * picker and nobody can read on a card.
 *
 * THE TWO FLOORS, AND WHAT EACH ONE PROTECTS.
 *
 *   THE ICON FLOOR. `sfaf_event_placeholder_svg()` fills a rectangle with the
 *   raw category colour and draws the icon and the label on it in whichever
 *   neutral wins, which is `sfaf_on_color()`. So the colour has to have a
 *   neutral that works. 3:1 is WCAG's large-text and non-text floor and the
 *   label there is 92px bold, so it is the right floor; 4.5:1 is reported as
 *   well, because a colour that clears it is one fewer thing to remember.
 *
 *   THE INK FLOOR. The chip, the ring and the small tile do not use the raw
 *   colour: they use the ink from `sfaf_category_shades()`, on white and on the
 *   page's own #F5F6F7. That is text and a meaningful shape, so 4.5:1 and
 *   3:1 respectively, and `.claude/category-ring-contrast.php` already watches
 *   the ink. This checks that every NEW colour has one at all.
 *
 * THE DISTINCTNESS FLOOR IS CIE76 IN Lab, NOT A HEX COMPARISON. Two hexes can
 * differ in every digit and look identical, and two that differ in one can look
 * nothing alike. dE 25 is the floor used here: at the 28px swatch this palette
 * renders at, a pair below it is a pair somebody will pick the wrong one from.
 *
 * @package SFAF_Calendar
 */

$root = dirname( __DIR__ );
$args = array_slice( $argv, 1 );
$self    = in_array( '--self-test', $args, true );
$propose = in_array( '--propose', $args, true );

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', $root . '/' );
}
require_once $root . '/includes/sfaf-template-functions.php';

/* ---------------------------------------------------------------------------
 * Colour arithmetic. sRGB luminance, WCAG ratio, and Lab for the eye.
 * ------------------------------------------------------------------------ */
function pa_rgb( $hex ) {
    $h = ltrim( strtoupper( trim( (string) $hex ) ), '#' );
    if ( 6 !== strlen( $h ) ) {
        return null;
    }
    return array( hexdec( substr( $h, 0, 2 ) ), hexdec( substr( $h, 2, 2 ) ), hexdec( substr( $h, 4, 2 ) ) );
}

function pa_lum( $hex ) {
    $rgb = pa_rgb( $hex );
    if ( null === $rgb ) {
        return 0.0;
    }
    $c = array();
    foreach ( $rgb as $v ) {
        $v   = $v / 255;
        $c[] = ( $v <= 0.03928 ) ? $v / 12.92 : pow( ( $v + 0.055 ) / 1.055, 2.4 );
    }
    return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
}

function pa_ratio( $a, $b ) {
    $la = pa_lum( $a );
    $lb = pa_lum( $b );
    return round( ( max( $la, $lb ) + 0.05 ) / ( min( $la, $lb ) + 0.05 ), 2 );
}

/** sRGB to CIE Lab, D65. */
function pa_lab( $hex ) {
    $rgb = pa_rgb( $hex );
    if ( null === $rgb ) {
        return array( 0, 0, 0 );
    }
    $lin = array();
    foreach ( $rgb as $v ) {
        $v     = $v / 255;
        $lin[] = ( $v <= 0.04045 ) ? $v / 12.92 : pow( ( $v + 0.055 ) / 1.055, 2.4 );
    }
    $x = ( 0.4124 * $lin[0] + 0.3576 * $lin[1] + 0.1805 * $lin[2] ) / 0.95047;
    $y = ( 0.2126 * $lin[0] + 0.7152 * $lin[1] + 0.0722 * $lin[2] ) / 1.00000;
    $z = ( 0.0193 * $lin[0] + 0.1192 * $lin[1] + 0.9505 * $lin[2] ) / 1.08883;
    $f = function ( $t ) {
        return ( $t > 0.008856 ) ? pow( $t, 1 / 3 ) : ( 7.787 * $t + 16 / 116 );
    };
    $fx = $f( $x );
    $fy = $f( $y );
    $fz = $f( $z );
    return array( 116 * $fy - 16, 500 * ( $fx - $fy ), 200 * ( $fy - $fz ) );
}

/** CIE76 distance. Good enough to answer "can a person tell these apart". */
function pa_delta_e( $a, $b ) {
    $la = pa_lab( $a );
    $lb = pa_lab( $b );
    return round( sqrt(
        pow( $la[0] - $lb[0], 2 ) + pow( $la[1] - $lb[1], 2 ) + pow( $la[2] - $lb[2], 2 )
    ), 1 );
}

function pa_mix( $hex, $towards, $amount ) {
    $a = pa_rgb( $hex );
    $b = pa_rgb( $towards );
    $out = '#';
    foreach ( array( 0, 1, 2 ) as $i ) {
        $out .= str_pad( strtoupper( dechex( (int) round( $a[ $i ] + ( $b[ $i ] - $a[ $i ] ) * $amount ) ) ), 2, '0', STR_PAD_LEFT );
    }
    return $out;
}

const PA_DARK  = '#373433';
const PA_WHITE = '#FFFFFF';
const PA_PAGE  = '#F5F6F7';

/** The best neutral on a colour, and what it measures. Mirrors sfaf_on_color(). */
function pa_icon_test( $hex ) {
    $d = pa_ratio( PA_DARK, $hex );
    $w = pa_ratio( PA_WHITE, $hex );
    return ( $d >= $w )
        ? array( 'on' => 'dark',  'ratio' => $d )
        : array( 'on' => 'white', 'ratio' => $w );
}

/* ---------------------------------------------------------------------------
 * --propose: candidate shades of each approved hue, ranked.
 *
 * TWO DIRECTIONS PER PARENT, because a mid-tone hue has no room to move without
 * turning into a different colour: a deep shade toward the brand's own Dark Gray
 * rather than toward black, so the family stays the brand's, and a light shade
 * toward white. Everything is then measured rather than looked at.
 * ------------------------------------------------------------------------ */
if ( $propose ) {
    echo "Candidate shades, measured\n";
    echo str_repeat( '=', 78 ) . "\n";
    printf( "%-12s %-9s %-9s %-8s %-6s %-6s %s\n", 'Parent', 'Parent hex', 'Candidate', 'icon on', 'ratio', 'dE', 'verdict' );
    foreach ( sfaf_brand_palette() as $hex => $label ) {
        foreach ( array( 'deep' => array( PA_DARK, 0.45 ), 'light' => array( PA_WHITE, 0.55 ) ) as $kind => $spec ) {
            $cand  = pa_mix( $hex, $spec[0], $spec[1] );
            $icon  = pa_icon_test( $cand );
            $de    = pa_delta_e( $hex, $cand );
            $ok    = ( $icon['ratio'] >= 3.0 && $de >= 25 );
            printf( "%-12s %-9s %-9s %-8s %-6s %-6s %s\n",
                $kind . ' ' . $label, $hex, $cand, $icon['on'], $icon['ratio'], $de,
                $ok ? 'usable' : 'no' );
        }
    }
    exit( 0 );
}

/* ---------------------------------------------------------------------------
 * The audit proper.
 * ------------------------------------------------------------------------ */
$palette = sfaf_brand_palette();
$fails   = array();
$rows    = array();

foreach ( $palette as $hex => $label ) {
    $icon   = pa_icon_test( $hex );
    $shades = sfaf_category_shades( $hex );
    $ink    = $shades['ink'];

    $rows[] = array(
        'label'   => $label,
        'hex'     => $hex,
        'on'      => $icon['on'],
        'icon'    => $icon['ratio'],
        'ink'     => $ink,
        'ink_w'   => pa_ratio( $ink, PA_WHITE ),
        'ink_p'   => pa_ratio( $ink, PA_PAGE ),
        'chip'    => pa_ratio( $ink, $shades['tint'] ),
        'media'   => pa_ratio( $ink, $shades['media'] ),
    );

    /*
     * THE CHIP PAIR, WHICH IS THE CONTRAST THE TABLE IN
     * sfaf_category_shades() EXISTS FOR. A chip is words on the colour's own
     * tint, not on white, and a pair that clears 4.5:1 on white can still fail
     * on its own tint: that is exactly what the old 12%-tint chip did at
     * 2.05:1. The ten originals are hand-measured stops; the six shades added
     * in 3.75.0 come from the computed fallback, so this is what says the
     * fallback is good enough for them rather than anybody assuming it.
     */
    if ( pa_ratio( $ink, $shades['tint'] ) < 4.5 ) {
        $fails[] = sprintf( '%s (%s) has ink %s at %s:1 on its own chip tint %s, under 4.5',
            $label, $hex, $ink, pa_ratio( $ink, $shades['tint'] ), $shades['tint'] );
    }
    if ( pa_ratio( $ink, $shades['media'] ) < 4.5 ) {
        $fails[] = sprintf( '%s (%s) has ink %s at %s:1 on its media tint %s, under 4.5',
            $label, $hex, $ink, pa_ratio( $ink, $shades['media'] ), $shades['media'] );
    }

    /*
     * THE ICON FLOOR IS 4.5, NOT 3, AND THE FIRST DRAFT OF THIS FILE HAD IT
     * WRONG IN A WAY THAT COULD NEVER FAIL.
     *
     * A 3:1 check against these two neutrals is arithmetically unfailable.
     * Dark Gray sits at luminance 0.0385, so clearing 3:1 against it needs
     * L < 0.2155, and clearing 3:1 against white needs L > 0.30. No colour can
     * be in both bands, and the worst any colour can do is the crossover at
     * L = 0.255, which measures 3.44:1. So "every colour clears 3:1" was a
     * statement about arithmetic and not about the palette, and it would have
     * passed any shade anybody ever added. That is the audit-scripts-fail-
     * silently lesson in PROJECT.md 7, caught here by its own self-test.
     *
     * 4.5 IS THE FLOOR THAT DISCRIMINATES, and it is the right one: the
     * placeholder draws an icon and a label, and a mid-tone colour with no
     * neutral over 4.5 is one where both look washed out at any size.
     *
     * TWO ARE EXEMPT BY NAME AND BOTH PREDATE THE RULE. Red and Pink are
     * approved brand colours from the guide, they are already documented as
     * large-text-only on sfaf_on_color(), and the placeholder label is 92px
     * bold, which is large by any reading. They are not a precedent: a NEW
     * shade has no such standing and has to clear the floor.
     */
    $icon_exempt = array( '#F04937', '#F1668C' );
    if ( $icon['ratio'] < 4.5 && ! in_array( $hex, $icon_exempt, true ) ) {
        $fails[] = sprintf( '%s (%s) has no neutral over 4.5:1, so its placeholder icon and label are washed out (best %s on %s)',
            $label, $hex, $icon['ratio'], $icon['on'] );
    }
    if ( pa_ratio( $ink, PA_WHITE ) < 4.5 ) {
        $fails[] = sprintf( '%s (%s) has ink %s at %s:1 on white, under the 4.5 a chip label needs',
            $label, $hex, $ink, pa_ratio( $ink, PA_WHITE ) );
    }
    if ( pa_ratio( $ink, PA_PAGE ) < 3.0 ) {
        $fails[] = sprintf( '%s (%s) has ink %s at %s:1 on the page, under the 3.0 a ring needs',
            $label, $hex, $ink, pa_ratio( $ink, PA_PAGE ) );
    }
}

/* EVERY PAIR, NOT EVERY PARENT. A shade has to be distinct from the colour it
 * came from AND from everything else in the picker, and a set that only checks
 * against the parent can quietly grow two near-identical cousins. */
$too_close = array();
$keys      = array_keys( $palette );
for ( $i = 0; $i < count( $keys ); $i++ ) {
    for ( $j = $i + 1; $j < count( $keys ); $j++ ) {
        $de = pa_delta_e( $keys[ $i ], $keys[ $j ] );
        if ( $de < 25 ) {
            $too_close[] = sprintf( '%s (%s) and %s (%s) are dE %s apart, under the 25 a swatch picker needs',
                $palette[ $keys[ $i ] ], $keys[ $i ], $palette[ $keys[ $j ] ], $keys[ $j ], $de );
        }
    }
}
$fails = array_merge( $fails, $too_close );

if ( $self ) {
    /* A checker that cannot fail is not evidence. */
    $caught = array();

    /* A mid-tone with no neutral over 4.5. #7A7A7A sits near the crossover,
     * which is the worst any colour can do and is the shape of the shade
     * somebody would add by darkening a bright hue halfway. */
    $muddy = '#7A7A7A';
    $caught['a mid-tone with no neutral over 4.5'] = ( pa_icon_test( $muddy )['ratio'] < 4.5 );

    /* AND THE FLOOR IS REACHABLE. A check that cannot fail is the fault this
     * plant exists for: 3:1 against these two neutrals is unfailable, so the
     * worst possible colour has to come out under 4.5 and over 3. */
    $caught['the floor is one a real colour can fail'] = (
        pa_icon_test( $muddy )['ratio'] > 3.0 && pa_icon_test( $muddy )['ratio'] < 4.5
    );

    /* Two colours a person could not tell apart. */
    $caught['two near-identical swatches'] = ( pa_delta_e( '#16BECF', '#18BECF' ) < 25 );

    /* And the measurement agrees with the documented figures, so a broken
     * luminance function cannot pass everything silently. */
    $caught['the maths matches the recorded figures'] = (
        8.92 === pa_ratio( PA_DARK, '#FFD900' )
        && 12.34 === pa_ratio( PA_WHITE, PA_DARK )
    );

    foreach ( $caught as $what => $ok ) {
        printf( "  %-56s%s\n", $what, $ok ? 'caught' : 'MISSED' );
    }
    printf( "  %-56s%s\n", 'the real palette, unmodified', empty( $fails ) ? 'passes' : 'FAILS' );
    echo "\n";
    if ( in_array( false, $caught, true ) || ! empty( $fails ) ) {
        foreach ( $fails as $f ) {
            echo '  - ' . $f . "\n";
        }
        echo "self-test FAILED\n";
        exit( 1 );
    }
    echo "self-test passed: an unusable colour and a near-duplicate are both caught.\n";
    exit( 0 );
}

printf( "The category palette, measured\n" );
echo str_repeat( '=', 78 ) . "\n";
printf( "%-14s %-9s %-8s %-6s %-9s %-6s %-6s %-6s %s\n",
    'Colour', 'Hex', 'icon on', 'ratio', 'ink', 'white', 'page', 'chip', 'media' );
echo str_repeat( '-', 78 ) . "\n";
foreach ( $rows as $r ) {
    printf( "%-14s %-9s %-8s %-6s %-9s %-6s %-6s %-6s %s\n",
        $r['label'], $r['hex'], $r['on'], $r['icon'], $r['ink'],
        $r['ink_w'], $r['ink_p'], $r['chip'], $r['media'] );
}
echo "\n";

if ( $fails ) {
    echo 'PALETTE: ' . count( $fails ) . " FAILURE(S)\n";
    foreach ( $fails as $f ) {
        echo '  - ' . $f . "\n";
    }
    exit( 1 );
}

printf( "%d colours. Every one but Red and Pink has a neutral over 4.5:1, so its placeholder\n", count( $palette ) );
echo "can carry an icon and a label; those two are exempt by name as brand colours that\n";
echo "predate the rule and are documented large-text-only. Every ink clears 4.5:1 on\n";
echo "white, on the page and on its own chip tint, and 3:1 where a ring draws. No pair is\n";
echo "under dE 25, so no two are a coin toss in the picker.\n";
echo "\n";
echo "A 3:1 neutral check would be UNFAILABLE with these two neutrals: the worst any\n";
echo "colour can do is 3.44:1 at the crossover. See the note on the icon floor in this\n";
echo "file for the arithmetic, and PROJECT.md 7 for why a check that cannot fail is\n";
echo "worse than no check.\n";
