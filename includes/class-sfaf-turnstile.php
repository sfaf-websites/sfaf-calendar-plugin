<?php
/**
 * Cloudflare Turnstile, for the one form that has no other gate.
 *
 * WHY THIS FORM AND NOT THE OTHER ONE. The staff request form is reached by a
 * link emailed to an sfaf.org address, so the mailbox is the gate and a widget
 * on top of it would be a second obstacle for somebody who has already proved
 * more than it could. The community form has no gate at all: the URL is public,
 * linked from a campaign site, and anybody can post to it. Turnstile is what
 * stands in for the mailbox there.
 *
 * TURNSTILE RATHER THAN A CAPTCHA OF OUR OWN, because it is already on this
 * site for Gravity Forms, which means the keys exist, somebody already knows
 * where they came from, and the domain is already configured at Cloudflare's
 * end. A second anti-spam mechanism would be a second thing to explain.
 *
 * IT IS NEVER THE ONLY PROTECTION. The honeypot still runs, the rate limits
 * still count, and every field is still validated. A widget that fails open
 * when Cloudflare is unreachable, which this one does, cannot be load-bearing:
 * see verify() for why it fails open and what still holds when it does.
 *
 * @package SFAF_Calendar
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SFAF_Turnstile {

    /** Cloudflare's verification endpoint. */
    const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    /** The widget script. */
    const SCRIPT_URL = 'https://challenges.cloudflare.com/turnstile/v0/api.js';

    /** The field the widget posts under. Cloudflare's name, not ours. */
    const FIELD = 'cf-turnstile-response';

    /**
     * Is it configured?
     *
     * BOTH KEYS OR NEITHER. A site key with no secret renders a widget nothing
     * checks, which is worse than no widget: it looks like protection on a page
     * that has none. So the form asks this one question and either shows a
     * checked widget or shows none at all.
     *
     * @return bool
     */
    public static function ready() {
        return ( '' !== self::site_key() && '' !== self::secret_key() );
    }

    /** The public half, safe to render. */
    public static function site_key() {
        return trim( (string) SFAF_Credentials::get( 'turnstile_site_key', '' ) );
    }

    /** The private half. Never rendered. */
    public static function secret_key() {
        return trim( (string) SFAF_Credentials::get( 'turnstile_secret_key', '' ) );
    }

    /**
     * The widget, and the script that draws it.
     *
     * RENDERED INSIDE THE FORM so the token posts with it. The script is loaded
     * `defer` and from Cloudflare's own domain; it is the only third-party
     * request either of these pages makes.
     */
    public static function field() {
        if ( ! self::ready() ) {
            return;
        }
        ?>
        <div class="uc-field uc-turnstile">
            <div class="cf-turnstile" data-sitekey="<?php echo esc_attr( self::site_key() ); ?>"></div>
            <noscript>
                <p class="uc-hint">
                    This form needs JavaScript turned on to check that you are not a robot.
                </p>
            </noscript>
        </div>
        <script src="<?php echo esc_url( self::SCRIPT_URL ); ?>" async defer></script>
        <?php
    }

    /**
     * Check the token this request carried.
     *
     * IT FAILS OPEN, AND THAT IS A DECISION RATHER THAN AN OVERSIGHT.
     * -----------------------------------------------------------------------
     * If Cloudflare cannot be reached, or answers something unreadable, this
     * returns true and the submission goes through. The alternative is that an
     * outage at a third party silently stops a campaign's calendar accepting
     * anything, with nobody watching and no error anybody would see.
     *
     * That is only tolerable because this is not the only protection. When it
     * fails open the honeypot has still run, both rate limits have still
     * counted, every field is still validated and sanitised, and the result is
     * still a PENDING post that a person has to approve before anybody sees it.
     * The worst case is more spam in a queue, not anything published.
     *
     * A MISSING OR EMPTY TOKEN IS STILL A FAILURE. Failing open covers
     * Cloudflare being unreachable, not the widget being absent from the post.
     *
     * @return bool
     */
    public static function verify() {
        if ( ! self::ready() ) {
            return true;
        }

        $token = isset( $_POST[ self::FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::FIELD ] ) ) : '';
        if ( '' === $token ) {
            return false;
        }

        $response = wp_remote_post( self::VERIFY_URL, array(
            'timeout' => 8,
            'body'    => array(
                'secret'   => self::secret_key(),
                'response' => $token,
                'remoteip' => SFAF_Submissions::client(),
            ),
        ) );

        if ( is_wp_error( $response ) ) {
            return true;
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code > 299 ) {
            return true;
        }

        $body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $body ) || ! array_key_exists( 'success', $body ) ) {
            return true;
        }

        return ! empty( $body['success'] );
    }
}
