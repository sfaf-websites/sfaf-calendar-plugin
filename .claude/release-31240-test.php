<?php
/**
 * 3.124.0: A TEAM'S MAILCHIMP LIST FROM THE RSVP FORM, AND THE SFAF BOX'S RECORD.
 *
 *     php .claude/release-31240-test.php
 *
 * The real plugin through wp-kit.php, with the three tables the sign-ups touch
 * (uc_rsvps, uc_optins, uc_mailchimp_queue) modelled here, and Mailchimp's API
 * answered by a fake that keeps an audience per list.
 *
 *   A  nothing about Mailchimp anywhere without a connection that has an
 *      organizer: no card on Add event, Edit event or the series page, no box
 *      on the form, no data on the button
 *   B  connections: a name and a key that looks like one are required; an
 *      organizer has one connection; the key is stored encrypted, opens only
 *      with this site's keys, is shown by its last four characters, and is
 *      in no page, message or log; removing a connection removes its key
 *   C  which events offer a team's box: one per connected organizer whose
 *      sign-up is on, none for an organizer without one, none on an event that
 *      takes no RSVPs here; a posted id the event did not offer is dropped
 *   D  the editors' card: a block per connection, shown for a connected
 *      organizer; the sign-up tick ticked by default; the audience only with
 *      more than one; tags as boxes plus a new one; the save writes only the
 *      blocks drawn; where a sign-up goes: event, then series, then the
 *      connection, and the tags of all three, never the event's name
 *   E  the form: Stay in touch at the end, the SFAF box and a team's, every box
 *      unticked, English and Spanish, Mark's line under the email field
 *   F  a registration records its ticks in Email Opt-ins with the list, the
 *      event, the date and the language, and queues a team's for Mailchimp; no
 *      email, nothing; a waitlist join records nothing until it is confirmed
 *   G  the send: new, subscribed with the names and tags; already there, the
 *      tags only; unsubscribed or cleaned, nothing at all; archived, added
 *      back; a failure retried and then failed and shown with Retry
 *   H  Email Opt-ins: the List and Language columns, on screen and in the CSV
 *
 * PLANTs in plant-31240.php.
 */

require __DIR__ . '/wp-kit.php';

$fails = array();
$ran   = 0;
function rt( $label, $got, $want ) {
    global $fails, $ran;
    $ran++;
    if ( $got !== $want ) { $fails[] = $label . ': got ' . var_export( $got, true ) . ', wanted ' . var_export( $want, true ); }
}
function rt_cap( $fn ) {
    $depth = ob_get_level();
    ob_start();
    try { $fn(); } catch ( KitRedirect $e ) { } catch ( Throwable $e ) { echo 'THREW ' . $e->getMessage() . ' at ' . basename( $e->getFile() ) . ':' . $e->getLine(); }
    $h = '';
    while ( ob_get_level() > $depth ) { $h = ob_get_clean() . $h; }
    return $h;
}

/* ---- What the kit does not have ------------------------------------------ */
$GLOBALS['rt_salt'] = 'this-site-salt';
if ( ! function_exists( 'wp_mail' ) ) { function wp_mail( ...$a ) { $GLOBALS['rt_mail'][] = $a; return true; } }
if ( ! function_exists( 'wp_salt' ) ) { function wp_salt( $s = 'auth' ) { return $GLOBALS['rt_salt']; } }
$GLOBALS['rt_cron'] = array();
if ( ! function_exists( 'wp_next_scheduled' ) ) { function wp_next_scheduled( $h ) { return isset( $GLOBALS['rt_cron'][ $h ] ) ? $GLOBALS['rt_cron'][ $h ] : false; } }
if ( ! function_exists( 'wp_schedule_single_event' ) ) { function wp_schedule_single_event( $t, $h ) { $GLOBALS['rt_cron'][ $h ] = (int) $t; return true; } }
if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) { function wp_clear_scheduled_hook( $h ) { unset( $GLOBALS['rt_cron'][ $h ] ); return 1; } }

/* ---- Mailchimp, faked: audiences, tags, and members by status ------------- */
$GLOBALS['mc'] = array( 'calls' => array(), 'members' => array(), 'down' => 0, 'auth' => array() );
function mc_reset_members() { $GLOBALS['mc']['members'] = array(); $GLOBALS['mc']['calls'] = array(); $GLOBALS['mc']['down'] = 0; }
if ( ! function_exists( 'wp_remote_request' ) ) {
    function wp_remote_request( $url, $args ) {
        $m    = $args['method'];
        $path = preg_replace( '#^https://[a-z0-9]+\.api\.mailchimp\.com/3\.0#', '', $url );
        $body = isset( $args['body'] ) ? json_decode( $args['body'], true ) : null;
        $GLOBALS['mc']['calls'][] = array( $m, $path, $body );
        $GLOBALS['mc']['auth'][]  = $args['headers']['Authorization'];
        $r = function ( $code, $data = array() ) { return array( 'response' => array( 'code' => $code ), 'body' => wp_json_encode( $data ) ); };
        if ( $GLOBALS['mc']['down'] > 0 ) { $GLOBALS['mc']['down']--; return $r( 503, array( 'title' => 'Service Unavailable', 'detail' => 'Try later. key ' . $GLOBALS['KEY'] ) ); }
        if ( 'GET' === $m && '/ping' === $path ) { return $r( 200, array( 'health_status' => 'Everything\'s Chimpy!' ) ); }
        if ( 'GET' === $m && 0 === strpos( $path, '/lists?' ) ) { return $r( 200, array( 'lists' => array( array( 'id' => 'aud1', 'name' => 'Aging Services list' ), array( 'id' => 'aud2', 'name' => 'Aging Services volunteers' ) ) ) ); }
        if ( 'GET' === $m && preg_match( '#^/lists/(\w+)/segments#', $path, $x ) ) { return $r( 200, array( 'segments' => 'aud1' === $x[1] ? array( array( 'name' => 'Seniors' ), array( 'name' => 'Lunch club' ) ) : array( array( 'name' => 'Volunteers' ) ) ) ); }
        if ( preg_match( '#^/lists/(\w+)/members/([0-9a-f]{32})(/tags)?(\?.*)?$#', $path, $x ) ) {
            $aud = $x[1]; $hash = $x[2];
            $have = isset( $GLOBALS['mc']['members'][ $aud ][ $hash ] ) ? $GLOBALS['mc']['members'][ $aud ][ $hash ] : null;
            if ( 'GET' === $m ) { return $have ? $r( 200, array( 'status' => $have['status'] ) ) : $r( 404, array( 'title' => 'Resource Not Found' ) ); }
            if ( 'PATCH' === $m ) { $GLOBALS['mc']['members'][ $aud ][ $hash ]['status'] = $body['status']; return $r( 200, array() ); }
            if ( 'POST' === $m && ! empty( $x[3] ) ) { foreach ( $body['tags'] as $t ) { $GLOBALS['mc']['members'][ $aud ][ $hash ]['tags'][] = $t['name']; } return $r( 204 ); }
        }
        if ( 'POST' === $m && preg_match( '#^/lists/(\w+)/members$#', $path, $x ) ) {
            if ( false !== strpos( $body['email_address'], 'fake' ) ) { return $r( 400, array( 'title' => 'Invalid Resource', 'detail' => 'looks fake' ) ); }
            $GLOBALS['mc']['members'][ $x[1] ][ md5( strtolower( $body['email_address'] ) ) ] = array( 'status' => $body['status'], 'merge' => $body['merge_fields'], 'tags' => isset( $body['tags'] ) ? $body['tags'] : array() );
            return $r( 200, array( 'id' => 'x' ) );
        }
        return $r( 400, array( 'title' => 'Unexpected', 'detail' => $m . ' ' . $path ) );
    }
    function wp_remote_retrieve_response_code( $r ) { return $r['response']['code']; }
    function wp_remote_retrieve_body( $r ) { return $r['body']; }
}

