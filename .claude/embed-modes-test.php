<?php
/**
 * THE COMBINED MODE, AND WHERE A BLOCK'S EVENTS LINK.
 *
 * Two claims that are easy to make on inspection and easy to get wrong:
 *
 *   1. The combined mode shows BOTH panels and no view toggle, and every other
 *      mode is unchanged.
 *   2. "Open events to source listing" reaches EVERY link that would otherwise
 *      point at an event page, in every display mode. This is the one that
 *      fails quietly: a renderer resolving its own URL is a mode where the
 *      setting silently does nothing, and nobody notices until a manager says
 *      the sidebar ignores it.
 *
 *     php .claude/embed-modes-test.php
 *
 * The second claim is checked two ways, because they catch different mistakes:
 * behaviour, by running sfaf_event_link() through every state it has; and
 * coverage, by sweeping the renderers for any get_permalink() that should have
 * been sfaf_event_link(). The sweep is what catches a mode added later.
 */

$root = dirname( __DIR__ );
define( 'ABSPATH', __DIR__ );

/* --- Just enough WordPress for the link resolver. ----------------------- */
$GLOBALS['meta'] = array();
function get_post_meta( $id, $key, $single = false ) {
    return isset( $GLOBALS['meta'][ $id ][ $key ] ) ? $GLOBALS['meta'][ $id ][ $key ] : '';
}
function get_permalink( $id = 0, $leavename = false ) {
    return 'https://resources.sfaf.org/events/event-' . (int) $id . '/';
}
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }

require_once $root . '/includes/sfaf-template-functions.php';

$fails = array();
function check( $ok, $msg ) {
    global $fails;
    if ( ! $ok ) { $fails[] = $msg; }
}

/* =========================================================================
 * WHERE EVENTS LINK
 * ====================================================================== */

// 10 is imported and has a source listing. 11 is native and has none.
$GLOBALS['meta'] = array(
    10 => array( '_uc_source_url' => 'https://donate.sfaf.org/cycle-to-zero' ),
    11 => array(),
);

/* --- Off, which is the shipped default. --------------------------------- */
check( false === sfaf_source_links_default(), 'the shipped default is no longer off; if that is deliberate, this line is the one to change' );

sfaf_set_source_links( null );
check(
    'https://resources.sfaf.org/events/event-10/' === sfaf_event_link( 10 ),
    'with the setting unset, an imported event does not link to its event page'
);
check( ! sfaf_event_link_is_external( 10 ), 'an event reads as external while the setting is off' );
check( '' === sfaf_external_marker( 10 ), 'the external marker rendered while the setting is off' );

/* --- On. ---------------------------------------------------------------- */
sfaf_set_source_links( true );
check(
    'https://donate.sfaf.org/cycle-to-zero' === sfaf_event_link( 10 ),
    'with the setting on, an imported event does not link to its source'
);
check( sfaf_event_link_is_external( 10 ), 'an imported event does not read as external while the setting is on' );
check( '' !== sfaf_external_marker( 10 ), 'no external marker on an imported event while the setting is on' );

/*
 * A NATIVE EVENT ALWAYS OPENS HERE. It has nowhere else to go, so the setting
 * cannot apply to it, and a block mixing the two must not send half its cards
 * to a blank URL.
 */
check(
    'https://resources.sfaf.org/events/event-11/' === sfaf_event_link( 11 ),
    'with the setting on, a NATIVE event was sent somewhere other than its event page'
);
check( ! sfaf_event_link_is_external( 11 ), 'a native event reads as external' );
check( '' === sfaf_external_marker( 11 ), 'a native event got an external marker' );

/* --- Explicitly off beats the default, whatever the default becomes. ----- */
sfaf_set_source_links( false );
check(
    'https://resources.sfaf.org/events/event-10/' === sfaf_event_link( 10 ),
    'an explicit no did not override the default'
);

/* --- The marker announces itself in words, not as an arrow. ------------- */
sfaf_set_source_links( true );
$mark = sfaf_external_marker( 10 );
check( false !== strpos( $mark, 'aria-hidden' ), 'the arrow is not hidden from screen readers' );
check( false !== strpos( $mark, 'uc-sr-only' ), 'there is no spoken alternative to the arrow' );
sfaf_set_source_links( null );

/* =========================================================================
 * COVERAGE: no renderer resolves its own event URL
 * ====================================================================== */

