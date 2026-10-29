<?php
/**
 * 3.129.0: THE NOTIFICATIONS CARD, TIDIED (Add event and Edit event).
 *
 *     php .claude/release-31290-test.php
 *
 * The real render_notify_box(), render_rsvp_setting() and the save, on
 * wp-kit.php.
 *
 *   A. One field, "Who gets these emails": the creator, the people, the team
 *      and the typed address chosen are ticked chips under the names the save
 *      has always read; everybody else is an unticked box in the fold; one
 *      box to type in. No creator tick line, Choose people, Anyone else, Add
 *      button or "Nobody else yet.".
 *   B. Nothing stored changes meaning: posting the card as drawn stores what
 *      was there, a chip unticked comes off, the creator unticked is the
 *      opt-out, a typed address is added, and a chosen person who is no
 *      longer a calendar person is kept rather than dropped.
 *   C. The emails in two groups: Sent to registrants (Confirmation, Morning-of
 *      reminder, each Always sent, no box), then Sent to the people above
 *      (the four with boxes).
 *   D. Replies go to: one labelled field, no Replies heading; the creator
 *      first, calendar people, Someone else last; the line under it word for
 *      word; and each choice saves as the old box did.
 *   E. Add event: the creator chip is whoever is adding it.
 *
 * release-31290-live.php types into the field in Chrome. PLANTs in
 * plant-31290.php.
 */

require __DIR__ . '/wp-kit.php';

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
function rt_text( $n ) { return trim( preg_replace( '/\s+/', ' ', $n ? $n->textContent : '' ) ); }

kit_reset();
$portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();
$me = new WP_User();

// Three calendar people beside the creator, and one chosen person who is no longer one.
$GLOBALS['kit_users'] = array( $me );
foreach ( array( 21 => 'Ana Ruiz', 22 => 'Ben Young', 23 => 'Cy Ortiz', 24 => 'Dee Former' ) as $uid => $name ) {
    $u = new WP_User(); $u->ID = $uid; $u->display_name = $name; $u->user_login = strtolower( strtok( $name, ' ' ) );
    $u->user_email = strtolower( strtok( $name, ' ' ) ) . '@sfaf.org';
    $GLOBALS['kit_users'][] = $u;
    if ( 24 !== $uid ) { $GLOBALS['kit_umeta'][ $uid ]['_uc_calendar_role'] = 'editor'; }
}
$GLOBALS['kit_umeta'][ $me->ID ]['_uc_calendar_role'] = 'admin';
update_option( SFAF_Teams::OPTION, array(
    'tprog' => array( 'name' => 'Programs', 'users' => array( 22, 23 ) ),
    'tdev'  => array( 'name' => 'Development', 'users' => array( 21 ) ),
) );

$ev = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_title' => 'Coffee social', 'post_author' => $me->ID ) );
update_post_meta( $ev, '_uc_event_date', '2026-12-03' );
update_post_meta( $ev, SFAF_Reminders::NOTIFY_USERS_META, array( 21, 24 ) );
update_post_meta( $ev, SFAF_Reminders::NOTIFY_EMAILS_META, array( 'board@example.org' ) );
SFAF_Teams::set_for_event( $ev, array( 'tprog' ) );

$card = function ( $id ) use ( $portal, $me ) {
    return rt_cap( function () use ( $portal, $me, $id ) { $_GET = array(); kit_call( 'SFAF_Portal', 'render_notify_box', $portal, array( $me, $id ) ); } );
};
$h = $card( $ev );
rt( 'A: the card rendered: ' . substr( $h, 0, 200 ), 0 !== strpos( $h, 'THREW' ) );
$x = rt_x( $h );

/* ---- A ---------------------------------------------------------------- */
$field = $x->query( '//*[@data-uc-recipients]' )->item( 0 );
rt( 'PLANT A: no Who gets these emails field', null !== $field );
$label = $field ? $x->query( './/label[contains(@class,"uc-field-label")]', $field )->item( 0 ) : null;
$input = $field ? $x->query( './/input[@data-uc-rcpt-input]', $field )->item( 0 ) : null;
rt( 'A: the field is labelled "Who gets these emails", for its box', $label && 'Who gets these emails' === rt_text( $label ) && $input && $label->getAttribute( 'for' ) === $input->getAttribute( 'id' ) );
rt( 'A: the box is notify_email_new, a combobox over its list', $input && 'notify_email_new' === $input->getAttribute( 'name' ) && 'combobox' === $input->getAttribute( 'role' )
    && 1 === $x->query( '//ul[@id="' . $input->getAttribute( 'aria-controls' ) . '"][@role="listbox"]' )->length );
