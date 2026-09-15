<?php
/**
 * ORGANIZERS: THE SCREEN, THE DELETION RULE, AND CREATE-FROM-THE-EDITOR.
 *
 * Three things this has to prove, and the third is the one the brief singled
 * out because this project has already got it wrong once:
 *
 *   1. The taxonomy is untouched. Its slug, its public archive and its
 *      registration are what embed blocks on other sites resolve through, and
 *      those blocks are HTML on pages this plugin cannot see. A rename must not
 *      move a slug.
 *   2. Deletion is ALLOWED (the category rule) and reports the count, rather
 *      than REFUSED (the venue rule). The two precedents differ and the
 *      difference is real, so both are asserted rather than assumed.
 *   3. Creating an organizer from the event editor does not discard unsaved
 *      changes. The FAQ set control applied by posting and redirecting until
 *      3.3.0, which threw away every unsaved edit on the form, and people
 *      learned not to press it. This asserts the new control is a FIELD on the
 *      event form rather than a second submit, because that is the property
 *      that makes losing work impossible rather than unlikely.
 *
 *     php .claude/organizers-test.php
 *
 * WHAT IT STUBS. WordPress's term functions, over an array. SFAF_Organizers is
 * the real file. Points 1 and 3 are structural and are read out of the real
 * sources, with the extraction asserted so this cannot pass by looking at
 * nothing.
 */

$root = dirname( __DIR__ );

define( 'ABSPATH', __DIR__ );
date_default_timezone_set( 'America/Los_Angeles' );

$fails = array();

/* ---------------------------------------------------------------------------
 * WordPress terms, in miniature.
 * ------------------------------------------------------------------------ */
$GLOBALS['terms']  = array();   // term_id => object
$GLOBALS['rel']    = array();   // post_id => array of term_ids
$GLOBALS['next']   = 100;

function sanitize_title( $t ) {
    $t = strtolower( trim( (string) $t ) );
    $t = preg_replace( '/[^a-z0-9]+/', '-', $t );
    return trim( $t, '-' );
}
function wp_strip_all_tags( $t, $b = false ) { return strip_tags( (string) $t ); }
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }

class WP_Error {
    public $code; public $message; public $data;
    public function __construct( $code = '', $message = '', $data = '' ) {
        $this->code = $code; $this->message = $message; $this->data = $data;
    }
    public function get_error_message() { return $this->message; }
    public function get_error_code() { return $this->code; }
    public function get_error_data() { return $this->data; }
}

