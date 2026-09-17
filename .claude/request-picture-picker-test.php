<?php
/**
 * THE STAFF FORM'S PICTURE PICKER, DECIDED BY RENDERING IT (3.65.0).
 *
 *     php .claude/request-picture-picker-test.php
 *     php .claude/request-picture-picker-test.php --self-test
 *
 * WHAT THIS REPLACED AND WHY IT NEEDS A TEST OF ITS OWN. The staff request form
 * drew every picture in the calendar folder at once, as a grid of thumbnails,
 * capped at 60. That is fine at a dozen and unusable at two hundred, and the
 * folder only grows. It is a disclosure with a search box and a scrollable list
 * now.
 *
 * THE ASSERTION THAT MATTERS MOST IS THE ONE ABOUT NO JAVASCRIPT. This page is
 * reached by a link, by staff who are not logged in to WordPress, on whatever
 * browser they have. A control that posts nothing without scripting would be a
 * form somebody cannot complete, and nobody would find out from a screenshot.
 * So the questions here are written as what a person WITHOUT SCRIPT can do:
 *
 *   1. There is a control named image_id, and it is radio buttons.
 *   2. Every picture in the folder is one of them, and each carries its own
 *      attachment id, so a choice submits the right picture.
 *   3. Nothing is `disabled` and no part of the list is `hidden`, so the
 *      browser will post whatever was chosen.
 *   4. The thing that opens and closes is a native <details>, which needs no
 *      script, and the list is INSIDE it, which is what makes 1 to 3 true
 *      whether it is open or shut.
 *   5. The search box has no name, so it posts nothing, and it is hidden until
 *      portal.js can act on it.
 *
 * AND THE THINGS A PERSON LOOKS FOR:
 *
 *   6. Every row shows its FILE NAME as text, not in an attribute and not on
 *      hover, because that is what tells two similar photographs apart and it
 *      is what the search matches.
 *   7. The closed trigger says which picture is chosen, or that none is.
 *   8. The folder is not widened: the query is the same calendar-folder one.
 *
 * DECIDED BY PARSING, NOT BY GREP. "Does the page contain name=image_id" would
 * pass on a commented-out control and on the word inside a JSON payload. Only a
 * parser knows what a form control is, which is the 3.64.1 lesson.
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
 * WordPress, in miniature. Only what render_image_choice() touches.
 * ------------------------------------------------------------------------ */

function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $t ) { return esc_html( $t ); }
function esc_url( $t ) { return (string) $t; }
function esc_url_raw( $t, $p = null ) { return (string) $t; }
function esc_textarea( $t ) { return esc_html( $t ); }
function __( $t, $d = '' ) { return $t; }
function _e( $t, $d = '' ) { echo $t; }
function wp_unslash( $v ) { return $v; }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $k ) ); }
function add_action( $h, $cb, $p = 10, $a = 1 ) { return true; }
function add_query_arg( $a, $v = '', $u = '' ) { return (string) $u; }
function home_url( $p = '', $s = null ) { return 'https://example.org' . $p; }
function is_wp_error( $t ) { return ( $t instanceof WP_Error ); }
function is_email( $e ) { return (bool) filter_var( (string) $e, FILTER_VALIDATE_EMAIL ); }
function wp_kses( $s, $a, $p = array() ) { return (string) $s; }
function wp_kses_post( $s ) { return (string) $s; }
function wp_strip_all_tags( $t, $b = false ) { return trim( strip_tags( (string) $t ) ); }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function get_transient( $k ) { return false; }
function set_transient( $k, $v, $t = 0 ) { return true; }
function get_option( $n, $d = false ) { return $d; }
function update_option( $n, $v, $a = null ) { return true; }
function delete_option( $n ) { return true; }
function sfaf_ap_date( $d, $f = 'full' ) { return (string) $d; }

class WP_Error {}

function __checked_selected_helper( $a, $b, $echo, $type ) {
    $out = ( (string) $a === (string) $b || $a == $b ) ? " $type='$type'" : '';
    if ( $echo ) { echo $out; }
    return $out;
}
function checked( $a, $b = true, $e = true ) { return __checked_selected_helper( $a, $b, $e, 'checked' ); }
function selected( $a, $b = true, $e = true ) { return __checked_selected_helper( $a, $b, $e, 'selected' ); }

