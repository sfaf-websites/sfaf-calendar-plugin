<?php
/**
 * A THIRD-PARTY EVENT TAKES ITS REGISTRATIONS AT THE SOURCE, AND THE BUTTON ROW.
 *
 *     php .claude/source-rsvps-test.php
 *     php .claude/source-rsvps-test.php --self-test
 *
 * TWO PIECES, AND THEY FAIL IN OPPOSITE DIRECTIONS.
 *
 * THE FIRST IS A REFUSAL, and the thing to be careful about is refusing too
 * much. A GoFundMe Pro campaign or an Eventbrite listing counts its places on
 * its own page: a second registration here would be a second list nobody
 * reconciles, and somebody who signed up on this calendar would not be on the
 * platform's door list. But a HAND-MADE event is this calendar's own whatever
 * links it carries, and locking one of those takes a working feature away from
 * somebody who was using it. So the rule keys on the import source and nothing
 * else, and both directions are asserted.
 *
 * THE SECOND IS AN ORDERING, and the thing to be careful about is the keyboard.
 * A browser sends Enter in a text field to the form's first submit button in
 * DOCUMENT order. Delete must never be that button, and the row is now drawn
 * with Delete on the LEFT, so order on screen and order in the markup are
 * deliberately different things. `order` in CSS moves neither the document nor
 * the keyboard, which is why it is the mechanism.
 *
 * Delete is also not a submit of the event form at all: it carries
 * `form="uc-delete-event-N"`. Both mechanisms are asserted, because the `form=`
 * attribute is one tidy-up away from being dropped.
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
 * WordPress, in miniature.
 * ------------------------------------------------------------------------ */
$GLOBALS['pmeta'] = array();
function get_post_meta( $id, $key, $single = false ) {
    return isset( $GLOBALS['pmeta'][ $id ][ $key ] ) ? $GLOBALS['pmeta'][ $id ][ $key ] : '';
}
function update_post_meta( $id, $key, $v ) { $GLOBALS['pmeta'][ $id ][ $key ] = $v; return true; }

/* ---------------------------------------------------------------------------
 * THE THREE READERS ARE LIFTED FROM SOURCE, NOT MODELLED.
 *
 * THEY WERE MODELLED FIRST, AND TWO PLANTS WENT STRAIGHT PAST: "a hand-made
 * event is locked by mistake" and "the reader stops refusing". Both edited the
 * shipped functions, and this file was running its own copies, so nothing it
 * asserted could see either change. Those are the two most important
 * assertions in the file, and they were decorative.
 *
 * A reader a test also writes is not under test. So the bodies come out of the
 * real files, brace-balanced, and are handed to PHP: an unbalanced lift is a
 * parse error at eval() and the file stops, which is the loudest way to find
 * out this is reading nothing.
 * ------------------------------------------------------------------------ */
$sources_src = file_get_contents( $root . '/includes/class-sfaf-sources.php' );
$tpl_src     = file_get_contents( $root . '/includes/sfaf-template-functions.php' );

/** Take one brace-balanced block out of a file, starting at a signature. */
function sfaf_lift( $src, $needle, $what ) {
    $at = strpos( $src, $needle );
    if ( false === $at ) {
        echo 'FAIL: could not find ' . $what . " in the source, so this test is reading nothing.\n";
        exit( 1 );
    }
    $open  = strpos( $src, '{', $at );
    $depth = 0;
    for ( $i = $open; $i < strlen( $src ); $i++ ) {
        if ( '{' === $src[ $i ] ) { $depth++; }
        if ( '}' === $src[ $i ] ) {
            $depth--;
            if ( 0 === $depth ) { return substr( $src, $at, $i - $at + 1 ) . "\n"; }
        }
    }
    echo 'FAIL: ' . $what . " never closes.\n";
    exit( 1 );
}
/** And one class constant, so its VALUE is the plugin's. */
function sfaf_lift_const( $src, $name ) {
    if ( ! preg_match( '/^\s*const\s+' . preg_quote( $name, '/' ) . '\s*=\s*[^;]+;/m', $src, $m ) ) {
        echo 'FAIL: could not find const ' . $name . " in the source.\n";
        exit( 1 );
    }
    return trim( $m[0] ) . "\n";
}