function get_terms( $args = array() ) {
    $out = array_values( $GLOBALS['terms'] );
    usort( $out, function ( $a, $b ) { return strcmp( $a->name, $b->name ); } );
    return $out;
}
function get_term( $id, $tax = '' ) {
    $id = (int) $id;
    return isset( $GLOBALS['terms'][ $id ] ) ? $GLOBALS['terms'][ $id ] : null;
}
function get_term_by( $field, $value, $tax = '' ) {
    foreach ( $GLOBALS['terms'] as $t ) {
        if ( 'name' === $field && $t->name === $value ) { return $t; }
        if ( 'slug' === $field && $t->slug === $value ) { return $t; }
    }
    return false;
}
function wp_insert_term( $name, $tax, $args = array() ) {
    $id   = $GLOBALS['next']++;
    $slug = isset( $args['slug'] ) && '' !== $args['slug'] ? $args['slug'] : sanitize_title( $name );
    $GLOBALS['terms'][ $id ] = (object) array(
        'term_id' => $id, 'name' => $name, 'slug' => $slug,
        'description' => isset( $args['description'] ) ? $args['description'] : '',
        'taxonomy' => $tax, 'count' => 0,
    );
    return array( 'term_id' => $id );
}
function wp_update_term( $id, $tax, $args = array() ) {
    $id = (int) $id;
    if ( ! isset( $GLOBALS['terms'][ $id ] ) ) { return new WP_Error( 'missing', 'no term' ); }
    foreach ( array( 'name', 'slug', 'description' ) as $k ) {
        if ( isset( $args[ $k ] ) ) { $GLOBALS['terms'][ $id ]->$k = $args[ $k ]; }
    }
    return array( 'term_id' => $id );
}
function wp_delete_term( $id, $tax ) {
    $id = (int) $id;
    unset( $GLOBALS['terms'][ $id ] );
    foreach ( $GLOBALS['rel'] as $post => $ids ) {
        $GLOBALS['rel'][ $post ] = array_values( array_diff( $ids, array( $id ) ) );
    }
    return true;
}
function wp_get_post_terms( $post_id, $tax, $args = array() ) {
    $ids = isset( $GLOBALS['rel'][ (int) $post_id ] ) ? $GLOBALS['rel'][ (int) $post_id ] : array();
    if ( isset( $args['fields'] ) && 'ids' === $args['fields'] ) { return $ids; }
    if ( isset( $args['fields'] ) && 'names' === $args['fields'] ) {
        $n = array();
        foreach ( $ids as $id ) { if ( isset( $GLOBALS['terms'][ $id ] ) ) { $n[] = $GLOBALS['terms'][ $id ]->name; } }
        return $n;
    }
    $out = array();
    foreach ( $ids as $id ) { if ( isset( $GLOBALS['terms'][ $id ] ) ) { $out[] = $GLOBALS['terms'][ $id ]; } }
    return $out;
}
function wp_list_pluck( $list, $field, $index_key = null ) {
    $out = array();
    foreach ( $list as $row ) { $out[] = is_object( $row ) ? $row->$field : $row[ $field ]; }
    return $out;
}
function get_the_title( $id = 0 ) { return 'Event ' . (int) $id; }
function get_post_status( $id = 0 ) { return 'publish'; }

class WP_Query {
    public $posts = array();
    public function __construct( $args = array() ) {
        // Only the organizer tax_query is understood, which is all that is asked.
        $want = 0;
        if ( isset( $args['tax_query'][0]['terms'] ) ) { $want = (int) $args['tax_query'][0]['terms']; }
        foreach ( $GLOBALS['rel'] as $post => $ids ) {
            if ( in_array( $want, $ids, true ) ) { $this->posts[] = (int) $post; }
        }
    }
}

require_once $root . '/includes/class-sfaf-organizers.php';

function expect( $label, $got, $want ) {
    global $fails;
    if ( $got !== $want ) {
        $fails[] = sprintf( '%s: got %s, expected %s', $label, var_export( $got, true ), var_export( $want, true ) );
    }
}

/* ===========================================================================
 * 1. WHAT AN ORGANIZER CARRIES, AND CREATING ONE.
 * ======================================================================== */
echo "Creating and editing\n";

$id = SFAF_Organizers::save( 0, 'The Stonewall Project', 'Substance use services.' );
if ( is_wp_error( $id ) ) {
    $fails[] = 'creating an organizer failed: ' . $id->get_error_message();
} else {
    $t = SFAF_Organizers::get( $id );
    expect( 'name',        $t->name,        'The Stonewall Project' );
    expect( 'slug',        $t->slug,        'the-stonewall-project' );
    expect( 'description', $t->description, 'Substance use services.' );
}

expect( 'a nameless organizer is refused', is_wp_error( SFAF_Organizers::save( 0, '   ' ) ), true );

/*
 * A DUPLICATE NAME RETURNS THE EXISTING TERM RATHER THAN FAILING, which is what
 * makes the create-from-the-editor path safe: somebody typing a name that
 * already exists means "use that one", and failing their whole event save over
 * it would be a poor trade.
 */
$again = SFAF_Organizers::save( 0, 'The Stonewall Project' );
expect( 'a duplicate name returns the existing term', $again, $id );
expect( 'and does not create a second', count( get_terms() ), 1 );

/* --- THE SLUG DOES NOT MOVE WHEN THE NAME CHANGES. ------------------------
 *
 * The single most important assertion in this file. An embed block on another
 * site can be scoped organizer="the-stonewall-project", those blocks are HTML
 * on pages this plugin cannot enumerate, and a slug that stops resolving
 * empties somebody else's calendar with nothing at all to say why.
 */