$src  = file_get_contents( $root . '/includes/class-sfaf-shortcodes.php' );
$code = preg_replace( '#/\*.*?\*/#s', '', $src );
$code = preg_replace( '#^\s*//.*$#m', '', $code );

/*
 * EVERY CARD RENDERER LIVES IN THIS FILE, so a get_permalink() left in it is a
 * link the setting cannot reach. There is no legitimate one: the share URL and
 * the RSVP handoff, which must always point here, are in
 * sfaf-template-functions.php and are not card links.
 */
preg_match_all( '/get_permalink\s*\(/', $code, $m );
check(
    0 === count( $m[0] ),
    sprintf(
        '%d get_permalink() call(s) left in the card renderers; each is a link "open events to source listing" cannot reach',
        count( $m[0] )
    )
);

// And the resolver is actually used, so the check above cannot pass by the
// renderers having no links at all.
preg_match_all( '/sfaf_event_link\s*\(/', $code, $m2 );
check( count( $m2[0] ) >= 4, 'fewer than four renderers ask sfaf_event_link(); the card, compact card, sidebar row and month grid all should' );

/* =========================================================================
 * THE COMBINED MODE
 * ====================================================================== */

$block = $code;

// The mode exists and is one of the four.
check(
    false !== strpos( $block, "'combined'" ),
    'normalize_view() does not know about the combined mode'
);

// Both panels are built for it.
check(
    false !== strpos( $block, '$want_list = ( $toggle || $combined' ) && false !== strpos( $block, '$want_grid = ( $toggle || $combined' ),
    'the combined mode does not force both panels to be built, so one of them would be empty'
);

// Neither panel is hidden in it.
check(
    false !== strpos( $block, 'if ( $combined ) {' ) && false !== strpos( $block, "return '';" ),
    'the combined mode does not un-hide both panels'
);

// The toggle is off, in the renderer and not only in the generator.
check(
    false !== strpos( $block, 'if ( $combined ) {' ) && false !== strpos( $block, '$toggle = false;' ),
    'the combined mode does not force the view toggle off, so a hand-written shortcode could render one'
);

// The grid is emitted before the list, in the DOM, for the combined mode only.
check(
    false !== strpos( $block, '? $panel_grid . $panel_list' ),
    'the combined mode does not put the grid before the list in the DOM'
);
check(
    false !== strpos( $block, ': $panel_list . $panel_grid' ),
    'the other modes no longer keep the original list-then-grid order'
);

/* --- The CSS side: intrinsic, and the stack point is what is published. -- */
$css = file_get_contents( $root . '/public/css/calendar.css' );

check(
    false !== strpos( $css, '.uc-view-panels-combined' ),
    'no CSS for the combined wrapper'
);
check(
    (bool) preg_match( '/\.uc-view-panels-combined\s*\{[^}]*flex-wrap:\s*wrap/', $css ),
    'the combined wrapper does not wrap, so it can never stack'
);
check(
    ! preg_match( '/@container[^{]*\{[^}]*uc-view-panels-combined/s', $css ),
    'the combined mode uses a container query; DESIGN.md asks for the intrinsic remedy here'
);

/*
 * THE PUBLISHED NUMBER IS THE ONE THE CSS PRODUCES.
 *
 * Flex line breaking uses each item's hypothetical main size, which is its
 * flex-basis, so the wrap point is the two bases plus the gap. Recomputed from
 * the file rather than trusted, because the readme publishes it and a basis
 * nudged later would make that number a lie.
 */
preg_match( '/\.uc-view-panels-combined\s*\{[^}]*gap:\s*(\d+)px/', $css, $g );
preg_match( '/uc-panel-calendar\s*\{\s*flex:\s*1\s+1\s+(\d+)px/', $css, $c1 );
preg_match( '/uc-panel-list\s*\{\s*flex:\s*1\s+1\s+(\d+)px/', $css, $c2 );

$stack = ( isset( $g[1], $c1[1], $c2[1] ) ) ? ( (int) $c1[1] + (int) $c2[1] + (int) $g[1] ) : 0;
check( $stack > 0, 'could not read the combined mode bases and gap out of the CSS' );
check( 920 === $stack, "the combined mode now stacks at {$stack}px and the readme publishes 920px" );

