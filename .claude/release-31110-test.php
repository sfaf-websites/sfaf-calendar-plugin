<?php
/**
 * 3.111.0: PINS, NO PUBLIC COUNT, SUBMISSIONS REVIEWED BY TEAMS, A RICH
 * REGISTRANT EMAIL, ONE PDF PER EVENT, COST AGES AND CONTACT, THE FORMS AUDIT.
 *
 *     php .claude/release-31110-test.php
 *
 *   A.1  pinned events come first in the list and the sidebar, marked
 *        Featured, and are not listed twice; a past, cancelled, private or
 *        unpublished pin is skipped; a visitor's narrowing sets pins aside
 *        PLANT A.1: a cancelled event still pinned
 *   B    no registration count in the list card, the embed payload or the
 *        event page's block; the states are Register, Full, Join the waitlist
 *        and Register on another site
 *        PLANT B: a count in the embed payload
 *   C    reviewers: the series default team, else the organizers' Review
 *        team, editors only, never with no organizer; may_review() is what the
 *        routes ask; the arrival email adds reviewers to the setting's list
 *        PLANT C.3: an editor outside the review team approving
 *   D    the registrant email renders headings and lists inside the wrapper,
 *        and an image never survives
 *   E.1  only a PDF of 5MB or less is taken, read from its bytes
 *        PLANT E.1: a non-PDF accepted
 *   E.3  the file goes with the last event that uses it
 *   F    Cost in Registration and Age range and Contact in Event details, on
 *        Add and Edit, no Other details card; schema 14 moves the old contact
 *        and registration link, losing nothing
 *        PLANT F.3: a migrated value lost
 *   G    neither form offers Any other pictures, and every helper line on
 *        both is one sentence
 *        PLANT G.2: the field still rendered
 *
 * The browser half, at 1280px, 430px and 390px, is release-31110-live.php.
 */

require __DIR__ . '/wp-kit.php';
if ( ! function_exists( 'wpautop' ) ) { function wpautop( $s ) { return $s; } }
if ( ! function_exists( 'wp_mail' ) ) {
    function wp_mail( $to, $subject, $body, $headers = '', $files = array() ) {
        $GLOBALS['rt_mail'][] = array( 'to' => $to, 'subject' => $subject, 'body' => $body );
        return true;
    }
}
if ( ! function_exists( 'get_post_stati' ) ) { function get_post_stati( $a = array() ) { return array( 'publish' => 1, 'pending' => 1, 'draft' => 1, 'future' => 1, 'private' => 1, 'trash' => 1 ); } }
if ( ! function_exists( 'wp_trim_words' ) ) { function wp_trim_words( $t, $n = 55, $more = '' ) { $w = preg_split( '/\s+/', trim( (string) $t ) ); return implode( ' ', array_slice( $w, 0, $n ) ); } }
if ( ! function_exists( 'force_balance_tags' ) ) { function force_balance_tags( $t ) { return $t; } }
if ( ! function_exists( 'wp_delete_attachment' ) ) { function wp_delete_attachment( $id, $force = false ) { $GLOBALS['rt_deleted'][] = (int) $id; unset( $GLOBALS['kit_posts'][ (int) $id ] ); return true; } }

$fails = array();
function rt( $ok, $why ) { global $fails; if ( ! $ok ) { $fails[] = $why; } }
function rt_capture( $fn ) {
    $d = ob_get_level(); ob_start();
    try { $fn(); } catch ( Throwable $t ) { while ( ob_get_level() > $d ) { ob_end_clean(); } return 'THREW ' . $t->getMessage() . ' at ' . basename( $t->getFile() ) . ':' . $t->getLine(); }
    $h = ''; while ( ob_get_level() > $d ) { $h = ob_get_clean() . $h; } return $h;
}

/* ---- A query that answers what the list and the generator ask. ---------- */
function rt_query( $a ) {
    $type  = isset( $a['post_type'] ) ? $a['post_type'] : 'uc_event';
    $stat  = isset( $a['post_status'] ) ? (array) $a['post_status'] : array();
    $not   = isset( $a['post__not_in'] ) ? array_map( 'intval', (array) $a['post__not_in'] ) : array();
    $today = current_time( 'Y-m-d' );
    $out   = array();
    foreach ( $GLOBALS['kit_posts'] as $id => $p ) {
        if ( $type !== $p->post_type || in_array( (int) $id, $not, true ) ) { continue; }
        if ( $stat && ! in_array( 'any', $stat, true ) && ! in_array( $p->post_status, $stat, true ) ) { continue; }
        if ( 'uc_event' === $type ) {
            $date = (string) get_post_meta( $id, '_uc_event_date', true );
            if ( empty( $a['rt_any_date'] ) && ( '' === $date || $date < $today ) ) { continue; }
            if ( SFAF_Privacy::is_private( $id ) && ! empty( $a['meta_query'] ) ) { continue; }
        }
        if ( ! empty( $a['meta_query'] ) ) {
            foreach ( $a['meta_query'] as $k => $c ) {
                if ( is_array( $c ) && isset( $c['key'] ) && '_uc_attachment_id' === $c['key'] && (string) get_post_meta( $id, '_uc_attachment_id', true ) !== (string) $c['value'] ) { continue 2; }
            }
        }
        $out[ $id ] = $p;
    }
    uasort( $out, function ( $x, $y ) { return strcmp( (string) get_post_meta( $x->ID, '_uc_event_date', true ), (string) get_post_meta( $y->ID, '_uc_event_date', true ) ); } );
    $out = array_values( $out );
    if ( isset( $a['fields'] ) && 'ids' === $a['fields'] ) { $out = wp_list_pluck( $out, 'ID' ); }
    $per = isset( $a['posts_per_page'] ) ? (int) $a['posts_per_page'] : -1;
    return $per > 0 ? array_slice( $out, 0, $per ) : $out;
}
$GLOBALS['kit_query']     = 'rt_query';
$GLOBALS['kit_get_posts'] = 'rt_query';
$GLOBALS['kit_loop']      = true;