$chips = array();
foreach ( $x->query( '//*[@data-uc-rcpt-chips]//input[@data-uc-rcpt-box]' ) as $b ) {
    $chips[] = $b->getAttribute( 'name' ) . '=' . $b->getAttribute( 'value' ) . ( $b->hasAttribute( 'checked' ) ? '' : ' (unticked)' );
}
$want = array( 'notify_author=1', 'notify_users[]=21', 'notify_users[]=24', 'notify_teams[]=tprog', 'notify_emails[]=board@example.org' );
rt( 'PLANT A: the chips are ' . json_encode( $chips ), $want === $chips );
$pool = array();
foreach ( $x->query( '//*[@data-uc-rcpt-pool]//input[@data-uc-rcpt-box]' ) as $b ) {
    $pool[] = $b->getAttribute( 'name' ) . '=' . $b->getAttribute( 'value' ) . ( $b->hasAttribute( 'checked' ) ? ' (ticked)' : '' );
}
rt( 'PLANT A: the fold holds ' . json_encode( $pool ), array( 'notify_users[]=22', 'notify_users[]=23', 'notify_teams[]=tdev' ) === $pool );
$creator = $x->query( '//*[@data-uc-rcpt-chips]//*[@data-uc-rcpt="author"]' )->item( 0 );
rt( 'A: the creator chip reads "' . rt_text( $creator ) . '"', $creator && 0 === strpos( rt_text( $creator ), $me->display_name . ', creator' ) );
$team = $x->query( '//*[@data-uc-rcpt-chips]//*[@data-uc-rcpt="team"]' )->item( 0 );
rt( 'A: the team chip reads "' . rt_text( $team ) . '"', $team && 0 === strpos( rt_text( $team ), 'Programs, team, 2 people' ) );
$gone = array( 'Choose people', 'Anyone else', 'Nobody else yet', 'Send to ', 'Who hears about this event', 'Choose emails', 'Emails for this event', 'data-uc-email-add', 'data-uc-picker' );
$left = array_values( array_filter( $gone, function ( $g ) use ( $h ) { return false !== strpos( $h, $g ); } ) );
rt( 'PLANT A: the old controls are still drawn: ' . json_encode( $left ), ! $left );
rt( 'A: the markers the save reads are there', 1 === substr_count( $h, 'name="notify_list_present"' ) && 1 === substr_count( $h, 'name="notify_teams_present"' ) );

/* ---- B: post the card as drawn ---------------------------------------- */
$post_of = function ( $h, $untick = array(), $extra = array() ) {
    $x = rt_x( $h );
    $p = array();
    foreach ( $x->query( '//input[@name]' ) as $i ) {
        $n = $i->getAttribute( 'name' ); $t = $i->getAttribute( 'type' );
        if ( in_array( $t, array( 'checkbox', 'radio' ), true ) && ( ! $i->hasAttribute( 'checked' ) || in_array( $n . '=' . $i->getAttribute( 'value' ), $untick, true ) ) ) { continue; }
        $v = $i->getAttribute( 'value' );
        if ( '[]' === substr( $n, -2 ) ) { $p[ substr( $n, 0, -2 ) ][] = $v; } else { $p[ $n ] = $v; }
    }
    foreach ( $x->query( '//select[@name]' ) as $sel ) {
        $o = $x->query( './/option[@selected]', $sel )->item( 0 ) ?: $x->query( './/option', $sel )->item( 0 );
        $p[ $sel->getAttribute( 'name' ) ] = $o ? $o->getAttribute( 'value' ) : '';
    }
    return array_merge( $p, $extra );
};
$save = function ( $post ) use ( $portal, $me, $ev ) {
    $_POST = $post;
    $r = rt_cap( function () use ( $portal, $me, $ev ) { kit_call( 'SFAF_Portal', 'save_rsvp_settings_from_post', $portal, array( $me, $ev, function () { return false; } ) ); } );
    $_POST = array();
    return $r;
};
$stored = function () use ( $ev ) {
    return array(
        'optout' => SFAF_Reminders::author_opted_out( $ev ),
        'users'  => array_map( 'intval', (array) get_post_meta( $ev, SFAF_Reminders::NOTIFY_USERS_META, true ) ),
        'teams'  => SFAF_Teams::for_event( $ev ),
        'emails' => array_values( (array) get_post_meta( $ev, SFAF_Reminders::NOTIFY_EMAILS_META, true ) ),
    );
};
$before = $stored();
$r = $save( $post_of( $h ) );
rt( 'B: the save threw: ' . $r, false === strpos( $r, 'THREW' ) );
rt( 'PLANT B: the card saved as drawn changes what is stored: ' . json_encode( array( $before, $stored() ) ), $before === $stored() );
rt( 'B: and keeps Dee, chosen but no longer a calendar person', in_array( 24, $stored()['users'], true ) );
$save( $post_of( $card( $ev ), array( 'notify_author=1', 'notify_users[]=21', 'notify_teams[]=tprog', 'notify_emails[]=board@example.org' ), array( 'notify_email_new' => 'cohost@example.org' ) ) );
$after = $stored();
rt( 'PLANT B: unticking the chips and typing an address stores ' . json_encode( $after ),
    array( 'optout' => true, 'users' => array( 24 ), 'teams' => array(), 'emails' => array( 'cohost@example.org' ) ) === $after );
