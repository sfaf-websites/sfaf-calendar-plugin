<?php
/**
 * THE FOUR PAGES THAT BUILD THEIR OWN DOCUMENT (3.66.0).
 *
 *     php .claude/self-built-pages-test.php
 *     php .claude/self-built-pages-test.php --self-test
 *
 * WHAT THESE FOUR HAVE IN COMMON, AND WHY IT KEEPS COSTING US. caladmin, the
 * staff request form, the community submission form and the notice page the
 * follow links and the registration cancel link land on all write their own
 * <!DOCTYPE>, their own <head>, and call wp_head() NOWHERE. Anything a normal
 * WordPress page gets for free, these get only if their own head asks for it.
 * They had no stylesheet until 3.44.0 for exactly this reason, and they had no
 * FAVICON until this release for exactly the same one.
 *
 * The comment beside caladmin's favicon said the two public forms did not need
 * icon links because they were "rendered by the theme through wp_head()". They
 * are not. That sentence was true of nothing, and it is the reason nobody
 * looked again for two years. So the assertions below are about the RENDERED
 * DOCUMENTS rather than about any claim made in a comment.
 *
 * WHAT IS ASSERTED:
 *
 *   1. Each of the four emits the calendar's favicon, all three links, from the
 *      plugin's own bundled asset. Not the site's, not the theme's: this plugin
 *      owns the icon and ships it.
 *   2. There is ONE declaration of those links. A second copy is three more
 *      things to update when the icon changes.
 *   3. The favicon files it points at actually exist in the plugin.
 *
 * AND THE OTHER THING THESE PAGES SHARE: they are all reached cross-origin,
 * which is what Part G of this release turned out to be about. The referrer
 * assertions at the end are the same subject seen from the other side.
 */

define( 'ABSPATH', __DIR__ );

$root  = dirname( __DIR__ );
$fails = array();
$self  = in_array( '--self-test', array_slice( $argv, 1 ), true );

function fail( $m ) { global $fails; $fails[] = $m; }
function expect( $label, $got, $want ) {
    if ( $got !== $want ) {
        fail( sprintf( '%s: got %s, expected %s', $label, var_export( $got, true ), var_export( $want, true ) ) );
    }
}

/* ---------------------------------------------------------------------------
 * WordPress, in miniature.
 * ------------------------------------------------------------------------ */

define( 'SFAF_PLUGIN_URL', 'https://resources.sfaf.org/wp-content/plugins/sfaf-calendar/' );
define( 'SFAF_VERSION', '3.66.0' );

function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $t ) { return esc_html( $t ); }
function esc_url( $t ) { return (string) $t; }
function esc_url_raw( $t, $p = null ) { return (string) $t; }
function esc_textarea( $t ) { return esc_html( $t ); }
function __( $t, $d = '' ) { return $t; }
function language_attributes() { echo 'lang="en-US"'; }
function bloginfo( $k ) { echo 'UTF-8'; }
function status_header( $c ) {}
function nocache_headers() {}
function wp_unslash( $v ) { return $v; }
function home_url( $p = '', $s = null ) { return 'https://resources.sfaf.org' . $p; }
function get_option( $n, $d = false ) { return isset( $GLOBALS['options'][ $n ] ) ? $GLOBALS['options'][ $n ] : $d; }
function sanitize_title( $s ) { return strtolower( preg_replace( '/[^a-z0-9-]+/i', '-', trim( (string) $s ) ) ); }
function get_term_by( $f, $v, $t ) { return false; }
function get_term_link( $t ) { return 'https://resources.sfaf.org/event-category/x/'; }
function is_wp_error( $t ) { return ( $t instanceof WP_Error ); }
function get_post_type_archive_link( $t ) { return 'https://resources.sfaf.org/events/'; }
function get_post_type_object( $t ) { return (object) array( 'rewrite' => array( 'slug' => 'events' ) ); }
function remove_query_arg( $k, $u ) { return (string) $u; }
function add_query_arg( $k, $v = '', $u = '' ) { return $u . ( false === strpos( $u, '?' ) ? '?' : '&' ) . $k . '=' . $v; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }

class WP_Error {}

$GLOBALS['options'] = array();

/*
 * THE TWO FUNCTIONS UNDER TEST ARE SLICED OUT OF THE REAL FILE, not copied and
 * not reimplemented. sfaf-template-functions.php is 3,000 lines and pulls in
 * most of the plugin at load; what is wanted is four functions out of it. The
 * slice is by name and brace depth and throws rather than returning something
 * plausible when a name is gone, which is the same arrangement
 * embed-combined-panels-test.js uses on portal.js.
 */
