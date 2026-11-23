<?php
/**
 * 3.144.1: REPLY ALERTS AT ONCE, REPLY BADGES, ONE LOOK FOR ERRORS.
 *
 *     php .claude/release-31441-test.php
 *
 * The real SFAF_Texts, SFAF_Text_Inbox and SFAF_Portal on wp-kit.php and
 * text-inbox-kit.php: Twilio recorded, mail and scheduled runs caught.
 *
 *   T  The timing: a person's first reply is emailed in the request that
 *      brought it, and opens a five-minute window with one backstop run at
 *      its close; replies inside the window send nothing and schedule
 *      nothing; any request after the five minutes (wp_loaded) sends them in
 *      one follow-up, "2 more replies", and clears the backstop; a run with
 *      nothing waiting sends nothing; a reply after the window is a first
 *      reply again; a reply arriving after the close but before any run
 *      sends the waiting ones first, in their own email. Who is told is
 *      3.144.0's (release-31440-test.php).
 *   B  The badges: replies new to a person are counted per event, per
 *      person; only on events they may edit; on the sidebar's Events and
 *      beside the RSVP count in the Events list, linked to Replies; seen by
 *      opening the RSVP page, for that person alone, before the sidebar is
 *      drawn; Replies opens on new ones and marks each "New".
 *   E  The errors: every caladmin error class takes the Red tint, a 1px
 *      brand red edge and Dark Gray words, never on the public forms;
 *      warnings stay amber and yellow; a failure flash is an error flash.
 *   D  DESIGN.md says so.
 *
 * Browser: release-31441-live.php. PLANTs in plant-31441.php.
 */

require __DIR__ . '/wp-kit.php';
require __DIR__ . '/text-inbox-kit.php';
if ( ! function_exists( 'wpautop' ) ) { function wpautop( $s ) { return $s; } }

$fails = array();
$ran   = 0;
function rt( $label, $ok ) { global $fails, $ran; $ran++; if ( ! $ok ) { $fails[] = $label; } }
function rt_cap( $fn ) {
    $d = ob_get_level();
    ob_start();
    try { $fn(); } catch ( Throwable $t ) { while ( ob_get_level() > $d ) { ob_end_clean(); } return 'THREW ' . $t->getMessage() . ' at ' . basename( $t->getFile() ) . ':' . $t->getLine(); }
    $h = '';
    while ( ob_get_level() > $d ) { $h = ob_get_clean() . $h; }
    return $h;
}
function rt_x( $html ) {
    $doc = new DOMDocument();
    libxml_use_internal_errors( true );
    $doc->loadHTML( '<?xml encoding="utf-8"?>' . $html );
    libxml_clear_errors();
    return new DOMXPath( $doc );
}
function rt_text( $n ) { return trim( preg_replace( '/\s+/', ' ', $n ? $n->textContent : '' ) ); }
$in   = function ( $from, $body ) { return SFAF_Texts::answer( $from, $body ); };
$root = dirname( __DIR__ );
$P    = '+14155550101';

/* =====================================================================
 * T. The timing
 * =================================================================== */