/*
 * THE LIBRARY. Deliberately awkward, and every title is one WordPress could
 * really produce:
 *
 *   41  a title a person typed.
 *   42  WordPress' own fallback, which is the file with the extension dropped
 *       and the dashes turned into spaces. Not a title, and saying so is an
 *       exact question rather than a guess.
 *   43  never titled at all.
 *   45  the same fallback on a camera file name, which is the shape the older
 *       digits-against-letters guess was written for.
 *   44  a picture WordPress cannot size, so there is no thumbnail to draw.
 *
 * 42 and 43 differ only in a suffix, which is the case a thumbnail alone
 * cannot answer and the whole reason the file name is on every row.
 */
$GLOBALS['library'] = array(
    41 => array( 'file' => 'calendar/prep-clinic-open-day-1200x675.jpg', 'title' => 'PrEP clinic open day' ),
    42 => array( 'file' => 'calendar/harm-reduction-2026-a.jpg',         'title' => 'harm reduction 2026 a' ),
    43 => array( 'file' => 'calendar/harm-reduction-2026-b.jpg',         'title' => '' ),
    44 => array( 'file' => 'calendar/no-thumbnail-here.jpg',             'title' => 'Cannot be sized' ),
    45 => array( 'file' => 'calendar/dsc_0043.jpg',                      'title' => 'dsc 0043' ),
);

/* What the query was asked for, so the folder restriction is decided by the
   arguments rather than assumed from the result. */
$GLOBALS['queries'] = array();

function get_posts( $args ) {
    $GLOBALS['queries'][] = $args;
    $out = array();
    foreach ( $GLOBALS['library'] as $id => $row ) {
        $out[] = (object) array( 'ID' => $id );
    }
    return $out;
}

/*
 * THE PICKER ASKS THROUGH WP_Query NOW (3.74.0), because the media screen it
 * shares its query builder with has to page and a bare get_posts() does not
 * hand back a total. The arguments are the same arguments and are recorded the
 * same way, so section 6 below still decides the folder restriction by reading
 * what was ASKED rather than by trusting what came back.
 */
class WP_Query {
    public $posts = array();
    public $found_posts = 0;
    public $max_num_pages = 1;
    public function __construct( $args ) {
        $GLOBALS['queries'][] = $args;
        foreach ( $GLOBALS['library'] as $id => $row ) {
            $this->posts[] = (object) array( 'ID' => $id );
        }
        $this->found_posts = count( $this->posts );
    }
}
function wp_list_pluck( $list, $field ) {
    $out = array();
    foreach ( (array) $list as $item ) {
        $out[] = is_object( $item ) ? $item->$field : $item[ $field ];
    }
    return $out;
}
/* No series on anything, which is the staff form's ordinary case: the picker
 * groups only when the event has a series AND something carries it. */
function get_the_terms( $id, $tax ) { return array(); }
function get_post_meta( $id, $k, $single = false ) {
    if ( '_wp_attached_file' === $k && isset( $GLOBALS['library'][ $id ] ) ) {
        return $GLOBALS['library'][ $id ]['file'];
    }
    return '';
}
function get_the_title( $id = 0 ) {
    return isset( $GLOBALS['library'][ $id ] ) ? $GLOBALS['library'][ $id ]['title'] : '';
}
function wp_get_attachment_image_url( $id, $size = 'thumbnail' ) {
    // 44 is the picture WordPress cannot size, and a row with no thumbnail is
    // dropped rather than rendered as a broken frame.
    if ( 44 === (int) $id ) { return false; }
    return 'https://example.org/wp-content/uploads/' . str_replace( '.jpg', '-300x169.jpg', $GLOBALS['library'][ $id ]['file'] );
}

function sfaf_icon( $name, $args = array() ) {
    return '<svg class="uc-icon uc-icon-' . esc_attr( $name ) . '" aria-hidden="true"></svg>';
}