eval( 'class SFAF_Sources {'
    . sfaf_lift_const( $sources_src, 'META_SOURCE' )
    . sfaf_lift_const( $sources_src, 'META_SOURCE_URL' )
    . sfaf_lift( $sources_src, 'public static function takes_rsvps_at_source(', 'takes_rsvps_at_source()' )
    . sfaf_lift( $sources_src, 'public static function registration_url(', 'registration_url()' )
    . '}' );

/* sfaf_show_feature() is NOT under test and is modelled at its documented
 * default: absent meta means on. Getting it backwards would make the
 * assertions below pass for the wrong reason. */
function sfaf_show_feature( $post_id, $feature ) {
    $v = get_post_meta( $post_id, '_uc_show_' . $feature, true );
    return ( '' === $v ) ? true : ( '1' === (string) $v );
}
eval( sfaf_lift( $tpl_src, 'function sfaf_event_takes_rsvps(', 'sfaf_event_takes_rsvps()' ) );

$HAND = 10;   // a hand-made event
$GFMP = 11;   // a GoFundMe Pro campaign
$EB   = 12;   // an Eventbrite listing

/* =========================================================================
 * 1. WHICH EVENTS ARE LOCKED, AND WHICH ARE NOT.
 * ====================================================================== */
$GLOBALS['pmeta'] = array();
update_post_meta( $GFMP, SFAF_Sources::META_SOURCE, 'gfmp' );
update_post_meta( $EB, SFAF_Sources::META_SOURCE, 'eventbrite' );

check( ! SFAF_Sources::takes_rsvps_at_source( $HAND ),
    'a hand-made event is treated as taking registrations elsewhere, which takes a working feature off somebody who was using it' );
check( SFAF_Sources::takes_rsvps_at_source( $GFMP ), 'a GoFundMe Pro campaign is not locked' );
check( SFAF_Sources::takes_rsvps_at_source( $EB ), 'an Eventbrite listing is not locked' );

/* A HAND-MADE EVENT IS UNTOUCHED WHATEVER LINKS IT CARRIES. This is the "too
 * much" direction, and it is the one worth being most careful about. */
update_post_meta( $HAND, '_uc_gofundme_url', 'https://gofund.me/abc' );
update_post_meta( $HAND, '_uc_source_url', 'https://example.test/somewhere' );
update_post_meta( $HAND, SFAF_Sources::META_SOURCE, '' );
check( ! SFAF_Sources::takes_rsvps_at_source( $HAND ),
    'a hand-made event with a donate URL, a typed source URL and an empty source string is now locked' );

/* AND THE SWITCH ITSELF. A source event never takes registrations here even
 * with the meta saying it does, which is what makes the reader the second
 * mechanism rather than a repeat of the save. */
update_post_meta( $GFMP, '_uc_rsvp_enabled', '1' );
update_post_meta( $HAND, '_uc_rsvp_enabled', '1' );
check( ! sfaf_event_takes_rsvps( $GFMP ),
    'a source event with the RSVP meta still set to 1 takes registrations here, so a row written before this rule leaks a form' );
check( sfaf_event_takes_rsvps( $HAND ),
    'a hand-made event with RSVPs on no longer takes them' );

/* =========================================================================
 * 2. THE SAVE REFUSES, WHATEVER IS POSTED.
 * ====================================================================== */
$portal = file_get_contents( $root . '/includes/class-sfaf-portal.php' );

/* WRITTEN AS A WRITE OF '0', NOT AS A SKIP. Skipping would leave an event that
 * already had RSVPs on still carrying them, which is the state this whole piece
 * exists to end. */
check( (bool) preg_match( "/if \( SFAF_Sources::takes_rsvps_at_source\( \\\$event_id \) \) \{\s*\n\s*update_post_meta\( \\\$event_id, '_uc_rsvp_enabled', '0' \);/", $portal ),
    'the save no longer forces the RSVP switch off for a source event, so a hand-edited POST can turn it on' );
check( (bool) preg_match( "/\} else \{\s*\n\s*update_post_meta\( \\\$event_id, '_uc_rsvp_enabled', isset\( \\\$_POST\['rsvp_enabled'\] \) \? '1' : '0' \);/", $portal ),
    'a hand-made event no longer saves its own RSVP answer' );

