<?php
/**
 * 3.134.0: THE CONFIRMATION BY TEXT OR WHATSAPP, EVERY TEXT AS THE CARRIER
 * CAMPAIGN HAS IT, ENDED EVENTS, AND REGISTERING ON A PRIVATE EVENT.
 *
 *     php .claude/release-31340-test.php
 *
 * The real plugin on wp-kit.php, with release-31310-test.php's tables and
 * Twilio recorded, never reached.
 *
 *   A. The confirmation, in Mark's words: by text in English and Spanish, by
 *      WhatsApp with sfaf_event_confirmation's six blanks; right after a
 *      registration (route_submission()), with or without an email, and when
 *      a waitlist place is confirmed; under the same switch as the email;
 *      nothing to somebody who chose neither.
 *   B. WhatsApp confirmations stay off until both SIDs are in, while the
 *      reminder still goes; the panel has the two boxes and refuses a SID
 *      that is not one.
 *   C. Every text starts "SFAF:" and ends "Reply STOP to opt out." ("Responda
 *      STOP para cancelar." in Spanish), never twice: the reminder, Text
 *      registrants, the automatic reply; WhatsApp is unchanged.
 *   D. Ended: sfaf_event_has_ended() by end time, by start time with none,
 *      by day with no time, past midnight, by a source's last day; submit()
 *      refuses an ended event in the form's language.
 *   E. The ended page: "This event has ended." in place of the Register
 *      button, the series' upcoming dates with weekdays and links, none that
 *      ended and not this one; no Add to Calendar; no "Upcoming in this
 *      series" box twice; an event not ended is as before.
 *   F. Private: a published private event takes a registration, and its
 *      confirmation, cancel link, Text registrants and reminder by text go as
 *      any event's do; an event not yet published says so to staff and
 *      "could not be found" to anybody else; the private link shown before
 *      publishing is the address it will have.
 *   G. The RSVP form's Terms link beside the privacy policy, in both
 *      languages. The browser half is release-31340-live.php.
 *
 * PLANTs in plant-31340.php.
 */

$GLOBALS['loop_done'] = false;
function have_posts() { return ! $GLOBALS['loop_done']; }
function the_post() { $GLOBALS['loop_done'] = true; $GLOBALS['kit_current'] = $GLOBALS['page_ev']; }
function get_header() { echo "<!--header-->\n"; }
function get_footer() { echo "\n<!--footer-->"; }
require __DIR__ . '/wp-kit.php';
foreach ( array( 'wpautop' => 'return "<p>".$a[0]."</p>";' ) as $f => $b ) { if ( ! function_exists( $f ) ) { eval( "function $f( ...\$a ) { $b }" ); } }
if ( ! function_exists( 'the_title' ) ) { function the_title() { echo get_the_title( get_the_ID() ); } }
if ( ! function_exists( 'the_content' ) ) { function the_content() { echo get_post_field( 'post_content', get_the_ID() ); } }

$fails = array();
$ran   = 0;
function rt( $label, $ok ) {
    global $fails, $ran;
    $ran++;
    if ( ! $ok ) { $fails[] = $label; }
}
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

/* ---- Twilio, recorded and never reached. ---- */
$GLOBALS['tw_calls'] = array();
if ( ! function_exists( 'wp_remote_request' ) ) {
    function wp_remote_request( $url, $args = array() ) {
        $GLOBALS['tw_calls'][] = array( 'url' => $url, 'args' => $args );
        return array( 'response' => array( 'code' => 201 ), 'body' => '{"sid":"SM1"}' );
    }
}
if ( ! function_exists( 'wp_remote_get' ) ) { function wp_remote_get( $url, $args = array() ) { return wp_remote_request( $url, $args ); } }
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) { function wp_remote_retrieve_response_code( $r ) { return isset( $r['response']['code'] ) ? $r['response']['code'] : 0; } }
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) { function wp_remote_retrieve_body( $r ) { return isset( $r['body'] ) ? $r['body'] : ''; } }
if ( ! function_exists( 'rest_url' ) ) { function rest_url( $p = '' ) { return 'https://resources.sfaf.org/wp-json/' . ltrim( $p, '/' ); } }
if ( ! function_exists( 'is_ssl' ) ) { function is_ssl() { return true; } }
if ( ! function_exists( 'wp_mail' ) ) { function wp_mail( ...$a ) { $GLOBALS['tw_mail'][] = $a; return true; } }

