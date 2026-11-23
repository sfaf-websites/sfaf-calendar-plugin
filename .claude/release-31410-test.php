<?php
/**
 * 3.141.0: GREEN MAIN ACTIONS, THE CREATOR, A TEAM ON ADD EVENT, NO PICTURE.
 *
 *     php .claude/release-31410-test.php
 *
 * The real renderers, the real event save and the real classes on wp-kit.php.
 * Colour, motion and the arrows are measured in Chrome by release-31410-live.
 *
 *   N  + New Event on the Events page: the main action's class, one calendar
 *      icon, the larger face and the reduced-motion rule in portal.css
 *   G  every main action is green: control-standard-audit's check 8 passes;
 *      Save draft, Sign In, Add organizer and Add venue carry the classes the
 *      check holds green; a dialog's default confirm is the primary
 *   W  Text registrants' panel is "What can I send by Text and WhatsApp?"
 *   E  Email registrants with nobody registered says so in place of the form,
 *      and with somebody registered has the form and not the line
 *   T  Add event's team rule: one team is ticked and stays changeable; two
 *      teams or none ask for one, and a save without one is held as a draft
 *      saying so; a contributor and Edit event are untouched
 *   C  the creator: recorded once at creation, unchanged by another person's
 *      save; Team and access names the creator, never the viewer; an import
 *      names its platform and not the fetcher; a request names the requester;
 *      Replies go to resolves to the creator and its picker names them; a
 *      copy is the copier's and does not keep the original creator's address;
 *      schema 19 records creators and clears only "the creator" and the
 *      fetcher's address
 *   P  published with no picture: one email per admin and one Asana copy per
 *      series, whatever the number of dates; none for an event with a series
 *      picture or its own; none twice; an event in no series is its own email
 *      with its name, who added it and the edit link
 *   A  the series page's four disclosures each carry the one chevron, and
 *      portal.css takes the browser's triangle off a summary that has one
 *
 * PLANTs in plant-31410.php.
 */

