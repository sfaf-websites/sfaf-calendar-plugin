<?php
/**
 * WHAT ACTUALLY REACHES THE MONTH ARROWS, COMPUTED RATHER THAN READ (3.80.0).
 *
 * WHY. The arrows were reported as "too small and blending into the colour
 * scheme". `calendar.css` appears to answer that already:
 *
 *     .uc-month-nav-side .uc-month-nav { border: 1px solid var(--uc-border); ... }
 *
 * so the obvious reading is that they DO have a boundary and it is simply too
 * pale. That reading is wrong, and acting on it would have meant darkening a
 * border that never renders. Twenty lines further down, at the same
 * specificity and therefore winning on source order, sits `border: 0`.
 *
 * This is the same fault as 3.20.0's "one class loses to class plus type",
 * arriving the other way round: equal specificity, later wins, and nothing in
 * either rule hints that the other exists.
 *
 *     php .claude/month-nav-cascade.php
 *
 * WHAT IT DOES. Collects every top-level rule in calendar.css whose selector
 * could match the month arrows, computes (a,b,c) specificity for each, orders
 * them the way a browser does, and prints the winning declaration per property
 * with the losers under it. Then it measures the winner against its backdrop
 * and against the control standard's floors.
 *
 * IT IS NOT A BROWSER. It resolves a single element's own declarations from one
 * stylesheet; it knows nothing about inheritance, shorthand expansion beyond
 * the two cases named below, or the host page. The host is the point of the
 * last section: sfaf.org's stylesheet is not in this repository, so what this
 * can say is how much specificity our rules carry to defend themselves with.
 */

$root = dirname( __DIR__ );
$css  = file_get_contents( $root . '/public/css/calendar.css' );

/* The element under test, as it is actually emitted by render_month_head(). */
$el = array(
    'tag'      => 'button',
    'classes'  => array( 'uc-month-nav', 'uc-month-prev' ),
    'ancestor' => array( 'uc-calendar', 'uc-month', 'uc-month-head', 'uc-month-nav-group' ),
);

/* ---------------------------------------------------------------------------
 * A very small reader: top-level rules only, at-rule bodies skipped.
 *
 * SKIPPING @media IS DELIBERATE AND IS ALSO A LIMITATION, stated rather than
 * hidden: a width-specific override would not be seen here. The report prints
 * the count it skipped so that a rule hiding in one is not silently missed.
 * ------------------------------------------------------------------------ */
$css_nc = preg_replace( '#/\*.*?\*/#s', '', $css );

$rules   = array();
$skipped = 0;
$len     = strlen( $css_nc );
$i       = 0;
$order   = 0;

while ( $i < $len ) {
    $brace = strpos( $css_nc, '{', $i );
    if ( false === $brace ) { break; }
    $sel = trim( substr( $css_nc, $i, $brace - $i ) );

    /* Walk to the matching close, counting depth, so a nested at-rule body is
     * consumed whole rather than ending this rule at its first inner brace. */
    $depth = 1;
    $j     = $brace + 1;
    while ( $j < $len && $depth > 0 ) {
        if ( '{' === $css_nc[ $j ] ) { $depth++; }
        if ( '}' === $css_nc[ $j ] ) { $depth--; }
        $j++;
    }
    $body = substr( $css_nc, $brace + 1, $j - $brace - 2 );

    if ( '' !== $sel && '@' === $sel[0] ) {
        $skipped++;
        $i = $j;
        continue;
    }

    foreach ( explode( ',', $sel ) as $one ) {
        $one = trim( preg_replace( '/\s+/', ' ', $one ) );
        if ( '' === $one ) { continue; }
        $rules[] = array(
            'sel'   => $one,
            'body'  => $body,
            'order' => $order++,
            'line'  => substr_count( substr( $css_nc, 0, $brace ), "\n" ) + 1,
        );
    }
    $i = $j;
}

/* ---------------------------------------------------------------------------
 * Specificity, and whether a selector matches our element.
 * ------------------------------------------------------------------------ */