/* ---- Registrations, stops, the reminder ledger. ---- */
class Tw_Db extends wpdb {
    public $rsvps = array(); public $stops = array(); public $ledger = array(); public $inserted = array();
    function prepare() { return kit_prepare( func_get_args() ); }
    function esc_like( $t ) { return (string) $t; }
    function suppress_errors( $s = true ) { return false; }
    function insert( $table, $data, $f = null ) {
        if ( false !== strpos( $table, 'uc_text_stops' ) ) { $this->stops[] = $data; return 1; }
        if ( false !== strpos( $table, 'uc_reminder_log' ) ) {
            foreach ( $this->ledger as $l ) { if ( (int) $l['event_id'] === (int) $data['event_id'] && $l['recipient_hash'] === $data['recipient_hash'] ) { return false; } }
            $this->insert_id = count( $this->ledger ) + 1;
            $data['id'] = $this->insert_id;
            $this->ledger[] = $data;
            return 1;
        }
        if ( false !== strpos( $table, 'uc_rsvps' ) ) {
            $this->insert_id = 700 + count( $this->inserted );
            $this->inserted[] = $data;
            return 1;
        }
        return 1;
    }
    function update( $table, $data, $where, $f = null, $wf = null ) {
        foreach ( $this->ledger as $i => $l ) { if ( isset( $where['id'] ) && (int) $l['id'] === (int) $where['id'] ) { $this->ledger[ $i ] = array_merge( $l, $data ); } }
        if ( false !== strpos( $table, 'uc_rsvps' ) ) {
            foreach ( $this->rsvps as $r ) { if ( isset( $where['id'] ) && (int) $r->id === (int) $where['id'] ) { foreach ( $data as $k => $v ) { $r->$k = $v; } } }
        }
        return 1;
    }
    function delete( $table, $where, $f = null ) { return 1; }
    function get_var( $sql ) {
        if ( preg_match( '/SELECT event_id FROM \S*uc_rsvps WHERE id = (\d+)/', $sql, $m ) ) {
            foreach ( $this->rsvps as $r ) { if ( (int) $r->id === (int) $m[1] ) { return $r->event_id; } }
        }
        if ( false !== strpos( $sql, 'COUNT' ) ) { return 0; }
        return null;
    }
    function get_row( $sql ) {
        if ( preg_match( '/uc_rsvps WHERE id = (\d+)/', $sql, $m ) ) {
            foreach ( $this->rsvps as $r ) { if ( (int) $r->id === (int) $m[1] ) { return $r; } }
        }
        if ( false !== strpos( $sql, 'text_opt_in = 1' ) ) { return (object) array( 'n' => 0, 'e' => 0 ); }
        return null;
    }
    function get_results( $sql ) {
        if ( preg_match( "/event_id = (\d+) AND status = 'confirmed' AND text_channel IN/", $sql, $m ) ) {
            return array_values( array_filter( $this->rsvps, function ( $r ) use ( $m ) {
                return (int) $r->event_id === (int) $m[1] && 'confirmed' === $r->status && in_array( $r->text_channel, array( 'sms', 'whatsapp' ), true );
            } ) );
        }
        if ( preg_match( "/phone LIKE '%(\d+)'/", $sql, $m ) ) {
            return array_values( array_filter( $this->rsvps, function ( $r ) use ( $m ) {
                return in_array( $r->text_channel, array( 'sms', 'whatsapp' ), true ) && substr( preg_replace( '/\D/', '', $r->phone ), -strlen( $m[1] ) ) === $m[1];
            } ) );
        }
        return array();
    }
}
function tw_row( $id, $event, $phone, $channel, $lang, $first, $status = 'confirmed' ) {
    return (object) array( 'id' => $id, 'event_id' => $event, 'first_name' => $first, 'last_name' => '', 'name' => $first, 'email' => strtolower( $first ) . '@example.org',
        'phone' => $phone, 'status' => $status, 'text_channel' => $channel, 'lang' => $lang, 'text_opt_in' => 0, 'format' => '', 'token' => str_repeat( strtolower( $first[0] ), 32 ), 'lists' => '' );
}
$SID = 'AC' . str_repeat( 'a', 32 );
$MG  = 'MG' . str_repeat( 'b', 32 );
function tw_hx( $c ) { return 'HX' . str_repeat( $c, 32 ); }
function tw_wa( $confirm = true ) {
    $f = array(
        'twilio_account_sid' => $GLOBALS['SID'], 'twilio_messaging_sid' => $GLOBALS['MG'], 'twilio_whatsapp_from' => '+1 (415) 555-0100',
        'twilio_wa_reminder_en' => tw_hx( 'c' ), 'twilio_wa_reminder_es' => tw_hx( 'd' ), 'twilio_wa_update_en' => tw_hx( 'e' ), 'twilio_wa_update_es' => tw_hx( 'f' ),
        'twilio_wa_confirmation_en' => $confirm ? tw_hx( '1' ) : '', 'twilio_wa_confirmation_es' => $confirm ? tw_hx( '2' ) : '',
    );
    return SFAF_Texts::save( $f, 'abcdef0123456789abcdef012345WXYZ' );
}
function tw_fresh( $confirm = true ) {
    kit_reset();
    $GLOBALS['wpdb'] = new Tw_Db();
    $GLOBALS['tw_calls'] = array();
    kit_write_post( array( 'ID' => 900, 'post_title' => 'Coffee &amp; Conversation', 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_author' => 1 ) );
    foreach ( array( '_uc_event_date' => '2026-12-01', '_uc_start_time' => '10:00', '_uc_email_replyto' => 'events@sfaf.org', '_uc_location' => '1035 Market St.', '_uc_rsvp_enabled' => '1' ) as $k => $v ) {
        update_post_meta( 900, $k, $v );
    }
    tw_wa( $confirm );
}
function tw_bodies() {
    $o = array();
    foreach ( $GLOBALS['tw_calls'] as $c ) { $o[ str_replace( 'whatsapp:', '', $c['args']['body']['To'] ) ] = $c['args']['body']; }
    return $o;
}
$cancel = function ( $t ) { return SFAF_Reminders::cancel_url( $t ); };

/* ======================================================================== A */
tw_fresh();
$ana  = tw_row( 1, 900, '(415) 555-0101', 'sms', 'en', 'Ana' );
$dee  = tw_row( 4, 900, '(415) 555-0104', 'sms', 'es', 'Dee' );
$beto = tw_row( 2, 900, '(415) 555-0102', 'whatsapp', 'es', 'Beto' );
$cruz = tw_row( 3, 900, '(415) 555-0103', 'whatsapp', 'en', 'Cruz' );
$none = tw_row( 5, 900, '(415) 555-0105', '', 'en', 'Nell' );
foreach ( array( $ana, $dee, $beto, $cruz, $none ) as $r ) { SFAF_Texts::send_confirmation( $r ); }
$by = tw_bodies();
$date_en = sfaf_ap_date( '2026-12-01', 'full', 'en' );
$date_es = sfaf_ap_date( '2026-12-01', 'full', 'es' );
$time_en = sfaf_ap_time( '10:00', true, 'en' );
$time_es = sfaf_ap_time( '10:00', true, 'es' );
rt( "A: the English text in Mark's words",
    "SFAF: You're registered for Coffee & Conversation on $date_en at $time_en, 1035 Market St. Details: https://resources.sfaf.org/events/900/ Can't make it? Cancel here: " . $cancel( $ana->token ) . ' Reply STOP to opt out.'
    === ( $by['+14155550101']['Body'] ?? '' ) );
rt( "A: the Spanish text in Mark's words",
    "SFAF: Está registrado para Coffee & Conversation el $date_es a las $time_es, en 1035 Market St. Detalles: https://resources.sfaf.org/events/900/ ¿No puede asistir? Cancele aquí: " . $cancel( $dee->token ) . ' Responda STOP para cancelar.'
    === ( $by['+14155550104']['Body'] ?? '' ) );
rt( 'A: a text goes through the Messaging Service', $MG === ( $by['+14155550101']['MessagingServiceSid'] ?? '' ) );
$vb = json_decode( (string) ( $by['+14155550102']['ContentVariables'] ?? '' ), true );
$vc = json_decode( (string) ( $by['+14155550103']['ContentVariables'] ?? '' ), true );
rt( 'A: WhatsApp has the six blanks', is_array( $vb ) && array( '1', '2', '3', '4', '5', '6' ) === array_map( 'strval', array_keys( $vb ) ) );
rt( 'A: {{1}} name, {{2}} date with weekday, {{3}} time, {{4}} place, {{5}} event page',
    is_array( $vc ) && 'Coffee & Conversation' === $vc['1'] && $date_en === $vc['2'] && $time_en === $vc['3'] && '1035 Market St' === $vc['4'] && 'https://resources.sfaf.org/events/900/' === $vc['5'] );
rt( 'A: the date has its weekday, in the text and in {{2}}', 0 === strpos( $date_en, 'Tuesday, ' ) && 0 === strpos( $date_es, 'martes, ' )
    && is_array( $vc ) && 0 === strpos( (string) $vc['2'], 'Tuesday, ' ) && is_array( $vb ) && 0 === strpos( (string) $vb['2'], 'martes, ' )
    && false !== strpos( $by['+14155550101']['Body'] ?? '', ' on Tuesday, ' ) );
rt( "A: {{6}} is the person's own cancel link", is_array( $vb ) && $cancel( $beto->token ) === $vb['6'] && $cancel( $cruz->token ) === $vc['6'] );
rt( 'A: Spanish in the Spanish template, English in the English', tw_hx( '2' ) === ( $by['+14155550102']['ContentSid'] ?? '' ) && tw_hx( '1' ) === ( $by['+14155550103']['ContentSid'] ?? '' ) );
rt( 'A: the Spanish blanks are Spanish', is_array( $vb ) && $date_es === $vb['2'] && $time_es === $vb['3'] );
rt( 'A: nothing to somebody who chose neither', ! isset( $by['+14155550105'] ) && 4 === count( $GLOBALS['tw_calls'] ) );

/* A missing time and place fall back as the reminder's do; no token, no cancel line, {{6}} the event page. */
tw_fresh();
delete_post_meta( 900, '_uc_start_time' );
delete_post_meta( 900, '_uc_location' );
$x = tw_row( 6, 900, '(415) 555-0106', 'sms', 'en', 'Xan' ); $x->token = '';
$y = tw_row( 7, 900, '(415) 555-0107', 'whatsapp', 'en', 'Yu' ); $y->token = '';
SFAF_Texts::send_confirmation( $x );
SFAF_Texts::send_confirmation( $y );
$by = tw_bodies();
$facts = SFAF_Texts::facts( 900, 'en' );
rt( 'A: the reminder\'s fallbacks for a missing time and place',
    false !== strpos( $by['+14155550106']['Body'] ?? '', ' at ' . SFAF_Messages::label( 'tbc', 'en' ) . ', ' . $facts['place'] . '. ' ) && '' !== $facts['place'] );
rt( 'A: no token, no cancel line', false === strpos( $by['+14155550106']['Body'] ?? '', 'Cancel here' ) );
$vy = json_decode( (string) ( $by['+14155550107']['ContentVariables'] ?? '' ), true );
rt( 'A: no token, {{6}} is the event page, never empty', is_array( $vy ) && 'https://resources.sfaf.org/events/900/' === $vy['6'] );

/* Right after a registration, with or without an email. */
tw_fresh();
$rsvp = new SFAF_RSVP();
$out  = rt_cap( function () use ( $rsvp ) {
    $rsvp->route_submission( 701, array( 'event_id' => 900, 'name' => 'Ana', 'first_name' => 'Ana', 'last_name' => '', 'email' => 'ana@example.org',
        'token' => str_repeat( 'a', 32 ), 'rsvp_id' => 701, 'format' => '', 'lang' => 'en', 'phone' => '(415) 555-0101', 'text_channel' => 'sms' ) );
    $rsvp->route_submission( 702, array( 'event_id' => 900, 'name' => 'Fay', 'first_name' => 'Fay', 'last_name' => '', 'email' => '',
        'token' => str_repeat( 'f', 32 ), 'rsvp_id' => 702, 'format' => '', 'lang' => 'es', 'phone' => '(415) 555-0108', 'text_channel' => 'sms' ) );
    $rsvp->route_submission( 703, array( 'event_id' => 900, 'name' => 'Gus', 'first_name' => 'Gus', 'last_name' => '', 'email' => 'gus@example.org',
        'token' => str_repeat( 'g', 32 ), 'rsvp_id' => 703, 'format' => '', 'lang' => 'en', 'phone' => '(415) 555-0109', 'text_channel' => '' ) );
} );
rt( 'A: route_submission ran (' . substr( $out, 0, 120 ) . ')', 0 !== strpos( $out, 'THREW' ) );
$by = tw_bodies();
rt( 'A: a registration that chose Text is texted its confirmation', 0 === strpos( $by['+14155550101']['Body'] ?? '', "SFAF: You're registered for " ) );
rt( 'A: with no email too, in the language they chose', 0 === strpos( $by['+14155550108']['Body'] ?? '', 'SFAF: Está registrado para ' ) );
rt( 'A: a registration that chose neither gets no text', ! isset( $by['+14155550109'] ) );

/* The site's switch for confirmations governs the text too. */
tw_fresh();
update_option( 'uc_settings', array( 'route_confirmation_email' => '0' ) );
rt( 'A: confirmations off for the site, no text', false === SFAF_Texts::send_confirmation( $ana ) && 0 === count( $GLOBALS['tw_calls'] ) );

/* A waitlist place confirmed by staff. */
tw_fresh();
$wl = tw_row( 9, 900, '(415) 555-0110', 'sms', 'en', 'Wren', 'waitlisted' );
$GLOBALS['wpdb']->rsvps = array( $wl );
$out = rt_cap( function () { SFAF_Waitlist::staff_confirm( 9 ); } );
rt( 'A: staff_confirm ran (' . substr( $out, 0, 120 ) . ')', 0 !== strpos( $out, 'THREW' ) );
$by = tw_bodies();
rt( 'A: a waitlist sign-up is texted once confirmed into the event', 0 === strpos( $by['+14155550110']['Body'] ?? '', "SFAF: You're registered for " ) );
rt( 'A: and their own cancel link', false !== strpos( $by['+14155550110']['Body'] ?? '', $cancel( $wl->token ) ) );
tw_fresh();
$GLOBALS['wpdb']->rsvps = array( tw_row( 9, 900, '(415) 555-0110', 'sms', 'en', 'Wren', 'waitlisted' ) );
rt( 'A: joining the waitlist itself texts nothing', 0 === count( $GLOBALS['tw_calls'] ) );

/* ======================================================================== B */
tw_fresh( false );
rt( 'B: WhatsApp is on with the four', SFAF_Texts::whatsapp_on() );
rt( 'B: WhatsApp confirmations stay off without the two', ! SFAF_Texts::whatsapp_confirm_on() );
SFAF_Texts::send_confirmation( $cruz );
SFAF_Texts::send_confirmation( $ana );
$by = tw_bodies();
rt( 'B: so nothing goes by WhatsApp', ! isset( $by['+14155550103'] ) );
rt( 'B: while a text confirmation still goes', isset( $by['+14155550101'] ) );
$GLOBALS['wpdb']->rsvps = array( $cruz );
update_post_meta( 900, '_uc_event_date', current_time( 'Y-m-d' ) );
$GLOBALS['tw_calls'] = array();
SFAF_Texts::send_reminders( 900 );
rt( 'B: and the WhatsApp reminder still goes', 1 === count( $GLOBALS['tw_calls'] ) && tw_hx( 'c' ) === $GLOBALS['tw_calls'][0]['args']['body']['ContentSid'] );
SFAF_Texts::save( array( 'twilio_wa_confirmation_en' => tw_hx( '1' ) ) + array_fill_keys( array( 'twilio_account_sid' ), $SID ) + array( 'twilio_messaging_sid' => $MG, 'twilio_whatsapp_from' => '+14155550100',
    'twilio_wa_reminder_en' => tw_hx( 'c' ), 'twilio_wa_reminder_es' => tw_hx( 'd' ), 'twilio_wa_update_en' => tw_hx( 'e' ), 'twilio_wa_update_es' => tw_hx( 'f' ), 'twilio_wa_confirmation_es' => '' ) );
rt( 'B: one of the two is not enough', ! SFAF_Texts::whatsapp_confirm_on() );
$bad = SFAF_Texts::save( array( 'twilio_wa_confirmation_es' => 'HX123' ) );
rt( 'B: a confirmation SID that is not one is refused', false === $bad['ok'] && false !== strpos( $bad['message'], 'starts with HX' ) );
tw_wa( true );
rt( 'B: both in, WhatsApp confirmations are on', SFAF_Texts::whatsapp_confirm_on() );
$panel = rt_cap( function () { SFAF_Texts::render_panel(); } );
$px = rt_x( $panel );
rt( 'B: the panel has the two confirmation boxes', 2 === $px->query( '//input[@data-uc-twilio-confirm-template]' )->length
    && 1 === $px->query( '//input[@name="twilio_wa_confirmation_en"]' )->length && 1 === $px->query( '//input[@name="twilio_wa_confirmation_es"]' )->length );
rt( 'B: labelled with the template names', false !== strpos( $panel, 'sfaf_event_confirmation, English' ) && false !== strpos( $panel, 'sfaf_event_confirmation, Spanish' ) );
rt( 'B: and say they are on', false !== strpos( $panel, 'WhatsApp confirmations are on.' ) );
$saved_box = $px->query( '//input[@name="twilio_wa_confirmation_en"]' )->item( 0 );
rt( 'B: the boxes keep what was saved', $saved_box && tw_hx( '1' ) === $saved_box->getAttribute( 'value' ) );
rt( 'B: both are credentials, never uc_settings', 'text' === SFAF_Credentials::type( 'twilio_wa_confirmation_en' ) && 'text' === SFAF_Credentials::type( 'twilio_wa_confirmation_es' ) );
tw_wa( false );
$panel = rt_cap( function () { SFAF_Texts::render_panel(); } );
rt( 'B: and say they stay off until both are in', false !== strpos( $panel, 'WhatsApp confirmations stay off until both are filled in' ) );

/* ======================================================================== C */
rt( 'C: "SFAF:" first and STOP last', 'SFAF: Hello. Reply STOP to opt out.' === SFAF_Texts::sms( 'Hello.', 'en' ) );
rt( 'C: in Spanish, "para cancelar"', 'SFAF: Hola. Responda STOP para cancelar.' === SFAF_Texts::sms( 'Hola.', 'es' ) );
rt( 'C: never twice', SFAF_Texts::sms( 'Hello.', 'en' ) === SFAF_Texts::sms( SFAF_Texts::sms( 'Hello.', 'en' ), 'en' )
    && 'SFAF: Already. Reply STOP to opt out.' === SFAF_Texts::sms( 'SFAF: Already. Reply STOP to opt out.', 'en' ) );
tw_fresh();
$GLOBALS['wpdb']->rsvps = array( $ana, $dee, $beto, $cruz );
update_post_meta( 900, '_uc_event_date', current_time( 'Y-m-d' ) );
SFAF_Texts::send_reminders( 900 );
$by = tw_bodies();
rt( 'C: the English reminder', 'SFAF: Reminder: Coffee & Conversation is today at ' . $time_en . ', at 1035 Market St. Event details: https://resources.sfaf.org/events/900/ Can\'t make it? Cancel here: ' . $cancel( $ana->token ) . ' Reply STOP to opt out.' === ( $by['+14155550101']['Body'] ?? '' ) );
rt( 'C: the Spanish reminder', 0 === strpos( $by['+14155550104']['Body'] ?? '', 'SFAF: Recordatorio: Coffee & Conversation es hoy a las ' ) && 'Responda STOP para cancelar.' === substr( $by['+14155550104']['Body'] ?? '', -strlen( 'Responda STOP para cancelar.' ) ) );
rt( 'C: STOP once in a text', 1 === substr_count( $by['+14155550101']['Body'] ?? '', 'STOP' ) && 1 === substr_count( $by['+14155550104']['Body'] ?? '', 'STOP' ) );
rt( 'C: the old Spanish ending is gone', false === strpos( $by['+14155550104']['Body'] ?? '', 'no recibir más' ) );
rt( 'C: a WhatsApp reminder is unchanged: a template, no body', ! isset( $by['+14155550102']['Body'] ) && isset( $by['+14155550102']['ContentSid'] ) );
$GLOBALS['tw_calls'] = array();
SFAF_Texts::send_update( 900, 'Doors open at 9:45. Reply STOP to opt out.', 1 );
$by = tw_bodies();
rt( 'C: Text registrants, English', 'SFAF: Update about Coffee & Conversation: Doors open at 9:45. Reply STOP to opt out. For questions, please email events@sfaf.org. Reply STOP to opt out.' === ( $by['+14155550101']['Body'] ?? '' ) );
rt( 'C: Text registrants, Spanish', 0 === strpos( $by['+14155550104']['Body'] ?? '', 'SFAF: Novedad sobre Coffee & Conversation: ' ) && 'Responda STOP para cancelar.' === substr( $by['+14155550104']['Body'] ?? '', -strlen( 'Responda STOP para cancelar.' ) ) );
$GLOBALS['kit_trans'] = array();
$auto = SFAF_Texts::answer( '+14155550101', 'Is there parking?' );
rt( 'C: the automatic reply by text', "SFAF: This number isn't monitored. For questions about Coffee & Conversation, please contact events@sfaf.org. Reply STOP to opt out." === $auto );
$GLOBALS['kit_trans'] = array();
$wa_auto = SFAF_Texts::answer( 'whatsapp:+14155550103', 'Is there parking?' );
rt( 'C: the automatic reply on WhatsApp is unchanged', "This number isn't monitored. For questions about Coffee & Conversation, please contact events@sfaf.org." === $wa_auto );
rt( 'C: HELP on WhatsApp is unchanged', 0 === strpos( SFAF_Texts::answer( 'whatsapp:+14155550103', 'HELP' ), 'SFAF messages about ' ) );
rt( 'C: STOP and HELP by text are still left to the Messaging Service', '' === SFAF_Texts::answer( '+14155550101', 'HELP' ) && '' === SFAF_Texts::answer( '+14155550101', 'STOP' ) );

/* ======================================================================== D */
kit_reset();
$GLOBALS['wpdb'] = new Tw_Db();
function ev( $meta, $status = 'publish', $title = 'Drop-in' ) {
    $id = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => $status, 'post_title' => $title, 'post_author' => 1 ) );
    foreach ( $meta as $k => $v ) { update_post_meta( $id, $k, $v ); }
    return $id;
}
$t = function ( $s ) { return ( new DateTime( $s, wp_timezone() ) )->getTimestamp(); };
$a = ev( array( '_uc_event_date' => '2026-11-03', '_uc_start_time' => '18:00', '_uc_end_time' => '19:30' ) );
rt( 'D: before its end it has not ended', ! sfaf_event_has_ended( $a, $t( '2026-11-03 19:29' ) ) );
rt( 'D: at its end time it has', sfaf_event_has_ended( $a, $t( '2026-11-03 19:30' ) ) );
$b = ev( array( '_uc_event_date' => '2026-11-03', '_uc_start_time' => '18:00' ) );
rt( 'D: with no end time, its start time decides', ! sfaf_event_has_ended( $b, $t( '2026-11-03 17:59' ) ) && sfaf_event_has_ended( $b, $t( '2026-11-03 18:00' ) ) );
$c = ev( array( '_uc_event_date' => '2026-11-03' ) );
rt( 'D: with no time at all, its day decides', ! sfaf_event_has_ended( $c, $t( '2026-11-03 23:00' ) ) && sfaf_event_has_ended( $c, $t( '2026-11-04 00:00' ) ) );
$d = ev( array( '_uc_event_date' => '2026-11-03', '_uc_start_time' => '22:00', '_uc_end_time' => '01:00' ) );
rt( 'D: an end past midnight is the next day', ! sfaf_event_has_ended( $d, $t( '2026-11-04 00:30' ) ) && sfaf_event_has_ended( $d, $t( '2026-11-04 01:00' ) ) );
$e = ev( array( '_uc_event_date' => '2026-11-03', '_uc_end_date' => '2026-11-05', '_uc_start_time' => '10:00', '_uc_end_time' => '16:00' ) );
rt( "D: a source's last day moves the end", ! sfaf_event_has_ended( $e, $t( '2026-11-04 17:00' ) ) && sfaf_event_has_ended( $e, $t( '2026-11-05 16:00' ) ) );
rt( 'D: no date, not ended', ! sfaf_event_has_ended( ev( array() ), $t( '2030-01-01' ) ) );

