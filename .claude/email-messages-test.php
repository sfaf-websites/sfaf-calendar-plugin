<?php
/**
 * EVERY REGISTRANT MESSAGE, IN BOTH LANGUAGES, AS IT GOES OUT (3.106.0).
 *
 *     php .claude/email-messages-test.php
 *
 * Through wp-kit.php, so the catalogue, the builders, the formatter and the
 * resolver are the plugin's own.
 *
 *   D.6  every message and variant renders in English and Spanish with no
 *        token left in braces and no em dash, in the subject, the HTML and the
 *        text; the Templates screen renders through render_sample(), which
 *        calls the builders that send.
 *   C    an event's language is its own, else its series', else English; a
 *        Spanish event's confirmation is Spanish with Spanish dates, and the
 *        staff alert for it stays English.
 *   F    the donate line is in the confirmation and the reminder, after the
 *        details and before the cancel line, linked to the resolved campaign;
 *        absent for None; in no other message, and not in the staff copy.
 *   D    a saved override is what renders; an unknown token is refused; reset
 *        gives back the shipped text; the Settings screen's old text moves
 *        into the English templates once, with its tokens renamed.
 */

require __DIR__ . '/wp-kit.php';

$fails = array();
function em( $label, $got, $want ) {
    global $fails;
    if ( $got !== $want ) { $fails[] = $label . ': got ' . var_export( $got, true ) . ', wanted ' . var_export( $want, true ); }
}

/* ---- D.6: every message, both languages. ---------------------------------- */
$count = 0;
foreach ( SFAF_Messages::catalog() as $key => $m ) {
    foreach ( array_keys( $m['variants'] ) as $variant ) {
        foreach ( array( 'en', 'es' ) as $lang ) {
            $r = SFAF_Messages::render_sample( $key, $variant, $lang );
            $count++;
            foreach ( array( 'subject', 'html', 'text' ) as $part ) {
                $v = (string) $r[ $part ];
                if ( preg_match( '/\{[a-z_]+\}/', $v, $mm ) ) {
                    $fails[] = "PLANT D.6: $key/$variant/$lang $part leaves $mm[0] unresolved";
                }
                if ( false !== strpos( $v, "\xE2\x80\x94" ) ) {
                    $fails[] = "PLANT D.6: $key/$variant/$lang $part has an em dash";
                }
            }
            if ( 'email' === $m['kind'] && '' === trim( $r['subject'] ) ) {
                $fails[] = "$key/$variant/$lang has no subject";
            }
            if ( 'email' === $m['kind'] && 'follow_confirm' !== $key && 'several' !== $variant ) {
                $month = ( 'es' === $lang ) ? 'noviembre' : 'November';
                em( "$key/$variant/$lang says the date in its language", false !== strpos( $r['text'], $month ), true );
            }
        }
    }
}
em( 'every message and variant rendered in both languages', $count >= 50, true );

/* The shipped text uses only the tokens its message can fill. */
$cat = SFAF_Messages::catalog();
foreach ( SFAF_Messages::defaults() as $key => $variants ) {
    foreach ( $variants as $variant => $langs ) {
        foreach ( $langs as $lang => $t ) {
            preg_match_all( '/\{([a-z_]+)\}/', implode( ' ', $t ), $mm );
            $bad = array_diff( array_unique( $mm[1] ), $cat[ $key ]['tokens'] );
            if ( $bad ) {
                $fails[] = "PLANT D.6: the shipped $key/$variant/$lang uses {" . implode( '}, {', $bad ) . '}, which it cannot fill';
            }
        }
    }
}

/* The screen, EMAILS.md and sending share the builders. */
$root = dirname( __DIR__ );
$port = (string) file_get_contents( $root . '/includes/class-sfaf-portal.php' );
$msgs = (string) file_get_contents( $root . '/includes/class-sfaf-messages.php' );
$wait = (string) file_get_contents( $root . '/includes/class-sfaf-waitlist.php' );
em( 'the Templates screen renders through render_sample()', false !== strpos( $port, 'SFAF_Messages::render_sample( $key, $variant, $lang )' ), true );
em( 'render_sample() renders an email through the builder that sends it', false !== strpos( $msgs, "return SFAF_Notifications::build( \$key, 0, \$person, array( 'sample' => \$s, 'variant' => \$variant ) );" ), true );
em( 'the offer that goes out is that builder\'s', false !== strpos( $wait, "SFAF_Notifications::build( 'offer', \$event_id, \$row," ), true );

