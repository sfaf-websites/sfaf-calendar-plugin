<?php
/**
 * A CHECK BOX SITS AT THE TOP OF ITS LABEL. EVERYWHERE. (3.93.0)
 *
 * THIS HAS BEEN FIXED TWICE AND REPORTED A THIRD TIME, and the reason it kept
 * coming back is that both fixes were a VALUE in a rule somebody had to
 * remember, and the list of rules was never the list of rules. 3.86.0's fix
 * carries the comment "this is the one place it is decided". By 3.92.0 three
 * later rules were overriding it and two stylesheets had never agreed with it.
 *
 * SO THIS FILE DOES NOT HOLD A LIST OF SELECTORS. It reads the SOURCE, finds
 * every element that actually wraps an <input type="checkbox"> or "radio",
 * collects the classes those elements wear, and then checks the stylesheets
 * against that set. A control added next month is covered by this the day it
 * is written, without anybody knowing this file exists.
 *
 * TWO THINGS ARE CHECKED.
 *
 *   1. The box-level rule is present in all three stylesheets. `align-self` on
 *      the box beats `align-items` on the row outright, because they are
 *      different properties on different elements, so a new row cannot take it
 *      back the way the last two fixes were taken back.
 *
 *   2. No rule naming a box-bearing class sets `align-items: center` or
 *      `baseline`. That is belt and braces: the rule in 1 already wins, but a
 *      centred row beside a top-aligned box is two rules disagreeing in the
 *      file, and the next person to read it will fix the wrong one.
 *
 * WHAT IS DELIBERATELY EXEMPT: a control whose input is hidden. A segmented
 * option, a day letter, a swatch, an email pill and a toggle all put
 * `position: absolute; opacity: 0` on the input and draw the control with a
 * sibling span. There is no box to align, and `align-self` on an absolutely
 * positioned element does nothing anyway, so centring that row is correct.
 * That exemption is DERIVED from the stylesheet, not listed here.
 *
 * Run: php .claude/checkbox-align-test.php [--self-test]
 */

$root = dirname( __DIR__ );
$fails = array();
function check( $ok, $why ) {
    global $fails;
    if ( ! $ok ) { $fails[] = $why; }
}

/* ---------------------------------------------------------------------------
 * 1. Which classes wrap a box, read out of the templates.
 * ------------------------------------------------------------------------ */
function uc_source_files( $dir ) {
    $out = array();
    $skip = array( '.git', 'node_modules', '.claude', '.build-stage', 'Old Calendar Files', 'Event Images', 'vendor' );
    $it = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
            function ( $file ) use ( $skip ) {
                return ! in_array( $file->getFilename(), $skip, true );
            }
        )
    );
    foreach ( $it as $file ) {
        if ( preg_match( '/\.(php|js)$/', $file->getFilename() ) ) {
            $out[] = $file->getPathname();
        }
    }
    return $out;
}

$box_classes = array();
foreach ( uc_source_files( $root ) as $file ) {
    $lines = file( $file );
    if ( ! $lines ) { continue; }
    foreach ( $lines as $i => $line ) {
        if ( ! preg_match( '/type=[\'"]?(checkbox|radio)/', $line ) ) { continue; }
        /*
         * THE NEAREST OPEN TAG, NOT EVERY TAG WITHIN FOUR LINES. A looser
         * reader collected the button row a tick-all sits beside, the icon in
         * a disclosure summary and the generic field row a toggle lives in,
         * none of which is a box against a label, and this check then failed
         * on rules that were correct. The wrapper is the LAST tag opened
         * before the input.
         */
        $wrapper = '';
        for ( $j = max( 0, $i - 4 ); $j <= $i; $j++ ) {
            if ( preg_match_all( '/<(?:label|div|span|li|td|p)[^>]*class=[\'"]([^\'"]+)/', $lines[ $j ], $mm ) ) {
                $wrapper = end( $mm[1] );
            }
        }
        if ( '' !== $wrapper ) {
            foreach ( preg_split( '/\s+/', $wrapper ) as $c ) {
                if ( preg_match( '/^uc-[a-z0-9-]+$/', $c ) ) { $box_classes[ $c ] = true; }
            }
        }
    }
}
$box_classes = array_keys( $box_classes );
sort( $box_classes );

check(
    count( $box_classes ) > 20,
    sprintf( 'only %d box-bearing classes were found in the source, so the reader is broken and this file is checking nothing', count( $box_classes ) )
);

/* ---------------------------------------------------------------------------
 * 2. The stylesheets.
 * ------------------------------------------------------------------------ */
$sheets = array(
    'public/css/calendar.css' => '.uc-calendar',
    'public/css/portal.css'   => '.uc-portal',
    'admin/css/admin.css'     => '.uc-admin-wrap',
);

