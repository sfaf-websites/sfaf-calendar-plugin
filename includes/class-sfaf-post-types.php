<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Post_Types {

    public function register() {
        // register() is called by sfaf_init() which is itself hooked to `init`.
        // Registering the post type / taxonomies via add_action( 'init', … )
        // here would be too late (init is already running) and they'd never
        // fire — leaving the admin menu and events missing. Register directly.
        $this->register_post_type();
        $this->register_taxonomies();
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
                'menu_name'          => 'SFAF Calendar',
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
            'uc_event_confirmation_email',
            'Confirmation Email (per-event override)',
            array( $this, 'render_confirmation_email_meta_box' ),
            'uc_event',
            'normal',
            'default'
        );
        add_meta_box(
            'uc_event_faq',
            'FAQ',
            array( $this, 'render_faq_meta_box' ),
            'uc_event',
            'normal',
            'default'
        );
        add_meta_box(
            'uc_event_image',
            'Event Image',
            array( $this, 'render_image_meta_box' ),
            'uc_event',
            'side',
            'default'
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
            'uc_event_display',
            'Display Options',
            array( $this, 'render_display_meta_box' ),
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
        add_meta_box(
            'uc_event_organizer',
            'Organizer Notifications',
            array( $this, 'render_organizer_meta_box' ),
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
        $rsvp_count   = sfaf_get_rsvp_count( $post->ID );
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
                    <strong><?php echo (int) $rsvp_count; ?></strong> confirmed RSVPs
                    <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=uc_event&page=uc-rsvps&event_id=' . $post->ID ) ); ?>">View list</a>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Per-event display toggles. Default all to checked (on).
     */
    public function render_display_meta_box( $post ) {
        $features = array(
            'rsvp'      => 'Show RSVP',
            'donate'    => 'Show Donate Button',
            'social'    => 'Show Social Share',
            'calendar'  => 'Show Add to Calendar',
            'reminders' => 'Show Reminders Signup',
        );
        ?>
        <div class="uc-meta-box">
            <p class="description" style="margin-top:0;">Control which actions appear on this event's card and page.</p>
            <?php foreach ( $features as $key => $label ) : ?>
                <label class="uc-display-toggle">
                    <input type="checkbox" name="uc_show_<?php echo esc_attr( $key ); ?>" value="1" <?php checked( sfaf_show_feature( $post->ID, $key ) ); ?> />
                    <?php echo esc_html( $label ); ?>
                </label>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /**
     * GoFundMe campaign + Pardot multi-select.
     */
    public function render_integrations_meta_box( $post ) {
        $settings         = get_option( 'uc_settings', array() );
        $gf_campaigns     = isset( $settings['gofundme_campaigns'] ) && is_array( $settings['gofundme_campaigns'] ) ? $settings['gofundme_campaigns'] : array();
        $pardot_campaigns = isset( $settings['pardot_campaigns'] ) && is_array( $settings['pardot_campaigns'] ) ? $settings['pardot_campaigns'] : array();

        $gofundme_url  = get_post_meta( $post->ID, '_uc_gofundme_url', true );
        $gofundme_goal = get_post_meta( $post->ID, '_uc_gofundme_goal', true );
        $selected      = (array) get_post_meta( $post->ID, '_uc_pardot_campaigns', true );
        ?>
        <div class="uc-meta-box">
            <div class="uc-meta-field">
                <label for="uc_gofundme_select"><?php echo sfaf_icon( 'heart' ); ?> GoFundMe Campaign</label>
                <select id="uc_gofundme_select" class="uc-gofundme-select">
                    <option value="" data-goal="">— Select a saved campaign —</option>
                    <?php foreach ( $gf_campaigns as $c ) : ?>
                        <option value="<?php echo esc_attr( $c['url'] ); ?>" data-goal="<?php echo esc_attr( $c['goal'] ); ?>" <?php selected( $gofundme_url, $c['url'] ); ?>>
                            <?php echo esc_html( $c['name'] ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ( empty( $gf_campaigns ) ) : ?>
                    <p class="description">Add campaigns under Events &rsaquo; Settings &rsaquo; GoFundMe Pro.</p>
                <?php endif; ?>
            </div>
            <div class="uc-meta-field">
                <label for="uc_gofundme_url">Campaign URL</label>
                <input type="url" id="uc_gofundme_url" name="uc_gofundme_url" value="<?php echo esc_attr( $gofundme_url ); ?>" placeholder="https://gofund.me/..." />
                <input type="hidden" id="uc_gofundme_goal" name="uc_gofundme_goal" value="<?php echo esc_attr( $gofundme_goal ); ?>" />
                <p class="description">Donate button shows on this event only when a URL is set.</p>
            </div>

            <div class="uc-meta-field">
                <label><?php echo sfaf_icon( 'cloud' ); ?> Pardot Campaigns</label>
                <?php if ( empty( $pardot_campaigns ) ) : ?>
                    <p class="description">Add campaigns under Events &rsaquo; Settings &rsaquo; Pardot / Salesforce.</p>
                <?php else : ?>
                    <div class="uc-checkbox-list">
                        <?php foreach ( $pardot_campaigns as $pc ) : ?>
                            <label>
                                <input type="checkbox" name="uc_pardot_campaigns[]" value="<?php echo esc_attr( $pc['id'] ); ?>" <?php checked( in_array( $pc['id'], $selected, true ) ); ?> />
                                <?php echo esc_html( $pc['name'] ); ?> <span class="uc-muted-inline"><?php echo esc_html( $pc['id'] ); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <p class="description">This event will also inherit campaigns from: Global default + Category mapping.</p>
            </div>
        </div>
        <?php
    }

    /**
     * Organizer notification email + toggle.
     */
    public function render_organizer_meta_box( $post ) {
        $organizer_email = get_post_meta( $post->ID, '_uc_organizer_email', true );
        $notify          = get_post_meta( $post->ID, '_uc_notify_organizer', true );
        ?>
        <div class="uc-meta-box">
            <div class="uc-meta-field">
                <label for="uc_organizer_email">Organizer Email</label>
                <input type="email" id="uc_organizer_email" name="uc_organizer_email" value="<?php echo esc_attr( $organizer_email ); ?>" placeholder="organizer@sfaf.org" />
            </div>
            <label class="uc-display-toggle">
                <input type="checkbox" name="uc_notify_organizer" value="1" <?php checked( $notify, '1' ); ?> />
                Email organizer on new RSVP
            </label>
        </div>
        <?php
    }

    /**
     * Per-event confirmation email override.
     */
    public function render_confirmation_email_meta_box( $post ) {
        $subject = get_post_meta( $post->ID, '_uc_email_subject', true );
        $body    = get_post_meta( $post->ID, '_uc_email_body', true );
        $replyto = get_post_meta( $post->ID, '_uc_email_replyto', true );
        ?>
        <div class="uc-meta-box">
            <p class="description" style="margin-top:0;">Leave blank to use the global defaults from Settings &rsaquo; Email Templates.</p>
            <div class="uc-meta-row">
                <div class="uc-meta-field" style="flex:2;">
                    <label for="uc_email_subject">Subject</label>
                    <input type="text" id="uc_email_subject" name="uc_email_subject" value="<?php echo esc_attr( $subject ); ?>" placeholder="You're registered for {event_name}" />
                </div>
                <div class="uc-meta-field">
                    <label for="uc_email_replyto">Reply-To</label>
                    <input type="email" id="uc_email_replyto" name="uc_email_replyto" value="<?php echo esc_attr( $replyto ); ?>" placeholder="events@sfaf.org" />
                </div>
            </div>
            <div class="uc-meta-field">
                <label for="uc_email_body">Body</label>
                <textarea id="uc_email_body" name="uc_email_body" rows="5" placeholder="Hi {attendee_name}, you're registered for {event_name} on {event_date}."><?php echo esc_textarea( $body ); ?></textarea>
                <p class="description">Tokens: <code>{event_name}</code> <code>{attendee_name}</code> <code>{event_date}</code> <code>{event_time}</code> <code>{event_location}</code> <code>{organizer_name}</code></p>
            </div>
        </div>
        <?php
    }

    /**
     * Whether a platform owns this event's FAQ rows.
     *
     * Read from the adapter, exactly as the /caladmin portal does, so the two
     * editors cannot disagree about which rows belong to whom.
     *
     * @param int $post_id
     * @return string Platform label when owned, '' otherwise.
     */
    private function faq_source_label( $post_id ) {
        $source = (string) get_post_meta( (int) $post_id, SFAF_Sources::META_SOURCE, true );
        if ( '' === $source ) {
            return '';
        }
        if ( ! in_array( 'faqs', SFAF_Sources::owned_fields_for( $source ), true ) ) {
            return '';
        }
        return SFAF_Sources::source_label( $source );
    }

    /**
     * Reusable FAQ repeater markup for a given POST field name.
     *
     * Imported rows are listed read-only and carry no form fields, so nothing
     * about them is submitted and save_meta() reads them back from the
     * database instead. See the longer note on SFAF_Portal::faq_repeater().
     *
     * @param string $name  POST field name for the editable rows.
     * @param array  $faqs  All rows, imported and manual.
     * @param string $label Platform that owns the imported rows, or ''.
     */
    private function faq_repeater_html( $name, $faqs, $label = '' ) {
        if ( '' !== $label ) {
            $imported = array();
            $manual   = array();
            foreach ( $faqs as $f ) {
                if ( sfaf_faq_is_imported( $f ) ) {
                    $imported[] = $f;
                } else {
                    $manual[] = $f;
                }
            }
            $faqs = $manual;

            echo '<p class="description"><strong>' . (int) count( $imported ) . ' question(s) from ' . esc_html( $label )
                . '.</strong> These are refreshed from the campaign on every fetch, so they cannot be edited here — an edit would be overwritten. '
                . 'Change them at ' . esc_html( $label ) . '.</p>';
            if ( ! empty( $imported ) ) {
                echo '<ul class="uc-faq-readonly">';
                foreach ( $imported as $f ) {
                    echo '<li><strong>' . esc_html( $f['question'] ) . '</strong><br>' . esc_html( $f['answer'] ) . '</li>';
                }
                echo '</ul>';
            }
            echo '<p class="description" style="margin-top:12px;"><strong>Your own questions</strong> — kept forever, never reordered or removed by a fetch.</p>';
            echo '<input type="hidden" name="uc_faq_has_manual" value="1" />';
        }
        ?>
        <div class="uc-repeater" data-repeater="<?php echo esc_attr( $name ); ?>">
            <div class="uc-repeater-rows">
                <?php foreach ( $faqs as $i => $f ) : ?>
                    <div class="uc-repeater-row uc-faq-row">
                        <input type="text" name="<?php echo esc_attr( $name ); ?>[<?php echo (int) $i; ?>][question]" value="<?php echo esc_attr( $f['question'] ); ?>" placeholder="Question" />
                        <textarea name="<?php echo esc_attr( $name ); ?>[<?php echo (int) $i; ?>][answer]" rows="2" placeholder="Answer"><?php echo esc_textarea( $f['answer'] ); ?></textarea>
                        <button type="button" class="button uc-repeater-remove" aria-label="Remove">&times;</button>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="button uc-repeater-add">+ Add FAQ</button>
            <script type="text/html" class="uc-repeater-template">
                <div class="uc-repeater-row uc-faq-row">
                    <input type="text" name="<?php echo esc_attr( $name ); ?>[__INDEX__][question]" placeholder="Question" />
                    <textarea name="<?php echo esc_attr( $name ); ?>[__INDEX__][answer]" rows="2" placeholder="Answer"></textarea>
                    <button type="button" class="button uc-repeater-remove" aria-label="Remove">&times;</button>
                </div>
            </script>
        </div>
        <?php
    }

    /**
     * FAQ box. Parent → managed in Series Manager. Child → inherited series FAQ
     * (read-only) + editable event-specific FAQ + replace toggle. Standalone →
     * its own FAQ.
     */
    public function render_faq_meta_box( $post ) {
        $series_parent = (int) get_post_meta( $post->ID, '_uc_series_parent', true );
        $is_parent     = $series_parent && $series_parent === (int) $post->ID;
        $is_child      = $series_parent && $series_parent !== (int) $post->ID;

        echo '<div class="uc-meta-box">';

        if ( $is_parent ) {
            $url = add_query_arg( array( 'post_type' => 'uc_event', 'page' => 'uc-series', 'series' => $post->ID ), admin_url( 'edit.php' ) );
            echo '<p class="description">This event is a series parent. Edit the shared <strong>Series FAQ</strong> in the <a href="' . esc_url( $url ) . '">Series Manager &rarr;</a></p>';
            echo '</div>';
            return;
        }

        if ( $is_child ) {
            $series = sfaf_get_series_faq( $post->ID );
            echo '<p class="description" style="margin-top:0;"><strong>Series FAQ (inherited)</strong></p>';
            if ( empty( $series ) ) {
                echo '<p class="uc-muted-inline">No series FAQ yet. Manage it in the Series Manager.</p>';
            } else {
                echo '<ul class="uc-faq-readonly">';
                foreach ( $series as $f ) {
                    echo '<li><strong>' . esc_html( $f['question'] ) . '</strong><br>' . esc_html( $f['answer'] ) . '</li>';
                }
                echo '</ul>';
            }
            echo '<label class="uc-display-toggle"><input type="checkbox" name="uc_faq_override" value="1" ' . checked( sfaf_faq_is_override( $post->ID ), true, false ) . ' /> Replace the series FAQ with the event-specific FAQ below</label>';
            echo '<p class="description" style="margin-top:12px;"><strong>Event-specific FAQ</strong></p>';
            $this->faq_repeater_html( 'uc_event_faq', sfaf_get_event_faq( $post->ID ), $this->faq_source_label( $post->ID ) );
            echo '</div>';
            return;
        }

        // Standalone event.
        echo '<p class="description" style="margin-top:0;">Frequently asked questions for this event.</p>';
        $this->faq_repeater_html( 'uc_series_faq', sfaf_normalize_faqs( get_post_meta( $post->ID, '_uc_series_faq', true ) ), $this->faq_source_label( $post->ID ) );
        echo '</div>';
    }

    /**
     * Event image source + reset-to-series control. The featured image (right)
     * is the per-event override.
     */
    public function render_image_meta_box( $post ) {
        $source = sfaf_event_image_source( $post->ID );
        $labels = array( 'event' => 'Event-specific', 'source' => 'From source', 'series' => 'From series', 'remote' => 'Synced', 'none' => 'Placeholder' );
        ?>
        <div class="uc-meta-box">
            <div class="uc-img-source-preview"><?php echo sfaf_event_thumbnail( $post->ID, 'medium' ); ?></div>
            <p class="uc-img-source-label">Showing: <strong><?php echo esc_html( $labels[ $source ] ); ?></strong> image</p>
            <p class="description">Set a <strong>Featured Image</strong> to override the series image for this occurrence.</p>
            <?php if ( $source === 'event' ) : ?>
                <label class="uc-display-toggle"><input type="checkbox" name="uc_reset_series_image" value="1" /> Reset to series image (clear this event's image on save)</label>
            <?php endif; ?>
        </div>
        <?php
    }

    /** Sanitize a posted FAQ repeater array. */
    private function sanitize_faq_post( $raw ) {
        $faqs = array();
        foreach ( (array) wp_unslash( $raw ) as $row ) {
            $q = isset( $row['question'] ) ? sanitize_text_field( $row['question'] ) : '';
            $a = isset( $row['answer'] ) ? sanitize_textarea_field( $row['answer'] ) : '';
            if ( $q === '' && $a === '' ) {
                continue;
            }
            $faqs[] = array( 'question' => $q, 'answer' => $a );
        }
        return $faqs;
    }

    public function save_meta( $post_id ) {
        if ( ! isset( $_POST['uc_event_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['uc_event_nonce'] ) ), 'uc_save_event' ) ) {
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        // Plain text fields.
        $text_fields = array(
            'uc_event_date'  => '_uc_event_date',
            'uc_start_time'  => '_uc_start_time',
            'uc_end_time'    => '_uc_end_time',
            'uc_location'    => '_uc_location',
            'uc_recurrence'  => '_uc_recurrence',
            'uc_end_date'    => '_uc_end_date',
            'uc_capacity'    => '_uc_capacity',
            'uc_email_subject' => '_uc_email_subject',
        );
        foreach ( $text_fields as $post_key => $meta_key ) {
            if ( isset( $_POST[ $post_key ] ) ) {
                update_post_meta( $post_id, $meta_key, sanitize_text_field( wp_unslash( $_POST[ $post_key ] ) ) );
            }
        }

        // Email addresses.
        $email_fields = array(
            'uc_organizer_email' => '_uc_organizer_email',
            'uc_email_replyto'   => '_uc_email_replyto',
        );
        foreach ( $email_fields as $post_key => $meta_key ) {
            if ( isset( $_POST[ $post_key ] ) ) {
                update_post_meta( $post_id, $meta_key, sanitize_email( wp_unslash( $_POST[ $post_key ] ) ) );
            }
        }

        // URL.
        if ( isset( $_POST['uc_gofundme_url'] ) ) {
            update_post_meta( $post_id, '_uc_gofundme_url', esc_url_raw( wp_unslash( $_POST['uc_gofundme_url'] ) ) );
        }

        // Goal (numeric).
        if ( isset( $_POST['uc_gofundme_goal'] ) ) {
            update_post_meta( $post_id, '_uc_gofundme_goal', preg_replace( '/[^0-9.]/', '', wp_unslash( $_POST['uc_gofundme_goal'] ) ) );
        }

        // Textarea (email body).
        if ( isset( $_POST['uc_email_body'] ) ) {
            update_post_meta( $post_id, '_uc_email_body', sanitize_textarea_field( wp_unslash( $_POST['uc_email_body'] ) ) );
        }

        // Pardot campaigns (array of campaign ids).
        $pardot = isset( $_POST['uc_pardot_campaigns'] ) ? (array) wp_unslash( $_POST['uc_pardot_campaigns'] ) : array();
        $pardot = array_values( array_filter( array_map( 'sanitize_text_field', $pardot ) ) );
        update_post_meta( $post_id, '_uc_pardot_campaigns', $pardot );

        // FAQ. Parent FAQ is managed in the Series Manager; here we handle
        // child (event-specific FAQ + replace toggle) and standalone (own FAQ).
        $sp       = (int) get_post_meta( $post_id, '_uc_series_parent', true );
        $is_child = $sp && $sp !== (int) $post_id;

        // Rows a platform owns are never read from the browser: they carry no
        // form fields, so they are read back from the database and put in
        // front of whatever was submitted. sanitize_faq_post() keeps only
        // question and answer, so a tampered POST cannot claim to be one.
        $faq_locked = ( '' !== $this->faq_source_label( $post_id ) );
        $merge_faq  = function ( $meta_key, $posted ) use ( $post_id, $faq_locked ) {
            $rows = $this->sanitize_faq_post( $posted );
            if ( ! $faq_locked ) {
                return $rows;
            }
            $keep = array();
            foreach ( sfaf_normalize_faqs( get_post_meta( $post_id, $meta_key, true ) ) as $row ) {
                if ( sfaf_faq_is_imported( $row ) ) {
                    $keep[] = $row;
                }
            }
            return array_merge( $keep, $rows );
        };

        // An empty repeater submits nothing, which is indistinguishable from
        // "the box was not on this screen" — hence the marker field.
        $posted_faq = function ( $field ) {
            if ( isset( $_POST[ $field ] ) ) {
                return $_POST[ $field ];
            }
            return isset( $_POST['uc_faq_has_manual'] ) ? array() : null;
        };

        if ( $is_child ) {
            $rows = $posted_faq( 'uc_event_faq' );
            if ( null !== $rows ) {
                update_post_meta( $post_id, '_uc_event_faq', $merge_faq( '_uc_event_faq', $rows ) );
            }
            update_post_meta( $post_id, '_uc_faq_override', isset( $_POST['uc_faq_override'] ) ? '1' : '0' );
        } elseif ( ! $sp ) {
            // Standalone event keeps its FAQ in _uc_series_faq.
            $rows = $posted_faq( 'uc_series_faq' );
            if ( null !== $rows ) {
                update_post_meta( $post_id, '_uc_series_faq', $merge_faq( '_uc_series_faq', $rows ) );
            }
        }

        // Image override: reset to series image, or flag a per-event image.
        if ( isset( $_POST['uc_reset_series_image'] ) ) {
            delete_post_thumbnail( $post_id );
            delete_post_meta( $post_id, '_uc_image_url' );
            delete_post_meta( $post_id, '_uc_image_override' );
        } elseif ( has_post_thumbnail( $post_id ) || get_post_meta( $post_id, '_uc_image_url', true ) ) {
            update_post_meta( $post_id, '_uc_image_override', '1' );
        } else {
            delete_post_meta( $post_id, '_uc_image_override' );
        }

        // Toggles ('1' / '0').
        $toggles = array(
            'uc_rsvp_enabled'     => '_uc_rsvp_enabled',
            'uc_notify_organizer' => '_uc_notify_organizer',
            'uc_show_rsvp'        => '_uc_show_rsvp',
            'uc_show_donate'      => '_uc_show_donate',
            'uc_show_social'      => '_uc_show_social',
            'uc_show_calendar'    => '_uc_show_calendar',
            'uc_show_reminders'   => '_uc_show_reminders',
        );
        foreach ( $toggles as $post_key => $meta_key ) {
            update_post_meta( $post_id, $meta_key, isset( $_POST[ $post_key ] ) ? '1' : '0' );
        }
    }
}
