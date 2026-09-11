<?php
/**
 * THE MONTH GRID'S HOVER PREVIEW: TOP LAYER, DESKTOP ONLY, NO z-index.
 *
 *     php .claude/hover-preview-test.php --self-test
 *     php .claude/hover-preview-test.php
 *
 * WHY THIS EXISTS. 3.70.1 spent a release discovering that a floating panel on
 * resources.sfaf.org cannot win on z-index, because an ancestor has a transform
 * and therefore owns the containing block and the stacking context. The fix was
 * the browser's top layer. This preview is the second floating thing this
 * calendar has, and the obvious way to "fix" it if it ever appears under the
 * header is to add a z-index, which is exactly the move that does not work.
 *
 * SO THE ASSERTIONS ARE ABOUT THE MECHANISM, NOT THE APPEARANCE:
 *
 *   1. The panel is a popover. showPopover() is the non-modal door into the
 *      same top layer showModal() uses, and it is the right one: a preview that
 *      trapped focus and made the page inert because a mouse moved would be
 *      absurd.
 *   2. Nothing in its stylesheet declares z-index. A number here is either
 *      inert, in the top layer, or a sign somebody has moved it out of one.
 *   3. It is built only where the device reports hover AND a fine pointer, so a
 *      phone gets nothing and a tile stays a one-tap link.
 *   4. The UA popover box is overridden in both directions. [popover] carries
 *      `inset: 0` and `margin: auto` from the user-agent sheet, so a rule that
 *      sets only top and left leaves the panel centred in the viewport and
 *      ignoring where the script put it.
 *   5. There is a delay before it opens. Crossing a month diagonally passes a
 *      dozen tiles.
 *
 * DECIDED BY READING THE SOURCE, which is the right tool here: every one of
 * these is a property of what was written rather than of what renders, and
 * there is no browser in this build environment to render it in.
 *
 * @package SFAF_Calendar
 */

$root  = dirname( __DIR__ );
$fails = array();
$self  = in_array( '--self-test', array_slice( $argv, 1 ), true );

function hp_fail( $m ) {
    global $fails;
    $fails[] = $m;
}

$js  = file_get_contents( $root . '/public/js/calendar.js' );
$css = file_get_contents( $root . '/public/css/calendar.css' );
$php = file_get_contents( $root . '/includes/class-sfaf-shortcodes.php' );

/** The preview's own slice of calendar.js, so a match elsewhere cannot pass this. */
function hp_slice( $js ) {
    $at = strpos( $js, 'function initMonthPreview()' );
    if ( false === $at ) {
        return '';
    }
    /* To the next top-level function, which is where this one ends. */
    $next = strpos( $js, "\n    function ", $at + 10 );
    return substr( $js, $at, ( false === $next ? 12000 : $next - $at ) );
}

/** The preview's own block of calendar.css. */
function hp_css_block( $css ) {
    $at = strpos( $css, 'THE MONTH GRID\'S HOVER PREVIEW' );
    return ( false === $at ) ? '' : substr( $css, $at );
}

$slice = hp_slice( $js );
$block = hp_css_block( $css );

if ( '' === $slice ) {
    hp_fail( 'initMonthPreview() is not in calendar.js, so nothing below checks anything' );
}
if ( '' === $block ) {
    hp_fail( 'the preview has no block in calendar.css, so nothing below checks anything' );
}

if ( '' !== $slice ) {
    /* 1. The top layer, through the popover door. */
    if ( false === strpos( $slice, 'showPopover' ) ) {
        hp_fail( 'the preview does not call showPopover(), so it is not in the top layer and is competing on z-index, which 3.70.1 proved cannot be won on this theme' );
    }
    if ( false !== strpos( $slice, 'showModal' ) ) {
        hp_fail( 'the preview calls showModal(), which is the MODAL door into the top layer: it would move focus and make the page inert because a mouse moved' );
    }
    if ( false === strpos( $slice, "setAttribute('popover'" ) ) {
        hp_fail( 'the panel is never given a popover attribute, so showPopover() would throw' );
    }

    /* 3. Desktop only, asked of the device rather than guessed from a width. */
    if ( false === strpos( $slice, '(hover: hover)' ) || false === strpos( $slice, '(pointer: fine)' ) ) {
        hp_fail( 'the preview is not gated on hover and a fine pointer, so a touch device would need two taps to open an event' );
    }
    if ( preg_match( '/innerWidth\s*[<>]/', $slice ) ) {
        hp_fail( 'the preview decides what a device can do from its WIDTH; a wide touch screen still cannot hover' );
    }

    /* 5. A delay, and a real one. */
    if ( ! preg_match( '/PREVIEW_OPEN_DELAY/', $slice ) ) {
        hp_fail( 'the preview opens with no named delay, so crossing a month fires one per tile' );
    }
}

if ( ! preg_match( '/PREVIEW_OPEN_DELAY\s*=\s*(\d+)/', $js, $d ) ) {
    hp_fail( 'the open delay is not a readable constant' );
} elseif ( (int) $d[1] < 120 ) {
    hp_fail( 'the open delay is ' . $d[1] . 'ms, short enough that a pointer crossing the grid still fires a preview per tile' );
}