function rt_event( $title, $days, $meta = array(), $status = 'publish' ) {
    $id = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => $status, 'post_title' => $title, 'post_author' => 1, 'post_content' => '<p>About it.</p>' ) );
    update_post_meta( $id, '_uc_event_date', date( 'Y-m-d', strtotime( ( $days >= 0 ? '+' : '' ) . $days . ' days' ) ) );
    update_post_meta( $id, '_uc_start_time', '18:00' );
    update_post_meta( $id, '_uc_end_time', '19:30' );
    foreach ( $meta as $k => $v ) { update_post_meta( $id, $k, $v ); }
    return $id;
}

/* =========================================================================
 * A.1 PINS
 * ====================================================================== */
$e1  = rt_event( 'First ordinary event', 2 );
$e2  = rt_event( 'Second ordinary event', 4 );
$pin = rt_event( 'Pinned gala', 20 );
$off = rt_event( 'Cancelled pinned talk', 6, array( '_uc_cancelled' => '1' ) );
$old = rt_event( 'Last year\'s pinned walk', -30 );
$prv = rt_event( 'Private pinned dinner', 8, array( SFAF_Privacy::META => '1' ) );
$drf = rt_event( 'Draft pinned clinic', 9, array(), 'draft' );
$pins = implode( ',', array( $pin, $off, $old, $prv, $drf ) );

rt( array( $pin ) === SFAF_Shortcodes::pinned_ids( $pins ), 'PLANT A.1: the pins that can be shown are not only the gala: ' . json_encode( SFAF_Shortcodes::pinned_ids( $pins ) ) );
rt( '3,1,2' === SFAF_Shortcodes::pin_list( ' 3, 1,,3 x 2' ), 'A.1: pin_list() does not keep positive ids once, in order: ' . SFAF_Shortcodes::pin_list( ' 3, 1,,3 x 2' ) );

$sc   = new SFAF_Shortcodes();
$list = $sc->render_events( 10, 1, array( 'pin' => $pins ), 'card' );
$at   = function ( $h, $needle ) { $p = strpos( $h, $needle ); return false === $p ? PHP_INT_MAX : $p; };
rt( $at( $list['html'], 'Pinned gala' ) < $at( $list['html'], 'First ordinary event' ), 'A.1: the pinned gala is not drawn first in the list' );
rt( 1 === substr_count( $list['html'], 'Pinned gala' ), 'A.1: the pinned gala is listed ' . substr_count( $list['html'], 'Pinned gala' ) . ' times' );
rt( 1 === substr_count( $list['html'], 'data-uc-featured' ) && (bool) preg_match( '/data-uc-featured>Featured<\/span>\s*<h3 class="uc-card-title uc-lrow-title">\s*<a[^>]*>Pinned gala/', $list['html'] ),
    'A.1: the list does not mark the gala, and only the gala, Featured' );
rt( false !== strpos( $list['html'], 'Cancelled pinned talk' ) && 0 === substr_count( $list['html'], 'is-featured" data-category="">' . "\n" ), 'A.1: a skipped pin is not left where it would have been' );
$page2 = $sc->render_events( 10, 2, array( 'pin' => $pins ), 'card' );
rt( false === strpos( $page2['html'], 'data-uc-featured' ), 'A.1: page two draws the pins again' );
$side = $sc->render_sidebar( array( 'pin' => (string) $pin ), 5 );
rt( $at( $side, 'Pinned gala' ) < $at( $side, 'First ordinary event' ) && false !== strpos( $side, 'data-uc-featured' ), 'A.1: the sidebar does not draw the pin first, marked' );
$bound = $sc->render_sidebar( array( 'pin' => (string) $pin ), 5, null, date( 'Y-m' ) );
rt( false === strpos( $bound, 'data-uc-featured' ), 'A.1: a month-bound sidebar draws a pin' );
$block = $sc->render_calendar_block( array( 'view' => 'list', 'pin' => (string) $pin, 'toggle' => 'no' ) );
rt( false !== strpos( $block['html'], 'data-uc-featured' ) && false !== strpos( $block['html'], 'data-pin="' . $pin . '"' ), 'A.1: the shortcode block does not draw its pin or carry data-pin' );
$narrow = $sc->render_calendar_block( array( 'view' => 'list', 'pin' => (string) $pin, 'toggle' => 'no', 's' => 'ordinary' ) );
rt( false === strpos( $narrow['html'], 'data-uc-featured' ) && false !== strpos( $narrow['html'], 'data-pin="' . $pin . '"' ) && false !== strpos( $narrow['html'], 'data-filter-pin=""' ),
    'A.1: a search does not set the pin aside while keeping it on the block' );
