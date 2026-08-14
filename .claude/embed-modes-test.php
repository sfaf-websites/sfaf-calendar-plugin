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
check( 744 === $stack, "the combined mode now stacks at {$stack}px and the readme publishes 744px" );

check(
    (bool) preg_match( '/uc-panel-calendar\s*\{[^}]*min-width:\s*0/', $css ),
    'the grid panel has no min-width: 0, so the month table cannot shrink and will overflow the host page'
);
check(
    (bool) preg_match( '/uc-panel-list\s*\{[^}]*min-width:\s*0/', $css ),
    'the list panel has no min-width: 0'
);

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
printf( "          intrinsic flex rather than a container query, stacks at %dpx, min-width: 0 on both\n", $stack );
echo "carried:  the generator control, the block attribute, embed.js, the endpoint parameter\n";
echo "          and the cache identity\n\n";

if ( $fails ) {
    echo 'FAIL: ' . count( $fails ) . "\n";
    foreach ( array_unique( $fails ) as $f ) { echo '  . ' . $f . "\n"; }
    exit( 1 );
}
echo "the combined mode composes both renderers, and source linking reaches every one of them.\n";
exit( 0 );