$css_all = '';
foreach ( $sheets as $rel => $scope ) {
    $raw = (string) file_get_contents( $root . '/' . $rel );
    /* Comments stripped. Every rule below is explained in prose a few lines
     * above itself, so a check against the raw file passes on the explanation
     * of a rule somebody has just deleted. */
    $css  = preg_replace( '#/\*.*?\*/#s', '', $raw );
    $css_all .= "\n" . $css;

    check(
        (bool) preg_match(
            '/' . preg_quote( $scope, '/' ) . ' input\[type="checkbox"\][^{]*\{[^}]*align-self:\s*flex-start/s',
            $css
        ),
        $rel . ' has no box-level align-self rule, so the next centred row takes the alignment back'
    );
    check(
        (bool) preg_match(
            '/' . preg_quote( $scope, '/' ) . ' input\[type="checkbox"\][^{]*input\[type="radio"\]/s',
            $css
        ),
        $rel . ' aligns checkboxes and not radios, and a radio beside a wrapping label is the same defect'
    );
}

/* Which box classes draw their own box, and which hide the input and draw a
 * sibling instead. Derived, so a new hidden-input control exempts itself. */
$hidden = array();
foreach ( $box_classes as $c ) {
    if ( preg_match(
        '/\.' . preg_quote( $c, '/' ) . '(?![a-z0-9-])[^{}]*input[^{}]*\{[^}]*(position:\s*absolute|opacity:\s*0)/s',
        $css_all
    ) ) {
        $hidden[ $c ] = true;
    }
}

/* Now every rule in every sheet, against the visible set. */
$bad = array();
foreach ( $sheets as $rel => $scope ) {
    $css = preg_replace( '#/\*.*?\*/#s', '', (string) file_get_contents( $root . '/' . $rel ) );
    if ( preg_match_all( '/([^{}]+)\{([^{}]*)\}/s', $css, $rules, PREG_SET_ORDER ) ) {
        foreach ( $rules as $r ) {
            $sel  = trim( preg_replace( '/\s+/', ' ', $r[1] ) );
            $body = $r[2];
            if ( '' === $sel || '@' === substr( $sel, 0, 1 ) ) { continue; }
            if ( ! preg_match( '/(?:^|;)\s*align-items\s*:\s*(center|baseline)/', $body, $m ) ) { continue; }
            /*
             * THE BOX CLASS MUST BE THE SUBJECT OF THE SELECTOR, not an
             * ancestor in it. `.uc-sched-freq .uc-radio-row span` styles the
             * span inside the row; the row's own alignment is decided by the
             * row's own rule, and reading the ancestor flagged a rule that has
             * nothing to do with any box.
             */
            $subject = preg_split( '/[ >+~]+/', $sel );
            $subject = (string) end( $subject );
            foreach ( $box_classes as $c ) {
                if ( isset( $hidden[ $c ] ) ) { continue; }
                if ( ! preg_match( '/\.' . preg_quote( $c, '/' ) . '(?![a-z0-9-])/', $subject ) ) { continue; }
                $bad[] = sprintf( '%s  %s  {align-items: %s}', $rel, $sel, $m[1] );
            }
        }
    }
}
check(
    empty( $bad ),
    "a box is centred against its label again:\n      " . implode( "\n      ", array_unique( $bad ) )
);

/* ---------------------------------------------------------------------------
 * 3. The self-test. A checker that has never failed is a checker nobody has
 *    proved, and two of these have shipped broken. Both halves are planted.
 * ------------------------------------------------------------------------ */
if ( in_array( '--self-test', $argv, true ) ) {
    $probe_sheet = "\n.uc-check { display: flex; align-items: center; gap: 8px; }\n";
    $probe_css   = preg_replace( '#/\*.*?\*/#s', '', (string) file_get_contents( $root . '/public/css/portal.css' ) ) . $probe_sheet;
    $found = false;
    if ( preg_match_all( '/([^{}]+)\{([^{}]*)\}/s', $probe_css, $rules, PREG_SET_ORDER ) ) {
        foreach ( $rules as $r ) {
            if ( preg_match( '/align-items\s*:\s*(center|baseline)/', $r[2] )
                 && preg_match( '/\.uc-check(?![a-z0-9-])/', $r[1] ) ) {
                $found = true;
            }
        }
    }
    if ( ! $found ) {
        echo "SELF-TEST FAILED: a planted centred rule was not seen.\n";
        exit( 1 );
    }
    if ( in_array( 'uc-check', $box_classes, true ) === false ) {
        echo "SELF-TEST FAILED: uc-check is not in the derived class set, so the reader is not reading.\n";
        exit( 1 );
    }
    if ( empty( $hidden ) ) {
        echo "SELF-TEST FAILED: nothing was exempted, so the hidden-input rule is not being derived.\n";
        exit( 1 );
    }
    echo "self-test passed: a planted centred rule is seen, the class set is real, and the exemption is derived.\n";
}

if ( $fails ) {
    echo 'FAIL: ' . count( $fails ) . "\n";
    foreach ( array_unique( $fails ) as $f ) { echo '  . ' . $f . "\n"; }
    exit( 1 );
}

printf(
    "Checkbox alignment\n  %d class(es) wrap a box in the source, %d of them draw their own\n",
    count( $box_classes ),
    count( $box_classes ) - count( $hidden )
);
printf( "  %d hidden-input control(s) exempt, derived from the stylesheet rather than listed\n", count( $hidden ) );
echo "  three stylesheets each align the BOX, not the row, so a new centred row cannot take it back\n";
echo "  no rule naming a visible box centres it against its label\n";
exit( 0 );
