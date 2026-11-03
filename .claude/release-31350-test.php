<?php
/**
 * 3.135.0: THE YELLOW AUDIT APPLIED, WHAT WHATSAPP TAKES, THE LANGUAGE BESIDE
 * EACH REGISTRANT, THE RSVP COUNT AS A BUTTON, 600 CHARACTERS AND THE COUNT.
 *
 *     php .claude/release-31350-test.php
 *
 * The real plugin on wp-kit.php, with release-31340-test.php's tables and
 * Twilio recorded, never reached.
 *
 *   A. The audit: the cancel-and-email buttons and Reassign and remove red,
 *      Submit for Review and the Approve, Save and email them and Publish
 *      dialogs green, the extra yellows grey, in caladmin, WP admin and the
 *      public request form; what was right left alone.
 *   B. Emails: an online event's confirmation and reminder keep one yellow,
 *      Join the event; Add to Google and See the event page are outlined.
 *      An in-person event's are as before.
 *   C. "What can I send by WhatsApp?" under Text registrants' box, Mark's
 *      lists and line, only with WhatsApp on.
 *   D. EN or ES beside each registrant: the RSVP list, its waitlist, Email
 *      registrants and Text registrants.
 *   E. The Events list's RSVP count as a small secondary button, "12 RSVPs"
 *      or "View RSVPs", only on an event that takes RSVPs here.
 *   F. 600 characters, and a long message going whole by WhatsApp.
 *   G. The count: characters of 600, and pieces by text from the whole text,
 *      the script and SFAF_Texts::pieces() agreeing. The browser half is
 *      release-31350-live.php.
 *
 * PLANTs in plant-31350.php.
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


/* ---- 3.135.0: the list across the event, and the waitlist's. ---- */
class Tw_Db_List extends Tw_Db {
    function get_results( $sql, $o = null ) {
        if ( false !== strpos( $sql, 'LEFT JOIN' ) && false !== strpos( $sql, 'uc_rsvps' ) ) {
            $out = array();
            foreach ( $this->rsvps as $r ) {
                if ( preg_match( '/r\.event_id = (\d+)/', $sql, $m ) && (int) $m[1] !== (int) $r->event_id ) { continue; }
                $c = clone $r;
                $c->post_title = get_the_title( $r->event_id );
                $out[] = $c;
            }
            return $out;
        }
        if ( preg_match( '/^SELECT \* FROM \S*uc_rsvps WHERE event_id = (\d+)$/', trim( $sql ), $m ) ) {
            return array_values( array_filter( $this->rsvps, function ( $r ) use ( $m ) { return (int) $r->event_id === (int) $m[1]; } ) );
        }
        return parent::get_results( $sql );
    }
}
function tl_fresh( $rows ) {
    tw_fresh();
    $db = new Tw_Db_List();
    foreach ( $rows as $r ) {
        foreach ( array( 'created_at' => '2026-11-01 10:00:00', 'agreed_at' => null, 'removed_by' => 0, 'cancelled_at' => null, 'event_title' => '', 'attended' => 0 ) as $k => $v ) {
            if ( ! isset( $r->$k ) ) { $r->$k = $v; }
        }
    }
    $db->rsvps = $rows;
    $GLOBALS['wpdb'] = $db;
}
$portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();
$root   = $GLOBALS['kit_root'];
$P      = (string) file_get_contents( $root . '/includes/class-sfaf-portal.php' );
$JS     = (string) file_get_contents( $root . '/public/js/portal.js' );
function tl_line( $src, $needle ) {
    foreach ( explode( "\n", $src ) as $l ) { if ( false !== strpos( $l, $needle ) ) { return $l; } }
    return '';
}

