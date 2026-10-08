<?php
/**
 * AN EDITOR OUTSIDE A TEAM GETS THE PUBLIC PAGE AND NO REGISTRATION DATA (3.110.1).
 *
 *     php .claude/team-access-test.php
 *
 * The rule: a team decides which events a person has; their level decides what
 * they can do on those events. An editor no longer has every event. This walks
 * every route that hands out registration data, as an editor on no team, for
 * an event somebody else created, and asserts that nothing comes back but the
 * public page; then gives the editor the event through Team and access and
 * asserts the same routes open, so the first half cannot pass by seeing
 * nothing at all.
 *
 *   screens   /caladmin/rsvps?event_id=N (the registration list), the event
 *             editor, the unscoped list, the dashboard, the events list's All
 *             events, the CSV export scoped and unscoped, and the REST feed.
 *             Real SFAF_Portal on wp-kit.php.
 *   mail      the registration alert and the pre-event summary: the link each
 *             editor is sent. Real SFAF_Notifications and the real gate,
 *             lifted, on mail-kit.php.
 *
 * Each half runs in its own process, because the two kits each define
 * WordPress. PLANT B.5: the gate's first step back to admin-or-editor.
 */

if ( ! isset( $argv[1] ) ) {
    $fails = array();
    foreach ( array( 'screens', 'mail' ) as $part ) {
        $out = shell_exec( 'php ' . escapeshellarg( __FILE__ ) . ' ' . $part . ' 2>&1' );
        if ( false === strpos( (string) $out, "PART $part OK" ) ) {
            $fails[] = "$part:\n" . trim( (string) $out );
        }
    }
    if ( $fails ) {
        echo 'TEAM ACCESS: ' . count( $fails ) . " PART(S) FAILED\n" . implode( "\n", $fails ) . "\n";
        exit( 1 );
    }
    echo "team access: an editor outside the team gets the public page and no registration data from the list, the editor, the export, the feed, the dashboard and both emails; on the team, every one of them opens.\n";
    exit( 0 );
}

$part  = $argv[1];
$fails = array();
function ta( $ok, $why ) { global $fails; if ( ! $ok ) { $fails[] = $why; } }
function ta_end( $part ) {
    global $fails;
    if ( $fails ) { echo "  - " . implode( "\n  - ", $fails ) . "\n"; exit( 1 ); }
    echo "PART $part OK\n";
    exit( 0 );
}

$SECRET = 'Ana Secretname';

/* =========================================================================
 * SCREENS
 * ====================================================================== */