class SFAF_Media_Folder {
    const FOLDER = 'calendar';
    public static function prefix() { return 'calendar/'; }
    public static function has_any() { return ! empty( $GLOBALS['library'] ); }
    public static function holds( $id ) { return isset( $GLOBALS['library'][ (int) $id ] ); }
}
class SFAF_Reminders {
    public static function new_token() { return str_repeat( 'a', 32 ); }
}
class SFAF_Series {
    const TAXONOMY = 'uc_series';
    public static function all() { return array(); }
    public static function set_for_event( $a, $b ) {}
}
class SFAF_Venues {
    public static function set_for_event( $a, $b ) {}
    public static function exists( $id ) { return false; }
}
class SFAF_Portal {
    public static function link( $p = '' ) { return 'https://example.org/caladmin/' . $p; }
}
class SFAF_Rich_Text {
    public static function sanitize( $v ) { return (string) $v; }
    public static function to_plain( $v ) { return trim( strip_tags( (string) $v ) ); }
    public static function enqueue() {}
    public static function render( $id, $name, $content, $args = array() ) {}
    public static function deferred( $name, $content, $args = array() ) {}
}
class SFAF_Turnstile {
    public static function field() {}
    public static function verify() { return true; }
}
class SFAF_Uploads {
    const MAX_BYTES = 10485760;
}

/*
 * THE UPLOAD FIELD IS STUBBED AND THE PICKER IS NOT, and the line between them
 * is the scope of this release. "Or send your own" belongs to SFAF_Submissions
 * and is untouched here; what must be proved is that it is still rendered
 * beside the picker, which a stub that emits a marker can answer.
 */
class SFAF_Submissions {
    public static function image_field( $error = '' ) {
        echo '<input type="file" name="uc_image" data-uc-test-upload-field />';
    }
    /* The extra pictures (3.96.0). Stubbed like the featured one and for the
     * same reason: what must be proved here is that it is rendered beside the
     * picker, and .claude/extra-images-test.php owns what it contains. */
    public static function extra_images_field( $errors = array() ) {
        echo '<input type="file" name="uc_image_extra_1" data-uc-test-extra-field />';
        echo '<input type="file" name="uc_image_extra_2" data-uc-test-extra-field />';
    }
    public static function page_open( $t, $a = array() ) {}
    public static function page_close( $a = array() ) {}
}
class SFAF_Submit {
    public static function clean_faqs( $rows ) { return array(); }
}
class SFAF_FAQ_Sets {
    public static function all() { return array(); }
    public static function get( $id ) { return null; }
}

require_once $root . '/includes/class-sfaf-media.php';
require_once $root . '/includes/class-sfaf-request.php';

/* ---------------------------------------------------------------------------
 * RENDERING IT, AND READING WHAT CAME BACK.
 * ------------------------------------------------------------------------ */

$render = new ReflectionMethod( 'SFAF_Request', 'render_image_choice' );
$render->setAccessible( true );

/**
 * @param int $chosen The attachment id already on the request, or 0.
 * @return string
 */
function render_picker( $chosen = 0 ) {
    global $render;
    $depth = ob_get_level();
    ob_start();
    try {
        $render->invoke( null, $chosen, '' );
    } catch ( Throwable $e ) {
        while ( ob_get_level() > $depth ) { ob_end_clean(); }
        fail( 'the picker could not be rendered at all: ' . get_class( $e ) . ' ' . $e->getMessage()
            . ' at ' . basename( $e->getFile() ) . ':' . $e->getLine() );
        return '';
    }
    $html = '';
    while ( ob_get_level() > $depth ) { $html = ob_get_clean() . $html; }
    return $html;
}

/** Parse a fragment and hand back a DOMXPath over it. */
function reader( $html ) {
    $doc = new DOMDocument();
    libxml_use_internal_errors( true );
    $doc->loadHTML( '<?xml encoding="utf-8" ?><div>' . $html . '</div>' );
    libxml_clear_errors();
    return new DOMXPath( $doc );
}

/** @return DOMElement[] */
function nodes( $html, $xpath ) {
    if ( '' === trim( $html ) ) { return array(); }
    $out = array();
    foreach ( reader( $html )->query( $xpath ) as $el ) { $out[] = $el; }
    return $out;
}

/**
 * The first node an XPath matches, or a stand-in that answers every question
 * with nothing.
 *
 * WHY A STAND-IN AND NOT AN INDEX. A missing element is exactly what a planted
 * fault looks like, and [0]->getAttribute() on nothing is a fatal that stops
 * the run at the FIRST failure and hides every one after it. The named
 * assertion is the thing worth reading.
 */
function first( $html, $xpath ) {
    $found = nodes( $html, $xpath );
    if ( $found ) {
        return $found[0];
    }
    return new class {
        public function getAttribute( $n ) { return '(no such element)'; }
        public function hasAttribute( $n ) { return false; }
        public function __get( $n ) { return ''; }
    };
}