/* ======================================================================== A */
// To red.
rt( 'A: the series "Cancel these and email the N people" is red', false !== strpos( tl_line( $P, 'name="notify_choice" value="send"' ), 'class="uc-btn uc-btn-danger"' ) );
rt( 'A: Users "Reassign and remove" is red', false !== strpos( tl_line( $P, 'Reassign and remove</button>' ), '<button type="submit" class="uc-btn uc-btn-danger">Reassign and remove</button>' ) );
rt( 'A: "Cancel and email them" is red on the first step', false !== strpos( $JS, "sendClass: back ? '' : 'uc-btn-danger'," ) );
rt( 'A: and on the second step, the same class', false !== strpos( $JS, "confirmSend.className = 'uc-btn uc-btn-sm ' + (opts.sendClass || 'uc-btn-primary');" )
    && false !== strpos( $JS, "send.className = 'uc-btn uc-btn-sm ' + (opts.sendClass || 'uc-btn-primary');" ) );
// To green.
rt( 'A: Submit for Review is green and the size of Publish', false !== strpos( tl_line( $P, '>Submit for Review</button>' ), 'class="uc-btn uc-btn-go uc-editor-publish"' ) );
rt( 'A: "Save and email them" is green', false !== strpos( $JS, "sendLabel: 'Save and email ' + (1 === people ? 'them' : 'them all'),\n                sendClass: 'uc-btn-go'," ) );
rt( 'A: the Approve dialog confirms in green, Reject in red', false !== strpos( $JS, "(btn.hasAttribute('data-uc-ask-danger') ? 'uc-btn-danger' : 'uc-btn-go')" ) );
rt( 'A: a green trigger, Publish, confirms in green', false !== strpos( $JS, "(danger ? 'uc-btn-danger' : (btn.classList.contains('uc-btn-go') ? 'uc-btn-go' : 'uc-btn-primary'))" ) );
// To grey secondary.
foreach ( array(
    'Venues: Save venue on every row'      => "<?php echo \$venue ? 'Save venue' : 'Add venue'; ?></button>",
    'Users: Save on every row'             => 'data-uc-user-save>Save</button>',
    'Preferences: the password Save'       => '<button type="submit" class="uc-btn">Save</button>',
    'RSVP list: Export CSV'                => '>Export CSV</a>',
    'RSVP list: Save settings'             => '>Save settings</button>',
    'Organizers: Add organizer'            => '>Add organizer</button>',
    'FAQ sets: Create set'                 => '>Create set</button>',
    'FAQ sets: Save set'                   => '>Save set</button>',
    "Add event: Fill these in"             => 'data-uc-prefill-apply>Fill these in</button>',
) as $what => $needle ) {
    $l = tl_line( $P, $needle );
    rt( "A: $what is grey", '' !== $l && false === strpos( $l, 'uc-btn-primary' ) && false !== strpos( $l, 'uc-btn' ) );
}
rt( 'A: Save attendance is grey', 1 === preg_match( '/name="attendance"[^\n]*\n[^\n]*\n\s*<button type="submit" class="uc-btn">Save<\/button>/', $P ) );
$org = substr( $P, strpos( $P, '>Add organizer</button>' ), 6000 );
rt( 'A: Organizers: the Save on an opened row is grey', 1 === preg_match( '/<button type="submit" class="uc-btn">Save<\/button>/', $org ) );
$bulk = substr( $P, strpos( $P, 'private function render_events(' ), strpos( $P, 'private function events_table(' ) - strpos( $P, 'private function render_events(' ) );
rt( 'A: the Events list bulk buttons are grey, + New Event keeps the yellow', 1 === substr_count( $bulk, 'uc-btn-primary' ) && false !== strpos( tl_line( $bulk, 'uc-btn-primary' ), 'New Event' ) );
$rq = (string) file_get_contents( $root . '/includes/class-sfaf-request.php' );
rt( 'A: the request form: Fill these in is grey', false === strpos( tl_line( $rq, 'data-uc-prefill-apply>Fill these in' ), 'uc-btn-primary' ) );
rt( 'A: the form-links Done is grey', false !== strpos( $JS, "done.className = 'uc-btn uc-btn-sm';" ) );
rt( 'A: Restore is grey', false !== strpos( (string) file_get_contents( $root . '/public/js/caladmin-session.js' ), "yes.className = 'uc-btn';" ) );
$tx = (string) file_get_contents( $root . '/includes/class-sfaf-texts.php' );
$mc = (string) file_get_contents( $root . '/includes/class-sfaf-mailchimp.php' );
$ad = (string) file_get_contents( $root . '/admin/class-sfaf-admin.php' );
rt( 'A: WP admin: Save Twilio settings is grey', '<button type="submit" class="button">Save Twilio settings</button>' === trim( tl_line( $tx, 'Save Twilio settings</button>' ) ) );
rt( "A: WP admin: Mailchimp's Save connection is grey", '<button type="submit" class="button">Save connection</button>' === trim( tl_line( $mc, 'Save connection</button>' ) ) );
rt( 'A: WP admin: Run now is grey', false !== strpos( tl_line( $ad, '>Run now</button>' ), 'class="button" ' ) );
rt( 'A: WP admin: Send it is grey', false !== strpos( tl_line( $ad, '>Send it</button>' ), 'class="button" ' ) );
// Left as they were.
rt( 'A: Save All Settings keeps the yellow', 1 === preg_match( "/submit_button\([^)]*'primary'/", $ad ) );
rt( 'A: Save preferences keeps the yellow', false !== strpos( tl_line( $P, '>Save preferences</button>' ), 'uc-btn-primary' ) );
rt( 'A: Users: Add keeps the yellow', 1 === preg_match( '/class="uc-btn uc-btn-primary"[^>]*>Add<\/button>/', $P ) );
rt( 'A: Add a venue keeps the yellow', 1 === substr_count( substr( $P, strpos( $P, 'Add a venue' ) - 400, 500 ), 'uc-btn-primary' ) || false !== strpos( substr( $P, strpos( $P, 'Add a venue' ) - 400, 500 ), 'uc-add-toggle' ) );
rt( 'A: Save draft keeps the in-between yellow', false !== strpos( $P, "\$save_main = \$keep_status ? ' uc-btn-go uc-editor-save-main' : ' uc-btn-primary';" ) );
rt( 'A: Put it back on and tell them stays as it was', false !== strpos( $JS, "sendClass: back ? '' : 'uc-btn-danger'," ) );

