<?php
/**
 * CATEGORY INK, MEASURED, WHEREVER IT STILL DRAWS A SHAPE.
 *
 * THE THUMBNAIL RING IS GONE AS OF 3.49.0, and this file outlived it on
 * purpose. The ring was removed because a visitor is never told what the colour
 * means, so it was decoration presenting itself as information; the thumbnails
 * now carry a neutral 1px hairline in --uc-border and section 2 below refuses
 * to let a category colour back onto them.
 *
 * What did NOT change is that category ink still draws things on these two
 * surfaces: the hover border on a day-event row, the chip, and the icon on a
 * placeholder tile. Those carry meaning, the chip literally beside its own
 * name, so the 3:1 floor still applies to them and is still measured here.
 *
 * The ink is the darkened half of the contrast-checked pair
 * sfaf_category_shades() returns. It is NOT the raw category colour, and the
 * rest of this file is why.
 *
 * The raw brand hues cannot carry a shape on white. This project's own audit
 * measured six of the ten below the 3:1 that WCAG 1.4.11 asks of a non-text
 * graphic: Yellow 1.38, Light Gray 1.50, Green 2.02, Teal 2.26, Orange 2.31,
 * Pink 2.99. A ring in those colours would be decoration claiming to be
 * information, on the densest category surface in the product, which is exactly
 * the accent bar this replaced.
 *
 *     php .claude/category-ring-contrast.php
 *
 * Fails if any ink falls under 3:1 on either surface it sits on: white, which
 * is the cell, and --uc-bg #F5F6F7, which is an out-of-month cell and a hovered
 * one. Both, because a mark that passes on one and not the other passes on
 * whichever the reviewer happened to open.
 *
 * Section 2 then checks the thumbnails themselves, and it is a source check on
 * calendar.css rather than a measurement: "this colour is absent" is not
 * something a contrast ratio can express.
 */

$root = dirname( __DIR__ );
define( 'ABSPATH', __DIR__ );

/* sfaf_category_shades() needs nothing from WordPress. */
require_once $root . '/includes/sfaf-template-functions.php';

/** WCAG relative luminance. */
function lum( $hex ) {
    $rgb = sfaf_hex_to_rgb( $hex );
    $c   = array();
    foreach ( array( 0, 1, 2 ) as $i ) {
        $v      = $rgb[ $i ] / 255;
        $c[ $i ] = ( $v <= 0.03928 ) ? ( $v / 12.92 ) : pow( ( $v + 0.055 ) / 1.055, 2.4 );
    }
    return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
}

/** WCAG contrast ratio between two hexes. */
function ratio( $a, $b ) {
    $la = lum( $a );
    $lb = lum( $b );
    $hi = max( $la, $lb );
    $lo = min( $la, $lb );
    return ( $hi + 0.05 ) / ( $lo + 0.05 );
}

$categories = array(
    'Yellow'     => '#FFD900',
    'Orange'     => '#F7921E',
    'Red'        => '#F04937',
    'Burgundy'   => '#A30C33',
    'Pink'       => '#F1668C',
    'Purple'     => '#8D54A2',
    'Teal'       => '#16BECF',
    'Green'      => '#8CC745',
    'Light Gray' => '#D1D3D4',
    'Dark Gray'  => '#373433',
);

/* The two surfaces a ring is ever drawn on. */
$WHITE = '#FFFFFF'; // an ordinary day cell
$BG    = '#F5F6F7'; // --uc-bg: an out-of-month cell, and a hovered cell

$FLOOR = 3.0; // WCAG 1.4.11, non-text contrast

$fails = array();
$rows  = array();

foreach ( $categories as $name => $hex ) {
    $shades = sfaf_category_shades( $hex );
    $ink    = $shades['ink'];

    $raw_white = ratio( $hex, $WHITE );
    $ink_white = ratio( $ink, $WHITE );
    $ink_bg    = ratio( $ink, $BG );

    $rows[] = array( $name, $hex, $ink, $raw_white, $ink_white, $ink_bg );

    if ( $ink_white < $FLOOR ) {
        $fails[] = sprintf( '%s ring ink %s measures %.2f on white, under the %.1f floor', $name, $ink, $ink_white, $FLOOR );
    }
    if ( $ink_bg < $FLOOR ) {
        $fails[] = sprintf( '%s ring ink %s measures %.2f on the out-of-month cell, under the %.1f floor', $name, $ink, $ink_bg, $FLOOR );
    }
}

/*
 * AND THE RAW COLOUR MUST STILL BE THE WRONG CHOICE. If a brand hue were ever
 * lightened to the point of passing on its own, the argument in the header
 * would have quietly stopped being true while the code kept citing it.
 */
$raw_failures = 0;
foreach ( $rows as $r ) {
    if ( $r[3] < $FLOOR ) { $raw_failures++; }
}
if ( $raw_failures < 6 ) {
    $fails[] = sprintf(
        'only %d raw category colours now fall under %.1f on white; the note explaining why the ring uses ink says six',
        $raw_failures, $FLOOR
    );
}

