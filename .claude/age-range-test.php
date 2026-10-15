<?php
/**
 * AGE RANGE IS ONE OF THREE (3.112.1, item 8).
 *
 *     php .claude/age-range-test.php
 *
 * _uc_age_restriction is chosen from All ages, 18+ and 21+, on Event details
 * (Add event and Edit event) and on the community form, from one list,
 * SFAF_Submit::age_ranges(). Through wp-kit.php:
 *
 *   - the value reader keeps the three in any case or spacing, in the list's
 *     spelling, and answers '' for everything else
 *   - both controls draw a select of exactly the prompt and the three, with
 *     no text box and no "Something else"
 *   - the editor's save stores a choice, clears a shown value when the select
 *     is left empty, and KEEPS a value typed before the list, which the
 *     select never showed
 *   - the community form's validator takes the three and nothing else
 *   - the public reader, which the event page's Ages line asks, shows only
 *     the three
 *
 * PLANT 8 in plant-31121.php lets free text through the public reader.
 */

require __DIR__ . '/wp-kit.php';
if ( ! function_exists( 'wpautop' ) ) { function wpautop( $s ) { return $s; } }

$fails = array();
$ran   = 0;
function ar( $label, $got, $want ) {
    global $fails, $ran;
    $ran++;
    if ( $got !== $want ) { $fails[] = $label . ': got ' . json_encode( $got ) . ', wanted ' . json_encode( $want ); }
}
function ar_cap( $fn ) { ob_start(); try { $fn(); } catch ( Throwable $t ) { ob_end_clean(); return 'THREW ' . $t->getMessage(); } return ob_get_clean(); }
/** The options of the one select named $name, as value => text. */
function ar_options( $html, $name ) {
    if ( ! preg_match( '#<select name="' . preg_quote( $name, '#' ) . '"[^>]*>(.*?)</select>#s', $html, $m ) ) { return null; }
    preg_match_all( '#<option value="([^"]*)"([^>]*)>([^<]*)</option>#', $m[1], $o, PREG_SET_ORDER );
    $out = array();
    foreach ( $o as $x ) { $out[] = array( $x[1], trim( $x[3] ), false !== strpos( $x[2], 'selected' ) ); }
    return $out;
}

/* ---- The list and the reader. ------------------------------------------- */
ar( 'the list', SFAF_Submit::age_ranges(), array( 'All ages', '18+', '21+' ) );
foreach ( array( 'All ages' => 'All ages', 'All Ages' => 'All ages', 'all ages' => 'All ages', ' ALL  AGES ' => 'All ages', 'allages' => 'All ages',
    '18+' => '18+', '18 +' => '18+', ' 21 + ' => '21+', '21+ after 9pm' => '', 'Adults' => '', '18' => '', 'other' => '', '' => '', '13+' => '' ) as $in => $want ) {
    ar( 'age_value(' . json_encode( $in ) . ')', SFAF_Submit::age_value( $in ), $want );
}

/* ---- Event details on Add event and Edit event. -------------------------- */
$portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();
$want_opts = function ( $sel ) {
    $o = array( array( '', SFAF_Submit::AGE_PROMPT, '' === $sel ) );
    foreach ( SFAF_Submit::age_ranges() as $r ) { $o[] = array( $r, $r, $r === $sel ); }
    return $o;
};
$add = ar_cap( function () use ( $portal ) { kit_call( 'SFAF_Portal', 'render_listing_fields', $portal, array( 0, 'details' ) ); } );
ar( 'Add event: the age range is a select of the prompt and the three', ar_options( $add, 'listing_age' ), $want_opts( '' ) );
ar( 'Add event: no age text box', (bool) preg_match( '#<input[^>]*name="listing_age"#', $add ), false );
ar( 'Add event: no Something else', false !== stripos( $add, 'Something else' ), false );

