<?php
/**
 * THE PICKER HIDES WHAT IS NOT IN THE CHOSEN SERIES (3.80.0).
 *
 * WHY THIS RENDERS RATHER THAN GREPS. The fault reported was "choosing a series
 * still shows every image", with two pictures tagged to the series and eight in
 * the folder. Every check that could be written over the SOURCE would have
 * passed: the filtering code was present, it was reachable, and it did group
 * the rows correctly. It grouped them. The list still had eight pictures in it.
 *
 * So this runs SFAF_Media::picker() against a stubbed WordPress and COUNTS THE
 * OPTIONS IN THE MARKUP, which is the only question anybody was asking.
 *
 *     php .claude/picker-filter-test.php
 *
 * WHAT IT STUBS. WordPress, and only what picker() and row() call. SFAF_Media
 * is the real file.
 *
 * WHAT IT CANNOT PROVE. That the staff form's live narrowing works: that half
 * is portal.js reacting to a <select>, there is no browser here, and the part
 * this file does assert about it is structural. TESTING.md carries the press.
 */

$root = dirname( __DIR__ );
define( 'ABSPATH', __DIR__ );

/* ---------------------------------------------------------------------------
 * WordPress, in miniature. Signatures match the real ones, optional arguments
 * included: a stub taking fewer arguments is read by the callable audit as the
 * definition, and it then reports every correct call site as an arity error.
 * ------------------------------------------------------------------------ */
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES, 'UTF-8' ); }
function checked( $a, $b = true, $echo = true ) {
    $out = ( (string) $a === (string) $b ) ? ' checked' : '';
    if ( $echo ) { echo $out; }
    return $out;
}
function sfaf_icon( $key, $args = array() ) { return '<svg class="sfaf-icon" data-i="' . $key . '"></svg>'; }

/* The library. Eight pictures; two of them tagged to series 12. */
$GLOBALS['uc_library'] = array(
    101 => array( 'file' => 'strut-clinic.jpg',     'title' => 'Strut Clinic',   'terms' => array( 12 ) ),
    102 => array( 'file' => 'strut-outside.jpg',    'title' => '',               'terms' => array( 12 ) ),
    103 => array( 'file' => 'cycle-to-zero.jpg',    'title' => 'Cycle To Zero',  'terms' => array( 34 ) ),
    104 => array( 'file' => 'aids-walk.jpg',        'title' => 'AIDS Walk',      'terms' => array( 34, 56 ) ),
    105 => array( 'file' => 'mobile-health.jpg',    'title' => 'Mobile Health',  'terms' => array( 56 ) ),
    106 => array( 'file' => 'untagged-one.jpg',     'title' => '',               'terms' => array() ),
    107 => array( 'file' => 'untagged-two.jpg',     'title' => '',               'terms' => array() ),
    /* THE TRAP THE SPACE PADDING EXISTS FOR: 121 contains 12. If the attribute
     * were matched as a bare substring this picture would appear under series
     * 12, which is a picture from another programme on a stranger's form. */
    108 => array( 'file' => 'other-programme.jpg',  'title' => 'Other Programme', 'terms' => array( 121 ) ),
);

function wp_get_attachment_image_url( $id, $size = 'thumbnail', $icon = false ) {
    return isset( $GLOBALS['uc_library'][ $id ] )
        ? 'https://example.org/wp-content/uploads/calendar/' . $size . '-' . $GLOBALS['uc_library'][ $id ]['file']
        : false;
}
function get_post_meta( $id, $key = '', $single = false ) {
    if ( '_wp_attached_file' === $key ) {
        return isset( $GLOBALS['uc_library'][ $id ] ) ? 'calendar/' . $GLOBALS['uc_library'][ $id ]['file'] : '';
    }
    return '';
}
function get_the_title( $id = 0 ) {
    return isset( $GLOBALS['uc_library'][ $id ] ) ? $GLOBALS['uc_library'][ $id ]['title'] : '';
}
function get_the_terms( $id, $taxonomy ) {
    $out = array();
    if ( ! isset( $GLOBALS['uc_library'][ $id ] ) ) { return $out; }
    foreach ( $GLOBALS['uc_library'][ $id ]['terms'] as $t ) {
        $term = new stdClass();
        $term->term_id = $t;
        $term->name    = 'Series ' . $t;
        $out[] = $term;
    }
    return $out;
}
function is_wp_error( $t ) { return false; }
function wp_list_pluck( $list, $field, $index_key = null ) {
    $out = array();
    foreach ( (array) $list as $item ) {
        $out[] = is_object( $item ) ? $item->$field : ( is_array( $item ) ? $item[ $field ] : $item );
    }
    return $out;
}