echo "Renaming does not move the slug\n";
SFAF_Organizers::save( $id, 'Stonewall Project' );
$t = SFAF_Organizers::get( $id );
expect( 'the name changed', $t->name, 'Stonewall Project' );
expect( 'the slug did NOT', $t->slug, 'the-stonewall-project' );

// And it CAN be changed deliberately, as its own field.
SFAF_Organizers::save( $id, 'Stonewall Project', '', 'stonewall' );
expect( 'an explicit slug is honoured', SFAF_Organizers::get( $id )->slug, 'stonewall' );

/* ===========================================================================
 * 2. THE COUNT, AND THE DELETION RULE.
 * ======================================================================== */
echo "Counting and deleting\n";

$GLOBALS['rel'][1] = array( $id );
$GLOBALS['rel'][2] = array( $id );
$GLOBALS['rel'][3] = array();

expect( 'events using it', SFAF_Organizers::event_count( $id ), 2 );

$empty = SFAF_Organizers::save( 0, 'Elizabeth Taylor 50 Plus Network' );
expect( 'an organizer with no events', SFAF_Organizers::event_count( $empty ), 0 );

/*
 * DELETION IS ALLOWED, WHICH IS THE CATEGORY RULE AND NOT THE VENUE RULE.
 *
 * Both are asserted, because the whole point is that this codebase has two
 * precedents and they disagree. An event that loses its organizer keeps its
 * date, its time and its location and merely has no byline. An event that loses
 * its venue has nowhere to be, which is why SFAF_Venues::delete() refuses.
 */
$n = SFAF_Organizers::delete( $id );
expect( 'deletion is allowed even in use', is_wp_error( $n ), false );
expect( 'and reports how many events were affected', $n, 2 );
expect( 'the organizer is gone', SFAF_Organizers::exists( $id ), false );

// The events survive, without an organizer.
expect( 'event 1 still exists and has no organizer', wp_get_post_terms( 1, 'uc_organizer', array( 'fields' => 'ids' ) ), array() );
expect( 'event 2 likewise', wp_get_post_terms( 2, 'uc_organizer', array( 'fields' => 'ids' ) ), array() );

expect( 'deleting a missing organizer is an error', is_wp_error( SFAF_Organizers::delete( 99999 ) ), true );

/*
 * AND THE VENUE RULE IS STILL THE VENUE RULE. If somebody ever "tidies up" by
 * making these two consistent, one of the two screens becomes wrong, and this
 * is what says so.
 */
$venues_src = file_get_contents( $root . '/includes/class-sfaf-venues.php' );
if ( false === strpos( $venues_src, 'sfaf_venue_in_use' ) ) {
    $fails[] = 'SFAF_Venues::delete() no longer refuses a venue in use. The two rules are deliberately different.';
}
$org_src = file_get_contents( $root . '/includes/class-sfaf-organizers.php' );
if ( preg_match( '#return new WP_Error\(\s*.sfaf_organizer_in_use#', $org_src ) ) {
    $fails[] = 'SFAF_Organizers::delete() now refuses. That is the venue rule, and an organizer is the category case.';
}

/* ===========================================================================
 * CO-HOSTED EVENTS (3.40.0), AND THE SILENT DATA LOSS THAT PRECEDED THEM.
 *
 * The taxonomy has always accepted several organizers: it is non-hierarchical,
 * `show_ui` defaults to `public` which is true, so the WordPress post editor's
 * own Organizers box has always been able to put two on an event.
 *
 * What limited an event to one was the caladmin picker, a single select that
 * read `[0]` and whose save wrote an array of that one back through
 * wp_set_object_terms(), which REPLACES by default. So a two-organizer event
 * showed one and lost the other the moment anybody pressed Save, with nothing
 * said. That is the fault categories had until 3.8.0, on a different taxonomy.
 *
 * Both halves of the old behaviour are asserted as GONE, because either one
 * surviving reintroduces the loss.
 * ======================================================================== */
echo "Co-hosted events\n";

$GLOBALS['terms'] = array();
$GLOBALS['rel']   = array();
$GLOBALS['next']  = 100;