/* THE CONTROL IS SHOWN, LOCKED AND OFF, NOT HIDDEN. A control that vanishes
 * leaves somebody looking for it; one that is off and disabled answers the
 * question they came with. */
check( (bool) preg_match( "/\\\$at_source = \\\$event_id \? SFAF_Sources::takes_rsvps_at_source\( \\\$event_id \) : false;/", $portal ),
    'the Accept RSVPs control no longer asks whether the event takes registrations at its source' );
/* ASSERTED AS A RELATIONSHIP, NOT AS THE BYTES (3.97.2).
 *
 * These two read `disabled( $at_source )` and `checked( ! $at_source && ... )`
 * literally, so 3.97.2 broke them by widening both expressions to admit the
 * hybrid lock, WITHOUT changing what either does on a source event. A test that
 * fails when nothing it is about has changed gets edited to match rather than
 * read, which is how the next real break gets waved through.
 *
 * WHAT ACTUALLY HAS TO HOLD is that a source event is disabled whatever else is
 * true, and is never ticked. That is two facts about the expressions rather
 * than one fact about their spelling:
 *
 *   disabled(...)  NAMES $at_source, so the source event locks whatever the
 *                  other disjunct is doing.
 *   $rsvp_forced   OPENS with `! $at_source`, so the term that could tick the
 *                  box is false on a source event, and the tick falls back to
 *                  the `! $at_source && ...` it always was.
 */
check( (bool) preg_match( '/disabled\( \$at_source(?: \|\| [^)]+)? \)/', $portal ),
    'the Accept RSVPs control is no longer disabled on a source event' );
check( (bool) preg_match( '/checked\([^)]*! \$at_source && .1. === \(string\) \$g\( ._uc_rsvp_enabled. \)/', $portal ),
    'the Accept RSVPs control can still render TICKED on a source event, which says the opposite of what the save will do' );
/* AND THE HYBRID TERM CANNOT REACH A SOURCE EVENT. It is the only other thing
 * that ticks this box, and an imported event can never be hybrid anyway:
 * import_event() refuses every one of the format keys. Written down rather than
 * left to that, because the ORDER of the two rules is the assertion. */
check( (bool) preg_match( '/\$rsvp_forced = \( ! \$at_source &&/', $portal ),
    'the hybrid RSVP lock is no longer gated on the event not being a source event, so an imported event could render ticked' );
/* AND IT SAYS WHERE REGISTRATION HAPPENS. One line, naming the platform. */
check( (bool) preg_match( '/People register on <\?php echo esc_html\( \$ctx\[.prov.\]\[.label.\] \); \?>/', $portal ),
    'the locked control no longer says where people actually register' );

/* BOTH CAPACITY BOXES ARE HIDDEN, and hidden rather than locked: a disabled box
 * reading 0 is a number that looks like a limit, and there is no number of
 * places this calendar could hold for an event counted somewhere else. */
check( (bool) preg_match( "/if \( \\\$event_id && SFAF_Sources::takes_rsvps_at_source\( \\\$event_id \) \) \{\s*\n\s*break;/", $portal ),
    'the in-person capacity box is still drawn on a source event' );
check( (bool) preg_match( "/\|\| SFAF_Sources::takes_rsvps_at_source\( \\\$event_id \) \) \{\s*\n\s*break;/", $portal ),
    'the online capacity box is still drawn on a source event' );

/* =========================================================================
 * 3. THE EVENT PAGE SENDS THEM TO THE SOURCE INSTEAD.
 * ====================================================================== */
$tpl = file_get_contents( $root . '/includes/sfaf-template-functions.php' );

check( (bool) preg_match( '/\$at_source = SFAF_Sources::registration_url\( \$post_id \);/', $tpl ),
    'the event page no longer offers the source registration link' );
/* ABOVE THE takes_rsvps GUARD. That guard answers false for these events by
 * design, so below it the page would have no way to register at all, which is
 * worse than the RSVP button it replaces. */