/* ---------------------------------------------------------------------------
 * pictures() IS STUBBED THROUGH WP_Query, NOT THROUGH A SUBCLASS.
 *
 * The first attempt overrode pictures() in a subclass and it never ran: picker()
 * calls `self::pictures()`, and `self::` is resolved at compile time against the
 * class the method is WRITTEN in, not the one it is called on. Only `static::`
 * would have dispatched to the child. Recording it here because the subclass
 * approach looks like it works and silently tests the real query instead.
 *
 * So the stub goes one level lower, at WP_Query, which is where the real method
 * gets its ids from anyway. That also means the real pictures() runs, including
 * its folder clause, rather than being replaced by something that agrees with
 * the test by construction.
 * ------------------------------------------------------------------------ */
class SFAF_Media_Folder {
    public static function prefix() { return 'calendar/'; }
    public static function has_any() { return true; }
}

class WP_Query {
    public $posts = array();
    public $found_posts = 0;
    public $max_num_pages = 1;
    public function __construct( $args = array() ) {
        /* OBJECTS WITH ->ID, BECAUSE THAT IS WHAT pictures() PLUCKS. A stub
         * handing back bare integers made wp_list_pluck() return them and then
         * row() was called with 0, which is a stub bug that looks exactly like
         * a fault in the thing under test. */
        foreach ( array_keys( $GLOBALS['uc_library'] ) as $id ) {
            $p = new stdClass();
            $p->ID = $id;
            $this->posts[] = $p;
        }
        $this->found_posts = count( $this->posts );
    }
}

class SFAF_Series {
    public static function image_url( $term_id, $size = 'medium' ) {
        return ( 12 === (int) $term_id ) ? 'https://example.org/series-12-' . $size . '.jpg' : '';
    }
}

/* ---------------------------------------------------------------------------
 * The real file, with the two methods this test does not exercise replaced.
 *
 * pictures() is a WP_Query over the calendar folder and tags_of() is a term
 * query; both are stubbed by the functions above being what the real row()
 * calls, EXCEPT pictures(), which is why SFAF_Media is loaded and then its
 * query method is fed from here rather than mocked around.
 * ------------------------------------------------------------------------ */
require_once $root . '/includes/class-sfaf-media.php';

if ( ! method_exists( 'SFAF_Media', 'picker' ) ) {
    echo "FAIL: SFAF_Media::picker() is gone. This file asserts nothing.\n";
    exit( 1 );
}

function render_picker( $args ) {
    ob_start();
    SFAF_Media::picker( $args );
    return ob_get_clean();
}

function options_in( $html ) {
    return preg_match_all( '/<input type="radio"[^>]*value="(\d+)"/', $html, $m ) ? $m[1] : array();
}

$fails = array();
$notes = array();

/* ---------------------------------------------------------------------------
 * 1. LOCKED, WITH PICTURES. Only that series' two, and nothing else.
 *    This is the reported fault, stated as a count.
 * ------------------------------------------------------------------------ */
$html = render_picker( array( 'name' => 'image_id', 'series' => 12, 'series_locked' => true ) );
$ids  = options_in( $html );
/* The "0" option is the no-picture / series-picture row and is not one of the
 * folder's pictures, so it is excluded from every count below. */
$offered = array_values( array_diff( $ids, array( '0' ) ) );

if ( array( '101', '102' ) !== $offered ) {
    $fails[] = 'locked to series 12 offered [' . implode( ', ', $offered ) . '] and should have offered [101, 102]. '
        . 'This is the 3.76.0 fault: ' . count( $offered ) . ' pictures in a list that should hold 2.';
} else {
    $notes[] = 'locked to a series with pictures: 2 offered out of 8 in the folder';
}
if ( false !== strpos( $html, 'uc-picker-group-head' ) ) {
    $fails[] = 'the locked picker still emits a group heading. It hides now; it does not group.';
}
if ( false === strpos( $html, 'value="0"' ) ) {
    $fails[] = 'the locked picker dropped the "no picture" row. That row is what makes '
        . '"they can still submit without an image" true.';
}

/* ---------------------------------------------------------------------------
 * 2. LOCKED, WITH NOTHING TAGGED. The message, not the whole folder.
 * ------------------------------------------------------------------------ */
$html = render_picker( array( 'name' => 'image_id', 'series' => 99, 'series_locked' => true ) );
$offered = array_values( array_diff( options_in( $html ), array( '0' ) ) );