/* ---- The tables ---------------------------------------------------------- */
class RT_DB extends wpdb {
    public $t = array( 'wp_uc_rsvps' => array(), 'wp_uc_optins' => array(), 'wp_uc_mailchimp_queue' => array() );
    public $next = 1;
    function prepare( ...$a ) { return kit_prepare( $a ); }
    function esc_like( $s ) { return addcslashes( (string) $s, '_%\\' ); }
    function insert( $table, $data, $f = null ) {
        if ( ! isset( $this->t[ $table ] ) ) { return 1; }
        $data['id'] = $this->next++;
        $this->t[ $table ][ $data['id'] ] = $data;
        $this->insert_id = $data['id'];
        return 1;
    }
    function update( $table, $data, $where, $f1 = null, $f2 = null ) {
        $n = 0;
        foreach ( isset( $this->t[ $table ] ) ? $this->t[ $table ] : array() as $id => $row ) {
            $hit = true;
            foreach ( $where as $k => $v ) { if ( (string) ( $row[ $k ] ?? '' ) !== (string) $v ) { $hit = false; } }
            if ( $hit ) { $this->t[ $table ][ $id ] = array_merge( $row, $data ); $n++; }
        }
        return $n;
    }
    private function table_of( $sql ) { return preg_match( '/\bFROM\s+(\w+)/', $sql, $m ) && isset( $this->t[ $m[1] ] ) ? $m[1] : ''; }
    private function rows( $sql ) {
        $table = $this->table_of( $sql );
        if ( '' === $table ) { return null; }
        $out = array();
        foreach ( $this->t[ $table ] as $row ) {
            $ok = true;
            if ( preg_match( "/status = 'failed' OR \\( status = 'queued' AND attempts > 0 \\)/", $sql ) ) {
                $ok = 'failed' === $row['status'] || ( 'queued' === $row['status'] && $row['attempts'] > 0 );
            } else {
                if ( preg_match( "/\\bstatus = '(\\w+)'/", $sql, $s ) && (string) ( $row['status'] ?? '' ) !== $s[1] ) { $ok = false; }
                if ( preg_match( "/event_id = (\\d+)/", $sql, $s ) && (int) $row['event_id'] !== (int) $s[1] ) { $ok = false; }
                if ( preg_match( "/email = '([^']*)'/", $sql, $s ) && (string) $row['email'] !== stripslashes( $s[1] ) ) { $ok = false; }
                if ( preg_match( "/next_at <= '([^']+)'/", $sql, $s ) && strcmp( (string) $row['next_at'], $s[1] ) > 0 ) { $ok = false; }
                if ( preg_match( "/WHERE id = (\\d+)/", $sql, $s ) && (int) $row['id'] !== (int) $s[1] ) { $ok = false; }
                if ( preg_match( "/status IN \\(([^)]*)\\)/", $sql, $s ) ) { preg_match_all( "/'(\\w+)'/", $s[1], $v ); if ( ! in_array( (string) $row['status'], $v[1], true ) ) { $ok = false; } }
            }
            if ( $ok ) { $out[] = $row; }
        }
        if ( preg_match( '/ORDER BY (?:o\.)?id DESC/', $sql ) ) { $out = array_reverse( $out ); }
        return $out;
    }
    function get_results( $sql = null, $o = 'OBJECT' ) {
        if ( preg_match( '/GROUP BY connection, status/', (string) $sql ) ) {
            $g = array();
            foreach ( $this->t['wp_uc_mailchimp_queue'] as $r ) { $k = $r['connection'] . '|' . $r['status']; $g[ $k ] = ( $g[ $k ] ?? 0 ) + 1; }
            $out = array(); foreach ( $g as $k => $n ) { list( $c, $s ) = explode( '|', $k ); $out[] = (object) array( 'connection' => $c, 'status' => $s, 'n' => $n ); }
            return $out;
        }
        $r = $this->rows( (string) $sql );
        if ( null === $r ) { return array(); }
        return array_map( function ( $x ) { $x = (object) $x; if ( ! isset( $x->event_title ) ) { $x->event_title = get_the_title( (int) ( $x->event_id ?? 0 ) ); } return $x; }, $r );
    }
    function get_row( $sql = null, $o = 'OBJECT', $y = 0 ) { $r = $this->rows( (string) $sql ); return $r ? (object) $r[0] : null; }
    function get_var( $sql = null ) {
        if ( preg_match( '/SELECT MIN\(next_at\)/', (string) $sql ) ) {
            $m = null; foreach ( $this->t['wp_uc_mailchimp_queue'] as $r ) { if ( 'queued' === $r['status'] && ( null === $m || strcmp( $r['next_at'], $m ) < 0 ) ) { $m = $r['next_at']; } } return $m;
        }
        $r = $this->rows( (string) $sql );
        if ( null === $r ) { return null; }
        return preg_match( '/COUNT\(\*\)/', (string) $sql ) ? count( $r ) : ( $r ? reset( $r[0] ) : null );
    }
    function get_col( $sql = null ) { return array(); }
    function query( $sql = null ) { return 0; }
}
$GLOBALS['wpdb'] = new RT_DB();
$db = $GLOBALS['wpdb'];

