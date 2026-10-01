<?php
/**
 * NO AUTHOR ON A PUBLIC EVENT PAGE, MEASURED IN CHROME (3.106.1).
 *
 *     php .claude/byline-live.php           writes the before and after pages
 *     php .claude/byline-live.php --run     writes them, drives Chrome, checks
 *     php .claude/byline-live.php --live    checks resources.sfaf.org as it is now
 *     php .claude/byline-live.php --capture refreshes the two fixtures from the site
 *
 * --run: the category archive and one event page as resources.sfaf.org served
 * them on 2026-10-01 (fixtures/bylines/before-*.html), and the same two pages
 * after SFAF_Bylines::strip(), the code the plugin runs on them. Both load in
 * Chrome against the live theme's stylesheets, with the site's own scripts
 * taken out of both so neither can navigate. For each page: the rendered
 * text, every link and the source carry no author name or login; the event
 * cards are the same number, none is taller than before, each keeps the rule
 * that divides it from the next, and each keeps every word but the name. The before pages must FAIL the author
 * check, or the check is reading nothing.
 *
 * --live: the same author check on the two pages fetched now, as source and as
 * Chrome renders them, plus the event's oEmbed answer, the public REST users
 * list, and the author archive of every login found, which must redirect away.
 * Until 3.106.1 is installed on the site this is expected to fail.
 */

$root = dirname( __DIR__ );
$fx   = __DIR__ . '/fixtures/bylines';
$out  = __DIR__ . '/byline-live';
$mode = isset( $argv[1] ) ? $argv[1] : '';
$site = 'https://resources.sfaf.org';
$pages = array(
    'category' => $site . '/collections/event-category/community-events/',
    'event'    => $site . '/collections/events/queer-intimacies-art-opening-for-harry-stone/',
);

/* Whose name and login must not appear. people.json is a stand-in, written
   into the captured pages in place of the real author so that no real name or
   login is committed; people.local.json, never committed, holds the real ones,
   and --live and --capture need it. */
$people = json_decode( (string) file_get_contents( $fx . '/people.json' ), true );
$real   = file_exists( $fx . '/people.local.json' ) ? json_decode( (string) file_get_contents( $fx . '/people.local.json' ), true ) : null;
if ( in_array( $mode, array( '--live', '--capture' ), true ) && ! $real ) {
    echo "$mode needs .claude/fixtures/bylines/people.local.json: {\"names\": [...], \"logins\": [...]}, the real author's.\n";
    exit( 1 );
}
if ( '--live' === $mode ) {
    $people = $real;   // the site carries the real author, never the stand-in
}

/* curl, because PHP on this machine has no https stream. One request, no
   redirect followed, headers and body both kept. */
function bl_fetch( $url ) {
    $raw = (string) shell_exec( 'curl -s -i -m 60 -A "Mozilla/5.0 (SFAF byline check)" ' . escapeshellarg( $url ) );
    // The pipe can hand back bare newlines, so either ending splits.
    $parts = preg_split( "/\r?\n\r?\n/", $raw, 2 );
    return array( 'body' => isset( $parts[1] ) ? $parts[1] : '', 'headers' => preg_split( "/\r?\n/", $parts[0] ) );
}

if ( '--capture' === $mode ) {
    // The real author becomes the stand-in, pair by pair, before anything is written.
    $swap = array_combine(
        array_merge( $real['names'], $real['logins'] ),
        array_merge( array_pad( array(), count( $real['names'] ), $people['names'][0] ), array_slice( array_pad( $people['logins'], count( $real['logins'] ), $people['logins'][0] ), 0, count( $real['logins'] ) ) )
    );
    foreach ( $pages as $k => $url ) {
        $body = str_ireplace( array_keys( $swap ), array_values( $swap ), bl_fetch( $url )['body'] );
        file_put_contents( "$fx/before-$k.html", $body );
        echo "captured $k: " . strlen( $body ) . " bytes, the author replaced by the stand-in\n";
    }
    exit( 0 );
}

/* ---- The author check, one function for every mode. ------------------- */
function bl_authors( $label, $source, $text, $links, $people ) {
    $found = array();
    foreach ( $links as $h ) {
        if ( preg_match( '#/author/#i', (string) $h ) ) { $found[] = "$label: a link to an author archive ($h)"; }
    }
    foreach ( array( 'source' => $source, 'rendered text' => $text, 'links' => implode( "\n", $links ) ) as $where => $hay ) {
        foreach ( array_merge( $people['names'], $people['logins'] ) as $who ) {
            if ( preg_match( '/(?<![\w.@-])' . preg_quote( $who, '/' ) . '(?![\w-])/i', $hay ) ) { $found[] = "$label: \"$who\" in the $where"; }
        }
    }
    return array_values( array_unique( $found ) );
}