rt( false !== strpos( file_get_contents( $GLOBALS['kit_root'] . '/public/js/embed-app.js' ), "pin: container.getAttribute('data-pin') || ''" ), 'A.1: embed.js does not send data-pin' );
$admin_src = file_get_contents( $GLOBALS['kit_root'] . '/admin/class-sfaf-admin.php' );
rt( false !== strpos( $admin_src, 'Pin an event' ) && false !== strpos( $admin_src, 'SFAF_Privacy::exclude( $args );' ), 'A.2: the generator has no Pin an event, or its list is not private-free' );
$choices = kit_call( 'SFAF_Admin', 'pin_choices', ( new ReflectionClass( 'SFAF_Admin' ) )->newInstanceWithoutConstructor() );
rt( array( $e1, $e2, $pin ) === array_column( (array) $choices, 'id' ), 'A.2: the generator offers other than the three upcoming public events: ' . json_encode( array_column( (array) $choices, 'id' ) ) );

/* =========================================================================
 * B. NO PUBLIC COUNT
 * ====================================================================== */
kit_reset();
$GLOBALS['kit_query'] = 'rt_query'; $GLOBALS['kit_get_posts'] = 'rt_query'; $GLOBALS['kit_loop'] = true;
$open = rt_event( 'Writing circle', 3, array( '_uc_rsvp_enabled' => '1', '_uc_capacity' => '20' ) );
$full = rt_event( 'Packed workshop', 5, array( '_uc_rsvp_enabled' => '1', '_uc_capacity' => '20' ) );
$store =& sfaf_rsvp_count_store();
$store[ $open ] = 7;
$store[ $full ] = 20;
sfaf_set_embed_context( true );
$payload = $sc->render_calendar_block( array( 'view' => 'list', 'toggle' => 'no' ) );
sfaf_set_embed_context( false );
$words = strip_tags( $payload['html'] );
rt( ! preg_match( '/\b7\s*(of|\/)|\bof 20\b|\/\s*20\b|\b13\s+(left|spots?|places?)|spots?\b|registered|places? left/i', $words ), 'PLANT B: the embed payload carries a registration count: ' . trim( preg_replace( '/\s+/', ' ', $words ) ) );
rt( 1 === substr_count( $payload['html'], '<span class="uc-lrow-note">Full</span>' ), 'B: the full event is not marked Full on its card' );
$page_open = sfaf_rsvp_block( $open );
$page_full = sfaf_rsvp_block( $full );
rt( false === strpos( $page_open, 'uc-capacity' ) && ! preg_match( '/\b7\b|\b20\b/', strip_tags( $page_open ) ), 'B: the event page block still shows a count: ' . strip_tags( $page_open ) );
rt( false !== strpos( $page_open, '>Register</span>' ) && false !== strpos( $page_full, '>Join the waitlist</span>' ), 'B: the page button is not Register, or Join the waitlist when full' );
$rsvp_src = file_get_contents( $GLOBALS['kit_root'] . '/includes/class-sfaf-rsvp.php' );
rt( false === strpos( $rsvp_src, "'count'      => sfaf_get_rsvp_count" ), 'B: the registration response still returns the count' );

/* =========================================================================
 * C. REVIEWED BY TEAMS
 * ====================================================================== */
kit_reset();
$GLOBALS['kit_get_posts'] = 'rt_query';
$GLOBALS['kit_users'] = array(); $GLOBALS['kit_umeta'] = array();
foreach ( array( 21 => 'editor', 22 => 'editor', 23 => 'contributor', 24 => 'admin', 25 => 'editor' ) as $uid => $role ) {
    $u = new WP_User(); $u->ID = $uid; $u->display_name = 'Person ' . $uid; $u->user_email = 'p' . $uid . '@sfaf.org'; $u->user_login = 'p' . $uid;
    $GLOBALS['kit_users'][] = $u;
    $GLOBALS['kit_umeta'][ $uid ] = array( '_uc_calendar_role' => $role );
    if ( 24 !== $uid ) { $GLOBALS['kit_refused'][] = $uid; }
}
$GLOBALS['kit_options']['sfaf_teams'] = array(
    'strut'  => array( 'id' => 'strut', 'name' => 'Strut team', 'users' => array( 21, 23 ), 'created' => 1, 'updated' => 1 ),
    'latino' => array( 'id' => 'latino', 'name' => 'Programa Latino', 'users' => array( 22 ), 'created' => 1, 'updated' => 1 ),
);
$GLOBALS['kit_options']['uc_settings'] = array( 'submission_alert_emails' => 'websites@sfaf.org' );
SFAF_Submissions::set_organizer_review_team( 11, 'strut' );
$sub = rt_event( 'Submitted bake sale', 9, array( SFAF_Submissions::META_KIND => SFAF_Submissions::KIND_COMMUNITY, SFAF_Request::META_NAME => 'Pat', SFAF_Request::META_EMAIL => 'pat@example.org' ), 'pending' );
$GLOBALS['kit_terms'][ $sub ]['uc_organizer'] = array( 11 );
rt( array( 'strut' ) === SFAF_Submissions::review_teams( $sub ), 'C.1: the organizer\'s Review team is not the review team: ' . json_encode( SFAF_Submissions::review_teams( $sub ) ) );
rt( array( 21 ) === SFAF_Submissions::reviewers( $sub ), 'C.1: the reviewers are not the team\'s one editor (the contributor is not one): ' . json_encode( SFAF_Submissions::reviewers( $sub ) ) );
rt( true === SFAF_Submissions::may_review( 21, $sub ), 'C.1: the team\'s editor may not review' );
rt( false === SFAF_Submissions::may_review( 22, $sub ) && false === SFAF_Submissions::may_review( 25, $sub ), 'PLANT C.3: an editor outside the review team may approve' );
rt( false === SFAF_Submissions::may_review( 23, $sub ), 'C.1: a contributor on the team may review' );
rt( true === SFAF_Submissions::may_review( 24, $sub ), 'C.1: an admin may not review' );
$GLOBALS['kit_terms'][ $sub ]['uc_series'] = array( 12 );
update_term_meta( 12, SFAF_Access::SERIES_TEAMS, array( 'latino' ) );
rt( array( 'latino' ) === SFAF_Submissions::review_teams( $sub ) && true === SFAF_Submissions::may_review( 22, $sub ) && false === SFAF_Submissions::may_review( 21, $sub ),
    'C.1: the series default team does not take over from the organizer\'s' );