printf( "Category ink, on the two cell surfaces it still draws on\n\n" );
printf( "%-11s %-8s %-8s %9s %9s %9s\n", 'Category', 'Brand', 'Ink', 'raw/white', 'ink/white', 'ink/#F5F6F7' );
printf( "%s\n", str_repeat( '-', 62 ) );
foreach ( $rows as $r ) {
    printf( "%-11s %-8s %-8s %8.2f%s %8.2f%s %8.2f%s\n",
        $r[0], $r[1], $r[2],
        $r[3], $r[3] < $FLOOR ? '*' : ' ',
        $r[4], $r[4] < $FLOOR ? '*' : ' ',
        $r[5], $r[5] < $FLOOR ? '*' : ' '
    );
}
printf( "\n* under %.1f:1. %d of the 10 RAW colours fail on white, which is why the ring is ink.\n", $FLOOR, $raw_failures );
echo "Floor is WCAG 1.4.11 non-text contrast: a ring is a shape carrying meaning, not decoration.\n\n";

/* =========================================================================
 * 2. AND THE THUMBNAILS ARE NEUTRAL, IN BOTH PLACES (3.49.0).
 *
 * THIS SECTION EXISTS TO STOP THE REVERSAL BEING REVERSED. The reasoning that
 * put a category ring on these thumbnails is still in calendar.css, because it
 * was sound about WHICH colour to use and only wrong that a colour belonged
 * there at all. Reasoning that good is exactly what somebody restores from. So
 * the decision is enforced rather than merely written down.
 *
 * TWO SURFACES, ONE ANSWER. The month grid's 32px thumbnail and the sidebar's
 * 16/9 band must carry the SAME neutral hairline: they are the same object on
 * two screens, and in the combined mode they are on ONE screen, a few hundred
 * pixels apart, where any difference between them is plainly a mistake.
 *
 * IT IS ON THE CONTAINER IN BOTH, which is what makes the photo and the
 * placeholder measure identically: there is one hairline and one radius for the
 * two of them, so they cannot drift.
 * ====================================================================== */
$css = file_get_contents( $root . '/public/css/calendar.css' );

/** The declaration block for one selector, as written. */
function block_for( $css, $selector ) {
    $at = strpos( $css, $selector . ' {' );
    if ( false === $at ) {
        return null;
    }
    $end = strpos( $css, '}', $at );
    return ( false === $end ) ? null : substr( $css, $at, $end - $at + 1 );
}

$NEUTRAL = '--uc-border';
$thumbs  = array(
    '.uc-calendar .uc-de-thumb' => 'the month grid thumbnail',
    '.uc-sidebar-thumb'         => 'the sidebar thumbnail',
);

$weights = array();

foreach ( $thumbs as $selector => $what ) {
    $block = block_for( $css, $selector );
    if ( null === $block ) {
        $fails[] = sprintf( '%s (%s) is not in calendar.css at all', $selector, $what );
        continue;
    }

    if ( ! preg_match( '/box-shadow:\s*0 0 0 (\d+)px\s+var\(\s*(--[a-z-]+)/', $block, $m ) ) {
        $fails[] = sprintf( '%s has no hairline, so %s is unbounded against a pale photograph', $selector, $what );
        continue;
    }

    $weights[ $selector ] = (int) $m[1];

    if ( $m[2] !== $NEUTRAL ) {
        $fails[] = sprintf(
            '%s draws its edge in %s rather than %s. A colour nothing on the page explains is decoration claiming to be information; see the note in calendar.css',
            $what, $m[2], $NEUTRAL
        );
    }

    if ( (int) $m[1] < 1 || (int) $m[1] > 2 ) {
        $fails[] = sprintf( '%s hairline is %dpx; it is meant to be 1 or 2', $what, (int) $m[1] );
    }

    /* The reversal, stated as the thing it forbids. */
    if ( preg_match( '/box-shadow:[^;]*cat-ink/', $block ) ) {
        $fails[] = sprintf( '%s is back to a category-coloured ring', $what );
    }
}

if ( 2 === count( $weights ) && 1 !== count( array_unique( $weights ) ) ) {
    $fails[] = sprintf(
        'the two thumbnails are edged at different weights (%s), and in the combined mode they are on the same screen',
        implode( ' and ', array_map( function ( $k, $v ) { return "$k at {$v}px"; }, array_keys( $weights ), $weights ) )
    );
}

/*
 * AND THE TWO THINGS THAT KEEP THEIR COLOUR, asserted so that "make it neutral"
 * is not applied one sweep too far. The chip carries the category name right
 * beside it and the placeholder tile needs a fill; colour is labelled in the
 * first and load-bearing in the second.
 */
foreach ( array(
    '.uc-calendar .uc-lc-chip'      => 'the category chip',
    '.uc-calendar .uc-de-thumb-ph'  => 'the month grid placeholder tile',
) as $selector => $what ) {
    $block = block_for( $css, $selector );
    if ( null === $block ) {
        $fails[] = sprintf( '%s (%s) is not in calendar.css', $selector, $what );
        continue;
    }
    if ( ! preg_match( '/cat-ink/', $block ) ) {
        $fails[] = sprintf( '%s lost its category colour, which it is supposed to keep', $what );
    }
}

printf( "Thumbnail edges: %s\n", $weights
    ? implode( ', ', array_map( function ( $k, $v ) { return "$k {$v}px " . '--uc-border'; }, array_keys( $weights ), $weights ) )
    : 'none found' );
echo "The chip and the placeholder tile keep the category colour, and are checked for it.\n\n";

if ( $fails ) {
    echo 'FAIL: ' . count( $fails ) . "\n";
    foreach ( array_unique( $fails ) as $f ) { echo '  . ' . $f . "\n"; }
    exit( 1 );
}
echo "category ink clears 3:1 everywhere it still draws, and both thumbnails are neutral.\n";
exit( 0 );