$old = ev( array( '_uc_event_date' => '2020-01-01', '_uc_start_time' => '10:00', '_uc_rsvp_enabled' => '1' ) );
$rsvp = new SFAF_RSVP();
$r_en = $rsvp->submit( array( 'event_id' => $old, 'first_name' => 'Ana', 'email' => 'ana@example.org', 'lang' => 'en' ) );
$r_es = $rsvp->submit( array( 'event_id' => $old, 'first_name' => 'Ana', 'email' => 'ana@example.org', 'lang' => 'es' ) );
rt( 'D: the server refuses an ended event', false === $r_en['success'] && 'This event has ended.' === $r_en['message'] );
rt( 'D: in Spanish', false === $r_es['success'] && 'Este evento ya terminó.' === $r_es['message'] );
rt( 'D: and writes nothing', array() === $GLOBALS['wpdb']->inserted );
$fut = ev( array( '_uc_event_date' => date( 'Y-m-d', strtotime( '+20 days' ) ), '_uc_start_time' => '10:00', '_uc_rsvp_enabled' => '1' ) );
$r_ok = $rsvp->submit( array( 'event_id' => $fut, 'first_name' => 'Ana', 'email' => 'ana@example.org', 'lang' => 'en' ) );
rt( 'D: an event still to come registers (' . ( $r_ok['message'] ?? '' ) . ')', true === $r_ok['success'] && 1 === count( $GLOBALS['wpdb']->inserted ) );