/* ======================================================================== B */
$gcal = 'https://calendar.google.com/x';
$ics  = 'https://resources.sfaf.org/x.ics';
$cal  = array( 'label' => 'add_to_calendar', 'gcal' => $gcal, 'ics' => $ics );
$vals = array( 'first_name' => 'Ana', 'event_title' => 'Coffee', 'event_date' => 'Dec. 1', 'event_time' => '10 am', 'event_link' => 'https://resources.sfaf.org/events/900/' );
function tl_btn( $html, $url ) {
    // The cell around the link: its fill says which style it is.
    $at = strpos( $html, 'href="' . $url . '"' );
    if ( false === $at ) { return 'none'; }
    $td = strrpos( substr( $html, 0, $at ), '<td ' );
    return false !== strpos( substr( $html, $td, $at - $td ), 'bgcolor="#FFD900"' ) ? 'primary' : 'outline';
}
$on  = SFAF_Messages::compose( 'confirmation', 'online_link', 'en', $vals, array( 'joining' => SFAF_Messages::joining_html( 'https://zoom.us/j/1', 'en' ), 'calendar' => $cal, 'event_url' => 'https://resources.sfaf.org/events/900/' ) );
$off = SFAF_Messages::compose( 'confirmation', 'in_person', 'en', $vals, array( 'calendar' => $cal, 'event_url' => 'https://resources.sfaf.org/events/900/' ) );
rt( 'B: online confirmation: Join the event is the yellow', 'primary' === tl_btn( $on['html'], 'https://zoom.us/j/1' ) );
rt( 'B: online confirmation: Add to Google is outlined', 'outline' === tl_btn( $on['html'], $gcal ) );
rt( 'B: online confirmation: one yellow', 1 === substr_count( $on['html'], 'bgcolor="#FFD900"' ) );
rt( 'B: in person: Add to Google keeps the yellow', 'primary' === tl_btn( $off['html'], $gcal ) );
rt( 'B: Apple stays outlined', 'outline' === tl_btn( $on['html'], $ics ) && 'outline' === tl_btn( $off['html'], $ics ) );
$ron  = SFAF_Messages::compose( 'reminder', 'online_link', 'es', $vals, array( 'joining' => SFAF_Messages::joining_html( 'https://zoom.us/j/1', 'es' ), 'event_url' => 'https://resources.sfaf.org/events/900/', 'event_button' => true ) );
$roff = SFAF_Messages::compose( 'reminder', 'in_person', 'en', $vals, array( 'event_url' => 'https://resources.sfaf.org/events/900/', 'event_button' => true ) );
rt( 'B: online reminder: See the event page is outlined, in Spanish too', 'outline' === tl_btn( $ron['html'], 'https://resources.sfaf.org/events/900/' ) && 1 === substr_count( $ron['html'], 'bgcolor="#FFD900"' ) );
rt( 'B: in-person reminder: See the event page keeps the yellow', 'primary' === tl_btn( $roff['html'], 'https://resources.sfaf.org/events/900/' ) );
rt( 'B: has_primary reads only a button', ! SFAF_Email::has_primary( '<td bgcolor="#FFD900">' ) && SFAF_Email::has_primary( SFAF_Email::button( 'https://x', 'X' ) ) && ! SFAF_Email::has_primary( SFAF_Email::button( 'https://x', 'X', 'outline' ) ) );