$db = ri_fresh();
SFAF_Texts::send_update( 900, 'Doors open at 9:45', 7 );
$in( $P, 'Is there parking?' );
rt( 'T: the first reply is emailed in the request that brought it', 1 === count( $GLOBALS['ri_mail'] ) && 'Reply from Ana Ruiz: Coffee & Conversation' === $GLOBALS['ri_mail'][0]['subject'] );
rt( 'T: to who 3.144.0 told: the sender of the message it answers', 'sam@sfaf.org' === $GLOBALS['ri_mail'][0]['to'] );
$w = SFAF_Text_Inbox::windows();
rt( 'T: it opens a five-minute window for the number: ' . json_encode( $w ), isset( $w[ $P ] ) && abs( $w[ $P ] - ( time() + 300 ) ) <= 2 );
rt( 'T: with one backstop run at the window\'s close', 1 === count( $GLOBALS['ri_cron'] ) && $GLOBALS['ri_cron'][0]['at'] === $w[ $P ] && array( $P ) === $GLOBALS['ri_cron'][0]['args'] );
$in( $P, 'And is it step-free?' );
$in( $P, 'Thanks' );
rt( 'T: replies inside the window are not emailed yet', 1 === count( $GLOBALS['ri_mail'] ) );
rt( 'T: and schedule nothing more', 1 === count( $GLOBALS['ri_cron'] ) );
SFAF_Text_Inbox::send_due();
rt( 'T: a request before the five minutes sends nothing', 1 === count( $GLOBALS['ri_mail'] ) );
// The five minutes pass.
update_option( SFAF_Text_Inbox::WINDOWS, array( $P => time() - 1 ) );
SFAF_Text_Inbox::send_due();
rt( 'T: the first request after them sends the follow-up', 2 === count( $GLOBALS['ri_mail'] ) );
$m = $GLOBALS['ri_mail'][1];
rt( 'T: "2 more replies", to the same person: ' . $m['subject'], '2 more replies from Ana Ruiz: Coffee & Conversation' === $m['subject'] && 'sam@sfaf.org' === $m['to'] );
rt( 'T: the two, in order, and not the first again', false !== strpos( $m['body'], 'And is it step-free?' ) && strpos( $m['body'], 'And is it step-free?' ) < strpos( $m['body'], 'Thanks' ) && false === strpos( $m['body'], 'Is there parking?' ) );
rt( 'T: it says they replied again', false !== strpos( $m['body'], 'Ana Ruiz replied again by text' ) );
rt( 'T: the window is closed and its backstop run cleared', array() === SFAF_Text_Inbox::windows() && array() === $GLOBALS['ri_cron'] );
SFAF_Text_Inbox::send_due();
SFAF_Text_Inbox::follow_up( $P );
rt( 'T: a run with nothing waiting sends nothing', 2 === count( $GLOBALS['ri_mail'] ) );
$in( $P, 'One more thing' );
rt( 'T: a reply after the window is a first reply again, emailed now', 3 === count( $GLOBALS['ri_mail'] ) && 'Reply from Ana Ruiz: Coffee & Conversation' === $GLOBALS['ri_mail'][2]['subject'] );
rt( 'T: and opens a new window, with its own backstop', isset( SFAF_Text_Inbox::windows()[ $P ] ) && 1 === count( $GLOBALS['ri_cron'] ) );

/* Closed but no run came: the next reply sends the waiting ones first. */
$db = ri_fresh();
SFAF_Texts::send_confirmation( $db->rsvps[0] );
$in( $P, 'First' );
$in( $P, 'Second' );
update_option( SFAF_Text_Inbox::WINDOWS, array( $P => time() - 1 ) );
$in( $P, 'Third' );
$subs = array_column( $GLOBALS['ri_mail'], 'subject' );
rt( 'T: a reply after the close, before any run: the waiting one in its own follow-up, then this one at once: ' . json_encode( $subs ), array( 'Reply from Ana Ruiz: Coffee & Conversation', '1 more reply from Ana Ruiz: Coffee & Conversation', 'Reply from Ana Ruiz: Coffee & Conversation' ) === $subs );
rt( 'T: each in its own email', false !== strpos( $GLOBALS['ri_mail'][1]['body'], 'Second' ) && false === strpos( $GLOBALS['ri_mail'][1]['body'], 'Third' ) && false !== strpos( $GLOBALS['ri_mail'][2]['body'], 'Third' ) );
rt( 'T: to Replies go to, as 3.144.0 sent a reply to a confirmation', 3 === count( ri_mail_to( 'events@sfaf.org' ) ) );
rt( 'T: one backstop run, not two', 1 === count( $GLOBALS['ri_cron'] ) );

/* Two numbers keep two windows. */
$db = ri_fresh();
SFAF_Texts::send_update( 900, 'Doors', 7 );
$in( $P, 'a' );
$in( 'whatsapp:+14155550102', 'b' );
$in( $P, 'c' );
rt( 'T: each person\'s first reply is emailed at once, whoever else is writing', 2 === count( $GLOBALS['ri_mail'] ) && 2 === count( SFAF_Text_Inbox::windows() ) );
$GLOBALS['wpdb']->query( 'noop' );
SFAF_Text_Inbox::follow_up( '+14155550102' );
rt( 'T: closing one window leaves the other waiting', 2 === count( $GLOBALS['ri_mail'] ) && array( $P ) === array_keys( SFAF_Text_Inbox::windows() ) );

