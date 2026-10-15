<?php
/**
 * ONE COPY OF EACH ADMIN ALERT FOR ASANA (3.112.1).
 *
 * Asana turns a message sent to a project's address into a task, so the five
 * messages calendar admins are sent each become one task: an event requested,
 * an event submitted, a picture uploaded, the no-organizer alert and its
 * all-clear, and the scheduled-task failure and recovery.
 *
 * A SEPARATE SEND, AFTER THE NORMAL ONES, NOT A Cc OR Bcc. A Cc would put the
 * Asana address in front of every admin and thread a reply-all into the task;
 * a Bcc on SFAF_Email::send() would be added once per recipient, because every
 * caller sends one message per address. So each sender calls copy() once,
 * after its own loop, and SFAF_Email::send() does not change.
 *
 * Settings: "Asana address", and one tick per message, all on until Settings
 * is saved with one off. An empty address sends nothing. The sanitiser writes
 * every key on every save; see sanitize().
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Asana {

    const DEFAULT_ADDRESS = 'x+1219554950091179@mail.asana.com';

    /** uc_settings keys. */
    const KEY_ADDRESS = 'asana_address';
    const KEY_PREFIX  = 'asana_copy_';
    const KEY_PRESENT = 'asana_present';

    /**
     * The messages that get a copy, as key => the name on Settings.
     *
     * @return array<string,string>
     */
    public static function messages() {
        return array(
            'request' => 'Event requested',
            'submit'  => 'Event submitted',
            'picture' => 'Picture uploaded for an event',
            'orphans' => 'Events with no organizer, and the all-clear',
            'cron'    => 'Scheduled tasks failing, and working again',
        );
    }

    private static function settings() {
        $s = get_option( 'uc_settings', array() );
        return is_array( $s ) ? $s : array();
    }

    /** The address, or '' when it is empty or not an address. */
    public static function address() {
        $s = self::settings();
        if ( ! array_key_exists( self::KEY_ADDRESS, $s ) ) {
            return self::DEFAULT_ADDRESS;
        }
        $a = trim( (string) $s[ self::KEY_ADDRESS ] );
        return ( '' !== $a && is_email( $a ) ) ? $a : '';
    }

    /** Whether a message's tick is on. On until a save turns it off. */
    public static function enabled( $which ) {
        $s = self::settings();
        $k = self::KEY_PREFIX . $which;
        return ! array_key_exists( $k, $s ) || '1' === (string) $s[ $k ];
    }

    /** Where this message's copy goes, or '' for none. */
    public static function to( $which ) {
        $messages = self::messages();
        if ( ! isset( $messages[ $which ] ) || ! self::enabled( $which ) ) {
            return '';
        }
        return self::address();
    }

    /**
     * Send the one copy, through the same sender as the message itself.
     *
     * Not when the Asana address was already one of the normal recipients:
     * it has its copy, and a second would be a second task.
     *
     * @param string   $which    A key of messages().
     * @param string[] $sent_to  The addresses the normal send went to.
     * @param string   $subject
     * @param string   $html
     * @param string   $text
     * @param string   $reply_to
     * @param string[] $files
     * @return bool Whether a copy was sent.
     */
    public static function copy( $which, $sent_to, $subject, $html, $text, $reply_to = '', $files = array() ) {
        $to = self::to( $which );
        if ( '' === $to || self::already( $to, $sent_to ) ) {
            return false;
        }
        return (bool) SFAF_Email::send( $to, $subject, $html, $text, $reply_to, $files );
    }

    /**
     * The same for a plain-text message sent by wp_mail() directly, which is
     * how the scheduled-task alerts go.
     *
     * @param string   $which
     * @param string[] $sent_to
     * @param string   $subject
     * @param string   $body
     * @param string[] $headers
     * @return bool
     */
    public static function copy_plain( $which, $sent_to, $subject, $body, $headers = array() ) {
        $to = self::to( $which );
        if ( '' === $to || self::already( $to, $sent_to ) ) {
            return false;
        }
        return (bool) wp_mail( $to, $subject, $body, $headers );
    }

    private static function already( $to, $sent_to ) {
        foreach ( (array) $sent_to as $a ) {
            if ( 0 === strcasecmp( trim( (string) $a ), $to ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * The settings, from what Settings posted, into $out.
     *
     * EVERY KEY IS WRITTEN ON EVERY SAVE. The ticks are told apart from "not
     * on this form" by a hidden marker the form always posts: with it, an
     * absent tick is off; without it (a save that did not come from the
     * form), what is already stored is kept, and the default where nothing is.
     *
     * @param array $input
     * @param array $out
     */
    public static function sanitize( $input, &$out ) {
        $input   = is_array( $input ) ? $input : array();
        $stored  = self::settings();
        $present = ! empty( $input[ self::KEY_PRESENT ] );

        if ( array_key_exists( self::KEY_ADDRESS, $input ) ) {
            $a = sanitize_email( trim( (string) $input[ self::KEY_ADDRESS ] ) );
            $out[ self::KEY_ADDRESS ] = is_email( $a ) ? $a : '';
        } else {
            $out[ self::KEY_ADDRESS ] = array_key_exists( self::KEY_ADDRESS, $stored ) ? (string) $stored[ self::KEY_ADDRESS ] : self::DEFAULT_ADDRESS;
        }

        foreach ( array_keys( self::messages() ) as $which ) {
            $k = self::KEY_PREFIX . $which;
            if ( $present ) {
                $out[ $k ] = ( isset( $input[ $k ] ) && '1' === (string) $input[ $k ] ) ? '1' : '0';
            } elseif ( isset( $input[ $k ] ) ) {
                $out[ $k ] = ( '1' === (string) $input[ $k ] ) ? '1' : '0';
            } else {
                $out[ $k ] = ( ! array_key_exists( $k, $stored ) || '1' === (string) $stored[ $k ] ) ? '1' : '0';
            }
        }
    }
}