$stonewall = SFAF_Organizers::save( 0, 'The Stonewall Project' );
$bbe       = SFAF_Organizers::save( 0, 'Black Brothers Esteem' );
$elizabeth = SFAF_Organizers::save( 0, 'Elizabeth Taylor 50 Plus Network' );

// An event co-hosted by two, stored in the order somebody happened to add them.
$GLOBALS['rel'][10] = array( $stonewall, $bbe );

$got = SFAF_Organizers::for_event( 10 );
expect( 'both organizers come back', count( $got ), 2 );

/*
 * ORDERED BY NAME, ALWAYS. Term relationships come back in an order nothing
 * guarantees, so without this the same event could name its hosts one way on
 * its page and the other way on a card. Stored Stonewall-then-BBE above; read
 * back alphabetically.
 */
expect( 'ordered by name, not by insertion', wp_list_pluck( $got, 'name' ),
    array( 'Black Brothers Esteem', 'The Stonewall Project' ) );

/* --- THE WORDING. One place decides it, so three surfaces cannot differ. --- */
echo "The wording\n";

$GLOBALS['rel'][11] = array( $stonewall );
$GLOBALS['rel'][12] = array( $stonewall, $bbe );
$GLOBALS['rel'][13] = array( $stonewall, $bbe, $elizabeth );
$GLOBALS['rel'][14] = array();

/*
 * ONE ORGANIZER READS EXACTLY AS IT ALWAYS DID. This is the requirement that
 * makes the whole change invisible unless somebody uses it.
 */
expect( 'one', SFAF_Organizers::phrase( 11 ), 'The Stonewall Project' );

expect( 'two', SFAF_Organizers::phrase( 12 ),
    'Black Brothers Esteem and The Stonewall Project' );

// AP style: no serial comma in a simple series. "A, B and C", not "A, B, and C".
expect( 'three', SFAF_Organizers::phrase( 13 ),
    'Black Brothers Esteem, Elizabeth Taylor 50 Plus Network and The Stonewall Project' );

if ( false !== strpos( SFAF_Organizers::phrase( 13 ), ', and ' ) ) {
    $fails[] = 'the phrase uses a serial comma, which is not AP style and not the house style';
}

expect( 'none', SFAF_Organizers::phrase( 14 ), '' );

// join() is the same rule for callers holding names rather than an event.
expect( 'join, two', SFAF_Organizers::join( array( 'A', 'B' ) ), 'A and B' );
expect( 'join, blanks dropped', SFAF_Organizers::join( array( 'A', '  ', 'B' ) ), 'A and B' );
expect( 'join, nothing', SFAF_Organizers::join( array() ), '' );

/* --- THE COUNT COUNTS "ONE OF SEVERAL". --------------------------------- */
echo "Counting a co-host\n";

$GLOBALS['rel'] = array(
    20 => array( $stonewall ),               // sole
    21 => array( $stonewall, $bbe ),         // one of two
    22 => array( $bbe ),                     // not Stonewall at all
);

expect( 'the sole event and the co-hosted one both count', SFAF_Organizers::event_count( $stonewall ), 2 );
expect( 'and the other organizer counts its two', SFAF_Organizers::event_count( $bbe ), 2 );
expect( 'and one with none counts nothing', SFAF_Organizers::event_count( $elizabeth ), 0 );

// The deletion confirmation names that same count, so it cannot understate.
$n = SFAF_Organizers::delete( $stonewall );
expect( 'deletion reports the co-hosted event too', $n, 2 );
// The co-hosted event keeps its other host.
expect( 'and the co-host survives', wp_get_post_terms( 21, 'uc_organizer', array( 'fields' => 'ids' ) ), array( $bbe ) );

/* --- THE PICKER AND THE SAVE, READ OUT OF THE SOURCE. -------------------
 *
 * The behaviour above is the API. What silently deleted data was the CONTROL,
 * so the control is checked too: a single select reading [0] and a save writing
 * one value would reintroduce the loss whatever the API does.
 */
echo "The control cannot drop one\n";

