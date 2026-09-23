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
/* THE SECTION MARK, WHICH IS A BAND FROM 3.93.0 AND WAS A RULE BEFORE IT.
 * This check used to name the rule. The rule was replaced rather than removed,
 * and what it was protecting is still protected: a section has to start
 * visibly. So this one stays as the CONTRACT rather than the implementation,
 * and the band is asserted on its own terms further down, with the contrast it
 * was measured at. A check written against one of two acceptable marks is a
 * check that has to be argued with every time the mark changes. */
check(
    (bool) preg_match( '/\.uc-who-panel \.uc-who-heading \{[^}]*(border-bottom:\s*1px|background:\s*var\()/s', $css ),
    'the who headings no longer mark off their section at all, by a rule or by a band'
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

/* ===========================================================================
 * THE COUNTS, THE HEADING COLOUR AND THE ROW PADDING (3.92.0).
 *
 * CSS COMMENTS ARE STRIPPED BEFORE ANY OF THIS. The rules below are written
 * out in prose a few lines above the declarations they describe, so a check
 * against the raw stylesheet would pass on the explanation of a rule somebody
 * had just deleted. That trap has been met three times in the PHP checks and
 * this is the first CSS check close enough to a comment to meet it.
 * ======================================================================== */
$cssc = preg_replace( '#/\*.*?\*/#s', '', $css );

/* --- A. THE COUNTS COST TWO QUERIES, NOT THIRTY-FOUR. --------------------
 *
 * The thing worth pinning is not that a number appears; it is where the
 * number comes from. `who_counts()` runs one id query and one term query and
 * tallies in PHP. A future edit that counts inside the render loop would look
 * correct on screen and cost a query per name. */
check(
    false !== strpos( $code, '$this->who_counts( $organizers, $who_groups, $filters )' ),
    'the who picker no longer asks who_counts() for its numbers'
);
/* THE FUNCTION IS SLICED OUT BEFORE ANY OF THIS IS ASKED, and that is not
 * tidiness. `/function who_counts\(.*?build_query_args\(/s` matches right past
 * the end of the function to the next call anywhere in the file, so it stayed
 * green on a plant that gutted who_counts() entirely. A planted fault found
 * that, which is what planting is for. */
$who_fn = '';
if ( preg_match( '/private function who_counts\(.*?\n    \}\n/s', $code, $m ) ) {
    $who_fn = $m[0];
}
check(
    '' !== $who_fn,
    'who_counts() is gone, so nothing computes the numbers in two queries'
);
check(
    (bool) preg_match( '/\$args\[.fields.\]\s*=\s*.ids./', $who_fn ),
    'who_counts() no longer fetches ids only, so it is loading whole posts to count them'
);
check(
    false !== strpos( $who_fn, 'wp_get_object_terms(' ),
    'who_counts() no longer resolves every term in one pass, which is the only reason it is two queries'
);
/* AND THE COUNTS ARE THE SAME QUERY AS THE LIST. Reusing build_query_args()
 * is what makes "upcoming only" true without stating it twice: a count that
 * built its own args would drift from the list the first time either moved. */
check(
    false !== strpos( $who_fn, '$this->build_query_args(' ),
    'who_counts() builds its own query args, so the counts can drift from the list they describe'
);
/* AN ORGANIZER COUNT IGNORES THE ORGANIZER TICKS. Organizer is the
 * controlling filter here: leaving it applied would make every unticked
 * organizer read (0) the moment one was ticked. */
check(
    (bool) preg_match( "/\\\$base\['organizer'\]\s*=\s*''/", $who_fn ),
    'who_counts() leaves the organizer filter applied to its own counts, so ticking one zeroes the rest'
);
/* QUOTED EXACTLY, for the same reason as `uc-day-event-text` above. */
check(
    2 === substr_count( $code, 'class="uc-who-count"' ),
    'the count is missing from one of the two lists, so organizers and groups no longer answer the same question'
);
check(
    false !== strpos( $code, 'if ( 0 === $n && ! $on ) { continue; }' ),
    'the organizer list shows its zero rows again; the panel hides empty rows and that is one answer, not two'
);
/* AND THE GROUPS HIDE THEIR ZEROS TOO, WITH THE ONE CASE PROJECT.md ALREADY
 * SETTLED HELD OUT OF IT. A group whose events name no organizer is always
 * shown; its count is computed inside the ticked organizers, so it reads (0)
 * the moment one is ticked and a plain zero rule would then hide it, which is
 * the narrowing rule reversed by a side effect.  is the whole of
 * that and is exactly the kind of clause a later tidy-up removes. */
check(
    false !== strpos( $code, 'if ( 0 === $n && ! $on && ! $protected ) { continue; }' ),
    'the group list hides a zero unconditionally, which hides the group with no organizer that the narrowing rule keeps'
);
check(
    false !== strpos( $code, '$protected = empty( $orgs_of ) && $ever > 0;' ),
    'the protected case is no longer a group with no organizer and something upcoming, so it is protecting something else'
);
/* A TICKED ROW SURVIVES ITS OWN ZERO, or the filter it represents cannot be
 * turned off. The `! $on` is the whole of that and is easy to tidy away. */
check(
    false === strpos( $code, 'if ( 0 === $n ) { continue; }' ),
    'a ticked row is now hidden by its own zero, which makes that filter unreachable'
);
check(
    (bool) preg_match( '/_n\(\s*.%d upcoming event.,\s*.%d upcoming events.,\s*\$n\s*\)/', $code ),
    'the count has no text alternative, so a screen reader hears "(12)" with nothing to attach it to'
);

/* --- B. THE HEADINGS CARRY PALETTE COLOUR, AND ONLY THE HEADINGS. -------- */
check(
    (bool) preg_match( '/\.uc-who-panel \.uc-who-heading \{[^}]*color:\s*var\(--uc-teal-text\)/s', $cssc ),
    'the who headings are back to grey, or have taken a colour that is not the palette token'
);
check(
    ! preg_match( '/\.uc-who-panel \.uc-who-heading \{[^}]*color:\s*#/s', $cssc ),
    'the heading colour is written as a literal; DESIGN.md owns the palette and a hex here escapes it'
);
/* COLOUR ON TWO HEADINGS IS SEPARATION, COLOUR ON THIRTY-FOUR ROWS IS NOISE.
 * The rows and the counts stay ink and secondary grey. */
check(
    ! preg_match( '/\n\.uc-who-opt \{[^}]*color:\s*var\(--uc-teal/s', $cssc ),
    'the who rows have taken the heading colour; thirty-four teal names is not separation'
);
check(
    (bool) preg_match( '/\.uc-who-count \{[^}]*color:\s*var\(--uc-secondary\)/s', $cssc ),
    'the count is no longer secondary grey, so it competes with the name it belongs to'
);

/* --- C. THE ROWS ARE TIGHTER AND STILL HITTABLE. ------------------------- */
/* THE NUMBER IS CAPTURED AND CHECKED, NOT MATCHED. A check for the literal
 * `5px` passes on 5px and fails on 6px, which is not the rule. The rule is a
 * floor: one line of 14px text at 1.4 is 19.6px, so 4px a side is the least
 * that still clears the 24px WCAG 2.5.8 target, and 8px is where this came
 * from and is no longer "less padding". */
if ( preg_match( '/\n\.uc-who-opt \{[^}]*padding:\s*(\d+)px\s+(\d+)px/s', $cssc, $m ) ) {
    check(
        (int) $m[1] >= 4 && (int) $m[1] < 8,
        sprintf( 'the who row padding is %dpx, which is either back where it was or below the 24px tap target', (int) $m[1] )
    );
    check(
        (int) $m[2] >= 8,
        sprintf( 'the who row side padding is %dpx; the name needs the width but the row still needs an edge', (int) $m[2] )
    );
} else {
    check( false, 'the who row has no padding declaration at all' );
}

/* --- D. THE SCROLL STAYS, AND THE CAP CLEARS THE CONTENT. ---------------- */
/* MEASURED, NOT ASSUMED: nine organizers and twenty-five groups want 460px at
 * the embed width once the padding came down. A cap at or below that draws a
 * scrollbar, the scrollbar takes 15px off every row, and three more names
 * wrap, which makes the panel taller. Removing the overflow instead would put
 * the last groups below the bottom edge on a short window with no way to
 * reach them. */
check(
    (bool) preg_match( '/\.uc-who-panel \{[^}]*overflow-y:\s*auto/s', $cssc ),
    'the who panel lost its overflow; on a 630px viewport 70vh is 441px and the last groups are then unreachable'
);
if ( preg_match( '/\.uc-who-panel \{[^}]*max-height:\s*min\(\s*70vh\s*,\s*(\d+)px/s', $cssc, $m ) ) {
    check(
        (int) $m[1] >= 500,
        sprintf( 'the who panel caps at %dpx, at or under the 460px the content wants, so the scrollbar fires and narrows the rows', (int) $m[1] )
    );
} else {
    check( false, 'the who panel no longer caps its height against the viewport' );
}


/* The other sources these checks read, comment-stripped for the reason the
 * CSS is: every rule here is explained in prose a few lines above itself. */
$strip_php = function ( $path ) {
    $src = (string) file_get_contents( $path );
    $out = '';
    foreach ( token_get_all( $src ) as $t ) {
        if ( is_array( $t ) ) {
            if ( T_COMMENT === $t[0] || T_DOC_COMMENT === $t[0] ) { continue; }
            $out .= $t[1];
        } else {
            $out .= $t;
        }
    }
    return $out;
};
$code_tf      = $strip_php( $root . '/includes/sfaf-template-functions.php' );
$code_submit  = $strip_php( $root . '/includes/class-sfaf-submit.php' );
$code_request = $strip_php( $root . '/includes/class-sfaf-request.php' );
$code_portal  = $strip_php( $root . '/includes/class-sfaf-portal.php' );
$code_uploads = $strip_php( $root . '/includes/class-sfaf-uploads.php' );

/*
 * SLICE THE THING BEFORE ASKING ANYTHING OF IT.
 *
 * THIS IS THE SECOND RELEASE RUNNING THAT PLANTED FAULTS CAUGHT THE SAME BUG
 * IN THIS FILE. `/function foo\(.*?bar\(/s` does not stop at the end of foo();
 * it runs on to the next bar() anywhere in the file, so four checks written
 * that way stayed green on plants that gutted exactly what they named. 3.92.0
 * fixed one instance by hand. These two helpers are so there is no reason to
 * write the unbounded form again.
 *
 * A top-level function ends at a `}` in column 0. A class method ends at one
 * indented four. A switch case ends at its `break;`. None of those is clever,
 * and all three are a great deal better than a wildcard crossing a file.
 */
$slice_fn = function ( $src, $name, $indent = '' ) {
    $pat = '/(?:private |public |protected |static )*function ' . preg_quote( $name, '/' )
         . '\(.*?\n' . preg_quote( $indent, '/' ) . '\}\n/s';
    return preg_match( $pat, $src, $m ) ? $m[0] : '';
};
$slice_case = function ( $src, $case ) {
    return preg_match( "/case '" . preg_quote( $case, '/' ) . "':.*?\n\s*break;/s", $src, $m ) ? $m[0] : '';
};

/* ===========================================================================
 * THE DROPDOWN AGAIN: NO MID-WORD BREAK, A BAND, NO REORDER, CLEAR OUT OF
 * THE FOOTER (3.93.0).
 * ======================================================================== */

/* --- B. A NAME NEVER BREAKS INSIDE A WORD. ------------------------------- */
/* These four properties are INHERITED and this panel renders inside somebody
 * else's page, so not declaring them is not a neutral choice: it is taking the
 * host's. The measurement said the column is wide enough for the longest real
 * name at every width, so a break can only have come from outside. */
check(
    (bool) preg_match( '/\.uc-who-opt[^{]*\{[^}]*overflow-wrap:\s*normal/s', $cssc ),
    'the who rows no longer declare overflow-wrap, so a host page that sets break-word again splits a name mid-word'
);
check(
    (bool) preg_match( '/\.uc-who-opt[^{]*\{[^}]*word-break:\s*normal/s', $cssc ),
    'the who rows no longer declare word-break, so a host page that sets break-all again splits a name mid-word'
);
check(
    (bool) preg_match( '/\.uc-who-opt[^{]*\{[^}]*hyphens:\s*manual/s', $cssc ),
    'the who rows no longer declare hyphens, so a host with hyphens: auto and a Spanish lang breaks the same name and adds a hyphen'
);
/* AND THE COLUMN CANNOT BE NARROWER THAN THE LONGEST NAME. A minimum width
 * with a maximum count: two sub-columns where two will hold a name, one where
 * they will not. Measured against the real terms: "Transformaciones" plus its
 * count is 145.6px, and 190px is that plus the row's own padding, box and gap.
 * `columns: 2` on its own put no floor under it at all. */
if ( preg_match( '/\.uc-who-list-2col \{[^}]*columns:\s*(\d+)px\s+2/s', $cssc, $m ) ) {
    check(
        (int) $m[1] >= 186,
        sprintf( 'the group sub-column floor is %dpx, under the 186px the longest real name and its count need', (int) $m[1] )
    );
} else {
    check( false, 'the group sub-columns have no width floor, so a narrow container can make a column smaller than a name' );
}

/* --- C. EACH HEADING SITS ON A TINTED BAND. ------------------------------ */
check(
    (bool) preg_match( '/\.uc-who-panel \.uc-who-heading \{[^}]*background:\s*var\(--uc-band-heading\)/s', $cssc ),
    'the who headings lost their band; coloured text on its own was reported as not being separation'
);
check(
    (bool) preg_match( '/--uc-band-heading:\s*#D5F3F6/', $cssc ),
    'the heading band is no longer the measured 18% teal, so the heading contrast on it is no longer the one that was checked'
);
/* THE BAND REPLACED THE RULE, IT DID NOT JOIN IT. A band and a hairline under
 * the band are two boundaries for one section. */
check(
    ! preg_match( '/\.uc-who-panel \.uc-who-heading \{[^}]*border-bottom:\s*1px/s', $cssc ),
    'the heading has a band AND a rule under it, which is two boundaries for one section'
);
/* AND THE TEXT ON IT IS STILL THE PALETTE TEAL, which is what decided how
 * strong the band may be. Changing either without the other breaks the pair. */
check(
    (bool) preg_match( '/\.uc-who-panel \.uc-who-heading \{[^}]*color:\s*var\(--uc-teal-text\)/s', $cssc ),
    'the heading colour moved off the palette token the band strength was measured against'
);

/* --- D. NOTHING REORDERS THE LIST, ON ANY OF THE THREE PATHS. ------------ */
/* THE POINT IS THAT IT IS GONE FROM ALL THREE. A sort left in any one of them
 * puts the behaviour back on that path only, which is the shortcode-versus-
 * embed split that has cost five faults. */
check(
    false === strpos( $code, '$sorter = function' ),
    'the renderer sorts ticked terms to the top again, so the server and the two scripts disagree about the order'
);
$cal_js = (string) file_get_contents( $root . '/public/js/calendar.js' );
$emb_js = (string) file_get_contents( $root . '/public/js/embed.js' );
$strip_js = function ( $js ) {
    $js = preg_replace( '#/\*.*?\*/#s', '', $js );
    return preg_replace( '#^\s*//.*$#m', '', $js );
};
$cal_code = $strip_js( $cal_js );
$emb_code = $strip_js( $emb_js );
check(
    false === strpos( $cal_code, 'function reorder' ),
    'calendar.js reorders the who list again'
);
check(
    false === strpos( $emb_code, 'function reorder' ),
    'embed.js reorders the who list again'
);
/* AND NOTHING CALLS ONE. A declaration removed while a call stayed is the
 * fault this file already met twice; a call removed while the declaration
 * stayed is the same fault the other way round and is what a half-finished
 * removal looks like. */
check(
    false === strpos( $cal_code, 'reorder(' ) && false === strpos( $emb_code, 'reorder(' ),
    'a script still calls reorder(), so the list still moves under somebody reading it'
);
/* THE ORDER IS STATED, NOT INHERITED. The comment on the removal says the list
 * is alphabetical; that claim rests on get_terms()'s default unless the query
 * says so. */
check(
    (bool) preg_match( "/'taxonomy'\s*=>\s*'uc_organizer',\s*'hide_empty'\s*=>\s*true,\s*'orderby'\s*=>\s*'name'/s", $code ),
    'the organizer list no longer states its own order, so "alphabetical" rests on somebody else\'s default'
);

/* --- E. CLEAR IS OUT OF THE FOOTER AND OUT OF FLOW. ---------------------- */
check(
    (bool) preg_match( '/\.uc-calendar \.uc-who-clear \{[^}]*position:\s*absolute/s', $cssc ),
    'Clear all is back in the flow, where it costs the list the height that decides whether the panel scrolls'
);
/* THE FOOTER IS THE NO-SCRIPT PATH AND NOTHING ELSE. An empty div still
 * carries its own padding and margin, which is the height this removed. */
check(
    (bool) preg_match( '/<noscript>\s*<div class="uc-who-foot">/s', $code ),
    'the who footer renders outside <noscript> again, so the row costs its height to everybody with script'
);
/* AND THE NO-SCRIPT SUBMIT IS STILL IN IT. Moving Clear must not have taken
 * Apply with it: without script, ticking a box does nothing until this submits. */
check(
    /* `.*?` RATHER THAN `[^>]*`, because the value attribute holds a PHP tag
     * and a character class excluding `>` stops dead at its `?>`. */
    (bool) preg_match( '/<button type="submit".*?class="uc-who-apply" data-uc-who-apply>Apply<\/button>/s', $code ),
    'the no-script Apply button is gone, so with script off the filter cannot be applied at all'
);
/* AND IT CARRIES THE CHOSEN CATEGORY (3.98.1). The bar's hidden uc_cat went
 * when the pills started carrying their own, and only the activating submit
 * button sends its own name and value, so without this an Apply with script off
 * clears a category somebody had already chosen. */
check(
    (bool) preg_match( '/<button type="submit" name="uc_cat" value="<\?php echo esc_attr\( \$active_category \); \?>"\s*\n\s*class="uc-who-apply"/s', $code ),
    'Apply no longer carries the chosen category, so with script off applying an organizer clears it'
);
/* CLEAR IS FIRST IN THE PANEL'S MARKUP. Absolute positioning moves it visually
 * and not in the tab order, so drawn at the top and written at the bottom
 * would put it after thirty-four boxes for anybody on a keyboard. */
/* THE BUTTON HAS TO EXIST BEFORE ITS POSITION MEANS ANYTHING. A planted
 * deletion passed the comparison below on its own: strpos() returns false for
 * the missing needle, PHP compares that as 0, and 0 is less than any real
 * offset. An ordering check with no presence check beside it reads a deletion
 * as a pass. */
check(
    false !== strpos( $code, 'data-uc-who-clear' ),
    'Clear all is gone from the panel, so unticking six things has to be done by hand'
);
check(
    false !== strpos( $code, 'data-uc-who-clear' )
    && strpos( $code, 'data-uc-who-clear' ) < strpos( $code, 'class="uc-who-cols"' ),
    'Clear all is written after the columns, so a keyboard reaches it after thirty-four checkboxes'
);

/* --- G. A PLACE NAME ON THE MANUAL ADDRESS PATH. ------------------------- */
/* THE NAME IS COMPOSED IN THE ONE READER, not at the four writers. Composing
 * it into the stored line at each writer is four places to get right, and a
 * stored line that already contains the name cannot be told apart from one
 * where somebody typed it into the street box. */
$fn_loc       = $slice_fn( $code_tf, 'sfaf_event_location' );
$fn_loc_short = $slice_fn( $code_tf, 'sfaf_event_location_short' );
check(
    '' !== $fn_loc && '' !== $fn_loc_short,
    'one of the two location readers is gone, so the checks below are asking nothing'
);
check(
    false !== strpos( $fn_loc, 'sfaf_event_location_name(' ),
    'sfaf_event_location() no longer composes the place name in, so the name is stored and never shown'
);
check(
    false !== strpos( $fn_loc_short, 'sfaf_event_location_name(' ),
    'the short form ignores the place name, so a queue row says "470 Castro St" where the event page says "Strut, 470 Castro St"'
);
/* BOTH FORMS ASK FOR IT. One of the two would be the same fault as every
 * shortcode-only fix in this file's history. */
check(
    false !== strpos( $code_submit, 'name="venue_name"' ),
    'the community form has no place name field'
);
check(
    false !== strpos( $code_request, 'name="venue_name"' ),
    'the staff request form has no place name field'
);
/* AND NEITHER FORM MAY MAKE A VENUE. A submitter who could add to the venue
 * list could put a wrong address on a place every later event inherits. */
check(
    false === strpos( $code_submit, 'SFAF_Venues::save(' ) && false === strpos( $code_request, 'SFAF_Venues::save(' ),
    'a public form creates venue terms, which lets a submitter write an address every later event inherits'
);
/* THE PROMOTION IS ADMIN-GATED AND READS NOTHING FROM THE FORM BUT THE ID.
 * Sliced to the case, for the reason the slicers say: an unbounded wildcard
 * from `case 'make_venue':` runs on into the rest of a 17,000 line file and
 * finds the same calls somewhere else. Both of these stayed green on plants
 * that gutted the case before they were bounded. */
$case_venue = $slice_case( $code_portal, 'make_venue' );
check(
    '' !== $case_venue,
    'the make_venue action is gone, so a typed place can no longer be promoted at all'
);
check(
    (bool) preg_match( "/case 'make_venue':\s*if \( ! \\\$this->is_admin_role\( \\\$user \) \) \{ wp_die\( 'Denied' \); \}/s", $case_venue ),
    'the make_venue action is not gated on the admin role'
);
check(
    false !== strpos( $case_venue, '$vname = sfaf_event_location_name( $event_id );' ),
    'make_venue takes the venue name from somewhere other than the event, so any name could be written against any address'
);
/* AND THE EVENT THEN POINTS AT THE VENUE AND KEEPS NO TEXT. Keeping a copy is
 * the one thing that would make the promotion pointless: a venue exists so a
 * corrected address reaches every event held there. */
check(
    false !== strpos( $case_venue, 'SFAF_Venues::set_for_event( $event_id, (int) $made );' )
    && false !== strpos( $case_venue, "delete_post_meta( \$event_id, '_uc_location' );" )
    && false !== strpos( $case_venue, "delete_post_meta( \$event_id, '_uc_location_name' );" ),
    'a promoted event keeps its own address text, so correcting the venue no longer corrects this event'
);
/* A CHOSEN VENUE CLEARS THE TYPED NAME. Without this a stale name sits in
 * front of the venue's own the moment anything reads it. The branch is sliced
 * on its own opening and closing, not searched for across the file, because
 * make_venue a few hundred lines away makes the same two calls. */
$branch_venue = '';
if ( preg_match(
    "/if \( 'venue' === \\\$mode && \\\$venue && SFAF_Venues::exists\( \\\$venue \) \) \{.*?\n            \} else \{/s",
    $code_portal,
    $bm
) ) {
    $branch_venue = $bm[0];
}
check(
    '' !== $branch_venue,
    'the editor\'s venue branch could not be found, so the check below is asking nothing'
);
check(
    false !== strpos( $branch_venue, "delete_post_meta( \$event_id, '_uc_location_name' );" ),
    'choosing a venue in the editor leaves the typed place name behind, so two names describe one place'
);

/* --- H. THE PICTURE FLOOR WARNS AND DOES NOT REFUSE. --------------------- */
check(
    (bool) preg_match( '/if \( \$w < self::MIN_WIDTH \) \{\s*\$warning =/s', $code_uploads ),
    'a picture under the floor is refused again, which refuses the whole submission with it'
);
check(
    ! preg_match( '/if \( \$w < self::MIN_WIDTH \) \{\s*return \$no\(/s', $code_uploads ),
    'the floor returns a refusal again'
);
/* THE SENTENCE IS THE ONE THAT WAS ASKED FOR, naming the real width and the
 * number it needs to be. "That image is too small" leaves somebody guessing at
 * both and the usual next move is to send the same file again. */
check(
    (bool) preg_match( "/'That image is ' \. \(int\) \\\$w \. ' pixels wide and needs to be at least '/", $code_uploads ),
    'the picture warning no longer names the width that was sent'
);
/* AND IT IS A DIFFERENT KEY FROM 'error'. Both callers treat a non-empty
 * 'error' as a refusal and delete the file. */
check(
    (bool) preg_match( "/return array\( 'id' => \\\$attachment_id, 'error' => '', 'warning' =>/", $code_uploads ),
    'the warning no longer travels back with a stored picture, so nothing can show it to the approver'
);
check(
    false !== strpos( $code_portal, 'SFAF_Submit::META_IMAGE_NOTE' ),
    'the pending row no longer shows the picture warning, so the warning is stored and never read'
);


/* The rest of what 3.94.0 touched, read the same way: comments stripped, and
 * sliced where a wildcard would run past the end of what it names. */
$code_media    = $strip_php( $root . '/includes/class-sfaf-media.php' );
$code_richtext = $strip_php( $root . '/includes/class-sfaf-rich-text.php' );
$cssp      = preg_replace( '#/\*.*?\*/#s', '', (string) file_get_contents( $root . '/public/css/portal.css' ) );
$portal_js = preg_replace( '#/\*.*?\*/#s', '', (string) file_get_contents( $root . '/public/js/portal.js' ) );
$portal_js = preg_replace( '#^\s*//.*$#m', '', $portal_js );

/* ===========================================================================
 * 3.94.0: THE COUNT INSIDE THE NAME, ONE FONT, THE EDITOR'S PICKER, THE THREE
 * ACTIONS, THE TICKED ROW, AND THE ROUNDED CORNER.
 * ======================================================================== */

/* --- A. THE COUNT IS PART OF THE NAME, NOT A COLUMN. -------------------- */
/* IT WAS A SIBLING FLEX ITEM AND A HOST RULE GROWING THE NAME PINNED IT TO
 * THE COLUMN EDGE. Inside the name there is no flex distribution to lose, and
 * it sits on the name's baseline because it is in the same line box. Both
 * faults, one change, and the check is that it is INSIDE. */
check(
    2 === preg_match_all( '/<span><\?php echo esc_html\( \$[og]->name \); \?><\?php if \( null !== \$n \) : \?> <span class="uc-who-count"/', $code ),
    'a count is back outside its name, where a host rule can push it to the column edge and the row can top-align it'
);
check(
    ! preg_match( '/\.uc-who-count \{[^}]*(margin-left|flex:)/s', $cssc ),
    'the count has flex or margin again, which only an element laid out beside the name would need'
);
/* AND THE NAME MAY NOT GROW. The belt to that markup change's braces. */
check(
    (bool) preg_match( '/\.uc-who-panel \.uc-who-opt > span \{[^}]*flex:\s*0 1 auto/s', $cssc ),
    'the name span may grow again, which is what pushed the count to the column edge in the first place'
);

/* --- B. ONE FONT IN A PASTED DESCRIPTION. ------------------------------- */
check(
    (bool) preg_match( '/\.uc-single \.uc-single-body \[style\*="font-family"\]/', $cssc ),
    'a pasted font-family is no longer overridden, so an event page is in whatever Word sent'
);
check(
    (bool) preg_match( '/font-family:\s*inherit\s*!important/', $cssc ),
    'the font override is no longer !important or no longer inherit; a style attribute beats every selector, and a named face would flatten our own headings'
);
/* NOTHING IS STRIPPED ON SAVE. The whole decision was to override, because
 * stripping cannot be undone and fixes only what is saved after it. A sanitizer
 * that started removing style attributes would make this rule pointless and the
 * loss invisible. */
check(
    false === strpos( $code_richtext, 'font-family' ),
    'the rich text sanitizer has started touching fonts; the decision was to override on display, not to strip on save'
);

/* --- C. THE EVENT EDITOR USES THE REQUEST FORM'S PICKER. ---------------- */
check(
    (bool) preg_match( '/if \( \$inline \) :.*?SFAF_Media::picker\( array\(/s', $code_portal ),
    'the event editor no longer calls the shared picker, so it is back on the media modal or on a second implementation'
);
check(
    (bool) preg_match( "/'inline'\s*=> true,\s*'inline_series' => \\\$picker_tag,/", $code_portal ),
    'the event editor does not pass its series to the picker, so the list is not narrowed to the event'
);
/* THE WAY PAST THE FILTER IS RENDERED VISIBLE AND HIDDEN BY SCRIPT, never the
 * other way round: a control rendered `hidden` and revealed by script is how
 * the last one stayed invisible for sixteen releases. */
check(
    (bool) preg_match( '/<button type="button" class="uc-btn uc-btn-sm uc-picker-show-all" data-uc-image-show-all>/', $code_media ),
    'the way past the series filter is gone from the picker'
);
check(
    ! preg_match( '/data-uc-image-show-all[^>]*\shidden/', $code_media ),
    'the show-all control is rendered hidden again, which is how the 3.74.0 one was never seen'
);
check(
    false !== strpos( $portal_js, 'initFixedSeriesImageFilter' ),
    'nothing narrows the editor picker to the event series'
);
check(
    false !== strpos( $portal_js, "run('fixedSeriesImageFilter', initFixedSeriesImageFilter);" ),
    'the fixed-series filter is declared and never started, which is a control built and unreachable'
);
/* AND IT USES THE ONE HIDING MECHANISM. A second scheme here would be the
 * search box and the series filter fighting over the same rows again. */
check(
    (bool) preg_match( "/function initFixedSeriesImageFilter\(\)[\s\S]*?setAttribute\('data-uc-off-series'/", $portal_js ),
    'the editor picker hides rows some other way than data-uc-off-series, so two things own hidden again'
);

/* --- E. THREE ACTIONS, ONE ROW, COLOURED BY CONSEQUENCE. ---------------- */
/* THE POINT IS THAT THE THREE DIFFER. Cancel and Delete were both red, which
 * is the fault under the mess: the two actions with the most different
 * consequences looked identical. */
foreach ( array(
    'uc-btn-go'      => '#15803D',
    'uc-btn-caution' => '#B45309',
    'uc-btn-stop'    => '#c0392b',
) as $cls => $fill ) {
    check(
        (bool) preg_match( '/\.uc-portal \.' . preg_quote( $cls, '/' ) . ' \{[^}]*background:\s*' . preg_quote( $fill, '/' ) . '/s', $cssp ),
        sprintf( 'the %s button is no longer %s, and that colour was measured against white text', $cls, $fill )
    );
}
/* SAVE REACHES ITS FORM BY ID, which is what lets the row sit outside the form
 * at all. Without it the row is three buttons and one of them does nothing. */
check(
    (bool) preg_match( '/<button type="submit" form="<\?php echo esc_attr\( \$form_id \); \?>" name="save_mode"/', $code_portal ),
    'Save no longer names the form it submits, so a row outside the form cannot save'
);
check(
    (bool) preg_match( '/<form method="post" id="<\?php echo esc_attr\( \$form_id \); \?>"/', $code_portal ),
    'the event form has no id for Save to reach it by'
);
/* THE CANCEL DISCLOSURE IS THE BUTTON, not a second control that opens one. */
/* THE SUMMARY IS THE BUTTON, and the check is that nothing but whitespace and
 * a stripped comment stands between the two. A separate button in the row that
 * opened a disclosure below would be the easy version and would leave two
 * things on the screen that both say cancel. */
check(
    (bool) preg_match( '/<details class="uc-cancel-inline"[^>]*>[\s\S]{0,200}?<summary class="uc-btn uc-btn-stop/', $code_portal ),
    'the cancel control is no longer a details whose summary is the button, so either it is expanded by default or there are two cancel controls'
);
check(
    (bool) preg_match( '/\.uc-cancel-inline\[open\] \{[^}]*flex:\s*1 1 100%/s', $cssp ),
    'the open cancel panel no longer takes the row width, so its options shoulder the buttons aside'
);
/* AND DELETE POSTS A FORM IT IS NOT INSIDE. */
check(
    (bool) preg_match( '/<button type="submit" form="uc-delete-event-<\?php echo \(int\) \$event_id; \?>"/', $code_portal ),
    'the Delete button no longer names its form, so it submits whatever form encloses it'
);

/* --- I. A TICKED ROW IS VISIBLE. ---------------------------------------- */
/* THE FILL IS THE WHOLE MARK NOW (3.96.0), AND THIS ASSERTION REVERSED WITH THE
 * BEHAVIOUR IT PINNED. 3.94.0 added a 3px teal edge because the fill it had
 * then, --uc-bg, measures 1.06:1 against the panel, and 3.95.1 straightened
 * that edge into a pseudo-element. Mark asked for the edge off and the row fill
 * to be the only mark, so the contract is the fill and the two things that ride
 * with it. The edge is asserted ABSENT further down, with the reason. */
check(
    (bool) preg_match( '/\.uc-who-opt:has\(input:checked\) \{[^}]*background:\s*var\(--uc-band-heading\)/s', $cssc ),
    'the ticked row lost its fill, and with the edge gone there is nothing marking it at all'
);
check(
    (bool) preg_match( '/\.uc-who-opt:has\(input:checked\) \{[^}]*font-weight:\s*600/s', $cssc ),
    'the ticked row is no longer bolder, so a 1.17:1 tint is carrying the selection alone'
);
check(
    (bool) preg_match( '/\.uc-who-opt:has\(input:checked\) \.uc-who-count \{[^}]*color:\s*var\(--uc-teal-text\)/s', $cssc ),
    'the count on a ticked row is back to secondary grey, 4.14:1 on the tint against the 4.5:1 an 11px number needs'
);
check(
    ! preg_match( '/\.uc-who-opt:has\(input:checked\) \{[^}]*background:\s*var\(--uc-bg\)/s', $cssc ),
    'the ticked row is back on --uc-bg, which is 1.06:1 against the panel and is white with a rounding error'
);
check(
    (bool) preg_match( '/\.uc-who-opt input\[type="checkbox"\] \{[^}]*accent-color:\s*var\(--uc-teal-text\)/s', $cssc ),
    'the tick itself is no longer brand ink'
);

/* --- H. "SEE ALL EVENTS" CLEARS THE ROUNDED CORNER. --------------------- */
/* MEASURED IN ALL FOUR STATES: the standalone card's 14px bottom padding
 * against its own 14px radius left 2px of clearance, and two pixels is a
 * coincidence rather than a clearance. Both previous diagnoses measured the
 * COMBINED view, where this element has no box at all. */
if ( preg_match( '/\.uc-sidebar \{[^}]*padding:\s*var\(--uc-sidebar-pad-t\) var\(--uc-sidebar-pad-x\) (\d+)px/s', $cssc, $m ) ) {
    check(
        (int) $m[1] >= 20,
        sprintf( 'the sidebar bottom padding is %dpx against a 14px corner radius; the link then sits on the curve, which has been reported three times', (int) $m[1] )
    );
} else {
    check( false, 'the standalone sidebar has no bottom padding declaration, so nothing holds the link off the corner' );
}


/* ===========================================================================
 * "USE THIS IMAGE" IS GONE AND MUST NOT COME BACK ON ITS OWN (3.95.0).
 *
 * It set a submitted attachment as the event thumbnail, and
 * sfaf_event_own_image_url() then refused it because SFAF_Media_Folder::holds()
 * is anchored on the calendar folder and a submitted file is not in it. The
 * event showed its series picture while the row said otherwise.
 *
 * THE PAIR IS WHAT IS ASSERTED, not just the absence. A button like this is
 * only correct if something first puts the file inside the folder the rule
 * names, and the decision was that submitted pictures stay outside it. So if
 * the action ever returns, the check below says what would have to be true for
 * it to work, rather than simply forbidding it.
 * ======================================================================== */
check(
    false === strpos( $code_portal, "case 'use_submitted_image'" ),
    'the use_submitted_image action is back; it can only work if something copies the file into the calendar folder first, because the folder rule refuses an attachment outside it'
);
check(
    false === strpos( $code_portal, 'Use this image' ),
    'the "Use this image" button is back on the queue'
);
/* AND NOTHING ABOUT A SUBMITTED PICTURE MAY DELETE THE TYPED URL. That was the
 * second half of the harm: pressing the button destroyed a working image URL
 * and replaced it with a thumbnail that resolved to nothing. The two that are
 * left are the editor's own image field acting on what the form posted: "Reset
 * to series image", and an image URL box somebody emptied. A third would be
 * something clearing it as a side effect again. */
$img_url_deletes = substr_count( $code_portal, "delete_post_meta( \$event_id, '_uc_image_url' )" );
check(
    2 === $img_url_deletes,
    sprintf( 'the typed image URL is deleted in %d places rather than the two the editor form owns', $img_url_deletes )
);


/* ===========================================================================
 * THE LIST ROW AT THE EMBED WIDTH, AND THE TICKED EDGE (3.95.1).
 * ======================================================================== */

/* --- 1. THE DATE BREAKS WHERE IT IS TOLD TO. ---------------------------- */
/* TWO PARTS IN THE MARKUP, EACH HELD TO ONE LINE, so the space between them is
 * the only break point. The column is 160px at the embed width and the longest
 * real date is 217.3px, so it always breaks there; a wider column draws it on
 * one line with no rule doing anything. */
check(
    false !== strpos( $code, 'class="uc-lrow-dow"' ) && false !== strpos( $code, 'class="uc-lrow-dmy"' ),
    'the list date is one string again, so it breaks wherever the column runs out and orphans the year'
);
check(
    (bool) preg_match( '/\.uc-lrow-dow,\s*\.uc-calendar \.uc-lrow-dmy \{[^}]*white-space:\s*nowrap/s', $cssc ),
    'the two halves of the date can break inside themselves again, which is the fault this replaced'
);
/* AND THE PARTS COMPOSE TO WHAT 'full' RETURNS. Two spellings of one date is
 * how one surface ends up disagreeing with another about what day it is. The
 * formatter is loaded and run rather than read, because a format string that
 * looks right and is not is the whole reason the formatter exists. */
if ( ! function_exists( 'date_i18n' ) ) {
    function date_i18n( $fmt, $ts = null ) { return date( $fmt, null === $ts ? time() : (int) $ts ); }
}
$fmts = array();
if ( preg_match( "/\\\$formats = array\(\s*(.*?)\n    \);/s", $code_tf, $fm ) ) {
    if ( preg_match_all( "/'([a-z_]+)'\s*=>\s*'([^']*)'/", $fm[1], $rows, PREG_SET_ORDER ) ) {
        foreach ( $rows as $r ) { $fmts[ $r[1] ] = $r[2]; }
    }
}
check(
    isset( $fmts['full'], $fmts['weekday_comma'], $fmts['day_year'] ),
    'one of the three date styles the list composes from is gone from the formatter'
);
if ( isset( $fmts['full'], $fmts['weekday_comma'], $fmts['day_year'] ) ) {
    $drift = array();
    /* Every weekday and a month with a long name, so a format that is right on
     * a Monday in May and wrong on a Wednesday in September is caught. */
    for ( $d = 0; $d < 14; $d++ ) {
        $t = mktime( 18, 0, 0, 9, 14 + $d, 2026 );
        $whole    = date( $fmts['full'], $t );
        $composed = date( $fmts['weekday_comma'], $t ) . ' ' . date( $fmts['day_year'], $t );
        if ( $whole !== $composed ) { $drift[] = $whole . ' vs ' . $composed; }
    }
    check(
        empty( $drift ),
        'the two date halves no longer compose to the whole one: ' . implode( '; ', array_slice( $drift, 0, 2 ) )
    );
}

/* --- 2. THE CHIP AND THE ORGANIZER ARE ONE LINE. ------------------------ */
check(
    (bool) preg_match( '/\.uc-calendar \.uc-lrow-meta \{[^}]*flex-wrap:\s*nowrap/s', $cssc ),
    'the meta line wraps again, so a long organizer drops under the chip and the row grows by a line'
);
check(
    (bool) preg_match( '/\.uc-lrow-meta > \.uc-lc-chip \{[^}]*flex:\s*0 0 auto/s', $cssc ),
    'the chip can be shrunk again; a pill broken across two lines is not a pill'
);
check(
    (bool) preg_match( '/\.uc-calendar \.uc-lrow-byline \{[^}]*min-width:\s*0/s', $cssc ),
    'the organizer cannot shrink below its content, so a nowrap line overflows the column instead of wrapping inside it'
);
/* THE THREE INHERITED PROPERTIES, declared for the 3.93.0 reason: this renders
 * inside somebody else's page and a host that sets break-word reaches it. */
foreach ( array( 'overflow-wrap:\s*normal', 'word-break:\s*normal', 'hyphens:\s*manual' ) as $prop ) {
    check(
        (bool) preg_match( '/\.uc-calendar \.uc-lrow-byline \{[^}]*' . $prop . '/s', $cssc ),
        'the organizer no longer declares ' . str_replace( ':\s*', ': ', $prop ) . ', so the host page decides whether a name is split in half'
    );
}

/* --- 3. ONE VERTICAL RULE, ON THE ITEMS. -------------------------------- */
/* ON THE ITEMS RATHER THAN THE ROW, because align-items is a parent's
 * suggestion and the 860px block already overrides it once. Same shape of fix
 * as the check box alignment in 3.93.0. */
check(
    (bool) preg_match( '/\.uc-calendar \.uc-lrow-media,\s*\.uc-calendar \.uc-lrow-main,\s*\.uc-calendar \.uc-lrow-when,\s*\.uc-calendar \.uc-lrow-where \{[^}]*align-self:\s*center/s', $cssc ),
    'the list columns no longer share one vertical rule, so the date and the venue sit level with the top of a picture they are beside'
);
/* AND ALL FOUR ARE NAMED. Three of four is the fault this fixes, wearing a
 * different name. */
if ( preg_match( '/(\.uc-calendar \.uc-lrow-[a-z]+,\s*)+\.uc-calendar \.uc-lrow-[a-z]+ \{\s*align-self:\s*center/s', $cssc, $m ) ) {
    check(
        4 === substr_count( $m[0], '.uc-lrow-' ),
        sprintf( 'only %d of the four list columns take the vertical rule', substr_count( $m[0], '.uc-lrow-' ) )
    );
}

/* --- 4. THE TICKED EDGE IS GONE. ---------------------------------------- */
/* 3.94.0 DREW IT, 3.95.1 STRAIGHTENED IT, AND 3.96.0 TOOK IT OFF, because Mark
 * asked for the row fill to be the only mark. So what is asserted here is
 * absence, in both shapes the edge has ever had: the inset shadow it was born
 * as and the pseudo-element it became. One of the two alone would let the other
 * come back.
 *
 * Measured before and after: the fill, the 600 weight and the teal count are
 * unchanged, and ticking still moves nothing, because the thing that was out of
 * flow is the thing that went.
 *
 * `position: relative` WENT WITH IT. The edge was the only thing in this panel
 * anchoring to it, and a positioning context left behind is how an unrelated
 * absolute element later resolves against the wrong box. */
check(
    ! preg_match( '/\.uc-who-opt:has\(input:checked\)::before/', $cssc ),
    'the ticked edge is drawn again as a pseudo-element, and the fill is meant to be the only mark'
);
check(
    ! preg_match( '/\.uc-who-opt:has\(input:checked\) \{[^}]*box-shadow:\s*inset/s', $cssc ),
    'the ticked edge is drawn again as an inset shadow, which is the shape it had in 3.94.0'
);
check(
    ! preg_match( '/\.uc-who-opt \{ position: relative; \}/', $cssc ),
    'the row is positioned again, which is a containing block left behind by an edge that no longer exists'
);
/* AND THE FILL KEEPS ITS SHAPE. The hover fill, the ticked fill and an
 * untouched row are all 6px, so a ticked row is the same shape as a hovered
 * one and only the colour tells them apart. */
check(
    ! preg_match( '/\.uc-who-opt:has\(input:checked\) \{[^}]*border-radius:\s*0/s', $cssc ),
    'the ticked row has had its radius squared, so a ticked row and a hovered row are two different shapes'
);


/* --- 5. THE TWO VIEWS ARE THE SAME WIDTH. ------------------------------- */
/* THE CAP IS KEYED TO THE MODE, NOT TO THE PANEL SHOWING. `uc-view-combined`
 * is rewritten by the toggle, so a cap keyed to it fell to 900px the moment
 * somebody pressed List. Measured at a 1200px container: the grid and sidebar
 * occupied 1198px and the list 900px, and the toggle moved 150px sideways. */
check(
    (bool) preg_match( '/\.uc-calendar:has\(\.uc-view-panels\[data-uc-combined="1"\]\) \{[^}]*max-width:\s*1200px/s', $cssc ),
    'the combined cap is keyed to the view class again, so the block changes width when somebody presses the toggle'
);
check(
    ! preg_match( '/\.uc-calendar\.uc-view-combined \{[^}]*max-width/s', $cssc ),
    'the cap is back on uc-view-combined, which the toggle rewrites'
);
/* AND NEITHER SCRIPT STRIPS THE SHARED CONTAINER. The card is what sizes the
 * panels inside it; taking it off for a lone list left the list 2px wider than
 * the grid and sidebar it replaces. Both scripts, because a change that reaches
 * one path and not the other is the split that has cost five faults. */
foreach ( array( 'calendar.js' => $cal_code, 'embed.js' => $emb_code ) as $which => $js ) {
    check(
        false === strpos( $js, "toggleClass('uc-view-panels-combined'" )
        && false === strpos( $js, "classList.toggle('uc-view-panels-combined'" ),
        $which . ' strips the combined wrapper class again, which takes the card off and resizes the list panel with it'
    );
}
/* AND "IS THIS COMBINED" IS ASKED OF THE ATTRIBUTE. Reading the class meant
 * monthParams() found nothing whenever the list was showing. */
check(
    false === strpos( $cal_code, "find('.uc-view-panels-combined')" ),
    'calendar.js asks the class whether a block is combined again, and that class used to come and go'
);

/* --- 6. A CLOSURE NOTE IS NOT CUT. -------------------------------------- */
/* THREE CUTS, ALL GONE: a 32 character shortener with an ellipsis, a two line
 * clamp, and overflow: hidden. Same decision as the event titles in these tiles
 * in 3.91.0. */
check(
    false === strpos( $code, 'SFAF_Closures::note_short(' ),
    'the month cell shortens its closure note again, and a cut note is the whole of what a closed day had to say'
);
check(
    ! preg_match( '/\.uc-day-closed-mark \.uc-closed-note \{[^}]*line-clamp/s', $cssc ),
    'the closure note is clamped again'
);
check(
    ! preg_match( '/\.uc-day-closed-mark \.uc-closed-note \{[^}]*overflow:\s*hidden/s', $cssc ),
    'the closure note is clipped again'
);
check(
    ! preg_match( '/class="uc-closed-note" title=/', $code ),
    'the closure note carries a title attribute again, which is the same string twice once the text is all on screen'
);
/* AND THE DAY PANEL CARRIES IT, which it never did: that panel is the readable
 * surface at the widths where the cell hides its own closure line, and a closed
 * day arrived there saying nothing about being closed. Both scripts. */
foreach ( array( 'calendar.js' => $cal_code, 'embed.js' => $emb_code ) as $which => $js ) {
    check(
        false !== strpos( $js, 'uc-day-closed-mark' ) && false !== strpos( $js, 'uc-day-panel-closed' ),
        $which . ' no longer copies the closure into the day panel, so a closed day says nothing there'
    );
}
check(
    (bool) preg_match( '/\.uc-calendar \.uc-day-panel-closed \.uc-closed-note \{[^}]*font-size:\s*12px/s', $cssc ),
    'the day panel closure note is not sized for the panel, so it renders at the 9px a grid cell uses'
);

/* AND THE PHONE RULE STILL OUTRANKS THE ONE THAT FILLS A CELL (3.95.1).
 *
 * "The widths where the cell hides its own closure line" is the sentence the
 * block above rests on, and it stopped being true inside this same release.
 * The rule letting a note fill its cell is unconditional
 * `.uc-day-closed-mark .uc-closed-note` at (0,2,0), and it sits later in the
 * stylesheet than both phone copies, which were the same (0,2,0). Equal
 * specificity, later wins: a 200 character note drew in full in an 80px cell
 * and took the row to 283px, while the closure NAME beside it stayed hidden,
 * because nothing later sets display on that one.
 *
 * Measured at a 500px container after the raise: the cell is 56.8px whether
 * the note is 65 characters or 220, and the day panel carries the whole note.
 *
 * BOTH COPIES, because the container query and the media query are the same
 * rule for two kinds of host and drift is what makes one of them a surprise. */
$phone_hides = preg_match_all(
    '/\.uc-calendar \.uc-day-closed-mark \.uc-closed-note \{[^}]*display:\s*none/s',
    $cssc
);
check(
    2 === $phone_hides,
    'a phone copy of the closure-note hide is back to (0,2,0), so the unconditional fill rule later in the file outranks it and a long note grows a phone cell'
);

if ( $fails ) {
    echo 'FAIL: ' . count( $fails ) . "\n";
    foreach ( array_unique( $fails ) as $f ) { echo '  . ' . $f . "\n"; }
    exit( 1 );
}
echo "the combined mode composes both renderers, source linking reaches every one of them,\n";
echo "and the grid card's thumbnail is edged in neutral, carrying no colour it cannot explain.\n";
exit( 0 );
