<?php
/**
 * 3.144.2: THE SEGMENTED TOGGLE'S TEAL EDGE, AND WHAT A REPLY ANSWERED.
 *
 *     php .claude/release-31442-test.php
 *
 * The real SFAF_Texts, SFAF_Text_Inbox and SFAF_Portal on wp-kit.php and
 * text-inbox-kit.php: Twilio recorded, mail and scheduled runs caught.
 *
 *   S  The segmented toggle: My events | All events and the RSVP card's No
 *      RSVP | RSVP Here | External RSVP are still the one component, and its
 *      boundary and seams are --uc-accent-text, the check-in bar's seams too;
 *      the chosen side fills --uc-accent-text with white words.
 *   R  Replies: above each reply, the confirmation, reminder or Text
 *      registrants message it answered (reply_to, the last message sent to
 *      the number), once however many replies answer it, labelled with its
 *      kind and when it went, a Text registrants message with who sent it;
 *      nothing above a reply to an answer from the RSVP page, nor above a
 *      reply to nothing recorded; never marked New; another event's message
 *      never shown; the text exactly as stored.
 *   D  DESIGN.md says so.
 *
 * Browser: release-31442-live.php. PLANTs in plant-31442.php.
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
$root = dirname( __DIR__ );

/* =====================================================================
 * S. The segmented toggle
 * ================================================================== */

$css = file_get_contents( $root . '/public/css/portal.css' );
function rt_rule( $css, $sel ) {
    $at = strpos( $css, $sel . ' {' );
    if ( false === $at ) { return ''; }
    $open = strpos( $css, '{', $at );
    return substr( $css, $open + 1, strpos( $css, '}', $open ) - $open - 1 );
}
$box  = rt_rule( $css, ".uc-scope-switch,\n.uc-seg" );
$opt  = rt_rule( $css, ".uc-portal .uc-scope-opt,\n.uc-seg-opt span" );
$on   = rt_rule( $css, ".uc-portal .uc-scope-opt.is-on,\n.uc-seg-opt input:checked + span" );
$ci   = rt_rule( $css, '.uc-checkin-bar .uc-seg-opt-btn' );
rt( 'S: both toggles are one rule: the group', '' !== $box );
rt( 'S: the group\'s boundary is 1px --uc-accent-text: ' . trim( $box ), (bool) preg_match( '/(^|;)\s*border:\s*1px solid var\(--uc-accent-text\)/', $box ) );
rt( 'S: both toggles are one rule: the options', '' !== $opt );
rt( 'S: the seams between options are 1px --uc-accent-text', (bool) preg_match( '/border-left:\s*1px solid var\(--uc-accent-text\)/', $opt ) );
rt( 'S: the side not chosen is white with Dark Gray words', false !== strpos( $opt, 'background: var(--p-control-face)' ) && false !== strpos( $opt, 'color: var(--p-text)' ) );
rt( 'S: the chosen side fills --uc-accent-text with white words', false !== strpos( $on, 'background: var(--uc-accent-text)' ) && false !== strpos( $on, 'color: #fff' ) );
rt( 'S: the check-in bar\'s seams are 1px --uc-accent-text', (bool) preg_match( '/border-left:\s*1px solid var\(--uc-accent-text\)/', $ci ) );
rt( 'S: no other rule gives a segmented group or option a grey edge', ! preg_match( '/\.uc-(seg|scope-switch|scope-opt|seg-opt|seg-opt-btn)(?![\w-])[^{]*\{[^}]*border(-left|-color)?:[^;}]*--p-border/', preg_replace( '#/\*.*?\*/#s', '', $css ) ) );

$portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();
ri_fresh();
$sw = rt_cap( function () use ( $portal ) { kit_call( 'SFAF_Portal', 'render_scope_toggle', $portal, array( 'mine', 'events' ) ); } );
$xs = rt_x( $sw );
rt( 'S: My events | All events is drawn as .uc-scope-switch, My events chosen', 1 === $xs->query( '//div[@class="uc-scope-switch"]' )->length && 2 === $xs->query( '//a[contains(@class,"uc-scope-opt")]' )->length && 'My events' === rt_text( $xs->query( '//a[contains(@class,"is-on")]' )->item( 0 ) ) );
$rc = rt_cap( function () { SFAF_Register_Elsewhere::render_rsvp_choice( 'here', '' ); } );
$xr = rt_x( $rc );
rt( 'S: the RSVP card is drawn as .uc-seg, RSVP Here chosen', 1 === $xr->query( '//div[@class="uc-seg"]' )->length && 3 === $xr->query( '//label[@class="uc-seg-opt"]' )->length );

/* =====================================================================
 * R. What a reply answered
 * ================================================================== */

$db  = ri_fresh();
$db->rsvps[] = ri_row( 4, 900, '(415) 555-0104', 'sms', 'en', 'Dee', 'Park' );
$ana = $db->rsvps[0];
$P   = '+14155550101';
update_post_meta( 900, '_uc_rsvp_enabled', '1' );

