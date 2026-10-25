<?php
/**
 * 3.116.0: THE SERIES PAGE BUILT LIKE THE EVENT EDITOR.
 *
 *     php .claude/release-31160-test.php
 *
 * The real render_series_edit() through wp-kit.php.
 *
 *   A  the event editor's two columns of topic cards, each field in its card,
 *      every field still inside the one form that saves the series
 *   B  the sticky bar after the schedule: Save changes (Create series on a new
 *      one) green and reaching the form by form=, Remove series a red link at
 *      its left on a saved series only; no solid red button, no yellow save
 *   C  the leave warning on the form, the required mark and the note
 *   D  the event editor's words for the same fields, and none of the lines
 *      the copy rule removed
 *
 * The page in Chrome is release-31160-live.php. PLANTs in plant-31160.php.
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
$portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();
$_GET   = array();
$page   = rt_cap( function () use ( $portal ) { kit_call( 'SFAF_Portal', 'render_series_edit', $portal, array( new WP_User(), 11 ) ); } );
$new    = rt_cap( function () use ( $portal ) { kit_call( 'SFAF_Portal', 'render_series_edit', $portal, array( new WP_User(), 0 ) ); } );

$doc = new DOMDocument();
libxml_use_internal_errors( true );
$doc->loadHTML( '<?xml encoding="utf-8"?>' . $page );
$xp = new DOMXPath( $doc );
function rt_cards( $xp, $col ) {
    $out = array();
    foreach ( $xp->query( "//div[contains(concat(' ',normalize-space(@class),' '),' $col ')]/section[contains(@class,'uc-bento-card')]" ) as $sec ) {
        $out[] = $sec->getAttribute( 'data-uc-card' );
    }
    return $out;
}
function rt_in_card( $xp, $card, $name ) {
    return $xp->query( "//section[@data-uc-card='$card']//*[@name='$name']" )->length > 0;
}

/* ======================================================================== A */
rt( 'A: the main column\'s cards', rt_cards( $xp, 'uc-bento-main' ), array( 'title', 'details', 'registration', 'new-events', 'notifications', 'faqs' ) );
rt( 'A: the side column\'s cards', rt_cards( $xp, 'uc-bento-side' ), array( 'language', 'classification', 'display', 'links', 'access' ) );
foreach ( array(
    'title' => array( 'series_name', 'series_slug' ),
    'details' => array( 'series_image_id', 'series_desc', 'series_video', 'uc_series_picture' ),
    'registration' => array( 'series_reg_choice', 'series_agreement' ),
    'faqs' => array( 'series_faq_set' ),
    'language' => array( 'series_language' ),
    'classification' => array( 'series_categories[]', 'series_organizers[]' ),
    'links' => array( 'series_donate_url' ),
    'access' => array( 'series_access_present' ),
) as $card => $names ) {
    foreach ( $names as $n ) {
        rt( "A: $n is in the $card card", rt_in_card( $xp, $card, $n ), true );
    }
}
$form = $xp->query( "//form[@id='uc-series-form']" )->item( 0 );
rt( 'A: the cards are inside the series form', $form && $xp->query( ".//div[contains(@class,'uc-bento')]", $form )->length > 0, true );
rt( 'A: no "Series details" single card is left', false === strpos( $page, '<div class="uc-card-head"><h2>Series details</h2></div>' ), true );

/* ======================================================================== B */
$bar = $xp->query( "//div[@data-uc-editor-bar]" )->item( 0 );
rt( 'B: there is the editor bar', null !== $bar && false !== strpos( $bar->getAttribute( 'class' ), 'uc-editor-bar' ), true );
rt( 'B: outside the form', $bar && ! $xp->query( 'ancestor::form', $bar )->length, true );
rt( 'B: after the schedule', strpos( $page, 'data-uc-editor-bar' ) > strpos( $page, 'data-uc-schedule' ) || false === strpos( $page, 'data-uc-schedule' ), true );
rt( 'B: Save changes is green and reaches the form', (bool) preg_match( '#<button type="submit" form="uc-series-form" class="uc-btn uc-btn-go uc-editor-save-main uc-editor-save">Save changes</button>#', $page ), true );
rt( 'B: a new series says Create series', (bool) preg_match( '#form="uc-series-form" class="uc-btn uc-btn-go uc-editor-save-main uc-editor-save">Create series</button>#', $new ), true );
rt( 'B: Remove series is a red link in the bar', $bar && $xp->query( ".//a[contains(@class,'uc-link-danger') and contains(@class,'uc-editor-delete') and contains(@href,'series/remove/11')]", $bar )->length === 1, true );
rt( 'B: and not on a new series', false === strpos( $new, 'Remove series' ), true );
rt( 'B: no solid red button', false === strpos( $page, 'uc-btn uc-btn-danger' ), true );
rt( 'B: no yellow save', false === strpos( $page, "Save Series" ) && false === strpos( $page, "Create Series" ), true );

/* ======================================================================== C */
rt( 'C: the leave warning is on the form', $form && $form->hasAttribute( 'data-uc-leave-warn' ), true );
rt( 'C: the title is marked required', (bool) preg_match( '#<h2 class="uc-bento-title" id="uc-series-title-heading">Title<span class="uc-req" aria-hidden="true">\*</span></h2>#', $page ), true );
rt( 'C: and labels the name', (bool) preg_match( '#name="series_name"[^>]*required aria-labelledby="uc-series-title-heading"#', $page ), true );
rt( 'C: the note says what the mark means', false !== strpos( $page, '<p class="uc-hint uc-required-note">Fields marked * are required.</p>' ), true );

/* ======================================================================== D */
foreach ( array( 'Featured Image', 'Language', 'Donation link in emails', 'Registration agreement', 'Categories', 'Organizers', 'FAQ set', 'Team and access', 'Paste a YouTube or Vimeo address from the browser bar, not embed code.' ) as $w ) {
    rt( "D: \"$w\"", false !== strpos( $page, $w ), true );
}
foreach ( array( 'Default language', 'Default categories', 'Default organizers', 'Default FAQ set', 'Default registration agreement', 'Default team and access',
    'The schedule is below the series details', 'including while it has no dates scheduled', 'A series made by hand', 'because registrations belong',
    'It joins the series for browsing', 'so there is no pattern to change', 'A YouTube or Vimeo link.' ) as $w ) {
    rt( "D: not \"$w\"", false === strpos( $page . $new, $w ), true );
}
$src = file_get_contents( $GLOBALS['kit_root'] . '/includes/class-sfaf-portal.php' );
rt( 'D: the list says what to do, not what a series is', false === strpos( $src, "A series is an event's schedule: the event, and all of its dates." ), true );

echo "\n3.116.0: the series page built like the event editor\n" . str_repeat( '=', 72 ) . "\n";
if ( $fails ) { echo count( $fails ) . " of $ran failed:\n  - " . implode( "\n  - ", $fails ) . "\n"; exit( 1 ); }
echo "$ran assertions passed. Two columns of topic cards in one form, the sticky bar with a green save and Remove series\nas a red link, the leave warning and the required mark, in the event editor's words.\n";
exit( 0 );
