<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class UC_Post_Types {

    public function register() {
        add_action( 'init', array( $this, 'register_post_type' ), 0 );
        add_action( 'init', array( $this, 'register_taxonomies' ), 0 );
        add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
        add_action( 'save_post_uc_event', array( $this, 'save_meta' ) );
    }

    public function register_post_type() {
        register_post_type( 'uc_event', array(
            'labels' => array(
                'name'               => 'Events',
                'singular_name'      => 'Event',
                'add_new'            => 'Add New Event',
                'add_new_item'       => 'Add New Event',
                'edit_item'          => 'Edit Event',
                'view_item'          => 'View Event',
                'search_items'       => 'Search Events',
                'not_found'          => 'No events found',
                'menu_name'          => 'Unified Calendar',
            ),
            'public'             => true,
            'has_archive'        => true,
            'rewrite'            => array( 'slug' => 'events' ),
            'menu_icon'          => 'dashicons-calendar-alt',
            'menu_position'      => 5,
            'supports'           => array( 'title', 'editor', 'thumbnail', 'excerpt' ),
            'show_in_rest'       => true,
        ) );
    }

    public function register_taxonomies() {
        register_taxonomy( 'uc_event_category', 'uc_event', array(
            'labels' => array(
                'name'          => 'Event Categories',
                'singular_name' => 'Event Category',
                'add_new_item'  => 'Add New Category',
                'menu_name'     => 'Categories',
            ),
            'hierarchical' => true,
            'public'       => true,
            'rewrite'      => array( 'slug' => 'event-category' ),
            'show_in_rest' => true,
        ) );

        register_taxonomy( 'uc_organizer', 'uc_event', array(
            'labels' => array(
                'name'          => 'Organizers',
                'singular_name' => 'Organizer',
                'add_new_item'  => 'Add New Organizer',
                'menu_name'     => 'Organizers',
            ),
            'hierarchical' => false,
            'public'       => true,
            'rewrite'      => array( 'slug' => 'event-organizer' ),
            'show_in_rest' => true,
        ) );

        register_taxonomy( 'uc_venue', 'uc_event', array(
            'labels' => array(
                'name'          => 'Venues',
                'singular_name' => 'Venue',
                'add_new_item'  => 'Add New Venue',
                'menu_name'     => 'Venues',
            ),
            'hierarchical' => false,
            'public'       => true,
            'rewrite'      => array( 'slug' => 'event-venue' ),
            'show_in_rest' => true,
        ) );
    }

    public function add_meta_boxes() {
        add_meta_box(
            'uc_event_details',
            'Event Details',
            array( $this, 'render_meta_box' ),
            'uc_event',
            'normal',
            'high'
        );
        add_meta_box(
            'uc_event_rsvp_settings',
            'RSVP Settings',
            array( $this, 'render_rsvp_meta_box' ),
            'uc_event',
            'side',
            'default'
        );
        add_meta_box(
            'uc_event_integrations',
            'Integrations',
            array( $this, 'render_integrations_meta_box' ),
            'uc_event',
            'side',
            'default'
        );
    }

    public function render_meta_box( $post ) {
        wp_nonce_field( 'uc_save_event', 'uc_event_nonce' );

        $date       = get_post_meta( $post->ID, '_uc_event_date', true );
        $start_time = get_post_meta( $post->ID, '_uc_start_time', true );
        $end_time   = get_post_meta( $post->ID, '_uc_end_time', true );
        $location   = get_post_meta( $post->ID, '_uc_location', true );
        $recurrence = get_post_meta( $post->ID, '_uc_recurrence', true );
        $end_date   = get_post_meta( $post->ID, '_uc_end_date', true );
        ?>
        <div class="uc-meta-box">
            <div class="uc-meta-row">
                <div class="uc-meta-field">
                    <label for="uc_event_date">Event Date</label>
                    <input type="date" id="uc_event_date" name="uc_event_date" value="<?php echo esc_attr( $date ); ?>" />
                </div>
                <div class="uc-meta-field">
                    <label for="uc_start_time">Start Time</label>
                    <input type="time" id="uc_start_time" name="uc_start_time" value="<?php echo esc_attr( $start_time ); ?>" />
                </div>
                <div class="uc-meta-field">
                    <label for="uc_end_time">End Time</label>
                    <input type="time" id="uc_end_time" name="uc_end_time" value="<?php echo esc_attr( $end_time ); ?>" />
                </div>
            </div>
            <div class="uc-meta-row">
                <div class="uc-meta-field" style="flex: 2;">
                    <label for="uc_location">Location</label>
                    <input type="text" id="uc_location" name="uc_location" value="<?php echo esc_attr( $location ); ?>" placeholder="e.g., Strut - 470 Castro St" />
                </div>
            </div>
            <div class="uc-meta-row">
                <div class="uc-meta-field">
                    <label for="uc_recurrence">Recurrence</label>
                    <select id="uc_recurrence" name="uc_recurrence">
                        <option value="" <?php selected( $recurrence, '' ); ?>>Does not repeat</option>
                        <option value="daily" <?php selected( $recurrence, 'daily' ); ?>>Daily</option>
                        <option value="weekly" <?php selected( $recurrence, 'weekly' ); ?>>Weekly</option>
                        <option value="biweekly" <?php selected( $recurrence, 'biweekly' ); ?>>Every 2 Weeks</option>
                        <option value="monthly" <?php selected( $recurrence, 'monthly' ); ?>>Monthly</option>
                    </select>
                </div>
                <div class="uc-meta-field">
                    <label for="uc_end_date">Series End Date (if recurring)</label>
                    <input type="date" id="uc_end_date" name="uc_end_date" value="<?php echo esc_attr( $end_date ); ?>" />
                </div>
            </div>
        </div>
        <?php
    }

    public function render_rsvp_meta_box( $post ) {
        $rsvp_enabled = get_post_meta( $post->ID, '_uc_rsvp_enabled', true );
        $capacity     = get_post_meta( $post->ID, '_uc_capacity', true );
        $rsvp_count   = uc_get_rsvp_count( $post->ID );
        ?>
        <div class="uc-meta-box">
            <label style="display: flex; align-items: center; gap: 8px; margin-bottom: 12px;">
                <input type="checkbox" name="uc_rsvp_enabled" value="1" <?php checked( $rsvp_enabled, '1' ); ?> />
                <strong>Enable RSVP</strong>
            </label>
            <div class="uc-meta-field">
                <label for="uc_capacity">Capacity (0 = unlimited)</label>
                <input type="number" id="uc_capacity" name="uc_capacity" value="<?php echo esc_attr( $capacity ); ?>" min="0" />
            </div>
            <?php if ( $post->ID && $rsvp_count > 0 ) : ?>
                <div class="uc-rsvp-count">
                    <strong><?php echo $rsvp_count; ?></strong> confirmed RSVPs
                    <a href="<?php echo admin_url( 'admin.php?page=uc-rsvps&event_id=' . $post->ID ); ?>">View list</a>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    public function render_integrations_meta_box( $post ) {
        $pardot_campaign = get_post_meta( $post->ID, '_uc_pardot_campaign', true );
        $gofundme_id     = get_post_meta( $post->ID, '_uc_gofundme_campaign', true );
        $pardot_notify   = get_post_meta( $post->ID, '_uc_pardot_notify', true );
        ?>
        <div class="uc-meta-box">
            <div class="uc-meta-field">
                <label for="uc_gofundme_campaign">💚 GoFundMe Pro Campaign ID</label>
                <input type="text" id="uc_gofundme_campaign" name="uc_gofundme_campaign" 
                       value="<?php echo esc_attr( $gofundme_id ); ?>" placeholder="Optional" />
                <p class="description">Shows live fundraising progress bar on this event.</p>
            </div>
            <div class="uc-meta-field">
                <label for="uc_pardot_campaign">☁️ Pardot Campaign</label>
                <select id="uc_pardot_campaign" name="uc_pardot_campaign">
                    <option value="">None</option>
                    <option value="support-2026" <?php selected( $pardot_campaign, 'support-2026' ); ?>>SFAF - Support Services 2026</option>
                    <option value="fundraising-2026" <?php selected( $pardot_campaign, 'fundraising-2026' ); ?>>SFAF - Fundraising Events 2026</option>
                    <option value="volunteer-2026" <?php selected( $pardot_campaign, 'volunteer-2026' ); ?>>SFAF - Volunteer Recruitment 2026</option>
                    <option value="general-2026" <?php selected( $pardot_campaign, 'general-2026' ); ?>>SFAF - General Community 2026</option>
                </select>
            </div>
            <label style="display: flex; align-items: center; gap: 8px; margin-top: 8px;">
                <input type="checkbox" name="uc_pardot_notify" value="1" <?php checked( $pardot_notify, '1' ); ?> />
                Send Pardot email on publish
            </label>
        </div>
        <?php
    }

    public function save_meta( $post_id ) {
        if ( ! isset( $_POST['uc_event_nonce'] ) || ! wp_verify_nonce( $_POST['uc_event_nonce'], 'uc_save_event' ) ) {
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        $fields = array(
            'uc_event_date'        => '_uc_event_date',
            'uc_start_time'        => '_uc_start_time',
            'uc_end_time'          => '_uc_end_time',
            'uc_location'          => '_uc_location',
            'uc_recurrence'        => '_uc_recurrence',
            'uc_end_date'          => '_uc_end_date',
            'uc_capacity'          => '_uc_capacity',
            'uc_gofundme_campaign' => '_uc_gofundme_campaign',
            'uc_pardot_campaign'   => '_uc_pardot_campaign',
        );

        foreach ( $fields as $post_key => $meta_key ) {
            if ( isset( $_POST[ $post_key ] ) ) {
                update_post_meta( $post_id, $meta_key, sanitize_text_field( $_POST[ $post_key ] ) );
            }
        }

        // Checkboxes
        update_post_meta( $post_id, '_uc_rsvp_enabled', isset( $_POST['uc_rsvp_enabled'] ) ? '1' : '0' );
        update_post_meta( $post_id, '_uc_pardot_notify', isset( $_POST['uc_pardot_notify'] ) ? '1' : '0' );
    }
}