/* ======================================================================== C */
$ppl = array(
    tw_row( 1, 900, '(415) 555-0101', 'sms', 'en', 'Ana' ),
    tw_row( 2, 900, '(415) 555-0102', 'whatsapp', 'es', 'Beto' ),
    tw_row( 3, 900, '(415) 555-0103', 'sms', '', 'Cruz' ),
);
tl_fresh( $ppl );
$me   = new WP_User();
$tr   = rt_cap( function () use ( $portal, $me ) { kit_call( 'SFAF_Portal', 'render_text_registrants', $portal, array( $me, 900 ) ); } );
$x    = rt_x( $tr );
$help = $x->query( '//details[@data-uc-tr-wa-help]' );
rt( 'C: Text registrants renders', false === strpos( $tr, 'THREW' ) && false !== strpos( $tr, 'data-uc-text-registrants' ) );
rt( 'C: "What can I send by WhatsApp?" opens a panel, closed', 1 === $help->length && ! $help->item( 0 )->hasAttribute( 'open' )
    && 'What can I send by WhatsApp?' === trim( $x->query( './summary', $help->item( 0 ) )->item( 0 )->textContent ) );
$items = array();
foreach ( $x->query( '//details[@data-uc-tr-wa-help]//li' ) as $li ) { $items[] = trim( $li->textContent ); }
rt( 'C: OK to send, the four', array( 'Changes to the time, date or location', 'Cancellations or postponements', 'What to bring, what to expect, accessibility or parking info', 'Check-in details or directions on the day' ) === array_slice( $items, 0, 4 ) );
rt( 'C: Not OK, the five', array( 'Promoting other events or programs', 'Donations or fundraising', "Surveys, feedback or \u{201C}rate this event\u{201D}", 'Newsletters or general SFAF announcements', "\u{201C}Don\u{2019}t miss,\u{201D} \u{201C}join us for,\u{201D} or offers of any kind" ) === array_slice( $items, 4 ) );
$helptext = $help->length ? preg_replace( '/\s+/', ' ', $help->item( 0 )->textContent ) : '';
rt( 'C: the two headings and the warning', false !== strpos( $helptext, 'OK to send:' ) && false !== strpos( $helptext, 'Not OK:' ) && false !== strpos( $helptext, 'If WhatsApp decides a message is promotional, it may block it, and future messages too.' ) );
rt( 'C: it is under the message box', false !== strpos( $tr, 'data-uc-tr-message' ) && strpos( $tr, 'data-uc-tr-message' ) < strpos( $tr, 'data-uc-tr-wa-help' ) && strpos( $tr, 'data-uc-tr-wa-help' ) < strpos( $tr, 'data-uc-rcp' ) );
// WhatsApp off: texts only.
SFAF_Texts::save( array( 'twilio_account_sid' => $GLOBALS['SID'], 'twilio_messaging_sid' => $GLOBALS['MG'], 'twilio_whatsapp_from' => '' ), 'abcdef0123456789abcdef012345WXYZ' );
$tr_off = rt_cap( function () use ( $portal, $me ) { kit_call( 'SFAF_Portal', 'render_text_registrants', $portal, array( $me, 900 ) ); } );
rt( 'C: with WhatsApp off there is no panel', false === strpos( $tr_off, 'THREW' ) && false !== strpos( $tr_off, 'data-uc-tr-form' ) && false === strpos( $tr_off, 'data-uc-tr-wa-help' ) );