/* ======================================================================== E */
function rt_page( $id ) {
    $GLOBALS['page_ev'] = $id;
    $GLOBALS['loop_done'] = false;
    ob_start();
    include $GLOBALS['kit_root'] . '/templates/single-uc_event.php';
    return (string) ob_get_clean();
}
kit_reset();
$GLOBALS['wpdb'] = new Tw_Db();
$past = ev( array( '_uc_event_date' => '2026-01-06', '_uc_start_time' => '18:00', '_uc_end_time' => '19:30', '_uc_rsvp_enabled' => '1', '_uc_location' => '1035 Market St' ), 'publish', 'Support group' );
$soon = ev( array( '_uc_event_date' => date( 'Y-m-d', strtotime( '+6 days' ) ), '_uc_start_time' => '18:00', '_uc_rsvp_enabled' => '1' ), 'publish', 'Support group' );
$next = ev( array( '_uc_event_date' => date( 'Y-m-d', strtotime( '+13 days' ) ), '_uc_start_time' => '18:30', '_uc_rsvp_enabled' => '1' ), 'publish', 'Support group' );
$gone = ev( array( '_uc_event_date' => date( 'Y-m-d' ), '_uc_start_time' => '00:00', '_uc_end_time' => '00:01' ), 'publish', 'Support group' );
foreach ( array( $past, $soon, $next, $gone ) as $id ) { wp_set_object_terms( $id, array( 11 ), 'uc_series' ); }
$GLOBALS['kit_query'] = function ( $a ) use ( $past, $soon, $next, $gone ) { return array( $past, $gone, $soon, $next ); };
$html = rt_page( $past );
$xp   = rt_x( $html );
$side = $xp->query( '//aside[contains(@class,"uc-single-sidebar")]' )->item( 0 );
$side_html = $side ? $side->ownerDocument->saveHTML( $side ) : '';
rt( 'E: the page drew (' . substr( $html, 0, 80 ) . ')', '' !== $side_html );
rt( 'E: "This event has ended." in the sidebar card', 1 === $xp->query( '//aside//*[@data-uc-ended]//p[contains(@class,"uc-ended-line") and normalize-space(.)="This event has ended."]' )->length );
rt( 'E: no Register button and no RSVP form', 0 === $xp->query( '//*[contains(@class,"uc-rsvp-btn")]' )->length && false === strpos( $html, 'data-event-id' ) );
/* Add to Calendar shows only without RSVPs, so it is asked of an event without them. */
$cal_past = ev( array( '_uc_event_date' => '2026-01-08', '_uc_start_time' => '18:00' ), 'publish', 'Open house' );
$cal_soon = ev( array( '_uc_event_date' => date( 'Y-m-d', strtotime( '+8 days' ) ), '_uc_start_time' => '18:00' ), 'publish', 'Open house' );
rt( 'E: no Add to Calendar', false === strpos( rt_page( $cal_past ), 'uc-addcal' ) && false !== strpos( rt_page( $cal_soon ), 'uc-addcal' ) );
$links = $xp->query( '//aside//*[@data-uc-ended-dates]//a' );
$hrefs = array();
$words = array();
foreach ( $links as $l ) { $hrefs[] = $l->getAttribute( 'href' ); $words[] = trim( preg_replace( '/\s+/', ' ', $l->textContent ) ); }
rt( 'E: the two upcoming dates, in order, linking to each', array( get_permalink( $soon ), get_permalink( $next ) ) === $hrefs );
rt( 'E: each with its weekday and time',
    2 === count( $words ) && 0 === strpos( $words[0], sfaf_ap_date( get_post_meta( $soon, '_uc_event_date', true ), 'full' ) ) && false !== strpos( $words[1], sfaf_ap_time( '18:30' ) )
    && 1 === preg_match( '/^(Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday), /', $words[0] ) );