$at_link  = strpos( $tpl, '$at_source = SFAF_Sources::registration_url( $post_id );' );
$at_guard = strpos( $tpl, 'if ( ! sfaf_event_takes_rsvps( $post_id ) ) {' );
check( false !== $at_link && false !== $at_guard && $at_link < $at_guard,
    'the source link is built below the guard that returns for these very events, so it is never reached' );
/* IN PLACE OF THE RSVP BUTTON, NOT BESIDE IT: it returns immediately. */
check( (bool) preg_match( "/if \( '' !== \\\$at_source \) \{[\s\S]{0,1400}?return '<div class=\"uc-card-rsvp uc-card-rsvp-source\">'/", $tpl ),
    'the source link no longer replaces the RSVP block, so a page can offer two registration routes' );
check( (bool) preg_match( "/'external' => true,/", $tpl ),
    'the source link no longer opens in a new tab with rel noopener, which PROJECT.md 1 records as load bearing' );

/* THE URL IS THE ONE THE IMPORT ALREADY CARRIES, so nothing new is stored and
 * nothing can go stale: source_url is in both adapters' owned_fields(). */
$GLOBALS['pmeta'] = array();
update_post_meta( $EB, SFAF_Sources::META_SOURCE, 'eventbrite' );
update_post_meta( $EB, SFAF_Sources::META_SOURCE_URL, 'https://eventbrite.test/e/123' );
check( 'https://eventbrite.test/e/123' === SFAF_Sources::registration_url( $EB ),
    'the registration URL is not the source page the import stored' );
check( '' === SFAF_Sources::registration_url( $HAND ),
    'a hand-made event offers a source registration URL' );
/* A SOURCE EVENT WITH NO URL DRAWS NOTHING rather than an empty button. */
$GLOBALS['pmeta'] = array();
update_post_meta( $GFMP, SFAF_Sources::META_SOURCE, 'gfmp' );
check( '' === SFAF_Sources::registration_url( $GFMP ),
    'a source event with no stored URL still produces a registration link' );

/* =========================================================================
 * 4. THE ONE-TIME PASS.
 * ====================================================================== */
$main = file_get_contents( $root . '/sfaf-calendar.php' );

check( (bool) preg_match( '/function sfaf_migrate_source_rsvps\(/', $main ),
    'the one-time pass is gone, so an imported event with RSVPs already on keeps them' );
check( (bool) preg_match( "/define\( 'SFAF_DB_VERSION', '([8-9]|\d\d+)' \)/", $main ),
    'the schema version was not bumped, so the pass never runs on a site updated by overwriting the folder' );
check( (bool) preg_match( '/sfaf_migrate_source_rsvps\(\);/', $main ),
    'the one-time pass is never called' );

/* THE KEY COMES FROM THE CLASS. A migration querying the wrong meta key finds
 * nothing, writes nothing, reports zero and looks exactly like one that had
 * nothing to do. That is the worst shape a one-time pass can fail in. */
check( (bool) preg_match( "/'key' => SFAF_Sources::META_SOURCE,/", $main ),
    'the migration names the source meta key as a literal, and it is not the name anybody guesses' );
check( false === strpos( $main, "'key' => '_uc_source'," ),
    'the migration is querying _uc_source, which is not the key: the real one is _uc_external_source' );

/* IT REPORTS, EVEN WHEN THE COUNT IS ZERO, so "nothing needed doing" and "it
 * never ran" are different answers. */
check( (bool) preg_match( "/update_option\( 'sfaf_source_rsvp_migration', array\(/", $main ),
    'the pass no longer records how many events it touched' );
/* AND IT DELETES NOTHING. Somebody registered in good faith; turning the switch
 * off must not remove them from a list an organizer still needs. */
$mig = '';
$at  = strpos( $main, 'function sfaf_migrate_source_rsvps(' );
if ( false !== $at ) {
    $open = strpos( $main, '{', $at );
    $d = 0;
    for ( $i = $open; $i < strlen( $main ); $i++ ) {
        if ( '{' === $main[ $i ] ) { $d++; }
        if ( '}' === $main[ $i ] ) { $d--; if ( 0 === $d ) { $mig = substr( $main, $at, $i - $at + 1 ); break; } }
    }
}
check( '' !== $mig, 'the migration could not be read' );
check( false === strpos( $mig, 'DELETE' ) && false === strpos( $mig, 'wpdb' ),
    'the one-time pass reaches the database directly; it must not remove anybody who already registered' );
