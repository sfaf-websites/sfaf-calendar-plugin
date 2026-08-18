<?php
/**
 * EVENTS THAT HAVE LOST THE PERSON RESPONSIBLE FOR THEM.
 *
 * WHY THIS EXISTS AND WHY IT IS NOT THE ONLY DEFENCE. Removing somebody on the
 * Users and Permissions screen now refuses to finish while they organize
 * anything, and makes an administrator hand those events to somebody else. That
 * is the deliberate path and it is the one that produces a good answer, because
 * a person is present who knows who should take the work on.
 *
 * The normal way staff leave is not that path. It is Users > Delete in the
 * WordPress admin, which knows nothing about this plugin, fires no hook this
 * plugin could usefully refuse from, and offers its own "attribute all content
 * to" control that somebody may or may not use. An event whose organizer is
 * gone is editable by calendar admins and nobody else, its RSVP list is
 * unreachable to the team who were running it, and NOTHING SAYS SO. That is the
 * failure this catches.
 *
 * WHY A DAILY CHECK RATHER THAN A HOOK ON user_deleted. A hook fires once, at a
 * moment nobody is watching, and if the mail fails there is no second chance. A
 * check that runs every day answers the question from the data every time, so it
 * is correct after a database restore, after a plugin update that missed a hook,
 * and after somebody deletes a user with the plugin deactivated. It also catches
 * the second cause a hook could not see: a calendar record withdrawn while the
 * WordPress account stays.
 *
 * ALERTING ON CHANGE, NOT ON STATE. The brief for this is exact and it is the
 * whole design: the same list arriving every morning is noise, and noise gets
 * filtered, and then the one that mattered is filtered too. So a message goes
 * out when the SET of orphaned events changes, and never otherwise. Four days of
 * the same three events is one email. A fourth event appearing on day five is a
 * second email naming all four. Everything being fixed is a third saying so.
 *
 * See SFAF_Cron for how the daily check is driven, and PROJECT.md §5.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Orphans {

    /**
     * What the last message said, so the next one can tell whether anything
     * moved. Holds the event ids that were reported and when.
     */
    const STATE_OPTION = 'sfaf_orphan_state';

    /** How often the check is allowed to do real work. */
    const CHECK_EVERY = 86400;

    /** When it last ran, so a 15-minute runner does not run it 96 times a day. */
    const CHECKED_OPTION = 'sfaf_orphan_checked';

    /**
     * The task, as SFAF_Cron::tasks() calls it.
     *
     * IT IS ON THE 15-MINUTE RUNNER AND THROTTLES ITSELF TO A DAY, rather than
     * being scheduled daily of its own. One runner, one lock, one log, which is
     * the rule SFAF_Cron opens with. A daily WP event would be a second thing
     * that can silently stop, and the whole subject here is things stopping
     * silently.
     *
     * @return array{status:string,summary:string,counts:array}
     */
    public static function run() {
        $last = (int) get_option( self::CHECKED_OPTION, 0 );
        $now  = time();

        if ( $last && ( $now - $last ) < self::CHECK_EVERY ) {
            return array(
                'status'  => 'ok',
                'summary' => 'Checked within the last day already.',
                'counts'  => array(),
            );
        }
        update_option( self::CHECKED_OPTION, $now, false );

        return self::check( false );
    }

    /**
     * Look, and email if the answer has changed since last time.
     *
     * @param bool $force Ignore the "has it changed" test and always send.
     *                    For the "Run now" button and the tests, never for cron.
     * @return array{status:string,summary:string,counts:array}
     */
    public static function check( $force = false ) {
        $orphans = SFAF_Portal::orphaned_events();
        $ids     = array_map( function ( $e ) { return (int) $e['id']; }, $orphans );
        sort( $ids );

        $stored = get_option( self::STATE_OPTION, array() );
        $known  = ( is_array( $stored ) && isset( $stored['ids'] ) && is_array( $stored['ids'] ) )
            ? array_map( 'intval', $stored['ids'] )
            : array();
        sort( $known );

        $changed = ( $ids !== $known );

        if ( ! $changed && ! $force ) {
            return array(
                'status'  => 'ok',
                'summary' => $ids
                    ? sprintf( '%d event%s still has no organizer. Already reported, so nothing was sent.',
                        count( $ids ), 1 === count( $ids ) ? '' : 's' )
                    : 'Every event has an organizer.',
                'counts'  => array( 'orphans' => count( $ids ) ),
            );
        }

        /*
         * THE STATE IS WRITTEN WHATEVER HAPPENS TO THE MAIL, and that is the
         * opposite of the choice SFAF_Cron makes for its health alert, which
         * retries. The difference is what a retry would cost. There, a missed
         * alert means nobody learns the site has stopped. Here, a missed alert
         * means nobody learns today; the check runs again tomorrow and the set
         * will still have changed relative to nothing having been recorded, so
         * it is reported then. Writing it regardless is what stops a permanently
         * failing mailbox producing a message a day forever.
         */
        update_option( self::STATE_OPTION, array(
            'ids'       => $ids,
            'checked'   => time(),
            'reported'  => time(),
        ), false );

        if ( empty( $ids ) ) {
            // Only worth saying if there was something to have fixed.
            if ( ! empty( $known ) ) {
                self::send_all_clear( count( $known ) );
                return array(
                    'status'  => 'ok',
                    'summary' => 'Every event has an organizer again. The calendar admins were told.',
                    'counts'  => array( 'orphans' => 0 ),
                );
            }
            return array(
                'status'  => 'ok',
                'summary' => 'Every event has an organizer.',
                'counts'  => array( 'orphans' => 0 ),
            );
        }

        $sent = self::send( $orphans );

        return array(
            'status'  => 'ok',
            'summary' => sprintf(
                '%d event%s with no organizer. %s',
                count( $ids ),
                1 === count( $ids ) ? '' : 's',
                $sent ? sprintf( 'Told %d calendar admin%s.', $sent, 1 === $sent ? '' : 's' ) : 'No calendar admin has a usable address.'
            ),
            'counts'  => array( 'orphans' => count( $ids ), 'told' => $sent ),
        );
    }

    /** What the last check recorded, for the Automation screen. */
    public static function state() {
        $stored = get_option( self::STATE_OPTION, array() );
        return array(
            'ids'      => ( is_array( $stored ) && isset( $stored['ids'] ) ) ? array_map( 'intval', $stored['ids'] ) : array(),
            'checked'  => ( is_array( $stored ) && isset( $stored['checked'] ) ) ? (int) $stored['checked'] : 0,
            'reported' => ( is_array( $stored ) && isset( $stored['reported'] ) ) ? (int) $stored['reported'] : 0,
        );
    }

    /* ---------------------------------------------------------------------
     * The message
     * ------------------------------------------------------------------- */

    /**
     * Tell every calendar admin, naming the events and linking to each.
     *
     * @param array[] $orphans From SFAF_Portal::orphaned_events().
     * @return int How many messages went.
     */
    private static function send( $orphans ) {
        $to = SFAF_Portal::admin_recipients();
        if ( empty( $to ) ) {
            return 0;
        }

        $site  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
        $n     = count( $orphans );
        $subject = sprintf(
            '[%s] %d calendar event%s ha%s no organizer',
            $site, $n, 1 === $n ? '' : 's', 1 === $n ? 's' : 've'
        );

        $rows = array();
        $text = '';
        foreach ( $orphans as $e ) {
            $url = SFAF_Portal::link( 'events/edit/' . (int) $e['id'] );
            $rows[] = '<tr>'
                . '<td valign="top" style="padding:8px 12px 0 0; font-family:' . SFAF_Email::FONT . '; font-size:14px; line-height:1.4; color:' . SFAF_Email::C_INK . ';">'
                . '<a href="' . esc_url( $url ) . '" style="color:' . SFAF_Email::C_TEAL . ';">' . esc_html( $e['title'] ) . '</a></td>'
                . '<td valign="top" style="padding:8px 12px 0 0; font-family:' . SFAF_Email::FONT . '; font-size:14px; line-height:1.4; color:' . SFAF_Email::C_MUTED . ';">'
                . esc_html( sfaf_status_label( $e['status'] ) ) . '</td>'
                . '<td valign="top" style="padding:8px 0 0 0; font-family:' . SFAF_Email::FONT . '; font-size:14px; line-height:1.4; color:' . SFAF_Email::C_MUTED . ';">'
                . esc_html( $e['who'] ) . '</td>'
                . '</tr>';
            $text .= '- ' . $e['title'] . ' (' . sfaf_status_label( $e['status'] ) . ', ' . $e['who'] . ")\n  " . $url . "\n";
        }

        $html  = SFAF_Email::heading( 1 === $n ? 'An event has no organizer' : sprintf( '%d events have no organizer', $n ) );
        $html .= SFAF_Email::para(
            'The person who created ' . ( 1 === $n ? 'this event' : 'these events' )
            . ' no longer has calendar access, so only calendar admins can edit '
            . ( 1 === $n ? 'it' : 'them' ) . ' or see who has registered.'
        );
        $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 20px 0;">'
            . '<tr>'
            . '<th align="left" style="padding:0 12px 6px 0; border-bottom:1px solid ' . SFAF_Email::C_RULE . '; font-family:' . SFAF_Email::FONT . '; font-size:12px; font-weight:700; color:' . SFAF_Email::C_MUTED . ';">Event</th>'
            . '<th align="left" style="padding:0 12px 6px 0; border-bottom:1px solid ' . SFAF_Email::C_RULE . '; font-family:' . SFAF_Email::FONT . '; font-size:12px; font-weight:700; color:' . SFAF_Email::C_MUTED . ';">Status</th>'
            . '<th align="left" style="padding:0 0 6px 0; border-bottom:1px solid ' . SFAF_Email::C_RULE . '; font-family:' . SFAF_Email::FONT . '; font-size:12px; font-weight:700; color:' . SFAF_Email::C_MUTED . ';">Organizer</th>'
            . '</tr>' . implode( '', $rows ) . '</table>';
        $html .= SFAF_Email::para( 'Open each one and set a new organizer, or assign a team that should own it.' );
        $html .= SFAF_Email::small_para(
            'This is sent only when the list changes. If nothing is done, it will not be sent again tomorrow.'
        );

        $body  = ( 1 === $n ? "An event has no organizer.\n\n" : "$n events have no organizer.\n\n" );
        $body .= 'The person who created ' . ( 1 === $n ? 'it' : 'them' ) . " no longer has calendar access, so only\n";
        $body .= "calendar admins can edit " . ( 1 === $n ? 'it' : 'them' ) . " or see who has registered.\n\n";
        $body .= $text;
        $body .= "\nOpen each one and set a new organizer, or assign a team that should own it.\n\n";
        $body .= "This is sent only when the list changes. If nothing is done, it will not be sent again tomorrow.\n\n";
        $body .= SFAF_Email::POSTAL . "\n";

        $sent = 0;
        foreach ( $to as $address => $name ) {
            if ( SFAF_Email::send(
                $address,
                $subject,
                SFAF_Email::shell( sprintf( '%d event%s need a new organizer', $n, 1 === $n ? '' : 's' ), $html ),
                $body
            ) ) {
                $sent++;
            }
        }
        return $sent;
    }

    /** The "all fixed" note, sent once when the last orphan is dealt with. */
    private static function send_all_clear( $was ) {
        $to = SFAF_Portal::admin_recipients();
        if ( empty( $to ) ) {
            return 0;
        }
        $site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

        $html  = SFAF_Email::heading( 'Every event has an organizer again' );
        $html .= SFAF_Email::para( sprintf(
            'The %d event%s reported earlier %s now been given somebody responsible for %s.',
            $was, 1 === $was ? '' : 's', 1 === $was ? 'has' : 'have', 1 === $was ? 'it' : 'them'
        ) );

        $body = sprintf(
            "Every event has an organizer again. The %d event%s reported earlier %s now been given\nsomebody responsible for %s.\n\n%s\n",
            $was, 1 === $was ? '' : 's', 1 === $was ? 'has' : 'have', 1 === $was ? 'it' : 'them', SFAF_Email::POSTAL
        );

        $sent = 0;
        foreach ( $to as $address => $name ) {
            if ( SFAF_Email::send( $address, sprintf( '[%s] Every calendar event has an organizer again', $site ),
                SFAF_Email::shell( 'Nothing is unassigned any more', $html ), $body ) ) {
                $sent++;
            }
        }
        return $sent;
    }
}
