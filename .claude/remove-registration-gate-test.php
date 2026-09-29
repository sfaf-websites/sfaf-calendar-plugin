<?php
/**
 * REMOVE IS BEHIND THE EVENT GATE, AT THE LIST AND AT THE WRITE (3.105.0).
 *
 *     php .claude/remove-registration-gate-test.php
 *
 * The real render_rsvps() and the real dispatch_post() through wp-kit.php, with
 * the registrations table modelled by a callable (kit_db). What is read is
 * the rendered list and the row after the POST, not the code.
 *
 *   the list      Remove on a confirmed row whose event the viewer may edit,
 *                 and nowhere else: not on a cancelled row, not on a row whose
 *                 event is gone. Its confirmation names the person.
 *   the route     a user the gate refuses is sent away and the row is
 *                 untouched; one it lets through releases the row, records
 *                 who, and lands back on the list.
 *
 * The behaviour of a release itself is remove-registration-test.php's.
 */

require __DIR__ . '/wp-kit.php';

$fails = array();
function rg_same( $label, $got, $want ) {
    global $fails;
    if ( $got !== $want ) { $fails[] = $label . ': got ' . var_export( $got, true ) . ', wanted ' . var_export( $want, true ); }
}

/* ---- The table. -------------------------------------------------------- */
function rg_row( $id, $event, $first, $email, $status ) {
    return array( 'id' => $id, 'event_id' => $event, 'event_title' => 'Gone event', 'name' => $first, 'first_name' => $first,
        'last_name' => '', 'email' => $email, 'phone' => '', 'status' => $status, 'token' => '', 'format' => '',
        'created_at' => '2026-09-20 10:00:00', 'cancelled_at' => null, 'removed_by' => 0 );
}
function rg_db( $method, $sql ) {
    $rows =& $GLOBALS['rg_rows'];
    if ( false === strpos( $sql, 'uc_rsvps' ) ) {
        return 'get_results' === $method ? array() : null;
    }
    if ( 'query' === $method && preg_match( "/^UPDATE .* SET status = 'cancelled', cancelled_at = '([^']+)', removed_by = (\d+) WHERE id = (\d+) AND status = 'confirmed'$/s", trim( $sql ), $m ) ) {
        $id = (int) $m[3];
        if ( ! isset( $rows[ $id ] ) || 'confirmed' !== $rows[ $id ]['status'] ) { return 0; }
        $rows[ $id ]['status'] = 'cancelled'; $rows[ $id ]['cancelled_at'] = $m[1]; $rows[ $id ]['removed_by'] = (int) $m[2];
        return 1;
    }
    if ( 'get_row' === $method && preg_match( '/WHERE id = (\d+)/', $sql, $m ) ) {
        return isset( $rows[ (int) $m[1] ] ) ? (object) $rows[ (int) $m[1] ] : null;
    }
    if ( 'get_results' === $method && false !== strpos( $sql, 'LEFT JOIN' ) ) {
        $out = array();
        foreach ( $rows as $r ) {
            if ( preg_match( '/r\.event_id = (\d+)/', $sql, $m ) && (int) $m[1] !== (int) $r['event_id'] ) { continue; }
            $p = get_post( $r['event_id'] );
            $r['post_title'] = $p ? $p->post_title : null;
            $out[] = (object) $r;
        }
        return $out;
    }
    if ( 'get_var' === $method ) { return 0; }
    if ( 'query' === $method ) { throw new RuntimeException( 'rg_db cannot run: ' . $sql ); }
    return 'get_results' === $method ? array() : null;
}

function rg_world() {
    kit_reset();
    $GLOBALS['kit_db'] = 'rg_db';
    $GLOBALS['kit_redirect_throws'] = true;
    $e = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_title' => 'Support group', 'post_author' => 1 ) );
    update_post_meta( $e, '_uc_event_date', '2026-10-01' );
    $GLOBALS['rg_rows'] = array(
        1 => rg_row( 1, $e, 'Robin', '', 'confirmed' ),
        2 => rg_row( 2, $e, 'Kim', 'kim@example.org', 'cancelled' ),
        3 => rg_row( 3, 99999, 'Alex', 'alex@example.org', 'confirmed' ),   // its event is gone
    );
    return $e;
}

$portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();
function rg_render( $portal, $get ) {
    $_GET = $get;
    ob_start();
    try { kit_call( 'SFAF_Portal', 'render_rsvps', $portal, array( wp_get_current_user() ) ); }
    catch ( Throwable $t ) { ob_end_clean(); return 'THREW ' . $t->getMessage(); }
    return (string) ob_get_clean();
}
function rg_removes( $html ) {
    $doc = new DOMDocument();
    @$doc->loadHTML( '<?xml encoding="utf-8" ?>' . $html );
    $out = array();
    foreach ( ( new DOMXPath( $doc ) )->query( '//button[@data-uc-rsvp-remove]' ) as $b ) {
        $form = $b->parentNode;
        $id = '';
        foreach ( $form->getElementsByTagName( 'input' ) as $in ) {
            if ( 'rsvp_id' === $in->getAttribute( 'name' ) ) { $id = $in->getAttribute( 'value' ); }
        }
        $out[ $id ] = array( 'confirm' => $b->getAttribute( 'data-uc-confirm' ), 'type' => $b->getAttribute( 'type' ) );
    }
    return $out;
}
function rg_post( $portal, $post ) {
    $_POST = $post;
    $GLOBALS['kit_redirect'] = '';
    try { kit_call( 'SFAF_Portal', 'dispatch_post', $portal, array( 'remove_rsvp' ) ); }
    catch ( KitRedirect $r ) { return 'REDIRECT ' . $r->getMessage(); }
    catch ( Throwable $t ) { return 'REFUSED ' . $t->getMessage(); }
    return 'NOTHING';
}

/* ---- 1. The list, across every event, as an administrator. -------------- */
$e = rg_world();
$all = rg_removes( rg_render( $portal, array() ) );
rg_same( 'Remove is on the confirmed row and nowhere else', array_keys( $all ), array( 1 ) );
rg_same( 'and it asks, naming the person', isset( $all['1'] ) ? $all['1']['confirm'] : '', 'Remove Robin\'s registration? Their place is released, and nothing puts it back.' );
rg_same( 'and the button says its type', isset( $all['1'] ) ? $all['1']['type'] : '', 'submit' );

/* One event's list: the same row, and the post comes back to that event. */
$one = rg_render( $portal, array( 'event_id' => $e ) );
rg_same( 'on one event\'s list the confirmed row has Remove', array_keys( rg_removes( $one ) ), array( 1 ) );
rg_same( 'and asks to come back to that event', false !== strpos( $one, 'name="back_event" value="1"' ), true );

/* ---- 2. The route, as somebody the gate refuses. ------------------------ */
$GLOBALS['kit_user_id'] = 42;
$GLOBALS['kit_refused'] = array( 42 );
$got = rg_post( $portal, array( 'uc_action' => 'remove_rsvp', 'uc_nonce' => 'nonce', 'rsvp_id' => '1' ) );
rg_same( 'PLANT: a user the gate refuses is sent away', 0 === strpos( $got, 'REFUSED wp_die: Denied' ), true );
rg_same( 'and the row is untouched', $GLOBALS['rg_rows'][1]['status'], 'confirmed' );

/* A row whose event is gone has no gate to pass, for anybody. */
$GLOBALS['kit_user_id'] = 1;
$GLOBALS['kit_refused'] = array();
$got = rg_post( $portal, array( 'uc_action' => 'remove_rsvp', 'uc_nonce' => 'nonce', 'rsvp_id' => '3' ) );
rg_same( 'a row whose event is gone cannot be removed', 0 === strpos( $got, 'REFUSED' ), true );
rg_same( 'and it is untouched', $GLOBALS['rg_rows'][3]['status'], 'confirmed' );

/* ---- 3. The route, as somebody the gate lets through. ------------------- */
$got = rg_post( $portal, array( 'uc_action' => 'remove_rsvp', 'uc_nonce' => 'nonce', 'rsvp_id' => '1', 'back_event' => '1' ) );
rg_same( 'the row is released', $GLOBALS['rg_rows'][1]['status'], 'cancelled' );
rg_same( 'and records who removed it', $GLOBALS['rg_rows'][1]['removed_by'], 1 );
rg_same( 'and when', null !== $GLOBALS['rg_rows'][1]['cancelled_at'], true );
rg_same( 'and lands back on that event\'s list, saying so', ( false !== strpos( $got, 'msg=rsvp_removed' ) && false !== strpos( $got, 'event_id=' . $e ) ), true );

/* The list afterwards: no Remove on the row, and who removed it under the status. */
$after = rg_render( $portal, array( 'event_id' => $e ) );
rg_same( 'a removed row offers Remove no more', array_keys( rg_removes( $after ) ), array() );
rg_same( 'and says who removed it', (bool) preg_match( '/Removed by Admin, /', $after ), true );

if ( $fails ) {
    echo 'REMOVE GATE: ' . count( $fails ) . " FAILURE(S)\n  - " . implode( "\n  - ", $fails ) . "\n";
    exit( 1 );
}
echo "remove gate: Remove is drawn only where the event gate passes, the route refuses whoever it refuses, and a release records who.\n";