/** The visible text of an element, with runs of whitespace collapsed. */
function seen( DOMElement $el ) {
    return trim( preg_replace( '/\s+/', ' ', $el->textContent ) );
}

/* =========================================================================
 * THE READER, PROVED BEFORE ANYTHING RESTS ON IT.
 * ====================================================================== */

if ( $self ) {
    $ok = true;
    $probe = function ( $label, $got, $want ) use ( &$ok ) {
        $good = ( $got === $want );
        printf( "  %s  %-58s got %s want %s\n", $good ? 'ok  ' : 'FAIL', $label,
            var_export( $got, true ), var_export( $want, true ) );
        $ok = $ok && $good;
    };

    $probe( 'finds a radio by name',
        count( nodes( '<input type="radio" name="image_id" value="1" />', '//input[@name="image_id"]' ) ), 1 );
    $probe( 'does not find the name in prose',
        count( nodes( '<p>name="image_id"</p>', '//input[@name="image_id"]' ) ), 0 );
    $probe( 'does not find a commented-out control',
        count( nodes( '<!-- <input type="radio" name="image_id" /> -->', '//input[@name="image_id"]' ) ), 0 );
    $probe( 'sees a control nested inside a details',
        count( nodes( '<details><input name="image_id" /></details>', '//details//input[@name="image_id"]' ) ), 1 );
    $probe( 'knows a control OUTSIDE the details is outside it',
        count( nodes( '<details></details><input name="image_id" />', '//details//input[@name="image_id"]' ) ), 0 );
    $probe( 'sees the checked attribute',
        nodes( "<input name='image_id' checked='checked' />", '//input' )[0]->hasAttribute( 'checked' ), true );
    $probe( 'reads visible text and collapses the whitespace',
        seen( nodes( "<span>  a\n  b  </span>", '//span' )[0] ), 'a b' );
    $probe( 'does not read an attribute as visible text',
        seen( nodes( '<span data-name="hidden-thing"></span>', '//span' )[0] ), '' );
    $probe( 'finds an element by class among several',
        count( nodes( '<span class="a uc-image-option-name b">x</span>',
            '//*[contains(concat(" ", normalize-space(@class), " "), " uc-image-option-name ")]' ) ), 1 );
    $probe( 'does not match a class that is only a prefix',
        count( nodes( '<span class="uc-image-option-nameplate">x</span>',
            '//*[contains(concat(" ", normalize-space(@class), " "), " uc-image-option-name ")]' ) ), 0 );

    echo "\n" . ( $ok ? 'the reader can see what it is looking for.' : 'THE READER IS BROKEN.' ) . "\n";
    exit( $ok ? 0 : 1 );
}

/* =========================================================================
 * 1. A CONTROL NAMED image_id, AND IT IS RADIO BUTTONS.
 * ====================================================================== */

$html = render_picker( 0 );

expect( 'the picker rendered at all', strlen( $html ) > 400, true );

$controls = nodes( $html, '//input[@name="image_id"]' );
expect( 'every control named image_id is a radio',
    array_values( array_unique( array_map( function ( $c ) { return $c->getAttribute( 'type' ); }, $controls ) ) ),
    array( 'radio' ) );

/* Three pictures, because 44 cannot be sized and a row with no thumbnail is
   dropped rather than drawn as a broken frame. Plus "No picture". */
expect( 'one radio per picture, plus no picture', count( $controls ), 5 );

$values = array_map( function ( $c ) { return $c->getAttribute( 'value' ); }, $controls );
expect( 'each carries its own attachment id', $values, array( '0', '41', '42', '43', '45' ) );

/* =========================================================================
 * 2. IT WORKS WITH NO JAVASCRIPT.
 *
 * Not "the script is careful": the control has to be complete before any
 * script runs, because on this page there may not be one.
 * ====================================================================== */

expect( 'the thing that opens and closes is a native <details>',
    count( nodes( $html, '//details' ) ), 1 );
expect( 'and it is opened by a native <summary>',
    count( nodes( $html, '//details/summary' ) ), 1 );
expect( 'every radio is INSIDE it, so it posts whether open or shut',
    count( nodes( $html, '//details//input[@name="image_id"]' ) ), count( $controls ) );