rt( array( 'websites@sfaf.org', 'p22@sfaf.org' ) === SFAF_Submissions::arrival_recipients( $sub ), 'C.3: the email does not go to the setting\'s list and then the reviewer: ' . json_encode( SFAF_Submissions::arrival_recipients( $sub ) ) );
$GLOBALS['kit_terms'][ $sub ]['uc_organizer'] = array();
rt( array() === SFAF_Submissions::reviewers( $sub ) && array( 'websites@sfaf.org' ) === SFAF_Submissions::arrival_recipients( $sub ) && false === SFAF_Submissions::may_review( 22, $sub ),
    'C.2: a submission with no organizer goes to somebody but the admins' );
$GLOBALS['kit_terms'][ $sub ]['uc_organizer'] = array( 12 );
unset( $GLOBALS['kit_term_meta'][12][ SFAF_Access::SERIES_TEAMS ] );
rt( array() === SFAF_Submissions::reviewers( $sub ), 'C.2: an organizer with no team gives the submission reviewers' );
$imp = rt_event( 'An import', 9, array(), 'pending' );
$GLOBALS['kit_terms'][ $imp ]['uc_organizer'] = array( 11 );
rt( array() === SFAF_Submissions::reviewers( $imp ), 'C: an event that is not a submission has reviewers' );
$portal_src = file_get_contents( $GLOBALS['kit_root'] . '/includes/class-sfaf-portal.php' );
foreach ( array( 'approve_event', 'reject_event' ) as $route ) {
    $sl = substr( $portal_src, strpos( $portal_src, "case '$route':" ), 400 );
    rt( false !== strpos( $sl, 'SFAF_Submissions::may_review( $user, $event_id )' ), "C.3: $route does not ask may_review()" );
}
rt( 2 === substr_count( file_get_contents( $GLOBALS['kit_root'] . '/includes/class-sfaf-request.php' ) . file_get_contents( $GLOBALS['kit_root'] . '/includes/class-sfaf-submit.php' ), 'SFAF_Submissions::arrival_recipients( $event_id )' ),
    'C.3: the two arrival emails do not both ask arrival_recipients()' );

/* The reviewer's own screen, and To review. */
$GLOBALS['kit_terms'][ $sub ]['uc_organizer'] = array( 11 );
$GLOBALS['kit_query'] = function ( $a ) { $a['rt_any_date'] = 1; return rt_query( $a ); };
$portal   = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();
$reviewer = get_userdata( 21 );
$queue    = rt_capture( function () use ( $portal, $reviewer ) { kit_call( 'SFAF_Portal', 'render_pending', $portal, array( $reviewer ) ); } );
rt( false !== strpos( $queue, 'data-uc-review-queue' ) && 1 === substr_count( $queue, 'data-uc-review-row' ) && false !== strpos( $queue, 'Submitted bake sale' ),
    'C.1: the reviewer\'s Pending is not their one submission: ' . substr( strip_tags( $queue ), 0, 300 ) );
rt( false === strpos( $queue, 'An import' ) && false === strpos( $queue, 'uc-pending-bulk' ) && false === strpos( $queue, 'fetch_sources' ) && false === strpos( $queue, 'events/edit/' ),
    'C.1: the reviewer\'s Pending reaches an import, the bulk bar, Fetch or the editor' );
rt( false !== strpos( $queue, 'value="approve_event"' ) && false !== strpos( $queue, 'value="reject_event"' ) && false !== strpos( $queue, 'id="uc-approve-ask-' . $sub . '"' ),
    'C.1: the reviewer\'s row has no Approve, Reject or the approval dialog' );
$dash = rt_capture( function () use ( $portal, $reviewer ) { kit_call( 'SFAF_Portal', 'render_dashboard', $portal, array( $reviewer ) ); } );
rt( (bool) preg_match( '/data-uc-to-review><span class="uc-stat-num">1<\/span><span class="uc-stat-label">To review<\/span>/', $dash ), 'C.4: the reviewer\'s dashboard does not say To review 1' );
$other = get_userdata( 22 );
$dash2 = rt_capture( function () use ( $portal, $other ) { kit_call( 'SFAF_Portal', 'render_dashboard', $portal, array( $other ) ); } );
rt( false === strpos( $dash2, 'data-uc-to-review' ), 'C.4: an editor with nothing to review is shown To review' );
$orgs = rt_capture( function () use ( $portal ) { kit_call( 'SFAF_Portal', 'render_organizers', $portal, array( get_userdata( 24 ) ) ); } );
rt( false !== strpos( $orgs, 'name="organizer_review_team"' ) && (bool) preg_match( '/<option value="strut"\s+selected/', $orgs ) && false !== strpos( $orgs, 'Review team: Strut team' ),
    'C.1: the Organizers screen has no Review team picker showing Alpha\'s team' );
