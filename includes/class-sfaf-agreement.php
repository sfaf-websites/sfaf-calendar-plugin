<?php
/**
 * THE REGISTRATION AGREEMENT (3.110.0).
 *
 * An event can ask everybody registering to agree to its conditions first.
 * Off by default. On, the public RSVP form's Register Now opens a dialog with
 * the agreement, a tick and Confirm RSVP; confirming submits the registration
 * as it always has, and the row stores agreed_at. A waitlist join goes through
 * the same dialog.
 *
 *   _uc_agreement          '1' when the event asks
 *   _uc_agreement_text     the event's own text, '' to use its series' text
 *   _sfaf_series_agreement the series' default text (term meta)
 *
 * THE SERIES TEXT IS INHERITED, NOT COPIED. An event with no text of its own
 * shows its series' text as it stands today, and the editor stores the box as
 * the event's own only when it differs from the series text, so a series
 * change reaches every event that never edited it.
 *
 * NEVER ON AN EVENT THAT DOES NOT TAKE REGISTRATIONS HERE: RSVPs off, or a
 * third-party event. is_on() answers no for both whatever the meta says, and
 * the server refuses a registration without agreement only when is_on() is yes.
 *
 * The words around it, the tick's label and the two buttons, are lines in
 * Email Templates, in both languages; the agreement prints as it was typed.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Agreement {

    const META_ON     = '_uc_agreement';
    const META_TEXT   = '_uc_agreement_text';
    const SERIES_META = '_sfaf_series_agreement';

    /** Whether registering for this event asks for agreement first. */
    public static function is_on( $event_id ) {
        $event_id = (int) $event_id;
        if ( '1' !== (string) get_post_meta( $event_id, self::META_ON, true ) ) {
            return false;
        }
        if ( '1' !== (string) get_post_meta( $event_id, '_uc_rsvp_enabled', true ) ) {
            return false;
        }
        // Register on another site (3.110.1): nothing is registered here to agree to.
        if ( class_exists( 'SFAF_Register_Elsewhere' ) && SFAF_Register_Elsewhere::is_on( $event_id ) ) {
            return false;
        }
        return ! SFAF_Sources::takes_rsvps_at_source( $event_id );
    }

    /** The series' default text, '' when it has none. */
    public static function series_text( $series_id ) {
        return (int) $series_id ? (string) get_term_meta( (int) $series_id, self::SERIES_META, true ) : '';
    }

    /** The event's own text, or its series' when it has none of its own. */
    public static function text( $event_id ) {
        $own = (string) get_post_meta( (int) $event_id, self::META_TEXT, true );
        if ( '' !== trim( $own ) ) {
            return $own;
        }
        return self::series_text( SFAF_Series::id_for_event( (int) $event_id ) );
    }

    /**
     * The agreement's text as stored: the description's sanitising, and no
     * pictures, so a pasted image cannot travel into every registration.
     */
    public static function clean( $value ) {
        $html = SFAF_Rich_Text::sanitize( (string) $value );
        return trim( (string) preg_replace( '#<img\b[^>]*>#i', '', $html ) );
    }

    /**
     * What the RSVP form needs to ask, in the event's language, or null when
     * it does not ask. Carried on the Register button as JSON.
     *
     * @return array|null
     */
    public static function form_data( $event_id ) {
        if ( ! self::is_on( $event_id ) ) {
            return null;
        }
        $lang = sfaf_event_language( (int) $event_id );
        return array(
            'text'    => SFAF_Rich_Text::display( self::text( $event_id ) ),
            'heading' => self::line( 'agreement_heading', $lang ),
            'tick'    => self::line( 'agreement_tick', $lang ),
            'confirm' => self::line( 'agreement_confirm', $lang ),
            'cancel'  => self::line( 'agreement_cancel', $lang ),
        );
    }

    /** One of the dialog's lines from Email Templates, plain text. */
    public static function line( $key, $lang ) {
        $t = SFAF_Messages::get( $key, 'default', $lang );
        return trim( (string) ( isset( $t['intro'] ) ? $t['intro'] : '' ) );
    }

    /**
     * Save the Registration card's agreement section. Only when the section
     * was on the form, which agreement_present says, so another screen saving
     * this event leaves it alone.
     */
    public static function save_from_post( $event_id, $post ) {
        $event_id = (int) $event_id;
        if ( empty( $post['agreement_present'] ) ) {
            return;
        }
        update_post_meta( $event_id, self::META_ON, ! empty( $post['agreement_on'] ) ? '1' : '0' );
        if ( ! isset( $post['agreement_text'] ) ) {
            return;
        }
        $text   = self::clean( wp_unslash( $post['agreement_text'] ) );
        $series = self::clean( self::series_text( SFAF_Series::id_for_event( $event_id ) ) );
        if ( '' === $text || $text === $series ) {
            delete_post_meta( $event_id, self::META_TEXT );
        } else {
            update_post_meta( $event_id, self::META_TEXT, $text );
        }
    }

    /**
     * The Registration card's section, on Add event and Edit event.
     *
     * @param int $event_id   0 on Add event.
     * @param int $series_id  The series the editor opened on, for its default.
     */
    public static function render_section( $event_id, $series_id ) {
        $event_id = (int) $event_id;
        $on       = $event_id && '1' === (string) get_post_meta( $event_id, self::META_ON, true );
        $text     = $event_id ? self::text( $event_id ) : self::series_text( $series_id );
        $series   = array();
        foreach ( SFAF_Series::all() as $term ) {
            $t = self::series_text( $term->term_id );
            if ( '' !== $t ) {
                $series[ (string) $term->term_id ] = $t;
            }
        }
        $own = $event_id && '' !== trim( (string) get_post_meta( $event_id, self::META_TEXT, true ) );
        ?>
        <div class="uc-agreement" data-uc-agreement
             data-uc-agreement-series="<?php echo esc_attr( wp_json_encode( (object) $series ) ); ?>"
             data-uc-agreement-own="<?php echo $own ? '1' : '0'; ?>">
            <h3 class="uc-agreement-title">Registration agreement</h3>
            <input type="hidden" name="agreement_present" value="1" />
            <label class="uc-check">
                <input type="checkbox" name="agreement_on" value="1" data-uc-agreement-on <?php checked( $on ); ?> />
                Ask registrants to agree before they register
            </label>
            <div class="uc-field uc-agreement-text" data-uc-agreement-body>
                <span class="uc-field-label" id="uc-agreement-label">Agreement text</span>
                <?php SFAF_Rich_Text::render( 'uc-agreement-text', 'agreement_text', $text, array( 'rows' => 6, 'aria_label' => 'Agreement text' ) ); ?>
                <span class="uc-hint">Leave it as the series wrote it to keep following the series.</span>
            </div>
        </div>
        <?php
    }
}