/* ---- Chrome. ------------------------------------------------------------ */
$chrome = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
function bl_chrome( $target ) {
    global $chrome;
    return (string) shell_exec( '"' . $chrome . '" --headless --disable-gpu --run-all-compositor-stages-before-draw --allow-file-access-from-files --window-size=1280,4000 --virtual-time-budget=15000 --dump-dom "' . $target . '" 2>NUL' );
}

/** A page made loadable from disk: the site's scripts out, a base, the probe in. */
function bl_probe_page( $html, $base ) {
    $html  = preg_replace( '#<script\b[^>]*>.*?</script>#is', '', $html );
    $html  = preg_replace( '#<head\b[^>]*>#i', '$0<base href="' . $base . '">', $html, 1 );
    $probe = <<<'JS'
<script>
(function () {
  function run() {
    var arts = [].slice.call(document.querySelectorAll('article.type-uc_event'));
    var out = {
      text: document.body.innerText,
      links: [].slice.call(document.querySelectorAll('a[href]')).map(function (a) { return a.getAttribute('href'); }),
      events: arts.map(function (a) {
        return {
          id: a.id,
          text: a.innerText,
          h: Math.round(a.getBoundingClientRect().height),
          byline: !!a.querySelector('.sfaf-authors-list, .sfaf-entry-athors'),
          rules: a.querySelectorAll('.sfaf-separator hr').length
        };
      })
    };
    var pre = document.createElement('pre');
    pre.id = 'out';
    pre.textContent = JSON.stringify(out);
    document.body.appendChild(pre);
  }
  if (document.readyState === 'complete') { run(); } else { window.addEventListener('load', run); }
})();
</script>
JS;
    // The theme closes <html> and never <body>, so the probe goes before
    // whichever close the page has.
    foreach ( array( '#</body>#i', '#</html>#i' ) as $close ) {
        if ( preg_match( $close, $html ) ) {
            return preg_replace( $close, $probe . '$0', $html, 1 );
        }
    }
    return $html . $probe;
}

function bl_read_probe( $file ) {
    $dom = bl_chrome( 'file:///' . str_replace( '\\', '/', realpath( $file ) ) );
    if ( ! preg_match( '#<pre id="out">(.*?)</pre>#s', $dom, $m ) ) { return null; }
    return json_decode( html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ), true );
}

$fails = array();
function bl_check( $ok, $why ) { global $fails; if ( ! $ok ) { $fails[] = $why; } }

/* ======================================================================
 * --live: the site as it is.
 * ==================================================================== */
if ( '--live' === $mode ) {
    if ( ! file_exists( $chrome ) ) { echo "Chrome not found at $chrome\n"; exit( 1 ); }
    @mkdir( $out );
    foreach ( $pages as $k => $url ) {
        $src  = bl_fetch( $url )['body'];
        bl_check( '' !== $src, "live $k: nothing came back from $url" );
        // As Chrome renders it, scripts and all; then read again from disk
        // with the probe, so innerText is what a visitor sees.
        $dom  = bl_chrome( $url );
        // The live page carries the real author, so it is read from the
        // system temp folder and deleted, never left in the repository.
        $tmp  = sys_get_temp_dir() . "/sfaf-byline-live-$k.html";
        file_put_contents( $tmp, bl_probe_page( $dom, $site . '/' ) );
        $p    = bl_read_probe( $tmp );
        @unlink( $tmp );
        bl_check( null !== $p, "live $k: Chrome returned no probe block" );
        foreach ( bl_authors( "live $k", $src . "\n" . $dom, $p ? $p['text'] : '', $p ? $p['links'] : array(), $people ) as $f ) { $fails[] = $f; }
        if ( $p ) { echo "live $k: " . count( $p['events'] ) . " event cards, " . count( array_filter( $p['events'], function ( $e ) { return $e['byline']; } ) ) . " with a byline\n"; }
    }
    $oe = bl_fetch( $site . '/wp-json/oembed/1.0/embed?url=' . $pages['event']   /* unencoded: escapeshellarg on Windows turns % into a space */ )['body'];
    bl_check( false !== strpos( $oe, '"title"' ), 'live: the event\'s oEmbed answer did not come back, so it was not checked' );
    bl_check( false === strpos( $oe, '"author_name"' ) && false === strpos( $oe, '"author_url"' ), 'live: the event\'s oEmbed answer names an author: ' . substr( $oe, 0, 200 ) );
    $users = bl_fetch( $site . '/wp-json/wp/v2/users?per_page=100' )['body'];
    bl_check( 0 === strpos( ltrim( $users ), '[' ), 'live: the REST users list did not come back, so it was not checked' );
    foreach ( $people['logins'] as $l ) { bl_check( false === stripos( $users, $l ), "live: the public REST users list carries \"$l\"" ); }
    foreach ( $people['logins'] as $l ) {
        $r = bl_fetch( $site . '/collections/author/' . $l . '/' );
        $loc = '';
        foreach ( $r['headers'] as $h ) { if ( 0 === stripos( $h, 'Location:' ) ) { $loc = trim( substr( $h, 9 ) ); } }
        bl_check( '' !== $loc && false === stripos( $loc, '/author/' ), "live: /collections/author/$l/ does not redirect away (" . ( $loc ? $loc : ( isset( $r['headers'][0] ) ? $r['headers'][0] : 'no answer' ) ) . ')' );
    }
    if ( $fails ) { echo 'BYLINES LIVE: ' . count( $fails ) . " FINDING(S)\n  - " . implode( "\n  - ", $fails ) . "\n"; exit( 1 ); }
    echo "live: no author name or login in the source, the rendered text or the links of either page; oEmbed, REST users and author archives give none out.\n";
    exit( 0 );
}