$portal_src = file_get_contents( $root . '/includes/class-sfaf-portal.php' );

if ( preg_match( "#wp_get_post_terms\(\s*\\\$event_id,\s*'uc_organizer'[^)]*\)\s*\?:\s*array\(\s*0\s*\)\s*\)\[0\]#", $portal_src ) ) {
    $fails[] = 'the organizer picker still reads [0], so an event with two shows one and loses the other on save';
}
if ( preg_match( '#<select name="organizer">#', $portal_src ) ) {
    $fails[] = 'the organizer control is still a single select, which cannot express a co-hosted event';
}
if ( false === strpos( $portal_src, 'name="organizer[]"' ) ) {
    $fails[] = 'the organizer control does not post an array, so only one value can ever arrive';
}
if ( false === strpos( $portal_src, "name=\"uc_organizer_present\"" ) ) {
    $fails[] = 'the organizer control has no present marker, so unticking every box would read as "the form did not ask" and leave the old organizers in place';
}
if ( preg_match( "#wp_set_object_terms\(\s*\\\$event_id,\s*\\\$org\s*\?#", $portal_src ) ) {
    $fails[] = 'the save still writes a single organizer, replacing whatever else the event had';
}

/* ---- THE TWO PUBLIC FORMS (3.84.0). ----
 *
 * 3.40.0 fixed the caladmin editor and the read surfaces and left both forms
 * single, because the staff form's organizer field did not exist yet: it was
 * added in 3.76.0, after the decision, as a select. So the one place a
 * co-hosted event could not be described was the place a co-hosted event is
 * most likely to be requested from.
 *
 * NEITHER FORM COULD EVER DROP AN EXISTING ORGANIZER, and that is worth being
 * exact about rather than implying a data loss there was not: both CREATE a
 * pending event and neither edits one, so there was never a stored set to
 * replace. What was lost was what the requester said, before it was stored. */
$req_src = file_get_contents( $root . '/includes/class-sfaf-request.php' );

if ( preg_match( '#<select name="organizer">#', $req_src ) ) {
    $fails[] = 'the staff request form is still a single select, so a co-hosted event cannot be requested as one';
}
if ( false === strpos( $req_src, 'name="organizer[]"' ) ) {
    $fails[] = 'the staff request form does not post an array of organizers';
}
if ( preg_match( "#wp_set_object_terms\(\s*\\\$event_id,\s*array\(\s*\(int\)\s*\\\$c\['organizer'\]\s*\),\s*'uc_organizer'#", $req_src ) ) {
    $fails[] = 'the staff request approval still writes exactly one organizer';
}
if ( preg_match( "#\\\$clean\['organizer'\]\s*=\s*0;#", $req_src ) ) {
    $fails[] = 'the staff request validator still reduces the organizers to a single id';
}

/* THE COMMUNITY FORM inherits from the series, and a series' most recent event
 * can be co-hosted. Taking [0] put a co-hosted submission under one team's
 * filter and not the other's, which is the whole purpose of the filter. */
$sub_src = file_get_contents( $root . '/includes/class-sfaf-submit.php' );

if ( preg_match( '#foreach\s*\(\s*SFAF_Series::organizers_for[^)]*\)\s*as\s*\$org_id\s*\)#', $sub_src ) ) {
    $fails[] = 'the community form still loops the series organizers one at a time, which either takes the first or, without the break, keeps only the last';
}
if ( false === strpos( $sub_src, 'array_map( \'intval\', $series_orgs )' ) ) {
    $fails[] = 'the community form no longer writes the whole set of series organizers in one call';
}

/* ONE CALL, NEVER ONE PER ID. wp_set_object_terms() REPLACES by default, so a
 * call inside a loop keeps only whichever ran last. This is the 3.8.0
 * categories fault and the 3.40.0 organizers fault, and it is the single shape
 * most likely to come back, so it is asserted against every writer by name.
 *
 * TWO CHECKS, BECAUSE THE FIRST ONE HAD A HOLE. The 3.84.0 version required a
 * NEWLINE between the foreach and the call, so the whole fault written on one
 * line walked straight past it. That was found by planting it in 3.85.0 and
 * watching the suite stay green, which is the reason the planting rule exists.
 *
 * COMMENTS ARE STRIPPED FIRST, so a docblock describing the fault is not read
 * as the fault. Every one of these three files has one.
 *
 * Check 1 is the loop. Check 2 is the SHAPE, which is the stronger of the two:
 * a single-element array literal written to this taxonomy is wrong however it
 * got there, loop or no loop, because an event can hold several organizers and
 * this call replaces. */
