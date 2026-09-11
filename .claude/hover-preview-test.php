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

/**
 * The preview's own slice of a file, between its markers.
 *
 * MARKERS RATHER THAN "FROM THE FUNCTION TO THE NEXT ONE", which is what this
 * did first and which cannot answer the question that now matters: the block
 * exists in TWO files and has to be identical in both, so the boundary has to
 * be the same boundary in each rather than whatever happens to follow it.
 *
 * @param string $js
 * @return string '' when the file has no marked block.
 */
function hp_slice( $js ) {
    $a = strpos( $js, '/* SFAF-PREVIEW-START' );
    $b = strpos( $js, '/* SFAF-PREVIEW-END */' );
    if ( false === $a || false === $b || $b < $a ) {
        return '';
    }
    return substr( $js, $a, $b - $a + strlen( '/* SFAF-PREVIEW-END */' ) );
}

/** The preview's own block of calendar.css. */
function hp_css_block( $css ) {
    $at = strpos( $css, 'THE MONTH GRID\'S HOVER PREVIEW' );
    return ( false === $at ) ? '' : substr( $css, $at );
}

$ejs   = file_get_contents( $root . '/public/js/embed.js' );
$slice = hp_slice( $js );
$eslice = hp_slice( $ejs );
$block = hp_css_block( $css );

if ( '' === $slice ) {
    hp_fail( 'calendar.js has no marked preview block, so nothing below checks anything' );
}
if ( '' === $block ) {
    hp_fail( 'the preview has no block in calendar.css, so nothing below checks anything' );
}

/* =========================================================================
 * IT HAS TO BE IN BOTH SCRIPTS, AND THIS IS THE ASSERTION 3.75.0 NEEDED.
 *
 * The calendar has no front end on resources.sfaf.org: it exists only as an
 * embed on sfaf.org, which runs embed.js. 3.75.0 shipped the preview in
 * calendar.js alone, so it was correct, tested by this very file, and never
 * executed anywhere anybody could see it. Every assertion below passed while
 * the feature did not exist on the only surface that matters.
 *
 * SO THE FIRST QUESTION IS NOT "IS IT RIGHT" BUT "IS IT THERE, TWICE". The two
 * copies are compared byte for byte, because a block that is nearly the same
 * in two files is the drift this project has paid for before.
 * ====================================================================== */
if ( '' === $eslice ) {
    hp_fail( 'embed.js has no marked preview block. The calendar exists ONLY as an embed, '
        . 'so a preview that is only in calendar.js never runs anywhere a visitor can see it' );
} elseif ( $eslice !== $slice ) {
    hp_fail( 'the preview block differs between calendar.js and embed.js. It is one block in two '
        . 'files and they have to be identical; copy whichever is right over the other' );
}
if ( false === strpos( $ejs, 'initMonthPreview()' ) || 1 === substr_count( $ejs, 'initMonthPreview' ) ) {
    hp_fail( 'embed.js defines the preview and never calls it, which is the same outcome as not having it' );
}
if ( false === strpos( $js, "run('monthPreview'" ) ) {
    hp_fail( 'calendar.js defines the preview and never calls it' );
}

/* AND IT CANNOT DEPEND ON jQUERY, because embed.js has none by design and the
 * host page is somebody else's.
 *
 * THE COMMENTS COME OUT FIRST, and the first draft of this check did not do
 * that and failed on the block's own note SAYING it uses no jQuery. A check
 * that reads prose as code is the grep trap this project keeps paying for. */