/* The wiring. */
kit_reset();
$GLOBALS['kit_hooks'] = array();
SFAF_Text_Inbox::register();
$hooked = function ( $hook, $method ) {
    foreach ( isset( $GLOBALS['kit_hooks'][ $hook ] ) ? $GLOBALS['kit_hooks'][ $hook ] : array() as $h ) {
        if ( is_array( $h['cb'] ) && 'SFAF_Text_Inbox' === $h['cb'][0] && $method === $h['cb'][1] ) { return true; }
    }
    return false;
};
rt( 'T: every request closes the windows that are due (wp_loaded)', $hooked( 'wp_loaded', 'send_due' ) );
rt( 'T: the backstop run closes its window', $hooked( SFAF_Text_Inbox::ALERT_HOOK, 'follow_up' ) );
$ib = file_get_contents( $root . '/includes/class-sfaf-text-inbox.php' );
rt( 'T: the window is an autoloaded option, so a request asks no query', 2 <= substr_count( $ib, 'update_option( self::WINDOWS, $windows, true );' ) );

/* =====================================================================
 * B. The badges
 * =================================================================== */
$db = ri_fresh();
SFAF_Texts::send_update( 900, 'Doors open at 9:45', 7 );
SFAF_Texts::send_confirmation( $db->rsvps[2] );
$in( $P, 'Is there parking?' );
$in( $P, 'Thanks' );
$in( '+14155550103', 'Hola' );
$in( '+14155559999', 'Who is this?' );
rt( 'B: new replies per event, a stranger\'s counted nowhere: ' . json_encode( SFAF_Text_Inbox::unseen( 1 ) ), array( 900 => 2, 901 => 1 ) === SFAF_Text_Inbox::unseen( 1 ) );
$portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();
$me     = wp_get_current_user();
$count  = function ( $u, $e = 0 ) use ( $portal ) { return kit_call( 'SFAF_Portal', 'new_reply_count', $portal, array( $u, $e ) ); };
rt( 'B: the sidebar counts every new reply on the events they may edit', 3 === $count( $me ) );
rt( 'B: a row counts its own', 2 === $count( $me, 900 ) && 1 === $count( $me, 901 ) );
$nobody = new WP_User(); $nobody->ID = 7;
$GLOBALS['kit_refused'] = array( 7 ); $GLOBALS['kit_cannot'] = array( 'manage_options' );
rt( 'B: nothing for somebody who may edit none of them', 0 === $count( $nobody ) );
$GLOBALS['kit_refused'] = array(); $GLOBALS['kit_cannot'] = array();

$side = rt_cap( function () use ( $portal, $me ) { kit_call( 'SFAF_Portal', 'chrome_open', $portal, array( $me, 'dashboard', false ) ); } );
$x    = rt_x( $side );
$ev   = $x->query( '//nav[contains(@class,"uc-portal-nav")]//a[contains(@href,"/caladmin/events")]' )->item( 0 );
$b    = $ev ? $x->query( './/*[@data-uc-replies-badge]', $ev )->item( 0 ) : null;
rt( 'B: the sidebar\'s Events carries the red badge: ' . rt_text( $b ), $b && false !== strpos( ' ' . $b->getAttribute( 'class' ) . ' ', ' uc-reply-badge ' ) && '3 new replies' === rt_text( $b ) );
rt( 'B: no other entry carries it', 1 === $x->query( '//nav//*[@data-uc-replies-badge]' )->length );

