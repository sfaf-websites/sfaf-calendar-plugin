<?php
/**
 * THE TICK COLUMN IS DRAWN AT BOTH ENDS, OR AT NEITHER.
 *
 * WHY THIS EXISTS. 3.73.0 built the bulk category control and put its checkbox
 * cell in the WRONG LOOP. The events list got this:
 *
 *     <thead>  ... <th class="uc-col-tick"> ...      one cell
 *     <tbody>  ... (no tick cell in any row)         no cell
 *
 * and the dashboard's read-only upcoming_overview() got the other half: a
 * <td class="uc-col-tick"> in every row, under a <thead> with no such column,
 * guarded by a `$plain` that is not defined in that method, posting to a form
 * that is not on that screen.
 *
 * IT SHIPPED AND STAYED FOR SIX RELEASES. Nothing caught it, and the reason is
 * worth writing down: the two loops are near-identical where the cell was
 * inserted. Both open
 *
 *     $date = get_post_meta( $id, '_uc_event_date', true );
 *     $st   = get_post_status( $id ); ?>
 *     <tr>
 *
 * so the hunk applied cleanly and read correctly in review. Every check in use
 * at the time proved the file PARSED and that the string "data-uc-tick-one"
 * was PRESENT. Both were true. Neither asks the only question that mattered:
 * is it present in the same table as its header.
 *
 *     php .claude/bulk-ticks-test.php
 *
 * WHAT IT ASSERTS, none of which is a grep for a string on its own:
 *
 *   1. Every table renderer that emits a tick <th> emits a tick <td>, and the
 *      reverse. Counted per METHOD, so a cell in a neighbouring renderer does
 *      not satisfy a header here.
 *   2. Both are guarded by the same condition, so they cannot come apart under
 *      a viewer the other one draws for.
 *   3. upcoming_overview() carries no bulk markup at all. It is a separate
 *      renderer precisely so it has no destructive control to leak.
 *   4. Every `form="X"` association names a form id that the same file opens.
 *   5. The bulk publish route CALLS SFAF_Series::publish_skip_reason() and does
 *      not restate any of the five rules itself.
 */

$root = dirname( __DIR__ );
$file = $root . '/includes/class-sfaf-portal.php';
$raw  = file_get_contents( $file );

/*
 * COMMENTS FIRST. This file's own prose names <th class="uc-col-tick"> and
 * data-uc-tick-one while explaining them, and a sweep that matches its own
 * explanation is the fourth instance of that trap on this project. Only block
 * comments and lines that BEGIN with // go, so a "https://" in a string lives.
 */
$src = preg_replace( '#/\*.*?\*/#s', '', $raw );
$src = preg_replace( '#^\s*//.*$#m', '', $src );

$fails = array();
$notes = array();

/* ---------------------------------------------------------------------------
 * Slice the file into methods, so every count below is per renderer.
 *
 * BY INDENTATION, NOT BY BRACE MATCHING. These renderers are mostly HTML with
 * PHP interleaved, and a brace counter walks straight into `{` inside a class
 * attribute or a JS snippet. Every method in this class opens at exactly four
 * spaces and the next one does too, which is the reliable boundary here.
 * ------------------------------------------------------------------------ */
$methods = array();
if ( preg_match_all(
    '#^    (?:private|public|protected)(?: static)? function (\w+)\s*\(#m',
    $src,
    $m,
    PREG_OFFSET_CAPTURE
) ) {
    $n = count( $m[0] );
    for ( $i = 0; $i < $n; $i++ ) {
        $name  = $m[1][ $i ][0];
        $start = $m[0][ $i ][1];
        $end   = ( $i + 1 < $n ) ? $m[0][ $i + 1 ][1] : strlen( $src );
        $methods[ $name ] = substr( $src, $start, $end - $start );
    }
}
if ( count( $methods ) < 50 ) {
    $fails[] = 'the method slicer found only ' . count( $methods )
        . ' methods, so it is not slicing this file. Everything below is meaningless.';
}

/* ---------------------------------------------------------------------------
 * 1 and 2. The header cell and the body cell, in the same method, on the same
 *          condition.
 * ------------------------------------------------------------------------ */
$with_th = array();
$with_td = array();
foreach ( $methods as $name => $body ) {
    if ( false !== strpos( $body, '<th class="uc-col-tick"' ) ) { $with_th[] = $name; }
    if ( false !== strpos( $body, '<td class="uc-col-tick"' ) ) { $with_td[] = $name; }
}

