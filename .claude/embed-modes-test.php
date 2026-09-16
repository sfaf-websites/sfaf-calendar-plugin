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
 * EVERY LINK TO AN EVENT OPENS A NEW TAB, AND SAYS SO (3.67.0)
 *
 * The calendar renders inside somebody else's page, so a card that navigated
 * in place would take a visitor away from wherever they were reading. That is
 * also why it is _blank and never _top: _top replaces the whole window the
 * embed sits in.
 *
 * COUNTED, NOT SPOT CHECKED. There are five anchors in this file that point at
 * an event: the month grid day link, the sidebar row, the card's media, the
 * card's title and the compact card. One left behind is a display mode that
 * behaves differently for no reason anybody could see.
 * ====================================================================== */

preg_match_all( '/sfaf_new_tab_attrs\s*\(/', $code, $m3 );
check( count( $m3[0] ) >= 5,
    sprintf( 'only %d of the five event anchors carry sfaf_new_tab_attrs(); one renderer still navigates in place', count( $m3[0] ) ) );

check( false === strpos( $code, 'target="_top"' ),
    'a renderer uses target="_top", which replaces the whole window the embed is sitting in' );

/*
 * AND NEVER noreferrer ON ONE OF THEM. noopener is the security half and is
 * what these carry. noreferrer ALSO suppresses the Referer header, and the
 * event page reads exactly that header to work out which calendar somebody
 * came from, which is what "All Events" needs. See sfaf_calendar_referrer().
 */
if ( preg_match_all( '/rel="([^"]*)"/', $code, $rels ) ) {
    foreach ( $rels[1] as $rel ) {
        if ( false !== strpos( $rel, 'noreferrer' ) ) {
            check( false, 'a card link carries rel="noreferrer", which strips the referrer the event page reads for its back link' );
            break;
        }
    }
}

/* THE ANNOUNCEMENT, once per link a person can reach. The card's media anchor
 * is aria-hidden and out of the tab order, so it has no name to add one to,
 * which leaves four. */
preg_match_all( '/sfaf_new_tab_note\s*\(/', $code, $m4 );
check( count( $m4[0] ) >= 4,
    sprintf( 'only %d event links say they open a new tab; a link that moves somebody to one has to say so', count( $m4[0] ) ) );

/* =========================================================================
 * THE COMBINED MODE
 * ====================================================================== */

$block = $code;

// The mode exists and is one of the four.
check(
    false !== strpos( $block, "'combined'" ),
    'normalize_view() does not know about the combined mode'
);

/*
 * THE GRID IS BUILT FOR IT AND THE CARD LIST IS NOT, which is the 3.32.0 change.
 * The right column is the sidebar renderer, so a combined block that still built
 * a card list would be paying for twelve cards it does not emit.
 */
check(
    false !== strpos( $block, '$want_grid = ( $toggle || $combined' ),
    'the combined mode does not force the month grid to be built, so its left half would be empty'
);
check(
    false !== strpos( $block, '$want_list = ( $toggle ||' ) && false === strpos( $block, '$want_list = ( $toggle || $combined' ),
    'the combined mode still builds the card list; its right column is the sidebar, so the list is work whose output is thrown away'
);
check(
    false !== strpos( $block, 'uc-view-panel uc-panel-sidebar' ),
    'the combined mode does not emit a sidebar panel'
);
check(
    false !== strpos( $block, '$this->render_sidebar(' ) && substr_count( $block, '$this->render_sidebar(' ) >= 2,
    'the combined mode does not go through render_sidebar(), so its right column is a second rendering of something that already exists'
);
check(
    false !== strpos( $block, 'self::sidebar_count(' ) && substr_count( $block, 'self::sidebar_count(' ) >= 2,
    'the count of upcoming dates is resolved in two places rather than one, so the sidebar mode and the combined mode can drift'
);

/* -------------------------------------------------------------------------
 * THE COMBINED MODE HAS A TOGGLE AGAIN (3.82.0), AND THESE FIVE CHECKS ARE THE
 * REVERSE OF THE THREE THAT WERE HERE.
 *
 * What those asserted was 3.45.0's decision: no toggle, no pagination, neither
 * panel hidden. That was right while the mode was only ever looked at SIDE BY
 * SIDE, where both views really are on screen. Stacked, which is what sfaf.org
 * gives it at 700px, what is on screen is a very tall month grid and a short
 * list of upcoming dates, and the list view is unreachable. A day carrying nine
 * events is exactly when somebody needs it.
 *
 * PHP CANNOT TELL THE TWO SHAPES APART, because the panels stack on flex-wrap
 * at a width decided in the browser. So the toggle is rendered in both shapes,
 * and what is asserted here is that it goes somewhere sensible and that nothing
 * belonging to the list can appear under the grid.
 * ---------------------------------------------------------------------- */

