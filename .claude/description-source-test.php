<?php
/**
 * WHERE AN EVENT'S DESCRIPTION COMES FROM, ON ALL FIVE SURFACES.
 *
 *     php .claude/description-source-test.php
 *     php .claude/description-source-test.php --self-test
 *
 * THREE RUNGS, IN THIS ORDER, AND THE ORDER IS THE WHOLE THING:
 *
 *     1. the event's own post_content       what the editor writes
 *     2. its series' description            3.98.0
 *     3. nothing
 *
 * AND NEVER `post_excerpt`. Nothing in the editor writes it, so the only values
 * it ever holds are copies from another post: the duplicate action, and
 * SFAF_Recurrence handing every generated occurrence the SEED's excerpt. An
 * occurrence whose description was edited keeps its own content and the seed's
 * excerpt for life, so anything reading the excerpt shows the text the whole
 * series shares. That was the card summary and the search engine summary until
 * 3.98.0, and the calendar file until 3.97.2.
 *
 * IT RUNS THE RESOLVER rather than reading it. The five surfaces are then
 * checked as SOURCE, because they live in five files that each need most of
 * WordPress to load, and what can go wrong there is a call site pointing
 * somewhere else rather than the resolver misbehaving.
 */

$root = dirname( __DIR__ );
define( 'ABSPATH', __DIR__ );

$self_test = in_array( '--self-test', $argv, true );
$fails     = array();
function check( $ok, $why ) {
    global $fails;
    if ( ! $ok ) { $fails[] = $why; }
}

/* ---------------------------------------------------------------------------
 * WordPress, in miniature, plus a series that can be given a description.
 * ------------------------------------------------------------------------ */
$GLOBALS['posts']  = array();
$GLOBALS['series'] = array();   // post_id => term object or null

function get_post( $id = null, $output = 'OBJECT', $filter = 'raw' ) {
    $id = (int) $id;
    return isset( $GLOBALS['posts'][ $id ] ) ? $GLOBALS['posts'][ $id ] : null;
}
function get_post_field( $field, $post_id = null, $context = 'display' ) {
    $p = get_post( $post_id );
    return ( $p && isset( $p->$field ) ) ? $p->$field : '';
}
function is_wp_error( $t ) { return ( $t instanceof WP_Error ); }
class WP_Error {}
function sfaf_flatten_html( $html ) {
    $html = (string) $html;
    if ( '' === $html ) { return ''; }
    $blocks = 'p|div|br|li|ul|ol|h[1-6]|blockquote|tr|td|th|section|article|header|footer|hr|pre';
    $html   = preg_replace( '#<\s*/?\s*(' . $blocks . ')\b[^>]*>#i', ' ', $html );
    return trim( preg_replace( '/\s+/u', ' ', strip_tags( $html ) ) );
}

/*
 * THE SERIES LOOKUP, and it answers off a table this test controls. The real
 * for_event() is get_the_terms(); what matters to the resolver is that it
 * returns a term with a description, or nothing.
 */
class SFAF_Series {
    public static function for_event( $post_id ) {
        $post_id = (int) $post_id;
        return isset( $GLOBALS['series'][ $post_id ] ) ? $GLOBALS['series'][ $post_id ] : null;
    }
}

/* Only the three functions under test, sliced out of the real file so this
 * asserts the shipped code rather than a copy of it. */
$tpl_src = file_get_contents( $root . '/includes/sfaf-template-functions.php' );
$slice   = function ( $name ) use ( $tpl_src ) {
    $at = strpos( $tpl_src, 'function ' . $name . '(' );
    if ( false === $at ) { return ''; }
    $depth = 0;
    for ( $i = $at; $i < strlen( $tpl_src ); $i++ ) {
        if ( '{' === $tpl_src[ $i ] ) { $depth++; }
        if ( '}' === $tpl_src[ $i ] ) { $depth--; if ( 0 === $depth ) { return substr( $tpl_src, $at, $i - $at + 1 ); } }
    }
    return '';
};

foreach ( array( 'sfaf_event_description_html', 'sfaf_event_description_is_series', 'sfaf_event_description_text' ) as $fn ) {
    $code = $slice( $fn );
    if ( '' === $code ) {
        echo "FAIL: $fn() could not be sliced out of sfaf-template-functions.php\n";
        exit( 1 );
    }
    eval( $code );
}

/* ---------------------------------------------------------------------------
 * The fixtures.
 * ------------------------------------------------------------------------ */
$OWN    = '<p>Bring a friend. We meet in the back room.</p>';
$SERIES = '<p>A weekly peer support group for trans and nonbinary people.</p>';

function make( $id, $content, $series_desc = null ) {
    $GLOBALS['posts'][ $id ] = (object) array( 'ID' => $id, 'post_content' => $content );
    $GLOBALS['series'][ $id ] = ( null === $series_desc )
        ? null
        : (object) array( 'term_id' => 7, 'description' => $series_desc );
}

/* ---------------------------------------------------------------------------
 * 1. RUNG ONE: THE EVENT'S OWN WORDS WIN, even when the series has some.
 * ------------------------------------------------------------------------ */
make( 1, $OWN, $SERIES );
check( $OWN === sfaf_event_description_html( 1 ),
    "an event with its own description shows the series' instead, which is the fault 3.98.0 must not introduce" );
check( false !== strpos( sfaf_event_description_text( 1 ), 'Bring a friend' ),
    "the flat text is not the event's own" );
check( false === strpos( sfaf_event_description_text( 1 ), 'peer support' ),
    'the series description reached an event that has its own' );
check( ! sfaf_event_description_is_series( 1 ),
    'the editor would tell an event with its own description that it will show the series one' );

/* ---------------------------------------------------------------------------
 * 2. RUNG TWO: NO DESCRIPTION OF ITS OWN, so the series' is shown.
 * ------------------------------------------------------------------------ */