/* ---- C: the language, and what speaks it. ------------------------------- */
function em_event( $meta = array(), $series = 0 ) {
    $id = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_title' => 'Grupo de apoyo' ) );
    foreach ( array_merge( array( '_uc_event_date' => '2026-11-12', '_uc_start_time' => '18:00', '_uc_end_time' => '19:30', '_uc_location' => '1035 Market St' ), $meta ) as $k => $v ) {
        update_post_meta( $id, $k, $v );
    }
    if ( $series ) { wp_set_object_terms( $id, array( $series ), 'uc_series' ); }
    return $id;
}
$person = (object) array( 'first_name' => 'Ana', 'name' => 'Ana', 'email' => 'ana@example.org', 'token' => 'tok', 'format' => '' );
em( 'no language and no series: English', sfaf_event_language( em_event() ), 'en' );
update_term_meta( 11, SFAF_Series::META_LANGUAGE, 'es' );
em( 'a Spanish series: Spanish', sfaf_event_language( em_event( array(), 11 ) ), 'es' );
em( 'its own English over a Spanish series: English', sfaf_event_language( em_event( array( '_uc_language' => 'en' ), 11 ) ), 'en' );
$es = em_event( array(), 11 );
$c  = SFAF_Notifications::build( 'confirmation', $es, $person );
em( 'a Spanish event\'s confirmation is Spanish', 0 === strpos( $c['subject'], 'Su inscripción en' ), true );
em( 'with its date in Spanish', false !== strpos( $c['text'], 'jueves, 12 de noviembre de 2026' ), true );
em( 'and its time', false !== strpos( $c['text'], 'p. m., hora del Pacífico' ), true );
$a = SFAF_Notifications::build( 'alert', $es, $person, array() );
em( 'the staff alert for a Spanish event stays English', false === strpos( $a['subject'] . $a['text'], 'inscripción' ) && false !== strpos( $a['text'], 'Thursday, November 12, 2026' ), true );
$staffcopy = SFAF_Notifications::build( 'reminder', $es, (object) array( 'is_staff' => true, 'name' => '', 'token' => '' ) );
em( 'the reminder\'s staff copy for a Spanish event stays English', false !== strpos( $staffcopy['text'], 'is today.' ), true );
delete_term_meta( 11, SFAF_Series::META_LANGUAGE );

/* The formatter's Spanish, every style on every weekday: valid UTF-8, and the
   forms written out. Cutting a weekday with substr() ended inside the é of
   miércoles. */
foreach ( array( '2026-11-08', '2026-11-09', '2026-11-10', '2026-11-11', '2026-11-12', '2026-11-13', '2026-11-14' ) as $day ) {
    foreach ( array( 'full', 'day', 'short', 'short_year', 'month_year', 'weekday', 'weekday_comma', 'day_year', 'month', 'daynum' ) as $style ) {
        $out = sfaf_ap_date( $day, $style, 'es' );
        if ( ! preg_match( '//u', $out ) ) { $fails[] = "PLANT C: sfaf_ap_date( $day, $style, es ) is not valid UTF-8"; }
    }
}
em( 'the Spanish weekday abbreviations', array_map( function ( $d ) { return sfaf_ap_date( $d, 'weekday', 'es' ); }, array( '2026-11-08', '2026-11-11', '2026-11-14' ) ), array( 'dom.', 'mié.', 'sáb.' ) );
em( 'the Spanish full date', sfaf_ap_date( '2026-11-11', 'full', 'es' ), 'miércoles, 11 de noviembre de 2026' );

/* ---- F: the donate line. ------------------------------------------------ */
$line_en = 'Support this work: donate to SFAF';
$ev = em_event();
$conf = SFAF_Notifications::build( 'confirmation', $ev, $person );
em( 'the confirmation carries the donate line', false !== strpos( $conf['text'], $line_en ), true );
em( 'linked to the resolved campaign', false !== strpos( $conf['html'], 'href="' . SFAF_DONATE_DEFAULT . '"' ), true );
em( 'after the details and before the cancel line',
    strpos( $conf['text'], 'Location:' ) < strpos( $conf['text'], $line_en ) && strpos( $conf['text'], $line_en ) < strpos( $conf['text'], 'Cannot make it?' ), true );
