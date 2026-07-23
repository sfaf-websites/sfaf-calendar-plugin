<?php
/**
 * Satellite admin: Settings (main URL, API key, interval, Sync Now) +
 * Shortcode Generator. No event editing UI, no integration panels.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Sat_Admin {

    const OPT = 'sfaf_sat_settings';

    public function register() {
        add_action( 'admin_menu', array( $this, 'menu' ) );
        add_action( 'admin_init', array( $this, 'settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
    }

    public function menu() {
        add_menu_page( 'SFAF Calendar', 'SFAF Calendar', 'manage_options', 'sfaf-sat-settings', array( $this, 'render_settings' ), 'dashicons-calendar-alt', 30 );
        add_submenu_page( 'sfaf-sat-settings', 'Settings', 'Settings', 'manage_options', 'sfaf-sat-settings', array( $this, 'render_settings' ) );
        add_submenu_page( 'sfaf-sat-settings', 'Shortcode Generator', 'Shortcode Generator', 'manage_options', 'sfaf-sat-generator', array( $this, 'render_generator' ) );
    }

    public function settings() {
        register_setting( self::OPT, self::OPT, array( $this, 'sanitize' ) );
        // Display settings live in `uc_settings` so the shared shortcode code
        // (which reads get_option('uc_settings')) works unchanged on the satellite.
        register_setting( 'uc_settings', 'uc_settings', array( $this, 'sanitize_display' ) );
    }

    public function sanitize( $input ) {
        $intervals = array( '5', '15', '30', '60' );
        return array(
            'main_site_url' => isset( $input['main_site_url'] ) ? esc_url_raw( $input['main_site_url'] ) : '',
            'api_key'       => isset( $input['api_key'] ) ? sanitize_text_field( $input['api_key'] ) : '',
            'sync_interval' => ( isset( $input['sync_interval'] ) && in_array( $input['sync_interval'], $intervals, true ) ) ? $input['sync_interval'] : '15',
        );
    }

    public function sanitize_display( $input ) {
        $pg_styles = array( 'load_more', 'pages', 'infinite' );
        return array(
            'display_per_page'   => ( isset( $input['display_per_page'] ) && $input['display_per_page'] !== '' ) ? intval( $input['display_per_page'] ) : 12,
            'display_pagination' => ( isset( $input['display_pagination'] ) && in_array( $input['display_pagination'], $pg_styles, true ) ) ? $input['display_pagination'] : 'load_more',
        );
    }

    public function assets( $hook ) {
        if ( ! isset( $_GET['page'] ) || ! in_array( $_GET['page'], array( 'sfaf-sat-settings', 'sfaf-sat-generator' ), true ) ) {
            return;
        }
        wp_enqueue_style( 'sfaf-sat-admin', SFAF_PLUGIN_URL . 'public/css/admin.css', array(), SFAF_VERSION );
        wp_enqueue_script( 'sfaf-sat-admin', SFAF_PLUGIN_URL . 'public/js/admin.js', array( 'jquery' ), SFAF_VERSION, true );
        wp_localize_script( 'sfaf-sat-admin', 'sfafSat', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'sfaf_sat_nonce' ),
        ) );
    }

    private function get( $key, $default = '' ) {
        $s = get_option( self::OPT, array() );
        return isset( $s[ $key ] ) ? $s[ $key ] : $default;
    }

    public function render_settings() {
        $main      = $this->get( 'main_site_url' );
        $last_sync = get_option( 'sfaf_sat_last_sync', '' );
        $endpoint  = $main ? trailingslashit( $main ) . 'wp-json/sfaf-calendar/v1/events' : '';
        ?>
        <div class="wrap sfaf-sat-wrap">
            <h1>SFAF Calendar Satellite</h1>
            <p class="description">Pull events from the main SFAF Calendar site and display them here. This site never sends data back.</p>

            <form method="post" action="options.php" class="sfaf-sat-card">
                <?php settings_fields( self::OPT ); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="sfaf-main-url">Main Site URL</label></th>
                        <td><input type="url" id="sfaf-main-url" name="<?php echo esc_attr( self::OPT ); ?>[main_site_url]" value="<?php echo esc_attr( $main ); ?>" class="regular-text code" placeholder="https://marketingmarksolutions.com" />
                            <?php if ( $endpoint ) : ?><p class="description">Feed: <code><?php echo esc_html( $endpoint ); ?></code></p><?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="sfaf-api-key">API Key</label></th>
                        <td><input type="text" id="sfaf-api-key" name="<?php echo esc_attr( self::OPT ); ?>[api_key]" value="<?php echo esc_attr( $this->get( 'api_key' ) ); ?>" class="regular-text code" placeholder="Paste the key generated on the main site" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="sfaf-interval">Sync Interval</label></th>
                        <td>
                            <select id="sfaf-interval" name="<?php echo esc_attr( self::OPT ); ?>[sync_interval]">
                                <?php
                                $cur = $this->get( 'sync_interval', '15' );
                                foreach ( array( '5' => '5 minutes', '15' => '15 minutes', '30' => '30 minutes', '60' => '1 hour' ) as $v => $l ) {
                                    printf( '<option value="%s" %s>%s</option>', esc_attr( $v ), selected( $cur, $v, false ), esc_html( $l ) );
                                }
                                ?>
                            </select>
                        </td>
                    </tr>
                </table>
                <?php submit_button( 'Save Settings' ); ?>
            </form>

            <?php
            $disp     = get_option( 'uc_settings', array() );
            $per_page = ( isset( $disp['display_per_page'] ) && $disp['display_per_page'] !== '' ) ? $disp['display_per_page'] : 12;
            $pg_style = isset( $disp['display_pagination'] ) ? $disp['display_pagination'] : 'load_more';
            $pg_opts  = array(
                'load_more' => 'Load More button — fetches the next batch and appends it',
                'pages'     => 'Next / Previous pages — numbered page links below the list',
                'infinite'  => 'Infinite scroll — loads more automatically near the bottom',
            );
            ?>
            <form method="post" action="options.php" class="sfaf-sat-card">
                <?php settings_fields( 'uc_settings' ); ?>
                <h2>Display</h2>
                <p class="description">How many events to show and how visitors page through them.</p>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="sfaf-pp">Events per page</label></th>
                        <td>
                            <input type="number" id="sfaf-pp" name="uc_settings[display_per_page]" value="<?php echo esc_attr( $per_page ); ?>" min="-1" step="1" class="small-text" />
                            <p class="description">Default 12. Use 0 or -1 to show all events with no pagination. Override per shortcode with <code>per_page="20"</code>.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Pagination style</th>
                        <td>
                            <fieldset>
                                <?php foreach ( $pg_opts as $val => $label ) : ?>
                                    <label style="display:block;margin-bottom:6px;">
                                        <input type="radio" name="uc_settings[display_pagination]" value="<?php echo esc_attr( $val ); ?>" <?php checked( $pg_style, $val ); ?> />
                                        <?php echo esc_html( $label ); ?>
                                    </label>
                                <?php endforeach; ?>
                            </fieldset>
                        </td>
                    </tr>
                </table>
                <?php submit_button( 'Save Display Settings' ); ?>
            </form>

            <div class="sfaf-sat-card">
                <h2>Manual Sync</h2>
                <p>
                    <button type="button" class="button button-secondary" id="sfaf-sat-sync-now">Sync Now</button>
                    <span id="sfaf-sat-sync-status" class="sfaf-sat-status"></span>
                </p>
                <p class="description">Last sync: <span id="sfaf-sat-last-sync"><?php echo $last_sync ? esc_html( $last_sync ) : 'Never'; ?></span></p>
            </div>

            <div class="sfaf-sat-card">
                <h2>Reset Data</h2>
                <p class="description">Permanently delete every event (and its stored data) on this satellite. Use this to clear stale events, then run a fresh sync. This only affects this site &mdash; the main site is never touched.</p>
                <p>
                    <button type="button" class="button button-link-delete" id="sfaf-sat-reset">Reset All Events</button>
                    <span id="sfaf-sat-reset-status" class="sfaf-sat-status"></span>
                </p>
            </div>
        </div>
        <?php
    }

    public function render_generator() {
        $categories = get_terms( array( 'taxonomy' => 'uc_event_category', 'hide_empty' => false ) );
        $organizers = get_terms( array( 'taxonomy' => 'uc_organizer', 'hide_empty' => false ) );
        ?>
        <div class="wrap sfaf-sat-wrap">
            <h1>Shortcode Generator</h1>
            <p class="description">Build a calendar shortcode, then paste it into any page or post.</p>

            <div class="sfaf-sat-card uc-generator">
                <div class="uc-generator-grid">
                    <div class="uc-gen-field">
                        <label for="uc-gen-type">Shortcode Type</label>
                        <select id="uc-gen-type" class="uc-gen-input">
                            <option value="sfaf_calendar">[sfaf_calendar] — Full calendar</option>
                            <option value="upcoming_events">[upcoming_events] — Compact widget</option>
                        </select>
                    </div>
                    <div class="uc-gen-field">
                        <label for="uc-gen-category">Category Filter</label>
                        <select id="uc-gen-category" class="uc-gen-input">
                            <option value="">All categories</option>
                            <?php if ( ! is_wp_error( $categories ) ) : foreach ( $categories as $cat ) : ?>
                                <option value="<?php echo esc_attr( $cat->slug ); ?>"><?php echo esc_html( $cat->name ); ?></option>
                            <?php endforeach; endif; ?>
                        </select>
                    </div>
                    <div class="uc-gen-field">
                        <label for="uc-gen-organizer">Organizer Filter</label>
                        <select id="uc-gen-organizer" class="uc-gen-input">
                            <option value="">All organizers</option>
                            <?php if ( ! is_wp_error( $organizers ) ) : foreach ( $organizers as $org ) : ?>
                                <option value="<?php echo esc_attr( $org->slug ); ?>"><?php echo esc_html( $org->name ); ?></option>
                            <?php endforeach; endif; ?>
                        </select>
                    </div>
                    <div class="uc-gen-field">
                        <label for="uc-gen-count">Number of Events</label>
                        <input type="number" id="uc-gen-count" class="uc-gen-input" value="12" min="1" max="100" />
                    </div>
                    <div class="uc-gen-field uc-gen-calendar-only">
                        <label for="uc-gen-layout">Layout Style</label>
                        <select id="uc-gen-layout" class="uc-gen-input">
                            <option value="cards">Cards</option>
                            <option value="compact">Compact list</option>
                        </select>
                    </div>
                    <div class="uc-gen-field uc-gen-calendar-only">
                        <label class="uc-gen-checkbox"><input type="checkbox" id="uc-gen-filters" checked /> Show filters &amp; search bar</label>
                    </div>
                </div>

                <div class="uc-gen-output-wrap">
                    <label>Generated Shortcode</label>
                    <div class="uc-gen-output-row">
                        <input type="text" id="uc-gen-output" class="uc-gen-output" readonly value="[sfaf_calendar]" onfocus="this.select();" />
                        <button type="button" class="button button-primary" id="uc-gen-copy">Copy</button>
                    </div>
                    <span class="uc-gen-copied" id="uc-gen-copied" style="display:none;">Copied!</span>
                </div>
            </div>
        </div>
        <?php
    }
}