function mn_specificity( $sel ) {
    $a = substr_count( $sel, '#' );
    /* Classes, attributes and pseudo-classes. :where() contributes zero, which
     * is the whole reason this project uses it for fallbacks. */
    $s = preg_replace( '/:where\([^)]*\)/', '', $sel );
    $b = preg_match_all( '/\.[A-Za-z_-]|\[[^\]]+\]|:(?!:)(?!where)[a-z-]+/', $s );
    /* Types and pseudo-elements, minus anything that was part of a class. */
    $c = preg_match_all( '/(?<![.\#\w-])\b(button|a|div|span|p|h[1-6]|input|select|textarea|table|td|th|tr|ul|li|img|form|summary|details|nav|section)\b/', $s );
    $c += preg_match_all( '/::[a-z-]+/', $s );
    return array( (int) $a, (int) $b, (int) $c );
}

function mn_matches( $sel, $el ) {
    /* The rightmost compound has to match the element itself; everything left
     * of it has to be satisfied by an ancestor. Descendant combinators only,
     * which is all this file uses on these selectors. */
    if ( false !== strpos( $sel, '>' ) || false !== strpos( $sel, '+' ) || false !== strpos( $sel, '~' ) ) {
        /* A sibling or child combinator: matched only when its own right-hand
         * side matches and the left-hand side names a class the element has,
         * which is enough for `.uc-month-nav + .uc-month-nav`. */
        $parts = preg_split( '/\s*[>+~]\s*/', $sel );
        $right = array_pop( $parts );
        if ( ! mn_compound( $right, $el ) ) { return false; }
        foreach ( $parts as $p ) {
            if ( ! mn_compound( $p, $el ) && ! mn_ancestor( $p, $el ) ) { return false; }
        }
        return true;
    }

    $parts = explode( ' ', $sel );
    $right = array_pop( $parts );
    if ( ! mn_compound( $right, $el ) ) { return false; }
    foreach ( $parts as $p ) {
        if ( ! mn_ancestor( $p, $el ) ) { return false; }
    }
    return true;
}

function mn_compound( $c, $el ) {
    $c = preg_replace( '/:(hover|focus|focus-visible|active|disabled|first-child|last-child)\b/', '', $c );
    $c = trim( $c );
    if ( '' === $c || '*' === $c ) { return true; }
    if ( preg_match_all( '/\.([A-Za-z0-9_-]+)/', $c, $m ) ) {
        foreach ( $m[1] as $cl ) {
            if ( ! in_array( $cl, $el['classes'], true ) ) { return false; }
        }
    }
    $tag = preg_replace( '/[.#:\[].*$/', '', $c );
    if ( '' !== $tag && $tag !== $el['tag'] ) { return false; }
    return true;
}

function mn_ancestor( $c, $el ) {
    $c = trim( $c );
    if ( '' === $c ) { return true; }
    if ( preg_match_all( '/\.([A-Za-z0-9_-]+)/', $c, $m ) ) {
        foreach ( $m[1] as $cl ) {
            if ( ! in_array( $cl, $el['ancestor'], true ) && ! in_array( $cl, $el['classes'], true ) ) {
                return false;
            }
        }
        return true;
    }
    return false;
}

/* ---------------------------------------------------------------------------
 * Resolve.
 * ------------------------------------------------------------------------ */
$hits = array();
foreach ( $rules as $r ) {
    if ( ! mn_matches( $r['sel'], $el ) ) { continue; }
    /* Resting state only. A :hover rule is not what the element looks like. */
    if ( preg_match( '/:(hover|focus|focus-visible|active)/', $r['sel'] ) ) { continue; }
    $r['spec'] = mn_specificity( $r['sel'] );
    $hits[]    = $r;
}

/* Browser order: specificity, then source order. */
usort( $hits, function ( $x, $y ) {
    for ( $k = 0; $k < 3; $k++ ) {
        if ( $x['spec'][ $k ] !== $y['spec'][ $k ] ) { return $x['spec'][ $k ] - $y['spec'][ $k ]; }
    }
    return $x['order'] - $y['order'];
} );

