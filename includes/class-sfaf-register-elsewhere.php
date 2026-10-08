<?php
/**
 * REGISTER ON ANOTHER SITE (3.110.1).
 *
 * The Registration card asks first where people register: "Take registrations
 * here" (the default) or "Register on another site" with a link. On another
 * site, the event page's Register button opens that link in a new tab, and
 * everything this calendar does with registrations is off for the event:
 * Accept RSVPs, Email required, Capacity, Questions, the agreement, the
 * waitlist and the text opt-in are hidden in the editor and ignored on save,
 * so their stored values wait unchanged for a switch back.
 *
 *   _uc_reg_mode                 'here' or 'elsewhere'; absent follows the series
 *   _uc_reg_url                  the event's own link; empty uses the series'
 *   _sfaf_series_reg_mode / _sfaf_series_reg_url   the series default
 *
 * AN IMPORTED EVENT IS NOT THIS. One whose source takes its registrations
 * keeps SFAF_Sources' "Register on [platform]" and never shows the choice;
 * is_on() answers no for it whatever is stored.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Register_Elsewhere {

    const META_MODE   = '_uc_reg_mode';
    const META_URL    = '_uc_reg_url';
    const SERIES_MODE = '_sfaf_series_reg_mode';
    const SERIES_URL  = '_sfaf_series_reg_url';

    /** An http or https address, or ''. */
    public static function clean_url( $raw ) {
        $url = esc_url_raw( trim( (string) $raw ), array( 'http', 'https' ) );
        return ( '' !== $url && preg_match( '#^https?://[^\s/]+\.[^\s/]+#i', $url ) ) ? $url : '';
    }

    private static function series_of( $event_id ) {
        return class_exists( 'SFAF_Series' ) ? (int) SFAF_Series::id_for_event( (int) $event_id ) : 0;
    }

    private static function imported( $event_id ) {
        return class_exists( 'SFAF_Sources' ) && SFAF_Sources::takes_rsvps_at_source( (int) $event_id );
    }

    /** The series default: 'here' or 'elsewhere'. */
    public static function series_mode( $series_id ) {
        return ( (int) $series_id && 'elsewhere' === (string) get_term_meta( (int) $series_id, self::SERIES_MODE, true ) ) ? 'elsewhere' : 'here';
    }

    /** The series' default link, or ''. */
    public static function series_url( $series_id ) {
        return (int) $series_id ? self::clean_url( get_term_meta( (int) $series_id, self::SERIES_URL, true ) ) : '';
    }

    /** Where this event takes registrations: its own choice, or its series'. */
    public static function mode( $event_id ) {
        $own = (string) get_post_meta( (int) $event_id, self::META_MODE, true );
        if ( 'here' === $own || 'elsewhere' === $own ) {
            return $own;
        }
        return self::series_mode( self::series_of( $event_id ) );
    }

    /** The link: the event's own, or its series'. */
    public static function url( $event_id ) {
        $own = self::clean_url( get_post_meta( (int) $event_id, self::META_URL, true ) );
        return '' !== $own ? $own : self::series_url( self::series_of( $event_id ) );
    }

    /**
     * Does this event send people to another site to register? Never for an
     * imported event, and never without a link to send them to.
     */
    public static function is_on( $event_id ) {
        $event_id = (int) $event_id;
        if ( ! $event_id || self::imported( $event_id ) ) {
            return false;
        }
        return 'elsewhere' === self::mode( $event_id ) && '' !== self::url( $event_id );
    }

    /**
     * Save the choice from the event form. Only when the choice was on the
     * form (reg_mode_present), so another screen saving the event leaves it.
     * A choice and link equal to the series default store nothing, so the
     * event follows its series.
     */
    public static function save_from_post( $event_id, $post ) {
        $event_id = (int) $event_id;
        if ( empty( $post['reg_mode_present'] ) || self::imported( $event_id ) ) {
            return;
        }
        $mode   = ( isset( $post['reg_mode'] ) && 'elsewhere' === $post['reg_mode'] ) ? 'elsewhere' : 'here';
        $url    = isset( $post['reg_url'] ) ? self::clean_url( wp_unslash( $post['reg_url'] ) ) : '';
        $series = self::series_of( $event_id );
        // Another site with no link to send people to is no choice: registrations stay here.
        if ( 'elsewhere' === $mode && '' === $url && '' === self::series_url( $series ) ) {
            $mode = 'here';
        }
        if ( $mode === self::series_mode( $series ) ) {
            delete_post_meta( $event_id, self::META_MODE );
        } else {
            update_post_meta( $event_id, self::META_MODE, $mode );
        }
        if ( '' === $url || $url === self::series_url( $series ) ) {
            delete_post_meta( $event_id, self::META_URL );
        } else {
            update_post_meta( $event_id, self::META_URL, $url );
        }
    }

    /** Save the series default from the series form. */
    public static function save_series_from_post( $series_id, $post ) {
        $series_id = (int) $series_id;
        if ( ! $series_id || empty( $post['series_reg_mode_present'] ) ) {
            return;
        }
        $mode = ( isset( $post['series_reg_mode'] ) && 'elsewhere' === $post['series_reg_mode'] ) ? 'elsewhere' : 'here';
        $url  = isset( $post['series_reg_url'] ) ? self::clean_url( wp_unslash( $post['series_reg_url'] ) ) : '';
        if ( 'elsewhere' === $mode ) {
            update_term_meta( $series_id, self::SERIES_MODE, 'elsewhere' );
        } else {
            delete_term_meta( $series_id, self::SERIES_MODE );
        }
        if ( '' !== $url ) {
            update_term_meta( $series_id, self::SERIES_URL, $url );
        } else {
            delete_term_meta( $series_id, self::SERIES_URL );
        }
    }

    /**
     * The choice, for the Registration card and the series screen.
     *
     * @param string $prefix    '' for an event, 'series_' for a series.
     * @param string $mode      'here' or 'elsewhere'.
     * @param string $url       The link shown in the box.
     * @param string $inherited The series link an event falls back to, shown as the placeholder.
     */
    public static function render_choice( $prefix, $mode, $url, $inherited = '' ) {
        $name = $prefix . 'reg_mode';
        $id   = 'uc-' . str_replace( '_', '-', $prefix ) . 'reg-url';
        ?>
        <fieldset class="uc-reg-where" data-uc-reg-where>
            <legend class="uc-field-label">Where people register</legend>
            <input type="hidden" name="<?php echo esc_attr( $prefix ); ?>reg_mode_present" value="1" />
            <div class="uc-seg" role="radiogroup" aria-label="Where people register">
                <label class="uc-seg-opt">
                    <input type="radio" name="<?php echo esc_attr( $name ); ?>" value="here" data-uc-reg-mode <?php checked( 'elsewhere' !== $mode ); ?> />
                    <span>Take registrations here</span>
                </label>
                <label class="uc-seg-opt">
                    <input type="radio" name="<?php echo esc_attr( $name ); ?>" value="elsewhere" data-uc-reg-mode <?php checked( 'elsewhere' === $mode ); ?> />
                    <span>Register on another site</span>
                </label>
            </div>
            <label class="uc-field uc-reg-url" for="<?php echo esc_attr( $id ); ?>" data-uc-reg-url<?php echo 'elsewhere' === $mode ? '' : ' hidden'; ?>>
                <span class="uc-field-label">Registration link</span>
                <input type="url" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $prefix ); ?>reg_url" value="<?php echo esc_attr( $url ); ?>"
                       placeholder="<?php echo esc_attr( '' !== $inherited ? $inherited : 'https://' ); ?>" inputmode="url" />
                <span class="uc-hint">The Register button opens this page in a new tab.</span>
            </label>
        </fieldset>
        <?php
    }
}