$TPL = file_get_contents( $root . '/includes/sfaf-template-functions.php' );

function slice_fn( $name ) {
    global $TPL;
    $at = strpos( $TPL, "\nfunction " . $name . '(' );
    if ( false === $at ) {
        throw new Exception( "sfaf-template-functions.php has no function $name(); this test is asking about code that is gone" );
    }
    $depth = 0;
    $start = $at + 1;
    for ( $j = strpos( $TPL, '{', $start ); $j < strlen( $TPL ); $j++ ) {
        if ( '{' === $TPL[ $j ] ) { $depth++; }
        elseif ( '}' === $TPL[ $j ] ) {
            $depth--;
            if ( 0 === $depth ) { return substr( $TPL, $start, $j + 1 - $start ); }
        }
    }
    throw new Exception( "unbalanced braces reading $name() out of sfaf-template-functions.php" );
}

$SLICED = array( 'sfaf_favicon_links', 'sfaf_host_base', 'sfaf_calendar_home_url', 'sfaf_calendar_referrer' );
foreach ( $SLICED as $name ) {
    eval( slice_fn( $name ) );
}

/** Capture what a callable prints. */
function printed( $fn ) {
    ob_start();
    $fn();
    return (string) ob_get_clean();
}

/** @return DOMElement[] */
function nodes( $html, $xpath ) {
    if ( '' === trim( $html ) ) { return array(); }
    $doc = new DOMDocument();
    libxml_use_internal_errors( true );
    $doc->loadHTML( '<?xml encoding="utf-8" ?><div>' . $html . '</div>' );
    libxml_clear_errors();
    $out = array();
    foreach ( ( new DOMXPath( $doc ) )->query( $xpath ) as $el ) { $out[] = $el; }
    return $out;
}

/** Every rel/href pair of icon links in a document, in order. */
function icon_links( $html ) {
    $out = array();
    foreach ( nodes( $html, '//link[@rel="icon" or @rel="apple-touch-icon"]' ) as $el ) {
        $href = (string) $el->getAttribute( 'href' );
        $out[] = $el->getAttribute( 'rel' ) . ' ' . preg_replace( '/\?.*$/', '', basename( $href ) );
    }
    return $out;
}

/* =========================================================================
 * THE READER, PROVED FIRST.
 * ====================================================================== */

if ( $self ) {
    $ok = true;
    $probe = function ( $label, $got, $want ) use ( &$ok ) {
        $good = ( $got === $want );
        printf( "  %s  %-56s got %s want %s\n", $good ? 'ok  ' : 'FAIL', $label,
            var_export( $got, true ), var_export( $want, true ) );
        $ok = $ok && $good;
    };

    $probe( 'reads an icon link',
        icon_links( '<link rel="icon" href="/a/b/thing.svg?ver=1" />' ), array( 'icon thing.svg' ) );
    $probe( 'reads an apple-touch-icon',
        icon_links( '<link rel="apple-touch-icon" href="/x-180.png" />' ), array( 'apple-touch-icon x-180.png' ) );
    $probe( 'ignores a stylesheet',
        icon_links( '<link rel="stylesheet" href="/a.css" />' ), array() );
    $probe( 'ignores an icon named only in prose',
        icon_links( '<p>rel="icon" href="favicon.svg"</p>' ), array() );
    $probe( 'ignores a commented-out link',
        icon_links( '<!-- <link rel="icon" href="/a.svg" /> -->' ), array() );
    $probe( 'keeps document order',
        icon_links( '<link rel="icon" href="/2.png" /><link rel="icon" href="/1.svg" />' ),
        array( 'icon 2.png', 'icon 1.svg' ) );

    $threw = false;
    try { slice_fn( 'sfaf_not_a_real_function' ); } catch ( Exception $e ) { $threw = true; }
    $probe( 'slicing a name that is gone throws', $threw, true );

    echo "\n" . ( $ok ? 'the reader can see what it is looking for.' : 'THE READER IS BROKEN.' ) . "\n";
    exit( $ok ? 0 : 1 );
}

/* =========================================================================
 * 1. THE ICON ITSELF: three links, in fallback order, at real files.
 * ====================================================================== */

$links = printed( 'sfaf_favicon_links' );
$want  = array(
    'icon favicon-caladmin.svg',
    'icon favicon-caladmin.png',
    'apple-touch-icon favicon-caladmin-180.png',
);
expect( 'three links, SVG first so a browser that understands it stops there',
    icon_links( $links ), $want );