rt( 'E: not this event, and not one that has ended too', ! in_array( get_permalink( $past ), $hrefs, true ) && ! in_array( get_permalink( $gone ), $hrefs, true ) );
rt( 'E: not listed twice: no "Upcoming in this series" box', 0 === $xp->query( '//*[@data-series]' )->length );
rt( 'E: the style travels with the block, not calendar.css', 1 === $xp->query( '//style[@id="uc-ended-css"]' )->length && false === strpos( (string) file_get_contents( $GLOBALS['kit_root'] . '/public/css/calendar.css' ), 'uc-ended' ) );
update_post_meta( $past, '_uc_language', 'es' );
$es_html = rt_page( $past );
rt( 'E: a Spanish event says it in both', false !== strpos( $es_html, 'This event has ended. / Este evento ya terminó.' ) );
delete_post_meta( $past, '_uc_language' );
/* Another series, since the series list is kept for the request. */
$lone = ev( array( '_uc_event_date' => '2026-01-07', '_uc_start_time' => '18:00', '_uc_rsvp_enabled' => '1' ), 'publish', 'Book club' );
wp_set_object_terms( $lone, array( 12 ), 'uc_series' );
$GLOBALS['kit_query'] = function ( $a ) use ( $lone ) { return array( $lone ); };
$alone = rt_x( rt_page( $lone ) );
rt( 'E: with no upcoming dates, the line alone', 1 === $alone->query( '//*[@data-uc-ended]' )->length && 0 === $alone->query( '//*[@data-uc-ended-dates]' )->length );
$GLOBALS['kit_query'] = function ( $a ) use ( $past, $soon, $next ) { return array( $past, $soon, $next ); };
$live = rt_page( $soon );
$lx   = rt_x( $live );
rt( 'E: an event still to come is as before: Register, no ended line', 1 === $lx->query( '//*[contains(@class,"uc-rsvp-btn")]' )->length && 0 === $lx->query( '//*[@data-uc-ended]' )->length );
rt( 'E: and its "Upcoming in this series" box', 1 === $lx->query( '//*[@data-series]' )->length );
$tpl = (string) file_get_contents( $GLOBALS['kit_root'] . '/templates/single-uc_event.php' );
rt( 'E: the template asks once, at the top of the loop', 1 === substr_count( $tpl, 'sfaf_event_has_ended(' ) );

