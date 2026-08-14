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

        /*
         * VENUES ARE MANAGED IN /caladmin, NOT IN THE WORDPRESS ADMIN.
         *
         * show_ui is off for the same reason it is off on the series taxonomy:
         * this is event management, not site configuration, and the people who
         * schedule events work in the portal. The WordPress screen also could
         * not hold an address, which is the thing a venue is actually for, so
         * keeping it would have meant two screens where one of them was wrong.
         *
         * The taxonomy itself is unchanged and still public, so /event-venue/
         * archives, the REST payload satellites read and every existing term
         * relationship carry on exactly as they were. See SFAF_Venues.
         */
        register_taxonomy( 'uc_venue', 'uc_event', array(
            'labels' => array(
                'name'          => 'Venues',
                'singular_name' => 'Venue',
                'add_new_item'  => 'Add New Venue',
                'menu_name'     => 'Venues',
            ),
            'hierarchical' => false,
            'public'       => true,
            'show_ui'      => false,
            'show_in_menu' => false,
            'rewrite'      => array( 'slug' => 'event-venue' ),
            'show_in_rest' => true,
        ) );

        // Series. Registered here alongside the others because that is what it
        // now is: a way of grouping events, exactly like category and
        // organizer, and not a kind of event. See class-sfaf-series.php.
        SFAF_Series::register_taxonomy();
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
            'Who hears about this event',
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
        $location   = sfaf_event_location( $post->ID );
        $series_id  = SFAF_Series::id_for_event( $post->ID );
        $all_series = SFAF_Series::all();
        $group      = SFAF_Recurrence::group_of( $post->ID );
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
                <div class="uc-meta-field" style="flex: 2;">
                    <label for="uc_series">Series</label>
                    <select id="uc_series" name="uc_series">
                        <option value="0">Not part of a series</option>
                        <?php foreach ( $all_series as $term ) : ?>
                            <option value="<?php echo (int) $term->term_id; ?>" <?php selected( $series_id, $term->term_id ); ?>>
                                <?php echo esc_html( $term->name ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description">
                        A series is an umbrella for grouping and filtering. It holds events, it is not one, and
                        it may hold different kinds of event. Create and edit series under
                        <a href="<?php echo esc_url( add_query_arg( array( 'post_type' => 'uc_event', 'page' => 'uc-series' ), admin_url( 'edit.php' ) ) ); ?>">Series</a>.
                    </p>
                </div>
            </div>

            <?php
            /*
             * REPEAT IS A GENERATOR, AND IT RUNS ONCE.
             *
             * Choosing a pattern here creates that many separate events, each
             * complete in itself, and then forgets the pattern. Nothing
             * regenerates: editing or deleting one of them afterwards is an
             * ordinary edit or an ordinary delete, and nothing brings it back.
             *
             * Hidden once this event already belongs to a group, because
             * generating from it again would double every date it produced the
             * first time.
             */
            if ( '' === $group ) : ?>
                <div class="uc-meta-row">
                    <div class="uc-meta-field">
                        <label for="uc_repeat">Repeat this event</label>
                        <select id="uc_repeat" name="uc_repeat">
                            <option value="">Does not repeat</option>
                            <?php foreach ( SFAF_Recurrence::patterns() as $key => $label ) : ?>
                                <option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="uc-meta-field">
                        <label for="uc_repeat_until">Repeat until</label>
                        <input type="date" id="uc_repeat_until" name="uc_repeat_until" value="" />
                    </div>
                </div>
                <p class="description">
                    On save this creates one separate event per date, all sharing a recurrence group so they can be
                    edited together later. It happens once. Nothing regenerates afterwards.
                </p>
            <?php else : ?>
                <?php $upcoming = count( SFAF_Recurrence::bulk_targets( $post->ID ) ); ?>
                <p class="description">
                    <strong>Part of a recurrence group.</strong>
                    <?php echo esc_html( SFAF_Recurrence::pattern_label( SFAF_Recurrence::pattern_of( $post->ID ), $date ) ); ?>
                    &middot; <?php echo (int) $upcoming; ?> upcoming
                    <?php echo esc_html( _n( 'occurrence', 'occurrences', $upcoming ) ); ?>.
                    This event is its own record: editing or deleting it affects nothing else.
                </p>
            <?php endif; ?>
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
                    <option value="" data-goal="">Select a saved campaign</option>
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

            <?php
            /*
             * The same choice, on the same meta key, as the manager panel the
             * portal renders on its editor and its pending queue. This is the
             * WordPress admin, which is a third surface with its own metabox
             * API and no access to that render, so the one thing shared here
             * is the thing that matters: sfaf_fundraising_progress_meta_key()
             * names the storage in one place, and every screen writes '1' or
             * '0' to it. Nothing infers a default from an absent value.
             */
            $fund_key = sfaf_fundraising_progress_meta_key();
            $fund_on  = ( '1' === (string) get_post_meta( $post->ID, $fund_key, true ) );
            ?>
            <div class="uc-meta-field">
                <label for="uc_show_fund_progress">Fundraising progress</label>
                <input type="hidden" name="uc_show_fund_progress" value="0" />
                <label class="uc-check">
                    <input type="checkbox" id="uc_show_fund_progress" name="uc_show_fund_progress" value="1" <?php checked( $fund_on ); ?> />
                    Show the raised and goal figures on this event
                </label>
                <p class="description">Off by default. Nothing about the money is published until this is ticked, and a fetch never changes it. With no raised figure on file nothing appears even when ticked: a goal on its own reads as zero raised.</p>
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
     * Who hears about this event.
     *
     * THE TWO CONTROLS THAT USED TO BE HERE ARE GONE, AND THAT IS THE POINT.
     * This box held an "Organizer Email" field and an "Email organizer on new
     * RSVP" checkbox. In 3.25.0 the registration alert moved onto the same
     * notification list as everything else, and nothing reads that pair any
     * more: SFAF_RSVP::route_submission() resolves the list instead. Leaving
     * two live-looking controls that change nothing is worse than not having
     * them, because somebody would type an address into one and believe it.
     *
     * NO PICKER IS REBUILT HERE. The list is people, teams and typed addresses,
     * with its own validation and its own resolution rules, and a second
     * implementation of it in the WordPress admin would be a second thing to
     * keep in step. This box says where the list is and shows who is currently
     * on it, which is the part somebody standing on this screen actually needs.
     */
    public function render_organizer_meta_box( $post ) {
        $list = SFAF_Reminders::notify_list( $post->ID );
        ?>
        <div class="uc-meta-box">
            <?php if ( empty( $list ) ) : ?>
                <p class="description" style="margin-top:0;">Nobody is on this event's notification list.</p>
            <?php else : ?>
                <p class="description" style="margin-top:0;">These people are told when somebody registers, get a copy of the morning-of reminder, and get the list of who is coming two hours before.</p>
                <ul class="uc-meta-list">
                    <?php foreach ( $list as $email => $label ) : ?>
                        <li><?php echo esc_html( $label ); ?> <span class="uc-muted-inline"><?php echo esc_html( $email ); ?></span></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <p class="description">
                <a href="<?php echo esc_url( SFAF_Portal::link( 'events/edit/' . (int) $post->ID ) ); ?>">Edit the list on the calendar portal</a>,
                where people, teams, and outside addresses are all picked in one place.
            </p>
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
                    <?php
                    // ONE reply-to per event, used by the confirmation email
                    // and, since 2.12.0, by the morning-of reminder as well.
                    // Left blank it falls back to whoever created the event and
                    // then to the site default, so replies always reach a real
                    // person. See SFAF_Reminders::reply_to_for().
                    $reply_resolved = SFAF_Reminders::reply_to_for( $post->ID );
                    ?>
                    <input type="email" id="uc_email_replyto" name="uc_email_replyto"
                           value="<?php echo esc_attr( '' !== $replyto ? $replyto : $reply_resolved ); ?>"
                           placeholder="events@sfaf.org" />
                    <p class="description" style="margin:4px 0 0;">Where replies to this event's emails go. Blank falls back to the event's creator, then to the site default.</p>
                </div>
            </div>
            <div class="uc-meta-field">
                <label for="uc_email_body">Body</label>
                <textarea id="uc_email_body" name="uc_email_body" rows="5" placeholder="Hi {attendee_name}, you're registered for {event_name} on {event_date}."><?php echo esc_textarea( $body ); ?></textarea>
                <p class="description">Tokens: <code>{event_name}</code> <code>{attendee_name}</code> <code>{first_name}</code> <code>{last_name}</code> <code>{event_date}</code> <code>{event_time}</code> <code>{event_location}</code> <code>{organizer_name}</code></p>
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
                . '.</strong> These are refreshed from the campaign on every fetch, so they cannot be edited here. An edit would be overwritten. '
                . 'Change them at ' . esc_html( $label ) . '.</p>';
            if ( ! empty( $imported ) ) {
                echo '<ul class="uc-faq-readonly">';
                foreach ( $imported as $f ) {
                    echo '<li><strong>' . esc_html( $f['question'] ) . '</strong><br>' . esc_html( $f['answer'] ) . '</li>';
                }
                echo '</ul>';
            }
            echo '<p class="description" style="margin-top:12px;"><strong>Your own questions.</strong> Kept forever, never reordered or removed by a fetch.</p>';
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
     * FAQ box.
     *
     * ONE BLOCK, ALWAYS. Whatever this event is, its questions live on it and
     * are edited here. There is no inherited block above these rows, no replace
     * toggle deciding which of two blocks a visitor sees, and no "manage this
     * elsewhere" for some events but not others — all three of which existed
     * only because a series used to be an event that other events read from.
     *
     * Reuse comes from saved sets and from a series' default set, both of which
     * COPY rows onto the event at a moment somebody chose. See SFAF_FAQ_Sets.
     */
    public function render_faq_meta_box( $post ) {
        echo '<div class="uc-meta-box">';
        echo '<p class="description" style="margin-top:0;">Frequently asked questions for this event.</p>';
        $this->faq_set_picker_html();
        $this->faq_repeater_html( 'uc_faqs', sfaf_get_faqs( $post->ID ), $this->faq_source_label( $post->ID ) );
        echo '</div>';
    }

    /**
     * Apply a saved FAQ set, from inside the metabox.
     *
     * The same control the portal editor grew, for the same reason: a set that
     * can only be applied by posting a separate form somewhere else is a set
     * nobody applies. See SFAF_Portal::faq_set_picker() for the long note on
     * why the copying happens in the browser and why appending is the only
     * mode offered.
     *
     * Applied rows carry no source_faq_id, because a repeater row is a
     * question and an answer and nothing else, so they are manual rows and
     * SFAF_Sources::sync_faqs() leaves them alone on every fetch.
     *
     * There is no fallback for a WordPress admin with JavaScript switched off,
     * because the post editor around this box does not work without it either.
     */
    private function faq_set_picker_html() {
        $sets = SFAF_FAQ_Sets::all();
        if ( empty( $sets ) ) {
            return;
        }

        $payload = array();
        foreach ( $sets as $id => $set ) {
            $payload[ $id ] = array(
                'name' => $set['name'],
                'rows' => $set['rows'],
            );
        }
        ?>
        <div class="uc-faq-picker" data-uc-faq-picker hidden>
            <label for="uc-faq-set-select"><strong>Apply a saved FAQ set</strong></label>
            <div class="uc-faq-picker-row">
                <select id="uc-faq-set-select" data-uc-faq-set>
                    <?php foreach ( $sets as $set ) : ?>
                        <option value="<?php echo esc_attr( $set['id'] ); ?>"><?php
                            echo esc_html( $set['name'] . ' (' . count( $set['rows'] ) . ')' );
                        ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="button" data-uc-faq-apply>Add these questions</button>
            </div>
            <p class="description" data-uc-faq-said role="status" hidden></p>
            <p class="description">
                The questions are copied in and added underneath the ones already here. Nothing already written is
                changed or removed, and questions already on this event are skipped rather than duplicated. Update
                the event to keep them. Editing the set afterwards does not change this event.
            </p>
            <script type="application/json" data-uc-faq-sets><?php
                echo wp_json_encode( $payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
            ?></script>
        </div>
        <?php
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
            <?php
            /*
             * Said here as well as in the portal editor, because this is the
             * other screen where somebody chooses the picture, and a spec that
             * only exists on one of two editors is a spec half the uploads
             * never meet. See the note in SFAF_Portal::render_manager_control().
             */
            ?>
            <p class="description"><strong>Best size: 1200 x 675 pixels (16:9 landscape).</strong> Event cards crop to
                this shape and fill it, so anything taller loses its top and bottom. It is also the shape used when
                someone shares the event.</p>
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
        //
        // uc_recurrence and uc_end_date are gone from this list on purpose.
        // The cadence is no longer a stored property that anything re-reads —
        // it is an instruction to the generator, handled at the bottom of this
        // method — and _uc_end_date now means only what a source says an event's
        // end date is, which this editor has no business writing.
        $text_fields = array(
            'uc_event_date'  => '_uc_event_date',
            'uc_start_time'  => '_uc_start_time',
            'uc_end_time'    => '_uc_end_time',
            'uc_location'    => '_uc_location',
            'uc_capacity'    => '_uc_capacity',
            'uc_email_subject' => '_uc_email_subject',
        );
        foreach ( $text_fields as $post_key => $meta_key ) {
            if ( isset( $_POST[ $post_key ] ) ) {
                update_post_meta( $post_id, $meta_key, sanitize_text_field( wp_unslash( $_POST[ $post_key ] ) ) );
            }
        }

        // Email addresses.
        //
        // uc_organizer_email is not here any more. The box that offered it does
        // not render it (see render_organizer_meta_box), and a save may only
        // speak for the fields its form actually showed.
        $email_fields = array(
            'uc_email_replyto' => '_uc_email_replyto',
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
        // The hidden 0 beside the checkbox is what makes an unticked box mean
        // off rather than "not submitted", so the toggle can be switched back.
        if ( isset( $_POST['uc_show_fund_progress'] ) ) {
            update_post_meta(
                $post_id,
                sfaf_fundraising_progress_meta_key(),
                ( '1' === (string) wp_unslash( $_POST['uc_show_fund_progress'] ) ) ? '1' : '0'
            );
        }
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

        $rows = $posted_faq( 'uc_faqs' );
        if ( null !== $rows ) {
            update_post_meta( $post_id, sfaf_faq_meta_key(), $merge_faq( sfaf_faq_meta_key(), $rows ) );
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
        //
        // THIS LOOP WRITES WHETHER OR NOT THE BOX WAS ON SCREEN, which is why
        // uc_notify_organizer had to come out of it rather than just off the
        // form: left here, every save from this screen would have written '0'
        // to a key that no longer has a control, quietly re-answering a question
        // nobody was asked.
        $toggles = array(
            'uc_rsvp_enabled'     => '_uc_rsvp_enabled',
            'uc_show_rsvp'        => '_uc_show_rsvp',
            'uc_show_donate'      => '_uc_show_donate',
            'uc_show_social'      => '_uc_show_social',
            'uc_show_calendar'    => '_uc_show_calendar',
            'uc_show_reminders'   => '_uc_show_reminders',
        );
        foreach ( $toggles as $post_key => $meta_key ) {
            update_post_meta( $post_id, $meta_key, isset( $_POST[ $post_key ] ) ? '1' : '0' );
        }

        // Which series this event belongs to. A plain term assignment: nothing
        // is inherited from it and nothing about the event changes because of
        // it, beyond the image fallback and the badge.
        if ( isset( $_POST['uc_series'] ) ) {
            $was = SFAF_Series::id_for_event( $post_id );
            $now = (int) $_POST['uc_series'];
            SFAF_Series::set_for_event( $post_id, $now );

            // Moving INTO a series applies that series' default FAQ set, and
            // only when the event has no questions of its own. Copied, once,
            // never linked.
            if ( $now && $now !== $was ) {
                SFAF_FAQ_Sets::apply_series_default( $post_id, $now );
            }
        }

        /*
         * GENERATE, ONCE.
         *
         * SFAF_Recurrence::generate() refuses a seed that already carries a
         * group, so a resubmitted form cannot double the dates. Everything it
         * makes is an ordinary event from the moment it exists.
         */
        $pattern = isset( $_POST['uc_repeat'] ) ? SFAF_Recurrence::clean_pattern( wp_unslash( $_POST['uc_repeat'] ) ) : '';
        $until   = isset( $_POST['uc_repeat_until'] ) ? sanitize_text_field( wp_unslash( $_POST['uc_repeat_until'] ) ) : '';
        if ( '' !== $pattern && '' !== $until ) {
            $made = SFAF_Recurrence::generate( $post_id, $pattern, $until );
            if ( ! empty( $made['created'] ) ) {
                set_transient(
                    'sfaf_generated_' . get_current_user_id() . '_' . $post_id,
                    count( $made['created'] ),
                    5 * MINUTE_IN_SECONDS
                );
            }
        }
    }
}