/* The PNG is the Safari fallback and must say what size it is, or a browser
   has no reason to prefer it to the SVG it just skipped. */
expect( 'the PNG declares its size',
    nodes( $links, '//link[@rel="icon"][@type="image/png"]' )[0]->getAttribute( 'sizes' ), '32x32' );

/* THE PLUGIN OWNS THE ICON. Every href is under the plugin's own URL, so this
   is a bundled asset travelling with the code rather than a media-library
   upload somebody can delete while tidying. */
foreach ( nodes( $links, '//link' ) as $el ) {
    $href = (string) $el->getAttribute( 'href' );
    expect( 'served from the plugin: ' . basename( preg_replace( '/\?.*$/', '', $href ) ),
        0 === strpos( $href, SFAF_PLUGIN_URL . 'public/images/' ), true );
}

/* And the files are really there. A link to a missing icon is a browser
   silently falling back to nothing, which looks exactly like no link at all. */
foreach ( array( 'favicon-caladmin.svg', 'favicon-caladmin.png', 'favicon-caladmin-180.png' ) as $file ) {
    expect( "public/images/$file exists", file_exists( $root . '/public/images/' . $file ), true );
}

/* Cache-busted on the version, like every other asset this plugin serves. */
expect( 'each link is version stamped',
    substr_count( $links, '?ver=' . SFAF_VERSION ), 3 );

/* =========================================================================
 * 2. ALL FOUR DOCUMENTS ASK FOR IT, AND ONE PLACE DECLARES IT.
 *
 * The heads themselves cannot be rendered here: caladmin's needs a WP_User and
 * a settings option, and the forms need a resolved token. What CAN be decided
 * is that each of the four documents calls the one emitter, and that none of
 * them writes icon links of its own. Given assertion 1 above, that is the whole
 * of the claim.
 * ====================================================================== */

$documents = array(
    'caladmin'                  => '/includes/class-sfaf-portal.php',
    'both public forms'         => '/includes/class-sfaf-submissions.php',
    'the follow and cancel page'=> '/includes/sfaf-template-functions.php',
);

foreach ( $documents as $what => $file ) {
    $src  = file_get_contents( $root . $file );
    /* Comments name the mechanism in order to explain it, so they are stripped
       before either question is asked. */
    $code = preg_replace( '#/\*.*?\*/#s', '', $src );

    /* AND SO IS THE EMITTER'S OWN BODY. It lives in one of these three files
       and it is the one thing in the plugin that is SUPPOSED to write icon
       links; finding it there and calling it a second copy would be this test
       reporting the fix as the fault. */
    $code = str_replace( slice_fn( 'sfaf_favicon_links' ), '', $code );

    expect( "$what calls the one emitter", substr_count( $code, 'sfaf_favicon_links()' ) >= 1, true );
    expect( "$what writes no icon link of its own",
        (bool) preg_match( '/rel=["\'](icon|apple-touch-icon)["\']/', $code ), false );
}

/* The emitter is declared once, and it is a function rather than three lines
   somebody will copy for the fifth document. */
$tpl_code = preg_replace( '#/\*.*?\*/#s', '', $TPL );
expect( 'one declaration of the icon links',
    substr_count( $tpl_code, 'function sfaf_favicon_links(' ), 1 );

/* =========================================================================
 * 3. AN ORIGIN IS NOT A CALENDAR PAGE.
 *
 * WHAT WAS ACTUALLY WRONG WITH "All Events". The event page is on
 * resources.sfaf.org and the calendar is a page on sfaf.org, so every real
 * click through to an event is CROSS-ORIGIN, and every current browser then
 * sends the ORIGIN ONLY as the referrer: "https://sfaf.org/", no path. That
 * passed every check in sfaf_calendar_referrer() and was handed back, so
 * "All Events" took a visitor who was reading a calendar to the SITE ROOT.
 *
 * It looked like a lost referrer or an unfilled setting. It was neither: the
 * referrer arrived, was valid, and named nothing.
 * ====================================================================== */

$GLOBALS['options']['uc_settings'] = array( 'calendar_home_url' => 'https://www.sfaf.org/events/' );

function from( $referrer ) {
    if ( null === $referrer ) {
        unset( $_SERVER['HTTP_REFERER'] );
    } else {
        $_SERVER['HTTP_REFERER'] = $referrer;
    }
    return sfaf_calendar_referrer();
}