$list = rt_cap( function () use ( $portal, $me ) { kit_call( 'SFAF_Portal', 'events_table', $portal, array( array( 900, 901 ), $me ) ); } );
$x    = rt_x( $list );
$rows = array();
foreach ( $x->query( '//a[@data-uc-replies-badge]' ) as $a ) { $rows[] = rt_text( $a ) . ' ' . $a->getAttribute( 'href' ); }
rt( 'B: the Events list has one beside each event with new replies: ' . json_encode( $rows ), 2 === count( $rows ) && 0 === strpos( $rows[0], '2 new replies ' ) && 0 === strpos( $rows[1], '1 new reply ' ) );
rt( 'B: linked to the event\'s Replies', false !== strpos( $rows[0], 'event_id=900' ) && false !== strpos( $rows[0], 'replies=1' ) && false !== strpos( $rows[0], '#uc-replies' ) );
$cell = $x->query( '//a[@data-uc-replies-badge]/parent::td' )->item( 0 );
rt( 'B: in the RSVP count\'s cell', $cell && 1 === $x->query( './/*[@data-uc-rsvp-pill or @data-uc-rsvp-none]', $cell )->length );
$pt = file_get_contents( $root . '/includes/class-sfaf-portal.php' );
rt( 'B: My events and All events both draw their editable rows with that table', 1 <= substr_count( $pt, '$this->events_table( $mine_ids, $user, $sort, $filters, $bulk );' ) && 1 <= substr_count( $pt, '$this->events_table( $ids, $user, $sort, $filters, $bulk );' ) );

// Opening the RSVP page.
$rp = rt_cap( function () use ( $portal, $me ) { kit_call( 'SFAF_Portal', 'render_text_replies', $portal, array( $me, 900 ) ); } );
$x  = rt_x( $rp );
rt( 'B: Replies opens on new replies, without ?replies', 1 === $x->query( '//details[@id="uc-replies" and @open]' )->length );
$new = array(); foreach ( $x->query( '//li[@data-uc-reply-new]' ) as $li ) { $new[] = rt_text( $x->query( './/*[contains(@class,"uc-reply-body")]', $li )->item( 0 ) ); }
rt( 'B: each new reply highlighted and marked New: ' . json_encode( $new ), array( 'Is there parking?', 'Thanks' ) === $new
    && 2 === $x->query( '//li[contains(@class,"is-new")]//*[contains(@class,"uc-reply-new-tag") and normalize-space(.)="New"]' )->length );
rt( 'B: never an answer', 0 === $x->query( '//li[@data-uc-reply-msg="out" and @data-uc-reply-new]' )->length );
rt( 'B: then seen, by this person', array( 901 => 1 ) === SFAF_Text_Inbox::unseen( 1 ) && 1 === $count( $me ) );
$lee = new WP_User(); $lee->ID = 8;
rt( 'B: and nobody else: they still have theirs', array( 900 => 2, 901 => 1 ) === SFAF_Text_Inbox::unseen( 8 ) && 3 === $count( $lee ) );
$portal2 = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();
$x = rt_x( rt_cap( function () use ( $portal2, $me ) { kit_call( 'SFAF_Portal', 'render_text_replies', $portal2, array( $me, 900 ) ); } ) );
rt( 'B: the next visit: nothing new, closed', 0 === $x->query( '//li[@data-uc-reply-new]' )->length && 0 === $x->query( '//details[@id="uc-replies" and @open]' )->length );
$in( $P, 'Hello again' );
$u1 = SFAF_Text_Inbox::unseen( 1 ); ksort( $u1 );
rt( 'B: a later reply is new again', array( 900 => 1, 901 => 1 ) === $u1 );
rt( 'B: the RSVP page sees them before the sidebar is drawn', (bool) preg_match( '/\$this->take_new_replies\( \$user, \$event_id \);\s*\}\s*\/\/[^\n]*\n\s*\$this->chrome_open\( \$user, \'events\', true \);/', $pt ) );
rt( 'B: and only for an event the viewer may edit', ( $g = strpos( $pt, "if ( ! \$event || 'uc_event' !== \$event->post_type || ! \$this->can_edit_event( \$user, \$event ) ) {\n                \$this->send_to_public_page( \$event_id );" ) ) && ( $t = strpos( $pt, '$this->take_new_replies( $user, $event_id );' ) ) && $t > $g );
rt( 'B: the mark is the person\'s own meta, never a role or a capability', false !== strpos( $ib, "const SEEN       = '_uc_replies_seen';" ) && false === strpos( $ib, 'add_cap' ) && false === strpos( $ib, 'set_role' ) );

