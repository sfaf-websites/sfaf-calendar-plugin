<?php
/**
 * 3.115.0: THE SERIES PAGE'S RSVP CHOICE, ITS GATE, AND LANGUAGE WITH NO SERIES.
 *
 *     php .claude/release-31150-test.php
 *
 * The real plugin through wp-kit.php.
 *
 *   A  the series page offers No RSVP | RSVP Here | External RSVP, from the
 *      event editor's one renderer, and stores each; a series that stored
 *      nothing is No RSVP, which is what its events already started on
 *   B  a new event starts on its series' choice, RSVP Here included, and Add
 *      event's series dropdown carries each series' choice for the script
 *   C  only somebody who has every event in the series may change it: anybody
 *      else sees it read-only, and their save stores nothing
 *   D  Add and Edit event keep Language when there is no series at all
 *
 * The script half is release-31150-live.php. PLANTs in plant-31150.php.
 */

require __DIR__ . '/wp-kit.php';

$fails = array();
$ran   = 0;
function rt( $label, $got, $want ) {
    global $fails, $ran;
    $ran++;
    if ( $got !== $want ) { $fails[] = $label . ': got ' . var_export( $got, true ) . ', wanted ' . var_export( $want, true ); }
}
function rt_cap( $fn ) { ob_start(); try { $fn(); } catch ( KitRedirect $e ) { } return (string) ob_get_clean(); }
$root   = $GLOBALS['kit_root'];
$portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();
$RE     = 'SFAF_Register_Elsewhere';

/* ======================================================================== A */
rt( 'A: nothing stored is No RSVP', $RE::series_choice( 11 ), 'none' );
rt( 'A: and the event fallback still reads "not elsewhere"', $RE::series_mode( 11 ), 'here' );
$admin = new WP_User();
$page = rt_cap( function () use ( $portal, $admin ) { kit_call( 'SFAF_Portal', 'render_series_edit', $portal, array( $admin, 11 ) ); } );
$box  = (string) strstr( $page, 'data-uc-series-rsvp>' );
$box  = substr( $box, 0, (int) strpos( $box, 'New events in this series start on this.' ) + 60 );
rt( 'A: the three choices', 3 === preg_match_all( '#<input type="radio" name="series_reg_choice" value="(none|here|elsewhere)" data-uc-reg-mode#', $box ), true );
rt( 'A: in the event editor\'s words', false !== strpos( $box, '<span>No RSVP</span>' ) && false !== strpos( $box, '<span>RSVP Here</span>' ) && false !== strpos( $box, '<span>External RSVP</span>' ), true );
rt( 'A: No RSVP chosen for a series that stored nothing', (bool) preg_match( '#value="none" data-uc-reg-mode\s+checked#', $box ), true );
rt( 'A: the old switch is gone', false === strpos( $page, 'Take registrations here' ) && false === strpos( $page, 'Where people register' ), true );
rt( 'A: the link box posts series_reg_url', false !== strpos( $box, 'name="series_reg_url"' ) && false !== strpos( $box, 'id="uc-series-reg-url"' ), true );
foreach ( array( 'here', 'elsewhere', 'none' ) as $c ) {
    $RE::save_series_from_post( 11, array( 'series_reg_choice_present' => '1', 'series_reg_choice' => $c, 'series_reg_url' => 'https://example.org/sign-up' ) );
    rt( 'A: ' . $c . ' is stored as ' . $c, $RE::series_choice( 11 ), $c );
}
rt( 'A: the link is kept', $RE::series_url( 11 ), 'https://example.org/sign-up' );
$RE::save_series_from_post( 11, array( 'series_reg_choice' => 'here' ) );
rt( 'A: a form that did not draw it changes nothing', $RE::series_choice( 11 ), 'none' );
update_term_meta( 11, $RE::SERIES_MODE, 'here' );
$page = rt_cap( function () use ( $portal, $admin ) { kit_call( 'SFAF_Portal', 'render_series_edit', $portal, array( $admin, 11 ) ); } );
rt( 'A: RSVP Here shows as chosen', (bool) preg_match( '#name="series_reg_choice" value="here" data-uc-reg-mode\s+checked#', $page ), true );
rt( 'A: there is one renderer', false === strpos( file_get_contents( $root . '/includes/class-sfaf-register-elsewhere.php' ), 'function render_choice(' ), true );