/* Pagination stays on, because there is a list to page and it holds 287 events.
 * What matters is that the controls live INSIDE the list panel, so they hide
 * with it and can never turn up under a month grid. */
check(
    false === strpos( $block, '$paginate = false;' ),
    'the combined mode switches pagination off again, so pressing List would hand over every event in one response'
);
if ( preg_match( '/<div class="uc-view-panel uc-panel-list".*?\$panel_list = ob_get_clean\(\);/s', $block, $lp ) ) {
    check(
        false !== strpos( $lp[0], 'render_pagination(' ),
        'the pagination controls moved out of the list panel, so they are no longer hidden with the list they page'
    );
} else {
    check( false, 'could not slice the list panel, so nothing was asserted about where pagination is emitted' );
}

/* The list panel is BUILT in the combined mode and carries hidden. Built,
 * because the toggle needs somewhere to go; hidden, because showing it under
 * the grid would be both views at once. */
check(
    false !== strpos( $block, "return ( 'list' === \$panel ) ? ' hidden' : '';" ),
    'the combined mode no longer hides its list panel, so the list would render underneath the grid'
);
check(
    false !== strpos( $block, '$panel_grid . $panel_side . $panel_list' ),
    'the combined mode does not emit its list panel, so the toggle has nowhere to go'
);

/* The toggle is rendered, and its calendar button goes HOME rather than to a
 * bare grid. Sending it to 'calendar' would collapse a block somebody
 * configured as combined and leave no way back, which is worse than the
 * missing toggle this replaced. */
check(
    false === strpos( $block, '$toggle = false;' ),
    'the combined mode forces the view toggle off again, which leaves the stacked shape with no route to the list'
);
check(
    false !== strpos( $block, 'render_view_toggle( $view, $combined ? "combined" : $view )' ),
    'the view toggle is not told which view is home, so its calendar button cannot send a combined block back to the combined layout'
);
if ( preg_match( '/private function render_view_toggle\(.*?\n    \}/s', $code, $tf ) ) {
    check(
        false !== strpos( $tf[0], "'combined' === \$home" ),
        'render_view_toggle() no longer asks what home is, so a combined block gets a button pointing at a view it was never configured for'
    );
} else {
    check( false, 'could not slice render_view_toggle(), so nothing was asserted about where its calendar button goes' );
}

/*
 * The grid is emitted before the sidebar, in the DOM, for the combined mode.
 *
 * MATCHED AS AN ORDER RATHER THAN AS A LINE. This was a grep for the exact
 * string `? $panel_grid . $panel_side`, and 3.45.0 put the month head in front
 * of them on the same expression, which is the same order and a different line.
 * A test that fails when correct code is rearranged teaches people to edit the
 * test. What matters is that the grid comes first; the real check that it does
 * is combined-outcome-test.php, which renders it and reads the markup.
 */
check(
    (bool) preg_match( '/\?\s*.*\$panel_grid\s*\.\s*\$panel_side/', $block ),
    'the combined mode does not put the grid before the sidebar in the DOM'
);

/*
 * THE TWO HALVES ARE HANDED THE SAME MONTH, AND THE HEAD IS DRAWN ONCE.
 *
 * These are composition, so they are checked here rather than in
 * combined-outcome-test.php: that file renders the two renderers and reads what
 * came back, which is the right way to test what they produce, but it does not
 * render the block that composes them. Planting both of these is what showed
 * the gap, because the outcome test could not see either.
 *
 * If the month stops being passed, the sidebar reverts to "what is coming up"
 * and sits beside a grid showing October with September's events in it. If the
 * head flag stops being passed, the grid draws its own head inside a container
 * that already has one across the top.
 */
/*
 * SCOPED TO THE COMPOSITION, NOT TO THE FILE. Both of these calls appear twice:
 * once where the block composes the panels and once in ajax_load_month(), which
 * redraws them. A check that only asked whether the string exists anywhere was
 * satisfied by the ajax copy while the panel had lost it, and both plants
 * passed. Anchored on the surrounding line instead.
 */