/* ======================================================================== D */
tl_fresh( $ppl );
$tr = rt_cap( function () use ( $portal, $me ) { kit_call( 'SFAF_Portal', 'render_text_registrants', $portal, array( $me, 900 ) ); } );
$x  = rt_x( $tr );
function tl_tags( $x, $rows ) {
    $o = array();
    foreach ( $x->query( $rows ) as $row ) {
        $name = $x->query( './/*[contains(@class,"uc-rcp-name")] | .//td[contains(@class,"uc-rc-name")]/strong | .//td/strong', $row );
        $tag  = $x->query( './/span[@data-uc-lang-tag]', $row );
        $o[ $name->length ? trim( $name->item( 0 )->textContent ) : '?' ] = $tag->length ? $tag->item( 0 )->getAttribute( 'data-uc-lang-tag' ) . '|' . trim( $x->query( './span[@aria-hidden]', $tag->item( 0 ) )->item( 0 )->textContent ) : '';
    }
    return $o;
}
rt( 'D: Text registrants: EN or ES beside each, in the language chosen; none chosen is the event\'s', array( 'Ana' => 'en|EN', 'Beto' => 'es|ES', 'Cruz' => 'en|EN' ) === tl_tags( $x, '//li[@data-uc-rcp-row]' ) );
rt( 'D: a screen reader hears the language', false !== strpos( $tr, '<span class="uc-visually-hidden">Spanish</span>' ) );
update_post_meta( 900, '_uc_language', 'es' );
$tr = rt_cap( function () use ( $portal, $me ) { kit_call( 'SFAF_Portal', 'render_text_registrants', $portal, array( $me, 900 ) ); } );
rt( 'D: on a Spanish event, the one who chose nothing is ES', 'es|ES' === ( tl_tags( rt_x( $tr ), '//li[@data-uc-rcp-row]' )['Cruz'] ?? '' ) );
delete_post_meta( 900, '_uc_language' );
$rm = rt_cap( function () use ( $portal, $me ) { kit_call( 'SFAF_Portal', 'render_registrant_mail', $portal, array( $me, 900 ) ); } );
rt( 'D: Email registrants renders', false === strpos( $rm, 'THREW' ) && false !== strpos( $rm, 'data-uc-registrant-mail' ) );
rt( 'D: Email registrants: EN or ES beside each', array( 'Ana' => 'en|EN', 'Beto' => 'es|ES', 'Cruz' => 'en|EN' ) === tl_tags( rt_x( $rm ), '//li[@data-uc-rcp-row]' ) );
$GLOBALS['kit_user_id'] = 1;
$_GET = array( 'event_id' => 900 );
$list = rt_cap( function () use ( $portal ) { kit_call( 'SFAF_Portal', 'render_rsvps', $portal, array( wp_get_current_user() ) ); } );
$_GET = array();
rt( 'D: the RSVP list renders', false === strpos( $list, 'THREW' ) && false !== strpos( $list, 'uc-rsvp-table' ) );
rt( 'D: the RSVP list: EN or ES beside each name', array( 'Ana' => 'en|EN', 'Beto' => 'es|ES', 'Cruz' => 'en|EN' ) === tl_tags( rt_x( $list ), '//table[contains(@class,"uc-rsvp-table")]//tr[@data-uc-rsvp-row]' ) );
$wl = $ppl;
$wl[] = tw_row( 4, 900, '', '', 'es', 'Dana', 'waitlisted' );
tl_fresh( $wl );
$w = rt_cap( function () use ( $portal, $me ) { kit_call( 'SFAF_Portal', 'render_waitlist_section', $portal, array( $me, 900 ) ); } );
rt( 'D: the waitlist on the same page: ES beside Dana', array( 'Dana' => 'es|ES' ) === tl_tags( rt_x( $w ), '//tr[@data-uc-waitlist-row]' ) );