/*
 * SIDE BY SIDE MUST NEVER BE WORSE THAN STACKING, which is the fault the 400px
 * grid basis shipped in 3.30.0 and 770px is where sfaf.org met it.
 *
 * The month grid stops showing events in its cells at or below 560px of its own
 * column and becomes seven columns of dots with a day panel under it. That is
 * the right treatment for a phone and the wrong one for half of a 770px block,
 * because the same 770px STACKED gives the grid all of it and its entries back.
 *
 * So the two numbers are tied together here rather than each written down on its
 * own: the changeover has to sit high enough that the grid's share, at every
 * width where the two sit side by side, clears the breakpoint. The grid's share
 * is its basis plus half the surplus, so the narrowest it is ever given is the
 * basis itself, and the sweep below says so at every width rather than trusting
 * that one line of algebra.
 */
preg_match( '/@container uc-calendar \(max-width: (\d+)px\) \{\s*\.uc-calendar \.uc-month-grid td/', $css, $d );
$dots = isset( $d[1] ) ? (int) $d[1] : 0;
check( $dots > 0, 'could not find the breakpoint where the month grid collapses to dots' );

$basis_grid = isset( $c1[1] ) ? (int) $c1[1] : 0;
$basis_list = isset( $c2[1] ) ? (int) $c2[1] : 0;

$bad = array();
for ( $w = 300; $w <= 1400; $w++ ) {
    if ( $w < $stack ) {
        continue; // Stacked. Each panel gets the whole width, which is the grid mode.
    }
    $share = $basis_grid + ( ( $w - $stack ) / 2 );
    if ( $share <= $dots ) {
        $bad[] = $w;
    }
}
check(
    empty( $bad ),
    sprintf(
        'side by side, the grid gets %dpx or less at %d width(s) from %dpx up, at or under the %dpx where it collapses to dots; stacking would give it the whole width, so the changeover is too low',
        $dots,
        count( $bad ),
        empty( $bad ) ? 0 : $bad[0],
        $dots
    )
);

/*
 * 770px BY NAME. It is the width the theme gives this block on sfaf.org, Mark
 * cannot widen it, and it is 26px above where the old bases put the changeover,
 * which is how the mode came to be side by side with nowhere to put the grid.
 */
$sfaf = 770;
check(
    $sfaf < $stack,
    sprintf( 'at %dpx the combined mode goes side by side and gives the grid %.0fpx, which is under the %dpx it needs; sfaf.org constrains this block to %dpx and the theme is locked', $sfaf, $basis_grid + ( ( $sfaf - $stack ) / 2 ), $dots, $sfaf )
);
check(
    $sfaf > $dots,
    sprintf( 'stacked at %dpx the grid still falls under %dpx and collapses to dots', $sfaf, $dots )
);

check(
    (bool) preg_match( '/uc-panel-calendar\s*\{[^}]*min-width:\s*0/', $css ),
    'the grid panel has no min-width: 0, so the month table cannot shrink and will overflow the host page'
);
check(
    (bool) preg_match( '/uc-panel-list\s*\{[^}]*min-width:\s*0/', $css ),
    'the list panel has no min-width: 0'
);

/* =========================================================================
 * THE MONTH GRID CARD
 *
 * The accent bar is gone and a 32px thumbnail with a category ring replaced
 * it. The numbers the readme publishes for cell and title width are computed
 * here from the stylesheet rather than written down twice.
 * ====================================================================== */

check(
    ! preg_match( '/uc-day-event a \{[^}]*border-left:\s*3px solid var\(--cat-color/', $css ),
    'the day event still has the accent bar, which is the thing this replaced'
);
check(
    false !== strpos( $css, '.uc-calendar .uc-de-thumb' ),
    'no thumbnail on the day event'
);
check(
    (bool) preg_match( '/\.uc-calendar \.uc-de-thumb \{[^}]*width:\s*32px[^}]*height:\s*32px/', $css ),
    'the day event thumbnail is not 32 by 32'
);
check(
    (bool) preg_match( '/\.uc-calendar \.uc-de-thumb \{[^}]*box-shadow:\s*0 0 0 2px var\(--cat-ink/', $css ),
    'the ring is not a 2px --cat-ink; it must be the contrast-checked ink, never the raw category colour'
);
check(
    ! preg_match( '/\.uc-calendar \.uc-de-thumb \{[^}]*var\(--cat-color/', $css ),
    'the ring uses the raw category colour, six of which fall under 3:1 on white'
);
check(
    (bool) preg_match( '/\.uc-day-event-title \{[^}]*-webkit-line-clamp:\s*2/', $css ),
    'the day event title does not clamp to two lines'
);
check(
    ! preg_match( '/\.uc-day-event-title \{[^}]*white-space:\s*nowrap/', $css ),
    'the day event title is still nowrap, so it truncates at one line rather than wrapping to two'
);
check(
    false !== strpos( $css, '@container uc-calendar (max-width: 930px)' ),
    'no breakpoint where the thumbnail stops fitting; at 89px columns it would crush the title'
);
check(
    (bool) preg_match( '/uc-view-panels-combined > \.uc-view-panel \{[^}]*container-name:\s*uc-calendar/', $css ),
    'the combined mode panels are not their own containers, so the grid asks the block how wide IT is and gets the wrong answer'
);