/* Declarations, last winner per property. Two shorthands are expanded because
 * they are the two that decide this control's appearance. */
$won = array();
foreach ( $hits as $r ) {
    foreach ( explode( ';', $r['body'] ) as $d ) {
        if ( false === strpos( $d, ':' ) ) { continue; }
        list( $prop, $val ) = explode( ':', $d, 2 );
        $prop = trim( $prop );
        $val  = trim( $val );
        if ( '' === $prop || '' === $val ) { continue; }
        $won[ $prop ][] = array( 'val' => $val, 'sel' => $r['sel'], 'spec' => $r['spec'], 'line' => $r['line'] );
        if ( 'border' === $prop ) {
            foreach ( array( 'border-width', 'border-style', 'border-color' ) as $sub ) {
                $won[ $sub ][] = array( 'val' => '(from border) ' . $val, 'sel' => $r['sel'], 'spec' => $r['spec'], 'line' => $r['line'] );
            }
        }
    }
}

echo "Month arrow cascade\n";
echo "  element:  <button class=\"uc-month-nav uc-month-prev\">\n";
echo "  inside:   ." . implode( ' .', $el['ancestor'] ) . "\n";
echo '  rules considered: ' . count( $rules ) . ', at-rule bodies skipped: ' . $skipped . "\n";
echo '  rules matching this element (resting state): ' . count( $hits ) . "\n\n";

$watch = array( 'border', 'border-width', 'border-color', 'border-radius', 'background', 'color', 'height', 'min-width', 'padding', 'font-size' );
foreach ( $watch as $prop ) {
    if ( empty( $won[ $prop ] ) ) { continue; }
    $list = $won[ $prop ];
    $win  = end( $list );
    printf( "  %-14s WINS  %-34s  (%d,%d,%d) line %d\n",
        $prop, $win['val'], $win['spec'][0], $win['spec'][1], $win['spec'][2], $win['line'] );
    for ( $k = 0; $k < count( $list ) - 1; $k++ ) {
        $l = $list[ $k ];
        printf( "  %-14s loses %-34s  (%d,%d,%d) line %d\n",
            '', $l['val'], $l['spec'][0], $l['spec'][1], $l['spec'][2], $l['line'] );
    }
}

/* ---------------------------------------------------------------------------
 * Measure the winner, against the control standard's own floors.
 * ------------------------------------------------------------------------ */
function mn_lin( $c ) { $c /= 255; return $c <= 0.03928 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 ); }
function mn_lum( $h ) {
    $h = ltrim( $h, '#' );
    if ( 3 === strlen( $h ) ) { $h = $h[0].$h[0].$h[1].$h[1].$h[2].$h[2]; }
    return 0.2126 * mn_lin( hexdec( substr( $h, 0, 2 ) ) )
         + 0.7152 * mn_lin( hexdec( substr( $h, 2, 2 ) ) )
         + 0.0722 * mn_lin( hexdec( substr( $h, 4, 2 ) ) );
}
function mn_ratio( $a, $b ) {
    $la = mn_lum( $a ); $lb = mn_lum( $b );
    return ( max( $la, $lb ) + 0.05 ) / ( min( $la, $lb ) + 0.05 );
}
function mn_token( $css, $name ) {
    return preg_match( '/' . preg_quote( $name, '/' ) . ':\s*(#[0-9A-Fa-f]{3,6})/', $css, $m ) ? $m[1] : '';
}

$border = mn_token( $css, '--uc-border' );
$strong = mn_token( $css, '--uc-border-strong' );
$second = mn_token( $css, '--uc-secondary' );
$edge   = mn_token( $css, '--uc-control-edge' );
$text   = mn_token( $css, '--uc-text' );