$h2 = $card( $ev );
$x2 = rt_x( $h2 );
$creator_in_fold = $x2->query( '//*[@data-uc-rcpt-pool]//input[@name="notify_author"][not(@checked)]' )->length;
rt( 'B: the creator taken off waits in the fold, unticked', 1 === $creator_in_fold );
$save( $post_of( $h2, array(), array( 'notify_author' => '1', 'notify_users' => array( '24', '22' ), 'notify_teams' => array( 'tdev' ) ) ) );
rt( 'B: ticking from the fold puts them back: ' . json_encode( $stored() ),
    array( 'optout' => false, 'users' => array( 24, 22 ), 'teams' => array( 'tdev' ), 'emails' => array( 'cohost@example.org' ) ) === $stored() );

/* ---- C ---------------------------------------------------------------- */
$x = rt_x( $card( $ev ) );
$kinds = $x->query( '//*[@data-uc-notify-kinds]' )->item( 0 );
$seq = array();
if ( $kinds ) {
    foreach ( $x->query( './*', $kinds ) as $n ) {
        $cls = ' ' . $n->getAttribute( 'class' ) . ' ';
        if ( 'h4' === $n->nodeName ) { $seq[] = 'H:' . rt_text( $n ); }
        elseif ( false !== strpos( $cls, ' uc-notify-always ' ) ) { $seq[] = 'A:' . rt_text( $n ); }
        elseif ( 'label' === $n->nodeName ) { $b = $x->query( './/input', $n )->item( 0 ); $seq[] = 'T:' . ( $b ? $b->getAttribute( 'value' ) : '?' ); }
    }
}
rt( 'PLANT C: the emails read ' . json_encode( $seq ), array(
    'H:Sent to registrants', 'A:Confirmation Always sent', 'A:Morning-of reminder Always sent',
    'H:Sent to the people above', 'T:alert', 'T:day_before', 'T:summary', 'T:cancel_alert',
) === $seq );
rt( 'C: no box under Sent to registrants', 0 === $x->query( '//input[@name="notify_kinds[]"][@value="confirmation" or @value="reminder"]' )->length );
rt( 'C: the emails are shown, not folded', 0 === $x->query( '//*[@data-uc-notify-kinds]//details' )->length && false === strpos( $card( $ev ), 'data-uc-notify-kinds-status' ) );

/* ---- D: Replies go to --------------------------------------------------- */
$reply = function ( $id ) use ( $portal, $me ) {
    return rt_cap( function () use ( $portal, $me, $id ) {
        $ctx = kit_call( 'SFAF_Portal', 'rsvp_settings_context', $portal, array( $me, $id ) );
        kit_call( 'SFAF_Portal', 'render_rsvp_settings', $portal, array( $ctx, array( 'replyto' ), array() ) );
    } );
};
$hr = $reply( $ev );
$x  = rt_x( $hr );
$f  = $x->query( '//*[@data-uc-replyto]' )->item( 0 );
rt( 'D: the field rendered: ' . substr( $hr, 0, 300 ), null !== $f );
rt( 'PLANT D: a Replies heading is still drawn', false === strpos( $hr, '>Replies</h4>' ) );
$lab = $f ? $x->query( './/label[contains(@class,"uc-field-label")]', $f )->item( 0 ) : null;
rt( 'D: one label, "Replies go to", for the picker', $lab && 'Replies go to' === rt_text( $lab ) && 'uc-event-replyto-pick' === $lab->getAttribute( 'for' ) );
$opts = array();
foreach ( $x->query( '//select[@name="event_replyto_pick"]/option' ) as $o ) { $opts[] = array( $o->getAttribute( 'value' ), rt_text( $o ) ); }
rt( 'PLANT D: the options are ' . json_encode( $opts ), array(
    array( '', $me->display_name . ' (creator), ' . $me->user_email ),
    array( 'ana@sfaf.org', 'Ana Ruiz, ana@sfaf.org' ), array( 'ben@sfaf.org', 'Ben Young, ben@sfaf.org' ), array( 'cy@sfaf.org', 'Cy Ortiz, cy@sfaf.org' ),
    array( '__other', 'Someone else' ),
) === $opts );
$line = $f ? $x->query( './/p[contains(@class,"uc-hint")]', $f )->item( 0 ) : null;
rt( 'PLANT D: the line under it is ' . json_encode( rt_text( $line ) ), 'Email replies come here. Anyone who replies to a text or WhatsApp is asked to email this address.' === rt_text( $line ) );
rt( 'D: the box for Someone else is there with no script', 1 === $x->query( '//input[@type="email"][@name="event_replyto"]' )->length );