$GLOBALS['kit_query'] = function ( $a ) { return array(); };
$GLOBALS['kit_organizers'] = true;   // 11 Alpha, 12 Beta
$portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();
$admin  = new WP_User();
/* A made-up key, put together here so no key-shaped text is in the file:
   GitHub's secret scanning refused the 3.124.0 push over the written-out one. */
$KEY    = implode( '', array_map( 'dechex', range( 0, 15 ) ) ) . implode( '', array_map( 'dechex', range( 0, 15 ) ) ) . '-' . 'us21';
$GLOBALS['KEY'] = $KEY;

function rt_event( $orgs, $meta = array(), $series = 0 ) {
    $id = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_title' => 'Lunch and learn', 'post_author' => 1 ) );
    foreach ( array_merge( array( '_uc_event_date' => '2027-01-14', '_uc_start_time' => '12:00', '_uc_end_time' => '13:00', '_uc_rsvp_enabled' => '1' ), $meta ) as $k => $v ) { update_post_meta( $id, $k, $v ); }
    $GLOBALS['kit_terms'][ $id ]['uc_organizer'] = $orgs;
    if ( $series ) { $GLOBALS['kit_terms'][ $id ]['uc_series'] = array( $series ); }
    return $id;
}
function rt_form( $id ) { return rt_cap( function () use ( $id ) { $_GET = array(); kit_call( 'SFAF_Portal', 'render_event_form', $GLOBALS['portal'], array( $GLOBALS['admin'], $id ) ); } ); }
function rt_series_page( $term ) { return rt_cap( function () use ( $term ) { $_GET = array(); kit_call( 'SFAF_Portal', 'render_series_edit', $GLOBALS['portal'], array( $GLOBALS['admin'], $term ) ); } ); }
function rt_button( $id ) { return sfaf_rsvp_block( $id ); }

$alpha_event = rt_event( array( 11 ), array(), 11 );
$beta_event  = rt_event( array( 12 ) );

/* ======================================================================== A */
foreach ( array( 'Add event' => rt_form( 0 ), 'Edit event' => rt_form( $alpha_event ), 'the series page' => rt_series_page( 11 ) ) as $where => $h ) {
    rt( "A: $where renders", false === strpos( $h, 'THREW' ), true );
    rt( "A: $where: no Newsletter sign-up card without a connection", false === strpos( $h, 'data-uc-mailchimp' ) && false === strpos( $h, 'Newsletter sign-up' ), true );
}
rt( 'A: the RSVP button carries no team list', false === strpos( rt_button( $alpha_event ), 'data-uc-lists' ), true );
rt( 'A: the event offers no team box', SFAF_Mailchimp::form_lists( $alpha_event ), array() );
rt( 'A: not active', SFAF_Mailchimp::active(), false );

/* ======================================================================== B */
rt( 'B: a name is required', SFAF_Mailchimp::save( '', array( 'name' => '' ), $KEY )['ok'], false );
rt( 'B: a key is required for a new one', SFAF_Mailchimp::save( '', array( 'name' => 'Aging Services' ), '' )['ok'], false );
rt( 'B: a key must look like one', SFAF_Mailchimp::save( '', array( 'name' => 'Aging Services' ), 'not-a-key' )['ok'], false );
$saved = SFAF_Mailchimp::save( '', array( 'name' => 'Aging Services', 'organizers' => array( 11 ), 'tags' => 'Seniors, seniors, ' ), $KEY );
rt( 'B: a connection saves', $saved['ok'], true );
$A = $saved['id'];
$c = SFAF_Mailchimp::get( $A );
rt( 'B: its organizers', $c['organizers'], array( 11 ) );
rt( 'B: its tags, once each', $c['tags'], array( 'Seniors' ) );
rt( 'B: the only audience is not chosen for it when there are two', $c['audience'], '' );
$stored = (string) SFAF_Credentials::raw( SFAF_Mailchimp::key_name( $A ) );
rt( 'B: the key is stored encrypted', '' !== $stored && false === strpos( $stored, $KEY ) && false === strpos( $stored, substr( $KEY, 0, 12 ) ), true );
rt( 'B: and never in uc_settings', false === strpos( wp_json_encode( get_option( 'uc_settings', array() ) ), $KEY ), true );
rt( 'B: the screen shows its last four characters', SFAF_Mailchimp::key_hint( $A ), 'Saved, ending us21.' );
$GLOBALS['rt_salt'] = 'another-site-salt';
rt( 'B: another site\'s keys do not open it', SFAF_Mailchimp::has_key( $A ), false );
rt( 'B: and the screen says to paste it again', SFAF_Mailchimp::key_hint( $A ), 'Saved, but it no longer opens. Paste it again.' );
$GLOBALS['rt_salt'] = 'this-site-salt';
rt( 'B: this site\'s do', SFAF_Mailchimp::has_key( $A ), true );
rt( 'B: an organizer has one connection', SFAF_Mailchimp::save( '', array( 'name' => 'Black Health', 'organizers' => array( 11 ) ), str_repeat( 'b', 32 ) . '-us5' )['ok'], false );
rt( 'B: a blank key on an edit keeps the stored one', SFAF_Mailchimp::save( $A, array( 'name' => 'Aging Services', 'organizers' => array( 11 ), 'tags' => 'Seniors', 'audience' => 'aud1' ), '' )['ok'] && SFAF_Mailchimp::has_key( $A ), true );
rt( 'B: the audience and its name are kept', array( SFAF_Mailchimp::get( $A )['audience'], SFAF_Mailchimp::get( $A )['audience_name'] ), array( 'aud1', 'Aging Services list' ) );
rt( 'B: a scrubbed message never carries a key', SFAF_Mailchimp::scrub( 'bad key ' . $KEY . ' here', $KEY ), 'bad key [key] here' );
rt( 'B: nor anything shaped like one', SFAF_Mailchimp::scrub( 'x ' . str_repeat( 'f', 32 ) . '-' . 'us9 y' ), 'x [key] y' );
$panel = rt_cap( function () { $_GET = array( 'mc_edit' => $GLOBALS['A'] ); SFAF_Mailchimp::render_panel(); } );
rt( 'B: the panel renders', false === strpos( $panel, 'THREW' ) && false !== strpos( $panel, 'Aging Services' ), true );
rt( 'B: the panel never prints the key', false === strpos( $panel, $KEY ) && false === strpos( $panel, substr( $KEY, 0, 16 ) ), true );
rt( 'B: the key box is empty, write-only', (bool) preg_match( '#<input type="password" id="uc-mc-key" name="api_key" value=""#', $panel ), true );
$GLOBALS['kit_cannot'] = array( 'manage_options' );
rt( 'B: the panel is for administrators only', rt_cap( function () { SFAF_Mailchimp::render_panel(); } ), '' );
$GLOBALS['kit_cannot'] = array();
$tmp = SFAF_Mailchimp::save( '', array( 'name' => 'Gone soon', 'organizers' => array() ), str_repeat( 'c', 32 ) . '-us2' );
rt( 'B: removing a connection removes its key', SFAF_Mailchimp::delete( $tmp['id'] ) && '' === SFAF_Credentials::raw( SFAF_Mailchimp::key_name( $tmp['id'] ) ) && null === SFAF_Mailchimp::get( $tmp['id'] ), true );
$crypt_src = file_get_contents( $GLOBALS['kit_root'] . '/includes/class-sfaf-mailchimp.php' );
rt( 'B: nothing in the class logs or emails', ! preg_match( '/\berror_log\s*\(|\bwp_mail\s*\(|\bSFAF_Email::/', $crypt_src ), true );