require __DIR__ . '/wp-kit.php';
if ( ! function_exists( 'wpautop' ) ) { function wpautop( $s ) { return $s; } }
if ( ! function_exists( 'get_post_stati' ) ) { function get_post_stati( $a = array() ) { return array( 'publish' => 1, 'draft' => 1, 'pending' => 1, 'future' => 1, 'private' => 1, 'trash' => 1 ); } }
if ( ! function_exists( 'wp_specialchars_decode' ) ) { function wp_specialchars_decode( $s, $q = ENT_NOQUOTES ) { return htmlspecialchars_decode( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'wp_mail' ) ) {
    function wp_mail( $to, $subject, $body, $headers = '', $att = array() ) {
        $GLOBALS['rt_mail'][] = array( 'to' => is_array( $to ) ? implode( ',', $to ) : (string) $to, 'subject' => (string) $subject, 'body' => (string) $body );
        return true;
    }
}

$root  = dirname( __DIR__ );
$fails = array();
$n     = 0;
function rt( $label, $ok ) { global $fails, $n; $n++; if ( ! $ok ) { $fails[] = $label; } }
function rt_cap( $fn ) {
    $d = ob_get_level(); ob_start();
    try { $fn(); } catch ( Throwable $t ) { while ( ob_get_level() > $d ) { ob_end_clean(); } return 'THREW ' . $t->getMessage() . ' at ' . basename( $t->getFile() ) . ':' . $t->getLine(); }
    $h = ''; while ( ob_get_level() > $d ) { $h = ob_get_clean() . $h; } return $h;
}
function rt_x( $html ) { $d = new DOMDocument(); libxml_use_internal_errors( true ); $d->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>' ); return new DOMXPath( $d ); }
function rt_user( $id, $name, $role = 'admin', $email = '' ) {
    $u = new WP_User(); $u->ID = $id; $u->display_name = $name; $u->user_login = 'u' . $id;
    $u->user_email = '' !== $email ? $email : strtolower( strtok( $name, ' ' ) ) . '@sfaf.org';
    $GLOBALS['kit_users'][] = $u;
    $GLOBALS['kit_umeta'][ $id ]['_uc_calendar_role'] = $role;
    if ( 'admin' !== $role ) { $GLOBALS['kit_refused'][] = $id; }
    return $u;
}
$portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();
$css    = (string) file_get_contents( $root . '/public/css/portal.css' );
$src    = (string) file_get_contents( $root . '/includes/class-sfaf-portal.php' );
$js     = (string) file_get_contents( $root . '/public/js/portal.js' );

$GLOBALS['kit_users'] = array();
$admin  = rt_user( 31, 'Ada Admin' );
$ed1    = rt_user( 32, 'Eli One', 'editor' );
$ed2    = rt_user( 33, 'Eve Two', 'editor' );
$ed0    = rt_user( 34, 'Ezra None', 'editor' );
$contr  = rt_user( 35, 'Cal Contributor', 'contributor' );
$viewer = rt_user( 36, 'Val Viewer' );
update_option( SFAF_Teams::OPTION, array(
    'housing' => array( 'name' => 'Housing', 'users' => array( 32, 33 ) ),
    'harm'    => array( 'name' => 'Harm reduction', 'users' => array( 33 ) ),
) );

/* ---- N: + New Event. ---- */
$GLOBALS['kit_user_id'] = 31;
$_GET = array();
$list = rt_cap( function () use ( $portal, $admin ) { kit_call( 'SFAF_Portal', 'render_events', $portal, array( $admin ) ); } );
rt( 'N: the Events page renders', 0 !== strpos( $list, 'THREW' ) );
$x  = rt_x( $list );
$nb = $x->query( '//a[@data-uc-new-event]' );
rt( 'PLANT N: + New Event is the main action, larger, with its own class', 1 === $nb->length
    && false !== strpos( ' ' . $nb->item( 0 )->getAttribute( 'class' ) . ' ', ' uc-btn-primary ' )
    && false !== strpos( $nb->item( 0 )->getAttribute( 'class' ), 'uc-btn-new-event' ) );
rt( 'PLANT N: + New Event carries one calendar icon and its words', 1 === $nb->length
    && 1 === $x->query( './/svg[contains(@class,"sfaf-icon-calendar")]', $nb->item( 0 ) )->length
    && '+ New Event' === trim( preg_replace( '/\s+/', ' ', $nb->item( 0 )->textContent ) ) );
rt( 'N: the face is a step larger, 16px', (bool) preg_match( '/\.uc-portal \.uc-btn-new-event \{[^}]*font-size: 16px;[^}]*padding: 12px 22px|\.uc-portal \.uc-btn-new-event \{[^}]*padding: 12px 22px;[^}]*font-size: 16px/s', $css ) );
rt( 'PLANT N: reduced motion takes the lift and the tip away', (bool) preg_match( '/@media \(prefers-reduced-motion: reduce\) \{\s*\.uc-portal \.uc-btn-new-event \{ transition: background-color[^}]*\}\s*\.uc-portal \.uc-btn-new-event:hover,\s*\.uc-portal \.uc-btn-new-event:focus-visible \{ transform: none; \}/s', $css ) );

/* ---- G: green main actions. ---- */
$out = (string) shell_exec( 'php ' . escapeshellarg( __DIR__ . '/control-standard-audit.php' ) . ' 2>&1' );
rt( 'PLANT G: control-standard-audit passes, check 8 included: ' . substr( $out, -600 ), false !== strpos( $out, 'PASS. One definition per kind of control.' ) );
rt( 'G: Save draft is the primary', false !== strpos( $src, "\$save_main = \$keep_status ? ' uc-btn-go uc-editor-save-main' : ' uc-btn-primary';" ) );
rt( 'G: Sign In is the primary', false !== strpos( $src, '<button type="submit" class="uc-btn uc-btn-primary uc-btn-block">Sign In</button>' ) );
rt( 'G: Add organizer and Add venue are the add toggle', 3 === substr_count( $src, '<summary class="uc-add-toggle"' ) );
rt( 'G: a dialog\'s default confirm, Put it back on and tell them among them, is the primary', 2 === substr_count( $js, "(opts.sendClass || 'uc-btn-primary')" ) );

/* ---- W: Text and WhatsApp. ---- */
rt( 'PLANT W: the panel reads "What can I send by Text and WhatsApp?"', 1 === substr_count( $src, '</span>What can I send by Text and WhatsApp?</summary>' ) && false === strpos( $src, '</span>What can I send by WhatsApp?</summary>' ) );

/* ---- E: Email registrants with nobody. ---- */
$ev = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_title' => 'Support group', 'post_author' => 31 ) );
update_post_meta( $ev, '_uc_rsvp_enabled', '1' );
$GLOBALS['kit_db'] = function ( $m, $sql ) { return 'get_results' === $m ? array() : null; };
$rm = rt_cap( function () use ( $portal, $admin, $ev ) { kit_call( 'SFAF_Portal', 'render_registrant_mail', $portal, array( $admin, $ev ) ); } );
$x  = rt_x( $rm );
$nobody = $x->query( '//p[@data-uc-rm-nobody]' );
rt( 'PLANT E: with nobody registered, the line is there and the form is not', 0 !== strpos( $rm, 'THREW' ) && 1 === $nobody->length
    && 'Nobody has registered for this event yet.' === trim( $nobody->item( 0 )->textContent ) && 0 === $x->query( '//form[@data-uc-rm-form]' )->length );
rt( 'E: the line sits inside the panel\'s body, where the form would be', 1 === $x->query( '//details[@data-uc-registrant-mail]//div[contains(@class,"uc-registrant-mail-body")]/p[@data-uc-rm-nobody]' )->length );
$GLOBALS['kit_db'] = function ( $m, $sql ) use ( $ev ) {
    if ( 'get_results' === $m && false !== strpos( $sql, 'uc_rsvps' ) ) {
        return array( (object) array( 'id' => 1, 'event_id' => $ev, 'email' => 'ana@example.org', 'name' => 'Ana', 'first_name' => 'Ana', 'last_name' => '', 'status' => 'confirmed', 'phone' => '', 'text_channel' => '', 'lang' => 'en', 'token' => 't1', 'format' => '' ) );
    }
    return 'get_results' === $m ? array() : null;
};
$rm = rt_cap( function () use ( $portal, $admin, $ev ) { kit_call( 'SFAF_Portal', 'render_registrant_mail', $portal, array( $admin, $ev ) ); } );
$x  = rt_x( $rm );
rt( 'E: with somebody registered, the form is there and the line is not', 1 === $x->query( '//form[@data-uc-rm-form]' )->length && 0 === $x->query( '//p[@data-uc-rm-nobody]' )->length );
$GLOBALS['kit_db'] = null;

/* ---- T: Add event's team rule. ---- */
function rt_access( $portal, $u, $event_id = 0 ) {
    $GLOBALS['kit_user_id'] = $u->ID;
    $_GET = array();
    $h = rt_cap( function () use ( $portal, $u, $event_id ) { kit_call( 'SFAF_Portal', 'render_access_card', $portal, array( $u, $event_id, 0 ) ); } );
    return array( $h, rt_x( $h ) );
}
list( $h1, $x1 ) = rt_access( $portal, $ed1 );
$ticked = function ( $x ) { $o = array(); foreach ( $x->query( '//input[@name="access_teams[]"][@checked]' ) as $i ) { $o[] = $i->getAttribute( 'value' ); } sort( $o ); return $o; };
rt( 'PLANT T: on one team, that team is ticked from the start', 0 !== strpos( $h1, 'THREW' ) && array( 'housing' ) === $ticked( $x1 ) );
rt( 'T: and it stays a checkbox they can change, with nothing required', 2 === $x1->query( '//input[@name="access_teams[]"][not(@disabled)]' )->length
    && 0 === $x1->query( '//*[@data-uc-access-need-team]' )->length && 0 === $x1->query( '//input[@name="access_team_required"]' )->length );
rt( 'T: the series dropdown keeps it ticked', '["housing"]' === $x1->query( '//*[@data-uc-access]' )->item( 0 )->getAttribute( 'data-uc-access-mine' ) );
list( $h2, $x2 ) = rt_access( $portal, $ed2 );
rt( 'PLANT T: on two teams, none is ticked and one is asked for', array() === $ticked( $x2 ) && 1 === $x2->query( '//*[@data-uc-access-need-team]' )->length
    && 1 === $x2->query( '//input[@name="access_team_required"][@data-uc-add-only="team-rule"]' )->length
    && 1 === $x2->query( '//p[@data-uc-access-team-error][@hidden]' )->length
    && 'Choose a team before saving.' === trim( $x2->query( '//p[@data-uc-access-team-hint]' )->item( 0 )->textContent ) );
list( $h0, $x0 ) = rt_access( $portal, $ed0 );
rt( 'T: on no team, one is asked for', 1 === $x0->query( '//*[@data-uc-access-need-team]' )->length );
list( $hc, $xc ) = rt_access( $portal, $contr );
rt( 'T: a contributor\'s card is the read-only one, with no rule', 1 === $xc->query( '//*[@data-uc-access-readonly]' )->length && 0 === $xc->query( '//*[@data-uc-access-need-team]' )->length );
$owned = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'draft', 'post_title' => 'Owned', 'post_author' => 33 ) );
list( $he, $xe ) = rt_access( $portal, $ed2, $owned );
rt( 'T: Edit event has no rule', 0 === $xe->query( '//*[@data-uc-access-need-team]' )->length && ! $xe->query( '//*[@data-uc-access]' )->item( 0 )->hasAttribute( 'data-uc-access-mine' ) );
rt( 'PLANT T: portal.js stops the save without a team and says so', false !== strpos( $js, "var needTeam = box.hasAttribute('data-uc-access-need-team');" )
    && (bool) preg_match( "/form\.addEventListener\('submit', function \(e\) \{\s*if \(teamTicked\(\)\) \{ return; \}\s*e\.preventDefault\(\);\s*e\.stopPropagation\(\);/", $js ) );