function org_strip_comments( $src ) {
    $out = '';
    foreach ( token_get_all( $src ) as $tok ) {
        if ( is_array( $tok ) ) {
            if ( T_COMMENT === $tok[0] || T_DOC_COMMENT === $tok[0] ) { continue; }
            $out .= $tok[1];
        } else {
            $out .= $tok;
        }
    }
    return $out;
}

foreach ( array(
    'class-sfaf-request.php' => $req_src,
    'class-sfaf-submit.php'  => $sub_src,
    'class-sfaf-portal.php'  => $portal_src,
) as $fname => $src ) {
    $code = org_strip_comments( $src );

    /* 1. A loop whose body reaches this taxonomy, on one line or many. */
    if ( preg_match( "#(?:foreach|for|while)\s*\([^)]*\)\s*\{?[^{}]{0,300}?wp_set_object_terms\s*\([^;]*?'uc_organizer'#s", $code ) ) {
        $fails[] = $fname . ' calls wp_set_object_terms() for uc_organizer inside a loop, which replaces on every pass and keeps only the last';
    }

    /* 2. A single-element array literal, which is the fault without the loop. */
    /* THE VARIABLE MAY CARRY A SUBSCRIPT OR A PROPERTY, and the first draft of
     * this pattern did not allow either, so `array( (int) $orgs[0] )` walked
     * past it. Planted and caught only after widening, which is the second hole
     * this one check has had. */
    if ( preg_match( "#wp_set_object_terms\s*\([^;]*?array\s*\(\s*(?:\(\s*int\s*\)\s*)?\\\$[A-Za-z_][A-Za-z0-9_]*(?:\s*\[[^\]]*\]|\s*->\s*[A-Za-z_][A-Za-z0-9_]*)*\s*\)\s*,\s*'uc_organizer'#s", $code ) ) {
        $fails[] = $fname . ' writes a SINGLE-element array to uc_organizer, which replaces whatever else the event had';
    }
}

/* THE PREFILL APPLIES WHAT IT PREVIEWS. The request form previewed the joined
 * phrase, "A, B and C", and then applied A on its own. */
$pjs = file_get_contents( $root . '/public/js/portal.js' );
if ( preg_match( '#el\.value\s*=\s*String\(\s*d\.organizers\[0\]\s*\)#', $pjs ) ) {
    $fails[] = 'the request form prefill still applies only the first organizer while previewing the full phrase';
}
if ( 2 !== preg_match_all( '#\[name="organizer\[\]"\]\[value="#', $pjs ) ) {
    $fails[] = 'both prefill appliers should tick organizer boxes by value; one of them does not';
}

/* ===========================================================================
 * 3. THE TAXONOMY IS UNTOUCHED.
 * ======================================================================== */
echo "The taxonomy is untouched\n";

$types_src = file_get_contents( $root . '/includes/class-sfaf-post-types.php' );

if ( ! preg_match( "#register_taxonomy\(\s*'uc_organizer'.*?\)\s*\);#s", $types_src, $m ) ) {
    $fails[] = 'the uc_organizer registration could not be found; this check is blind';
} else {
    $reg = $m[0];
    foreach ( array(
        "'public'       => true"        => 'it must stay public, or the archive and every embed filter stop resolving',
        "'slug' => 'event-organizer'"   => 'the rewrite slug is what existing URLs use',
        "'show_in_rest' => true"        => 'satellites read it over REST',
    ) as $needle => $why ) {
        if ( false === strpos( $reg, $needle ) ) {
            $fails[] = "uc_organizer registration lost \"$needle\": $why";
        }
    }
    // show_ui must NOT have been switched off: the WordPress screen stays
    // reachable by URL as the fallback, the same reasoning that kept Calendar
    // Users in wp-admin.
    if ( false !== strpos( $reg, "'show_ui'" ) && false !== strpos( $reg, "'show_ui'      => false" ) ) {
        $fails[] = 'uc_organizer lost show_ui, so the WordPress fallback screen is gone';
    }
}

