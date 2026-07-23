<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class UC_Admin {

    public function register() {
        add_action( 'admin_menu', array( $this, 'add_menu_pages' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
    }

    public function add_menu_pages() {
        // RSVPs submenu under Events
        add_submenu_page(
            'edit.php?post_type=uc_event',
            'RSVPs',
            'RSVPs',
            'edit_posts',
            'uc-rsvps',
            array( $this, 'render_rsvps_page' )
        );

        // Settings / Integrations
        add_submenu_page(
            'edit.php?post_type=uc_event',
            'Settings & Integrations',
            'Settings',
            'manage_options',
            'uc-settings',
            array( $this, 'render_settings_page' )
        );
    }

    public function register_settings() {
        register_setting( 'uc_settings', 'uc_settings', array( $this, 'sanitize_settings' ) );
    }

    public function sanitize_settings( $input ) {
        $sanitized = array();
        $fields = array(
            'multisite_api_key', 'multisite_sync_interval',
            'gofundme_api_token', 'gofundme_org_id',
            'pardot_business_unit', 'google_calendar_id',
            'galaxy_api_key', 'galaxy_portal_url',
            'webhook_url', 'webhook_secret',
        );
        foreach ( $fields as $field ) {
            $sanitized[ $field ] = isset( $input[ $field ] ) ? sanitize_text_field( $input[ $field ] ) : '';
        }
        $toggles = array(
            'gofundme_show_progress', 'gofundme_auto_import',
            'pardot_auto_prospect', 'pardot_event_emails',
            'google_auto_publish', 'galaxy_auto_sync',
            'webhook_event_created', 'webhook_event_updated',
            'webhook_event_deleted', 'webhook_event_rsvp',
        );
        foreach ( $toggles as $toggle ) {
            $sanitized[ $toggle ] = isset( $input[ $toggle ] ) ? '1' : '0';
        }
        // Satellite sites (stored as JSON)
        if ( isset( $input['satellite_sites'] ) ) {
            $sanitized['satellite_sites'] = sanitize_text_field( $input['satellite_sites'] );
        }
        return $sanitized;
    }

    /**
     * Render RSVPs admin page
     */
    public function render_rsvps_page() {
        $event_id = isset( $_GET['event_id'] ) ? intval( $_GET['event_id'] ) : 0;
        $search   = isset( $_GET['s'] ) ? sanitize_text_field( $_GET['s'] ) : '';
        $rsvps    = UC_RSVP::get_all_rsvps( array( 'event_id' => $event_id, 'search' => $search ) );

        $export_url = admin_url( 'admin-ajax.php?action=uc_export_rsvps' );
        if ( $event_id ) {
            $export_url .= '&event_id=' . $event_id;
        }
        ?>
        <div class="wrap uc-admin-wrap">
            <div class="uc-admin-header">
                <div>
                    <h1>RSVPs</h1>
                    <p class="uc-subtitle">
                        <?php if ( $event_id ) : ?>
                            Registrations for: <strong><?php echo get_the_title( $event_id ); ?></strong>
                            &nbsp;<a href="<?php echo admin_url( 'admin.php?page=uc-rsvps' ); ?>">View all</a>
                        <?php else : ?>
                            All event registrations
                        <?php endif; ?>
                    </p>
                </div>
                <div class="uc-admin-actions">
                    <a href="<?php echo esc_url( $export_url ); ?>" class="button">Export CSV</a>
                    <a href="<?php echo esc_url( $export_url ); ?>" class="button" title="CSV can be imported into Google Sheets">Export to Google Sheets</a>
                </div>
            </div>

            <div class="uc-admin-card">
                <!-- Search -->
                <form method="get" class="uc-rsvp-search">
                    <input type="hidden" name="post_type" value="uc_event" />
                    <input type="hidden" name="page" value="uc-rsvps" />
                    <?php if ( $event_id ) : ?>
                        <input type="hidden" name="event_id" value="<?php echo $event_id; ?>" />
                    <?php endif; ?>
                    <input type="text" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Search by name or email..." class="uc-search-input" />
                    <button type="submit" class="button">Search</button>
                </form>

                <div class="uc-rsvp-count-bar">
                    <strong><?php echo count( $rsvps ); ?></strong> registrations found
                </div>

                <table class="uc-admin-table">
                    <thead>
                        <tr>
                            <th>Event</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>Status</th>
                            <th>Registered</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $rsvps ) ) : ?>
                            <tr><td colspan="6" class="uc-no-data">No RSVPs found.</td></tr>
                        <?php else : ?>
                            <?php foreach ( $rsvps as $rsvp ) : ?>
                                <tr>
                                    <td>
                                        <a href="<?php echo admin_url( 'admin.php?page=uc-rsvps&event_id=' . $rsvp->event_id ); ?>">
                                            <?php echo esc_html( $rsvp->event_title ?? get_the_title( $rsvp->event_id ) ); ?>
                                        </a>
                                    </td>
                                    <td><strong><?php echo esc_html( $rsvp->name ); ?></strong></td>
                                    <td><?php echo esc_html( $rsvp->email ); ?></td>
                                    <td><?php echo esc_html( $rsvp->phone ); ?></td>
                                    <td><span class="uc-status uc-status-<?php echo esc_attr( $rsvp->status ); ?>"><?php echo esc_html( ucfirst( $rsvp->status ) ); ?></span></td>
                                    <td><?php echo date( 'M j, Y g:i A', strtotime( $rsvp->created_at ) ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    /**
     * Render Settings / Integrations page
     */
    public function render_settings_page() {
        $settings = get_option( 'uc_settings', array() );
        $s = function( $key, $default = '' ) use ( $settings ) {
            return isset( $settings[ $key ] ) ? $settings[ $key ] : $default;
        };
        ?>
        <div class="wrap uc-admin-wrap">
            <div class="uc-admin-header">
                <div>
                    <h1>Settings & Integrations</h1>
                    <p class="uc-subtitle">Connect your calendar to external services and sync events across your digital ecosystem</p>
                </div>
            </div>

            <form method="post" action="options.php">
                <?php settings_fields( 'uc_settings' ); ?>

                <!-- MULTI-SITE API -->
                <div class="uc-integration-panel">
                    <div class="uc-panel-header" onclick="this.parentElement.classList.toggle('uc-panel-open')">
                        <span class="uc-panel-icon">🔗</span>
                        <div class="uc-panel-info">
                            <h2>Multi-Site API</h2>
                            <p>Sync events across multiple WordPress sites via REST API</p>
                        </div>
                        <span class="uc-panel-status uc-status-active">Active</span>
                        <span class="uc-panel-toggle">&#9660;</span>
                    </div>
                    <div class="uc-panel-body">
                        <div class="uc-field-row">
                            <label>API Endpoint</label>
                            <input type="text" value="<?php echo esc_url( rest_url( 'unified-calendar/v1/' ) ); ?>" readonly class="uc-input uc-monospace" />
                        </div>
                        <div class="uc-field-row">
                            <label>API Key</label>
                            <input type="text" name="uc_settings[multisite_api_key]" value="<?php echo esc_attr( $s( 'multisite_api_key' ) ); ?>" class="uc-input" placeholder="Generate or enter API key" />
                        </div>
                        <div class="uc-field-row">
                            <label>Sync Interval</label>
                            <select name="uc_settings[multisite_sync_interval]" class="uc-input">
                                <option value="5" <?php selected( $s( 'multisite_sync_interval' ), '5' ); ?>>5 minutes</option>
                                <option value="15" <?php selected( $s( 'multisite_sync_interval', '15' ), '15' ); ?>>15 minutes</option>
                                <option value="30" <?php selected( $s( 'multisite_sync_interval' ), '30' ); ?>>30 minutes</option>
                                <option value="60" <?php selected( $s( 'multisite_sync_interval' ), '60' ); ?>>1 hour</option>
                            </select>
                        </div>
                        <div class="uc-satellite-sites">
                            <h3>Connected Satellite Sites</h3>
                            <div class="uc-satellite-list">
                                <div class="uc-satellite-item">
                                    <span class="uc-status-dot uc-dot-green"></span>
                                    <code>annualreport.sfaf.org</code>
                                    <span class="uc-muted">Last sync: 2 min ago</span>
                                </div>
                                <div class="uc-satellite-item">
                                    <span class="uc-status-dot uc-dot-green"></span>
                                    <code>strutsf.org</code>
                                    <span class="uc-muted">Last sync: 4 min ago</span>
                                </div>
                                <div class="uc-satellite-item">
                                    <span class="uc-status-dot uc-dot-yellow"></span>
                                    <code>devhelp.sfaf.org</code>
                                    <span class="uc-muted">Pending setup</span>
                                </div>
                            </div>
                            <button type="button" class="button uc-add-satellite">+ Add Satellite Site</button>
                        </div>
                    </div>
                </div>

                <!-- GOFUNDME PRO -->
                <div class="uc-integration-panel">
                    <div class="uc-panel-header" onclick="this.parentElement.classList.toggle('uc-panel-open')">
                        <span class="uc-panel-icon">💚</span>
                        <div class="uc-panel-info">
                            <h2>GoFundMe Pro</h2>
                            <p>Pull fundraising campaigns and display live progress on event cards</p>
                        </div>
                        <span class="uc-panel-status uc-status-active">Active</span>
                        <span class="uc-panel-toggle">&#9660;</span>
                    </div>
                    <div class="uc-panel-body">
                        <div class="uc-field-row">
                            <label>API Token</label>
                            <input type="password" name="uc_settings[gofundme_api_token]" value="<?php echo esc_attr( $s( 'gofundme_api_token' ) ); ?>" class="uc-input" />
                        </div>
                        <div class="uc-field-row">
                            <label>Organization ID</label>
                            <input type="text" name="uc_settings[gofundme_org_id]" value="<?php echo esc_attr( $s( 'gofundme_org_id' ) ); ?>" class="uc-input" />
                        </div>
                        <div class="uc-field-row">
                            <label>Show Progress Bars</label>
                            <label class="uc-toggle"><input type="checkbox" name="uc_settings[gofundme_show_progress]" value="1" <?php checked( $s( 'gofundme_show_progress' ), '1' ); ?> /><span class="uc-toggle-slider"></span></label>
                        </div>
                        <div class="uc-field-row">
                            <label>Auto-Import Campaigns as Events</label>
                            <label class="uc-toggle"><input type="checkbox" name="uc_settings[gofundme_auto_import]" value="1" <?php checked( $s( 'gofundme_auto_import' ), '1' ); ?> /><span class="uc-toggle-slider"></span></label>
                        </div>
                    </div>
                </div>

                <!-- PARDOT / SALESFORCE -->
                <div class="uc-integration-panel">
                    <div class="uc-panel-header" onclick="this.parentElement.classList.toggle('uc-panel-open')">
                        <span class="uc-panel-icon">☁️</span>
                        <div class="uc-panel-info">
                            <h2>Pardot / Salesforce</h2>
                            <p>Push event engagement to Pardot for prospect tracking and automated nurture</p>
                        </div>
                        <span class="uc-panel-status uc-status-active">Active</span>
                        <span class="uc-panel-toggle">&#9660;</span>
                    </div>
                    <div class="uc-panel-body">
                        <div class="uc-field-row">
                            <label>Salesforce SSO</label>
                            <button type="button" class="button">Connect Salesforce Account</button>
                        </div>
                        <div class="uc-field-row">
                            <label>Business Unit ID</label>
                            <input type="text" name="uc_settings[pardot_business_unit]" value="<?php echo esc_attr( $s( 'pardot_business_unit' ) ); ?>" class="uc-input" />
                        </div>
                        <div class="uc-field-row">
                            <label>Auto-Create Prospect on RSVP</label>
                            <label class="uc-toggle"><input type="checkbox" name="uc_settings[pardot_auto_prospect]" value="1" <?php checked( $s( 'pardot_auto_prospect' ), '1' ); ?> /><span class="uc-toggle-slider"></span></label>
                        </div>
                        <div class="uc-field-row">
                            <label>Event Notification Emails</label>
                            <label class="uc-toggle"><input type="checkbox" name="uc_settings[pardot_event_emails]" value="1" <?php checked( $s( 'pardot_event_emails' ), '1' ); ?> /><span class="uc-toggle-slider"></span></label>
                        </div>
                        <div class="uc-campaign-map">
                            <h3>Category to Campaign Mapping</h3>
                            <div class="uc-map-row"><span>Support Groups</span><span>→</span><code>SFAF - Support Services 2026</code></div>
                            <div class="uc-map-row"><span>Fundraising</span><span>→</span><code>SFAF - Fundraising Events 2026</code></div>
                            <div class="uc-map-row"><span>Volunteer</span><span>→</span><code>SFAF - Volunteer Recruitment 2026</code></div>
                        </div>
                    </div>
                </div>

                <!-- GOOGLE CALENDAR -->
                <div class="uc-integration-panel">
                    <div class="uc-panel-header" onclick="this.parentElement.classList.toggle('uc-panel-open')">
                        <span class="uc-panel-icon">📅</span>
                        <div class="uc-panel-info">
                            <h2>Google Calendar</h2>
                            <p>Two-way sync between WordPress events and Google Workspace calendars</p>
                        </div>
                        <span class="uc-panel-status uc-status-connected">Connected</span>
                        <span class="uc-panel-toggle">&#9660;</span>
                    </div>
                    <div class="uc-panel-body">
                        <div class="uc-field-row">
                            <label>Google Calendar ID</label>
                            <input type="text" name="uc_settings[google_calendar_id]" value="<?php echo esc_attr( $s( 'google_calendar_id' ) ); ?>" class="uc-input" placeholder="events@sfaf.org" />
                        </div>
                        <div class="uc-field-row">
                            <label>Auto-Publish to Public Calendar</label>
                            <label class="uc-toggle"><input type="checkbox" name="uc_settings[google_auto_publish]" value="1" <?php checked( $s( 'google_auto_publish' ), '1' ); ?> /><span class="uc-toggle-slider"></span></label>
                        </div>
                    </div>
                </div>

                <!-- GALAXY DIGITAL -->
                <div class="uc-integration-panel">
                    <div class="uc-panel-header" onclick="this.parentElement.classList.toggle('uc-panel-open')">
                        <span class="uc-panel-icon">🤝</span>
                        <div class="uc-panel-info">
                            <h2>Galaxy Digital</h2>
                            <p>Import volunteer opportunities from volunteers.sfaf.org</p>
                        </div>
                        <span class="uc-panel-status uc-status-connected">Connected</span>
                        <span class="uc-panel-toggle">&#9660;</span>
                    </div>
                    <div class="uc-panel-body">
                        <div class="uc-field-row">
                            <label>Portal URL</label>
                            <input type="url" name="uc_settings[galaxy_portal_url]" value="<?php echo esc_attr( $s( 'galaxy_portal_url' ) ); ?>" class="uc-input" placeholder="https://volunteers.sfaf.org" />
                        </div>
                        <div class="uc-field-row">
                            <label>API Key</label>
                            <input type="password" name="uc_settings[galaxy_api_key]" value="<?php echo esc_attr( $s( 'galaxy_api_key' ) ); ?>" class="uc-input" />
                        </div>
                        <div class="uc-field-row">
                            <label>Auto-Sync New Opportunities</label>
                            <label class="uc-toggle"><input type="checkbox" name="uc_settings[galaxy_auto_sync]" value="1" <?php checked( $s( 'galaxy_auto_sync' ), '1' ); ?> /><span class="uc-toggle-slider"></span></label>
                        </div>
                    </div>
                </div>

                <!-- WEBHOOKS -->
                <div class="uc-integration-panel">
                    <div class="uc-panel-header" onclick="this.parentElement.classList.toggle('uc-panel-open')">
                        <span class="uc-panel-icon">⚡</span>
                        <div class="uc-panel-info">
                            <h2>Webhooks (n8n / Zapier)</h2>
                            <p>Fire outbound webhooks on event actions for custom automation</p>
                        </div>
                        <span class="uc-panel-status uc-status-active">Active</span>
                        <span class="uc-panel-toggle">&#9660;</span>
                    </div>
                    <div class="uc-panel-body">
                        <div class="uc-field-row">
                            <label>Webhook URL</label>
                            <input type="url" name="uc_settings[webhook_url]" value="<?php echo esc_attr( $s( 'webhook_url' ) ); ?>" class="uc-input uc-monospace" />
                        </div>
                        <div class="uc-field-row">
                            <label>Secret Key (HMAC)</label>
                            <input type="password" name="uc_settings[webhook_secret]" value="<?php echo esc_attr( $s( 'webhook_secret' ) ); ?>" class="uc-input" />
                        </div>
                        <h3>Event Triggers</h3>
                        <?php
                        $triggers = array(
                            'webhook_event_created' => 'event.created',
                            'webhook_event_updated' => 'event.updated',
                            'webhook_event_deleted' => 'event.deleted',
                            'webhook_event_rsvp'    => 'event.rsvp',
                        );
                        foreach ( $triggers as $key => $label ) :
                        ?>
                        <div class="uc-field-row uc-trigger-row">
                            <code><?php echo $label; ?></code>
                            <label class="uc-toggle"><input type="checkbox" name="uc_settings[<?php echo $key; ?>]" value="1" <?php checked( $s( $key ), '1' ); ?> /><span class="uc-toggle-slider"></span></label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="uc-save-bar">
                    <?php submit_button( 'Save All Settings', 'primary', 'submit', false ); ?>
                    <button type="button" class="button">Test All Connections</button>
                </div>
            </form>
        </div>
        <?php
    }
}