echo "\nMeasured, against the 3.64.0 control standard's floors\n";
$rows = array(
    array( 'hairline --uc-border on white', $border, '#FFFFFF', 3.00 ),
    array( 'card edge on white',            $strong, '#FFFFFF', 3.00 ),
    array( 'card edge on the page',         $strong, '#F7F8FA', 3.00 ),
    array( 'CONTROL edge on white',         $edge,   '#FFFFFF', 3.00 ),
    array( 'CONTROL edge on the page',      $edge,   '#F7F8FA', 3.00 ),
    array( '--uc-secondary ink on white',   $second, '#FFFFFF', 4.50 ),
    array( '--uc-text ink on white',        $text,   '#FFFFFF', 4.50 ),
);
$fails = 0;
foreach ( $rows as $r ) {
    list( $said, $fg, $bg, $floor ) = $r;
    if ( '' === $fg ) {
        printf( "  %-32s %s\n", $said, 'token not defined' );
        continue;
    }
    $ratio = mn_ratio( $fg, $bg );
    $ok    = $ratio >= $floor;
    printf( "  %-32s %s on %s  %5.2f:1  floor %.2f  %s\n",
        $said, strtoupper( $fg ), strtoupper( $bg ), $ratio, $floor, $ok ? 'ok' : 'FAILS' );
    if ( ! $ok && '--uc-border' !== $said ) { /* the hairline is expected to fail as a boundary */ }
}

/* ---------------------------------------------------------------------------
 * The host. THIS IS THE CONSTRAINT THE EMBED ADDS, and it is about how much
 * specificity our own rules carry rather than about any rule of theirs, which
 * is not in this repository and cannot be read from here.
 * ------------------------------------------------------------------------ */
echo "\nWhat a host rule would have to carry to beat ours\n";
$min = null;
foreach ( $hits as $r ) {
    if ( null === $min || $r['spec'] < $min['spec'] ) { $min = $r; }
}
foreach ( $hits as $r ) {
    printf( "  (%d,%d,%d)  %s\n", $r['spec'][0], $r['spec'][1], $r['spec'][2], $r['sel'] );
}
echo "\n";
echo "  A theme rule on `button` is (0,0,1) and loses to every one of these.\n";
echo "  `.entry-content button` is (0,1,1) and loses to any (0,2,0) above.\n";
echo "  Anything at (0,2,0) of ours is safe from both; a (0,1,0) rule of ours\n";
echo "  is not, and is the shape that has cost this project six faults.\n";

/*
 * ONLY THE RULES THAT SAY WHAT IT LOOKS LIKE, and this distinction is the
 * whole of the check rather than a refinement of it.
 *
 * The first version of this gate failed on `.uc-calendar *`, which is the
 * scoped box-sizing reset. That rule is (0,1,0) and is MEANT to be: it is a
 * floor under everything in the block and it declares nothing about this
 * control's appearance, so a host rule beating it changes nothing that
 * matters. Failing on it would have trained somebody to ignore this output,
 * which is the way a check stops being read.
 *
 * What must carry two classes is any rule declaring the properties that make
 * this a control somebody can see and aim at.
 */
$appearance = array( 'border', 'border-width', 'border-style', 'border-color', 'border-left',
    'border-radius', 'background', 'background-color', 'color', 'height', 'min-width',
    'padding', 'font-size', 'font-weight', 'font-family' );

$weak = array();
foreach ( $hits as $r ) {
    if ( ! ( 0 === $r['spec'][0] && $r['spec'][1] <= 1 && $r['spec'][2] === 0 ) ) { continue; }
    foreach ( explode( ';', $r['body'] ) as $d ) {
        if ( false === strpos( $d, ':' ) ) { continue; }
        $prop = trim( explode( ':', $d, 2 )[0] );
        if ( in_array( $prop, $appearance, true ) ) {
            $weak[] = $r['sel'] . ' declares ' . $prop . ' at line ' . $r['line'];
            break;
        }
    }
}
if ( $weak ) {
    echo "\nFAIL: " . count( $weak ) . " rule(s) decide this control's appearance with one\n";
    echo "class or less, and can lose to an ordinary theme selector on the embed:\n";
    foreach ( $weak as $w ) { echo '  . ' . $w . "\n"; }
    exit( 1 );
}
echo "\nEvery rule that decides this control's appearance carries at least two\n";
echo "classes. The low-specificity ones reaching it are resets, which is right.\n";