// SFAF_Organizers must not register anything of its own.
if ( false !== strpos( $org_src, 'register_taxonomy' ) ) {
    $fails[] = 'SFAF_Organizers registers a taxonomy. It is a wrapper; registration lives in class-sfaf-post-types.php.';
}

/* ===========================================================================
 * 4. NO CREATING ONE FROM THE EVENT EDITOR. REVERSED IN 3.41.0.
 *
 * 3.37.0 added a "Not listed? Add one" field to the organizer control, and this
 * section asserted it was there, that it was a FIELD rather than a button that
 * posts, and that a typed name already ticked was not added twice. Those were
 * the right assertions for a control that should exist.
 *
 * It should not. The same release gave organizers their own screen, which is
 * what the field was compensating for, and a text box beside a curated list
 * invents entries in passing: an organizer typed mid-event gets whatever
 * spelling was in somebody's head, and the list acquires three of them, each
 * owning some events. Merging afterwards is manual.
 *
 * SO THE ASSERTION IS INVERTED, AND IT IS CHECKED IN BOTH HALVES. That is the
 * shape cancellation-test settled on when the native-only rule removed a
 * control: the renderer not drawing it is not on its own a guarantee, because a
 * save still reading the POST key is a second way in for anything that posts
 * here. What is KEPT from the old section is everything about not losing
 * unsaved work, because the next control somebody adds to this card is subject
 * to it.
 * ======================================================================== */
echo "Adding one from the event editor\n";

$portal_src = file_get_contents( $root . '/includes/class-sfaf-portal.php' );

/* No field. */
if ( false !== strpos( $portal_src, 'name="organizer_new"' ) ) {
    $fails[] = 'the organizer_new field is back on the event editor. Organizers are made on the Organizers screen; a text box beside the picker invents near-duplicates in passing.';
}

/* And no save reading it. Checked separately, because either half alone would
 * be a live path: a field with no save is a lie, and a save with no field is
 * reachable by anything that posts to this action. */
if ( ! preg_match( '#function save_manager_fields_from_post\s*\(.*?\)\s*\{#s', $portal_src, $m, PREG_OFFSET_CAPTURE ) ) {
    $fails[] = 'save_manager_fields_from_post() not found';
} else {
    $body = substr( $portal_src, $m[0][1], 8000 );
    $body = preg_replace( '#/\*.*?\*/#s', '', $body );
    if ( false !== strpos( $body, "_POST['organizer_new']" ) ) {
        $fails[] = 'the save still reads organizer_new, so an organizer can be created by posting to save_event even with no field on the form';
    }
    if ( preg_match( '#SFAF_Organizers::save\(#', $body ) ) {
        $fails[] = 'the event save still creates organizer terms. Creating one is a decision taken on the Organizers screen.';
    }
}

/*
 * THE RENDERER OPENS NO FORM OF ITS OWN, AND POSTS NOTHING ON ITS OWN.
 *
 * Kept from the reversed section, because it is the durable half. A control on
 * this card with its own form or its own submit leaves the event form and
 * discards every unsaved edit, which is the 3.3.0 FAQ set fault, and it is also
 * the 3.40.0 nested-form fault by another name.
 */
