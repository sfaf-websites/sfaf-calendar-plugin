<?php
/**
 * EVERY DESTRUCTIVE CONTROL, MEASURED.
 *
 * WHY THIS EXISTS. `.uc-btn-danger` was declared TWICE in portal.css with
 * different intents. The older rule was a filled button, white on #c0392b. The
 * one 3.38.0 added for the cancel control was an outline, red text on the card.
 * Same class, same specificity, and the newer one loaded second, so it won
 * `color` and `border-color` and did NOT win `background`. The result was
 * #b91c1c text on a #c0392b fill: two reds, measuring 1.19:1 against a 4.5:1
 * floor, which is very close to invisible.
 *
 * That is the same mistake as the category chips before 3.15.0, where one
 * colour was used as both tint and ink. A colour is either the fill or the
 * writing, and a rule that sets one without the other is a rule that inherits
 * whatever the last one left behind.
 *
 * So the pairs are measured rather than chosen by eye, and the check is
 * committed so the next person who adds a red control finds out before Mark
 * does.
 *
 *     php .claude/destructive-contrast.php
 *
 * WCAG 2.1 relative luminance and contrast ratio, the same arithmetic as
 * category-ring-contrast.php. Normal text needs 4.5:1; this file has no large
 * text in it, so 4.5 is the floor throughout.
 */

$root = dirname( __DIR__ );

/** WCAG relative luminance of an sRGB hex colour. */
function luminance( $hex ) {
    $hex = ltrim( trim( $hex ), '#' );
    if ( 3 === strlen( $hex ) ) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    $parts = array(
        hexdec( substr( $hex, 0, 2 ) ),
        hexdec( substr( $hex, 2, 2 ) ),
        hexdec( substr( $hex, 4, 2 ) ),
    );
    $lin = array();
    foreach ( $parts as $p ) {
        $c     = $p / 255;
        $lin[] = ( $c <= 0.03928 ) ? ( $c / 12.92 ) : pow( ( $c + 0.055 ) / 1.055, 2.4 );
    }
    return 0.2126 * $lin[0] + 0.7152 * $lin[1] + 0.0722 * $lin[2];
}

/** Contrast ratio between two hex colours. */
function ratio( $a, $b ) {
    $la = luminance( $a );
    $lb = luminance( $b );
    $hi = max( $la, $lb );
    $lo = min( $la, $lb );
    return ( $hi + 0.05 ) / ( $lo + 0.05 );
}

const FLOOR = 4.5;

/*
 * EVERY PAIR A DESTRUCTIVE CONTROL PUTS ON SCREEN. ink, ground, and where it is.
 * Read out of the stylesheets by hand and listed here, because "which colour is
 * behind this text" is a cascade question and no regex answers it.
 */
$PAIRS = array(
    array(
        'what'   => '.uc-btn-danger, filled',
        'ink'    => '#ffffff',
        'ground' => '#c0392b',
        'note'   => 'the delete and cancel buttons in caladmin',
    ),
    array(
        'what'   => '.uc-btn-danger:hover',
        'ink'    => '#ffffff',
        'ground' => '#a33025',
        'note'   => 'the same button, pressed',
    ),
    array(
        'what'   => '.uc-link-danger on a card',
        'ink'    => '#c0392b',
        'ground' => '#ffffff',
        'note'   => 'text-only destructive links on --p-panel, using --p-danger-text',
    ),
    array(
        'what'   => '.uc-link-danger on the page',
        'ink'    => '#c0392b',
        'ground' => '#F5F6F7',
        'note'   => 'the same links where no card sits behind them, on --p-bg',
    ),
    array(
        /*
         * BRAND RED AS TEXT, MEASURED AND RECORDED AS THE REASON IT IS NOT USED
         * FOR TEXT. Kept in the table rather than deleted: a number nobody can
         * see is a number somebody re-litigates.
         */
        'what'   => 'brand red as body text (rejected)',
        'ink'    => '#F04937',
        'ground' => '#ffffff',
        'note'   => 'why --p-danger is fills and icons only. DESIGN.md has Red as large-text only.',
        'expect_fail' => true,
    ),
    array(
        'what'   => 'WP admin .button-small danger',
        'ink'    => '#b32d2e',
        'ground' => '#ffffff',
        'note'   => "WordPress core's own delete red, on the Closures screen",
    ),
);