$save = function ( $u, $extra ) use ( $portal ) {
    $GLOBALS['kit_user_id'] = $u->ID;
    $_GET  = array();
    $_POST = array_merge( array(
        'event_id' => 0, 'save_mode' => 'publish', 'title' => 'Team night', 'date' => date( 'Y-m-d', strtotime( '+10 days' ) ),
        'start_time' => '18:00', 'end_time' => '19:00', 'uc_organizer_present' => '1', 'organizer' => array( '11' ),
        'uc_category_present' => '1', 'category' => array( '11' ), 'description' => '<p>Come.</p>',
        'uc_online_present' => '1', 'uc_online' => '0', 'location_mode' => 'custom', 'location_street' => '1035 Market St', 'location_city' => 'San Francisco',
        'access_present' => '1',
    ), $extra );
    $GLOBALS['kit_redirect_throws'] = true;
    try { return kit_call( 'SFAF_Portal', 'save_event_from_post', $portal, array( $u ) ); } catch ( Throwable $t ) { return array( 'id' => 0, 'msg' => 'THREW ' . $t->getMessage() ); }
};
$r = $save( $ed2, array() );
rt( 'PLANT T: a save with no team, from somebody on two, is held as a draft and says why: ' . json_encode( $r ), (int) $r['id'] > 0 && 'team_required' === $r['msg'] && 'draft' === get_post_status( (int) $r['id'] ) );
$r = $save( $ed2, array( 'access_teams' => array( 'harm' ) ) );
rt( 'T: with a team ticked it goes through: ' . json_encode( $r ), (int) $r['id'] > 0 && 'team_required' !== $r['msg'] );
$r = $save( $ed1, array() );
rt( 'T: somebody on one team is not held for unticking it: ' . json_encode( $r ), (int) $r['id'] > 0 && 'team_required' !== $r['msg'] );