/* The exact header a browser sends coming from sfaf.org, in its three spellings. */
foreach ( array( 'https://sfaf.org/', 'https://sfaf.org', 'https://www.sfaf.org/' ) as $origin_only ) {
    expect( "an origin-only referrer names no page: $origin_only", from( $origin_only ), '' );
}

/* A referrer that DOES carry a path is still used, which is the case this must
   not have broken: same-origin navigation is not truncated. */
expect( 'a real calendar page is still returned',
    from( 'https://www.sfaf.org/calendar/' ), 'https://www.sfaf.org/calendar/' );
expect( 'and keeps its query string',
    from( 'https://www.sfaf.org/whats-on/?uc_cat=housing' ), 'https://www.sfaf.org/whats-on/?uc_cat=housing' );
expect( 'a path on this site is still returned',
    from( 'https://resources.sfaf.org/some-page/' ), 'https://resources.sfaf.org/some-page/' );

/*
 * A PRE-EXISTING QUIRK, RECORDED RATHER THAN FIXED, because it is not what this
 * release is about and changing it is a behaviour change on the public event
 * page that nobody asked for.
 *
 * The "is this an event page" test applies THIS SITE'S archive base to any
 * allowed host. resources.sfaf.org/events/{slug} really is an event and must be
 * refused. sfaf.org/events/{anything} is somebody else's URL space, and a
 * calendar page living under it is refused too. The consequence is mild: it
 * falls through to the configured Calendar home URL, whose own placeholder is
 * https://www.sfaf.org/events/, so a visitor still lands on a calendar. It is
 * asserted here so that it is a decision somebody can find rather than a
 * surprise the next person debugs from scratch.
 */
expect( 'a calendar page under sfaf.org/events/ is refused as if it were an event',
    from( 'https://www.sfaf.org/events/calendar/' ), '' );

/* Everything the function already refused, still refused. This release added a
   condition and must not have widened anything. */
expect( 'no referrer at all', from( null ), '' );
expect( 'an empty referrer', from( '' ), '' );
expect( 'somebody else entirely', from( 'https://evil.example.com/events/' ), '' );
expect( 'their origin alone', from( 'https://evil.example.com/' ), '' );
expect( 'a scheme that is not a page', from( 'javascript:alert(1)' ), '' );
expect( 'an event page is not a calendar', from( 'https://resources.sfaf.org/events/some-event/' ), '' );
/* The bare archive still is one, and that must survive the new condition:
   its path is '/events/', which is not empty. */
expect( 'the bare archive is still a calendar', from( 'https://resources.sfaf.org/events/' ),
    'https://resources.sfaf.org/events/' );

/* =========================================================================
 * 4. AND WITH NO REFERRER, THE SETTING IS WHAT ANSWERS.
 *
 * Half the fix is code and half is a value somebody has to type. Both halves
 * are asserted, because the code half alone still sends a visitor to the site
 * root by way of the archive.
 * ====================================================================== */

expect( 'a configured calendar home is read', sfaf_calendar_home_url(), 'https://www.sfaf.org/events/' );

$GLOBALS['options']['uc_settings'] = array( 'calendar_home_url' => '' );
expect( 'an empty setting is no setting', sfaf_calendar_home_url(), '' );

$GLOBALS['options']['uc_settings'] = array( 'calendar_home_url' => 'sfaf.org/events' );
expect( 'a setting with no scheme is refused rather than half used', sfaf_calendar_home_url(), '' );

$GLOBALS['options']['uc_settings'] = array( 'calendar_home_url' => 'javascript:alert(1)' );
expect( 'and a setting that is not a page is refused', sfaf_calendar_home_url(), '' );

/* ===================================================================== */

if ( $fails ) {
    echo 'SELF-BUILT PAGES: ' . count( $fails ) . ' FAILURE' . ( 1 === count( $fails ) ? '' : 'S' ) . "\n";
    foreach ( $fails as $f ) { echo "  - $f\n"; }
    exit( 1 );
}

echo "SELF-BUILT PAGES\n";
echo "  the icon      three links, SVG then PNG then apple-touch, at files that exist\n";
echo "  all four      caladmin, both forms and the notice page call the one emitter\n";
echo "  one place     declares those links, and none of the four writes its own\n";
echo "  the referrer  an origin with no path names no calendar page and is not used\n";
echo "  the setting   is what answers then, and is refused unless it is a real URL\n";
exit( 0 );
