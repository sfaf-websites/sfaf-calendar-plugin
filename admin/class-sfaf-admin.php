<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Admin {

    public function register() {
        add_action( 'admin_menu', array( $this, 'add_menu_pages' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_init', array( $this, 'handle_series_save' ) );
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

        // Series Manager
        add_submenu_page(
            'edit.php?post_type=uc_event',
            'Series',
            'Series',
            'edit_posts',
            'uc-series',
            array( $this, 'render_series_page' )
        );

        // Shortcode Generator (produces [sfaf_calendar] blocks for pages on THIS site)
        add_submenu_page(
            'edit.php?post_type=uc_event',
            'Shortcode Generator',
            'Shortcode Generator',
            'edit_posts',
            'uc-shortcode-generator',
            array( $this, 'render_shortcode_generator' )
        );

        // Embed Code (produces an HTML block for OTHER sites)
        add_submenu_page(
            'edit.php?post_type=uc_event',
            'Embed Code',
            'Embed Code',
            'edit_posts',
            'uc-embed',
            array( $this, 'render_embed_page' )
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
        $out = array();

        // Credentials are taken out of the submission here and written to
        // their own dedicated option. They are deliberately NOT added to $out:
        // this array is what replaces uc_settings wholesale, and a credential
        // that never enters it cannot be dropped by a later save that forgets
        // about it. See class-sfaf-credentials.php.
        SFAF_Credentials::absorb( $input );

        // Plain text fields. No credentials in this list — see above.
        $text_fields = array(
            'pardot_business_unit', 'google_calendar_id',
            'galaxy_portal_url', 'galaxy_agency_id',
            'galaxy_needs_category', 'galaxy_sync_interval',
            'webhook_url',
            'pardot_default_campaign',
            'route_sheet_url',
            'brand_logo',
            'email_rsvp_subject', 'email_reminder_subject',
        );
        foreach ( $text_fields as $field ) {
            $out[ $field ] = isset( $input[ $field ] ) ? sanitize_text_field( $input[ $field ] ) : '';
        }

        // Email addresses.
        foreach ( array( 'email_rsvp_replyto', 'route_organizer_email' ) as $field ) {
            $out[ $field ] = isset( $input[ $field ] ) ? sanitize_email( $input[ $field ] ) : '';
        }

        // Textareas.
        foreach ( array( 'email_rsvp_body', 'email_reminder_body' ) as $field ) {
            $out[ $field ] = isset( $input[ $field ] ) ? sanitize_textarea_field( $input[ $field ] ) : '';
        }

        // Colors.
        foreach ( array( 'brand_primary_color', 'brand_accent_color' ) as $field ) {
            $color = isset( $input[ $field ] ) ? sanitize_hex_color( $input[ $field ] ) : '';
            $out[ $field ] = $color ? $color : '';
        }

        // Display: events per page (0/-1 = all) + pagination style.
        $out['display_per_page'] = ( isset( $input['display_per_page'] ) && $input['display_per_page'] !== '' ) ? intval( $input['display_per_page'] ) : 12;
        $pg_styles = array( 'load_more', 'pages', 'infinite' );
        $out['display_pagination'] = ( isset( $input['display_pagination'] ) && in_array( $input['display_pagination'], $pg_styles, true ) )
            ? $input['display_pagination'] : 'load_more';

        // Card style (whitelist).
        $card_styles = array( 'bordered', 'shadow', 'minimal' );
        $out['brand_card_style'] = ( isset( $input['brand_card_style'] ) && in_array( $input['brand_card_style'], $card_styles, true ) )
            ? $input['brand_card_style'] : 'bordered';

        // Endpoint overrides and secrets used to be handled here, each guarded
        // by a read-back of the previous uc_settings value. Both now go to the
        // dedicated credential option via the SFAF_Credentials::absorb() call
        // at the top of this method, which is what makes them survive a save
        // that does not mention them.

        // Toggles. gofundme_connected is gone: connection state is now derived
        // from whether a real token is on file (SFAF_GFMP::status()), so it can
        // no longer be set by hand.
        $toggles = array(
            'gofundme_show_progress', 'gofundme_auto_import',
            'pardot_auto_prospect', 'pardot_event_emails',
            'google_auto_publish', 'galaxy_auto_sync',
            'galaxy_import_events', 'galaxy_active_only',
            'webhook_event_created', 'webhook_event_updated',
            'webhook_event_deleted', 'webhook_event_rsvp',
            'route_email_organizer', 'route_pardot',
            'route_google_sheet', 'route_confirmation_email',
        );
        foreach ( $toggles as $toggle ) {
            $out[ $toggle ] = isset( $input[ $toggle ] ) ? '1' : '0';
        }

        // GoFundMe campaign manager (repeater: name, url, goal).
        $out['gofundme_campaigns'] = array();
        if ( isset( $input['gofundme_campaigns'] ) && is_array( $input['gofundme_campaigns'] ) ) {
            foreach ( $input['gofundme_campaigns'] as $row ) {
                if ( empty( $row['name'] ) && empty( $row['url'] ) ) {
                    continue;
                }
                $out['gofundme_campaigns'][] = array(
                    'name' => sanitize_text_field( isset( $row['name'] ) ? $row['name'] : '' ),
                    'url'  => esc_url_raw( isset( $row['url'] ) ? $row['url'] : '' ),
                    'goal' => preg_replace( '/[^0-9.]/', '', isset( $row['goal'] ) ? $row['goal'] : '' ),
                );
            }
        }

        // Pardot campaign manager (repeater: name, id).
        $out['pardot_campaigns'] = array();
        if ( isset( $input['pardot_campaigns'] ) && is_array( $input['pardot_campaigns'] ) ) {
            foreach ( $input['pardot_campaigns'] as $row ) {
                if ( empty( $row['name'] ) && empty( $row['id'] ) ) {
                    continue;
                }
                $out['pardot_campaigns'][] = array(
                    'name' => sanitize_text_field( isset( $row['name'] ) ? $row['name'] : '' ),
                    'id'   => sanitize_text_field( isset( $row['id'] ) ? $row['id'] : '' ),
                );
            }
        }

        // Pardot category -> campaigns map.
        $out['pardot_category_map'] = array();
        if ( isset( $input['pardot_category_map'] ) && is_array( $input['pardot_category_map'] ) ) {
            foreach ( $input['pardot_category_map'] as $term_id => $campaign_ids ) {
                $term_id = intval( $term_id );
                if ( ! $term_id || ! is_array( $campaign_ids ) ) {
                    continue;
                }
                $out['pardot_category_map'][ $term_id ] = array_values( array_map( 'sanitize_text_field', $campaign_ids ) );
            }
        }

        // Satellite sites (stored as JSON).
        if ( isset( $input['satellite_sites'] ) ) {
            $out['satellite_sites'] = sanitize_text_field( $input['satellite_sites'] );
        }

        return $out;
    }

    /**
     * Render RSVPs admin page
     */
    public function render_rsvps_page() {
        $event_id = isset( $_GET['event_id'] ) ? intval( $_GET['event_id'] ) : 0;
        $search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
        $rsvps    = SFAF_RSVP::get_all_rsvps( array( 'event_id' => $event_id, 'search' => $search ) );

        $export_url = admin_url( 'admin-ajax.php?action=uc_export_rsvps' );
        if ( $event_id ) {
            $export_url .= '&event_id=' . $event_id;
        }
        $export_url = wp_nonce_url( $export_url, 'uc_export_rsvps' );
        ?>
        <div class="wrap uc-admin-wrap">
            <div class="uc-admin-header">
                <div>
                    <h1>RSVPs</h1>
                    <p class="uc-subtitle">
                        <?php if ( $event_id ) : ?>
                            Registrations for: <strong><?php echo esc_html( get_the_title( $event_id ) ); ?></strong>
                            &nbsp;<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=uc_event&page=uc-rsvps' ) ); ?>">View all</a>
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
                        <input type="hidden" name="event_id" value="<?php echo (int) $event_id; ?>" />
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
                                        <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=uc_event&page=uc-rsvps&event_id=' . $rsvp->event_id ) ); ?>">
                                            <?php echo esc_html( $rsvp->event_title ?? get_the_title( $rsvp->event_id ) ); ?>
                                        </a>
                                    </td>
                                    <td><strong><?php echo esc_html( $rsvp->name ); ?></strong></td>
                                    <td><?php echo esc_html( $rsvp->email ); ?></td>
                                    <td><?php echo esc_html( $rsvp->phone ); ?></td>
                                    <td><span class="uc-status uc-status-<?php echo esc_attr( $rsvp->status ); ?>"><?php echo esc_html( ucfirst( $rsvp->status ) ); ?></span></td>
                                    <td><?php echo esc_html( date( 'M j, Y g:i A', strtotime( $rsvp->created_at ) ) ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    /* ---------------------------------------------------------------------
     * Series Manager
     * ------------------------------------------------------------------- */

    public function render_series_page() {
        $series_id = isset( $_GET['series'] ) ? intval( $_GET['series'] ) : 0;
        if ( $series_id && ( $p = get_post( $series_id ) ) && $p->post_type === 'uc_event' ) {
            $this->render_series_edit( $series_id );
        } else {
            $this->render_series_list();
        }
    }

    /** Process the Series edit save on admin_init (before any output). */
    public function handle_series_save() {
        if ( empty( $_POST['uc_series_save'] ) ) {
            return;
        }
        $parent_id = isset( $_POST['series_id_post'] ) ? intval( $_POST['series_id_post'] ) : 0;
        if ( ! $parent_id || ! isset( $_POST['uc_series_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['uc_series_nonce'] ) ), 'uc_save_series_' . $parent_id ) ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $parent_id ) ) {
            return;
        }

        wp_update_post( array(
            'ID'           => $parent_id,
            'post_title'   => sanitize_text_field( wp_unslash( $_POST['series_name'] ?? '' ) ) ?: get_the_title( $parent_id ),
            'post_content' => wp_kses_post( wp_unslash( $_POST['series_desc'] ?? '' ) ),
        ) );

        update_post_meta( $parent_id, '_uc_location', sanitize_text_field( wp_unslash( $_POST['series_location'] ?? '' ) ) );
        update_post_meta( $parent_id, '_uc_start_time', sanitize_text_field( wp_unslash( $_POST['series_start'] ?? '' ) ) );
        update_post_meta( $parent_id, '_uc_end_time', sanitize_text_field( wp_unslash( $_POST['series_end'] ?? '' ) ) );

        wp_set_object_terms( $parent_id, ( $c = intval( $_POST['series_category'] ?? 0 ) ) ? array( $c ) : array(), 'uc_event_category' );
        wp_set_object_terms( $parent_id, ( $o = intval( $_POST['series_organizer'] ?? 0 ) ) ? array( $o ) : array(), 'uc_organizer' );

        // Series image.
        $img_id = intval( $_POST['series_image_id'] ?? 0 );
        if ( $img_id ) {
            update_post_meta( $parent_id, '_uc_series_image_id', $img_id );
        } else {
            delete_post_meta( $parent_id, '_uc_series_image_id' );
        }
        $img_url = esc_url_raw( wp_unslash( $_POST['series_image_url'] ?? '' ) );
        if ( $img_url ) {
            update_post_meta( $parent_id, '_uc_series_image_url', $img_url );
        } else {
            delete_post_meta( $parent_id, '_uc_series_image_url' );
        }

        // Series FAQ (canonical location).
        $faqs = array();
        if ( isset( $_POST['uc_series_faq'] ) && is_array( $_POST['uc_series_faq'] ) ) {
            foreach ( wp_unslash( $_POST['uc_series_faq'] ) as $row ) {
                $qq = isset( $row['question'] ) ? sanitize_text_field( $row['question'] ) : '';
                $aa = isset( $row['answer'] ) ? sanitize_textarea_field( $row['answer'] ) : '';
                if ( $qq === '' && $aa === '' ) {
                    continue;
                }
                $faqs[] = array( 'question' => $qq, 'answer' => $aa );
            }
        }
        update_post_meta( $parent_id, '_uc_series_faq', $faqs );

        // Propagate shared fields to non-overridden occurrences.
        $rec = new SFAF_Recurrence();
        $rec->maybe_generate( $parent_id );

        wp_safe_redirect( add_query_arg(
            array( 'post_type' => 'uc_event', 'page' => 'uc-series', 'series' => $parent_id, 'updated' => '1' ),
            admin_url( 'edit.php' )
        ) );
        exit;
    }

    private function render_series_list() {
        $parents = sfaf_get_series_parents();
        if ( ! empty( $parents ) ) {
            // Bulk-load post/meta/term caches so the per-row lookups below are
            // cache hits rather than one query each.
            _prime_post_caches( $parents, true, true );
        }
        ?>
        <div class="wrap uc-admin-wrap">
            <div class="uc-admin-header"><div><h1>Series</h1><p class="uc-subtitle">Manage shared properties for recurring event series</p></div></div>
            <div class="uc-admin-card">
                <?php if ( empty( $parents ) ) : ?>
                    <p class="uc-no-data">No event series yet. Create a recurring event (set a recurrence + series end date) to start a series.</p>
                <?php else : ?>
                    <table class="uc-admin-table">
                        <thead><tr><th>Image</th><th>Series</th><th>Category</th><th>Organizer</th><th>Recurrence</th><th>Occurrences</th><th>Next date</th></tr></thead>
                        <tbody>
                        <?php foreach ( $parents as $pid ) :
                            $cats = wp_get_post_terms( $pid, 'uc_event_category', array( 'fields' => 'names' ) );
                            $orgs = wp_get_post_terms( $pid, 'uc_organizer', array( 'fields' => 'names' ) );
                            $rec  = get_post_meta( $pid, '_uc_recurrence', true );
                            $up   = sfaf_get_series_events( $pid, true );
                            $next = ! empty( $up ) ? get_post_meta( $up[0], '_uc_event_date', true ) : '';
                            $edit = add_query_arg( array( 'post_type' => 'uc_event', 'page' => 'uc-series', 'series' => $pid ), admin_url( 'edit.php' ) );
                            ?>
                            <tr>
                                <td class="uc-series-thumb"><a href="<?php echo esc_url( $edit ); ?>"><?php
                                    $simg = sfaf_event_image_url( $pid );
                                    if ( $simg ) {
                                        echo '<img src="' . esc_url( $simg ) . '" alt="" />';
                                    } else {
                                        echo '<span class="uc-series-thumb-none" aria-hidden="true">' . sfaf_icon( 'calendar', array( 'size' => '18px' ) ) . '</span>';
                                    }
                                ?></a></td>
                                <td><a href="<?php echo esc_url( $edit ); ?>"><strong><?php echo esc_html( get_the_title( $pid ) ); ?></strong></a></td>
                                <td><?php echo ! is_wp_error( $cats ) && $cats ? esc_html( implode( ', ', $cats ) ) : 'None'; ?></td>
                                <td><?php echo ! is_wp_error( $orgs ) && $orgs ? esc_html( implode( ', ', $orgs ) ) : 'None'; ?></td>
                                <td><?php echo $rec ? esc_html( ucfirst( $rec ) ) : 'None'; ?></td>
                                <td><?php echo (int) sfaf_series_count( $pid ); ?></td>
                                <td><?php echo $next ? esc_html( date_i18n( 'M j, Y', strtotime( $next ) ) ) : 'Not set'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    private function render_series_edit( $parent_id ) {
        $cats   = get_terms( array( 'taxonomy' => 'uc_event_category', 'hide_empty' => false ) );
        $orgs   = get_terms( array( 'taxonomy' => 'uc_organizer', 'hide_empty' => false ) );
        $curcat = ( wp_get_post_terms( $parent_id, 'uc_event_category', array( 'fields' => 'ids' ) ) ?: array( 0 ) )[0];
        $curorg = ( wp_get_post_terms( $parent_id, 'uc_organizer', array( 'fields' => 'ids' ) ) ?: array( 0 ) )[0];
        $img_id = (int) get_post_meta( $parent_id, '_uc_series_image_id', true );
        $img_url= get_post_meta( $parent_id, '_uc_series_image_url', true );
        $preview= $img_id ? wp_get_attachment_image_url( $img_id, 'medium' ) : $img_url;
        $faqs   = sfaf_normalize_faqs( get_post_meta( $parent_id, '_uc_series_faq', true ) );
        $back   = add_query_arg( array( 'post_type' => 'uc_event', 'page' => 'uc-series' ), admin_url( 'edit.php' ) );
        ?>
        <div class="wrap uc-admin-wrap">
            <div class="uc-admin-header">
                <div><h1>Edit Series</h1><p class="uc-subtitle">Shared properties flow down to occurrences that haven't been individually overridden.</p></div>
                <div class="uc-admin-actions"><a href="<?php echo esc_url( $back ); ?>" class="button">&larr; All series</a></div>
            </div>

            <?php if ( ! empty( $_GET['updated'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Series saved and propagated to occurrences.</p></div><?php endif; ?>

            <form method="post" class="uc-admin-card" style="padding:20px;">
                <input type="hidden" name="uc_series_save" value="1" />
                <input type="hidden" name="series_id_post" value="<?php echo (int) $parent_id; ?>" />
                <?php wp_nonce_field( 'uc_save_series_' . $parent_id, 'uc_series_nonce' ); ?>

                <div class="uc-meta-field"><label>Series name</label>
                    <input type="text" name="series_name" value="<?php echo esc_attr( get_the_title( $parent_id ) ); ?>" class="uc-input" /></div>

                <div class="uc-meta-field"><label>Featured image (series default)</label>
                    <div class="uc-image-control">
                        <input type="hidden" name="series_image_id" id="uc_series_image_id" value="<?php echo (int) $img_id; ?>" />
                        <div class="uc-logo-preview" id="uc_series_image_preview"><?php if ( $preview ) : ?><img src="<?php echo esc_url( $preview ); ?>" alt="" /><?php endif; ?></div>
                        <button type="button" class="button uc-series-upload-image">Choose Image</button>
                        <button type="button" class="button uc-series-remove-image">Remove</button>
                        <input type="url" name="series_image_url" id="uc_series_image_url" value="<?php echo esc_attr( $img_url ); ?>" class="uc-input" placeholder="…or paste an image URL" />
                    </div>
                </div>

                <div class="uc-meta-field"><label>Default description</label>
                    <textarea name="series_desc" rows="4" class="uc-input"><?php echo esc_textarea( get_post_field( 'post_content', $parent_id ) ); ?></textarea></div>

                <div class="uc-meta-row">
                    <div class="uc-meta-field"><label>Default location</label>
                        <input type="text" name="series_location" value="<?php echo esc_attr( get_post_meta( $parent_id, '_uc_location', true ) ); ?>" class="uc-input" /></div>
                    <div class="uc-meta-field"><label>Default start time</label>
                        <input type="time" name="series_start" value="<?php echo esc_attr( get_post_meta( $parent_id, '_uc_start_time', true ) ); ?>" class="uc-input" /></div>
                    <div class="uc-meta-field"><label>Default end time</label>
                        <input type="time" name="series_end" value="<?php echo esc_attr( get_post_meta( $parent_id, '_uc_end_time', true ) ); ?>" class="uc-input" /></div>
                </div>

                <div class="uc-meta-row">
                    <div class="uc-meta-field"><label>Category</label>
                        <select name="series_category" class="uc-input"><option value="0">None</option>
                            <?php if ( ! is_wp_error( $cats ) ) foreach ( $cats as $c ) : ?>
                                <option value="<?php echo (int) $c->term_id; ?>" <?php selected( $curcat, $c->term_id ); ?>><?php echo esc_html( $c->name ); ?></option>
                            <?php endforeach; ?>
                        </select></div>
                    <div class="uc-meta-field"><label>Organizer</label>
                        <select name="series_organizer" class="uc-input"><option value="0">None</option>
                            <?php if ( ! is_wp_error( $orgs ) ) foreach ( $orgs as $o ) : ?>
                                <option value="<?php echo (int) $o->term_id; ?>" <?php selected( $curorg, $o->term_id ); ?>><?php echo esc_html( $o->name ); ?></option>
                            <?php endforeach; ?>
                        </select></div>
                </div>

                <h3>Series FAQ</h3>
                <p class="description">These FAQs appear on every event in this series.</p>
                <div class="uc-repeater" data-repeater="series_faq">
                    <div class="uc-repeater-rows">
                        <?php foreach ( $faqs as $i => $f ) : ?>
                            <div class="uc-repeater-row uc-faq-row">
                                <input type="text" name="uc_series_faq[<?php echo (int) $i; ?>][question]" value="<?php echo esc_attr( $f['question'] ); ?>" placeholder="Question" />
                                <textarea name="uc_series_faq[<?php echo (int) $i; ?>][answer]" rows="2" placeholder="Answer"><?php echo esc_textarea( $f['answer'] ); ?></textarea>
                                <button type="button" class="button uc-repeater-remove" aria-label="Remove">&times;</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="button uc-repeater-add">+ Add FAQ</button>
                    <script type="text/html" class="uc-repeater-template">
                        <div class="uc-repeater-row uc-faq-row">
                            <input type="text" name="uc_series_faq[__INDEX__][question]" placeholder="Question" />
                            <textarea name="uc_series_faq[__INDEX__][answer]" rows="2" placeholder="Answer"></textarea>
                            <button type="button" class="button uc-repeater-remove" aria-label="Remove">&times;</button>
                        </div>
                    </script>
                </div>

                <div class="uc-save-bar"><?php submit_button( 'Save Series', 'primary', 'submit', false ); ?></div>
            </form>
        </div>
        <?php
    }

    /**
     * Render Shortcode Generator page
     */
    public function render_shortcode_generator() {
        $categories = get_terms( array( 'taxonomy' => 'uc_event_category', 'hide_empty' => false ) );
        $organizers = get_terms( array( 'taxonomy' => 'uc_organizer', 'hide_empty' => false ) );
        ?>
        <div class="wrap uc-admin-wrap">
            <div class="uc-admin-header">
                <div>
                    <h1>Shortcode Generator</h1>
                    <p class="uc-subtitle">Build a calendar shortcode, then copy it into any page or post.</p>
                </div>
            </div>

            <div class="uc-admin-card uc-generator">
                <div class="uc-generator-grid">
                    <div class="uc-gen-field">
                        <label for="uc-gen-type">Shortcode Type</label>
                        <select id="uc-gen-type" class="uc-gen-input">
                            <option value="sfaf_calendar">[sfaf_calendar]: Full calendar</option>
                            <option value="upcoming_events">[upcoming_events]: Compact widget</option>
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
                        <label class="uc-gen-checkbox">
                            <input type="checkbox" id="uc-gen-filters" checked /> Show filters &amp; search bar
                        </label>
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

    /**
     * Render the Embed Code generator.
     *
     * Picks the filters, writes the block to paste into another site, and shows
     * that block actually running underneath — the preview is a real embed
     * loading from the real endpoint, not a mock-up of one.
     *
     * Distinct from the Shortcode Generator: that produces [sfaf_calendar]
     * shortcodes for pages on THIS WordPress site; this produces a portable HTML
     * block for OTHER sites that can't run the plugin.
     */
    public function render_embed_page() {
        $categories = get_terms( array( 'taxonomy' => 'uc_event_category', 'hide_empty' => false ) );
        $organizers = get_terms( array( 'taxonomy' => 'uc_organizer', 'hide_empty' => false ) );
        $categories = is_wp_error( $categories ) ? array() : $categories;
        $organizers = is_wp_error( $organizers ) ? array() : $organizers;

        // Recurring series, named by their parent event.
        $series = array();
        foreach ( sfaf_get_series_parents() as $parent_id ) {
            $series[ $parent_id ] = get_the_title( $parent_id );
        }
        natcasesort( $series );

        $settings = get_option( 'uc_settings', array() );
        $per_page = ( isset( $settings['display_per_page'] ) && $settings['display_per_page'] !== '' )
            ? (int) $settings['display_per_page'] : 12;
        ?>
        <div class="wrap uc-admin-wrap uc-embed-gen"
             data-script-url="<?php echo esc_attr( SFAF_Embed::script_url() ); ?>">

            <div class="uc-admin-header">
                <div>
                    <h1>Embed Code</h1>
                    <p class="uc-subtitle">Build a calendar block, then paste it into any page on any site.</p>
                </div>
            </div>

            <div class="uc-admin-card uc-embed-note">
                <p>
                    <strong>This block works on any site or page</strong>: another WordPress site, or a
                    platform where you can only add an HTML block. Nothing is installed on the other
                    site and no events are copied to it. The block asks this calendar for its events
                    each time someone opens the page, so what visitors see is always current, and every
                    RSVP still happens here.
                </p>
            </div>

            <div class="uc-embed-layout">
                <div class="uc-admin-card uc-embed-options">
                    <h2>What should this calendar show?</h2>

                    <?php
                    /*
                     * DISPLAY MODE AND FILTER ARE INDEPENDENT CONTROLS.
                     *
                     * Not a list of preset block types: every combination is
                     * valid and useful, so offering "Programa Latino sidebar"
                     * and "Programa Latino calendar" as separate presets would
                     * mean nine presets today and more with every new filter.
                     * Two controls produce all of them, and a Programa Latino
                     * sidebar and a TransLife sidebar are just two snippets
                     * from the same screen.
                     */
                    ?>
                    <div class="uc-embed-field">
                        <span class="uc-embed-label">Display mode</span>
                        <div class="uc-radio-stack">
                            <?php
                            $modes = array(
                                'list'     => 'List: one card per event, with images and details',
                                'calendar' => 'Calendar: a month grid',
                                'sidebar'  => 'Sidebar: a narrow column of upcoming dates',
                            );
                            foreach ( $modes as $val => $label ) :
                                ?>
                                <label class="uc-radio-opt">
                                    <input type="radio" name="uc_embed_view" class="uc-embed-view"
                                           value="<?php echo esc_attr( $val ); ?>" <?php checked( 'list', $val ); ?> />
                                    <?php echo esc_html( $label ); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="uc-embed-field" data-when-view="list calendar">
                        <span class="uc-embed-label">Visitor view toggle</span>
                        <label class="uc-check">
                            <input type="checkbox" id="uc-embed-toggle" checked />
                            Let visitors switch between list and calendar
                        </label>
                        <p class="description">The mode chosen above is what the block opens on. A visitor who switches keeps their choice for this block only.</p>
                    </div>

                    <div class="uc-embed-field" data-when-view="sidebar">
                        <label class="uc-embed-label" for="uc-embed-count">How many to show</label>
                        <input type="number" id="uc-embed-count" class="uc-input uc-input-narrow"
                               value="10" min="1" max="50" />
                        <p class="description">The next N dates. Occurrences, so a weekly group appears once per date. Fewer are shown if fewer exist.</p>
                    </div>

                    <?php
                    /*
                     * ORGANIZER FIRST. On a programme page that is the filter
                     * that matches how the work is actually organised: Programa
                     * Latino runs five distinct series, so filtering by series
                     * would show a fifth of their programming, and category
                     * cuts across organizers entirely.
                     */
                    ?>
                    <div class="uc-embed-field">
                        <label class="uc-embed-label" for="uc-embed-filter-type">Limit to</label>
                        <select id="uc-embed-filter-type" class="uc-input">
                            <option value="">Everything</option>
                            <option value="organizer" selected>One organizer</option>
                            <option value="series">One recurring series</option>
                            <option value="category">One category</option>
                        </select>
                        <p class="description">Organizer is usually the right one for a programme page: a team&rsquo;s work is often several series and several categories.</p>
                    </div>

                    <div class="uc-embed-field" data-when-filter="organizer">
                        <label class="uc-embed-label" for="uc-embed-organizer">Which organizer</label>
                        <select id="uc-embed-organizer" class="uc-input uc-embed-which">
                            <?php if ( empty( $organizers ) ) : ?>
                                <option value="">No organizers yet</option>
                            <?php else : ?>
                                <?php foreach ( $organizers as $org ) : ?>
                                    <option value="<?php echo esc_attr( $org->slug ); ?>"
                                            data-name="<?php echo esc_attr( $org->name ); ?>"><?php echo esc_html( $org->name ); ?></option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>

                    <div class="uc-embed-field" data-when-filter="series" hidden>
                        <label class="uc-embed-label" for="uc-embed-series">Which series</label>
                        <select id="uc-embed-series" class="uc-input uc-embed-which">
                            <?php if ( empty( $series ) ) : ?>
                                <option value="">No recurring series yet</option>
                            <?php else : ?>
                                <?php foreach ( $series as $parent_id => $title ) : ?>
                                    <option value="<?php echo (int) $parent_id; ?>"
                                            data-name="<?php echo esc_attr( $title ); ?>"><?php echo esc_html( $title ); ?></option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>

                    <div class="uc-embed-field" data-when-filter="category" hidden>
                        <label class="uc-embed-label" for="uc-embed-category">Which category</label>
                        <select id="uc-embed-category" class="uc-input uc-embed-which">
                            <?php if ( empty( $categories ) ) : ?>
                                <option value="">No categories yet</option>
                            <?php else : ?>
                                <?php foreach ( $categories as $cat ) : ?>
                                    <option value="<?php echo esc_attr( $cat->slug ); ?>"
                                            data-name="<?php echo esc_attr( $cat->name ); ?>"><?php echo esc_html( $cat->name ); ?></option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>

                    <div class="uc-embed-field" data-when-view="list calendar">
                        <label class="uc-embed-label" for="uc-embed-per-page">Events per page</label>
                        <input type="number" id="uc-embed-per-page" class="uc-input uc-input-narrow"
                               value="<?php echo (int) $per_page; ?>" min="1" max="100" />
                        <p class="description">Visitors load the next batch with a button. Applies to the list; the calendar shows a whole month.</p>
                    </div>

                    <div class="uc-embed-field uc-embed-field-inline" data-when-view="list calendar">
                        <label class="uc-embed-label" for="uc-embed-filters">Let visitors search and filter</label>
                        <label class="uc-toggle">
                            <input type="checkbox" id="uc-embed-filters" checked />
                            <span class="uc-toggle-slider"></span>
                        </label>
                        <p class="description">Shows a search box and category buttons above the events.</p>
                    </div>
                </div>

                <div class="uc-admin-card uc-embed-output">
                    <h2>Your block</h2>
                    <textarea id="uc-embed-code" class="uc-embed-code" readonly rows="9"
                              onfocus="this.select();"></textarea>
                    <div class="uc-embed-actions">
                        <button type="button" class="button button-primary" id="uc-embed-copy">Copy block</button>
                        <span class="uc-embed-copied" id="uc-embed-copied" aria-live="polite"></span>
                    </div>
                    <p class="description">
                        Paste it into an HTML or Custom HTML block. To filter by venue as well, add
                        <code>data-venue="venue-slug"</code> to the block by hand.
                    </p>
                </div>
            </div>

            <div class="uc-admin-card uc-embed-preview-card">
                <h2>Preview</h2>
                <p class="description">This is the block above, running for real against the embed endpoint.</p>
                <div class="uc-embed-preview" id="uc-embed-preview"></div>
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

        // Credentials come from their own option, never from uc_settings. The
        // two write-only ones (Eventbrite private token, GoFundMe Pro client
        // secret) are never passed through this — their fields stay empty.
        $c = function ( $key ) {
            return SFAF_Credentials::is_secret( $key ) ? '' : SFAF_Credentials::get( $key );
        };

        $gf_campaigns     = $s( 'gofundme_campaigns', array() );
        $pardot_campaigns = $s( 'pardot_campaigns', array() );
        $category_map     = $s( 'pardot_category_map', array() );
        $event_categories = get_terms( array( 'taxonomy' => 'uc_event_category', 'hide_empty' => false ) );
        $pardot_connected = $s( 'pardot_business_unit' ) !== '';
        ?>
        <div class="wrap uc-admin-wrap">
            <div class="uc-admin-header">
                <div>
                    <h1>Settings &amp; Integrations</h1>
                    <p class="uc-subtitle">Connect your calendar to external services and sync events across your digital ecosystem</p>
                </div>
            </div>

            <form method="post" action="options.php">
                <?php settings_fields( 'uc_settings' ); ?>

                <!-- DISPLAY -->
                <div class="uc-integration-panel uc-panel-open">
                    <div class="uc-panel-header" onclick="this.parentElement.classList.toggle('uc-panel-open')">
                        <span class="uc-panel-icon"><?php echo sfaf_icon( 'calendar', array( 'size' => '20px' ) ); ?></span>
                        <div class="uc-panel-info">
                            <h2>Display</h2>
                            <p>How many events to show and how visitors page through them</p>
                        </div>
                        <span class="uc-panel-toggle">&#9660;</span>
                    </div>
                    <div class="uc-panel-body">
                        <div class="uc-field-row">
                            <label>Events per page</label>
                            <input type="number" name="uc_settings[display_per_page]" value="<?php echo esc_attr( $s( 'display_per_page', '12' ) ); ?>" class="uc-input" min="-1" step="1" style="max-width:120px;" />
                        </div>
                        <p class="description">Default 12. Use 0 or -1 to show all events with no pagination. Override per shortcode with <code>per_page="20"</code>.</p>
                        <div class="uc-field-row uc-field-row-top">
                            <label>Pagination style</label>
                            <div class="uc-radio-stack">
                                <?php
                                $pg_style = $s( 'display_pagination', 'load_more' );
                                $pg_opts  = array(
                                    'load_more' => 'Load More button: fetches the next batch and appends it',
                                    'pages'     => 'Next / Previous pages: numbered page links below the list',
                                    'infinite'  => 'Infinite scroll: loads more automatically near the bottom',
                                );
                                foreach ( $pg_opts as $val => $label ) :
                                ?>
                                    <label class="uc-radio-opt">
                                        <input type="radio" name="uc_settings[display_pagination]" value="<?php echo esc_attr( $val ); ?>" <?php checked( $pg_style, $val ); ?> />
                                        <?php echo esc_html( $label ); ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- BRANDING -->
                <div class="uc-integration-panel uc-panel-open">
                    <div class="uc-panel-header" onclick="this.parentElement.classList.toggle('uc-panel-open')">
                        <span class="uc-panel-icon"><?php echo sfaf_icon( 'palette', array( 'size' => '20px' ) ); ?></span>
                        <div class="uc-panel-info">
                            <h2>Branding</h2>
                            <p>Logo, colors, and card style for the public calendar</p>
                        </div>
                        <span class="uc-panel-toggle">&#9660;</span>
                    </div>
                    <div class="uc-panel-body">
                        <div class="uc-field-row">
                            <label>Logo</label>
                            <div class="uc-logo-control">
                                <input type="text" name="uc_settings[brand_logo]" id="uc_brand_logo" value="<?php echo esc_attr( $s( 'brand_logo' ) ); ?>" class="uc-input uc-monospace" placeholder="No logo selected" />
                                <button type="button" class="button uc-upload-logo">Upload Logo</button>
                                <button type="button" class="button uc-remove-logo">Remove</button>
                                <div class="uc-logo-preview">
                                    <?php if ( $s( 'brand_logo' ) ) : ?>
                                        <img src="<?php echo esc_url( $s( 'brand_logo' ) ); ?>" alt="" />
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="uc-field-row">
                            <label>Primary Color</label>
                            <input type="text" name="uc_settings[brand_primary_color]" value="<?php echo esc_attr( $s( 'brand_primary_color', '#FFD900' ) ); ?>" class="uc-color-field" data-default-color="#FFD900" />
                        </div>
                        <div class="uc-field-row">
                            <label>Accent Color</label>
                            <input type="text" name="uc_settings[brand_accent_color]" value="<?php echo esc_attr( $s( 'brand_accent_color', '#16BECF' ) ); ?>" class="uc-color-field" data-default-color="#16BECF" />
                        </div>
                        <div class="uc-field-row">
                            <label>SFAF Palette</label>
                            <div class="uc-palette">
                                <span class="uc-swatch" style="background:#FFD900" title="SFAF Yellow #FFD900"></span>
                                <span class="uc-swatch" style="background:#000000" title="SFAF Black #000000"></span>
                                <span class="uc-swatch" style="background:#373433" title="SFAF Dark Gray #373433"></span>
                                <span class="uc-swatch" style="background:#16BECF" title="Teal #16BECF"></span>
                                <span class="uc-palette-note">Yellow #FFD900 · Black #000000 · Dark Gray #373433</span>
                            </div>
                        </div>
                        <div class="uc-field-row">
                            <label>Card Style</label>
                            <select name="uc_settings[brand_card_style]" class="uc-input">
                                <?php
                                $styles = array( 'bordered' => 'Bordered', 'shadow' => 'Shadow', 'minimal' => 'Minimal' );
                                $current_style = $s( 'brand_card_style', 'bordered' );
                                foreach ( $styles as $val => $label ) :
                                ?>
                                    <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $current_style, $val ); ?>><?php echo esc_html( $label ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- RSVP DATA ROUTING -->
                <div class="uc-integration-panel uc-panel-open">
                    <div class="uc-panel-header" onclick="this.parentElement.classList.toggle('uc-panel-open')">
                        <span class="uc-panel-icon"><?php echo sfaf_icon( 'link', array( 'size' => '20px' ) ); ?></span>
                        <div class="uc-panel-info">
                            <h2>RSVP Data Routing</h2>
                            <p>What happens when someone RSVPs</p>
                        </div>
                        <span class="uc-panel-toggle">&#9660;</span>
                    </div>
                    <div class="uc-panel-body">
                        <div class="uc-routing-row">
                            <span class="uc-status-dot uc-dot-green"></span>
                            <div class="uc-routing-info"><strong>Save to database</strong><span>Always on. Every RSVP is stored.</span></div>
                            <label class="uc-toggle uc-toggle-disabled"><input type="checkbox" checked disabled /><span class="uc-toggle-slider"></span></label>
                        </div>

                        <div class="uc-routing-row">
                            <span class="uc-status-dot <?php echo $s( 'route_email_organizer' ) === '1' ? 'uc-dot-green' : 'uc-dot-gray'; ?>"></span>
                            <div class="uc-routing-info">
                                <strong>Email organizer</strong>
                                <input type="email" name="uc_settings[route_organizer_email]" value="<?php echo esc_attr( $s( 'route_organizer_email' ) ); ?>" class="uc-input" placeholder="organizer@sfaf.org (global default)" />
                            </div>
                            <label class="uc-toggle"><input type="checkbox" name="uc_settings[route_email_organizer]" value="1" <?php checked( $s( 'route_email_organizer' ), '1' ); ?> /><span class="uc-toggle-slider"></span></label>
                        </div>

                        <div class="uc-routing-row">
                            <span class="uc-status-dot <?php echo $pardot_connected ? 'uc-dot-green' : 'uc-dot-gray'; ?>"></span>
                            <div class="uc-routing-info">
                                <strong>Create Pardot prospect</strong>
                                <span class="<?php echo $pardot_connected ? 'uc-conn-ok' : 'uc-conn-no'; ?>"><?php echo $pardot_connected ? 'Connected' : 'Not configured'; ?></span>
                            </div>
                            <label class="uc-toggle"><input type="checkbox" name="uc_settings[route_pardot]" value="1" <?php checked( $s( 'route_pardot' ), '1' ); ?> /><span class="uc-toggle-slider"></span></label>
                        </div>

                        <div class="uc-routing-row">
                            <span class="uc-status-dot <?php echo $s( 'route_google_sheet' ) === '1' ? 'uc-dot-green' : 'uc-dot-gray'; ?>"></span>
                            <div class="uc-routing-info">
                                <strong>Add to Google Sheet</strong>
                                <input type="url" name="uc_settings[route_sheet_url]" value="<?php echo esc_attr( $s( 'route_sheet_url' ) ); ?>" class="uc-input uc-monospace" placeholder="https://docs.google.com/spreadsheets/..." />
                            </div>
                            <label class="uc-toggle"><input type="checkbox" name="uc_settings[route_google_sheet]" value="1" <?php checked( $s( 'route_google_sheet' ), '1' ); ?> /><span class="uc-toggle-slider"></span></label>
                        </div>

                        <div class="uc-routing-row">
                            <span class="uc-status-dot <?php echo $s( 'route_confirmation_email' ) === '1' ? 'uc-dot-green' : 'uc-dot-gray'; ?>"></span>
                            <div class="uc-routing-info"><strong>Send confirmation email</strong><span>Emails the attendee using your template below.</span></div>
                            <label class="uc-toggle"><input type="checkbox" name="uc_settings[route_confirmation_email]" value="1" <?php checked( $s( 'route_confirmation_email' ), '1' ); ?> /><span class="uc-toggle-slider"></span></label>
                        </div>
                    </div>
                </div>

                <!-- EMAIL TEMPLATES -->
                <div class="uc-integration-panel">
                    <div class="uc-panel-header" onclick="this.parentElement.classList.toggle('uc-panel-open')">
                        <span class="uc-panel-icon"><?php echo sfaf_icon( 'mail', array( 'size' => '20px' ) ); ?></span>
                        <div class="uc-panel-info">
                            <h2>Email Templates</h2>
                            <p>Global defaults for confirmation and reminder emails</p>
                        </div>
                        <span class="uc-panel-toggle">&#9660;</span>
                    </div>
                    <div class="uc-panel-body">
                        <p class="uc-token-ref">Tokens: <code>{event_name}</code> <code>{attendee_name}</code> <code>{event_date}</code> <code>{event_time}</code> <code>{event_location}</code> <code>{organizer_name}</code></p>

                        <h3>RSVP Confirmation</h3>
                        <div class="uc-field-row">
                            <label>Subject</label>
                            <input type="text" name="uc_settings[email_rsvp_subject]" value="<?php echo esc_attr( $s( 'email_rsvp_subject' ) ); ?>" class="uc-input" placeholder="You're registered for {event_name}" />
                        </div>
                        <div class="uc-field-row uc-field-row-top">
                            <label>Body</label>
                            <textarea name="uc_settings[email_rsvp_body]" rows="4" class="uc-input uc-textarea" placeholder="Hi {attendee_name}, you're registered for {event_name} on {event_date}."><?php echo esc_textarea( $s( 'email_rsvp_body' ) ); ?></textarea>
                        </div>
                        <div class="uc-field-row">
                            <label>Reply-To</label>
                            <input type="email" name="uc_settings[email_rsvp_replyto]" value="<?php echo esc_attr( $s( 'email_rsvp_replyto' ) ); ?>" class="uc-input" placeholder="events@sfaf.org" />
                        </div>

                        <h3>Reminder Signup Confirmation</h3>
                        <div class="uc-field-row">
                            <label>Subject</label>
                            <input type="text" name="uc_settings[email_reminder_subject]" value="<?php echo esc_attr( $s( 'email_reminder_subject' ) ); ?>" class="uc-input" placeholder="You'll get a reminder for {event_name}" />
                        </div>
                        <div class="uc-field-row uc-field-row-top">
                            <label>Body</label>
                            <textarea name="uc_settings[email_reminder_body]" rows="4" class="uc-input uc-textarea" placeholder="Thanks! We'll remind you before {event_name}."><?php echo esc_textarea( $s( 'email_reminder_body' ) ); ?></textarea>
                        </div>
                    </div>
                </div>

                <!-- MULTI-SITE API (server) -->
                <div class="uc-integration-panel">
                    <div class="uc-panel-header" onclick="this.parentElement.classList.toggle('uc-panel-open')">
                        <span class="uc-panel-icon"><?php echo sfaf_icon( 'link', array( 'size' => '20px' ) ); ?></span>
                        <div class="uc-panel-info">
                            <h2>Multi-Site API</h2>
                            <p>This site is the API server. Satellite sites pull events from it using the SFAF Calendar Satellite plugin.</p>
                        </div>
                        <span class="uc-panel-status uc-status-active">Server</span>
                        <span class="uc-panel-toggle">&#9660;</span>
                    </div>
                    <div class="uc-panel-body">
                        <div class="uc-field-row">
                            <label>Public Endpoint</label>
                            <input type="text" value="<?php echo esc_url( rest_url( 'sfaf-calendar/v1/events' ) ); ?>" readonly class="uc-input uc-monospace" />
                        </div>
                        <div class="uc-field-row">
                            <label>API Key</label>
                            <input type="text" id="uc_multisite_api_key" name="uc_settings[multisite_api_key]" value="<?php echo esc_attr( $c( 'multisite_api_key' ) ); ?>" class="uc-input uc-monospace" placeholder="Generate a key, then paste it into each satellite" />
                            <button type="button" class="button uc-generate-key">Generate Key</button>
                        </div>
                        <p class="description">When a key is set, the events feed requires the <code>X-SFAF-API-Key</code> header (satellites send it automatically). Leave blank to keep the feed public. This site never pulls from or pushes to other sites.</p>
                    </div>
                </div>

                <!-- GOFUNDME PRO -->
                <div class="uc-integration-panel">
                    <div class="uc-panel-header" onclick="this.parentElement.classList.toggle('uc-panel-open')">
                        <span class="uc-panel-icon"><?php echo sfaf_icon( 'heart', array( 'size' => '20px' ) ); ?></span>
                        <div class="uc-panel-info">
                            <h2>GoFundMe Pro</h2>
                            <p>Manage campaigns and display live progress on event cards</p>
                        </div>
                        <span class="uc-panel-status uc-status-active">Active</span>
                        <span class="uc-panel-toggle">&#9660;</span>
                    </div>
                    <div class="uc-panel-body">
                        <p class="description">GoFundMe Pro uses OAuth2 (client credentials). Tokens and data come from <strong>different hosts</strong>. GoFundMe Pro support has confirmed the token endpoint below is correct, so <strong>leave it as it is</strong>; pro.gofundme.com does not issue tokens. Every request also carries the <code>x-integration-id</code> header they issued, which is what stops their edge security treating this server as a bot.</p>
                        <div class="uc-field-row">
                            <label>Token endpoint URL</label>
                            <input type="url" name="uc_settings[gofundme_token_url]" value="<?php echo esc_attr( $c( 'gofundme_token_url' ) ); ?>"
                                   placeholder="<?php echo esc_attr( SFAF_GFMP::DEFAULT_TOKEN_URL ); ?>" class="uc-input" />
                        </div>
                        <div class="uc-field-row">
                            <label>API base URL (data)</label>
                            <input type="url" name="uc_settings[gofundme_api_base]" value="<?php echo esc_attr( $c( 'gofundme_api_base' ) ); ?>"
                                   placeholder="<?php echo esc_attr( SFAF_GFMP::DEFAULT_API_BASE ); ?>" class="uc-input" />
                        </div>
                        <p class="description">Leave blank to use the defaults shown. In use now. Token: <code><?php echo esc_html( SFAF_GFMP::token_endpoint() ); ?></code> &middot; data: <code><?php echo esc_html( SFAF_GFMP::api_base() ); ?></code> &middot; integration ID: <code><?php echo esc_html( SFAF_GFMP::integration_id() ); ?></code></p>
                        <div class="uc-field-row">
                            <label>Client ID</label>
                            <input type="text" name="uc_settings[gofundme_client_id]" value="<?php echo esc_attr( $c( 'gofundme_client_id' ) ); ?>" class="uc-input" />
                        </div>
                        <div class="uc-field-row">
                            <label>Client Secret</label>
                            <?php // Never rendered back to the browser — only whether one is stored. ?>
                            <input type="password" name="uc_settings[gofundme_client_secret]" value="" autocomplete="new-password"
                                   placeholder="<?php echo SFAF_GFMP::has_secret() ? 'Saved. Leave blank to keep it' : 'Paste the client secret'; ?>"
                                   class="uc-input" />
                        </div>
                        <div class="uc-field-row">
                            <label>Organization ID</label>
                            <input type="text" name="uc_settings[gofundme_org_id]" value="<?php echo esc_attr( $c( 'gofundme_org_id' ) ); ?>" class="uc-input" />
                        </div>
                        <p class="description">The Organization ID is not used to obtain a token. It identifies which organization's data to read, in calls such as <code>GET /organizations/{org_id}/campaigns</code>. Set it before the campaign step.</p>
                        <div class="uc-field-row">
                            <label>Connection</label>
                            <div class="uc-conn-controls">
                                <?php $gf_status = SFAF_GFMP::status(); ?>
                                <button type="button" class="button uc-gofundme-connect">Test connection</button>
                                <span class="uc-conn-pill <?php echo $gf_status['connected'] ? 'is-connected' : ''; ?>"><?php
                                    echo $gf_status['connected']
                                        ? 'Connected. Token valid for ' . esc_html( $gf_status['expires_human'] )
                                        : 'Not connected';
                                ?></span>
                            </div>
                        </div>
                        <p class="description uc-gofundme-conn-msg" style="display:none;"></p>

                        <?php // [PROBE] Temporary diagnostic. Remove this block, the
                              // [PROBE] block in class-sfaf-gfmp.php and initGfmpProbe()
                              // in admin.js together once the payload is understood. ?>
                        <div class="uc-probe-box">
                            <h3>Campaign probe <span class="uc-probe-tag">diagnostic</span></h3>
                            <p class="description">Calls four campaign endpoints and prints exactly what comes back: status and body, nothing decoded or filtered. <strong>Read-only:</strong> it imports nothing and changes nothing. Here to replace guesswork about where the campaign's real copy and images live.</p>
                            <div class="uc-field-row">
                                <label>Campaign ID</label>
                                <div class="uc-conn-controls">
                                    <input type="text" class="uc-input uc-probe-id" placeholder="e.g. 227362" />
                                    <button type="button" class="button uc-gfmp-probe">Probe campaign</button>
                                    <button type="button" class="button uc-probe-copy" style="display:none;">Copy all output</button>
                                </div>
                            </div>
                            <p class="description uc-probe-msg" style="display:none;"></p>
                            <div class="uc-probe-out" style="display:none;"></div>
                        </div>
                        <div class="uc-field-row">
                            <label>Show progress bar on event cards</label>
                            <label class="uc-toggle"><input type="checkbox" name="uc_settings[gofundme_show_progress]" value="1" <?php checked( $s( 'gofundme_show_progress' ), '1' ); ?> /><span class="uc-toggle-slider"></span></label>
                        </div>
                        <div class="uc-field-row">
                            <label>Auto-Import Campaigns as Events</label>
                            <label class="uc-toggle"><input type="checkbox" name="uc_settings[gofundme_auto_import]" value="1" <?php checked( $s( 'gofundme_auto_import' ), '1' ); ?> /><span class="uc-toggle-slider"></span></label>
                        </div>

                        <div class="uc-campaign-manager">
                            <h3>Campaign Manager</h3>
                            <p class="description">These campaigns populate the dropdown on each event's Integrations box.</p>
                            <div class="uc-repeater" data-repeater="gofundme_campaigns">
                                <div class="uc-repeater-rows">
                                    <?php if ( is_array( $gf_campaigns ) ) : foreach ( $gf_campaigns as $i => $c ) : ?>
                                        <div class="uc-repeater-row">
                                            <input type="text" name="uc_settings[gofundme_campaigns][<?php echo (int) $i; ?>][name]" value="<?php echo esc_attr( $c['name'] ); ?>" placeholder="Campaign name" />
                                            <input type="url" name="uc_settings[gofundme_campaigns][<?php echo (int) $i; ?>][url]" value="<?php echo esc_attr( $c['url'] ); ?>" placeholder="https://gofund.me/..." />
                                            <input type="text" name="uc_settings[gofundme_campaigns][<?php echo (int) $i; ?>][goal]" value="<?php echo esc_attr( $c['goal'] ); ?>" placeholder="Goal $" class="uc-repeater-narrow" />
                                            <button type="button" class="button uc-repeater-remove" aria-label="Remove">&times;</button>
                                        </div>
                                    <?php endforeach; endif; ?>
                                </div>
                                <button type="button" class="button uc-repeater-add">+ Add Campaign</button>
                                <script type="text/html" class="uc-repeater-template">
                                    <div class="uc-repeater-row">
                                        <input type="text" name="uc_settings[gofundme_campaigns][__INDEX__][name]" placeholder="Campaign name" />
                                        <input type="url" name="uc_settings[gofundme_campaigns][__INDEX__][url]" placeholder="https://gofund.me/..." />
                                        <input type="text" name="uc_settings[gofundme_campaigns][__INDEX__][goal]" placeholder="Goal $" class="uc-repeater-narrow" />
                                        <button type="button" class="button uc-repeater-remove" aria-label="Remove">&times;</button>
                                    </div>
                                </script>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- EVENTBRITE -->
                <?php $eb_status = SFAF_Eventbrite::status(); ?>
                <div class="uc-integration-panel">
                    <div class="uc-panel-header" onclick="this.parentElement.classList.toggle('uc-panel-open')">
                        <span class="uc-panel-icon"><?php echo sfaf_icon( 'calendar', array( 'size' => '20px' ) ); ?></span>
                        <div class="uc-panel-info">
                            <h2>Eventbrite</h2>
                            <p>Connect the Eventbrite account whose events this calendar will read</p>
                        </div>
                        <?php // The badge reflects a real verified call, never a stored flag. ?>
                        <span class="uc-panel-status uc-eventbrite-panel-status <?php echo $eb_status['connected'] ? 'uc-status-connected' : 'uc-status-pending'; ?>"><?php
                            echo $eb_status['connected'] ? 'Connected' : ( $eb_status['has_token'] ? 'Not verified' : 'Not configured' );
                        ?></span>
                        <span class="uc-panel-toggle">&#9660;</span>
                    </div>
                    <div class="uc-panel-body">
                        <p class="description">Eventbrite uses a single long-lived <strong>private token</strong> from your account's API keys page. There is no OAuth round trip. It is sent as a bearer token on every request.</p>
                        <div class="uc-field-row">
                            <label>API base URL</label>
                            <input type="url" name="uc_settings[eventbrite_api_base]" value="<?php echo esc_attr( $c( 'eventbrite_api_base' ) ); ?>"
                                   placeholder="<?php echo esc_attr( SFAF_Eventbrite::DEFAULT_API_BASE ); ?>" class="uc-input" />
                        </div>
                        <p class="description">Leave blank to use the default shown. In use now: <code><?php echo esc_html( SFAF_Eventbrite::api_base() ); ?></code> &middot; the test calls <code><?php echo esc_html( SFAF_Eventbrite::me_endpoint() ); ?></code></p>
                        <div class="uc-field-row">
                            <label>Private token</label>
                            <?php // Never rendered back to the browser — only whether one is stored. ?>
                            <input type="password" name="uc_settings[eventbrite_private_token]" value="" autocomplete="new-password"
                                   placeholder="<?php echo SFAF_Eventbrite::has_token() ? 'Saved. Leave blank to keep it' : 'Paste the private token'; ?>"
                                   class="uc-input" />
                        </div>
                        <div class="uc-field-row">
                            <label>Connection</label>
                            <div class="uc-conn-controls">
                                <button type="button" class="button uc-eventbrite-connect">Test connection</button>
                                <span class="uc-conn-pill <?php echo $eb_status['connected'] ? 'is-connected' : ''; ?>"><?php
                                    if ( $eb_status['connected'] ) {
                                        $eb_who = ( '' !== $eb_status['name'] ) ? $eb_status['name'] : 'Eventbrite';
                                        echo 'Connected as ' . esc_html( $eb_who );
                                        if ( '' !== $eb_status['verified_human'] ) {
                                            echo ', verified ' . esc_html( $eb_status['verified_human'] ) . ' ago';
                                        }
                                    } else {
                                        echo 'Not connected';
                                    }
                                ?></span>
                            </div>
                        </div>
                        <?php if ( $eb_status['connected'] && '' !== $eb_status['email'] ) : ?>
                            <p class="description">Account email: <code><?php echo esc_html( $eb_status['email'] ); ?></code></p>
                        <?php endif; ?>
                        <p class="description uc-eventbrite-conn-msg" style="display:none;"></p>

                        <div class="uc-field-row">
                            <label>Events</label>
                            <div class="uc-conn-controls">
                                <button type="button" class="button uc-eventbrite-preview">Fetch events (preview)</button>
                                <select class="uc-input uc-eventbrite-status uc-repeater-narrow">
                                    <option value="live">Published (live)</option>
                                    <option value="draft">Draft</option>
                                    <option value="started">Started</option>
                                    <option value="ended">Ended</option>
                                    <option value="completed">Completed</option>
                                    <option value="canceled">Canceled</option>
                                    <option value="all">All statuses</option>
                                </select>
                            </div>
                        </div>
                        <p class="description">Reads the account's organizations, then every event under each one, and shows what came back. <strong>Nothing is imported</strong>. This is a look at the data before anything is mapped to events on this site.</p>
                        <p class="description uc-eventbrite-fetch-msg" style="display:none;"></p>
                        <div class="uc-eventbrite-preview-out" style="display:none;"></div>

                        <p class="description">Step 2 of the integration: authenticate, then read. Importing into the Pending queue comes next, using this same fetch.</p>
                    </div>
                </div>

                <!-- PARDOT / SALESFORCE -->
                <div class="uc-integration-panel">
                    <div class="uc-panel-header" onclick="this.parentElement.classList.toggle('uc-panel-open')">
                        <span class="uc-panel-icon"><?php echo sfaf_icon( 'cloud', array( 'size' => '20px' ) ); ?></span>
                        <div class="uc-panel-info">
                            <h2>Pardot / Salesforce</h2>
                            <p>Manage campaigns and route RSVPs to prospect lists</p>
                        </div>
                        <span class="uc-panel-status <?php echo $pardot_connected ? 'uc-status-connected' : 'uc-status-pending'; ?>"><?php echo $pardot_connected ? 'Connected' : 'Not configured'; ?></span>
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

                        <div class="uc-campaign-manager">
                            <h3>Campaign Manager</h3>
                            <p class="description">These campaigns populate the multi-select on each event and the mapping table below.</p>
                            <div class="uc-repeater" data-repeater="pardot_campaigns">
                                <div class="uc-repeater-rows">
                                    <?php if ( is_array( $pardot_campaigns ) ) : foreach ( $pardot_campaigns as $i => $c ) : ?>
                                        <div class="uc-repeater-row">
                                            <input type="text" name="uc_settings[pardot_campaigns][<?php echo (int) $i; ?>][name]" value="<?php echo esc_attr( $c['name'] ); ?>" placeholder="Campaign name" />
                                            <input type="text" name="uc_settings[pardot_campaigns][<?php echo (int) $i; ?>][id]" value="<?php echo esc_attr( $c['id'] ); ?>" placeholder="Campaign ID" />
                                            <button type="button" class="button uc-repeater-remove" aria-label="Remove">&times;</button>
                                        </div>
                                    <?php endforeach; endif; ?>
                                </div>
                                <button type="button" class="button uc-repeater-add">+ Add Campaign</button>
                                <script type="text/html" class="uc-repeater-template">
                                    <div class="uc-repeater-row">
                                        <input type="text" name="uc_settings[pardot_campaigns][__INDEX__][name]" placeholder="Campaign name" />
                                        <input type="text" name="uc_settings[pardot_campaigns][__INDEX__][id]" placeholder="Campaign ID" />
                                        <button type="button" class="button uc-repeater-remove" aria-label="Remove">&times;</button>
                                    </div>
                                </script>
                            </div>
                        </div>

                        <div class="uc-field-row uc-field-row-top">
                            <label>Global Default Campaign</label>
                            <div style="flex:1;">
                                <input type="text" name="uc_settings[pardot_default_campaign]" value="<?php echo esc_attr( $s( 'pardot_default_campaign' ) ); ?>" class="uc-input" placeholder="Campaign ID, all RSVPs go here regardless" />
                                <p class="description">Every RSVP is assigned to this campaign, no matter the category or event.</p>
                            </div>
                        </div>

                        <div class="uc-campaign-map">
                            <h3>Category &rarr; Campaign Mapping</h3>
                            <?php if ( empty( $pardot_campaigns ) ) : ?>
                                <p class="description">Add campaigns above to enable category mapping.</p>
                            <?php elseif ( is_wp_error( $event_categories ) || empty( $event_categories ) ) : ?>
                                <p class="description">Create event categories to enable mapping.</p>
                            <?php else : ?>
                                <?php foreach ( $event_categories as $cat ) :
                                    $mapped = isset( $category_map[ $cat->term_id ] ) ? (array) $category_map[ $cat->term_id ] : array(); ?>
                                    <div class="uc-map-row uc-map-row-multi">
                                        <span class="uc-map-cat"><?php echo esc_html( $cat->name ); ?></span>
                                        <div class="uc-checkbox-list uc-checkbox-list-inline">
                                            <?php foreach ( $pardot_campaigns as $pc ) : ?>
                                                <label>
                                                    <input type="checkbox" name="uc_settings[pardot_category_map][<?php echo (int) $cat->term_id; ?>][]" value="<?php echo esc_attr( $pc['id'] ); ?>" <?php checked( in_array( $pc['id'], $mapped, true ) ); ?> />
                                                    <?php echo esc_html( $pc['name'] ); ?>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                        <div class="uc-tier-explainer">
                            <strong>How RSVPs are assigned to campaigns:</strong>
                            <span>Global default <em>+</em> Category mapping <em>+</em> Event-specific campaigns</span>
                        </div>
                    </div>
                </div>

                <!-- GOOGLE CALENDAR -->
                <div class="uc-integration-panel">
                    <div class="uc-panel-header" onclick="this.parentElement.classList.toggle('uc-panel-open')">
                        <span class="uc-panel-icon"><?php echo sfaf_icon( 'calendar', array( 'size' => '20px' ) ); ?></span>
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
                        <span class="uc-panel-icon"><?php echo sfaf_icon( 'handshake', array( 'size' => '20px' ) ); ?></span>
                        <div class="uc-panel-info">
                            <h2>Galaxy Digital</h2>
                            <p>Import volunteer opportunities from volunteers.sfaf.org</p>
                        </div>
                        <span class="uc-panel-status uc-status-connected">Connected</span>
                        <span class="uc-panel-toggle">&#9660;</span>
                    </div>
                    <div class="uc-panel-body">
                        <p class="description">Get Connected (v1.9.2) uses Bearer-token auth. Base URL: <code>https://api.galaxydigital.com/api/</code></p>
                        <div class="uc-field-row">
                            <label>API Key (Bearer Token)</label>
                            <input type="password" name="uc_settings[galaxy_api_key]" value="<?php echo esc_attr( $c( 'galaxy_api_key' ) ); ?>" class="uc-input" />
                        </div>
                        <div class="uc-field-row">
                            <label>Portal URL</label>
                            <input type="text" name="uc_settings[galaxy_portal_url]" value="<?php echo esc_attr( $s( 'galaxy_portal_url' ) ); ?>" class="uc-input" placeholder="https://volunteers.sfaf.org" />
                        </div>
                        <div class="uc-field-row">
                            <label>Agency / Organization ID</label>
                            <input type="text" name="uc_settings[galaxy_agency_id]" value="<?php echo esc_attr( $s( 'galaxy_agency_id' ) ); ?>" class="uc-input" />
                        </div>
                        <div class="uc-field-row">
                            <label>Import Needs as Category</label>
                            <select name="uc_settings[galaxy_needs_category]" class="uc-input">
                                <option value="">Select a category</option>
                                <option value="__create__" <?php selected( $s( 'galaxy_needs_category' ), '__create__' ); ?>>Auto-create "Volunteer Opportunities"</option>
                                <?php if ( ! is_wp_error( $event_categories ) ) : foreach ( $event_categories as $cat ) : ?>
                                    <option value="<?php echo (int) $cat->term_id; ?>" <?php selected( $s( 'galaxy_needs_category' ), (string) $cat->term_id ); ?>><?php echo esc_html( $cat->name ); ?></option>
                                <?php endforeach; endif; ?>
                            </select>
                        </div>
                        <div class="uc-field-row">
                            <label>Import Galaxy Digital Events</label>
                            <label class="uc-toggle"><input type="checkbox" name="uc_settings[galaxy_import_events]" value="1" <?php checked( $s( 'galaxy_import_events' ), '1' ); ?> /><span class="uc-toggle-slider"></span></label>
                        </div>
                        <div class="uc-field-row">
                            <label>Active Needs Only</label>
                            <label class="uc-toggle"><input type="checkbox" name="uc_settings[galaxy_active_only]" value="1" <?php checked( $s( 'galaxy_active_only', '1' ), '1' ); ?> /><span class="uc-toggle-slider"></span></label>
                        </div>
                        <div class="uc-field-row">
                            <label>Auto-Sync</label>
                            <label class="uc-toggle"><input type="checkbox" name="uc_settings[galaxy_auto_sync]" value="1" <?php checked( $s( 'galaxy_auto_sync' ), '1' ); ?> /><span class="uc-toggle-slider"></span></label>
                        </div>
                        <div class="uc-field-row">
                            <label>Sync Interval</label>
                            <select name="uc_settings[galaxy_sync_interval]" class="uc-input">
                                <option value="5" <?php selected( $s( 'galaxy_sync_interval' ), '5' ); ?>>5 minutes</option>
                                <option value="15" <?php selected( $s( 'galaxy_sync_interval', '15' ), '15' ); ?>>15 minutes</option>
                                <option value="30" <?php selected( $s( 'galaxy_sync_interval' ), '30' ); ?>>30 minutes</option>
                                <option value="60" <?php selected( $s( 'galaxy_sync_interval' ), '60' ); ?>>1 hour</option>
                            </select>
                        </div>
                        <div class="uc-field-row">
                            <label>Manual Sync</label>
                            <div class="uc-conn-controls">
                                <button type="button" class="button uc-galaxy-sync">Sync Now</button>
                                <span class="uc-conn-pill <?php echo $c( 'galaxy_api_key' ) ? 'is-connected' : ''; ?>"><?php echo $c( 'galaxy_api_key' ) ? 'Connected' : 'Not configured'; ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- WEBHOOKS -->
                <div class="uc-integration-panel">
                    <div class="uc-panel-header" onclick="this.parentElement.classList.toggle('uc-panel-open')">
                        <span class="uc-panel-icon"><?php echo sfaf_icon( 'bolt', array( 'size' => '20px' ) ); ?></span>
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
                            <input type="password" name="uc_settings[webhook_secret]" value="<?php echo esc_attr( $c( 'webhook_secret' ) ); ?>" class="uc-input" />
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
                            <code><?php echo esc_html( $label ); ?></code>
                            <label class="uc-toggle"><input type="checkbox" name="uc_settings[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( $s( $key ), '1' ); ?> /><span class="uc-toggle-slider"></span></label>
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