/* ======================================================================== F */
tw_fresh();
$GLOBALS['kit_query'] = null;
update_post_meta( 900, '_uc_event_date', date( 'Y-m-d', strtotime( '+10 days' ) ) );
update_post_meta( 900, SFAF_Privacy::META, '1' );
$GLOBALS['kit_posts'][900]->post_name = str_repeat( '7', 8 ) . 'abcdef0123456789abcdef01';
$rsvp = new SFAF_RSVP();
$pr = $rsvp->submit( array( 'event_id' => 900, 'first_name' => 'Ana', 'email' => 'ana@example.org', 'lang' => 'en', 'phone' => '(415) 555-0101', 'text_channel' => 'sms' ) );
rt( 'F: a published private event takes a registration (' . ( $pr['message'] ?? '' ) . ')', true === $pr['success'] && 1 === count( $GLOBALS['wpdb']->inserted ) );
rt( 'F: with the channel kept', 'sms' === ( $GLOBALS['wpdb']->inserted[0]['text_channel'] ?? '' ) );
$priv = tw_row( 1, 900, '(415) 555-0101', 'sms', 'en', 'Ana' );
$GLOBALS['tw_calls'] = array();
SFAF_Texts::send_confirmation( $priv );
$by = tw_bodies();
rt( 'F: its confirmation text links the private address and the cancel link',
    false !== strpos( $by['+14155550101']['Body'] ?? '', get_permalink( 900 ) ) && false !== strpos( $by['+14155550101']['Body'] ?? '', $cancel( $priv->token ) ) );