$ev = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_title' => 'Drop-in' ) );
foreach ( array( 'All Ages' => 'All ages', '21 +' => '21+', '21+ after 9pm' => '' ) as $stored => $sel ) {
    update_post_meta( $ev, SFAF_Submit::META_AGE, $stored );
    $edit = ar_cap( function () use ( $portal, $ev ) { kit_call( 'SFAF_Portal', 'render_listing_fields', $portal, array( $ev, 'details' ) ); } );
    ar( 'Edit event, stored ' . json_encode( $stored ) . ': the select shows ' . json_encode( $sel ? $sel : 'the prompt' ), ar_options( $edit, 'listing_age' ), $want_opts( $sel ) );
}

$save = function ( $stored, $posted ) use ( $portal, $ev ) {
    if ( null === $stored ) { delete_post_meta( $ev, SFAF_Submit::META_AGE ); } else { update_post_meta( $ev, SFAF_Submit::META_AGE, $stored ); }
    $_POST = array( 'uc_details_extra_present' => '1', 'listing_age' => $posted, 'contact_name' => '', 'contact_phone' => '', 'contact_email' => '' );
    kit_call( 'SFAF_Portal', 'save_listing_fields', $portal, array( $ev ) );
    $m = get_post_meta( $ev );
    return array_key_exists( SFAF_Submit::META_AGE, $m ) ? $m[ SFAF_Submit::META_AGE ] : null;
};
ar( 'save: nothing stored, 18+ chosen', $save( null, '18+' ), '18+' );
ar( 'save: All Ages stored, All ages chosen, written in the list\'s spelling', $save( 'All Ages', 'All ages' ), 'All ages' );
ar( 'save: 21+ stored, the prompt chosen, clears it', $save( '21+', '' ), null );
ar( 'save: nothing stored, the prompt chosen, stores nothing', $save( null, '' ), null );
ar( 'save: free text posted is not stored', $save( null, 'Adults only' ), null );
ar( 'save: an old free-text value, the prompt left alone, is KEPT', $save( '21+ after 9pm', '' ), '21+ after 9pm' );
ar( 'save: an old free-text value replaced by a choice', $save( '21+ after 9pm', '21+' ), '21+' );

/* ---- The community form. ------------------------------------------------- */
$form = ar_cap( function () { kit_call( 'SFAF_Submit', 'render_form', null, array( (object) array( 'slug' => 'alpha', 'name' => 'Alpha', 'term_id' => 11 ), array(), array() ) ); } );
ar( 'community form: the age range is a select of the prompt and the three', ar_options( $form, 'age_restriction' ), $want_opts( '' ) );
ar( 'community form: no age text box', (bool) preg_match( '#name="age_other"#', $form ), false );
foreach ( array( '21+' => '21+', 'All Ages' => 'All ages', 'other' => '', '18 and over' => '', '' => '' ) as $posted => $want ) {
    $v = SFAF_Submit::validate( array( 'age_restriction' => $posted ) );
    ar( 'community form validator: ' . json_encode( $posted ), $v['clean']['age'], $want );
    ar( 'community form validator: ' . json_encode( $posted ) . ' asks nothing more', isset( $v['errors']['age_other'] ), false );
}
ar( 'community form: no age_other rule left', array_key_exists( 'age_other', SFAF_Submit::required_fields() ), false );

/* ---- The public reader. -------------------------------------------------- */
foreach ( array( '18 +' => '18+', 'all ages' => 'All ages', '21+ after 9pm' => '', 'Teens welcome' => '' ) as $stored => $want ) {
    update_post_meta( $ev, SFAF_Submit::META_AGE, $stored );
    ar( 'event page reader, stored ' . json_encode( $stored ), sfaf_event_age_restriction( $ev ), $want );
}
$tpl = (string) file_get_contents( dirname( __DIR__ ) . '/templates/single-uc_event.php' );
ar( 'the event page reads the age range through the reader', (bool) preg_match( '#\$ages\s*=\s*sfaf_event_age_restriction\( \$post_id \);#', $tpl ), true );

echo "\nAge range, one of three\n" . str_repeat( '=', 72 ) . "\n";
echo "ran $ran assertions.\n";
if ( $fails ) { echo count( $fails ) . " failed:\n  - " . implode( "\n  - ", $fails ) . "\n"; exit( 1 ); }
echo "every control offers All ages, 18+ and 21+, an old free-text value is kept and shown nowhere.\n";
exit( 0 );