expect( 'nothing is disabled', count( nodes( $html, '//input[@disabled]' ) ), 0 );
expect( 'no row is hidden from the browser',
    count( nodes( $html, '//input[@name="image_id"]/ancestor::*[@hidden]' ) ), 0 );
expect( 'the panel is not hidden either',
    count( nodes( $html, '//*[contains(concat(" ", normalize-space(@class), " "), " uc-picker-panel ")][@hidden]' ) ), 0 );

/* The <details> is not open on arrival, which is the whole point of it, and
   that is a fact about the markup rather than about any script. */
expect( 'it starts closed', first( $html, '//details' )->hasAttribute( 'open' ), false );

/* =========================================================================
 * 3. THE SEARCH BOX POSTS NOTHING, AND IS NOT SHOWN UNTIL IT CAN WORK.
 * ====================================================================== */

expect( 'there is one search box', count( nodes( $html, '//input[@type="search"]' ) ), 1 );
expect( 'it has no name, so it posts nothing',
    first( $html, '//input[@type="search"]' )->hasAttribute( 'name' ), false );
expect( 'and it is the shared filter, not a second one',
    first( $html, '//input[@type="search"]' )->hasAttribute( 'data-uc-filter' ), true );
expect( 'the list it narrows is marked for that filter',
    count( nodes( $html, '//*[@data-uc-filter-list]' ) ), 1 );
expect( 'and the scope that pairs them is on the panel',
    count( nodes( $html, '//*[@data-uc-filter-scope]//*[@data-uc-filter-list]' ) ), 1 );
expect( 'there is a line for when nothing matches',
    count( nodes( $html, '//*[@data-uc-filter-empty]' ) ), 1 );

/*
 * HIDDEN BY THE STYLESHEET, REVEALED BY THE SCRIPT. Two files have to agree,
 * so both are asked. A search box visible on a page with no script is a
 * control that lies about what it will do.
 */
$css = file_get_contents( $root . '/public/css/portal.css' );
$js  = file_get_contents( $root . '/public/js/portal.js' );
expect( 'the box is wrapped in the class the stylesheet hides',
    count( nodes( $html, '//*[contains(concat(" ", normalize-space(@class), " "), " uc-image-search ")]//input[@type="search"]' ) ), 1 );
expect( 'the stylesheet hides it', (bool) strpos( $css, '.uc-image-search { display: none; }' ), true );
expect( 'and reveals it only once the picker is live',
    (bool) strpos( $css, '.uc-image-picker-live .uc-image-search { display: block; }' ), true );
expect( 'which portal.js is what marks',
    (bool) strpos( $js, "picker.classList.add('uc-image-picker-live');" ), true );

/* =========================================================================
 * 4. EVERY ROW SHOWS ONE NAME, AS TEXT: THE TITLE OR THE FILE, NEVER BOTH.
 *
 * The reason this control exists. An attribute is not a name somebody can
 * read, and neither is a title attribute that appears on hover.
 *
 * WHICH ONE EACH ROW GETS IS THE WHOLE ASSERTION (3.67.0). 41 was titled by a
 * person and shows that title. 42's and 45's titles are what WordPress makes
 * of a file name, and 43 has no title at all, so all three show the file. A
 * row that showed both was two lines for one picture and a line with a gap
 * above it for the next.
 * ====================================================================== */

$names = array_map( 'seen', nodes( $html,
    '//*[contains(concat(" ", normalize-space(@class), " "), " uc-image-option-name ")]' ) );
expect( 'one name on every row, and on the no-picture row',
    $names,
    array( 'No picture', 'PrEP clinic open day', 'harm-reduction-2026-a.jpg', 'harm-reduction-2026-b.jpg', 'dsc_0043.jpg' ) );

/* Two pictures whose names differ only in a suffix are still told apart, which
   is the case a thumbnail alone cannot answer. */
expect( 'two similar pictures are distinguishable by what is on the screen',
    count( array_unique( $names ) ), count( $names ) );

$thumbs = nodes( $html, '//img[contains(concat(" ", normalize-space(@class), " "), " uc-image-option-thumb ")]' );
expect( 'every picture row carries a thumbnail', count( $thumbs ), 4 );
expect( 'and the thumbnails are decorative, because the name is beside them',
    array_values( array_unique( array_map( function ( $t ) { return $t->getAttribute( 'alt' ); }, $thumbs ) ) ),
    array( '' ) );