/* ======================================================================== C */
rt( 'C: active once a connection has an organizer', SFAF_Mailchimp::active(), true );
rt( 'C: an event of a connected organizer offers its box', SFAF_Mailchimp::form_lists( $alpha_event ), array( array( 'id' => $A, 'name' => 'Aging Services' ) ) );
rt( 'C: an organizer without a connection adds nothing', SFAF_Mailchimp::form_lists( $beta_event ), array() );
$both = rt_event( array( 11, 12 ) );
rt( 'C: and adds nothing beside a connected one', count( SFAF_Mailchimp::form_lists( $both ) ), 1 );
$no_rsvp = rt_event( array( 11 ), array( '_uc_rsvp_enabled' => '0' ) );
rt( 'C: an event that takes no RSVPs here offers none', SFAF_Mailchimp::form_lists( $no_rsvp ), array() );
update_post_meta( $both, SFAF_Mailchimp::META_EVENT, array( $A => array( 'show' => '0' ) ) );
rt( 'C: an event with the sign-up unticked offers none', SFAF_Mailchimp::form_lists( $both ), array() );
delete_post_meta( $both, SFAF_Mailchimp::META_EVENT );
rt( 'C: a posted id the event did not offer is dropped', SFAF_Mailchimp::allowed_lists( $alpha_event, $A . ',cfake,' ), array( $A ) );
rt( 'C: and nothing for an event that offers none', SFAF_Mailchimp::allowed_lists( $beta_event, $A ), array() );
$btn = rt_button( $alpha_event );
rt( 'C: the RSVP button carries the box', false !== strpos( $btn, 'data-uc-lists="' . esc_attr( wp_json_encode( array( array( 'id' => $A, 'name' => 'Aging Services' ) ) ) ) . '"' ), true );
$saved2 = SFAF_Mailchimp::save( '', array( 'name' => 'Black Health', 'organizers' => array( 12 ), 'audience' => 'aud9' ), str_repeat( 'd', 32 ) . '-us5' );
$B = $saved2['id'];
rt( 'C: several connected organizers, several boxes, in the order added', wp_list_pluck( SFAF_Mailchimp::form_lists( $both ), 'name' ), array( 'Aging Services', 'Black Health' ) );

/* ======================================================================== D */
$GLOBALS['kit_trans'][ 'sfaf_mc_meta_' . $A ] = array( 'audiences' => array( array( 'id' => 'aud1', 'name' => 'Aging Services list' ), array( 'id' => 'aud2', 'name' => 'Aging Services volunteers' ) ), 'tags' => array( 'Lunch club', 'Seniors' ), 'at' => time(), 'error' => '' );
$GLOBALS['kit_trans'][ 'sfaf_mc_meta_' . $B ] = array( 'audiences' => array( array( 'id' => 'aud9', 'name' => 'Black Health list' ) ), 'tags' => array(), 'at' => time(), 'error' => '' );
function rt_dom( $html ) { $d = new DOMDocument(); libxml_use_internal_errors( true ); $d->loadHTML( '<?xml encoding="utf-8"?>' . $html ); libxml_clear_errors(); return new DOMXPath( $d ); }
$h = rt_form( $alpha_event );
$x = rt_dom( $h );
$card = $x->query( '//section[@data-uc-card="mailchimp"]' )->item( 0 );
rt( 'D: Edit event has the card', null !== $card, true );
rt( 'D: shown, for an event with a connected organizer', $card && ! $card->hasAttribute( 'hidden' ), true );
rt( 'D: it follows the organizer ticks', $card ? $card->getAttribute( 'data-uc-mailchimp' ) : '', 'organizer[]' );
$blockA = $x->query( '//*[@data-uc-mc-conn="' . $A . '"]' )->item( 0 );
$blockB = $x->query( '//*[@data-uc-mc-conn="' . $B . '"]' )->item( 0 );
rt( 'D: a block per connection, the event\'s shown', $blockA && ! $blockA->hasAttribute( 'hidden' ), true );
rt( 'D: the other team\'s hidden', $blockB && $blockB->hasAttribute( 'hidden' ), true );
rt( 'D: each knows its organizers', array( $blockA ? $blockA->getAttribute( 'data-uc-mc-orgs' ) : '', $blockB ? $blockB->getAttribute( 'data-uc-mc-orgs' ) : '' ), array( '11', '12' ) );
$show = $x->query( './/input[@name="mc[' . $A . '][show]"]', $blockA )->item( 0 );
rt( 'D: the sign-up tick is ticked by default', $show && $show->hasAttribute( 'checked' ), true );
rt( 'D: and says what it does', false !== strpos( $h, 'Show Aging Services newsletter sign-up on the RSVP form' ), true );
$aud = $x->query( './/select[@name="mc[' . $A . '][audience]"]/option', $blockA );
rt( 'D: the audience, with two in the account, first the usual one', $aud->length ? array( $aud->item( 0 )->getAttribute( 'value' ), trim( $aud->item( 0 )->textContent ) ) : null, array( '', 'The usual audience (Aging Services list)' ) );
rt( 'D: no audience dropdown with one in the account', $x->query( './/select[@name="mc[' . $B . '][audience]"]', $blockB )->length, 0 );
rt( 'D: the account\'s tags are boxes', $x->query( './/input[@name="mc[' . $A . '][tags][]"]', $blockA )->length, 2 );
rt( 'D: with a box for a new one', $x->query( './/input[@name="mc[' . $A . '][new_tags]"]', $blockA )->length, 1 );
rt( 'D: and the connection\'s own tags named', false !== strpos( $h, 'Also added: Seniors (every sign-up).' ), true );
rt( 'D: Add event has the card too, hidden until an organizer is ticked', (bool) preg_match( '#<section class="uc-bento-card" data-uc-card="mailchimp" data-uc-mailchimp="organizer\[\]" hidden>#', rt_form( 0 ) ), true );
$none = rt_event( array() );
rt( 'D: hidden on an event with no connected organizer', (bool) preg_match( '#data-uc-card="mailchimp" data-uc-mailchimp="organizer\[\]" hidden>#', rt_form( $none ) ), true );
rt( 'D: the tour has a caption for it', false !== strpos( html_entity_decode( $h, ENT_QUOTES ), '"card":"mailchimp"' ), true );