$del = SFAF_Teams::delete( 'strut' );
rt( is_wp_error( $del ) && false !== strpos( $del->get_error_message(), 'Alpha' ), 'C: a Review team can be deleted while an organizer names it' );

/* =========================================================================
 * D. THE REGISTRANT EMAIL
 * ====================================================================== */
$typed = '<h3>Before you come</h3><p>Hi {first_name}, <strong>bring</strong> water.</p><ul><li>Water</li><li>A <em>hat</em></li></ul><ol><li>Park</li><li>Walk</li></ol><p><img src="https://x/y.png" alt="" />Thanks</p><p>{event_link}</p>';
/* wp-kit's wp_kses() keeps everything, so the list is what is asserted, and the
 * builder is shown never to print an <img> even if one arrived. */
$allowed = SFAF_Registrant_Mail::allowed_tags();
rt( ! isset( $allowed['img'] ) && isset( $allowed['ul'], $allowed['ol'], $allowed['li'], $allowed['h3'], $allowed['em'], $allowed['strong'], $allowed['a'] ), 'D: the body\'s tags are not the toolbar\'s, or let an image through' );
$clean = SFAF_Registrant_Mail::clean_body( $typed );
$blocks = SFAF_Messages::rich_blocks( $clean, array( 'event_link' => 'https://e/1/' ) );
rt( 6 === count( $blocks ) && 'h3' === $blocks[0]['tag'] && 'ul' === $blocks[2]['tag'] && array( 'Park', 'Walk' ) === $blocks[3]['items'],
    'D: the body is not read as heading, paragraph, two lists and two paragraphs: ' . json_encode( $blocks ) );
$built = SFAF_Messages::compose( 'registrant_message', 'default', 'en', array( 'first_name' => 'Ana', 'event_link' => 'https://e/1/', 'title' => 'T' ), array(
    'text' => array( 'subject' => 'A note', 'intro' => $clean ), 'rich_intro' => true, 'event_url' => 'https://e/1/',
) );
rt( (bool) preg_match( '/<h3 style="[^"]*">Before you come<\/h3>/', $built['html'] ) && (bool) preg_match( '/<ul style="[^"]*"><li style="[^"]*">Water<\/li><li style="[^"]*">A <em>hat<\/em><\/li><\/ul>/', $built['html'] )
    && false !== strpos( $built['html'], '<strong>bring</strong>' ) && false !== strpos( $built['html'], 'Hi Ana' ), 'D: the email does not render the heading, the list and the bold' );
rt( false !== strpos( $built['text'], "- Water\n- A hat" ) && false !== strpos( $built['text'], "1. Park\n2. Walk" ), 'D: the plain text does not carry the lists: ' . $built['text'] );
rt( false === strpos( $built['html'], '{first_name}' ) && false === strpos( $built['html'], '<img src="https://x/y.png"' ), 'D: a token or an image reached the message' );

/* =========================================================================
 * E. THE PDF
 * ====================================================================== */