foreach ( $with_th as $name ) {
    if ( ! in_array( $name, $with_td, true ) ) {
        $fails[] = $name . '() draws a tick COLUMN HEADER and no tick cell in any row.'
            . ' That is the 3.73.0 fault exactly: a header with nothing under it.';
    }
}
foreach ( $with_td as $name ) {
    if ( ! in_array( $name, $with_th, true ) ) {
        $fails[] = $name . '() draws a tick CELL with no column header over it.'
            . ' That is the other half of the 3.73.0 fault.';
    }
}
if ( empty( $with_th ) && empty( $with_td ) ) {
    $fails[] = 'no renderer draws a tick column at all. The bulk controls cannot select anything.';
}
$notes[] = sprintf(
    'tick column: %d renderer(s) draw the header, %d draw the cell',
    count( $with_th ),
    count( $with_td )
);

/*
 * THE SAME GUARD AT BOTH ENDS. A header drawn on one condition and a cell on
 * another is the same misalignment arriving by a slower route: it would be
 * right for the viewer who was tested and wrong for somebody else.
 */
foreach ( array_intersect( $with_th, $with_td ) as $name ) {
    $body = $methods[ $name ];

    $guard_th = '';
    $guard_td = '';
    if ( preg_match( '#if \(([^)]*)\) : \?>\s*<th class="uc-col-tick"#', $body, $g ) ) {
        $guard_th = trim( $g[1] );
    }
    if ( preg_match( '#if \(([^)]*)\) : \?>\s*<td class="uc-col-tick"#', $body, $g ) ) {
        $guard_td = trim( $g[1] );
    }

    if ( '' === $guard_th || '' === $guard_td ) {
        $fails[] = $name . '(): could not read the guard on the tick '
            . ( '' === $guard_th ? 'header' : 'cell' )
            . '. An unguarded tick column appears on every screen that borrows this table,'
            . ' where the form it posts to does not exist.';
        continue;
    }
    if ( $guard_th !== $guard_td ) {
        $fails[] = $name . '(): the tick header and the tick cell are drawn on DIFFERENT conditions.'
            . ' header: ' . $guard_th . ' / cell: ' . $guard_td;
        continue;
    }
    $notes[] = $name . '(): both ends guarded by ' . $guard_th;
}

/* ---------------------------------------------------------------------------
 * 3. The read-only table stays read-only.
 * ------------------------------------------------------------------------ */
if ( isset( $methods['upcoming_overview'] ) ) {
    foreach ( array( 'uc-col-tick', 'data-uc-tick-one', 'bulk_ids[]', 'uc-bulk-cat' ) as $marker ) {
        if ( false !== strpos( $methods['upcoming_overview'], $marker ) ) {
            $fails[] = 'upcoming_overview() contains "' . $marker . '". It is a separate renderer'
                . ' from events_table() so that it has no destructive control to leak, and a bulk'
                . ' selector is one. This is where the 3.73.0 cell actually landed.';
        }
    }
    $notes[] = 'upcoming_overview() carries no bulk markup';
} else {
    $fails[] = 'upcoming_overview() was not found, so rule 3 asserted nothing.';
}

/* ---------------------------------------------------------------------------
 * 4. Every form= association names a form this file opens.
 *
 * A checkbox associated with a form that is not on the page is a control that
 * cannot do anything, and it fails SILENTLY: the box ticks, and the press
 * carries nothing.
 * ------------------------------------------------------------------------ */
/*
 * EVERY <?php ... ?> BECOMES ONE TOKEN FIRST, AND THIS WAS NOT OPTIONAL.
 *
 * The first run of this file reported all seven associations broken and found
 * exactly ONE form id in a file that opens dozens. `<form[^>]*id="..."` cannot
 * work here: every form on these screens opens
 *
 *     <form method="post" action="<?php echo esc_url( ... ); ?>" id="...">
 *
 * and the `?>` closing that action attribute ends [^>]* long before id=. So the
 * sweep was not finding forms, it was finding one, and had it been written the
 * other way round it would have found none and passed in silence.
 *
 * Substituting a token also makes the dynamic ids fall out for free: an
 * id="uc-untag-<?php ?>-<?php ?>" and a form="uc-untag-<?php ?>-<?php ?>"
 * become the same literal string and compare as one.
 */
$tok = "\x01";

/*
 * AND IT IS BUILT FROM $raw, NOT FROM $src, WHICH COST THE SECOND RUN.
 *
 * $src has had every line beginning // removed, which is right for the method
 * slicing above and wrong here, because this file writes one-line comments as
 *
 *     <?php // Carries the fields only
 *           // and reaches it by id. ?>
 *
 * Stripping that second line takes the `?>` with it, so the `<?php` never
 * closes and the tokeniser swallows the next form whole. That is precisely how
 * uc-remove-user-N came to be reported as an association with no form: the form
 * was there, and the sweep had eaten it.
 *
 * Block comments still go, so a <form> inside one is not counted as opened.
 */
$flat = preg_replace( '#<\?php.*?\?>#s', $tok, preg_replace( '#/\*.*?\*/#s', '', $raw ) );