/* ---- C: the creator. ---- */
$r   = $save( $ed1, array( 'access_teams' => array( 'housing' ) ) );
$cid = (int) $r['id'];
rt( 'PLANT C: a new event records who made it', (string) 32 === (string) get_post_meta( $cid, SFAF_Access::CREATOR_META, true ) && 32 === SFAF_Access::creator_id( $cid ) );
SFAF_Access::record_creator( $cid, 36 );
rt( 'C: and a second record changes nothing', 32 === SFAF_Access::creator_id( $cid ) );
$GLOBALS['kit_posts'][ $cid ]->post_author = 31; // a reassign
rt( 'C: a reassign moves the author, not the creator', 32 === SFAF_Access::creator_id( $cid ) );
list( $hv, $xv ) = rt_access( $portal, $viewer, $cid );
$line = trim( preg_replace( '/\s+/', ' ', $xv->query( '//p[contains(@class,"uc-access-organizer")]' )->item( 0 )->textContent ) );
rt( 'PLANT C: Team and access names the creator to somebody else, never the viewer: ' . $line, 'Eli One created this event.' === $line && false === strpos( $hv, 'Val Viewer created' ) );
rt( 'C: and names who can always edit it apart', 1 === $xv->query( '//p[@data-uc-access-owner]' )->length && false !== strpos( $xv->query( '//p[@data-uc-access-owner]' )->item( 0 )->textContent, 'Ada Admin' ) );
$GLOBALS['kit_posts'][ $cid ]->post_author = 32;
list( $hv, $xv ) = rt_access( $portal, $viewer, $cid );
rt( 'C: with the author the creator, one line', 'Eli One created this event and can always edit it.' === trim( preg_replace( '/\s+/', ' ', $xv->query( '//p[contains(@class,"uc-access-organizer")]' )->item( 0 )->textContent ) ) && 0 === $xv->query( '//p[@data-uc-access-owner]' )->length );