$GLOBALS['wpdb']->rsvps = array( $priv );
$GLOBALS['tw_calls'] = array();
$sent = SFAF_Texts::send_update( 900, 'Doors open at 9:45.', 1 );
rt( 'F: Text registrants reaches a private event\'s registrants', 1 === $sent['sms'] );
update_post_meta( 900, '_uc_event_date', current_time( 'Y-m-d' ) );
$GLOBALS['tw_calls'] = array();
$rem = SFAF_Texts::send_reminders( 900 );
rt( 'F: the reminder by text goes for a private event', 1 === $rem['sent'] );
rt( 'F: the cancel link is the same as any event\'s', $cancel( $priv->token ) === SFAF_Texts::cancel_link( $priv ) && false !== strpos( $cancel( $priv->token ), 'uc_rsvp_cancel=' ) );
$rm = (string) file_get_contents( $GLOBALS['kit_root'] . '/includes/class-sfaf-registrant-mail.php' ) . (string) file_get_contents( $GLOBALS['kit_root'] . '/includes/class-sfaf-reminders.php' );
rt( 'F: Email registrants and the reminder do not ask whether an event is private', false === strpos( $rm, 'SFAF_Privacy' ) && false === strpos( $rm, '_uc_private' ) );

/* Not published yet: staff are told why, anybody else nothing. */
$GLOBALS['kit_posts'][900]->post_status = 'draft';
update_post_meta( 900, '_uc_event_date', date( 'Y-m-d', strtotime( '+10 days' ) ) );
$GLOBALS['wpdb']->inserted = array();
$staff = $rsvp->submit( array( 'event_id' => 900, 'first_name' => 'Ana', 'email' => 'ana@example.org', 'lang' => 'en' ) );
rt( 'F: staff on a draft are told it takes registrations once published (' . ( $staff['message'] ?? '' ) . ')', false === $staff['success'] && 'This event takes registrations once it is published.' === $staff['message'] );
$GLOBALS['kit_user_id'] = 0;
$visitor = $rsvp->submit( array( 'event_id' => 900, 'first_name' => 'Ana', 'email' => 'ana@example.org', 'lang' => 'en' ) );
rt( 'F: anybody else, "could not be found", as before', false === $visitor['success'] && 'This event could not be found.' === $visitor['message'] );
rt( 'F: nothing written either way', array() === $GLOBALS['wpdb']->inserted );
$GLOBALS['kit_user_id'] = 1;