$opened = array();
if ( preg_match_all( '#<form\b[^>]*\bid="([^"]+)"#', $flat, $m ) ) {
    $opened = array_unique( $m[1] );
}

/*
 * THE FLOOR IS THE ASSOCIATIONS, NOT A NUMBER SOMEBODY LIKED. Most forms here
 * need no id at all; only the ones something points AT do. So the check that
 * this sweep is working is that it found at least as many ids as there are
 * distinct things pointing at one, which is the only relationship it exists to
 * verify.
 */
$assoc_probe = preg_match_all( '#\bform="([^"]+)"#', $flat, $m ) ? count( array_unique( $m[1] ) ) : 0;
if ( count( $opened ) < $assoc_probe ) {
    $fails[] = 'the form scanner found ' . count( $opened ) . ' form ids against '
        . $assoc_probe . ' association targets. It is not reading this file correctly,'
        . ' and every "missing form" below is more likely its fault than the source\'s.';
}

$assoc = array();
if ( preg_match_all( '#\bform="([^"]+)"#', $flat, $m ) ) {
    $assoc = array_unique( $m[1] );
}
$unverifiable = 0;
foreach ( $assoc as $target ) {
    if ( in_array( $target, $opened, true ) ) {
        continue;
    }
    if ( $tok === $target ) {
        /* form="<?php echo esc_attr( $form ); ?>": the id is computed, and the
         * renderer that computes it also opens the form. Counted and said, not
         * asserted, because asserting it would mean evaluating PHP. */
        $unverifiable++;
        continue;
    }
    $fails[] = 'form="' . str_replace( $tok, '<?php ?>', $target )
        . '" names a form id this file never opens.'
        . ' Every control associated with it is inert, and nothing says so.';
}
$notes[] = sprintf(
    '%d form id(s) opened, %d association target(s) resolved, %d computed at render',
    count( $opened ),
    count( $assoc ) - $unverifiable,
    $unverifiable
);

/* ---------------------------------------------------------------------------
 * 5. The publish rules are CALLED, not copied.
 *
 * This is the invariant the whole feature rests on: the schedule screen and the
 * events list must never come to disagree about what may reach the public
 * calendar, and the only way to guarantee that is one method deciding.
 * ------------------------------------------------------------------------ */
$publishers = array( 'bulk_plan', 'bulk_publish_from_post' );
foreach ( $publishers as $name ) {
    if ( ! isset( $methods[ $name ] ) ) {
        $fails[] = $name . '() was not found, so the publish-rule reuse asserted nothing.';
        continue;
    }
    $body = $methods[ $name ];
    if ( false === strpos( $body, 'SFAF_Series::publish_skip_reason(' ) ) {
        $fails[] = $name . '() does not call SFAF_Series::publish_skip_reason().'
            . ' The five publish rules must be asked, never restated.';
    }

    /*
     * AND IT MUST NOT HAVE QUIETLY GROWN ITS OWN COPY. These are the exact
     * tests publish_skip_reason() makes; any of them appearing HERE means the
     * rule now lives in two places, which is how the two screens drift.
     */
    $copies = array(
        "'draft' !== get_post_status"      => 'the draft test',
        'SFAF_Sources::META_REMOVED_AT'    => 'the removed-at-source test',
        'SFAF_Submissions::META_KIND'      => 'the submission test',
        'SFAF_Sources::provenance('        => 'the provenance test',
    );
    foreach ( $copies as $needle => $said ) {
        if ( false !== strpos( $body, $needle ) ) {
            $fails[] = $name . '() restates ' . $said . ' itself.'
                . ' That rule belongs to SFAF_Series::publish_skip_reason() and nowhere else.';
        }
    }
}

/* The rule itself still has to be where they are calling. */
$series = file_get_contents( $root . '/includes/class-sfaf-series.php' );
if ( false === strpos( $series, 'public static function publish_skip_reason(' ) ) {
    $fails[] = 'SFAF_Series::publish_skip_reason() is gone. Both callers above are broken.';
} else {
    $notes[] = 'publish_skip_reason() is called by ' . implode( ' and ', $publishers ) . ', and copied by neither';
}

/* ---------------------------------------------------------------------------
 * Report.
 * ------------------------------------------------------------------------ */
echo "Bulk ticks test\n";
foreach ( $notes as $n ) {
    echo '  . ' . $n . "\n";
}
echo "\n";

if ( $fails ) {
    echo 'FAIL: ' . count( $fails ) . "\n";
    foreach ( $fails as $f ) {
        echo '  . ' . $f . "\n";
    }
    exit( 1 );
}

echo "the tick column is drawn at both ends, on one condition, and the publish\n";
echo "rules are asked rather than repeated.\n";