$code_only = preg_replace( '#/\*.*?\*/#s', '', $slice );
$code_only = preg_replace( '#//[^\n]*#', '', $code_only );
if ( preg_match( '/(^|[^A-Za-z0-9_$])\$\(/', $code_only ) || false !== strpos( $code_only, 'jQuery' ) ) {
    hp_fail( 'the preview block uses jQuery, which embed.js does not have and the host page may not either' );
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

    /* 6. THE PANEL GOES SOMEWHERE (3.77.0).
     *
     * 3.76.0 shipped it with "View Event Details" as a <span>: a pill that
     * looked like a button, was not one, and had nothing behind it. The address
     * was never missing, because the tile it previews IS the link, so the fault
     * was entirely that nothing read the href and nothing in the panel was
     * clickable.
     *
     * SO THE ASSERTION IS ABOUT THE DESTINATION, not about the markup. There
     * has to be an anchor, its href has to come off the tile, and the target
     * and rel have to be copied rather than invented, or the panel could open
     * in the same tab while the tile opens a new one. */
    /* TWO TARGETS SINCE 3.78.0, and both have to be there. 3.77.0 wrapped the
     * whole panel in one anchor, which tinted every line in it with the
     * theme's link colour; the picture and the pill are the two things
     * somebody aims at, and the title is deliberately neither. */
    foreach ( array( 'uc-mp-shot', 'uc-mp-go' ) as $target ) {
        if ( false === strpos( $slice, "panel.querySelector('." . $target . "')" ) ) {
            hp_fail( 'the preview panel has no .' . $target . ' link, so one of its two targets is not clickable' );
        }
    }
    if ( false !== strpos( $slice, 'uc-mp-link' ) ) {
        hp_fail( 'the panel is wrapped in one anchor again, which tints every line in it with the theme\'s link colour' );
    }
    if ( ! preg_match( "/setAttribute\(\s*'href'\s*,\s*a\.getAttribute\(\s*'href'\s*\)/", $slice ) ) {
        hp_fail( "the panel's links do not take their href from the tile, so they go nowhere or somewhere of their own" );
    }
    foreach ( array( 'target', 'rel' ) as $attr ) {
        if ( false === strpos( $slice, "copyAttr(a, link, '" . $attr . "')" ) ) {
            hp_fail( 'the panel does not copy ' . $attr . ' from the tile, so it can open differently from the tile it previews' );
        }
    }
    /* AND BOTH GET THEM, from one loop rather than two hand-written blocks, so
     * the picture and the pill cannot drift apart. */
    if ( ! preg_match( '/links\.forEach\(/', $slice ) ) {
        hp_fail( 'the two targets are filled in separately rather than from one list, so one can be given a destination and the other forgotten' );
    }
    /* AND IT IS NOT A TAB STOP. The panel is aria-hidden and the tile already
     * carries the same destination; a focusable element inside an aria-hidden
     * container is invisible to a screen reader and still a stop, which on a
     * sixty-event month is sixty extra. */
    if ( false === strpos( $slice, 'tabindex="-1"' ) ) {
        hp_fail( "the panel's link is a tab stop inside an aria-hidden panel, which is a stop a screen reader cannot see" );
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

    /* THE 3.75.0 FAULT ITSELF: the block in one script and not the other. */
    $caught['the block missing from embed.js'] = ( '' === hp_slice( 'nothing marked in here' ) );
    $caught['the two copies drifting apart']   = ( str_replace( '260', '300', $slice ) !== $slice );

    /* And a jQuery call slipping in, which embed.js cannot run. */
    $planted = str_replace( 'document.addEventListener(', '$(document).on(', $slice );
    $planted = preg_replace( '#/\*.*?\*/#s', '', $planted );
    $caught['jQuery slipping into the shared block'] = (bool) preg_match( '/(^|[^A-Za-z0-9_$])\$\(/', $planted );

    /* THE 3.76.0 FAULT: the pill back to a span with nothing behind it. */
    $planted = str_replace( "panel.querySelector('.uc-mp-go')", "panel.querySelector('.uc-mp-none')", $slice );
    $caught['the panel losing one of its two targets'] =
        ( false === strpos( $planted, "panel.querySelector('.uc-mp-go')" ) );

    /* AND THE 3.77.0 FAULT: one anchor round everything, which tints the box. */
    $planted = str_replace( 'uc-mp-shot', 'uc-mp-link', $slice );
    $caught['the whole panel becoming one link again'] = ( false !== strpos( $planted, 'uc-mp-link' ) );

    /* And the panel deciding its own destination instead of taking the tile's. */
    $planted = str_replace( "a.getAttribute('href')", "'/events/'", $slice );
    $caught['the panel inventing its own href'] =
        ! preg_match( "/setAttribute\(\s*'href'\s*,\s*a\.getAttribute\(\s*'href'\s*\)/", $planted );

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
echo "  where     in BOTH calendar.js and embed.js, byte for byte, because the calendar\n";
echo "            has no front end on resources and exists only as an embed. 3.75.0\n";
echo "            shipped it in calendar.js alone and it never ran anywhere\n";
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
echo "  targets   two, the picture and the pill, both taking href, target and rel off\n";
echo "            the tile in one loop, so they cannot disagree with each other or with\n";
echo "            it. The panel itself is NOT a link: one anchor round everything tinted\n";
echo "            every line in it with the theme's link colour. Neither is a tab stop\n";
echo "\n";
echo "Whether it LOOKS right, and whether a bottom-row tile flips above the fold, are\n";
echo "browser facts this cannot reach. TESTING.md has them.\n";