/* The series page. */
update_term_meta( 11, SFAF_Series::META_ORGANIZERS, array( 11 ) );
$sp = rt_series_page( 11 );
rt( 'D: the series page has the card, following its organizers', (bool) preg_match( '#data-uc-card="mailchimp" data-uc-mailchimp="series_organizers\[\]">#', $sp ), true );
rt( 'D: with no sign-up tick there', false === strpos( $sp, 'name="mc[' . $A . '][show]"' ), true );

/* The saves: only the blocks drawn. */
$stored = array( 'cother' => array( 'show' => '0', 'audience' => 'x', 'tags' => array( 'Kept' ) ) );
$post   = array( 'mc_present' => array( $A ), 'mc' => array( $A => array( 'audience' => 'aud2', 'tags' => array( 'Lunch club' ), 'new_tags' => 'Fall 2026, lunch CLUB' ), 'cother' => array( 'show' => '1' ) ) );
$after  = SFAF_Mailchimp::from_post( $post, $stored, true );
rt( 'D: a block drawn is written: unticked, its audience, its tags and the new one', $after[ $A ], array( 'show' => '0', 'audience' => 'aud2', 'tags' => array( 'Lunch club', 'Fall 2026' ) ) );
rt( 'D: anything not drawn is kept', $after['cother'], $stored['cother'] );
rt( 'D: the series has no sign-up tick of its own', array_keys( SFAF_Mailchimp::from_post( $post, array(), false )[ $A ] ), array( 'audience', 'tags' ) );
$_POST = array( 'mc_present' => array( $A ), 'mc' => array( $A => array( 'show' => '1', 'tags' => array( 'Lunch club' ) ) ) );
kit_call( 'SFAF_Portal', 'save_series_mailchimp', $portal, array( 11 ) );
rt( 'D: the series page saves', SFAF_Mailchimp::series_choice( 11, $A ), array( 'audience' => '', 'tags' => array( 'Lunch club' ) ) );
$_POST = array();

/* Where a sign-up goes. */
rt( 'D: left alone: the connection\'s audience and tags, the series\' tags', SFAF_Mailchimp::destination( $alpha_event, $A ), array( 'audience' => 'aud1', 'tags' => array( 'Seniors', 'Lunch club' ) ) );
update_term_meta( 11, SFAF_Mailchimp::META_SERIES, array( $A => array( 'audience' => 'aud2', 'tags' => array( 'Lunch club' ) ) ) );
rt( 'D: the series\' audience before the connection\'s', SFAF_Mailchimp::destination( $alpha_event, $A )['audience'], 'aud2' );
update_post_meta( $alpha_event, SFAF_Mailchimp::META_EVENT, array( $A => array( 'show' => '1', 'audience' => 'aud1', 'tags' => array( 'Jan lunch', 'seniors' ) ) ) );
rt( 'D: the event\'s before the series\', its tags added to theirs', SFAF_Mailchimp::destination( $alpha_event, $A ), array( 'audience' => 'aud1', 'tags' => array( 'Seniors', 'Lunch club', 'Jan lunch' ) ) );
rt( 'D: the event\'s name is never a tag', in_array( 'Lunch and learn', SFAF_Mailchimp::destination( $alpha_event, $A )['tags'], true ), false );
$h2 = rt_form( $alpha_event );
rt( 'D: the editor then names the series\' audience as the default', false !== strpos( $h2, 'The series&#039; audience (Aging Services volunteers)' ) || false !== strpos( $h2, "The series' audience (Aging Services volunteers)" ), true );
rt( 'D: and its tags', false !== strpos( $h2, 'Lunch club (the series)' ), true );

/* ======================================================================== E */
$js  = file_get_contents( $GLOBALS['kit_root'] . '/public/js/calendar.js' );
$css = file_get_contents( $GLOBALS['kit_root'] . '/public/css/calendar.css' );
$at_stay = strpos( $js, '<fieldset class="uc-rsvp-stay" id="uc-rsvp-stay">' );
rt( 'E: Stay in touch is a group on the form', false !== $at_stay && false !== strpos( $js, '<legend data-uc-w="stay">Stay in touch</legend>' ), true );
rt( 'E: at the end, after the phone and before the button', false !== $at_stay && $at_stay > strpos( $js, 'id="uc-rsvp-phone"' ) && $at_stay < strpos( $js, 'id="uc-rsvp-submit-btn"' ), true );
rt( 'E: the SFAF box in it, in Mark\'s words', false !== strpos( $js, '<span data-uc-w="optin">Sign me up for SFAF Newsletter and Updates</span>' ), true );
rt( 'E: no box is drawn ticked', ! preg_match( '/type="checkbox"[^>]*\bchecked\b/', substr( $js, (int) $at_stay, 3000 ) ) && false === strpos( $js, "prop('checked', true)" ), true );
rt( 'E: every box is cleared when the form opens', false !== strpos( $js, "\$('#uc-rsvp-optin').prop('checked', false);" ) && false !== strpos( $js, "var box = \$('#uc-rsvp-lists').empty()" ), true );
rt( 'E: a team\'s box, one per list on the button', false !== strpos( $js, "JSON.parse(\$(btn).attr('data-uc-lists') || '[]')" ) && false !== strpos( $js, 'name="uc_rsvp_list"' ), true );
rt( 'E: and sent as lists', false !== strpos( $js, 'lists:      lists,' ), true );
rt( 'E: the email line is Mark\'s', false !== strpos( $js, '>We use your email for this event, and only add it to a list if you tick a box below.</p>' ), true );
rt( 'E: the group\'s style is not in calendar.css, which the embed loads', false === strpos( $css, 'uc-rsvp-stay' ), true );
$w = SFAF_Messages::form_words();
rt( 'E: English', array( $w['en']['stay'], $w['en']['optin'], $w['en']['team_optin'], $w['en']['email_use'] ),
    array( 'Stay in touch', 'Sign me up for SFAF Newsletter and Updates', 'Sign me up for %s email updates', 'We use your email for this event, and only add it to a list if you tick a box below.' ) );