/*
 * AND NOTHING DRAWS A SECOND LINE. The two classes the old two-line row used
 * are gone from the rendered markup entirely, so a row cannot quietly grow its
 * file name back under a title.
 */
expect( 'no row carries a separate title line', count( nodes( $html,
    '//*[contains(concat(" ", normalize-space(@class), " "), " uc-image-option-title ")]' ) ), 0 );
expect( 'and no row carries a separate file line', count( nodes( $html,
    '//*[contains(concat(" ", normalize-space(@class), " "), " uc-image-option-file ")]' ) ), 0 );

/*
 * THE FALLBACK IS DECIDED AGAINST THE FILE, and this is the question the
 * function's own docblock got wrong for eighteen releases: "img 2847 final v3"
 * has nine letters against five digits and no extension, so neither guess
 * caught it. Given the file it is exact.
 */
/* PUBLIC ON SFAF_Media SINCE 3.74.0, so no reflection is needed to ask it. */
$is_file = function ( $title, $file = '' ) {
    return SFAF_Media::looks_like_a_filename( $title, $file );
};
expect( "WordPress' fallback is not a title",
    $is_file( 'harm reduction 2026 a', 'harm-reduction-2026-a.jpg' ), true );
expect( 'underscores too', $is_file( 'dsc 0043', 'dsc_0043.jpg' ), true );
expect( 'and the case it is written in does not matter',
    $is_file( 'PrEP Clinic Open Day', 'prep-clinic-open-day.jpg' ), true );
expect( 'a title somebody typed is kept',
    $is_file( 'PrEP clinic open day', 'prep-clinic-open-day-1200x675.jpg' ), false );
expect( 'a title that is nearly the file is still a title',
    $is_file( 'Harm reduction, 2026', 'harm-reduction-2026-a.jpg' ), false );
expect( 'the older guesses still work with no file to compare',
    array( $is_file( 'board-photo.jpg' ), $is_file( 'dsc_0043' ), $is_file( 'Board photo' ) ),
    array( true, true, false ) );

/* The search matches the file name, so the haystack has to hold it. */
$hay = array_map( function ( $n ) { return $n->getAttribute( 'data-uc-filter-text' ); },
    nodes( $html, '//*[@data-uc-filter-text]' ) );
expect( 'search matches the file names, lowercased',
    $hay,
    array(
        'no picture',
        'prep-clinic-open-day-1200x675.jpg prep clinic open day',
        'harm-reduction-2026-a.jpg',
        'harm-reduction-2026-b.jpg',
        'dsc_0043.jpg',
    ) );

/* =========================================================================
 * 5. THE CLOSED TRIGGER SAYS WHAT IS CHOSEN.
 * ====================================================================== */

$current = nodes( $html, '//summary//*[@data-uc-image-current]' );
expect( 'the trigger carries the current selection', count( $current ), 1 );
if ( 1 === count( $current ) ) {
    expect( 'with nothing chosen it says so', seen( $current[0] ), 'No picture chosen' );
    expect( 'and shows no thumbnail', count( nodes( $html, '//summary//img' ) ), 0 );
}
expect( 'nothing is chosen means the no-picture radio is the checked one',
    first( $html, '//input[@name="image_id"][@checked]' )->getAttribute( 'value' ), '0' );

$picked = render_picker( 42 );
$pcur   = nodes( $picked, '//summary//*[@data-uc-image-current]' );
expect( 'a chosen picture is named on the trigger', count( $pcur ) ? seen( $pcur[0] ) : '',
    'harm-reduction-2026-a.jpg' );
expect( 'and shown on it', count( nodes( $picked, '//summary//img' ) ), 1 );
expect( 'exactly one radio is checked',
    count( nodes( $picked, '//input[@name="image_id"][@checked]' ) ), 1 );
expect( 'and it is the one that was chosen',
    first( $picked, '//input[@name="image_id"][@checked]' )->getAttribute( 'value' ), '42' );

/* The trigger's words come from the row, so the closed control and the open one
   cannot describe the same picture differently. */
$opt = first( $picked, '//input[@name="image_id"][@value="42"]' );
expect( 'the script is handed the same name the trigger shows',
    $opt->getAttribute( 'data-uc-image-name' ), 'harm-reduction-2026-a.jpg' );