/* ======================================================================== B */
rt( 'B: a new event in an RSVP Here series starts on RSVP Here', $RE::choice( 0, 11 ), 'here' );
update_term_meta( 11, $RE::SERIES_MODE, 'elsewhere' );
rt( 'B: External RSVP', $RE::choice( 0, 11 ), 'elsewhere' );
delete_term_meta( 11, $RE::SERIES_MODE );
rt( 'B: No RSVP', $RE::choice( 0, 11 ), 'none' );
rt( 'B: no series, No RSVP', $RE::choice( 0, 0 ), 'none' );
update_term_meta( 12, $RE::SERIES_MODE, 'here' );
$form = rt_cap( function () use ( $portal, $admin ) { kit_call( 'SFAF_Portal', 'render_event_form', $portal, array( $admin, 0 ) ); } );
$series_map = preg_match( '#data-uc-reg-series="([^"]*)"#', $form, $m3 ) ? json_decode( html_entity_decode( $m3[1], ENT_QUOTES ), true ) : array();
rt( 'B: series 12 is RSVP Here in the payload', isset( $series_map['12']['choice'] ) ? $series_map['12']['choice'] : null, 'here' );
rt( 'B: series 11 is No RSVP in the payload', isset( $series_map['11']['choice'] ) ? $series_map['11']['choice'] : null, 'none' );
$js = file_get_contents( $root . '/public/js/portal.js' );
rt( 'B: the script follows the series\' choice', false !== strpos( $js, "var want = d.choice || (d.mode === 'elsewhere' ? 'elsewhere' : 'none');" ), true );
rt( 'B: and reads its own box, not the event form\'s field by name', false !== strpos( $js, "var r = box.querySelector('input[data-uc-reg-mode]:checked');" ), true );

/* ======================================================================== C */
$GLOBALS['kit_refused'][] = 77;
$GLOBALS['kit_umeta'][77] = array( '_uc_calendar_role' => 'editor' );
$ed = new WP_User(); $ed->ID = 77; $ed->roles = array( 'editor' );
$other = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_title' => 'Not theirs', 'post_author' => 1 ) );
wp_set_object_terms( $other, array( 11 ), 'uc_series' );
$GLOBALS['kit_get_posts'] = function ( $a ) use ( $other ) { return ( isset( $a['tax_query'][0]['terms'] ) && 11 === (int) $a['tax_query'][0]['terms'] ) ? array( $other ) : array(); };
rt( 'C: the editor does not have every event in series 11', kit_call( 'SFAF_Portal', 'can_manage_series_events', $portal, array( $ed, 11 ) ), false );
update_term_meta( 11, $RE::SERIES_MODE, 'elsewhere' );
update_term_meta( 11, $RE::SERIES_URL, 'https://example.org/sign-up' );
$page = rt_cap( function () use ( $portal, $ed ) { kit_call( 'SFAF_Portal', 'render_series_edit', $portal, array( $ed, 11 ) ); } );
rt( 'C: they see it read-only', (bool) preg_match( '#<p class="uc-muted" data-uc-series-rsvp-readonly>External RSVP: https://example.org/sign-up</p>#', $page ), true );
rt( 'C: with nothing to post', false === strpos( $page, 'name="series_reg_choice"' ) && false === strpos( $page, 'series_reg_choice_present' ), true );
$_POST = array( 'series_reg_choice_present' => '1', 'series_reg_choice' => 'none', 'series_reg_url' => '' );
kit_call( 'SFAF_Portal', 'save_series_access', $portal, array( $ed, 11 ) );
rt( 'C: and a hand-made post changes nothing', $RE::series_choice( 11 ) . ' ' . $RE::series_url( 11 ), 'elsewhere https://example.org/sign-up' );
kit_call( 'SFAF_Portal', 'save_series_access', $portal, array( $admin, 11 ) );
rt( 'C: an admin\'s does', $RE::series_choice( 11 ), 'none' );
$_POST = array();

/* ======================================================================== D */
$GLOBALS['kit_series_none'] = true;
$card = rt_cap( function () use ( $portal ) { kit_call( 'SFAF_Portal', 'render_series_prefill', $portal, array( array(), 0, 0 ) ); } );
rt( 'D: with no series, Add event keeps Language', false !== strpos( $card, 'name="event_language"' ) && false !== strpos( $card, '<h2 class="uc-bento-title">Language</h2>' ), true );
rt( 'D: and offers no series', false === strpos( $card, 'name="series"' ) && false === strpos( $card, 'data-uc-prefill-panel' ), true );
$card = rt_cap( function () use ( $portal ) { kit_call( 'SFAF_Portal', 'render_series_prefill', $portal, array( array(), 0, 9 ) ); } );
rt( 'D: so does Edit event', false !== strpos( $card, 'name="event_language"' ) && false === strpos( $card, 'Moving it changes only' ), true );
$card = rt_cap( function () use ( $portal ) { kit_call( 'SFAF_Portal', 'render_series_prefill', $portal, array( SFAF_Series::all(), 0, 0 ) ); } );
rt( 'D: with series, unchanged', false !== strpos( $card, 'Series and language' ) && false !== strpos( $card, 'name="series"' ) && false !== strpos( $card, 'name="event_language"' ), true );

echo "\n3.115.0: the series page's RSVP choice, its gate, Language with no series\n" . str_repeat( '=', 72 ) . "\n";
if ( $fails ) { echo count( $fails ) . " of $ran failed:\n  - " . implode( "\n  - ", $fails ) . "\n"; exit( 1 ); }
echo "$ran assertions passed. The series page offers the event editor's three RSVP choices and new events start on the\nseries' one; only somebody with every event in the series may change it; Language stays with no series.\n";
exit( 0 );