rt( 'R: the confirmation went by text', SFAF_Texts::send( $ana, 'confirmation' ) );
SFAF_Texts::answer( $P, 'Will there be coffee?' );
rt( 'R: the reminder went by text', SFAF_Texts::send( $ana, 'reminder' ) );
SFAF_Texts::answer( $P, 'Running late' );
SFAF_Texts::answer( $P, 'Five minutes away' );
SFAF_Text_Inbox::reply( 900, $P, 'No problem, see you soon.', 8 );
SFAF_Texts::answer( $P, 'Here now' );
// Beto, by WhatsApp, answers a Text registrants message from Sam Staff.
SFAF_Texts::send_update( 900, 'Doors open at 9:45', 7, array( 2 ) );
SFAF_Texts::answer( 'whatsapp:+14155550102', 'Gracias' );
// Cruz is on the other event: its reminder must not show on this one.
SFAF_Texts::send( $db->rsvps[2], 'reminder' );
SFAF_Texts::answer( '+14155550103', 'Ahí estaré' );
// Dee was never sent anything recorded.
SFAF_Texts::answer( '+14155550104', 'Is this the right number?' );

$byid = array(); foreach ( $db->msgs as $m ) { $byid[ (int) $m->id ] = $m; }
$sent = function ( $kind, $phone ) use ( $db ) { foreach ( $db->msgs as $m ) { if ( 'out' === $m->direction && $kind === $m->kind && $phone === $m->phone ) { return $m; } } return null; };
$conf = $sent( 'confirmation', $P );
$rem  = $sent( 'reminder', $P );
$upd  = $sent( 'update', '+14155550102' );
rt( 'R: the fixture recorded the confirmation, reminder and update', $conf && $rem && $upd );

$threads = SFAF_Text_Inbox::threads( 900 );
$by = array(); foreach ( $threads as $t ) { $by[ $t['phone'] ] = $t; }
rt( 'R: threads() names what Ana\'s replies answered: the confirmation and the reminder, not the RSVP page answer',
    isset( $by[ $P ] ) && $conf && $rem && array( (int) $conf->id, (int) $rem->id ) === array_map( 'intval', array_keys( $by[ $P ]['answers'] ) ) );
rt( 'R: threads() names nothing for Dee', isset( $by['+14155550104'] ) && array() === $by['+14155550104']['answers'] );
rt( 'R: threads() keeps the rows a thread had: replies and answers only', isset( $by[ $P ] ) && 5 === count( $by[ $P ]['rows'] ) );

$me   = wp_get_current_user();
$_GET = array( 'replies' => '1' );
$html = rt_cap( function () use ( $portal, $me ) { kit_call( 'SFAF_Portal', 'render_text_replies', $portal, array( $me, 900 ) ); } );
$_GET = array();
rt( 'R: the section renders', false === strpos( $html, 'THREW' ) && false !== strpos( $html, 'data-uc-text-replies' ) );
$x = rt_x( $html );
$thread = function ( $phone ) use ( $x ) {
    $sec = $x->query( '//section[@data-uc-reply-thread="' . $phone . '"]' )->item( 0 );
    $out = array();
    if ( ! $sec ) { return $out; }
    foreach ( $x->query( './/li[@data-uc-reply-msg]', $sec ) as $li ) {
        $out[] = array(
            'dir'  => $li->getAttribute( 'data-uc-reply-msg' ),
            'kind' => $li->getAttribute( 'data-uc-reply-kind' ),
            'meta' => rt_text( $x->query( './/*[contains(@class,"uc-reply-meta")]', $li )->item( 0 ) ),
            'body' => rt_text( $x->query( './/*[contains(@class,"uc-reply-body")]', $li )->item( 0 ) ),
            'new'  => $li->hasAttribute( 'data-uc-reply-new' ),
            'cls'  => $li->getAttribute( 'class' ),
        );
    }
    return $out;
};
$a     = $thread( $P );
$shape = array_map( function ( $m ) { return $m['dir'] . ( '' !== $m['kind'] ? ':' . $m['kind'] : '' ) . '|' . $m['body']; }, $a );
$want  = array(
    'sent:confirmation|' . $conf->body,
    'in|Will there be coffee?',
    'sent:reminder|' . $rem->body,
    'in|Running late',
    'in|Five minutes away',
    'out|SFAF: No problem, see you soon. Reply STOP to opt out.',
    'in|Here now',
);
rt( 'R: Ana\'s thread: each message replied to above the first reply to it, once: ' . json_encode( $shape ), $want === $shape );
rt( 'R: the confirmation is its text as sent', 0 === strpos( (string) $conf->body, "SFAF: You're registered for Coffee & Conversation" ) );
rt( 'R: the reminder is its text as sent', 0 === strpos( (string) $rem->body, 'SFAF: Reminder: Coffee & Conversation is today' ) );
$sentrows = array_values( array_filter( $a, function ( $m ) { return 'sent' === $m['dir']; } ) );
rt( 'R: labelled Confirmation and Reminder, with when they went: ' . json_encode( array_column( $sentrows, 'meta' ) ),
    2 === count( $sentrows ) && 0 === strpos( $sentrows[0]['meta'], 'Confirmation, ' ) && 0 === strpos( $sentrows[1]['meta'], 'Reminder, ' )
    && $sentrows[0]['meta'] === 'Confirmation, ' . sfaf_ap_datetime( (string) $conf->created_at ) );