/* ======================================================================== E */
tl_fresh( array() );
kit_write_post( array( 'ID' => 901, 'post_title' => 'No RSVPs here', 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_author' => 1 ) );
update_post_meta( 901, '_uc_event_date', '2026-12-02' );
kit_write_post( array( 'ID' => 902, 'post_title' => 'Twelve', 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_author' => 1 ) );
update_post_meta( 902, '_uc_event_date', '2026-12-03' );
update_post_meta( 902, '_uc_rsvp_enabled', '1' );
kit_write_post( array( 'ID' => 903, 'post_title' => 'One', 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_author' => 1 ) );
update_post_meta( 903, '_uc_event_date', '2026-12-04' );
update_post_meta( 903, '_uc_rsvp_enabled', '1' );
$store =& sfaf_rsvp_count_store();
$store[900] = 0; $store[901] = 3; $store[902] = 12; $store[903] = 1;
$admin = wp_get_current_user();
$ev = rt_cap( function () use ( $portal, $admin ) { kit_call( 'SFAF_Portal', 'events_table', $portal, array( array( 900, 901, 902, 903 ), $admin ) ); } );
$x  = rt_x( $ev );
function tl_rsvp_cell( $x, $title ) {
    foreach ( $x->query( '//tr[td]' ) as $tr ) {
        if ( false !== strpos( $tr->textContent, $title ) ) {
            $b = $x->query( './/a[@data-uc-rsvp-count-btn]', $tr );
            if ( $b->length ) { return 'button:' . trim( $b->item( 0 )->textContent ) . '|' . $b->item( 0 )->getAttribute( 'class' ) . '|' . $b->item( 0 )->getAttribute( 'href' ); }
            $cells = $x->query( './td', $tr );
            foreach ( $cells as $i => $td ) { if ( preg_match( '/^\d+$/', trim( $td->textContent ) ) ) { return 'plain:' . trim( $td->textContent ); } }
            return 'nothing';
        }
    }
    return 'no row';
}
rt( 'E: the Events list renders', false === strpos( $ev, 'THREW' ) );
rt( 'E: none yet: a small secondary "View RSVPs"', 0 === strpos( tl_rsvp_cell( $x, 'Coffee' ), 'button:View RSVPs|uc-btn uc-btn-sm uc-rsvp-count-btn|' ) );
rt( 'E: "12 RSVPs", to the event\'s list', 0 === strpos( tl_rsvp_cell( $x, 'Twelve' ), 'button:12 RSVPs|uc-btn uc-btn-sm uc-rsvp-count-btn|' ) && false !== strpos( tl_rsvp_cell( $x, 'Twelve' ), 'event_id=902' ) );
rt( 'E: "1 RSVP"', 0 === strpos( tl_rsvp_cell( $x, 'One' ), 'button:1 RSVP|' ) );
rt( 'E: an event that does not take RSVPs here keeps the plain number', 'plain:3' === tl_rsvp_cell( $x, 'No RSVPs here' ) );
update_post_meta( 902, SFAF_Register_Elsewhere::META_MODE, 'elsewhere' );
update_post_meta( 902, SFAF_Register_Elsewhere::META_URL, 'https://example.org/register' );
$x2 = rt_x( rt_cap( function () use ( $portal, $admin ) { kit_call( 'SFAF_Portal', 'events_table', $portal, array( array( 902 ), $admin ) ); } ) );
rt( 'E: registering on another site is not here: the plain number', SFAF_Register_Elsewhere::is_on( 902 ) && 'plain:12' === tl_rsvp_cell( $x2, 'Twelve' ) );
delete_post_meta( 902, SFAF_Register_Elsewhere::META_MODE );
rt( 'E: only for who can open the list', false !== strpos( $P, "if ( sfaf_event_takes_rsvps( \$id ) && \$this->can_edit_event( \$user, \$id ) ) {\n                            echo '<a class=\"uc-btn uc-btn-sm uc-rsvp-count-btn\"" ) );
rt( 'E: the button does not wrap', false !== strpos( (string) file_get_contents( $root . '/public/css/portal.css' ), '.uc-rsvp-count-btn { white-space: nowrap; }' ) );

/* ======================================================================== F */
rt( 'F: the limit is 600', 600 === SFAF_Texts::MAX_MESSAGE );
rt( 'F: a long message is cut at 600, not 300', 600 === strlen( SFAF_Texts::clean_message( str_repeat( 'a', 900 ) ) ) && 450 === strlen( SFAF_Texts::clean_message( str_repeat( 'a', 450 ) ) ) );
tl_fresh( $ppl );
$x = rt_x( rt_cap( function () use ( $portal, $me ) { kit_call( 'SFAF_Portal', 'render_text_registrants', $portal, array( $me, 900 ) ); } ) );
rt( 'F: the box takes 600', 1 === $x->query( '//textarea[@name="tr_message" and @maxlength="600"]' )->length );
$GLOBALS['tw_calls'] = array();
$long = str_repeat( 'Doors open at 9:45 at the side entrance. ', 13 );
$r = SFAF_Texts::send_update( 900, $long, 1 );
$wa = null;
foreach ( $GLOBALS['tw_calls'] as $c ) { if ( isset( $c['args']['body']['ContentVariables'] ) ) { $wa = json_decode( $c['args']['body']['ContentVariables'], true ); } }
rt( 'F: a 500-character message goes whole by WhatsApp', is_array( $wa ) && false !== strpos( $wa['2'], trim( $long ) ) && strlen( trim( $long ) ) > 500 );

/* ======================================================================== G */
rt( 'G: no text, no pieces', 0 === SFAF_Texts::pieces( '' ) );
rt( 'G: 160 plain characters are one piece, 161 are two', 1 === SFAF_Texts::pieces( str_repeat( 'a', 160 ) ) && 2 === SFAF_Texts::pieces( str_repeat( 'a', 161 ) ) );
rt( 'G: 306 are two, 307 are three', 2 === SFAF_Texts::pieces( str_repeat( 'a', 306 ) ) && 3 === SFAF_Texts::pieces( str_repeat( 'a', 307 ) ) );
rt( 'G: é and ñ are in the alphabet, so they cost nothing more', 1 === SFAF_Texts::pieces( str_repeat( 'é', 100 ) . str_repeat( 'ñ', 60 ) ) );
rt( 'G: one á makes 70 the piece', 1 === SFAF_Texts::pieces( 'á' . str_repeat( 'a', 69 ) ) && 2 === SFAF_Texts::pieces( 'á' . str_repeat( 'a', 70 ) ) && 3 === SFAF_Texts::pieces( 'á' . str_repeat( 'a', 134 ) ) );
rt( 'G: an emoji counts twice', 2 === SFAF_Texts::pieces( "\u{1F600}" . str_repeat( 'a', 69 ) ) );
rt( 'G: { and € count twice', 2 === SFAF_Texts::pieces( '{' . str_repeat( 'a', 159 ) ) && 1 === SFAF_Texts::pieces( '{' . str_repeat( 'a', 158 ) ) );
$wrap = SFAF_Texts::sms_wrap( 900 );
rt( 'G: the words around the message, once per language texted', 1 === count( $wrap ) && 'SFAF: Update about Coffee & Conversation: ' === $wrap[0][0] && ' For questions, please email events@sfaf.org. Reply STOP to opt out.' === $wrap[0][1] );
$ppl_es = $ppl;
$ppl_es[] = tw_row( 5, 900, '(415) 555-0105', 'sms', 'es', 'Eva' );
tl_fresh( $ppl_es );
$wrap = SFAF_Texts::sms_wrap( 900 );
rt( 'G: and the Spanish one when somebody texted chose Spanish', 2 === count( $wrap ) && 'SFAF: Novedad sobre Coffee & Conversation: ' === $wrap[1][0] && ' Si tiene preguntas, escriba a events@sfaf.org. Responda STOP para cancelar.' === $wrap[1][1] );
rt( 'G: the wrap is the real text around the words', SFAF_Texts::sms( SFAF_Texts::words( 'update', 'en', array_merge( SFAF_Texts::facts( 900, 'en' ), array( 'message' => 'Hi.' ) ) ), 'en' ) === $wrap[0][0] . 'Hi.' . $wrap[0][1] );
$tr = rt_cap( function () use ( $portal, $me ) { kit_call( 'SFAF_Portal', 'render_text_registrants', $portal, array( $me, 900 ) ); } );
$x  = rt_x( $tr );
$cnt = $x->query( '//p[@data-uc-tr-count]' );
rt( 'G: the count is under the box, described by it', 1 === $cnt->length && 'uc-tr-count-900' === $cnt->item( 0 )->getAttribute( 'id' ) && 1 === $x->query( '//textarea[@aria-describedby="uc-tr-count-900"]' )->length
    && strpos( $tr, 'data-uc-tr-message' ) < strpos( $tr, 'data-uc-tr-count' ) );
rt( 'G: it starts at 0 of 600', '0 of 600 characters.' === trim( $x->query( '//*[@data-uc-tr-chars]' )->item( 0 )->textContent ) );
rt( 'G: and says the pieces, and that accents shorten them', 1 === $x->query( '//*[@data-uc-tr-pieces]' )->length && false !== strpos( $cnt->item( 0 )->textContent, 'Accented letters make each piece shorter.' ) );
rt( 'G: the words around it go to the script', is_array( json_decode( $cnt->item( 0 )->getAttribute( 'data-uc-tr-wrap' ), true ) ) && 2 === count( json_decode( $cnt->item( 0 )->getAttribute( 'data-uc-tr-wrap' ), true ) ) );
rt( 'G: the hint no longer repeats the limit', false === strpos( $tr, 'Up to 600 characters' ) && false !== strpos( $tr, 'It arrives by text as' ) );
tl_fresh( array( tw_row( 2, 900, '(415) 555-0102', 'whatsapp', 'es', 'Beto' ) ) );
$x = rt_x( rt_cap( function () use ( $portal, $me ) { kit_call( 'SFAF_Portal', 'render_text_registrants', $portal, array( $me, 900 ) ); } ) );
rt( 'G: nobody by text, no pieces: only the characters', 1 === $x->query( '//*[@data-uc-tr-chars]' )->length && 0 === $x->query( '//*[@data-uc-tr-pieces]' )->length );
// The script's arithmetic against pieces(), in node.
$cases = array( '', str_repeat( 'a', 160 ), str_repeat( 'a', 161 ), str_repeat( 'a', 307 ), 'á' . str_repeat( 'a', 70 ), "\u{1F600}" . str_repeat( 'a', 69 ), '{' . str_repeat( 'a', 159 ), 'Está registrado. ¿Preguntas?', str_repeat( 'é', 161 ), '€' . str_repeat( 'b', 158 ) );
$start = strpos( $JS, '    var UC_GSM = ' );
$end   = strpos( $JS, '    function initTextCount() {' );
$tmp   = sys_get_temp_dir() . '/uc-pieces-' . getmypid() . '.js';
file_put_contents( $tmp, substr( $JS, $start, $end - $start ) . "\nvar cases = " . json_encode( $cases ) . ";\nconsole.log(JSON.stringify(cases.map(ucTextPieces)));\n" );
$node = json_decode( (string) shell_exec( 'node ' . escapeshellarg( $tmp ) . ' 2>&1' ), true );
@unlink( $tmp );
rt( 'G: the script counts as pieces() does, case by case', is_array( $node ) && array_map( array( 'SFAF_Texts', 'pieces' ), $cases ) === $node );
rt( 'G: the script runs on the page', false !== strpos( $JS, "run('textCount', initTextCount);" ) );

echo "\n3.135.0: the yellow audit, what WhatsApp takes, the language beside each registrant, the RSVP button, 600 characters and the count\n" . str_repeat( '=', 72 ) . "\n";
echo "release-31350: $ran checks\n";
if ( $fails ) { foreach ( $fails as $f ) { echo "FAIL  $f\n"; } exit( 1 ); }
echo "every check passed.\n";
exit( 0 );