$fails  = array();
$report = array();

foreach ( $PAIRS as $p ) {
    $r = ratio( $p['ink'], $p['ground'] );
    $report[] = array( $p['what'], $p['ink'], $p['ground'], $r, $p['note'], ! empty( $p['expect_fail'] ) );

    /*
     * A pair recorded BECAUSE it fails is not a failure. It is the measurement
     * that explains why a colour is not used for text, kept so nobody has to
     * take that on trust or measure it again.
     */
    if ( ! empty( $p['expect_fail'] ) ) {
        if ( $r >= FLOOR ) {
            $fails[] = sprintf( '%s now measures %.2f:1 and passes. The reason it was rejected no longer holds, so either the colour or this note is wrong.', $p['what'], $r );
        }
        continue;
    }

    if ( $r < FLOOR ) {
        $fails[] = sprintf(
            '%s: %s on %s measures %.2f:1, under the %.1f:1 floor. %s',
            $p['what'], $p['ink'], $p['ground'], $r, FLOOR, $p['note']
        );
    }
}

/*
 * AND THE CLASS IS DECLARED ONCE.
 *
 * The measurements above are only meaningful if the pairs are the pairs that
 * reach the screen. Two rules for one class, at the same specificity, is how a
 * colour and its background came from different declarations in the first
 * place: the second rule set `color` and left `background` to the first.
 */
$portal = file_get_contents( $root . '/public/css/portal.css' );
$code   = preg_replace( '#/\*.*?\*/#s', '', $portal );

$declared = preg_match_all( '#(^|\})\s*\.uc-btn-danger\s*\{#m', $code );
if ( $declared > 1 ) {
    $fails[] = sprintf(
        '.uc-btn-danger is declared %d times at the same specificity. That is how red text ended up on a red fill: the second rule set colour and inherited the first rule\'s background.',
        $declared
    );
}

/*
 * THE BASE RULE SAYS BOTH WHAT THE INK IS AND WHAT IS BEHIND IT.
 *
 * Checked on the BASE selector only, `.uc-btn-danger` on its own. A more
 * specific override that sets colour alone is deliberate and correct:
 * `.uc-portal a.uc-btn-danger` exists to beat the portal's anchor colour and
 * inherits the fill from the base rule on purpose. Flagging that would be
 * flagging correct code, and a check that does is a check people learn to
 * ignore.
 *
 * What must never happen again is the BASE pair coming from two different
 * declarations, which is what put #b91c1c ink on a #c0392b fill.
 */
if ( preg_match( '#(^|\})\s*\.uc-btn-danger\s*\{([^}]*)\}#m', $code, $m ) ) {
    $decls      = $m[2];
    $has_colour = preg_match( '#(^|;)\s*color\s*:#', $decls );
    $has_ground = preg_match( '#(^|;)\s*background(-color)?\s*:#', $decls );
    if ( $has_colour && ! $has_ground ) {
        $fails[] = 'the base .uc-btn-danger rule sets colour with no background beside it, so its ink lands on whatever another rule left behind.';
    }
    if ( ! $has_colour ) {
        $fails[] = 'the base .uc-btn-danger rule does not say what colour its text is.';
    }
} else {
    $fails[] = 'no base .uc-btn-danger rule was found, so the measurements above describe nothing.';
}

/* ------------------------------------------------------------------------ */
echo "Destructive control contrast\n";
echo str_repeat( '-', 78 ) . "\n";
foreach ( $report as $r ) {
    $verdict = ! empty( $r[5] ) ? 'rejected, as recorded' : ( $r[3] >= FLOOR ? 'pass' : 'FAIL' );
    printf( "  %-32s %-8s on %-8s %6.2f:1  %s\n", $r[0], $r[1], $r[2], $r[3], $verdict );
}
echo "\nfloor: " . FLOOR . ":1 (normal text, WCAG 1.4.3)\n\n";

if ( $fails ) {
    echo 'FAIL: ' . count( $fails ) . "\n";
    foreach ( $fails as $f ) { echo "  . $f\n"; }
    exit( 1 );
}
echo "every destructive control clears the floor, and the class is declared once.\n";
