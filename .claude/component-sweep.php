<?php
/**
 * EVERY CALADMIN SCREEN IS BUILT FROM THE SHARED COMPONENTS (3.112.1).
 *
 *     php .claude/component-sweep.php               renders every screen, sweeps it
 *     php .claude/component-sweep.php --self-test   proves each rule can fail
 *
 * The Tags section shipped in 3.112.0 as fresh markup beside the Categories
 * section it should have been built from: no fold card, and Add tag directly
 * against the New tag field. It keeps happening when a section is added, so
 * this renders every caladmin screen through the real renderers (it runs
 * caps-sweep-live.php, which writes them through wp-kit.php) and reads each
 * one for four things:
 *
 *   A  BUTTONS. Every <button>, submit, button input and role="button" link
 *      carries a uc- class portal.css styles. The clearest signal of fresh
 *      markup is a button the stylesheet has never heard of.
 *   B  SECTIONS. Every <section> is a shared section (.uc-section) or card
 *      (.uc-bento-card, .uc-card), and a .uc-section has its .uc-section-head
 *      and draws its content in a .uc-card, as Series, Categories and Tags do.
 *   C  CARDS. A block whose class names a card is the shared card (.uc-card or
 *      .uc-bento-card), or a card component named below with its reason.
 *   D  ACTION ROWS. No submit button sits directly after a block field
 *      (.uc-field): its button goes in an action row, which is what gives it
 *      the gap. An inline bar (a search box and its button) is not a block
 *      field and is not this.
 *
 * CLAUDE.md 7 states the rule this checks.
 */

$root = dirname( __DIR__ );
$args = array_slice( isset( $argv ) ? $argv : array(), 1 );

/* Card components that are not .uc-card, each its own styled component. */
$CARDS_ALLOWED = array(
    'uc-login-card'     => 'the sign-in page\'s card, on a page with no portal chrome',
    'uc-questions-card' => 'Questions for registrants, a block inside the Registration bento card',
);

/** The uc- classes portal.css styles, comments stripped. */
function sweep_defined( $css ) {
    preg_match_all( '/\.(uc-[A-Za-z0-9_-]+)/', preg_replace( '#/\*.*?\*/#s', '', $css ), $m );
    return array_flip( $m[1] );
}
function sweep_classes( $el ) {
    return array_values( array_filter( preg_split( '/\s+/', trim( (string) $el->getAttribute( 'class' ) ) ) ) );
}
function sweep_label( $el ) {
    $t = trim( preg_replace( '/\s+/', ' ', $el->textContent ) );
    return '<' . $el->nodeName . ( $el->getAttribute( 'class' ) ? ' class="' . $el->getAttribute( 'class' ) . '"' : '' ) . '>' . ( '' !== $t ? ' "' . substr( $t, 0, 40 ) . '"' : '' );
}

/**
 * The four rules over one page.
 *
 * @return string[] What failed.
 */
function sweep_page( $html, $defined, $cards_allowed ) {
    $out = array();
    $d   = new DOMDocument();
    libxml_use_internal_errors( true );
    $d->loadHTML( '<?xml encoding="utf-8"?>' . $html );
    libxml_clear_errors();
    $x = new DOMXPath( $d );
    $has = function ( $el, $cls ) { return in_array( $cls, sweep_classes( $el ), true ); };

    /* A */
    foreach ( $x->query( '//button | //input[@type="submit" or @type="button" or @type="reset"] | //a[@role="button"]' ) as $b ) {
        $styled = false;
        foreach ( sweep_classes( $b ) as $c ) {
            if ( isset( $defined[ $c ] ) ) { $styled = true; break; }
        }
        if ( ! $styled ) { $out[] = 'A: a button with no class portal.css styles: ' . sweep_label( $b ); }
    }
    /* B */
    foreach ( $x->query( '//section' ) as $s ) {
        if ( ! $has( $s, 'uc-section' ) && ! $has( $s, 'uc-bento-card' ) && ! $has( $s, 'uc-card' ) && ! $has( $s, 'uc-tpl-main' ) ) {
            $out[] = 'B: a section that is not a shared section or card: ' . sweep_label( $s );
            continue;
        }
        if ( $has( $s, 'uc-section' ) ) {
            $head = $card = false;
            foreach ( $s->childNodes as $c ) {
                if ( XML_ELEMENT_NODE !== $c->nodeType ) { continue; }
                if ( $has( $c, 'uc-section-head' ) ) { $head = true; }
                if ( $has( $c, 'uc-card' ) ) { $card = true; }
            }
            $name = $s->getAttribute( 'id' ) ? '#' . $s->getAttribute( 'id' ) : sweep_label( $s );
            if ( ! $head ) { $out[] = "B: the section $name has no .uc-section-head"; }
            if ( ! $card ) { $out[] = "B: the section $name draws its content outside a .uc-card"; }
        }
    }
    /* C */
    foreach ( $x->query( '//*[@class]' ) as $el ) {
        $cls = sweep_classes( $el );
        foreach ( $cls as $c ) {
            if ( ! preg_match( '/^uc-[a-z0-9-]*card$/', $c ) || 'uc-card' === $c || 'uc-bento-card' === $c ) { continue; }
            if ( in_array( 'uc-card', $cls, true ) || in_array( 'uc-bento-card', $cls, true ) || isset( $cards_allowed[ $c ] ) ) { continue; }
            $out[] = 'C: a card that is not the shared card: ' . sweep_label( $el );
        }
    }
    /* D */
    foreach ( $x->query( '//button[@type="submit"] | //input[@type="submit"]' ) as $b ) {
        $prev = $b->previousSibling;
        while ( $prev && ( XML_ELEMENT_NODE !== $prev->nodeType || ( 'input' === $prev->nodeName && 'hidden' === $prev->getAttribute( 'type' ) ) ) ) {
            $prev = $prev->previousSibling;
        }
        if ( $prev && in_array( 'uc-field', sweep_classes( $prev ), true ) ) {
            $out[] = 'D: ' . sweep_label( $b ) . ' sits directly after a field, with no action row: put it in one';
        }
    }
    return array_values( array_unique( $out ) );
}