/* The private link shown before publishing is the address it will have. */
$live_link = SFAF_Preview::live_link( 900 );
// The kit's get_permalink() ignores the status, so this asks for the published copy's address, not ?p=.
$as_live = clone $GLOBALS['kit_posts'][900];
$as_live->post_status = 'publish';
rt( 'F: a draft private event\'s link is the address it will have (' . $live_link . ')', get_permalink( $as_live ) === $live_link && false === strpos( $live_link, 'p=900' ) );
rt( 'F: a published one\'s is its permalink', ( function () { $GLOBALS['kit_posts'][900]->post_status = 'publish'; $l = SFAF_Preview::live_link( 900 ); $GLOBALS['kit_posts'][900]->post_status = 'draft'; return $l === get_permalink( 900 ); } )() );
$portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();
$card = rt_cap( function () use ( $portal ) { kit_call( 'SFAF_Portal', 'render_private_control', $portal, array( 900, false, 'card' ) ); } );
$cx = rt_x( $card );
$box = $cx->query( '//input[@data-uc-private-link]' )->item( 0 );
rt( 'F: the card\'s box holds that address', $box && $live_link === $box->getAttribute( 'value' ) );
rt( 'F: and says the link works once the event is published', 1 === $cx->query( '//*[@data-uc-private-not-live]' )->length
    && false !== strpos( $card, 'The link opens the event and takes registrations once the event is published.' ) );
$GLOBALS['kit_posts'][900]->post_status = 'publish';
$card = rt_cap( function () use ( $portal ) { kit_call( 'SFAF_Portal', 'render_private_control', $portal, array( 900, false, 'card' ) ); } );
rt( 'F: a published private event\'s card has no such line', false === strpos( $card, 'data-uc-private-not-live' ) );

/* ======================================================================== G */
$fw = SFAF_Messages::form_words();
rt( 'G: Terms, and Términos', 'Terms' === ( $fw['en']['terms'] ?? '' ) && 'Términos' === ( $fw['es']['terms'] ?? '' ) );
$js = (string) file_get_contents( $GLOBALS['kit_root'] . '/public/js/calendar.js' );
$p0 = strpos( $js, 'id="uc-rsvp-text-consent"' );
$p1 = strpos( $js, 'id="uc-rsvp-privacy"' );
$p2 = strpos( $js, 'id="uc-rsvp-terms" href="https://www.sfaf.org/calendar/events-calendar-terms-conditions/" target="_blank" rel="noopener" data-uc-w="terms">Terms</a></p>' );
rt( 'G: the Terms link is in the consent line, after the privacy policy', false !== $p0 && false !== $p1 && false !== $p2 && $p0 < $p1 && $p1 < $p2 );

echo "\n3.134.0: the confirmation by text and WhatsApp, every text as the campaign has it, ended events, private registrations\n" . str_repeat( '=', 72 ) . "\n";
echo "release-31340: $ran checks\n";
if ( $fails ) { foreach ( $fails as $f ) { echo "FAIL  $f\n"; } exit( 1 ); }
echo "every check passed.\n";
exit( 0 );