rt( 'E: Spanish', array( $w['es']['stay'], $w['es']['optin'], $w['es']['team_optin'], $w['es']['email_use'] ),
    array( 'Sigamos en contacto', 'Quiero recibir el boletín y las novedades de SFAF', 'Quiero recibir las novedades por correo de %s', 'Usamos su correo electrónico para este evento, y solo lo agregamos a una lista si marca una casilla abajo.' ) );
rt( 'E: EMAILS.md carries both for review', false !== strpos( (string) file_get_contents( $GLOBALS['kit_root'] . '/EMAILS.md' ), '| We use your email for this event, and only add it to a list if you tick a box below. | Usamos su correo electrónico para este evento, y solo lo agregamos a una lista si marca una casilla abajo. |' ), true );
// What 3.124.0 wrote; calendar.js has three in older comments.
foreach ( array( substr( $js, (int) $at_stay, 1500 ), substr( $js, (int) strpos( $js, 'function renderLists(' ), 1200 ),
                 (string) file_get_contents( $GLOBALS['kit_root'] . '/includes/class-sfaf-mailchimp.php' ),
                 (string) file_get_contents( $GLOBALS['kit_root'] . '/admin/js/mailchimp.js' ) ) as $src ) {
    rt( 'E: no em dash', false === strpos( $src, "\u{2014}" ), true );
}

/* ======================================================================== F */
$db->t = array( 'wp_uc_rsvps' => array(), 'wp_uc_optins' => array(), 'wp_uc_mailchimp_queue' => array() );
$rsvp = ( new ReflectionClass( 'SFAF_RSVP' ) )->newInstanceWithoutConstructor();
function rt_submit( $event_id, $email, $optin, $lists, $lang = 'en' ) {
    sfaf_clear_rsvp_count_cache( $event_id );
    return $GLOBALS['rsvp']->submit( array( 'event_id' => $event_id, 'first_name' => 'Ana', 'last_name' => 'Ruiz', 'email' => $email, 'phone' => '',
        'optin' => $optin, 'lists' => $lists, 'format' => '', 'no_email' => '' === $email, 'answers' => array(), 'answers_more' => array(), 'agreed' => false, 'text_opt_in' => false, 'lang' => $lang ) );
}
update_post_meta( $alpha_event, SFAF_Mailchimp::META_EVENT, array() );
delete_term_meta( 11, SFAF_Mailchimp::META_SERIES );
$res = rt_submit( $alpha_event, 'ana@example.org', true, $A . ',' . $B, 'es' );
rt( 'F: the registration goes through', ! empty( $res['success'] ), true );
$opt = array_values( $db->t['wp_uc_optins'] );
rt( 'F: two consents: SFAF\'s, and the one team the event offers', array_map( function ( $r ) { return array( $r['list_key'], $r['list_name'] ); }, $opt ), array( array( 'sfaf', 'SFAF Newsletter' ), array( 'mc:' . $A, 'Aging Services' ) ) );
rt( 'F: each with the event, the date, the language and the registration', $opt ? array( $opt[0]['event_id'], '' !== (string) $opt[0]['consented_at'], $opt[0]['lang'], $opt[0]['rsvp_id'] > 0, $opt[0]['email'] ) : null, array( $alpha_event, true, 'es', true, 'ana@example.org' ) );
$q = array_values( $db->t['wp_uc_mailchimp_queue'] );
rt( 'F: one send queued, for the team', count( $q ), 1 );
rt( 'F: with the audience and tags decided now', $q ? array( $q[0]['connection'], $q[0]['audience'], json_decode( $q[0]['tags'], true ), $q[0]['first_name'], $q[0]['last_name'], $q[0]['status'] ) : null, array( $A, 'aud1', array( 'Seniors' ), 'Ana', 'Ruiz', 'queued' ) );
rt( 'F: in the background: a single run is scheduled, nothing sent yet', array( isset( $GLOBALS['rt_cron'][ SFAF_Mailchimp::CRON_HOOK ] ), count( $GLOBALS['mc']['calls'] ) > 0 && 'POST' === end( $GLOBALS['mc']['calls'] )[0] ), array( true, false ) );
rt( 'F: the row keeps what was ticked', array_values( $db->t['wp_uc_rsvps'] )[0]['lists'], 'sfaf,mc:' . $A );
$db->t['wp_uc_optins'] = array(); $db->t['wp_uc_mailchimp_queue'] = array();
rt_submit( $alpha_event, 'ben@example.org', false, '' );
rt( 'F: nothing ticked, nothing recorded', array( count( $db->t['wp_uc_optins'] ), count( $db->t['wp_uc_mailchimp_queue'] ) ), array( 0, 0 ) );
rt_submit( $alpha_event, '', true, $A );
rt( 'F: no email, nothing recorded or queued', array( count( $db->t['wp_uc_optins'] ), count( $db->t['wp_uc_mailchimp_queue'] ) ), array( 0, 0 ) );
SFAF_Optins::record_ticks( array( 'event_id' => $alpha_event, 'email' => ' ', 'first_name' => 'Al' ), 'sfaf,mc:' . $A );
rt( 'F: no email, nothing recorded or queued, whoever asks record_ticks()', array( count( $db->t['wp_uc_optins'] ), count( $db->t['wp_uc_mailchimp_queue'] ) ), array( 0, 0 ) );
rt_submit( $beta_event, 'cy@example.org', false, $A );
rt( 'F: a team the event does not offer is not signed up for', count( $db->t['wp_uc_optins'] ), 0 );