$imp = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_title' => 'Imported', 'post_author' => 36 ) );
update_post_meta( $imp, SFAF_Sources::META_SOURCE, 'everyaction' );
rt( 'PLANT C: an import before the record is not its fetcher\'s', 0 === SFAF_Access::creator_id( $imp ) && 'import' === SFAF_Access::creator( $imp )['kind'] );
list( $hi, $xi ) = rt_access( $portal, $viewer, $imp );
$iline = trim( preg_replace( '/\s+/', ' ', $xi->query( '//p[contains(@class,"uc-access-organizer")]' )->item( 0 )->textContent ) );
rt( 'C: Team and access says Imported from the platform: ' . $iline, 0 === strpos( $iline, 'Imported from ' ) && false === strpos( $iline, 'Val Viewer created' ) );
$req = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_title' => 'Requested', 'post_author' => 36 ) );
update_post_meta( $req, SFAF_Submissions::META_KIND, SFAF_Submissions::KIND_STAFF );
update_post_meta( $req, SFAF_Request::META_NAME, 'Pat Reed' );
update_post_meta( $req, SFAF_Request::META_EMAIL, 'pat.reed@sfaf.org' );
rt( 'PLANT C: a request saved in WordPress\'s editor is still the requester\'s', 'request' === SFAF_Access::creator( $req )['kind'] && 'Pat Reed' === SFAF_Access::creator( $req )['name'] );
list( $hq, $xq ) = rt_access( $portal, $viewer, $req );
rt( 'C: Team and access names the requester', 0 === strpos( trim( preg_replace( '/\s+/', ' ', $xq->query( '//p[contains(@class,"uc-access-organizer")]' )->item( 0 )->textContent ) ), 'Pat Reed asked for this event' ) );