if ( 'screens' === $part ) {
    require __DIR__ . '/wp-kit.php';
    if ( ! function_exists( 'wpautop' ) ) { function wpautop( $s ) { return $s; } }

    // User 1 is a WordPress administrator (the kit's default). User 3 is a
    // calendar editor with no WordPress capability.
    $GLOBALS['kit_umeta']   = array( 3 => array( '_uc_calendar_role' => 'editor' ) );
    $GLOBALS['kit_refused'] = array( 3 );
    $GLOBALS['kit_redirect_throws'] = true;

    $GLOBALS['kit_query'] = function ( $a ) {
        $o = array();
        foreach ( $GLOBALS['kit_posts'] as $id => $p ) {
            if ( 'uc_event' !== $p->post_type ) { continue; }
            if ( ! empty( $a['post__in'] ) && ! in_array( (int) $id, array_map( 'intval', $a['post__in'] ), true ) ) { continue; }
            if ( ! empty( $a['author'] ) && (int) $p->post_author !== (int) $a['author'] ) { continue; }
            $o[] = ( isset( $a['fields'] ) && 'ids' === $a['fields'] ) ? (int) $id : $p;
        }
        return $o;
    };
    $E = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_title' => 'Trans health drop-in', 'post_author' => 1 ) );
    foreach ( array( '_uc_event_date' => date( 'Y-m-d', strtotime( '+5 days' ) ), '_uc_start_time' => '18:00', '_uc_rsvp_enabled' => '1', '_uc_capacity' => '20' ) as $k => $v ) {
        update_post_meta( $E, $k, $v );
    }
    $GLOBALS['ta_rows'] = array( array( 'id' => 1, 'event_id' => $E, 'name' => $SECRET, 'first_name' => 'Ana', 'last_name' => 'Secretname',
        'email' => 'ana.secret@example.org', 'phone' => '', 'status' => 'confirmed', 'token' => 't', 'format' => '', 'created_at' => '2026-10-01 10:00:00',
        'cancelled_at' => null, 'removed_by' => 0, 'agreed_at' => null, 'text_opt_in' => 0, 'checked_in_at' => null, 'answers' => '', 'optin' => 0, 'post_title' => 'Trans health drop-in', 'event_title' => '' ) );
    $GLOBALS['kit_db'] = function ( $method, $sql ) {
        if ( false === strpos( $sql, 'uc_rsvps' ) ) { return 'get_results' === $method ? array() : null; }
        $rows = $GLOBALS['ta_rows'];
        if ( preg_match( '/event_id\s*=\s*(\d+)/', $sql, $m ) ) {
            $rows = array_filter( $rows, function ( $r ) use ( $m ) { return (int) $r['event_id'] === (int) $m[1]; } );
        }
        if ( preg_match( '/event_id\s+IN\s*\(([^)]*)\)/i', $sql, $m ) ) {
            $in = array_map( 'intval', explode( ',', $m[1] ) );
            $rows = array_filter( $rows, function ( $r ) use ( $in ) { return in_array( (int) $r['event_id'], $in, true ); } );
        }
        if ( 'get_var' === $method ) { return (string) count( $rows ); }
        if ( 'get_results' === $method ) { return array_map( function ( $r ) { return (object) $r; }, array_values( $rows ) ); }
        return null;
    };

    $portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();
    $as = function ( $uid ) { $GLOBALS['kit_user_id'] = $uid; $u = new WP_User(); $u->ID = $uid; $u->display_name = 'User ' . $uid; return $u; };
    /** Run a screen; answer what it printed, where it redirected, and whether it died. */
    $run = function ( $method, $args, $get = array() ) use ( $portal ) {
        $GLOBALS['kit_redirect'] = null; $_GET = $get;
        $d = ob_get_level(); ob_start(); $died = '';
        try { kit_call( 'SFAF_Portal', $method, $portal, $args ); }
        catch ( KitRedirect $r ) { /* the redirect is the answer */ }
        catch ( RuntimeException $e ) { $died = $e->getMessage(); }
        $h = ''; while ( ob_get_level() > $d ) { $h = ob_get_clean() . $h; }
        return array( 'html' => $h, 'to' => (string) $GLOBALS['kit_redirect'], 'died' => $died );
    };
    $public = get_permalink( $E );

    foreach ( array( 'outside' => false, 'on the team' => true ) as $label => $has ) {
        if ( $has ) {
            $GLOBALS['kit_options']['sfaf_teams'] = array( 'prog' => array( 'id' => 'prog', 'name' => 'Programs', 'users' => array( 3 ), 'created' => 1, 'updated' => 1 ) );
            SFAF_Access::set( $E, array( 'prog' ), array() );
        }
        $ed = $as( 3 );
        ta( $has === SFAF_Portal::user_can_edit_event( 3, $E ), "PLANT B.5: the gate says " . var_export( ! $has, true ) . " for an editor $label" );

        $r = $run( 'render_rsvps', array( $ed ), array( 'event_id' => $E ) );
        if ( ! $has ) {
            ta( $public === $r['to'], "PLANT B.5: the registration list, for an editor $label, did not send them to the public page: " . json_encode( $r['to'] ) );
            ta( false === strpos( $r['html'], 'Secretname' ), "PLANT B.5: the registration list showed a registrant to an editor $label" );
        } else {
            ta( '' === $r['to'] && false !== strpos( $r['html'], 'Secretname' ), "the registration list did not open for an editor $label: " . json_encode( array( $r['to'], $r['died'] ) ) );
        }

        $r = $run( 'render_event_form', array( $ed, $E ) );
        ta( $has ? '' === $r['to'] && false !== strpos( $r['html'], 'data-uc-card="registration"' ) : $public === $r['to'],
            "PLANT B.5: the event editor, for an editor $label: " . json_encode( array( $r['to'], $r['died'] ) ) );

        // Scoped export. Only the refusal is driven: an export that is allowed
        // writes headers and exits, which would end this test.
        if ( ! $has ) {
            $r = $run( 'export_rsvps_csv', array(), array( 'event_id' => $E, '_wpnonce' => 'nonce' ) );
            ta( false === strpos( $r['html'], 'Secretname' ) && ( $public === $r['to'] || '' !== $r['died'] ), "PLANT B.5: the CSV export gave an editor $label something: " . json_encode( array( $r['to'], $r['died'], substr( $r['html'], 0, 80 ) ) ) );
        }

        // Unscoped: every registration on the calendar is an admin's alone.
        $r = $run( 'export_rsvps_csv', array(), array( '_wpnonce' => 'nonce' ) );
        ta( false === strpos( $r['html'], 'Secretname' ) && '' !== $r['died'], "PLANT B.5: the unscoped CSV export gave an editor $label the whole calendar" );
        $r = $run( 'render_rsvps', array( $ed ), array() );
        ta( false === strpos( $r['html'], 'Secretname' ), "PLANT B.5: the unscoped registration list showed an editor $label a registrant" );

        // The dashboard and All events: the row is public, with no count and no link in.
        $r = $run( 'render_dashboard', array( $ed ), array( 'scope' => 'all' ) );
        ta( false === strpos( $r['html'], 'Secretname' ), "PLANT B.5: the dashboard named a registrant to an editor $label" );
        $r = $run( 'render_events', array( $ed ), array( 'scope' => 'all' ) );
        $rsvp_link = 'rsvps?event_id=' . $E;
        $edit_link = 'events/edit/' . $E;
        if ( ! $has ) {
            ta( false === strpos( $r['html'], $rsvp_link ) && false === strpos( $r['html'], $edit_link ), "PLANT B.5: All events gave an editor $label a way into the event" );
            ta( false !== strpos( $r['html'], 'data-uc-public-link' ) && false !== strpos( $r['html'], $public ), "All events did not give an editor $label the public page" );
        } else {
            ta( false !== strpos( $r['html'], $rsvp_link ) || false !== strpos( $r['html'], $edit_link ), "All events gave an editor $label no way into their own event" );
        }
    }

    /*
     * THE REST FEED. Its payload is the satellite sync's and carries each
     * event's rsvp_count, so what keeps registration data from an editor is
     * the route's permission: the site's API key, which a signed-in editor
     * does not have. Asked as the editor, with no key, a wrong key, and the
     * feed switched off; then with the key, so the refusal is not the only
     * answer the function knows.
     */
    $GLOBALS['kit_user_id'] = 3;
    class TA_Request { private $h; function __construct( $h ) { $this->h = $h; } function get_param( $k ) { return 'per_page' === $k ? 50 : 1; } function get_header( $n ) { return $this->h; } }
    $GLOBALS['kit_options']['sfaf_credentials'] = array();
    ta( is_wp_error( sfaf_rest_events_permission( new TA_Request( '' ) ) ), 'PLANT B.5: the REST feed answered an editor while switched off' );
    $GLOBALS['kit_options']['sfaf_credentials'] = array( 'multisite_api_key' => 'site-key' );
    ta( is_wp_error( sfaf_rest_events_permission( new TA_Request( '' ) ) ), 'PLANT B.5: the REST feed answered an editor with no key' );
    ta( is_wp_error( sfaf_rest_events_permission( new TA_Request( 'guess' ) ) ), 'PLANT B.5: the REST feed answered a wrong key' );
    ta( true === sfaf_rest_events_permission( new TA_Request( 'site-key' ) ), 'the REST feed refuses its own key, so the refusals above prove nothing' );
    ta_end( $part );
}