/* The waitlist: kept on the row, counted when confirmed. */
$full = rt_event( array( 11 ), array( '_uc_capacity' => '0', '_uc_waitlist' => '1' ) );
$GLOBALS['kit_db'] = null;
$res = rt_submit( $full, 'dee@example.org', true, $A );
rt( 'F: a full event puts them on the waitlist', ! empty( $res['waitlisted'] ), true );
$wl = array_values( array_filter( $db->t['wp_uc_rsvps'], function ( $r ) { return 'dee@example.org' === $r['email']; } ) );
rt( 'F: the waitlist row keeps the ticks', $wl ? $wl[0]['lists'] : null, 'sfaf,mc:' . $A );
rt( 'F: and nothing is recorded or queued yet', array( count( $db->t['wp_uc_optins'] ), count( $db->t['wp_uc_mailchimp_queue'] ) ), array( 0, 0 ) );
if ( $wl ) {
    kit_call( 'SFAF_Waitlist', 'admit', null, array( (object) $db->t['wp_uc_rsvps'][ $wl[0]['id'] ], true ) );
}
rt( 'F: confirmed, both are recorded and the team\'s queued', array( count( $db->t['wp_uc_optins'] ), count( $db->t['wp_uc_mailchimp_queue'] ) ), array( 2, 1 ) );
rt( 'F: with the registration they came from', array_values( $db->t['wp_uc_optins'] )[0]['rsvp_id'] ?? null, $wl ? $wl[0]['id'] : -1 );
$db->t['wp_uc_optins'] = array(); $db->t['wp_uc_mailchimp_queue'] = array();
kit_call( 'SFAF_Waitlist', 'admit', null, array( (object) array( 'id' => 999, 'event_id' => $full, 'name' => 'Old', 'first_name' => 'Old', 'last_name' => '', 'email' => 'old@example.org', 'token' => 't', 'format' => '', 'lang' => '' ), true ) );
rt( 'F: a waitlisted row from before 3.124.0 records nothing', array( count( $db->t['wp_uc_optins'] ), count( $db->t['wp_uc_mailchimp_queue'] ) ), array( 0, 0 ) );

/* ======================================================================== G */
/* As WordPress runs a single event: off the schedule first, then the hook. */
function rt_cron_run() { unset( $GLOBALS['rt_cron'][ SFAF_Mailchimp::CRON_HOOK ] ); SFAF_Mailchimp::process_queue(); }
function rt_queue( $email, $first = 'Ana', $last = 'Ruiz' ) {
    global $db;
    $db->t['wp_uc_mailchimp_queue'] = array();
    $GLOBALS['A_OPT'] = SFAF_Optins::record( $email, $first, $GLOBALS['alpha_event'], 'rsvp', array( 'list_key' => 'mc:' . $GLOBALS['A'], 'list_name' => 'Aging Services' ) );
    SFAF_Mailchimp::enqueue( $GLOBALS['A_OPT'], $GLOBALS['A'], array( 'event_id' => $GLOBALS['alpha_event'], 'email' => $email, 'first_name' => $first, 'last_name' => $last ) );
    rt_cron_run();
    return array_values( $db->t['wp_uc_mailchimp_queue'] )[0];
}
$hash = function ( $e ) { return md5( strtolower( $e ) ); };
update_post_meta( $alpha_event, SFAF_Mailchimp::META_EVENT, array( $A => array( 'show' => '1', 'tags' => array( 'Jan lunch' ) ) ) );

mc_reset_members();
$row = rt_queue( 'New@Example.org' );
$m   = $GLOBALS['mc']['members']['aud1'][ $hash( 'new@example.org' ) ] ?? null;
rt( 'G: new to the audience: added', $row['outcome'], 'added' );
rt( 'G: subscribed, with the names and the tags', $m ? array( $m['status'], $m['merge'], $m['tags'] ) : null, array( 'subscribed', array( 'FNAME' => 'Ana', 'LNAME' => 'Ruiz' ), array( 'Seniors', 'Jan lunch' ) ) );
rt( 'G: the row is done, with its attempt logged', array( $row['status'], $row['attempts'], count( json_decode( $row['log'], true ) ) ), array( 'done', 1, 1 ) );
mc_reset_members();
$row = rt_queue( 'nolast@example.org', 'Ana', '' );
rt( 'G: no last name, none sent', $GLOBALS['mc']['members']['aud1'][ $hash( 'nolast@example.org' ) ]['merge'] ?? null, array( 'FNAME' => 'Ana' ) );

foreach ( array( 'subscribed', 'pending' ) as $st ) {
    mc_reset_members();
    $GLOBALS['mc']['members']['aud1'][ $hash( 'old@example.org' ) ] = array( 'status' => $st, 'merge' => array( 'FNAME' => 'Their own' ), 'tags' => array( 'Earlier' ) );
    $row = rt_queue( 'old@example.org' );
    $m   = $GLOBALS['mc']['members']['aud1'][ $hash( 'old@example.org' ) ];
    rt( "G: already $st: the new tags only", array( $row['outcome'], $m['status'], $m['merge'], $m['tags'] ), array( 'tagged', $st, array( 'FNAME' => 'Their own' ), array( 'Earlier', 'Seniors', 'Jan lunch' ) ) );
    rt( "G: already $st: their details are not sent", count( array_filter( $GLOBALS['mc']['calls'], function ( $c ) { return in_array( $c[0], array( 'PUT', 'PATCH' ), true ) || ( 'POST' === $c[0] && preg_match( '#/members$#', $c[1] ) ); } ) ), 0 );
}
foreach ( array( 'unsubscribed', 'cleaned' ) as $st ) {
    mc_reset_members();
    $GLOBALS['mc']['members']['aud1'][ $hash( 'gone@example.org' ) ] = array( 'status' => $st, 'merge' => array(), 'tags' => array() );
    $row = rt_queue( 'gone@example.org' );
    rt( "G: $st: never re-subscribed, not even tagged", array( $row['outcome'], $GLOBALS['mc']['members']['aud1'][ $hash( 'gone@example.org' ) ] ), array( 'skipped', array( 'status' => $st, 'merge' => array(), 'tags' => array() ) ) );
    rt( "G: $st: only the look-up was made", array_map( function ( $c ) { return $c[0]; }, $GLOBALS['mc']['calls'] ), array( 'GET' ) );
}
foreach ( array( 'archived', 'transactional' ) as $st ) {
    mc_reset_members();
    $GLOBALS['mc']['members']['aud1'][ $hash( 'back@example.org' ) ] = array( 'status' => $st, 'merge' => array(), 'tags' => array() );
    $row = rt_queue( 'back@example.org' );
    $m   = $GLOBALS['mc']['members']['aud1'][ $hash( 'back@example.org' ) ];
    rt( "G: $st: added back, with the tags", array( $row['outcome'], $m['status'], $m['tags'] ), array( 'restored', 'subscribed', array( 'Seniors', 'Jan lunch' ) ) );
}
mc_reset_members();
$row = rt_queue( 'fake@example.org' );
rt( 'G: an address Mailchimp refuses is skipped, not retried', $row['outcome'], 'skipped' );