make( 2, '', $SERIES );
check( $SERIES === sfaf_event_description_html( 2 ),
    'an event with no description of its own does not fall back to its series' );
check( false !== strpos( sfaf_event_description_text( 2 ), 'peer support' ),
    "the flat text does not carry the series description" );
check( sfaf_event_description_is_series( 2 ),
    'the editor does not say the event will show the series description' );

/* WHITESPACE IS NOT A DESCRIPTION. An editor that leaves an empty paragraph
 * behind must not count as the event having said something. */
make( 3, "   \n\t ", $SERIES );
check( $SERIES === sfaf_event_description_html( 3 ),
    'an event whose description is whitespace is treated as having one, so the series never shows' );

/* ---------------------------------------------------------------------------
 * 3. RUNG THREE: NOTHING, and nothing is invented.
 * ------------------------------------------------------------------------ */
make( 4, '', null );
check( '' === sfaf_event_description_html( 4 ),
    'an event with no description and no series returned something' );
check( '' === sfaf_event_description_text( 4 ), 'the flat text invented something' );
check( ! sfaf_event_description_is_series( 4 ),
    'the editor offers the series note on an event with no series' );

/* A SERIES WITH AN EMPTY DESCRIPTION IS THE SAME AS NO SERIES. */
make( 5, '', '' );
check( '' === sfaf_event_description_html( 5 ),
    'a series with an empty description is treated as having one' );
check( ! sfaf_event_description_is_series( 5 ),
    'the editor promises the series description of a series that has none' );

/* AND AN EVENT THAT DOES NOT EXIST ANSWERS NOTHING rather than warning. */
check( '' === sfaf_event_description_html( 999 ), 'a missing event did not answer empty' );

/* ---------------------------------------------------------------------------
 * 4. THE FIVE SURFACES ALL ASK THE ONE RESOLVER.
 *
 * Asserted as source. Each of these was a separate answer to the same question
 * before 3.98.0, and three of the five were wrong in the same direction.
 * ------------------------------------------------------------------------ */
$main  = file_get_contents( $root . '/sfaf-calendar.php' );
$cards = file_get_contents( $root . '/includes/class-sfaf-shortcodes.php' );
$seo   = file_get_contents( $root . '/includes/class-sfaf-seo.php' );
$page  = file_get_contents( $root . '/templates/single-uc_event.php' );

check( (bool) preg_match( '/\$description = sfaf_event_description_text\( \$post_id \);/', $main ),
    'the calendar file does not ask the resolver' );
check( (bool) preg_match( "/'details'  => sfaf_event_description_text\( \\\$post_id \),/", $tpl_src ),
    'the Google Calendar URL does not ask the resolver' );
check( (bool) preg_match( '/wp_trim_words\( sfaf_event_description_text\( \$post_id \), 25 \)/', $cards ),
    "the card summary does not ask the resolver, so an edited occurrence shows its seed's text on the calendar" );
check( (bool) preg_match( '/wp_trim_words\( sfaf_event_description_text\( \$id \), 40 \)/', $seo ),
    'the search engine summary does not ask the resolver, and Google reads that one' );
check( (bool) preg_match( '/sfaf_event_description_html\( \$post_id \)/', $page ),
    'the event page does not fall back to the series description' );

/* AND NONE OF THEM CALLS THE EXCERPT ANY MORE.
 *
 * WITH THE COMMENTS STRIPPED, BY THE TOKENIZER. All four files EXPLAIN in prose
 * that they used to read get_the_excerpt() and why they stopped, so a plain
 * strpos() over the source reports every one of them as still doing it. That is
 * the repo's own rule: audit with a tokenizer, not grep. The first version of
 * this check failed on its own explanatory comments. */
$code_only = function ( $src ) {
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
foreach ( array(
    'the calendar file'         => $main,
    'the card summary'          => $cards,
    'the search engine summary' => $seo,
    'the event page'            => $page,
) as $what => $src ) {
    check( false === strpos( $code_only( $src ), 'get_the_excerpt' ),
        $what . " calls get_the_excerpt() again, which on a generated occurrence is the seed event's" );
}

/* THE EVENT PAGE KEEPS the_content() FOR THE ORDINARY CASE. Replacing it
 * wholesale would change how an event with its own description renders, because
 * the_content() also handles the more tag, paging and the global post. */
check( false !== strpos( $page, 'the_content();' ),
    'the event page no longer calls the_content() at all, so an ordinary description renders by a different path than it used to' );

/* ---------------------------------------------------------------------------
 * THE SELF-TEST: a reader that cannot see the fault reports none.
 * ------------------------------------------------------------------------ */
if ( $self_test ) {
    $probe = array();
    make( 90, '', $SERIES );
    if ( '' === sfaf_event_description_html( 90 ) ) {
        $probe[] = 'the harness cannot give a series a description, so the whole of rung two is untested';
    }
    make( 91, $OWN, $SERIES );
    if ( sfaf_event_description_html( 91 ) === $SERIES ) {
        $probe[] = 'the harness cannot tell the event\'s own words from the series\'';
    }
    if ( $probe ) {
        echo "SELF-TEST FAILED\n";
        foreach ( $probe as $p ) { echo '  . ' . $p . "\n"; }
        exit( 1 );
    }
    echo "self-test passed: the harness can tell the three rungs apart.\n";
}

if ( $fails ) {
    echo 'FAIL: ' . count( $fails ) . "\n";
    foreach ( $fails as $f ) { echo '  . ' . $f . "\n"; }
    exit( 1 );
}
echo "one resolver, three rungs, five surfaces: the event's own words, then its\n";
echo "series', then nothing, and the excerpt nowhere.\n";
exit( 0 );