check( false === strpos( $mig, 'delete_post_meta' ),
    'the one-time pass deletes meta rather than writing the switch off' );

/* AND THE COUNT IS ON A SCREEN SOMEBODY LOOKS AT, outside the branch that only
 * a site with NO sources connected ever reaches. */
check( (bool) preg_match( '/private function render_source_rsvp_migration_note\(/', $portal ),
    'nothing reports what the one-time pass touched' );
check( (bool) preg_match( '/\$this->render_source_rsvp_migration_note\(\);\s*\n\s*\n?\s*\$key\s*=/', $portal ),
    'the migration count is drawn inside a branch, and a site with sources connected is never in it' );

/* =========================================================================
 * 5. THE BUTTON ROW: THE KEYBOARD FIRST.
 * ====================================================================== */
/* A browser sends Enter in a text field to the form's FIRST SUBMIT in DOCUMENT
 * order. Two mechanisms keep Delete away from that, and both are asserted
 * because either one alone is an edit away from being dropped. */

/* ONE: Delete is not a submit of the event form at all. */
check( (bool) preg_match( '/<button type="submit" form="uc-delete-event-<\?php echo \(int\) \$event_id; \?>"/', $portal ),
    'the Delete button no longer names the delete form, so it became a submit of the event form and Enter can reach it' );

/* TWO: Save draft is still first in the markup. Positions, not presence. */
$save_at = strpos( $portal, "name=\"save_mode\" value=\"<?php echo \$keep_status ? 'keep' : 'draft'; ?>\"" );
$del_at  = strpos( $portal, '<button type="submit" form="uc-delete-event-' );
check( false !== $save_at && false !== $del_at && $save_at < $del_at,
    'Delete comes before Save draft in the markup, and the markup is what the keyboard reads' );

/* AND THE ORDER ON SCREEN IS CSS, WHICH MOVES NEITHER. */
$css = file_get_contents( $root . '/public/css/portal.css' );
foreach ( array(
    'uc-editor-delete'  => 1,
    'uc-cancel-inline'  => 2,
    'uc-editor-save'    => 3,
    'uc-editor-publish' => 4,
) as $cls => $n ) {
    check( (bool) preg_match( '/\.uc-editor-actions \.' . $cls . '\s*\{ order: ' . $n . '; \}/', $css ),
        $cls . ' is no longer at order ' . $n . ', so the row does not read destructive to primary' );
}

/* =========================================================================
 * 6. THE BUTTON ROW: THE COLOURS AND THE SIZE.
 * ====================================================================== */
/* TWO ROWS, ONE RULE (3.98.0). A draft ends in Publish; an existing event has
 * no Publish and ends in Save changes. GREEN AND LARGEST mark whichever of the
 * two puts the event in front of people, YELLOW the in-between save, and yellow
 * therefore appears only on the draft row. Both invert what DESIGN.md says
 * about yellow, which is why DESIGN.md records the exception by name.
 *
 * ASSERTED AS THE TERNARY, NOT AS ONE RENDERED CLASS STRING. The class is now
 * decided per state, so matching one literal spelling would pass for whichever
 * row happened to be written first and say nothing about the other. */
check( (bool) preg_match( '/\$save_main = \$keep_status \? \x27 uc-btn-go uc-editor-save-main\x27 : \x27 uc-btn-primary\x27;/', $portal ),
    'the save button no longer picks its colour from the event state, so one of the two rows is wrong' );
check( (bool) preg_match( '/class="uc-btn<\?php echo esc_attr\( \$save_main \); \?> uc-editor-save"/', $portal ),
    'the save button stopped taking the class the state chose' );
check( (bool) preg_match( '/class="uc-btn uc-btn-go uc-editor-publish"/', $portal ),
    'Publish is no longer green' );
check( (bool) preg_match( '/class="uc-btn uc-btn-stop uc-editor-delete"/', $portal ),
    'Delete is no longer red' );