/* ======================================================================
 * The before and after pages, from the fixtures and the real strip.
 * ==================================================================== */
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
function home_url( $p = '' ) { return 'https://resources.sfaf.org' . $p; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
require $root . '/includes/class-sfaf-bylines.php';

@mkdir( $out );
$built = array();
foreach ( array_keys( $pages ) as $k ) {
    $before = (string) file_get_contents( "$fx/before-$k.html" );
    $after  = SFAF_Bylines::strip( $before );
    file_put_contents( "$out/before-$k.html", bl_probe_page( $before, $site . '/' ) );
    file_put_contents( "$out/after-$k.html", bl_probe_page( $after, $site . '/' ) );
    $built[ $k ] = array( 'before' => $before, 'after' => $after );
}
if ( '--run' !== $mode ) { echo "wrote the pages to $out\n"; exit( 0 ); }
if ( ! file_exists( $chrome ) ) { echo "Chrome not found at $chrome\n"; exit( 1 ); }

foreach ( $built as $k => $src ) {
    $b = bl_read_probe( "$out/before-$k.html" );
    $a = bl_read_probe( "$out/after-$k.html" );
    bl_check( null !== $b && null !== $a, "$k: Chrome returned no probe block" );
    if ( null === $b || null === $a ) { continue; }

    $was = bl_authors( "before $k", $src['before'], $b['text'], $b['links'], $people );
    $now = bl_authors( "after $k", $src['after'], $a['text'], $a['links'], $people );
    foreach ( $now as $f ) { $fails[] = $f; }
    if ( 'category' === $k ) {
        bl_check( count( $was ) > 0, 'before category: the check found no author on a page that carried 24 bylines, so it is reading nothing' );
        bl_check( count( $b['events'] ) > 0, 'before category: no event cards were found to measure' );
    }

    bl_check( count( $a['events'] ) === count( $b['events'] ), "$k: " . count( $b['events'] ) . ' event cards before, ' . count( $a['events'] ) . ' after' );
    foreach ( $a['events'] as $i => $e ) {
        $prev = isset( $b['events'][ $i ] ) ? $b['events'][ $i ] : null;
        bl_check( ! $e['byline'], "$k: {$e['id']} still has a byline" );
        if ( $prev ) {
            bl_check( $e['h'] <= $prev['h'], "$k: {$e['id']} is taller after ({$prev['h']}px to {$e['h']}px)" );
            // The rule above the byline is the line between one event and the next.
            bl_check( $e['rules'] === $prev['rules'], "$k: {$e['id']} had {$prev['rules']} rule(s) and has {$e['rules']}" );
            // Nothing but the byline goes: the card's words are the same, less the name.
            $norm = function ( $t ) { return trim( preg_replace( '/s+/u', ' ', (string) $t ) ); };
            $want = $norm( str_ireplace( $people['names'], '', $prev['text'] ) );   // the theme sets it in capitals
            bl_check( $norm( $e['text'] ) === $want, "$k: {$e['id']} lost words besides the byline" );
        }
    }
    $hb = array_sum( array_map( function ( $e ) { return $e['h']; }, $b['events'] ) );
    $ha = array_sum( array_map( function ( $e ) { return $e['h']; }, $a['events'] ) );
    echo sprintf( "%-8s %2d event cards; bylines %d before, %d after; card height %dpx before, %dpx after; author findings %d before, %d after\n",
        $k, count( $a['events'] ),
        count( array_filter( $b['events'], function ( $e ) { return $e['byline']; } ) ),
        count( array_filter( $a['events'], function ( $e ) { return $e['byline']; } ) ),
        $hb, $ha, count( $was ), count( $now ) );
}

if ( $fails ) {
    echo 'BYLINES: ' . count( $fails ) . " FAILURE(S)\n  - " . implode( "\n  - ", array_slice( $fails, 0, 30 ) ) . "\n";
    exit( 1 );
}
echo "bylines: the category page and the event page, after the strip, carry no author name or login in the text, the links or the source; the cards keep their number and their dividing rules, grow no taller and lose no other word.\n";