$dir = sys_get_temp_dir() . '/rt31110-' . getmypid();
@mkdir( $dir );
file_put_contents( "$dir/flyer.pdf", "%PDF-1.4\n%\xE2\xE3\n1 0 obj << >> endobj\ntrailer << >>\n%%EOF\n" );
file_put_contents( "$dir/photo.pdf", base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==' ) );
file_put_contents( "$dir/page.pdf", "<html><script>alert(1)</script></html>" );
rt( '' === SFAF_Uploads::pdf_rule_error( "$dir/flyer.pdf", filesize( "$dir/flyer.pdf" ) ), 'E.1: a real PDF is refused' );
rt( SFAF_Uploads::PDF_RULE_MESSAGE === SFAF_Uploads::pdf_rule_error( "$dir/photo.pdf", filesize( "$dir/photo.pdf" ) ), 'PLANT E.1: a PNG named .pdf is accepted' );
rt( SFAF_Uploads::PDF_RULE_MESSAGE === SFAF_Uploads::pdf_rule_error( "$dir/page.pdf", filesize( "$dir/page.pdf" ) ), 'PLANT E.1: an HTML file named .pdf is accepted' );
rt( SFAF_Uploads::PDF_RULE_MESSAGE === SFAF_Uploads::pdf_rule_error( "$dir/flyer.pdf", SFAF_Uploads::PDF_BYTES + 1 ), 'E.1: a PDF over 5MB is accepted' );
array_map( 'unlink', glob( "$dir/*" ) ); @rmdir( $dir );
rt( 'Attachments must be a PDF under 5MB.' === SFAF_Uploads::PDF_RULE_MESSAGE, 'E.1: the rule\'s sentence changed' );
$up_src  = file_get_contents( $GLOBALS['kit_root'] . '/includes/class-sfaf-uploads.php' );
$ins     = substr( $up_src, strpos( $up_src, 'public static function inspect(' ), 6000 );
rt( false !== strpos( $ins, 'self::pdf_rule_error( $tmp, (int) $bytes )' ) && false !== strpos( file_get_contents( $GLOBALS['kit_root'] . '/includes/class-sfaf-attachments.php' ), "SFAF_Uploads::inspect( self::FIELD, null, 'pdf' )" ),
    'E.1: the attachment does not go through inspect() and its pdf rule' );

/* E.2 and E.3: the button, sharing, deletion. */
kit_reset();
$GLOBALS['kit_get_posts'] = function ( $a ) { $a['rt_any_date'] = 1; return rt_query( $a ); };
kit_write_post( array( 'ID' => 900, 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_title' => '' ) );
update_post_meta( 900, '_wp_attached_file', 'calendar-files/autumn-flyer.pdf' );
$GLOBALS['kit_files'][900] = 'https://resources.sfaf.org/wp-content/uploads/calendar-files/autumn-flyer.pdf';
$o1 = rt_event( 'Weekly walk', 3, array( SFAF_Attachments::META_ID => 900 ) );
$o2 = rt_event( 'Weekly walk', 10, array( SFAF_Attachments::META_ID => 900, SFAF_Attachments::META_LABEL => 'Route map' ) );
rt( 'Download event flyer' === SFAF_Attachments::label( $o1 ) && 'Route map' === SFAF_Attachments::label( $o2 ), 'E.2: the label is not the typed one, or the default' );
$btn = SFAF_Attachments::button_html( $o2 );
rt( false !== strpos( $btn, 'uc-actionbtn-secondary' ) && false !== strpos( $btn, 'target="_blank"' ) && false !== strpos( $btn, 'autumn-flyer.pdf' ) && false !== strpos( $btn, '>Route map<' ) && false !== stripos( $btn, 'opens in a new tab' ),
    'E.2: the button is not Route map, outlined, to the file in a new tab saying so: ' . $btn );
SFAF_Attachments::delete_with_event( $o1 );
rt( empty( $GLOBALS['rt_deleted'] ), 'E.3: the file went while another event still uses it' );
unset( $GLOBALS['kit_posts'][ $o1 ] );
SFAF_Attachments::delete_with_event( $o2 );
rt( array( 900 ) === (array) $GLOBALS['rt_deleted'], 'E.3: the file did not go with the last event using it' );
$rec_src = file_get_contents( $GLOBALS['kit_root'] . '/includes/class-sfaf-recurrence.php' );
rt( false !== strpos( $rec_src, "'_uc_attachment_id', '_uc_attachment_label'," ) && 2 === substr_count( $portal_src, 'SFAF_Attachments::META_ID, SFAF_Attachments::META_LABEL,' ), 'E.1: the attachment does not travel with a repeating event and a copy' );
$single = file_get_contents( $GLOBALS['kit_root'] . '/templates/single-uc_event.php' );
rt( strpos( $single, 'SFAF_Attachments::button_html( $post_id' ) > strpos( $single, 'sfaf_event_description_html( $post_id )' )
    && strpos( $single, 'SFAF_Attachments::button_html( $post_id' ) < strpos( $single, 'sfaf_social_share_buttons( $post_id )' ), 'E.2: the page button is not under the description and above the share buttons' );

/* =========================================================================
 * F. COST, AGES, CONTACT
 * ====================================================================== */
kit_reset();
$GLOBALS['kit_options']['sfaf_teams'] = array();
$admin  = new WP_User();
$portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();
$ev     = rt_event( 'Listing event', 7, array( '_uc_cost' => 'Twenty dollars at the door, or pay what you can afford tonight', '_uc_age_restriction' => '18+', '_uc_contact_name' => 'Pat' ) );
foreach ( array( 'add' => 0, 'edit' => $ev ) as $which => $id ) {
    $h   = rt_capture( function () use ( $portal, $admin, $id ) { $_GET = array(); kit_call( 'SFAF_Portal', 'render_event_form', $portal, array( $admin, $id ) ); } );
    $reg = substr( $h, strpos( $h, 'data-uc-card="registration"' ), 2500 );
    $det = substr( $h, strpos( $h, 'data-uc-card="details"' ) );
    $det = substr( $det, 0, strpos( $det, '</section>' ) );
    rt( false !== strpos( $reg, 'name="listing_cost"' ), "F.1: $which: Cost is not in the Registration card" );
    rt( false !== strpos( $det, 'name="listing_age"' ) && false !== strpos( $det, 'name="contact_name"' ) && false !== strpos( $det, 'name="contact_email"' ) && false !== strpos( $det, 'name="contact_phone"' ),
        "F.1: $which: Age range and Contact are not in Event details" );
    rt( false !== strpos( $det, 'name="uc_attachment_file"' ) && false !== strpos( $det, 'placeholder="Download event flyer"' ), "E.1: $which: Event details has no Attachment control" );
    rt( false === strpos( $h, 'data-uc-card="other-details"' ) && false === strpos( $h, 'Other details</h2>' ), "F.3: $which: the Other details card is still drawn" );
    rt( 1 === substr_count( $h, 'name="listing_cost"' ), "F.1: $which: Cost is drawn " . substr_count( $h, 'name="listing_cost"' ) . ' times' );
}
/* The save: an unchanged long cost is kept; a changed one is cut to 40. */
$_POST = array( 'uc_cost_present' => '1', 'listing_cost' => 'Twenty dollars at the door, or pay what you can afford tonight', 'uc_details_extra_present' => '1', 'listing_age' => '18+', 'contact_name' => 'Pat', 'contact_email' => 'not an address', 'contact_phone' => '' );
kit_call( 'SFAF_Portal', 'save_listing_fields', $portal, array( $ev ) );
rt( 'Twenty dollars at the door, or pay what you can afford tonight' === get_post_meta( $ev, '_uc_cost', true ), 'F.1: an unchanged cost over 40 was cut on save' );
rt( '' === (string) get_post_meta( $ev, '_uc_contact_email', true ), 'F.1: an email that is not one was kept' );
$_POST['listing_cost'] = 'Fifteen dollars, or free for anybody who is under twenty five';
kit_call( 'SFAF_Portal', 'save_listing_fields', $portal, array( $ev ) );
rt( 40 >= strlen( (string) get_post_meta( $ev, '_uc_cost', true ) ), 'F.1: a changed cost is not cut to 40' );
$_POST = array();

/* The migration. */
$GLOBALS['kit_query'] = function ( $a ) { $a['rt_any_date'] = 1; $a['post_status'] = 'any';
    $want = isset( $a['meta_query'][0]['key'] ) ? $a['meta_query'][0]['key'] : '';
    return array_values( array_filter( rt_query( array_merge( $a, array( 'meta_query' => array() ) ) ), function ( $id ) use ( $want ) { return '' === $want || '' !== (string) get_post_meta( $id, $want, true ); } ) ); };
$m1 = rt_event( 'Old contact', 7, array( '_uc_public_contact' => 'Pat Example, pat@example.org' ) );
$m2 = rt_event( 'Old contact beside new', 7, array( '_uc_public_contact' => 'Old line', '_uc_contact_name' => 'Ana' ) );
$m3 = rt_event( 'Old link', 7, array( '_uc_rsvp_url' => 'https://tickets.example.org/walk' ) );
$m4 = rt_event( 'Old link, RSVPs here', 7, array( '_uc_rsvp_url' => 'https://tickets.example.org/talk', '_uc_rsvp_enabled' => '1' ) );
rt( true === sfaf_migrate_other_details() > 0, 'F.3: the migration moved nothing' );
rt( 'Pat Example, pat@example.org' === get_post_meta( $m1, '_uc_contact_name', true ) && 'Pat Example, pat@example.org' === sfaf_event_public_contact( $m1 ), 'PLANT F.3: the old contact line was lost: ' . json_encode( array( get_post_meta( $m1, '_uc_contact_name', true ), sfaf_event_public_contact( $m1 ) ) ) );
rt( 'Ana' === get_post_meta( $m2, '_uc_contact_name', true ) && 'Old line' === get_post_meta( $m2, '_uc_public_contact', true ), 'F.3: a contact the event already had was replaced, or the old line deleted' );
rt( 'https://tickets.example.org/walk' === get_post_meta( $m3, SFAF_Register_Elsewhere::META_URL, true ) && SFAF_Register_Elsewhere::is_on( $m3 ) && '' === (string) get_post_meta( $m3, '_uc_rsvp_url', true ),
    'PLANT F.3: the old registration link did not become Register on another site' );
rt( 'https://tickets.example.org/talk' === get_post_meta( $m4, SFAF_Register_Elsewhere::META_URL, true ) && ! SFAF_Register_Elsewhere::is_on( $m4 ), 'F.3: an event taking RSVPs here was switched to another site' );
update_post_meta( $m4, '_uc_public_contact', 'A later one' );
rt( 0 === sfaf_migrate_other_details() && 'A later one' === get_post_meta( $m4, '_uc_public_contact', true ), 'F.3: the migration ran twice' );
rt( '14' === SFAF_DB_VERSION, 'F.3: the schema is not 14' );

/* The page's three lines. */
rt( false !== strpos( $single, '<span class="uc-fact-label">Cost</span>' ) && false !== strpos( $single, '<span class="uc-fact-label">Ages</span>' ) && false !== strpos( $single, '<span class="uc-fact-label">Contact</span>' ),
    'F.2: the page does not label Cost, Ages and Contact' );

/* =========================================================================
 * G. THE FORMS
 * ====================================================================== */
$forms = array(
    'staff request'        => rt_capture( function () { kit_call( 'SFAF_Request', 'render_form', null, array( 'tok', 'someone@sfaf.org', array(), array() ) ); } ),
    'community submission' => rt_capture( function () { kit_call( 'SFAF_Submit', 'render_form', null, array( (object) array( 'slug' => 'alpha', 'name' => 'Alpha', 'term_id' => 11 ), array(), array() ) ); } ),
);
foreach ( $forms as $name => $html ) {
    rt( 0 !== strpos( $html, 'THREW' ), "G: the $name form did not render: $html" );
    rt( false === strpos( $html, 'uc_image_extra' ) && false === stripos( $html, 'Any other pictures' ), "PLANT G.2: the $name form still offers Any other pictures" );
    $d = new DOMDocument(); @$d->loadHTML( '<?xml encoding="utf-8"?>' . $html );
    foreach ( ( new DOMXPath( $d ) )->query( '//*[contains(concat(" ",normalize-space(@class)," ")," uc-hint ")]' ) as $n ) {
        $t = trim( preg_replace( '/\s+/', ' ', $n->textContent ) );
        if ( '' === $t ) { continue; }
        $sentences = preg_match_all( '/[.!?](\s|$)/', $t );
        rt( 1 === $sentences, "G.3: a $name helper line is not one sentence: \"$t\"" );
    }
}

/* =========================================================================
 * H. NOTIFICATIONS WITH REGISTRATION ELSEWHERE
 * ====================================================================== */
kit_reset();
$GLOBALS['kit_options']['sfaf_teams'] = array();
$GLOBALS['rt_mail'] = array();
$away = rt_event( 'Taken elsewhere', 6, array( '_uc_rsvp_enabled' => '1', SFAF_Register_Elsewhere::META_MODE => 'elsewhere', SFAF_Register_Elsewhere::META_URL => 'https://tickets.example.org/x' ) );
$imp  = rt_event( 'Imported', 6, array( SFAF_Sources::META_SOURCE => 'eventbrite' ) );
$here = rt_event( 'Taken here', 6, array( '_uc_rsvp_enabled' => '1' ) );
$kinds = array_keys( SFAF_Notifications::kinds() );
rt( count( $kinds ) >= 5, 'H: fewer than five message kinds were read' );
foreach ( $kinds as $kind ) {
    rt( false === SFAF_Notifications::on( $away, $kind ), "PLANT H: $kind is sent for an event registering on another site" );
    rt( false === SFAF_Notifications::on( $imp, $kind ), "PLANT H: $kind is sent for an imported event" );
    rt( true === SFAF_Notifications::on( $here, $kind ) && true === SFAF_Notifications::stored_on( $away, $kind ), "H: $kind is off for an event registering here, or its stored switch was lost" );
}
$person = (object) array( 'name' => 'Ana Lee', 'first_name' => 'Ana', 'last_name' => 'Lee', 'email' => 'ana@example.org', 'token' => 't', 'format' => '', 'rsvp_id' => 1 );
SFAF_Notifications::send_confirmation( $away, $person );
SFAF_Notifications::send_alert( $away, $person );
SFAF_Notifications::send_cancel_alert( $away, 'ana@example.org' );
rt( empty( $GLOBALS['rt_mail'] ), 'PLANT H: a message went out for an event registering on another site: ' . json_encode( $GLOBALS['rt_mail'] ) );
/* The two scheduled senders and the reminder ask on() too, by name. */
$nsrc = file_get_contents( $GLOBALS['kit_root'] . '/includes/class-sfaf-notifications.php' ) . file_get_contents( $GLOBALS['kit_root'] . '/includes/class-sfaf-reminders.php' );
foreach ( array( "self::on( \$id, 'summary' )", "self::on( \$id, 'day_before' )", "SFAF_Notifications::on( \$event_id, 'reminder' )" ) as $ask ) {
    rt( false !== strpos( $nsrc, $ask ), "H: a scheduled sender no longer asks on(): $ask" );
}
/* The card: one line, the fields hidden but holding their values. */
$portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();
$hh = rt_capture( function () use ( $portal, $away ) { $_GET = array(); kit_call( 'SFAF_Portal', 'render_event_form', $portal, array( new WP_User(), $away ) ); } );
$card = substr( $hh, strpos( $hh, 'data-uc-notifications-card' ) );
$card = substr( $card, 0, strpos( $card, '</section>' ) );
rt( (bool) preg_match( '/data-uc-notify-away>Registrations are taken on another site, so no registration emails are sent\.<\/p>/', $card )
    && (bool) preg_match( '/data-uc-notify-fields hidden>/', $card ) && 0 < substr_count( $card, 'checked' ), 'H: with Register on another site the card is not the line over hidden, unchanged fields' );
$hh2 = rt_capture( function () use ( $portal, $here ) { $_GET = array(); kit_call( 'SFAF_Portal', 'render_event_form', $portal, array( new WP_User(), $here ) ); } );
$card2 = substr( $hh2, strpos( $hh2, 'data-uc-notifications-card' ), 600 );
rt( (bool) preg_match( '/data-uc-notify-away hidden>/', $card2 ) && (bool) preg_match( '/data-uc-notify-fields>/', $card2 ), 'H: an event registering here hides its notification fields' );
$hh3 = rt_capture( function () use ( $portal, $imp ) { $_GET = array(); kit_call( 'SFAF_Portal', 'render_event_form', $portal, array( new WP_User(), $imp ) ); } );
$card3 = substr( $hh3, strpos( $hh3, 'data-uc-notifications-card' ) );
$card3 = substr( $card3, 0, strpos( $card3, '</section>' ) );
rt( false !== strpos( $card3, 'data-uc-notify-away>' ) && false === strpos( $card3, 'notify_kinds' ), 'H: an imported event\'s card is not the line alone' );
rt( false !== strpos( file_get_contents( $GLOBALS['kit_root'] . '/public/js/portal.js' ), "if (awayLine && notifyFields) { awayLine.hidden = !away; notifyFields.hidden = away; }" ), 'H: switching where people register does not swap the card' );

if ( $fails ) {
    echo 'RELEASE 3.111.0: ' . count( $fails ) . " FAILURE(S)\n  - " . implode( "\n  - ", $fails ) . "\n";
    exit( 1 );
}
echo "3.111.0: pins first and Featured, skipped when past, cancelled, private or unpublished, aside while a visitor narrows; no public count; reviewers from the series or the organizer, editors only, asked by the routes and the email; the registrant email's headings and lists; a PDF read from its bytes and kept while any event uses it; Cost, Age range and Contact on Add and Edit, the old keys moved and nothing lost; neither form offers other pictures, and every helper line is one sentence; nothing is sent for an event registering elsewhere, and its Notifications card says so.\n";