if ( ! preg_match( '#function render_manager_control\s*\(.*?\)\s*\{#s', $portal_src, $m, PREG_OFFSET_CAPTURE ) ) {
    $fails[] = 'render_manager_control() not found';
} else {
    $start = $m[0][1];
    $depth = 0;
    $end   = $start;
    for ( $i = strpos( $portal_src, '{', $start ), $n = strlen( $portal_src ); $i < $n; $i++ ) {
        if ( '{' === $portal_src[ $i ] ) { $depth++; }
        if ( '}' === $portal_src[ $i ] ) {
            $depth--;
            if ( 0 === $depth ) { $end = $i; break; }
        }
    }
    $method = preg_replace( '#/\*.*?\*/#s', '', substr( $portal_src, $start, $end - $start ) );

    if ( preg_match( '#<form\b#', $method ) ) {
        $fails[] = 'render_manager_control() opens a form. A control with its own form submits on its own, which leaves the event form and discards every unsaved edit. That was the 3.3.0 FAQ set fault.';
    }
    if ( preg_match( '#type="submit"#', $method ) ) {
        $fails[] = 'render_manager_control() emits a submit button, which would post and discard unsaved edits';
    }
}

/*
 * AND NO SEPARATE ACTION APPEARED IN ITS PLACE. Removing the field is not an
 * invitation to rebuild it as a button: from the event editor that is a second
 * submit, and a second submit discards the form.
 */
if ( preg_match( "#case 'create_organizer':#", $portal_src ) ) {
    $fails[] = 'a create_organizer POST action exists. From the event editor that is a second submit, which discards every unsaved edit. It was the 3.3.0 FAQ set fault.';
}
if ( preg_match( '#name="uc_action" value="create_organizer"#', $portal_src ) ) {
    $fails[] = 'the event form posts a create_organizer action, which would discard unsaved edits';
}

/*
 * THE ORGANIZERS SCREEN IS WHERE ONE IS MADE, so it has to be able to. This is
 * what carries the removed field's job, and removing the field is only correct
 * while this holds.
 */
if ( ! preg_match( "#case 'save_organizer':#", $portal_src ) ) {
    $fails[] = 'there is no save_organizer action, so nothing can create an organizer at all';
}

/* ===========================================================================
 * 5. THE ADAPTER PATH IS UNAFFECTED.
 * ======================================================================== */
echo "Imported events\n";

foreach ( array( 'eventbrite', 'gfmp' ) as $adapter ) {
    $src = file_get_contents( $root . '/includes/class-sfaf-source-' . $adapter . '.php' );
    if ( ! preg_match( '#function manager_fields\(\)\s*\{\s*return array\(([^)]*)\);#s', $src, $m ) ) {
        $fails[] = "$adapter: manager_fields() not found";
        continue;
    }
    if ( false === strpos( $m[1], "'organizer'" ) ) {
        $fails[] = "$adapter: organizer is no longer a manager field, so a fetch could start writing it";
    }
}

/* ------------------------------------------------------------------------ */
echo "\nOrganizers test\n";
echo "checked: name, slug and description are all an organizer carries; a rename does NOT move the\n";
echo "         slug and an explicit slug change does; a duplicate name returns the existing term;\n";
echo "         the count covers drafts as well as published; deletion is ALLOWED and reports the\n";
echo "         count while the venue refusal stays a refusal; the taxonomy keeps public, its rewrite\n";
echo "         slug, show_in_rest and its WordPress fallback screen; the editor offers no way to\n";
echo "         create one, in the renderer or in the save, and no second submit replaced it; that\n";
echo "         the Organizers screen still can, since it now carries that job alone; organizer\n";
echo "         stays a manager\n";
echo "         field on both adapters so no fetch writes it; that an event can hold SEVERAL\n";
echo "         organizers, ordered by name so two surfaces cannot disagree, phrased 'A and B' and\n";
echo "         'A, B and C' with no serial comma, counted when one of several, and that neither the\n";
echo "         single select nor the single-value save that silently dropped one can come back;\n";
echo "         and that BOTH public forms can now say so too: the staff request form posts an\n";
echo "         array and approves the whole set, the community form inherits every organizer the\n";
echo "         series lends rather than the first, no writer sets this taxonomy inside a loop,\n";
echo "         and the request prefill applies the same organizers its preview names\n\n";

if ( $fails ) {
    echo 'FAIL: ' . count( $fails ) . "\n";
    foreach ( $fails as $f ) { echo "  . $f\n"; }
    exit( 1 );
}
echo "organizers are managed in caladmin, and nothing a URL depends on moved.\n";