if ( ! empty( $offered ) ) {
    $fails[] = 'a series with nothing tagged fell back to ' . count( $offered ) . ' pictures. '
        . 'A quiet fallback to the full list is indistinguishable from the filter not working, '
        . 'which is what was reported twice.';
} else {
    $notes[] = 'locked to a series with nothing tagged: 0 offered, message shown';
}

$wanted = 'No images are available for that series yet. Contact MarCom for an event image to be added.';
if ( false === strpos( $html, $wanted ) ) {
    $fails[] = 'the empty-series message is missing or reworded. It must read exactly: ' . $wanted;
}
/* Visible, not rendered-then-hidden: with the series locked there is no script
 * coming to reveal it. */
if ( preg_match( '/data-uc-image-none\s+hidden/', $html ) ) {
    $fails[] = 'the empty-series message is rendered hidden on a locked picker, where nothing will reveal it.';
}

/* ---------------------------------------------------------------------------
 * 3. NOT LOCKED. Every row written out, each carrying its own series, because
 *    the select can still change and portal.js does the hiding.
 * ------------------------------------------------------------------------ */
$html = render_picker( array( 'name' => 'image_id', 'series' => 12 ) );
$offered = array_values( array_diff( options_in( $html ), array( '0' ) ) );

if ( count( $offered ) !== count( $GLOBALS['uc_library'] ) ) {
    $fails[] = 'the unlocked picker wrote out ' . count( $offered ) . ' of ' . count( $GLOBALS['uc_library'] )
        . ' pictures. It must write them all: the series can change after load, and a row '
        . 'that is not in the document cannot be revealed by any script.';
} else {
    $notes[] = 'not locked: all 8 written out for the script to narrow';
}
if ( ! preg_match( '/data-uc-image-none[^>]*hidden/', $html ) ) {
    $fails[] = 'the unlocked picker shows the empty-series message on arrival. '
        . 'It must start hidden and be revealed only when a chosen series has nothing.';
}

/* ---------------------------------------------------------------------------
 * 4. THE SERIES ATTRIBUTE, AND THE SUBSTRING TRAP.
 * ------------------------------------------------------------------------ */
if ( ! preg_match_all( '/data-uc-image-series="([^"]*)"/', $html, $m ) ) {
    $fails[] = 'no option carries data-uc-image-series, so the script has nothing to filter on.';
} else {
    $seen = count( $m[1] );
    if ( $seen !== count( $GLOBALS['uc_library'] ) ) {
        $fails[] = 'only ' . $seen . ' of ' . count( $GLOBALS['uc_library'] ) . ' options carry their series. '
            . 'An option without it is hidden by every series, which silently removes a picture.';
    }
    foreach ( $m[1] as $val ) {
        if ( '' === $val ) { continue; }
        if ( ' ' !== $val[0] || ' ' !== substr( $val, -1 ) ) {
            $fails[] = 'data-uc-image-series="' . $val . '" is not padded with spaces at both ends. '
                . 'Without the padding a search for 12 matches 121, which offers another programme\'s picture.';
            break;
        }
    }
    /* The trap, stated as the actual pair rather than as a rule. */
    $padded_121 = ' 121 ';
    if ( false !== strpos( $padded_121, ' 12 ' ) ) {
        $fails[] = 'the padding does not separate 12 from 121, so the scheme itself is wrong.';
    } else {
        $notes[] = 'series ids are space padded: " 12 " does not match " 121 "';
    }
}

/* An untagged picture carries an empty value and is therefore hidden by every
 * series, which is the intended behaviour and worth asserting rather than
 * assuming: an untagged picture is one nobody has filed, not one for everybody. */
if ( ! preg_match( '/value="106"[^>]*>/', $html ) ) {
    $fails[] = 'the untagged picture is not in the unlocked markup at all.';
} elseif ( ! preg_match( '/data-uc-image-series=""[^>]*>[^<]*<input type="radio"[^>]*value="106"/s', $html )
        && ! preg_match( '/data-uc-image-series=""/', $html ) ) {
    $fails[] = 'no option carries an empty data-uc-image-series, so an untagged picture is not '
        . 'being marked as belonging to no series.';
} else {
    $notes[] = 'an untagged picture carries an empty series list and is hidden by every series';
}

/* ---------------------------------------------------------------------------
 * 5. ONE OWNER OF `hidden` IN portal.js.
 *
 * The series filter and the search filter act on the same rows. If both assign
 * .hidden, the one that runs last wins and typing in the search box un-hides
 * everything the series filter removed. This asserts that only initFilterLists()
 * ever assigns it on a filter row, and that the series filter uses the marker.
 * ------------------------------------------------------------------------ */