/* THE MEASURED PAIRS ARE UNCHANGED. Both are 700 weights already on this screen
 * and both clear 4.5:1 on white text. */
check( (bool) preg_match( '/\.uc-portal \.uc-btn-go \{ background: #15803D;/', $css ),
    'the green moved off the 700 weight measured at 5.02:1' );
check( (bool) preg_match( '/\.uc-portal \.uc-btn-stop \{ background: #c0392b;/', $css ),
    'the red moved off the shade measured at 5.44:1' );
/* AND YELLOW KEEPS ITS DARK GREY INK, which is the only text colour on it. */
check( (bool) preg_match( '/\.uc-btn-primary,\s*\n\.uc-form-links-open \{ background: var\(--uc-primary\); border-color: #E0BE00; color: var\(--sfaf-darkgray\); \}/', $css ),
    'the yellow button lost its dark gray ink; white on yellow measures 1.38:1' );

/* THE MAIN ACTION CARRIES "MAIN" BY SIZE now that it is not the only coloured
 * button in the row, and BOTH rows have one: Publish on a draft, Save changes on
 * an existing event. One rule, one selector list. */
check( (bool) preg_match( '/\.uc-editor-actions \.uc-editor-publish,\s*\n\.uc-editor-actions \.uc-editor-save-main \{\s*\n\s*padding: 13px 30px;/', $css ),
    'the main action is no longer the largest control in its row, so nothing marks it' );

/* AND CANCEL EVENT IS RED, NOT AMBER (3.98.0). It takes the event off the
 * public calendar and mails everybody who registered, which is Delete's
 * neighbourhood of consequence rather than "careful". The two reds are told
 * apart by their labels and by the chevron, which only this one carries. */
check( (bool) preg_match( '/<summary class="uc-btn uc-btn-stop uc-cancel-inline-toggle"/', $portal ),
    'Cancel event is amber again, which reads as "careful" for something that mails every registrant' );
check( false !== strpos( $portal, '<span>Cancel event</span>' ),
    'the cancel control is labelled just "Cancel", which beside Delete does not say what it cancels' );

/* AND DELETE KEEPS ITS CONFIRMATION. */
check( (bool) preg_match( '/data-uc-confirm="Delete this event\? Nothing puts it back\."/', $portal ),
    'the Delete button lost its confirmation' );

/* =========================================================================
 * SELF-TEST.
 * ====================================================================== */
if ( $self_test ) {
    $bad = array();

    $before = count( $fails );
    check( SFAF_Sources::takes_rsvps_at_source( 99999 ), 'deliberate: an unknown event reported as a source event' );
    if ( count( $fails ) === $before ) {
        $bad[] = 'check() does not record a failure, so every assertion above is decorative';
    } else {
        array_pop( $fails );
    }

    /* The reader must really refuse, proving section 1 by construction. */
    $GLOBALS['pmeta'] = array();
    update_post_meta( 5, SFAF_Sources::META_SOURCE, 'gfmp' );
    update_post_meta( 5, '_uc_rsvp_enabled', '1' );
    if ( sfaf_event_takes_rsvps( 5 ) ) {
        $bad[] = 'the modelled reader does not refuse a source event, so section 1 proves nothing';
    }
    update_post_meta( 6, '_uc_rsvp_enabled', '1' );
    if ( ! sfaf_event_takes_rsvps( 6 ) ) {
        $bad[] = 'the modelled reader refuses a hand-made event too, so it is not a filter, it is an off switch';
    }

    if ( $bad ) {
        echo "SELF-TEST FAILED\n";
        foreach ( $bad as $b ) { echo '  . ' . $b . "\n"; }
        exit( 1 );
    }
    echo "PASS  source-rsvps --self-test       self-test passed: the reader reports faults and refuses only source events.\n";
    exit( 0 );
}

if ( $fails ) {
    echo 'FAIL: ' . count( $fails ) . "\n";
    foreach ( array_unique( $fails ) as $f ) { echo '  . ' . $f . "\n"; }
    exit( 1 );
}

echo "a third-party event registers at its source and nowhere else, a hand-made one is\n";
echo "untouched, and Enter in the title field still reaches Save draft rather than Delete.\n";
exit( 0 );
