<?php
/**
 * THE MONTH GRID THUMBNAIL RING, MEASURED.
 *
 * The ring around a day-event thumbnail is a 2px border in the category's INK,
 * which is the darkened half of the contrast-checked pair sfaf_category_shades()
 * returns and the same ink the chips use for their text. It is NOT the raw
 * category colour, and this file is why.
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
 * Fails if any ink falls under 3:1 on either surface the ring sits on: white,
 * which is the cell, and --uc-bg #F5F6F7, which is an out-of-month cell and a
 * hovered one. Both, because a ring that passes on one and not the other passes
 * on whichever the reviewer happened to open.
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

printf( "Month grid thumbnail ring: category ink on the two cell surfaces\n\n" );
printf( "%-11s %-8s %-8s %9s %9s %9s\n", 'Category', 'Brand', 'Ring ink', 'raw/white', 'ink/white', 'ink/#F5F6F7' );
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

if ( $fails ) {
    echo 'FAIL: ' . count( $fails ) . "\n";
    foreach ( array_unique( $fails ) as $f ) { echo '  . ' . $f . "\n"; }
    exit( 1 );
}
echo "every ring colour clears 3:1 on both surfaces it is drawn on.\n";
exit( 0 );
