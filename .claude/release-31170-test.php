<?php
/**
 * 3.117.0: THE EVENT EDITOR'S TIDY-UPS AND ITS UNUSED CODE.
 *
 *     php .claude/release-31170-test.php
 *
 * The real plugin through wp-kit.php.
 *
 *   A  Replies go to holds only the event's own address; the fallback is the
 *      placeholder and the line under it names it
 *   B  Follow the series is hidden on an event in no series, and the script
 *      follows the series dropdown
 *   C  a scheduled event has one green button: Schedule; Save changes plain
 *   D  the words: Organizers, what capacity 0 does, the tour's picture line
 *   E  the unused code is gone: the wp.media picker branch and its script, the
 *      fundraising control and its save
 *
 * Follow the series in Chrome is release-31170-live.php. PLANTs in plant-31170.php.
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
$src    = file_get_contents( $root . '/includes/class-sfaf-portal.php' );
$js     = file_get_contents( $root . '/public/js/portal.js' );
$admin  = new WP_User();
$_GET   = array();
$GLOBALS['kit_query'] = function ( $a ) { return array(); };

function rt_event( $status, $series = 0, $meta = array() ) {
    $id = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => $status, 'post_title' => 'An event', 'post_author' => 1 ) );
    foreach ( array_merge( array( '_uc_event_date' => '2027-01-14', '_uc_start_time' => '18:00', '_uc_end_time' => '19:00' ), $meta ) as $k => $v ) { update_post_meta( $id, $k, $v ); }
    if ( $series ) { $GLOBALS['kit_terms'][ $id ]['uc_series'] = array( $series ); }
    return $id;
}
function rt_form( $portal, $admin, $id ) { return rt_cap( function () use ( $portal, $admin, $id ) { kit_call( 'SFAF_Portal', 'render_event_form', $portal, array( $admin, $id ) ); } ); }

/* ======================================================================== A */
$plain = rt_event( 'publish', 11 );
$own   = rt_event( 'publish', 11, array( '_uc_email_replyto' => 'team@sfaf.org' ) );
$h = rt_form( $portal, $admin, $plain );
rt( 'A: with no address of its own the box is empty', (bool) preg_match( '#name="event_replyto" id="uc-event-replyto"\s+value=""#', $h ), true );
rt( 'A: the fallback is the placeholder', (bool) preg_match( '#name="event_replyto"[^>]*placeholder="[^"]+@[^"]+"#', $h ), true );
rt( 'A: and the line names it', (bool) preg_match( '#<span class="uc-hint">Leave it empty to send replies to [^<]+@[^<]+\.</span>#', $h ), true );
rt( 'A: the old line is gone', false === strpos( $h, 'Empty sends replies to' ), true );
$h = rt_form( $portal, $admin, $own );
rt( 'A: its own address is shown', (bool) preg_match( '#name="event_replyto" id="uc-event-replyto"\s+value="team@sfaf.org"#', $h ), true );
rt( 'A: with no line under it', false === strpos( $h, 'Leave it empty to send replies to' ), true );
$h = rt_form( $portal, $admin, 0 );
rt( 'A: Add event starts empty too', (bool) preg_match( '#name="event_replyto" id="uc-event-replyto"\s+value=""#', $h ), true );

/* ======================================================================== B */
$lone = rt_event( 'publish', 0 );
$h = rt_form( $portal, $admin, $lone );
rt( 'B: Follow the series is hidden on an event in no series', (bool) preg_match( '#<label class="uc-check" data-uc-follow-check hidden>#', $h ), true );
rt( 'B: and its tick still posts', false !== strpos( $h, 'name="show_reminders"' ), true );
$h = rt_form( $portal, $admin, $plain );
rt( 'B: and shown on one in a series', (bool) preg_match( '#<label class="uc-check" data-uc-follow-check>#', $h ), true );
rt( 'B: the script follows the dropdown', false !== strpos( $js, "run('follow-check', initFollowCheck);" ) && false !== strpos( $js, "label.hidden = !(select.value && select.value !== '0');" ), true );

/* ======================================================================== C */
$soon = rt_event( 'future', 11 );
$h = rt_form( $portal, $admin, $soon );
rt( 'C: a scheduled event\'s Save changes is plain', (bool) preg_match( '#name="save_mode" value="keep" class="uc-btn uc-editor-save">Save changes</button>#', $h ), true );
rt( 'C: and one green button on its bar', 1 === substr_count( substr( $h, (int) strpos( $h, 'data-uc-editor-bar' ) ), 'uc-btn-go' ), true );
$h = rt_form( $portal, $admin, $plain );
rt( 'C: a published one keeps its green Save changes', (bool) preg_match( '#class="uc-btn uc-btn-go uc-editor-save-main uc-editor-save">Save changes</button>#', $h ), true );

/* ======================================================================== D */
rt( 'D: Organizers', (bool) preg_match( '#<span class="uc-field-label">Organizers<#', $h ), true );
rt( 'D: what 0 does', false !== strpos( $h, '<span class="uc-hint">Enter 0 to send everybody to the waitlist.</span>' ), true );
rt( 'D: the tour no longer sends pictures to the Images screen', false === strpos( $src, 'upload new pictures on the Images screen first' ) && false !== strpos( $src, 'Choose the featured picture, or use + Upload a picture for this event' ), true );
rt( 'D: category ticks take the disabled state as organizer ticks do', false !== strpos( $src, "<?php checked( in_array( (int) \$c->term_id, \$current, true ) ); ?><?php echo \$this->field_disabled( \$state ); ?> />" ), true );

/* ======================================================================== E */
rt( 'E: no wp.media binder', false === strpos( $js, 'function bindImageField' ) && false === strpos( $js, 'function initImagePicker' ), true );
rt( 'E: no Choose Image or All calendar images button', false === strpos( $src, 'uc-choose-image' ), true );
$fn = substr( $src, (int) strpos( $src, 'private function render_image_picker' ), 9000 );
rt( 'E: no wp.media branch in the renderer', false === strpos( $fn, '$inline' ) && false === strpos( $fn, 'Choose Image shows the calendar folder only' ), true );
rt( 'E: no fundraising control', false === strpos( $src, "case 'fundraising_progress':" ) && false === strpos( $src, 'Show the raised and goal figures on this event' ), true );
rt( 'E: and no save for it', false === strpos( $src, "\$_POST['show_fund_progress']" ), true );
rt( 'E: the series picker still renders', false !== strpos( rt_cap( function () use ( $portal, $admin ) { kit_call( 'SFAF_Portal', 'render_series_edit', $portal, array( $admin, 11 ) ); } ), 'data-uc-image-block' ), true );

echo "\n3.117.0: the event editor's tidy-ups and its unused code\n" . str_repeat( '=', 72 ) . "\n";
if ( $fails ) { echo count( $fails ) . " of $ran failed:\n  - " . implode( "\n  - ", $fails ) . "\n"; exit( 1 ); }
echo "$ran assertions passed. Replies go to holds the event's own address with the fallback named, Follow the series only on an\nevent in one, one green button on a scheduled event, the editor's words fixed, and the media box and fundraising code gone.\n";
exit( 0 );