/*
 * ONE COMPOSITION, AND THE BLOCK USES IT (3.45.1).
 *
 * These two used to check the block's own argument lists: that it passed the
 * month to render_sidebar() and the head flag to render_month_grid(). It did
 * both, and the redraw in ajax_load_month() composed the same three pieces
 * again with its own rules, so the two paths produced different markup for the
 * same month and only the first render was right.
 *
 * There is one composition now, so the useful claim is that the block asks it
 * rather than assembling the pieces itself. The two outputs being identical is
 * asserted where they can be compared: combined-outcome-test.php.
 */
check(
    false !== strpos( $block, '$this->render_combined_parts(' ),
    'the combined mode does not go through render_combined_parts(), so it composes the view a second time'
);
check(
    false !== strpos( $block, "\$parts['head']" ) && false !== strpos( $block, "\$parts['side']" ) && false !== strpos( $block, "\$parts['grid']" ),
    'the combined mode does not use all three pieces of the one composition, so at least one is still built locally'
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
preg_match( '/\.uc-view-panels-combined\s*\{[^}]*gap:\s*(\d+)(?:px)?\s*;/', $css, $g );
/* ANY GROW AND SHRINK, BECAUSE THE BASIS IS WHAT DECIDES THE WRAP. These
 * patterns read `flex: 1 1 576px` until 3.81.0 gave the grid a grow factor of
 * 999, at which point they matched nothing, $stack went to 0 and every check
 * below it failed with a number derived from zero. A pattern that stops
 * matching is a check that stops checking, and it reported three faults that
 * were all itself. */
preg_match( '/uc-panel-calendar\s*\{\s*flex:\s*(\d+)\s+(\d+)\s+(\d+)px/', $css, $c1m );
preg_match( '/uc-panel-sidebar\s*\{\s*flex:\s*(\d+)\s+(\d+)\s+(\d+)px/', $css, $c2m );
$c1 = isset( $c1m[3] ) ? array( $c1m[0], $c1m[3] ) : array();
$c2 = isset( $c2m[3] ) ? array( $c2m[0], $c2m[3] ) : array();
$grow_grid = isset( $c1m[1] ) ? (int) $c1m[1] : 0;
$grow_side = isset( $c2m[1] ) ? (int) $c2m[1] : 0;

$stack = ( isset( $g[1], $c1[1], $c2[1] ) ) ? ( (int) $c1[1] + (int) $c2[1] + (int) $g[1] ) : 0;
/* 864 since 3.45.0: the gap between the halves went to zero when they became
 * one container with a divider, so the wrap point is the two bases alone. */
check( $stack > 0, 'could not read the combined mode bases and gap out of the CSS' );
check( 864 === $stack, "the combined mode now stacks at {$stack}px and the readme publishes 864px" );

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
 *
 * THE RIGHT PANEL HAS THE SAME KIND OF FLOOR, and since 3.32.0 it is the
 * sidebar's: at 272px and under, its row stops putting the thumbnail beside the
 * text and stacks a 150px picture above it. Right for a phone, wrong in a column
 * beside a month grid, and the same argument as the grid's dots. 272 is measured
 * in .claude/embed-width-probe.html rather than declared in the stylesheet, so it
 * is named here as a constant with that pointer instead of being read out of a
 * rule that does not exist.
 */
preg_match( '/@container uc-calendar \(max-width: (\d+)px\) \{\s*\.uc-calendar \.uc-month-grid td/', $css, $d );
$dots = isset( $d[1] ) ? (int) $d[1] : 0;
check( $dots > 0, 'could not find the breakpoint where the month grid collapses to dots' );

$rowstack   = 272; // Measured, not declared. See embed-width-probe.html.
$basis_grid = isset( $c1[1] ) ? (int) $c1[1] : 0;
$basis_side = isset( $c2[1] ) ? (int) $c2[1] : 0;

$bad = array();
$bad_side = array();
for ( $w = 300; $w <= 1400; $w++ ) {
    if ( $w < $stack ) {
        continue; // Stacked. The grid gets the whole width, which is the grid mode.
    }
    if ( $basis_grid + ( ( $w - $stack ) / 2 ) <= $dots ) {
        $bad[] = $w;
    }
    if ( $basis_side + ( ( $w - $stack ) / 2 ) <= $rowstack ) {
        $bad_side[] = $w;
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
check(
    empty( $bad_side ),
    sprintf(
        'side by side, the sidebar panel gets %dpx or less at %d width(s) from %dpx up, at or under the %dpx where its row breaks onto two lines',
        $rowstack,
        count( $bad_side ),
        empty( $bad_side ) ? 0 : $bad_side[0],
        $rowstack
    )
);

/*
 * THE SURPLUS GOES TO THE GRID, AND IT IS SAID WITH GROW FACTORS NOW (3.81.0).
 *
 * IT USED TO BE SAID WITH A max-width, and the two caps had to match. The intent
 * was always "the sidebar sits at its basis and every spare pixel goes to the
 * grid, which has seven columns to spend it on". A cap is an imprecise way to
 * say that and it is UNCONDITIONAL, so when the panel wrapped onto a line of its
 * own it stayed 380px wide inside a 770px card with 390px of blank beside it.
 * That is sfaf.org's shape, and it was reported twice as the card not enclosing
 * its contents.
 *
 * Grow factors say it exactly and say it once: sharing a line the grid takes all
 * but a thousandth of the free space; alone on a wrapped line the sidebar has
 * nothing to share with and fills it. So what is asserted now is the ratio, and
 * that neither panel carries a cap that would defeat the wrapped case.
 */
check(
    $grow_grid > 0 && $grow_side > 0 && $grow_grid >= 100 * $grow_side,
    sprintf(
        'the grid grows at %d and the sidebar at %d; the grid has to out-grow it by at least a hundred to one, or the surplus is shared instead of going to the columns that can use it',
        $grow_grid,
        $grow_side
    )
);
preg_match( '/uc-panel-sidebar\s*\{[^}]*max-width:\s*(\d+)px/', $css, $pcap );
check(
    empty( $pcap ),
    'the sidebar panel has a max-width again. It is unconditional, so it also applies on the line the panel wraps onto, which is where it leaves a ragged gap down the card'
);
check(
    (bool) preg_match( '/uc-view-panels-combined\s+\.uc-sidebar\s*\{[^}]*max-width:\s*none/', $css ),
    'the sidebar inside the combined mode does not clear its own 380px cap, so a full-width panel would hold a 380px column and the gap moves one element in'
);
check(
    (bool) preg_match( '/^\.uc-sidebar \{[^}]*max-width:\s*(\d+)px/m', $css ),
    'the standalone sidebar block lost its own max-width; that cap is what the sidebar mode is designed around and only the combined mode clears it'
);

/*
 * 770px BY NAME. It is the width the theme gives this block on sfaf.org, Mark
 * cannot widen it, and the mode has to work there. It stacks there, and has done
 * since 3.31.1; the changeover has moved twice since without ever getting near it.
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
    (bool) preg_match( '/uc-panel-sidebar\s*\{[^}]*min-width:\s*0/', $css ),
    'the sidebar panel has no min-width: 0'
);

/*
 * NOTHING IN THIS MODE CONSTRAINS A HEIGHT OR SCROLLS.
 *
 * 3.30.0 capped the panel's list at 640px with overflow-y: auto. `.uc-event-list`
 * is a column flex container, so a definite max-height takes its negative free
 * space out of the items, and `.uc-event-card` is overflow: hidden, which removes
 * the automatic minimum size that would otherwise floor them at their content.
 * Every card in the panel became a 40px strip with its title, image, meta and
 * button clipped out of it. Three properties, each defensible, and the failure
 * needs all three.
 *
 * ASKED OF THE WHOLE MODE RATHER THAN OF THE RULE THAT WAS REMOVED. The list
 * panel does not exist here any more, so a check naming .uc-panel-list would pass
 * for the wrong reason forever. This sweeps every rule whose selector mentions
 * the combined wrapper.
 *
 * flex-shrink: 0 on the list's items stays asserted because the LIST mode still
 * has the same flex column with the same overflow: hidden cards in it, and would
 * fail the same way if anything ever put a height on it.
 *
 * WHAT THIS CANNOT SEE is whether the panel then RENDERS like the sidebar, and
 * this is the pass that has been fooled three times.
 * .claude/combined-panel-parity.php renders the renderer's own sidebar in both
 * places in a browser, at 770px stacked and 1000px side by side, and compares
 * every element. Run that too.
 */
/*
 * SWEPT WITH THE COMMENTS OFF, for the reason the embed.js sweep is: the note
 * above these rules QUOTES the rule that was removed, so a sweep of the raw
 * stylesheet reports the fault it just fixed. Third time a checker here has read
 * its own explanation as evidence.
 */
$css_code = preg_replace( '#/\*.*?\*/#s', '', $css );

preg_match_all( '/([^{}]*uc-view-panels-combined[^{}]*)\{([^}]*)\}/', $css_code, $rules, PREG_SET_ORDER );
$constrained = array();
foreach ( $rules as $rule ) {
    if ( preg_match( '/(max-height|overflow)\s*:/', $rule[2] ) ) {
        $constrained[] = trim( preg_replace( '/\s+/', ' ', $rule[1] ) );
    }
}
check(
    empty( $constrained ),
    sprintf(
        'the combined mode constrains height or scrolls again, in %d rule(s): %s. A height on a column flex container squashes its overflow:hidden items to nothing rather than scrolling',
        count( $constrained ),
        implode( ' / ', $constrained )
    )
);
check(
    count( $rules ) >= 3,
    sprintf( 'only %d rule(s) name the combined wrapper, so the sweep above is passing because it found nothing to look at', count( $rules ) )
);
/* -------------------------------------------------------------------------
 * THE LIST PANEL IS SIZED INSIDE THE COMBINED WRAPPER (3.83.0), AND THIS IS THE
 * REVERSE OF THE CHECK THAT WAS HERE.
 *
 * What it used to forbid was any rule naming uc-panel-list, on the reasoning
 * that the combined mode was the only place that class ever sat beside a grid
 * and the list panel had been taken out of it. 3.82.0 put one back, hidden, so
 * the toggle would have somewhere to go, and nothing gave it a size.
 *
 * MEASURED AT 700px, in .claude/list-view-stacked.php:
 *
 *     uc-panel-sidebar   shown   698x964  left  21
 *     uc-panel-list      shown     0x964  left 719   flex 0 1 auto  min-width auto
 *     a card              26x268
 *
 * A zero-width panel with its cards overflowing at their 26px min-content
 * width, which is the "one letter per line" that was reported.
 *
 * So what is asserted now is that it HAS a full-row basis and a min-width floor,
 * and that the rule does not depend on the script having removed the wrapper
 * class first: the block is served to another site, where the script can be old,
 * cached or blocked.
 * ---------------------------------------------------------------------- */
check(
    (bool) preg_match( '/uc-view-panels-combined\s*>\s*\.uc-panel-list\s*\{[^}]*flex:\s*1\s+1\s+100%/', $css_code ),
    'the list panel has no full-row flex basis inside the combined wrapper, so it collapses beside the sidebar to the width of one character'
);
check(
    (bool) preg_match( '/\.uc-calendar \.uc-event-list > \*\s*\{[^}]*flex-shrink:\s*0/', $css_code ),
    'the list items no longer declare flex-shrink: 0, so any height constraint above them squashes the cards to strips instead of overflowing where it can be seen'
);
check(
    file_exists( __DIR__ . '/combined-panel-parity.php' ),
    'the panel parity generator is gone; nothing then compares the rendered right panel against the sidebar mode'
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
/*
 * THE EDGE IS NEUTRAL AS OF 3.49.0, and this assertion used to say the
 * opposite: it required a 2px ring in --cat-ink. That was right about which
 * colour to use if a colour was used at all, and 3.49.0 decided none should be,
 * because nothing on the grid tells a visitor what the hue means. The check is
 * kept rather than deleted, pointed the other way, so the reversal is enforced
 * in the same place the old rule was. The weight and the ban on category colour
 * are measured across BOTH thumbnails in .claude/category-ring-contrast.php.
 */
check(
    (bool) preg_match( '/\.uc-calendar \.uc-de-thumb \{[^}]*box-shadow:\s*0 0 0 1px var\(--uc-border/', $css ),
    'the day event thumbnail has no neutral hairline; it is a 1px var(--uc-border) edge'
);
check(
    ! preg_match( '/\.uc-calendar \.uc-de-thumb \{[^}]*box-shadow:[^;]*cat-ink/', $css ),
    'the category ring is back on the day event thumbnail; it was removed deliberately in 3.49.0'
);
check(
    ! preg_match( '/\.uc-calendar \.uc-de-thumb \{[^}]*var\(--cat-color/', $css ),
    'the ring uses the raw category colour, six of which fall under 3:1 on white'
);
/*
 * ONE LINE, CUT, NOT TWO LINES WRAPPED (3.89.0). These two asserted the
 * opposite and are reversed deliberately rather than deleted.
 *
 * WHY THE OLD RULE WAS RIGHT AND IS NOT ANY MORE. Two lines was the right
 * answer for a card carrying a 32px picture: the row was already 42px, so a
 * second line of text cost little. With the card gone the row is 21px and a
 * title wrapping to three lines is the thing that makes a busy day enormous,
 * which is the whole reason this changed. Uniform row height is what makes nine
 * of them scannable.
 *
 * NOTHING IS LOST TO THE CUT, and that is what makes it allowed: the full title
 * is on the hover preview via data-uc-pv-title on the same link, on the event
 * page, and in the accessibility tree, which reads the text not the box.
 */
/*
 * REVERSED AGAIN IN 3.91.0, AND THIS TIME IT IS THE ANSWER.
 *
 * 3.89.0 cut the title to one line with an ellipsis and these asserted it.
 * That was wrong about the thing that matters: a column of ellipses tells
 * nobody what anything is, hover does not help somebody scanning, and hover
 * does not exist on a phone. The title wraps to as many lines as it needs and
 * the height is the accepted cost.
 *
 * SO THE CLAIM IS NOW THE OPPOSITE, and it is the stronger one: nothing in the
 * tile may cut the title, in any context. The three checks below are what a
 * later "tidy up the busy days" change would have to argue with.
 */
check(
    ! preg_match( '/\.uc-day-event-title \{[^}]*white-space:\s*nowrap/s', $css ),
    'the day event title is truncated to one line again; the whole title must show'
);
check(
    ! preg_match( '/\.uc-day-event-title \{[^}]*text-overflow:\s*ellipsis/s', $css ),
    'the day event title is being cut with an ellipsis again'
);
check(
    ! preg_match( '/\.uc-day-event-title \{[^}]*-webkit-line-clamp/s', $css ),
    'the day event title is clamped to a line count again'
);
/* AND THE TIME IS OFF THE TITLE'S LINE, because pinned beside it the time takes
 * width from the title and forces wrapping the title did not need. */
check(
    (bool) preg_match( '/\.uc-day-event-time \{[^}]*display:\s*block/s', $css ),
    'the time is back on the title\'s line, taking width the title needs'
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
/*
 * A DOT, NOT A THUMBNAIL (3.89.0), AND THIS ASSERTION REVERSED WITH IT.
 *
 * The tile was a bordered card with a 32px picture, a title clamped to two
 * lines and a time on its own line, about 42px each. Nine on one Wednesday made
 * a row that pushed the rest of the month off screen. It is one 21px line now:
 * a category dot, the title, the time.
 *
 * THE CLAIM IS STRONGER THAN THE ONE IT REPLACES, not weaker. The old check
 * asked only that a helper was called. These ask that the dot is drawn AND that
 * the category is not left to colour alone, which is the rule that governs the
 * whole change and the one somebody removing "a redundant hidden span" would
 * break without noticing.
 */
check(
    false !== strpos( $code, 'sfaf_day_event_dot( $id )' ),
    'the day event does not render its category dot'
);
$tpl = file_get_contents( dirname( __DIR__ ) . '/includes/sfaf-template-functions.php' );
check(
    (bool) preg_match( '/function sfaf_day_event_dot\(.*?uc-visually-hidden/s', $tpl ),
    'the category dot is the only carrier of the category; the name must be there as text too'
);
check(
    false === strpos( $code, 'sfaf_day_event_thumb( $id )' ),
    'the 32px thumbnail is back in the day tile, which is what made a busy day push the month off screen'
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
printf( "combined: both panels built and shown, no view toggle, grid before the sidebar in the DOM,\n" );
printf( "          intrinsic flex rather than a container query, stacks at %dpx, min-width: 0 on both,\n", $stack );
echo "          and each half is its own query container so it measures its own column\n";
printf( "width:    side by side never gives the grid under %dpx, checked at every width from 300\n", $dots );
printf( "          to 1400; at %dpx it stacks, so the grid gets all %dpx and keeps its entries\n", $sfaf, $sfaf );
echo "          (the panels being VISIBLE is asserted in embed-combined-panels-test.js; run it too)\n";
printf( "right:    the sidebar renderer, one count helper for both callers, no card list built, no\n" );
printf( "          pagination, no height cap and no scroll region in any rule naming the wrapper;\n" );
printf( "          panel capped at %spx where the sidebar itself caps, and its %dpx basis clears the\n",
    isset( $pcap[1] ) ? $pcap[1] : '?', $basis_side );
printf( "          %dpx where the row breaks in two\n", $rowstack );
echo "          (whether it RENDERS like the sidebar is combined-panel-parity.php; run that too)\n";
echo "grid card: no accent bar, a 32px thumbnail on a neutral 1px hairline, the title clamped to two\n";
echo "          lines, the thumbnail dropped below 930px, and nothing capping how many show\n";
printf( "geometry: cap %dpx to %dpx. Column %.1fpx to %.1fpx outer, cell content %.1fpx to %.1fpx,\n",
    $cap_old, $cap_new, $col_o, $col_n, $cell_o, $cell_n );
printf( "          title %.1fpx to %.1fpx once the 32px thumbnail and its %dpx gap are taken\n", $title_o, $title_n, $thumbgap );
echo "carried:  the generator control, the block attribute, embed.js, the endpoint parameter\n";
echo "          and the cache identity\n\n";

/* =========================================================================
 * WHICH VIEW A BLOCK OPENS ON, SAID IN FOUR PLACES THAT HAVE TO AGREE.
 *
 * A block with no view of its own is drawn by whichever route reached it, and
 * there are four: the shortcode's attributes, the shortcode's render
 * arguments, the REST route's parameter, and embed.js's own fallback. One left
 * behind on a change would make the same block open as a grid on the page and
 * as a list in the feed, which is the shape of fault that gets reported as
 * "the calendar looks different on the other site".
 *
 * THE VALUE IS READ, NOT ASSERTED. This does not care whether the default is
 * the grid or the list; it cares that the four say the same thing. So changing
 * the default deliberately is one edit in four places and this passes, and
 * changing it in three does not.
 * ====================================================================== */
$defaults = array();

preg_match_all( "/'view'\s*=>\s*'([a-z]+)'/", $src, $sc );
foreach ( $sc[1] as $i => $v ) {
    /* 'sidebar' in this file is a renderer's own return value rather than a
     * default, and it is the only one that is never a default. */
    if ( 'sidebar' !== $v ) {
        $defaults[ 'shortcode #' . ( $i + 1 ) ] = $v;
    }
}
if ( preg_match( "/'view'\s*=>\s*array\([^)]*'default'\s*=>\s*'([a-z]+)'/", $embed, $m ) ) {
    $defaults['REST parameter'] = $m[1];
}
if ( preg_match( "/getAttribute\('data-view'\)\s*\|\|\s*'([a-z]+)'/", $ejs, $m ) ) {
    $defaults['embed.js'] = $m[1];
}

check( count( $defaults ) >= 4,
    'only ' . count( $defaults ) . ' view default(s) could be read; this check cannot see what it is meant to compare' );

$agreed = array_unique( array_values( $defaults ) );
if ( count( $agreed ) > 1 ) {
    $said = array();
    foreach ( $defaults as $where => $v ) { $said[] = $where . '=' . $v; }
    $fails[] = 'the view defaults disagree, so a block with no view of its own opens differently '
        . 'depending on which route drew it: ' . implode( ', ', $said );
}
printf( "default:  %d places agree that a block with no view of its own opens on '%s'\n",
    count( $defaults ), reset( $agreed ) );
echo "          (a visitor's remembered choice still wins over it; see viewFor())\n\n";


/* ===========================================================================
 * THE LIST VIEW IS A TABLE OF ROWS (3.91.0).
 *
 * It REPLACED the card: no large image, no excerpt, no footer button, and no
 * second list layout kept behind an option. These assertions are what a later
 * "bring the description back" change would have to argue with, because the
 * description column is the one thing that would undo the density.
 *
 * ONE RENDERER SERVES BOTH PATHS, which is why this file is its home: the
 * shortcode and the embed both compose render_event_card() and both use this
 * stylesheet, so a row proved here is a row on both.
 * ======================================================================== */
check(
    false !== strpos( $code, 'uc-event-card uc-lrow' ),
    'the list view is not rendering rows'
);
check(
    false === strpos( $code, 'uc-card-excerpt uc-lc-summary' ),
    'the excerpt is back in the list row; a paragraph per row is what makes rows tall'
);
check(
    ! preg_match( '/uc-lc-foot/', $code ),
    'the card footer and its button are back in the list row'
);
check(
    ! preg_match( '/uc-lrow[^>]*>.*?sfaf_share|uc-lrow-share/s', $code ),
    'social icons are on the row; an event is shared from its own page'
);
/* FOUR COLUMNS, AND ONLY THE TITLE FLEXES, so rows cannot disagree about where
 * a column starts, which is the whole point of a table. */
if ( ! preg_match( '/\.uc-calendar \.uc-lrow \{([^}]*)\}/s', $css, $lrow ) ) {
    $fails[] = 'the list row has no grid rule at all';
} else {
    check(
        (bool) preg_match( '/grid-template-columns:\s*96px minmax\(0, 1fr\) 170px 200px/', $lrow[1] ),
        'the list row is no longer four columns with only the title flexing'
    );
    /* THE CARD CHROME STAYS OFF. `.uc-event-card` still gives this element a
     * border, a radius and a lift shadow, and the class has to stay because
     * both scripts select on it. Nine bordered boxes is what this replaced. */
    foreach ( array( 'border: 0', 'border-radius: 0', 'box-shadow: none' ) as $off ) {
        check(
            false !== strpos( $lrow[1], $off ),
            'the list row does not turn off the card chrome (' . $off . '), so it reads as a stack of boxes'
        );
    }
}
/* A PHONE GETS TWO COLUMNS, NOT A SIDEWAYS SCROLL, and it is decided on the
 * CONTAINER because this block is embedded in a column it does not control. */
check(
    (bool) preg_match( '/@container uc-calendar \(max-width: 640px\)/', $css ),
    'there is no container breakpoint for a narrow column, so the row cannot restack'
);
check(
    (bool) preg_match( '/@container uc-calendar \(max-width: 860px\)/', $css ),
    'the middle step is gone; at 700px the fixed date and venue tracks squeeze the title'
);

/* ===========================================================================
 * THE MONTH TILE SHOWS THE WHOLE TITLE (3.91.0), and the text is a column
 * beside the dot so the time can sit under the title rather than beside it.
 * ======================================================================== */
/* QUOTED EXACTLY. `uc-day-event-textX` contains `uc-day-event-text`, so a
 * substring check passed a planted rename. Third time this trap has been met
 * across these files. */
check(
    false !== strpos( $code, 'class="uc-day-event-text"' ),
    'the tile has no text column, so the time cannot sit under the title'
);
check(
    false !== strpos( $code, 'sfaf_day_event_dot( $id )' ),
    'the tile no longer renders its category dot'
);

/* ===========================================================================
 * THE DROPDOWN IS SEPARATED, NOT LIGHTENED (3.91.0).
 *
 * The complaint was "a big blob of text and boxes", and the previous one was
 * the opposite: thick and boxy. Measuring first found the heading's margin was
 * being eaten by `.uc-calendar p { margin: 0 }` at (0,1,1), so the headings sat
 * 0px above the first row and had done since the panel was built. These pin
 * the four things that separate it, and the specificity that makes the first
 * one actually apply.
 * ======================================================================== */
check(
    (bool) preg_match( '/\.uc-who-panel \.uc-who-heading \{/', $css ),
    'the who heading is back to one class and loses its margin to the paragraph reset again'
);
check(
    (bool) preg_match( '/\.uc-who-panel \.uc-who-heading \{[^}]*border-bottom:\s*1px/s', $css ),
    'the who headings no longer rule off their section'
);
check(
    (bool) preg_match( '/\.uc-who-opt \{[^}]*border-bottom:\s*1px/s', $css ),
    'the who rows have no separator, so thirty-four names read as one mass'
);
check(
    (bool) preg_match( '/\.uc-who-col \+ \.uc-who-col \{[^}]*border-left:\s*1px/s', $css ),
    'there is no divider between the two columns of the who panel'
);
check(
    (bool) preg_match( '/\.uc-who-list-2col \{[^}]*column-rule:\s*1px solid/s', $css ),
    'the group sub-columns have no rule between them'
);
/* AND THE WEIGHTS WERE NOT TOUCHED, which is the point: the problem was
 * separation. Lightening further was the wrong direction and is asserted
 * against here rather than left to memory. */
check(
    (bool) preg_match( '/\.uc-who-panel \.uc-who-heading \{[^}]*font-weight:\s*600/s', $css ),
    'the who heading weight moved; the reported problem was separation, not weight'
);
if ( $fails ) {
    echo 'FAIL: ' . count( $fails ) . "\n";
    foreach ( array_unique( $fails ) as $f ) { echo '  . ' . $f . "\n"; }
    exit( 1 );
}
echo "the combined mode composes both renderers, source linking reaches every one of them,\n";
echo "and the grid card's thumbnail is edged in neutral, carrying no colour it cannot explain.\n";
exit( 0 );