$rem = SFAF_Notifications::build( 'reminder', $ev, $person );
em( 'the reminder carries it', false !== strpos( $rem['text'], $line_en ), true );
em( 'the reminder\'s staff copy does not', false === strpos( SFAF_Notifications::build( 'reminder', $ev, (object) array( 'is_staff' => true, 'name' => '', 'token' => '' ) )['text'], $line_en ), true );
update_term_meta( 12, SFAF_Series::META_DONATE, 'https://donate.sfaf.org/campaign/999/donate' );
$in_series = em_event( array(), 12 );
em( 'PLANT F: a series link is used over the default', false !== strpos( SFAF_Notifications::build( 'confirmation', $in_series, $person )['html'], 'campaign/999/donate' ), true );
$none = em_event( array( '_uc_donate_choice' => 'none' ) );
em( 'PLANT F: None leaves the line out of the confirmation', false === strpos( SFAF_Notifications::build( 'confirmation', $none, $person )['text'], $line_en ), true );
em( 'and out of the reminder', false === strpos( SFAF_Notifications::build( 'reminder', $none, $person )['text'], $line_en ), true );
foreach ( array( 'waitlist', 'offer', 'offer_passed', 'cancelled', 'changed', 'reinstated' ) as $k ) {
    em( "PLANT F: the $k message has no donate line", false === strpos( (string) SFAF_Notifications::build( $k, $ev, $person, array() )['text'], $line_en ), true );
}
$conf_es = SFAF_Notifications::build( 'confirmation', em_event( array( '_uc_language' => 'es' ) ), $person );
em( 'the Spanish donate line', false !== strpos( $conf_es['text'], 'Apoye este trabajo: done a SFAF' ), true );

/* ---- D: overrides, refusals, reset. ------------------------------------- */
em( 'an unknown token is refused', is_wp_error( SFAF_Messages::save( 'waitlist', 'default', 'en', array( 'subject' => 'Hi {nme}', 'intro' => 'x', 'closing' => '' ) ) ), true );
em( 'a token the message cannot fill is refused', is_wp_error( SFAF_Messages::save( 'cancelled', 'default', 'en', array( 'subject' => 'x', 'intro' => '{confirm_link}', 'closing' => '' ) ) ), true );
em( 'a good override saves', SFAF_Messages::save( 'waitlist', 'default', 'es', array( 'subject' => 'Lista: {title}', 'intro' => "Hola, {first_name}.\n\nPosición {position}.", 'closing' => '' ) ), true );
$over = SFAF_Messages::render_sample( 'waitlist', 'default', 'es' );
em( 'the override is what renders', array( $over['subject'], false !== strpos( $over['text'], 'Posición 3.' ) ), array( 'Lista: Coffee and Conversation', true ) );
em( 'one option per message per language', is_array( get_option( 'sfaf_email_waitlist_es' ) ), true );
SFAF_Messages::reset( 'waitlist', 'default', 'es' );
em( 'reset gives back the shipped text', SFAF_Messages::render_sample( 'waitlist', 'default', 'es' )['subject'], 'Está en la lista de espera de Coffee and Conversation' );
em( 'and removes the option', get_option( 'sfaf_email_waitlist_es', 'gone' ), 'gone' );

/* ---- The Settings screen's text moves once. ----------------------------- */
update_option( 'uc_settings', array( 'email_rsvp_subject' => 'See you at {event_name}', 'email_rsvp_body' => 'Hi {attendee_name}, it is on {event_date}.', 'display_per_page' => '12' ) );
delete_option( 'sfaf_email_settings_moved' );
SFAF_Messages::migrate_settings();
$moved = SFAF_Messages::get( 'confirmation', 'online_link', 'en' );
em( 'the old subject is the English template, tokens renamed', $moved['subject'], 'See you at {title}' );
em( 'the old body follows the heading', $moved['intro'], "You are registered, {first_name}.\n\nHi {first_name}, it is on {date}." );
em( 'and is gone from Settings, the rest kept', get_option( 'uc_settings' ), array( 'display_per_page' => '12' ) );
update_option( 'uc_settings', array( 'email_rsvp_subject' => 'again' ) );
SFAF_Messages::migrate_settings();
em( 'it moves once', SFAF_Messages::get( 'confirmation', 'in_person', 'en' )['subject'], 'See you at {title}' );

if ( $fails ) {
    echo 'EMAIL MESSAGES: ' . count( $fails ) . " FAILURE(S)\n  - " . implode( "\n  - ", array_slice( $fails, 0, 40 ) ) . "\n";
    exit( 1 );
}
printf( "email messages: %d renders in two languages, no token left, no em dash; the donate line where it belongs; overrides, refusals and reset behave.\n", $count );