/* =====================================================================
 * E. The errors
 * =================================================================== */
$css = file_get_contents( $root . '/public/css/portal.css' );
$CLASSES = array( '.uc-flash-error', '.uc-login-error', '.uc-action-error', '.uc-notice.uc-notice-error', '.uc-field-error', '.uc-hint-error', '.uc-fetch-fail', '.uc-lastrun-alarm' );
rt( 'E: one rule', (bool) preg_match( '/((?:\.uc-portal:not\(\.uc-request-page\) [^,{]+,\s*)+\.uc-portal:not\(\.uc-request-page\) [^,{]+)\s*\{\s*background: var\(--p-error-tint\);\s*border: 1px solid var\(--p-danger\);\s*color: var\(--p-text\);\s*\}/', $css, $em ) );
$sels = isset( $em[1] ) ? array_map( function ( $s ) { return trim( str_replace( '.uc-portal:not(.uc-request-page) ', '', $s ) ); }, explode( ',', $em[1] ) ) : array();
foreach ( $CLASSES as $c ) { rt( "E: $c is in it", in_array( $c, $sels, true ) ); }
rt( 'E: the tokens are the Red tint, brand red and Dark Gray', false !== strpos( $css, '--p-error-tint: #FDE9E7;' ) && false !== strpos( $css, '--p-danger: #F04937;' ) && false !== strpos( $css, '--p-text: #373433;' ) );
rt( 'E: the warnings are untouched', false !== strpos( $css, '.uc-flash-warn { background: #fef3c7; color: #92400e; }' ) && false !== strpos( $css, '.uc-flash-attention { background: #fffbeb; color: #92400e; border: 1px solid #fcd34d;' ) && false !== strpos( $css, '.uc-hint-warn { background: #fffbeb; color: #92400e; border: 1px solid #fcd34d;' ) );
$map = array();
if ( preg_match( '/const FLASH_ERRORS = array\((.*?)\);/s', $pt, $fm ) ) { preg_match_all( "/'([a-z_]+)'/", $fm[1], $fk ); $map = $fk[1]; }
rt( 'E: the failure flashes are listed: ' . count( $map ), count( $map ) >= 25 );
foreach ( $map as $k ) { rt( "E: \"$k\" is a message the flash knows", 1 === preg_match_all( "/^\s*'" . preg_quote( $k, '/' ) . "'\s*=>/m", $pt ) ); }
foreach ( array( 'category_failed' => 'uc-flash uc-flash-error', 'cancel_failed' => 'uc-flash uc-flash-error', 'reassign_first' => 'uc-flash uc-flash-error', 'saved' => 'uc-flash', 'category_saved' => 'uc-flash' ) as $k => $want ) {
    $_GET = array( 'msg' => $k );
    $h = rt_cap( function () use ( $portal ) { kit_call( 'SFAF_Portal', 'flash', $portal, array() ); } );
    $x = rt_x( $h );
    $d = $x->query( '//div[contains(@class,"uc-flash")]' )->item( 0 );
    rt( "E: \"$k\" is drawn as $want: " . ( $d ? $d->getAttribute( 'class' ) : substr( $h, 0, 120 ) ), $d && $want === $d->getAttribute( 'class' ) );
}
$_GET = array();

/* =====================================================================
 * D. DESIGN.md
 * =================================================================== */
$dm = file_get_contents( $root . '/DESIGN.md' );
rt( 'D: DESIGN.md has the error look', false !== strpos( $dm, '### Every error in caladmin is one look (3.144.1)' ) && false !== strpos( $dm, '`#FDE9E7`' ) );
rt( 'D: and the reply badge', false !== strpos( $dm, '### New replies: a red count (3.144.1)' ) );

echo "\n3.144.1: reply alerts at once, reply badges, one look for errors\n" . str_repeat( '=', 72 ) . "\n";
if ( $fails ) { echo count( $fails ) . " of $ran FAILED:\n  - " . implode( "\n  - ", $fails ) . "\n"; exit( 1 ); }
echo "$ran checks passed.\n";
exit( 0 );