/* ---- Self-test: each rule fails on the thing it is for. ------------------ */
if ( in_array( '--self-test', $args, true ) ) {
    $defined = array_flip( array( 'uc-btn', 'uc-card', 'uc-section', 'uc-section-head', 'uc-field', 'uc-cat-form-actions' ) );
    $good = '<section class="uc-section"><div class="uc-section-head"><h2>Tags</h2></div><div class="uc-card"><form>'
        . '<label class="uc-field">Name <input type="text" name="n"></label><div class="uc-cat-form-actions"><button type="submit" class="uc-btn">Add</button></div>'
        . '</form></div></section>';
    $cases = array(
        'A' => '<button type="submit">Add</button>',
        'A2' => '<button type="button" class="uc-made-up">Add</button>',
        'B' => '<section><h2>Loose</h2></section>',
        'B2' => '<section class="uc-section"><h2>No head</h2><div class="uc-card"></div></section>',
        'B3' => '<section class="uc-section"><div class="uc-section-head"></div><ul></ul></section>',
        'C' => '<div class="uc-thing-card">x</div>',
        'D' => '<form><label class="uc-field">New tag <input type="text"></label><button type="submit" class="uc-btn">Add tag</button></form>',
    );
    $bad = array();
    if ( sweep_page( $good, $defined, array() ) ) { $bad[] = 'a page built from the components fails: ' . implode( '; ', sweep_page( $good, $defined, array() ) ); }
    foreach ( $cases as $k => $html ) {
        $got = sweep_page( $html, $defined, array() );
        if ( ! $got || 0 !== strpos( $got[0], substr( $k, 0, 1 ) . ':' ) ) { $bad[] = "rule $k did not fail on: $html"; }
    }
    if ( sweep_page( '<div class="uc-login-card"></div>', $defined, array( 'uc-login-card' => 'x' ) ) ) { $bad[] = 'an allowed card was reported'; }
    if ( sweep_page( '<form class="bar"><input type="search"><button type="submit" class="uc-btn">Search</button></form>', $defined, array() ) ) { $bad[] = 'an inline search bar was reported as a missing action row'; }
    if ( $bad ) { echo "SELF-TEST FAILED:\n  - " . implode( "\n  - ", $bad ) . "\n"; exit( 1 ); }
    echo "self-test passed: each of A, B, C and D fails on its fault, and passes the components and the allowed cases.\n";
    exit( 0 );
}

/* ---- Every caladmin screen, rendered. ------------------------------------ */
$wrote = shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/caps-sweep-live.php' ) . ' 2>&1' );
if ( false === strpos( (string) $wrote, 'wrote ' ) ) { echo "the screens could not be rendered:\n$wrote\n"; exit( 1 ); }
$defined = sweep_defined( (string) file_get_contents( $root . '/public/css/portal.css' ) );
$pages   = glob( __DIR__ . '/caps-sweep-live-caladmin-*.html' );
$fails   = array();
if ( count( $pages ) < 20 ) { $fails[] = 'only ' . count( $pages ) . ' caladmin screens were rendered, so this swept almost nothing'; }
$buttons = 0;
foreach ( $pages as $f ) {
    $html = (string) file_get_contents( $f );
    $buttons += preg_match_all( '/<button\b/', $html );
    foreach ( sweep_page( $html, $defined, $CARDS_ALLOWED ) as $why ) {
        $fails[] = str_replace( 'caps-sweep-live-', '', basename( $f, '.html' ) ) . ': ' . $why;
    }
}

echo "\nThe component sweep: every caladmin screen\n" . str_repeat( '=', 72 ) . "\n";
echo count( $pages ) . " screens, $buttons buttons, read for unstyled buttons, loose sections, one-off cards\n";
echo "and buttons with no action row.\n";
echo "Card components allowed by name:\n";
foreach ( $CARDS_ALLOWED as $c => $why ) { echo "  .$c  $why\n"; }
if ( $fails ) { echo "\n" . count( $fails ) . " problem(s):\n  - " . implode( "\n  - ", $fails ) . "\n"; exit( 1 ); }
echo "\nevery screen is built from the shared section, card, field and button components.\n";
exit( 0 );