/* =========================================================================
 * MAIL
 * ====================================================================== */
if ( 'mail' === $part ) {
    require __DIR__ . '/mail-kit.php';
    ( new SFAF_Reminders() )->register();
    $ADMIN  = mk_user( 'admin@sfaf.org', 'Ada', 'admin' );
    $EDITOR = mk_user( 'eli@sfaf.org', 'Eli', 'editor' );
    $E = mk_post( array( 'post_title' => 'Trans health drop-in', 'post_author' => $ADMIN ) );
    update_post_meta( $E, '_uc_event_date', date( 'Y-m-d', strtotime( '+5 days' ) ) );
    update_post_meta( $E, '_uc_start_time', '18:00' );
    update_post_meta( $E, '_uc_end_time', '19:00' );
    update_post_meta( $E, '_uc_rsvp_enabled', '1' );
    update_post_meta( $E, SFAF_Reminders::NOTIFY_USERS_META, array( $EDITOR ) );

    $person = (object) array( 'name' => $SECRET, 'first_name' => 'Ana', 'last_name' => 'Secretname', 'email' => 'ana.secret@example.org', 'format' => '' );
    $rsvp_link = '/caladmin/rsvps?event_id=' . $E;
    $edit_link = '/caladmin/events/edit/' . $E;
    $mail_for = function ( $to ) { return array_values( array_filter( $GLOBALS['mk_mail'], function ( $m ) use ( $to ) { return strtolower( $m['to'] ) === $to; } ) ); };
    $has_link = function ( $msgs, $link ) { foreach ( $msgs as $m ) { if ( false !== strpos( $m['html'] . $m['text'], $link ) ) { return true; } } return false; };

    foreach ( array( 'outside' => false, 'on the team' => true ) as $label => $has ) {
        if ( $has ) {
            update_option( 'sfaf_teams', array( 'prog' => array( 'id' => 'prog', 'name' => 'Programs', 'users' => array( $EDITOR ), 'created' => 1, 'updated' => 1 ) ) );
            SFAF_Access::set( $E, array( 'prog' ), array() );
        }
        $GLOBALS['mk_mail'] = array();
        SFAF_Notifications::send_alert( $E, $person );
        $alert = $mail_for( 'eli@sfaf.org' );
        ta( 1 === count( $alert ), "the alert did not reach an editor $label on the notification list" );
        ta( $has === $has_link( $alert, $rsvp_link ), "PLANT B.5: the alert's registration list link, for an editor $label: " . ( $has ? 'missing' : 'sent' ) );

        $GLOBALS['mk_mail'] = array();
        $GLOBALS['mk_db']['wp_uc_rsvps'] = array( 1 => array( 'id' => 1, 'event_id' => $E, 'name' => $SECRET, 'first_name' => 'Ana', 'last_name' => 'Secretname',
            'email' => 'ana.secret@example.org', 'phone' => '', 'status' => 'confirmed', 'token' => 't', 'format' => '', 'created_at' => '2026-10-01 10:00:00' ) );
        delete_post_meta( $E, SFAF_Notifications::SUMMARY_CLAIM_META );
        $said = SFAF_Notifications::send_summary_for_event( $E );
        $summary = $mail_for( 'eli@sfaf.org' );
        if ( $summary ) {
            ta( $has === $has_link( $summary, $edit_link ), "PLANT B.5: the summary's caladmin link, for an editor $label: " . ( $has ? 'missing' : 'sent' ) );
        } else {
            ta( false, "the summary reached nobody for an editor $label, so its link could not be read: " . json_encode( $said ) );
        }
    }
    ta_end( $part );
}
echo "unknown part\n";
exit( 1 );
