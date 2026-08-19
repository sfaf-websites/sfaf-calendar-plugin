<?php
/**
 * Did somebody deliberately ask for the registrants to be emailed?
 *
 * ONE PLACE ANSWERS IT, FOR EVERY PATH THAT CAN SEND MANAGER-CAUSED MAIL:
 * saving a moved event, cancelling one, and cancelling a whole series. Each of
 * those used to read its own ticked checkbox out of $_POST, which is three
 * definitions of consent and three chances for one of them to drift.
 *
 * WHY THE CHECKBOX WENT. It was ticked by default and sat among thirty other
 * controls, so the common case was that mail went to everybody registered
 * because nobody noticed a box, and that cannot be undone. A control somebody
 * scrolls past is not a decision. The question is now asked at the moment of
 * saving, in a dialog that names how many people would be told and what
 * changed, with two buttons that both do something and neither of which is
 * chosen by inaction.
 *
 * THE ABSENT VALUE MEANS DO NOT SEND, AND THAT IS THE WHOLE POINT.
 * ---------------------------------------------------------------------------
 * A ticked checkbox fails open: anything that posts to the save route without
 * the manager ever seeing the form sends mail. This fails closed. The costs are
 * not symmetrical and are not reversible in the same way. Not sending an email
 * leaves a manager able to send it; sending one cannot be taken back, and the
 * people it reaches are registrants being told, wrongly, that something about
 * an event they signed up for has changed.
 *
 * So: no value, an unknown value, or a form submitted with scripting off all
 * mean silence. What a save does about that is tell the manager plainly which
 * of the two happened, so nobody is left assuming. See the 'told' argument on
 * the redirect out of save_event_from_post().
 *
 * NOT FOR AUTOMATIC MAIL. Registration confirmations, the morning-of reminder
 * and the two-hour summary are not caused by somebody editing and are not asked
 * about: they are the thing the person signed up for. This gate is only ever
 * consulted on a path a manager drove.
 *
 * @package SFAF_Calendar
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The choice a manager made, as posted.
 *
 * @param array $post Usually $_POST.
 * @return string 'send', 'silent', or '' when nothing usable was posted.
 */
function sfaf_notify_choice( $post ) {
    if ( ! is_array( $post ) || ! isset( $post['notify_choice'] ) ) {
        return '';
    }
    $choice = sanitize_key( is_string( $post['notify_choice'] ) ? wp_unslash( $post['notify_choice'] ) : '' );
    return in_array( $choice, array( 'send', 'silent' ), true ) ? $choice : '';
}

/**
 * May this request email the people registered?
 *
 * ONLY 'send' IS A YES. Every other answer, including no answer at all, is a
 * no. See the note at the top of this file for why the default direction is
 * silence rather than mail.
 *
 * @param array $post Usually $_POST.
 * @return bool
 */
function sfaf_should_notify( $post ) {
    return 'send' === sfaf_notify_choice( $post );
}