update_option( 'uc_settings', array( 'email_reply_to' => 'events@sfaf.org' ) );
rt( 'PLANT R: replies go to the creator', 'eli@sfaf.org' === SFAF_Reminders::reply_to_for( $cid ) );
rt( 'R: an import\'s go to the site, not its fetcher', 'events@sfaf.org' === SFAF_Reminders::reply_to_for( $imp ) );
rt( 'R: a request\'s go to the requester', 'pat.reed@sfaf.org' === SFAF_Reminders::reply_to_for( $req ) );
$GLOBALS['kit_user_id'] = 36;
$_GET = array();
$pick = rt_cap( function () use ( $portal, $viewer, $cid ) { kit_call( 'SFAF_Portal', 'render_rsvp_field', $portal, array( 'replyto', $viewer, $cid, function ( $k ) use ( $cid ) { return get_post_meta( $cid, $k, true ); } ) ); } );
if ( 0 === strpos( $pick, 'THREW' ) ) {
    $pick = rt_cap( function () use ( $portal, $viewer, $cid ) { kit_call( 'SFAF_Portal', 'render_event_form', $portal, array( $viewer, $cid ) ); } );
}
$xp  = rt_x( $pick );
$opt = $xp->query( '//select[@name="event_replyto_pick"]/option[@value=""]' );
rt( 'PLANT R: Replies go to\'s first choice is the creator, to anybody looking: ' . ( $opt->length ? $opt->item( 0 )->textContent : 'none' ), 1 === $opt->length && 'Eli One (creator), eli@sfaf.org' === trim( $opt->item( 0 )->textContent ) );

update_post_meta( $cid, '_uc_email_replyto', 'eli@sfaf.org' );
$GLOBALS['kit_user_id'] = 33;
$copy = kit_call( 'SFAF_Portal', 'duplicate_event', $portal, array( $ed2, $cid ) );
$copy = is_array( $copy ) ? (int) ( $copy['id'] ?? 0 ) : (int) $copy;
rt( 'PLANT D: a copy is the copier\'s and does not carry the original creator\'s address', $copy > 0 && 33 === SFAF_Access::creator_id( $copy ) && '' === (string) get_post_meta( $copy, '_uc_email_replyto', true ) );
update_post_meta( $cid, '_uc_email_replyto', 'group@sfaf.org' );
$copy2 = kit_call( 'SFAF_Portal', 'duplicate_event', $portal, array( $ed2, $cid ) );
$copy2 = is_array( $copy2 ) ? (int) ( $copy2['id'] ?? 0 ) : (int) $copy2;
rt( 'D: a chosen address travels with the copy', 'group@sfaf.org' === (string) get_post_meta( $copy2, '_uc_email_replyto', true ) );

/* Schema 19. */
$GLOBALS['kit_meta_backup'] = $GLOBALS['kit_meta'];
$old_hand = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_title' => 'Old hand-made', 'post_author' => 32 ) );
update_post_meta( $old_hand, '_uc_email_replyto', 'ELI@sfaf.org' );
$old_imp = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'pending', 'post_title' => 'Old import', 'post_author' => 36 ) );
update_post_meta( $old_imp, SFAF_Sources::META_SOURCE, 'everyaction' );
update_post_meta( $old_imp, '_uc_email_replyto', 'val@sfaf.org' );
$old_pick = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_title' => 'Old chosen', 'post_author' => 32 ) );
update_post_meta( $old_pick, '_uc_email_replyto', 'group@sfaf.org' );
$GLOBALS['kit_query'] = function ( $a ) { return array( $GLOBALS['rt_old'][0], $GLOBALS['rt_old'][1], $GLOBALS['rt_old'][2] ); };
$GLOBALS['rt_old'] = array( $old_hand, $old_imp, $old_pick );
sfaf_migrate_creators();
$rep = get_option( 'sfaf_creator_migration' );
rt( 'PLANT M: schema 19 records each creator, an import\'s as nobody', 32 === SFAF_Access::creator_id( $old_hand ) && '0' === (string) get_post_meta( $old_imp, SFAF_Access::CREATOR_META, true ) && 32 === SFAF_Access::creator_id( $old_pick ) );
rt( 'PLANT M: it clears "the creator" and the fetcher\'s address, and keeps a chosen one', '' === (string) get_post_meta( $old_hand, '_uc_email_replyto', true )
    && '' === (string) get_post_meta( $old_imp, '_uc_email_replyto', true ) && 'group@sfaf.org' === (string) get_post_meta( $old_pick, '_uc_email_replyto', true ) );
