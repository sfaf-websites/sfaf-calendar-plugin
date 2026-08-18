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
 * 4. CREATE FROM THE EVENT EDITOR, WITHOUT LOSING UNSAVED WORK.
 *
 * The property that matters is structural, so it is checked structurally: the
 * control must be a FIELD on the event form, whose value is read inside
 * save_event_from_post(). If it were a button posting its own uc_action, the
 * form would submit, redirect, and discard everything typed since the page
 * loaded. That is exactly what the FAQ set control did until 3.3.0.
 * ======================================================================== */
echo "Adding one from the event editor\n";

$portal_src = file_get_contents( $root . '/includes/class-sfaf-portal.php' );

// It is an input on the form.
if ( false === strpos( $portal_src, 'name="organizer_new"' ) ) {
    $fails[] = 'there is no organizer_new field on the event form, so the editor still cannot add one';
}

/*
 * IT IS READ ON THE PATH AN ORDINARY SAVE ALREADY TAKES.
 *
 * The organizer is a MANAGER field, so it is written by
 * save_manager_fields_from_post() rather than inline in save_event_from_post().
 * That is the shared-field-list rule: one list, one render, one save. What
 * matters for losing work is not which of the two methods holds the line, but
 * that the line is reached by the ordinary Save with no second submit, so both
 * halves of that chain are asserted.
 */
if ( ! preg_match( '#function save_manager_fields_from_post\s*\(.*?\)\s*\{#s', $portal_src, $m, PREG_OFFSET_CAPTURE ) ) {
    $fails[] = 'save_manager_fields_from_post() not found';
} else {
    $body = substr( $portal_src, $m[0][1], 8000 );
    if ( false === strpos( $body, 'organizer_new' ) ) {
        $fails[] = 'organizer_new is not handled in save_manager_fields_from_post(), so it does not ride the ordinary save';
    }
}

if ( ! preg_match( '#function save_event_from_post\s*\(.*?\)\s*\{#s', $portal_src, $m, PREG_OFFSET_CAPTURE ) ) {
    $fails[] = 'save_event_from_post() not found';
} else {
    $body = substr( $portal_src, $m[0][1], 20000 );
    if ( false === strpos( $body, 'save_manager_fields_from_post' ) ) {
        $fails[] = 'save_event_from_post() no longer calls save_manager_fields_from_post(), so the new-organizer field is never read on an ordinary save';
    }
}

/*
 * THE RENDERER OPENS NO FORM OF ITS OWN.
 *
 * This is the assertion that encodes the 3.3.0 lesson structurally. A control
 * that opens its own <form> submits on its own, which means leaving the event
 * form and discarding everything typed into it. A control that emits only
 * fields belongs to whichever form encloses it, and the only form that calls
 * render_manager_control() is the event form.
 *
 * Checked by slicing the method rather than the file, because file order is not
 * DOM order here: this renderer is defined above the form markup and called
 * from inside it.
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
    $method = substr( $portal_src, $start, $end - $start );

    if ( false === strpos( $method, 'name="organizer_new"' ) ) {
        $fails[] = 'the organizer_new input is not rendered by render_manager_control()';
    }
    if ( preg_match( '#<form\b#', $method ) ) {
        $fails[] = 'render_manager_control() opens a form. A control with its own form submits on its own, which leaves the event form and discards every unsaved edit. That was the 3.3.0 FAQ set fault.';
    }
    if ( preg_match( '#type="submit"#', $method ) ) {
        $fails[] = 'render_manager_control() emits a submit button, which would post and discard unsaved edits';
    }
}

/*
 * AND THERE IS NO SEPARATE ACTION FOR IT. This is the assertion that actually
 * encodes the 3.3.0 lesson: a `create_organizer` arm in the POST dispatcher
 * would mean a second submit from the event screen, and a second submit is what
 * discards the form.
 */
if ( preg_match( "#case 'create_organizer':#", $portal_src ) ) {
    $fails[] = 'a create_organizer POST action exists. From the event editor that is a second submit, which discards every unsaved edit. It was the 3.3.0 FAQ set fault.';
}
if ( preg_match( '#name="uc_action" value="create_organizer"#', $portal_src ) ) {
    $fails[] = 'the event form posts a create_organizer action, which would discard unsaved edits';
}

/*
 * THE TYPED ORGANIZER IS ADDED, NOT AN ALTERNATIVE.
 *
 * "The select wins" was correct while the control was a single select and the
 * two were alternatives. With checkboxes, ticking two and typing a third means
 * three hosts. What must still hold is that it is not added twice when it names
 * one already ticked, which save() makes possible by returning the existing
 * term for a duplicate name.
 */
if ( ! preg_match( '#! in_array\( \(int\) \$made, \$orgs, true \)#', $portal_src ) ) {
    $fails[] = 'a newly typed organizer is not checked against the ticked ones, so naming one already ticked would add it twice';
}
if ( preg_match( '#if \( ! \$org && ! empty\( \$_POST\[.organizer_new.\] \) \)#', $portal_src ) ) {
    $fails[] = 'the new-organizer box is still gated on nothing being chosen, which was the single-select rule: it would now refuse to add a third host to an event that already names two';
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
echo "         slug, show_in_rest and its WordPress fallback screen; the editor's add-one control is\n";
echo "         a field on the event form and not a second submit; and organizer stays a manager\n";
echo "         field on both adapters so no fetch writes it; that an event can hold SEVERAL\n";
echo "         organizers, ordered by name so two surfaces cannot disagree, phrased 'A and B' and\n";
echo "         'A, B and C' with no serial comma, counted when one of several, and that neither the\n";
echo "         single select nor the single-value save that silently dropped one can come back\n\n";

if ( $fails ) {
    echo 'FAIL: ' . count( $fails ) . "\n";
    foreach ( $fails as $f ) { echo "  . $f\n"; }
    exit( 1 );
}
echo "organizers are managed in caladmin, and nothing a URL depends on moved.\n";