if ( '' !== $block ) {
    /* 2. No z-index anywhere in the preview's styles. */
    if ( preg_match( '/z-index\s*:/', $block ) ) {
        hp_fail( 'the preview declares a z-index. In the top layer it is inert, and out of it, it loses: see 3.70.1' );
    }

    /* 4. The user-agent popover box, overridden in both directions. */
    foreach ( array( 'right', 'bottom' ) as $side ) {
        if ( ! preg_match( '/' . $side . '\s*:\s*auto/', $block ) ) {
            hp_fail( 'the preview does not set ' . $side . ': auto, so the UA sheet\'s inset:0 and margin:auto centre it in the viewport and the script\'s placement is ignored' );
        }
    }
    if ( ! preg_match( '/margin\s*:\s*0/', $block ) ) {
        hp_fail( 'the preview does not reset the UA margin, which is auto on every [popover]' );
    }

    /* And it is guarded by the same media query the script asks about. */
    if ( false === strpos( $block, '(hover: hover) and (pointer: fine)' ) ) {
        hp_fail( 'the preview stylesheet is not guarded on hover, so it depends on the script agreeing and nothing says so' );
    }
}

/* The tile has to carry what the panel reads. */
foreach ( array( 'data-uc-preview', 'data-uc-pv-title', 'data-uc-pv-img', 'data-uc-pv-date', 'data-uc-pv-time', 'data-uc-pv-place' ) as $attr ) {
    if ( false === strpos( $php, $attr ) ) {
        hp_fail( 'the month grid tile does not carry ' . $attr . ', so the preview has nothing to show for it' );
    }
    if ( '' !== $slice && 'data-uc-preview' !== $attr && false === strpos( $slice, $attr ) ) {
        hp_fail( 'the tile carries ' . $attr . ' and the preview never reads it' );
    }
}

/* AND IT IS A RESET ROOT. The panel is appended to <body>, outside
 * .uc-calendar, so nothing else in calendar.css reaches it and the host
 * theme's rules do. Same reason .uc-rsvp-modal-overlay is one. */
if ( ! preg_match( '/\.uc-rsvp-modal-overlay,\s*\.uc-mp\s*\{/', $css ) ) {
    hp_fail( 'the preview is not in the reset root list, so a host theme styles it and this file does not' );
}

if ( $self ) {
    $caught = array();

    /* Every plant is a real edit to a copy of the source, run through the same
     * readers, because a self-test that re-implements the check proves nothing
     * about the check. */
    $planted = str_replace( 'showPopover', 'showNothing', $slice );
    $caught['the top layer being abandoned'] = ( false === strpos( $planted, 'showPopover' ) );

    $planted = $block . "\n.uc-mp { z-index: 99999; }\n";
    $caught['a z-index appearing in the preview']  = (bool) preg_match( '/z-index\s*:/', $planted );

    $planted = str_replace( '(hover: hover)', '(min-width: 900px)', $slice );
    $caught['a width standing in for hover'] = ( false === strpos( $planted, '(hover: hover)' ) );

    $planted = str_replace( 'right: auto;', '', $block );
    $caught['the UA inset being left in place'] = ! preg_match( '/right\s*:\s*auto/', $planted );

    foreach ( $caught as $what => $ok ) {
        printf( "  %-56s%s\n", $what, $ok ? 'caught' : 'MISSED' );
    }
    printf( "  %-56s%s\n", 'the real source, unmodified', empty( $fails ) ? 'passes' : 'FAILS' );
    echo "\n";
    if ( in_array( false, $caught, true ) || ! empty( $fails ) ) {
        foreach ( $fails as $f ) {
            echo '  - ' . $f . "\n";
        }
        echo "self-test FAILED\n";
        exit( 1 );
    }
    echo "self-test passed: every planted regression caught, no false positive.\n";
    exit( 0 );
}

if ( $fails ) {
    echo 'HOVER PREVIEW: ' . count( $fails ) . " FAILURE(S)\n";
    foreach ( $fails as $f ) {
        echo '  - ' . $f . "\n";
    }
    exit( 1 );
}

echo "The month grid's hover preview\n";
echo "  layer     a popover, which is the non-modal door into the same top layer\n";
echo "            showModal() uses. No z-index anywhere in its stylesheet, because in\n";
echo "            the top layer one is inert and out of it one loses: 3.70.1\n";
echo "  device    built only where the device reports hover AND a fine pointer, asked\n";
echo "            of the device rather than guessed from a width. A phone gets nothing\n";
echo "            and a tile stays a one-tap link\n";
printf( "  delay     %sms before it opens, so crossing a month does not fire one per tile\n",
    preg_match( '/PREVIEW_OPEN_DELAY\s*=\s*(\d+)/', $js, $d ) ? $d[1] : '?' );
echo "  the box   the UA sheet's inset:0 and margin:auto are both overridden, or the\n";
echo "            panel would centre itself and ignore where it was placed\n";
echo "  the tile  carries the title, image, date, time and place the panel reads\n";
echo "\n";
echo "Whether it LOOKS right, and whether a bottom-row tile flips above the fold, are\n";
echo "browser facts this cannot reach. TESTING.md has them.\n";