// The PHP side: shades, not the raw colour, and no cap on how many show.
check(
    false !== strpos( $code, 'sfaf_category_shades( sfaf_event_category_color( $id ) )' ),
    'the day event does not resolve its colours through sfaf_category_shades()'
);
check(
    false !== strpos( $code, 'sfaf_day_event_thumb( $id )' ),
    'the day event does not render a thumbnail'
);
check(
    ! preg_match( '/uc-day-events.*?array_slice|uc-day-events.*?more<|\+\s*\$more/s', $code ),
    'something caps how many events a day cell shows; every event on a day must be in it'
);

/* --- The geometry, computed from the two caps in the stylesheet. -------- */
preg_match( '/\.uc-calendar \{[^}]*max-width:\s*(\d+)px/', $css, $m_old );
preg_match( '/\.uc-calendar\.uc-view-calendar[^{]*\{\s*max-width:\s*(\d+)px/', $css, $m_new );
preg_match( '/\.uc-calendar \.uc-month-grid td \{[^}]*padding:\s*(\d+)px/', $css, $m_pad );
preg_match( '/\.uc-calendar \.uc-day-event a \{[^}]*gap:\s*(\d+)px/', $css, $m_gap );

$cap_old = isset( $m_old[1] ) ? (int) $m_old[1] : 0;
$cap_new = isset( $m_new[1] ) ? (int) $m_new[1] : 0;
$cellpad = isset( $m_pad[1] ) ? (int) $m_pad[1] : 0;
$thumbgap = isset( $m_gap[1] ) ? (int) $m_gap[1] : 0;

check( 900 === $cap_old, "the block cap is now {$cap_old}px and the readme publishes 900px for the list" );
check( 1200 === $cap_new, "the month grid cap is now {$cap_new}px and the readme publishes 1200px" );
check( $cap_new > $cap_old, 'the month grid cap is not wider than the block cap, so nothing was widened' );

/*
 * Column outer = (cap - 2 for the wrapper border) / 7.
 * Cell content = column - 2 collapsed borders - both cell paddings.
 * Title        = cell content - the entry's own border and padding - thumb - gap.
 */
function geom( $cap, $cellpad, $thumbgap ) {
    $col   = ( $cap - 2 ) / 7;
    $cell  = $col - 2 - ( 2 * $cellpad );
    $entry = $cell - 2 - 8;              // 1px border each side, 4px padding each side
    return array( $col, $cell, $entry - 32 - $thumbgap );
}
list( $col_o, $cell_o, $title_o ) = geom( $cap_old, 4, $thumbgap ); // the old cell padding was 4
list( $col_n, $cell_n, $title_n ) = geom( $cap_new, $cellpad, $thumbgap );

check( $title_n > $title_o, 'the wider grid did not leave more room for a title than the old one did' );
check( $title_n > 100, sprintf( 'the title gets %.0fpx, which is under the 100px this was widened to reach', $title_n ) );

/* --- The generator offers it, and the embed carries it. ----------------- */
$admin = file_get_contents( $root . '/admin/class-sfaf-admin.php' );
check( false !== strpos( $admin, "'combined' =>" ), 'the embed generator does not offer the combined mode' );
check( false !== strpos( $admin, 'uc-embed-source-links' ), 'the embed generator has no source-links control' );

$js = file_get_contents( $root . '/admin/js/admin.js' );
check( false !== strpos( $js, "attrs['data-source-links']" ), 'the generator does not write data-source-links onto the block' );

$ejs = file_get_contents( $root . '/public/js/embed.js' );
check( false !== strpos( $ejs, "data-source-links" ), 'embed.js does not send the setting back to the endpoint' );
check(
    false !== strpos( $ejs, "configured === 'combined'" ),
    'embed.js lets a remembered view override the combined mode, which has no toggle to have chosen with'
);