$set = function ( $post ) use ( $portal, $me, $ev ) {
    $_POST = $post;
    kit_call( 'SFAF_Portal', 'save_rsvp_settings_from_post', $portal, array( $me, $ev, function () { return false; } ) );
    $_POST = array();
    return (string) get_post_meta( $ev, '_uc_email_replyto', true );
};
rt( 'PLANT D: choosing Ben stores his address', 'ben@sfaf.org' === $set( array( 'event_replyto_pick' => 'ben@sfaf.org', 'event_replyto' => '' ) ) );
rt( 'D: and he is chosen when it opens again', 1 === rt_x( $reply( $ev ) )->query( '//option[@value="ben@sfaf.org"][@selected]' )->length );
rt( 'PLANT D: choosing the creator stores nothing of its own', '' === $set( array( 'event_replyto_pick' => '', 'event_replyto' => 'stale@example.org' ) ) );
rt( 'D: Someone else stores the box', 'team@example.org' === $set( array( 'event_replyto_pick' => '__other', 'event_replyto' => 'team@example.org' ) ) );
$xr = rt_x( $reply( $ev ) );
rt( 'D: and opens on Someone else with the box filled', 1 === $xr->query( '//option[@value="__other"][@selected]' )->length && 1 === $xr->query( '//input[@name="event_replyto"][@value="team@example.org"]' )->length );
rt( 'D: Someone else with a non-address keeps what was stored', 'team@example.org' === $set( array( 'event_replyto_pick' => '__other', 'event_replyto' => 'not an address' ) ) );
rt( 'D: a form with only the box saves as before', 'old@example.org' === $set( array( 'event_replyto' => 'old@example.org' ) ) );

/* ---- E: Add event ----------------------------------------------------- */
$x = rt_x( $card( 0 ) );
$c = $x->query( '//*[@data-uc-rcpt-chips]//*[@data-uc-rcpt="author"]' )->item( 0 );
rt( 'PLANT E: on Add event the creator chip is whoever is adding it', $c && 0 === strpos( rt_text( $c ), $me->display_name . ', creator' ) && 1 === $x->query( '//*[@data-uc-rcpt-chips]//input[@name="notify_author"][@checked]' )->length );
rt( 'E: and nobody else is chosen', 1 === $x->query( '//*[@data-uc-rcpt-chips]//input[@data-uc-rcpt-box]' )->length );

/* ---- the script --------------------------------------------------------- */
$js = (string) file_get_contents( $GLOBALS['kit_root'] . '/public/js/portal.js' );
rt( 'the script runs both', false !== strpos( $js, "run('recipients', initRecipients);" ) && false !== strpos( $js, "run('replyTo', initReplyTo);" ) );
rt( 'the old scripts are gone', false === strpos( $js, 'function initNotifyPicker(' ) && false === strpos( $js, 'function initEmailPills(' ) && false === strpos( $js, 'function initNotifyKinds(' ) );

echo "\n3.129.0: the Notifications card\n" . str_repeat( '=', 72 ) . "\n";
if ( $fails ) { echo count( $fails ) . " of $ran failed:\n  - " . implode( "\n  - ", $fails ) . "\n"; exit( 1 ); }
echo "$ran assertions passed. One field holds the creator, the people, the teams and the addresses as chips that save as before;\nthe emails in two groups; Replies go to a picker with the creator first and the line under it.\n";
exit( 0 );