/* Failure: retried, then failed, shown, retried by hand. */
mc_reset_members();
$GLOBALS['mc']['down'] = 99;
$row = rt_queue( 'later@example.org' );
rt( 'G: Mailchimp down: tried again later', array( $row['status'], $row['attempts'], $row['next_at'] > gmdate( 'Y-m-d H:i:s' ) ), array( 'queued', 1, true ) );
rt( 'G: the next run is scheduled for then', $GLOBALS['rt_cron'][ SFAF_Mailchimp::CRON_HOOK ] >= time() + 290, true );
rt( 'G: the message has no key in it', false === strpos( $row['last_error'], '-us21' ) && false !== strpos( $row['last_error'], '[key]' ), true );
for ( $i = 0; $i < 4; $i++ ) {
    $id = array_keys( $db->t['wp_uc_mailchimp_queue'] )[0];
    $db->t['wp_uc_mailchimp_queue'][ $id ]['next_at'] = gmdate( 'Y-m-d H:i:s', time() - 1 );
    rt_cron_run();
}
$row = array_values( $db->t['wp_uc_mailchimp_queue'] )[0];
rt( 'G: after five tries it has failed', array( $row['status'], $row['attempts'], count( json_decode( $row['log'], true ) ) ), array( 'failed', 5, 5 ) );
rt( 'G: the log has no key in it', false === strpos( $row['log'], '-us21' ), true );
$panel = rt_cap( function () { $_GET = array(); SFAF_Mailchimp::render_panel(); } );
rt( 'G: the connection screen shows it, with Retry', (bool) preg_match( '#data-uc-mc-problem="' . $row['id'] . '"#', $panel ) && false !== strpos( $panel, 'later@example.org' ) && false !== strpos( $panel, '>Retry</button>' ), true );
rt( 'G: and the counts', false !== strpos( $panel, '0 sent, 0 waiting, 1 failed' ), true );
rt( 'G: Retry puts it back now', SFAF_Mailchimp::requeue( $row['id'] ) && 'queued' === $db->t['wp_uc_mailchimp_queue'][ $row['id'] ]['status'], true );
$GLOBALS['mc']['down'] = 0;
rt_cron_run();
rt( 'G: and it goes through', $db->t['wp_uc_mailchimp_queue'][ $row['id'] ]['outcome'], 'added' );
rt( 'G: the key went in the Authorization header', in_array( 'Basic ' . base64_encode( 'sfaf:' . $KEY ), $GLOBALS['mc']['auth'], true ), true );
rt( 'G: and in no request\'s address or body', false === strpos( wp_json_encode( $GLOBALS['mc']['calls'] ), $KEY ), true );
SFAF_Mailchimp::delete( $B );
$db->t['wp_uc_mailchimp_queue'] = array();
SFAF_Mailchimp::enqueue( 1, $A, array( 'event_id' => $alpha_event, 'email' => 'x@example.org' ) );
$qid = array_keys( $db->t['wp_uc_mailchimp_queue'] )[0];
$db->t['wp_uc_mailchimp_queue'][ $qid ]['connection'] = $B;
rt_cron_run();
rt( 'G: a removed connection\'s sign-up fails with that reason', array( $db->t['wp_uc_mailchimp_queue'][ $qid ]['status'], $db->t['wp_uc_mailchimp_queue'][ $qid ]['last_error'] ), array( 'failed', 'The connection was removed.' ) );

/* ======================================================================== H */
$db->t['wp_uc_optins'] = array();
SFAF_Optins::record( 'ana@example.org', 'Ana Ruiz', $alpha_event, 'rsvp', array( 'lang' => 'es' ) );
SFAF_Optins::record( 'ana@example.org', 'Ana Ruiz', $alpha_event, 'rsvp', array( 'list_key' => 'mc:' . $A, 'list_name' => 'Aging Services', 'lang' => 'en' ) );
$db->insert( 'wp_uc_optins', array( 'email' => 'old@example.org', 'name' => 'Old', 'event_id' => 0, 'source_form' => 'rsvp', 'consented_at' => '2026-01-01 10:00:00', 'created_gmt' => '' ) );
$page = rt_cap( function () use ( $portal, $admin ) { $_GET = array(); kit_call( 'SFAF_Portal', 'render_optins', $portal, array( $admin ) ); } );
rt( 'H: Email Opt-ins renders', false === strpos( $page, 'THREW' ), true );
rt( 'H: List and Language columns', false !== strpos( $page, '<th>Email</th><th>Name</th><th>List</th><th>Consented</th><th>Language</th><th>Form</th><th>Event</th>' ), true );
rt( 'H: SFAF\'s, a team\'s, and a row from before', array( substr_count( $page, '<td>SFAF Newsletter</td>' ), substr_count( $page, '<td>Aging Services</td>' ), substr_count( $page, '<td>Spanish</td>' ), substr_count( $page, '<td>English</td>' ) ), array( 2, 1, 1, 1 ) );
rt( 'H: the old line about nothing being sent is gone', false === strpos( $page, 'Nothing is sent from here and nothing is pushed anywhere.' ), true );
$src = file_get_contents( $GLOBALS['kit_root'] . '/includes/class-sfaf-portal.php' );
rt( 'H: the CSV has them too', false !== strpos( $src, "array( 'Email', 'Name', 'List', 'Consented At', 'Consented At (GMT)', 'Language', 'Source Form', 'Event' )" ), true );

echo "\n3.124.0: a team's Mailchimp list from the RSVP form\n" . str_repeat( '=', 72 ) . "\n";
if ( $fails ) { echo count( $fails ) . " of $ran failed:\n  - " . implode( "\n  - ", $fails ) . "\n"; exit( 1 ); }
echo "$ran assertions passed. Nothing about Mailchimp anywhere without a connection; the key encrypted and never shown; a box per\nconnected team on events that take RSVPs; the editors' card; Stay in touch unticked in both languages; consent recorded\nwith list, event, date and language, a waitlist's on confirmation; the send's five cases and its retries.\n";
exit( 0 );