/*
 * WHICH PANELS ARE SHOWN IS ONE DECISION, IN ONE FUNCTION.
 *
 * showView() used to make it twice, as `list.hidden = (view !== 'list')` beside
 * `cal.hidden = (view !== 'calendar')`. Both are right for the two views that
 * existed when they were written and both are wrong for any third, so the
 * combined mode hid every panel it had and rendered its chrome over an empty
 * space. This file asserted the sources and passed throughout.
 *
 * THE ASSERTION THAT ACTUALLY CATCHES IT IS IN
 * .claude/embed-combined-panels-test.js, which runs these functions and counts
 * the events a visitor can see. Run both; this one only proves the decision has
 * not been split back into two comparisons that can disagree.
 */
/*
 * SWEPT WITH THE COMMENTS OFF. The comment above panelHiddenFor() quotes the
 * line it replaced, so a sweep of the raw file reports the fault it just fixed.
 * That is not a hypothetical: it is what this check did on its first run.
 */
$ejs_code = preg_replace( '#/\*.*?\*/#s', '', $ejs );
$ejs_code = preg_replace( '#^\s*//.*$#m', '', $ejs_code );

check(
    false !== strpos( $ejs_code, 'function panelHiddenFor(' ),
    'embed.js decides panel visibility inline again rather than in one function; that is the shape the combined mode shipped broken in'
);
check(
    ! preg_match( '/\.hidden\s*=\s*\(\s*view\s*!==/', $ejs_code ),
    'a panel in embed.js is hidden by comparing the view directly, which hides every panel for any view the comparison does not name'
);
check(
    ! preg_match( '/uc-view-\(list\|calendar\)\\\\b/', $ejs_code ),
    'the uc-view class rewrite in embed.js names only list and calendar again, so a combined block keeps two uc-view classes'
);

$cjs = preg_replace( '#/\*.*?\*/#s', '', file_get_contents( $root . '/public/js/calendar.js' ) );
check(
    false !== strpos( $cjs, 'function panelHiddenFor(' ),
    'calendar.js has not had the same treatment as its twin in embed.js'
);

$embed = file_get_contents( $root . '/includes/class-sfaf-embed.php' );
check( false !== strpos( $embed, "'source_links'" ), 'the embed endpoint does not accept source_links' );
check(
    false !== strpos( $embed, "\$identity['source_links']" ),
    'source_links is not part of the cache identity, so two blocks differing only in it would share a cached payload'
);
check(
    false !== strpos( $embed, 'sfaf_set_source_links' ),
    'build_payload() does not set the link destination, so page two and month navigation would ignore it'
);

echo "Embed modes\n";
echo "links:    the resolver in all four states (unset, on, off, native event), the marker's\n";
echo "          spoken alternative, and no get_permalink() left in any card renderer\n";
printf( "combined: both panels built and shown, no view toggle, grid before list in the DOM,\n" );
printf( "          intrinsic flex rather than a container query, stacks at %dpx, min-width: 0 on both,\n", $stack );
echo "          and each half is its own query container so it measures its own column\n";
printf( "width:    side by side never gives the grid under %dpx, checked at every width from 300\n", $dots );
printf( "          to 1400; at %dpx it stacks, so the grid gets all %dpx and keeps its entries\n", $sfaf, $sfaf );
echo "          (the panels being VISIBLE is asserted in embed-combined-panels-test.js; run it too)\n";
echo "grid card: no accent bar, a 32px thumbnail ringed in --cat-ink, the title clamped to two\n";
echo "          lines, the thumbnail dropped below 930px, and nothing capping how many show\n";
printf( "geometry: cap %dpx to %dpx. Column %.1fpx to %.1fpx outer, cell content %.1fpx to %.1fpx,\n",
    $cap_old, $cap_new, $col_o, $col_n, $cell_o, $cell_n );
printf( "          title %.1fpx to %.1fpx once the 32px thumbnail and its %dpx gap are taken\n", $title_o, $title_n, $thumbgap );
echo "carried:  the generator control, the block attribute, embed.js, the endpoint parameter\n";
echo "          and the cache identity\n\n";

if ( $fails ) {
    echo 'FAIL: ' . count( $fails ) . "\n";
    foreach ( array_unique( $fails ) as $f ) { echo '  . ' . $f . "\n"; }
    exit( 1 );
}
echo "the combined mode composes both renderers, source linking reaches every one of them,\n";
echo "and the grid card carries a ring that can be seen rather than a bar that could not.\n";
exit( 0 );
