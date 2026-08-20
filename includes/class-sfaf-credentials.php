<?php
/**
 * Platform credentials — stored apart from uc_settings, on purpose.
 *
 * WHY THIS EXISTS
 * ---------------------------------------------------------------------------
 * uc_settings is rebuilt from scratch on every settings-form submit: the
 * sanitize callback starts with an empty array and copies across only the keys
 * it knows about, so anything it forgets to handle is gone. Keeping a
 * credential in there means its survival depends, on every single save, on a
 * read-back of the old value inside the sanitize callback — and on nobody ever
 * writing uc_settings from anywhere else with a partial array.
 *
 * That is a lot of ways to lose a password. The GoFundMe Pro access token was
 * already pulled out into its own option for this reason; this file finishes
 * the job for every other credential the plugin holds.
 *
 * Credentials now live in one dedicated, autoloaded option that is NEVER
 * rewritten wholesale. set() reads the current array, changes the one key it
 * was asked to change, and writes it back — so a save that knows nothing about
 * a key cannot erase it, and neither can a future settings field.
 *
 * WHAT SURVIVES WHAT
 *   - A settings-form save: the form no longer carries credentials into
 *     uc_settings at all; absorb() writes them here, key by key.
 *   - A plugin update: nothing in this plugin deletes options. There is no
 *     uninstall.php and no register_uninstall_hook, and the activation routine
 *     (sfaf_run_activation) only creates the RSVP table, registers the post
 *     type, seeds sample data once and flushes rewrites — it never touches
 *     credentials. See the guard comment there.
 *   - Deactivate/reactivate: sfaf_deactivate() only flushes rewrite rules.
 *
 * MIGRATION. Values already sitting in uc_settings are copied here once, on
 * load, by migrate(). It never overwrites a value already held here, so it is
 * safe to run on every request and heals a half-migrated site. The stale
 * copies left behind in uc_settings are never read again, and disappear by
 * themselves on the next settings save now that the sanitize callback no
 * longer emits those keys.
 *
 * SECRETS. get() returns values byte-for-byte. Nothing here logs, echoes or
 * returns a credential to the browser; that is the caller's business, and the
 * two write-only fields (the Eventbrite private token and the GoFundMe Pro
 * client secret) are never rendered back into their form inputs.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Credentials {

    /**
     * The one option every credential lives in.
     *
     * Autoloaded: these are read on ordinary front-end requests (the REST feed
     * checks the multi-site API key) and an autoloaded option costs no extra
     * query.
     */
    const OPTION = 'sfaf_credentials';

    /**
     * Every credential, and how its submitted value should be treated.
     *
     *   secret — write-only in the UI. The field submits blank unless it was
     *            retyped, and blank means "keep what is stored". Stored
     *            byte-for-byte; never sanitised, never rendered back.
     *   url    — an endpoint override. Blank is meaningful: it means "use the
     *            documented default", so blank clears it.
     *   text   — an identifier or key that is visible in the form and round
     *            trips normally. Blank clears it.
     *
     * @return array<string,string> Key => type.
     */
    public static function keys() {
        return array(
            'eventbrite_private_token' => 'secret',
            'eventbrite_api_base'      => 'url',
            'gofundme_client_id'       => 'text',
            'gofundme_client_secret'   => 'secret',
            'gofundme_org_id'          => 'text',
            'gofundme_token_url'       => 'url',
            'gofundme_api_base'        => 'url',
            'multisite_api_key'        => 'text',
            'galaxy_api_key'           => 'text',
            'webhook_secret'           => 'text',

            /*
             * The Google Maps Embed API key.
             *
             * It lives here rather than in uc_settings for the reason at the
             * top of this file: uc_settings is rebuilt from scratch on every
             * settings save, so a key kept there survives only while the
             * sanitize callback keeps remembering it. This one has to outlive
             * plugin updates and every future settings field, so it goes where
             * nothing rewrites the option wholesale.
             *
             * 'text' rather than 'secret' on purpose. A Maps browser key is
             * not confidential. It is visible to anyone who loads a map, by
             * design, so hiding it in the form would be theatre and would
             * stop an admin checking which key is in place. Its protection is
             * the referrer and API restrictions it carries at Google's end,
             * not secrecy here. See the readme for what those must be.
             */
            'google_maps_embed_key'    => 'text',

            /*
             * Cloudflare Turnstile, for the public community submission form.
             *
             * The site key is 'text' for the same reason the Maps key is: it is
             * rendered into the widget on a public page, so anybody can already
             * read it and hiding it in the form would stop an admin checking
             * which key is in place without protecting anything. The secret is
             * 'secret', because it is the half that proves a token, and it is
             * never rendered back.
             *
             * Both come from the same Cloudflare account Gravity Forms already
             * uses on this site. See SFAF_Turnstile::ready(): one without the
             * other draws a widget nothing checks, so the form treats that as
             * not configured.
             */
            'turnstile_site_key'       => 'text',
            'turnstile_secret_key'     => 'secret',
        );
    }

    /** True for keys that must never be rendered back into their form field. */
    public static function is_secret( $key ) {
        $keys = self::keys();
        return isset( $keys[ $key ] ) && 'secret' === $keys[ $key ];
    }

    /* ---------------------------------------------------------------------
     * Reading
     * ------------------------------------------------------------------- */

    /** The whole store. Always an array. */
    public static function all() {
        $stored = get_option( self::OPTION, array() );
        return is_array( $stored ) ? $stored : array();
    }

    /**
     * One credential.
     *
     * Returned trimmed of surrounding whitespace — a credential pasted with a
     * stray newline should still work — but otherwise untouched.
     *
     * @param string $key
     * @param string $default
     * @return string
     */
    public static function get( $key, $default = '' ) {
        $all = self::all();
        if ( ! isset( $all[ $key ] ) ) {
            return $default;
        }
        $value = trim( (string) $all[ $key ] );
        return ( '' === $value ) ? $default : $value;
    }

    /** Whether a credential is on file, without revealing it. */
    public static function has( $key ) {
        return '' !== self::get( $key );
    }

    /* ---------------------------------------------------------------------
     * Writing — one key at a time, never wholesale
     * ------------------------------------------------------------------- */

    /**
     * Store one credential.
     *
     * Read-modify-write of a single key: a caller that knows about one
     * credential cannot disturb any other.
     *
     * @param string $key
     * @param string $value
     * @return bool Whether anything changed.
     */
    public static function set( $key, $value ) {
        $keys = self::keys();
        if ( ! isset( $keys[ $key ] ) ) {
            return false;
        }

        $all     = self::all();
        $value   = (string) $value;
        $current = isset( $all[ $key ] ) ? (string) $all[ $key ] : '';

        if ( $current === $value ) {
            return false;
        }

        $all[ $key ] = $value;
        update_option( self::OPTION, $all, true );
        return true;
    }

    /**
     * Take whatever the settings form submitted.
     *
     * Called from the uc_settings sanitize callback, which is the one place
     * that sees the submitted values — but the credentials are written here
     * and deliberately left out of the array that callback returns, so they
     * never enter uc_settings in the first place.
     *
     * A key absent from the submission is left alone entirely, so a partial
     * form can never blank a credential it did not include.
     *
     * @param array $input Raw $_POST['uc_settings'], already unslashed by core.
     */
    public static function absorb( $input ) {
        if ( ! is_array( $input ) ) {
            return;
        }

        $all     = self::all();
        $changed = false;

        foreach ( self::keys() as $key => $type ) {
            if ( ! array_key_exists( $key, $input ) ) {
                continue;
            }

            $raw = is_scalar( $input[ $key ] ) ? (string) $input[ $key ] : '';

            if ( 'secret' === $type ) {
                // Write-only: blank means the field was not retyped, which
                // means keep what is stored — never clear it.
                $value = trim( $raw );
                if ( '' === $value ) {
                    continue;
                }
            } elseif ( 'url' === $type ) {
                $trimmed = trim( $raw );
                $value   = ( '' === $trimmed ) ? '' : esc_url_raw( $trimmed );
            } else {
                $value = sanitize_text_field( $raw );
            }

            $current = isset( $all[ $key ] ) ? (string) $all[ $key ] : '';
            if ( $current !== $value ) {
                $all[ $key ] = $value;
                $changed     = true;
            }
        }

        if ( $changed ) {
            update_option( self::OPTION, $all, true );
        }
    }

    /* ---------------------------------------------------------------------
     * Migration out of uc_settings
     * ------------------------------------------------------------------- */

    /**
     * Copy any credential still living in uc_settings into this store.
     *
     * Deliberately never overwrites a value already held here, which makes it
     * idempotent and safe to run on every request: once a credential has been
     * moved, or re-entered, the copy left in uc_settings is ignored forever.
     *
     * Nothing is deleted from uc_settings here. Removing keys would mean
     * writing that option back through its own sanitize callback, which is the
     * fragile path this whole file exists to get away from. The stale copies
     * are simply never read again, and the next settings save drops them on
     * its own because the sanitize callback no longer emits those keys.
     */
    public static function migrate() {
        $settings = get_option( 'uc_settings', array() );
        if ( ! is_array( $settings ) || empty( $settings ) ) {
            return;
        }

        $all   = self::all();
        $moved = false;

        foreach ( self::keys() as $key => $type ) {
            // Already held here — this store always wins.
            if ( isset( $all[ $key ] ) && '' !== trim( (string) $all[ $key ] ) ) {
                continue;
            }
            if ( ! isset( $settings[ $key ] ) || ! is_scalar( $settings[ $key ] ) ) {
                continue;
            }

            $value = (string) $settings[ $key ];
            if ( '' === trim( $value ) ) {
                continue;
            }

            // Stored exactly as found: a credential must survive the move
            // byte-for-byte.
            $all[ $key ] = $value;
            $moved       = true;
        }

        if ( $moved ) {
            update_option( self::OPTION, $all, true );
        }
    }
}