rt( 'R: drawn as an outgoing message, never New', 2 === count( array_filter( $sentrows, function ( $m ) { return false !== strpos( $m['cls'], 'uc-reply-out' ) && false === strpos( $m['cls'], 'is-new' ) && ! $m['new']; } ) ) );

$b = $thread( '+14155550102' );
rt( 'R: Beto\'s thread: the Text registrants message, then "Gracias": ' . json_encode( $b ), 2 === count( $b ) && 'sent' === $b[0]['dir'] && 'update' === $b[0]['kind'] && 'in' === $b[1]['dir'] && 'Gracias' === $b[1]['body'] );
rt( 'R: a Text registrants message names who sent it: ' . ( $b ? $b[0]['meta'] : '' ), $b && 0 === strpos( $b[0]['meta'], 'Text registrants from Sam Staff, ' ) );
rt( 'R: a WhatsApp message shows the words stored for it', $b && $upd && $b[0]['body'] === trim( preg_replace( '/\s+/', ' ', (string) $upd->body ) ) && false !== strpos( $b[0]['body'], 'Doors open at 9:45' ) );

$d = $thread( '+14155550104' );
rt( 'R: nothing above a reply to nothing recorded: ' . json_encode( $d ), 1 === count( $d ) && 'in' === $d[0]['dir'] );
rt( 'R: the other event\'s reminder is not on this event', false === strpos( $html, 'Grupo de apoyo' ) && 0 === $x->query( '//section[@data-uc-reply-thread="+14155550103"]' )->length );

$_GET = array( 'replies' => '1' );
$h901 = rt_cap( function () use ( $portal, $me ) { kit_call( 'SFAF_Portal', 'render_text_replies', $portal, array( $me, 901 ) ); } );
$_GET = array();
$x9 = rt_x( $h901 );
$c  = array(); foreach ( $x9->query( '//li[@data-uc-reply-msg]' ) as $li ) { $c[] = $li->getAttribute( 'data-uc-reply-msg' ) . ':' . $li->getAttribute( 'data-uc-reply-kind' ); }
rt( 'R: on its own event, Cruz\'s reply sits under its reminder: ' . json_encode( $c ), array( 'sent:reminder', 'in:' ) === $c );

// A message's text is escaped, as a reply's is.
$db2 = ri_fresh();
SFAF_Text_Inbox::sent( array( 'event_id' => 900, 'rsvp_id' => 1, 'phone' => $P, 'channel' => 'sms', 'kind' => 'update', 'body' => 'Bring <b>ID</b> & a pen', 'by' => 0 ) );
SFAF_Texts::answer( $P, 'OK' );
$_GET = array( 'replies' => '1' );
$h2 = rt_cap( function () use ( $portal, $me ) { kit_call( 'SFAF_Portal', 'render_text_replies', $portal, array( $me, 900 ) ); } );
$_GET = array();
rt( 'R: the text is escaped', false !== strpos( $h2, 'Bring &lt;b&gt;ID&lt;/b&gt; &amp; a pen' ) && false === strpos( $h2, '<b>ID</b>' ) );
$x2 = rt_x( $h2 );
rt( 'R: a Text registrants message with no sender recorded is labelled Text registrants alone', 0 === strpos( rt_text( $x2->query( '//li[@data-uc-reply-msg="sent"]/*[contains(@class,"uc-reply-meta")]' )->item( 0 ) ), 'Text registrants, ' ) );

/* =====================================================================
 * D. DESIGN.md
 * ================================================================== */

$design = file_get_contents( $root . '/DESIGN.md' );
rt( 'D: DESIGN.md gives the segmented group its teal boundary (3.144.2)', false !== strpos( $design, '| Segmented option group | white, chosen fills `#0E7680` | one `#0E7680` boundary, seams inside the same (3.144.2) | pick one of these |' ) );

echo "\n3.144.2: the segmented toggle's teal edge, what a reply answered\n" . str_repeat( '=', 72 ) . "\n";
if ( $fails ) {
    echo count( $fails ) . " of $ran FAILED:\n  - " . implode( "\n  - ", $fails ) . "\n";
    exit( 1 );
}
echo "$ran checks passed.\n";
exit( 0 );