$js = file_get_contents( $root . '/public/js/portal.js' );

if ( false === strpos( $js, 'data-uc-off-series' ) ) {
    $fails[] = 'portal.js does not use the data-uc-off-series marker. If the series filter is '
        . 'assigning hidden directly it is fighting initFilterLists() over the same rows.';
} else {
    $notes[] = 'the series filter marks rows and initFilterLists() owns `hidden`';
}
if ( false === strpos( $js, "scope.addEventListener('uc:refilter'" ) ) {
    $fails[] = 'initFilterLists() does not listen for uc:refilter, so a series change marks rows '
        . 'and nothing ever re-reads them.';
}
/*
 * EXACTLY ONE PLACE ASSIGNS hidden ON A [data-uc-filter-text] ROW.
 *
 * SCOPED TO THOSE ROWS, AND THE FIRST VERSION OF THIS CHECK WAS NOT. It counted
 * `opt.hidden =` anywhere in the file and reported two, the second being the
 * people picker on the Notify card. That control loops over
 * [data-uc-picker-search] on a different screen and never touches these rows,
 * so it is not a second owner of anything: it owns its own list.
 *
 * A check that fails on a correct file is worse than no check, because the next
 * person to see it red will widen it until it goes green. So this looks only at
 * loops over the rows actually in question.
 */
$owners = 0;
$offsetp = 0;
while ( false !== ( $at = strpos( $js, '[data-uc-filter-text]', $offsetp ) ) ) {
    $offsetp = $at + 1;
    /* The body of the loop that follows, generously bounded. An assignment
     * further away than this is not in the same iteration. */
    $window = substr( $js, $at, 900 );
    if ( preg_match( '/\.hidden\s*=/', $window ) ) {
        $owners++;
    }
}
if ( 1 !== $owners ) {
    $fails[] = $owners . ' place(s) assign `hidden` while iterating [data-uc-filter-text] rows. '
        . 'Exactly one may: two functions assigning it is two answers to "is this row on screen", '
        . 'and the one that wins is whichever ran last, so typing in the search box would '
        . 'un-hide everything the series filter removed.';
} else {
    $notes[] = 'exactly one function assigns `hidden` on the picker rows';
}

/*
 * AND THE SERIES FILTER ITSELF MUST NOT ASSIGN IT, which the check above does
 * NOT cover and was proved not to: a planted `opt.hidden = true` inside
 * initSeriesImageFilter() passed it, because that function loops over
 * [data-uc-image-series] rather than [data-uc-filter-text] and the scoped
 * window never reached it.
 *
 * So the function is sliced and read on its own. The message element is allowed
 * a `hidden` of its own, since that is a paragraph this function does own; what
 * is forbidden is setting it on an option.
 */
$from = strpos( $js, 'function initSeriesImageFilter' );
if ( false === $from ) {
    $fails[] = 'initSeriesImageFilter() is gone, so the series narrowing asserts nothing.';
} else {
    $to   = strpos( $js, "\n    function ", $from + 10 );
    $body = substr( $js, $from, ( false === $to ? 2000 : $to - $from ) );
    if ( preg_match( '/\bopt\.hidden\s*=/', $body ) ) {
        $fails[] = 'initSeriesImageFilter() assigns opt.hidden directly. It must set the '
            . 'data-uc-off-series marker and let initFilterLists() decide, or the two filters '
            . 'fight over the same rows and the search box wins by running last.';
    } elseif ( false === strpos( $body, "setAttribute('data-uc-off-series'" ) ) {
        $fails[] = 'initSeriesImageFilter() no longer sets the data-uc-off-series marker, '
            . 'so nothing tells initFilterLists() which rows are out of series.';
    } else {
        $notes[] = 'the series filter sets the marker and assigns no `hidden` on a row';
    }
}

/* ---------------------------------------------------------------------------
 * Report.
 * ------------------------------------------------------------------------ */
echo "Picker filter test\n";
foreach ( $notes as $n ) { echo '  . ' . $n . "\n"; }
echo "\n";

if ( $fails ) {
    echo 'FAIL: ' . count( $fails ) . "\n";
    foreach ( $fails as $f ) { echo '  . ' . $f . "\n"; }
    exit( 1 );
}

echo "the picker hides rather than groups, says so when a series has nothing,\n";
echo "and the two filters over one list do not fight.\n";