expect( 'and the same thumbnail',
    $opt->getAttribute( 'data-uc-image-thumb' ),
    'https://example.org/wp-content/uploads/calendar/harm-reduction-2026-a-300x169.jpg' );

$titled = render_picker( 41 );
$tcur   = nodes( $titled, '//summary//*[@data-uc-image-current]' );
expect( 'a titled picture is named by its title', count( $tcur ) ? seen( $tcur[0] ) : '',
    'PrEP clinic open day' );

/* =========================================================================
 * 6. THE FOLDER IS NOT WIDENED.
 * ====================================================================== */

$q = $GLOBALS['queries'][0];
expect( 'attachments only', $q['post_type'], 'attachment' );
expect( 'images only', $q['post_mime_type'], 'image' );
expect( 'and the calendar folder only',
    $q['meta_query'][0]['key'] . '|' . $q['meta_query'][0]['value'] . '|' . $q['meta_query'][0]['compare'],
    '_wp_attached_file|^calendar/|REGEXP' );

/* =========================================================================
 * 7. WHAT WAS BESIDE IT IS STILL BESIDE IT.
 *
 * "Or send your own" is SFAF_Submissions' field and is not in scope, so the
 * assertion is that it is still rendered rather than anything about it.
 * ====================================================================== */

expect( 'the upload field is still on the form',
    count( nodes( $html, '//*[@data-uc-test-upload-field]' ) ), 1 );

/*
 * THE PICTURE IS A SECTION NOW (3.66.0), not a field label.
 *
 * Both request forms were black text at one size: a heading over a GROUP of
 * fields was set at .uc-field-label, 13/600, which is the step the labels of
 * the fields INSIDE that group are already at, so a heading and the thing it
 * headed rendered identically. The Subhead step, 16/600, was sitting unused.
 * This fieldset heads two fields, the picker and the upload, so it takes it.
 */
$legend = nodes( $html, '//fieldset/legend' );
expect( 'the picture fieldset has one legend', count( $legend ), 1 );
if ( 1 === count( $legend ) ) {
    expect( 'and it is a section heading rather than a field label',
        $legend[0]->getAttribute( 'class' ), 'uc-field-group-title' );
    expect( 'saying what the section is', seen( $legend[0] ), 'Event Image' );
}
$fieldset_class = (string) nodes( $html, '//fieldset' )[0]->getAttribute( 'class' );
expect( 'the fieldset takes the section treatment',
    false !== strpos( $fieldset_class, 'uc-form-section-group' ), true );
/*
 * AND NOT .uc-field WITH IT (3.68.0). `.uc-request-card fieldset.uc-field` is
 * (0,2,1) and sets `border: 0; padding: 0`; `.uc-form-section-group` is (0,1,0).
 * One class loses to one class plus one type, so while this fieldset carried
 * both it drew no boundary and had no top padding, and the form's picture
 * section ran straight on from the field above it.
 */
expect( 'and does not carry .uc-field, which would strip its boundary',
    false !== strpos( ' ' . $fieldset_class . ' ', ' uc-field ' ), false );
expect( 'and the way out is still named',
    (bool) strpos( $html, 'Roxane Chicoine' ), true );

/* An empty folder offers no picker at all, rather than an empty one. */
$GLOBALS['library'] = array();
$empty = render_picker( 0 );
expect( 'an empty folder renders no picker', count( nodes( $empty, '//details' ) ), 0 );
expect( 'and no control to post', count( nodes( $empty, '//input[@name="image_id"]' ) ), 0 );
expect( 'it says who to ask instead', (bool) strpos( $empty, 'Ask Roxane Chicoine' ), true );

/* ===================================================================== */

if ( $fails ) {
    echo 'PICTURE PICKER: ' . count( $fails ) . ' FAILURE' . ( 1 === count( $fails ) ? '' : 'S' ) . "\n";
    foreach ( $fails as $f ) { echo "  - $f\n"; }
    exit( 1 );
}

echo "PICTURE PICKER\n";
echo "  no script    a native <details> of radios named image_id, none disabled, none hidden\n";
echo "  the rows     a thumbnail and the file name, visible, on every one\n";
echo "  the trigger  names the chosen picture, or says none is\n";
echo "  the search   posts nothing and is hidden until portal.js can filter\n";
echo "  the source   still the calendar folder, unwidened\n";
echo "  decided by rendering the control and parsing what came back.\n";
exit( 0 );