rt( 'M: and reports what it did: ' . json_encode( $rep ), is_array( $rep ) && 3 === $rep['recorded'] && array( $old_hand ) === $rep['replyto_creator'] && array( $old_imp ) === $rep['replyto_viewer']
    && array( $old_pick ) === $rep['replyto_kept'] && array( $old_imp ) === $rep['author_not_maker'] && ! empty( $rep['at'] ) );
update_post_meta( $old_pick, SFAF_Access::CREATOR_META, '31' );
rt( 'M: once, under its own option', 0 === sfaf_migrate_creators() && 31 === SFAF_Access::creator_id( $old_pick ) );
// 19 or later: 3.144.0 moved it to 20, and the schema 19 pass must still run.
rt( 'M: schema 19 is the version and runs it', (int) SFAF_DB_VERSION >= 19 && false !== strpos( (string) file_get_contents( $root . '/sfaf-calendar.php' ), "    sfaf_migrate_creators();\n" ) );
$GLOBALS['kit_query'] = null;

/* ---- P: published with no picture. ---- */
$GLOBALS['rt_mail'] = array();
$asana = SFAF_Asana::messages();
rt( 'P: Asana has the message, ticked on until Settings says otherwise', isset( $asana['nopicture'] ) && SFAF_Asana::enabled( 'nopicture' ) );
rt( 'P: it is listened for on the move to Published', false !== strpos( (string) file_get_contents( $root . '/sfaf-calendar.php' ), 'SFAF_No_Picture::register();' ) );
$mk = function ( $title, $series, $status = 'publish' ) {
    $id = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => $status, 'post_title' => $title, 'post_author' => 32 ) );
    update_post_meta( $id, '_uc_event_date', date( 'Y-m-d', strtotime( '+12 days' ) ) );
    SFAF_Access::record_creator( $id, 32 );
    if ( $series ) { $GLOBALS['kit_terms'][ $id ]['uc_series'] = array( $series ); }
    return $id;
};
$move = function ( $ids ) {
    foreach ( $ids as $id ) { $p = get_post( $id ); SFAF_No_Picture::note( 'publish', 'draft', $p ); }
    return SFAF_No_Picture::flush();
};
$GLOBALS['rt_mail'] = array();
$s1 = array( $mk( 'Walk 1', 11 ), $mk( 'Walk 2', 11 ), $mk( 'Walk 3', 11 ) );
$sent = $move( $s1 );
$admins = count( SFAF_Portal::admin_recipients() );
$subj   = array_map( function ( $m ) { return $m['subject']; }, $GLOBALS['rt_mail'] );
rt( 'PLANT P: three dates of a series with no picture are one message per admin and one Asana copy: ' . json_encode( $subj ), 1 === count( $sent ) && 11 === $sent[0]['series']
    && $admins + 1 === count( $GLOBALS['rt_mail'] ) && 1 === count( array_unique( $subj ) ) && 0 === strpos( $subj[0], 'Published with no picture: ' ) );
rt( 'P: the Asana copy went to the Asana address', in_array( SFAF_Asana::address(), array_map( function ( $m ) { return $m['to']; }, $GLOBALS['rt_mail'] ), true ) );
$body = $GLOBALS['rt_mail'][0]['body'];
rt( 'P: it names the series, the dates, who added them and links to the series', false !== strpos( $body, '3 dates published' ) && false !== strpos( $body, 'Eli One' ) && false !== strpos( $body, 'series/edit/11' ) );
$GLOBALS['rt_mail'] = array();
$more = $move( array( $mk( 'Walk 4', 11 ) ) );
rt( 'PLANT P: a later date of the same series sends nothing', array() === $more && array() === $GLOBALS['rt_mail'] );
$GLOBALS['kit_images'][ 901 ] = 'https://resources.sfaf.org/wp-content/uploads/calendar/s12.jpg';
update_term_meta( 12, SFAF_Series::META_IMAGE_URL, 'https://resources.sfaf.org/wp-content/uploads/calendar/s12.jpg' );
$GLOBALS['rt_mail'] = array();
$withs = $move( array( $mk( 'Lunch', 12 ) ) );
rt( 'PLANT P: an event with no picture of its own in a series with one sends nothing', '' !== sfaf_series_image_url( 12 ) ? ( array() === $withs && array() === $GLOBALS['rt_mail'] ) : false );
$own = $mk( 'Own picture', 0 );
update_post_meta( $own, '_uc_image_url', 'https://example.org/p.jpg' );
update_post_meta( $own, '_uc_image_url_typed', 'https://example.org/p.jpg' );
$GLOBALS['rt_mail'] = array();
rt( 'P: an event with its own picture sends nothing', array() === $move( array( $own ) ) && array() === $GLOBALS['rt_mail'] );
$draft = $mk( 'Still a draft', 0, 'draft' );
$GLOBALS['rt_mail'] = array();
SFAF_No_Picture::note( 'draft', 'new', get_post( $draft ) );
SFAF_No_Picture::note( 'pending', 'draft', get_post( $draft ) );
rt( 'PLANT P: a draft or a pending event sends nothing', array() === SFAF_No_Picture::flush() && array() === $GLOBALS['rt_mail'] );
$solo = $mk( 'Pop-up clinic', 0 );
$GLOBALS['rt_mail'] = array();
$one  = $move( array( $solo ) );
$b    = isset( $GLOBALS['rt_mail'][0] ) ? $GLOBALS['rt_mail'][0]['body'] : '';
rt( 'PLANT P: an event in no series is its own message, with its name, who added it and its edit link', 1 === count( $one ) && 0 === $one[0]['series']
    && 'Published with no picture: Pop-up clinic' === $GLOBALS['rt_mail'][0]['subject'] && false !== strpos( $b, 'Eli One' ) && false !== strpos( $b, 'events/edit/' . $solo ) );
$GLOBALS['rt_mail'] = array();
rt( 'P: and not twice', array() === $move( array( $solo ) ) && array() === $GLOBALS['rt_mail'] );

/* ---- A: one arrow. ---- */
rt( 'PLANT A: portal.css takes the browser\'s triangle off a summary with the chevron', false !== strpos( $css, ':where(summary:has(> .uc-disclosure-chevron)) { list-style: none; }' )
    && false !== strpos( $css, ':where(summary:has(> .uc-disclosure-chevron))::-webkit-details-marker { display: none; }' ) );
foreach ( array( 'uc-schedule-edit' => 'Change the pattern', 'uc-schedule-extend' => 'Extend the series', 'uc-schedule-add' => 'onto another date', 'uc-schedule-pastfold' => 'past' ) as $cls => $words ) {
    rt( "A: $words is a summary with the chevron as its first child", (function () use ( $src, $cls ) {
        $at = strpos( $src, '<details class="' . $cls . '">' );
        if ( false === $at ) { return false; }
        $chunk = substr( $src, $at, 4000 );
        $sum   = strpos( $chunk, '<summary>' );
        return false !== $sum && (bool) preg_match( '/^<summary>\s*<span class="uc-disclosure-chevron"/', substr( $chunk, $sum, 200 ) );
    })() );
}

echo "\n3.141.0: green main actions, + New Event, Text and WhatsApp, nobody registered, the team rule, the creator, no picture, one arrow\n" . str_repeat( '=', 72 ) . "\n";
echo "release-31410: $n checks\n";
if ( $fails ) { foreach ( $fails as $f ) { echo "FAIL  $f\n"; } exit( 1 ); }
echo "every check passed.\n";
exit( 0 );
