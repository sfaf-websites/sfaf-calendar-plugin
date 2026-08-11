<?php
/**
 * Plugin Name: SFAF Calendar
 * Plugin URI: https://sfaf.org
 * Description: The San Francisco AIDS Foundation event calendar. Staff manage events, RSVPs, reminders, and recurring series in one place, through the WordPress admin or the /caladmin front-end portal, and display them on this site with the [sfaf_calendar] shortcode or embed them on any other site with a small block of HTML.
 * Version: 3.23.0
 * Author: San Francisco AIDS Foundation
 * Author URI: https://sfaf.org
 * License: GPL v2 or later
 * Text Domain: sfaf-calendar
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'SFAF_VERSION', '3.23.0' );

/**
 * Schema version for the plugin's own tables.
 *
 * Bumped whenever a CREATE TABLE below changes. Checked on every load so a
 * plugin updated by overwriting its folder — which never fires the activation
 * hook — still gets its new tables, instead of throwing "table doesn't exist"
 * the first time the runner looks for one.
 */
define( 'SFAF_DB_VERSION', '3' );
define( 'SFAF_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SFAF_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Deactivate the plugin and show an admin notice explaining why.
 *
 * The last-resort safety net: a fatal or compile error at load time would
 * otherwise white-screen the entire site on every request (which is exactly
 * what forced a folder rename in the field). Instead we catch it, switch the
 * plugin off, and tell the admin what happened.
 *
 * @param string $context Where the failure happened, for the notice.
 * @param string $message The error message.
 */
function sfaf_fail_safe( $context, $message ) {
    // Remember the reason so a notice can be shown after any redirect.
    set_transient( 'sfaf_fatal_notice', array( 'context' => $context, 'message' => $message ), HOUR_IN_SECONDS );

    // Turn the plugin off so the next request loads cleanly rather than
    // re-triggering the same fatal. deactivate_plugins() lives in the admin
    // plugin API, which isn't always loaded this early.
    add_action( 'admin_init', function () {
        if ( ! function_exists( 'deactivate_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        deactivate_plugins( plugin_basename( __FILE__ ) );
    } );
}

/**
 * Render the stored fatal notice on admin screens, once.
 */
function sfaf_render_fatal_notice() {
    $info = get_transient( 'sfaf_fatal_notice' );
    if ( ! $info ) {
        return;
    }
    delete_transient( 'sfaf_fatal_notice' );
    $context = isset( $info['context'] ) ? $info['context'] : 'loading';
    $message = isset( $info['message'] ) ? $info['message'] : '';
    echo '<div class="notice notice-error"><p><strong>SFAF Calendar was deactivated</strong> to protect your site after a problem while '
        . esc_html( $context ) . '.</p>';
    if ( $message ) {
        echo '<p><code>' . esc_html( $message ) . '</code></p>';
    }
    echo '</div>';
}
add_action( 'admin_notices', 'sfaf_render_fatal_notice' );

// Core includes, loaded defensively. A syntax/compile error or a fatal in any
// of these files is caught here (as a Throwable) rather than taking the whole
// site down; the plugin deactivates itself and the rest of this file is skipped.
$sfaf_includes = array(
    'includes/class-sfaf-credentials.php',
    'includes/sfaf-template-functions.php',
    'includes/class-sfaf-series.php',
    'includes/class-sfaf-venues.php',
    'includes/class-sfaf-categories.php',
    'includes/class-sfaf-post-types.php',
    'includes/class-sfaf-shortcodes.php',
    'includes/class-sfaf-embed.php',
    'includes/class-sfaf-rsvp.php',
    'includes/class-sfaf-optins.php',
    'includes/class-sfaf-reminders.php',
    'includes/class-sfaf-cron.php',
    'includes/class-sfaf-recurrence.php',
    'includes/class-sfaf-list-columns.php',
    'includes/class-sfaf-sync.php',
    'includes/class-sfaf-gfmp.php',
    'includes/class-sfaf-eventbrite.php',
    'includes/class-sfaf-faq-sets.php',
    'includes/class-sfaf-teams.php',
    'includes/class-sfaf-search.php',
    'includes/class-sfaf-sources.php',
    'includes/class-sfaf-source-eventbrite.php',
    'includes/class-sfaf-source-gfmp.php',
    'includes/class-sfaf-seo.php',
    'includes/class-sfaf-migrate.php',
    'includes/class-sfaf-portal.php',
    'includes/sfaf-sample-data.php',
    'admin/class-sfaf-admin.php',
);
try {
    foreach ( $sfaf_includes as $sfaf_include ) {
        require_once SFAF_PLUGIN_DIR . $sfaf_include;
    }
} catch ( \Throwable $sfaf_load_error ) {
    // Bail before registering any hooks — the classes/functions those hooks
    // depend on may be missing, which would only cause more fatals.
    sfaf_fail_safe( 'loading the plugin files', $sfaf_load_error->getMessage() );
    return;
}

/**
 * Initialize the plugin
 */
function sfaf_init() {
    // Before anything reads a credential: move any that are still sitting in
    // uc_settings into their own option. Idempotent, and a no-op once done.
    SFAF_Credentials::migrate();

    // Split any venue address still held as one line into street, city, state
    // and ZIP. Adds only, never overwrites, and a no-op once done.
    SFAF_Venues::migrate_addresses();

    // Tables, for the install that was updated by overwriting the folder.
    sfaf_maybe_install_tables();

    // The one posts_clauses filter that widens an event search beyond the post
    // table. Inert on every query that does not ask for it. See SFAF_Search.
    SFAF_Search::register();

    $post_types = new SFAF_Post_Types();
    $post_types->register();

    $shortcodes = new SFAF_Shortcodes();
    $shortcodes->register();

    // The embed endpoint renders through the shortcode class rather than
    // duplicating it, so it is handed the same instance.
    $embed = new SFAF_Embed( $shortcodes );
    $embed->register();

    $rsvp = new SFAF_RSVP();
    $rsvp->register();

    // Morning-of reminders, and the single hourly runner that drives them.
    $reminders = new SFAF_Reminders();
    $reminders->register();

    $cron = new SFAF_Cron();
    $cron->register();

    $recurrence = new SFAF_Recurrence();
    $recurrence->register();

    $sync = new SFAF_Sync();
    $sync->register();

    // GoFundMe Pro (Classy): authentication only at this stage.
    $gfmp = new SFAF_GFMP();
    $gfmp->register();

    $eventbrite = new SFAF_Eventbrite();
    $eventbrite->register();

    // Third-party import framework. The queue statuses are registered here,
    // on `init`, which is where register_post_status() must be called.
    $sources = new SFAF_Sources();
    $sources->register();

    // The source adapters. Both go through the same framework and the same
    // "Fetch updates" run; EveryAction will register the same way, or from
    // outside via the sfaf_source_adapters filter.
    SFAF_Sources::register_adapter( new SFAF_Source_Eventbrite() );
    SFAF_Sources::register_adapter( new SFAF_Source_GFMP() );

    // 2.6.0 wrote a source image to the manual-override key as well as its
    // own. Separate them once so a refetch can refresh the source image
    // without stepping on an image a person chose.
    SFAF_Sources::migrate_image_split();

    $portal = new SFAF_Portal();
    $portal->register();

    $seo = new SFAF_SEO();
    $seo->register();

    // The 3.0.0 data migration. Registers its admin notice and screen only —
    // it never writes on its own. See class-sfaf-migrate.php for why the write
    // is a button somebody presses and not something that happens on upgrade.
    SFAF_Migrate::register();

    if ( is_admin() ) {
        $admin = new SFAF_Admin();
        $admin->register();

        $columns = new SFAF_List_Columns();
        $columns->register();
    }
}
add_action( 'init', 'sfaf_init' );

/**
 * Enqueue frontend styles and scripts
 */
function sfaf_enqueue_frontend_assets() {
    wp_enqueue_style(
        'sfaf-calendar-public',
        SFAF_PLUGIN_URL . 'public/css/calendar.css',
        array(),
        SFAF_VERSION
    );
    // Shared inline email validation. A dependency of calendar.js rather than
    // part of it, because the portal and wp-admin need the same behaviour and
    // neither of them loads calendar.js.
    wp_enqueue_script(
        'sfaf-email',
        SFAF_PLUGIN_URL . 'public/js/sfaf-email.js',
        array(),
        SFAF_VERSION,
        true
    );
    wp_enqueue_script(
        'sfaf-calendar-public',
        SFAF_PLUGIN_URL . 'public/js/calendar.js',
        array( 'jquery', 'sfaf-email' ),
        SFAF_VERSION,
        true
    );
    wp_localize_script( 'sfaf-calendar-public', 'ucData', array(
        'ajaxUrl' => admin_url( 'admin-ajax.php' ),
        'nonce'   => wp_create_nonce( 'uc_nonce' ),
        'restUrl' => rest_url( 'sfaf-calendar/v1/' ),
    ) );
}
add_action( 'wp_enqueue_scripts', 'sfaf_enqueue_frontend_assets' );

/**
 * Enqueue admin styles and scripts
 */
function sfaf_enqueue_admin_assets( $hook ) {
    $screen         = get_current_screen();
    $plugin_pages   = array( 'uc-rsvps', 'uc-settings', 'uc-shortcode-generator', 'uc-embed', 'uc-series', 'uc-automation', 'uc-users' );
    $is_plugin_page = isset( $_GET['page'] ) && in_array( $_GET['page'], $plugin_pages, true );
    $is_event_edit  = $screen && $screen->post_type === 'uc_event';

    if ( ! $is_plugin_page && ! $is_event_edit ) {
        return;
    }

    // WP media (logo upload) + colour picker (branding) used on the settings/event screens.
    wp_enqueue_media();
    wp_enqueue_style( 'wp-color-picker' );

    wp_enqueue_style(
        'sfaf-calendar-admin',
        SFAF_PLUGIN_URL . 'admin/css/admin.css',
        array(),
        SFAF_VERSION
    );
    wp_enqueue_script(
        'sfaf-email',
        SFAF_PLUGIN_URL . 'public/js/sfaf-email.js',
        array(),
        SFAF_VERSION,
        true
    );
    wp_enqueue_script(
        'sfaf-calendar-admin',
        SFAF_PLUGIN_URL . 'admin/js/admin.js',
        array( 'jquery', 'wp-color-picker', 'sfaf-email' ),
        SFAF_VERSION,
        true
    );
    wp_localize_script( 'sfaf-calendar-admin', 'sfafAdmin', array(
        'ajaxUrl' => admin_url( 'admin-ajax.php' ),
        'nonce'   => wp_create_nonce( 'uc_admin_nonce' ),
    ) );

    // The embed generator previews the block by running the real embed script
    // against the real endpoint, so it loads exactly what a remote site loads.
    // It also needs the public calendar stylesheet, since the preview renders
    // actual event cards.
    if ( isset( $_GET['page'] ) && $_GET['page'] === 'uc-embed' ) {
        wp_enqueue_style(
            'sfaf-calendar-public',
            SFAF_PLUGIN_URL . 'public/css/calendar.css',
            array(),
            SFAF_VERSION
        );
        wp_enqueue_script(
            'sfaf-calendar-embed',
            SFAF_PLUGIN_URL . 'public/js/embed.js',
            array(),
            SFAF_VERSION,
            true
        );
    }
}
add_action( 'admin_enqueue_scripts', 'sfaf_enqueue_admin_assets' );

/**
 * Load the plugin's single event template unless the theme provides one.
 */
function sfaf_template_include( $template ) {
    // event => the plugin's own template file, theme override first in both
    // cases. The series archive is here because the "Part of series" badge now
    // links to it — see sfaf_series_link().
    $ours = array();
    if ( is_singular( 'uc_event' ) ) {
        $ours = array( 'single-uc_event.php' );
    } elseif ( is_tax( SFAF_Series::TAXONOMY ) ) {
        $ours = array( 'taxonomy-uc_series.php' );
    }
    if ( empty( $ours ) ) {
        return $template;
    }

    $theme_template = locate_template( $ours );
    if ( $theme_template ) {
        return $theme_template;
    }
    $plugin_template = SFAF_PLUGIN_DIR . 'templates/' . $ours[0];
    if ( file_exists( $plugin_template ) ) {
        return $plugin_template;
    }
    return $template;
}
add_filter( 'template_include', 'sfaf_template_include' );

/**
 * Output an .ics download for a single event: /?uc_ics=ID
 */
function sfaf_output_ics() {
    if ( empty( $_GET['uc_ics'] ) ) {
        return;
    }

    $post_id = intval( $_GET['uc_ics'] );
    $post    = get_post( $post_id );
    if ( ! $post || $post->post_type !== 'uc_event' || $post->post_status !== 'publish' ) {
        status_header( 404 );
        exit;
    }

    $dt = sfaf_event_datetimes( $post_id );
    if ( ! $dt ) {
        status_header( 404 );
        exit;
    }
    list( $start, $end ) = $dt;

    $utc = new DateTimeZone( 'UTC' );
    $start->setTimezone( $utc );
    $end->setTimezone( $utc );

    $host        = wp_parse_url( home_url(), PHP_URL_HOST );
    $description = wp_strip_all_tags( get_the_excerpt( $post_id ) );

    $lines   = array();
    $lines[] = 'BEGIN:VCALENDAR';
    $lines[] = 'VERSION:2.0';
    $lines[] = 'PRODID:-//SFAF Calendar//EN';
    $lines[] = 'CALSCALE:GREGORIAN';
    $lines[] = 'METHOD:PUBLISH';
    $lines[] = 'BEGIN:VEVENT';
    $lines[] = 'UID:sfaf-event-' . $post_id . '@' . $host;
    $lines[] = 'DTSTAMP:' . gmdate( 'Ymd\THis\Z' );
    $lines[] = 'DTSTART:' . $start->format( 'Ymd\THis\Z' );
    $lines[] = 'DTEND:' . $end->format( 'Ymd\THis\Z' );
    $lines[] = 'SUMMARY:' . sfaf_ics_escape( get_the_title( $post_id ) );
    $lines[] = 'DESCRIPTION:' . sfaf_ics_escape( $description );
    $lines[] = 'LOCATION:' . sfaf_ics_escape( sfaf_event_location( $post_id ) );
    $lines[] = 'URL:' . esc_url_raw( get_permalink( $post_id ) );
    $lines[] = 'END:VEVENT';
    $lines[] = 'END:VCALENDAR';

    $slug = sanitize_title( get_the_title( $post_id ) ) ?: 'event';

    nocache_headers();
    header( 'Content-Type: text/calendar; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename="' . $slug . '.ics"' );
    echo implode( "\r\n", $lines );
    exit;
}
add_action( 'template_redirect', 'sfaf_output_ics' );

/**
 * Output branding overrides (colors) in the head.
 */
function sfaf_output_branding_css() {
    $settings = get_option( 'uc_settings', array() );

    // SFAF brand defaults (yellow primary, teal accent). These are exposed as
    // --uc-primary / --uc-accent for branded UI accents; the event cards keep
    // their own teal/warm palette (--uc-teal / --uc-warm) intentionally.
    $primary = ( ! empty( $settings['brand_primary_color'] ) ? sanitize_hex_color( $settings['brand_primary_color'] ) : '' );
    $accent  = ( ! empty( $settings['brand_accent_color'] ) ? sanitize_hex_color( $settings['brand_accent_color'] ) : '' );
    $primary = $primary ? $primary : '#FFD900';
    $accent  = $accent ? $accent : '#16BECF';

    echo "<style id='sfaf-branding'>:root{";
    echo '--uc-primary:' . esc_html( $primary ) . ';';
    echo '--uc-accent:' . esc_html( $accent ) . ';';
    echo '--sfaf-yellow:#FFD900;--sfaf-black:#000000;--sfaf-gray:#373433;';
    echo "}</style>\n";
}
add_action( 'wp_head', 'sfaf_output_branding_css' );

/**
 * Add the chosen card style as a body class so the CSS can react to it.
 */
function sfaf_body_class( $classes ) {
    $settings = get_option( 'uc_settings', array() );
    $style    = isset( $settings['brand_card_style'] ) ? $settings['brand_card_style'] : '';
    if ( $style ) {
        $classes[] = 'sfaf-card-' . sanitize_html_class( $style );
    }
    return $classes;
}
add_filter( 'body_class', 'sfaf_body_class' );

/**
 * Activation hook.
 *
 * Wraps the real work so a failure aborts the activation cleanly — the plugin
 * is switched off and the admin sees the reason — instead of leaving a broken,
 * white-screening install behind.
 */
function sfaf_activate() {
    try {
        sfaf_run_activation();
    } catch ( \Throwable $e ) {
        if ( ! function_exists( 'deactivate_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        deactivate_plugins( plugin_basename( __FILE__ ) );
        set_transient( 'sfaf_fatal_notice', array( 'context' => 'activating the plugin', 'message' => $e->getMessage() ), HOUR_IN_SECONDS );

        // Abort activation with a readable page (WordPress styles wp_die — this
        // is not a white screen) and a link back to the plugins list.
        wp_die(
            '<h1>SFAF Calendar could not be activated</h1>'
            . '<p>The plugin was switched off and your site was left untouched.</p>'
            . '<p><strong>Reason:</strong> <code>' . esc_html( $e->getMessage() ) . '</code></p>',
            'Plugin activation failed',
            array( 'back_link' => true )
        );
    }
}
register_activation_hook( __FILE__, 'sfaf_activate' );

/**
 * The real activation work: tables, the hourly runner, post type/taxonomies,
 * sample data, portal rewrites, flush. Any fatal here is caught by
 * sfaf_activate().
 *
 * NOTHING HERE MAY TOUCH STORED CREDENTIALS. This runs on every activation,
 * including the reactivation that follows installing a new version, so any
 * option reset added here would wipe the connected platforms on every update.
 * The credential store (sfaf_credentials), the GoFundMe Pro token
 * (sfaf_gfmp_token), the Eventbrite verification record
 * (sfaf_eventbrite_status) and uc_settings are all deliberately untouched —
 * as is the whole plugin, which has no uninstall.php and no
 * register_uninstall_hook, so even deleting it leaves them intact.
 */
function sfaf_run_activation() {
    sfaf_install_tables();

    // The hourly runner. Scheduling it here means a fresh install is already
    // ticking over; sfaf_init() re-checks on every load so an unscheduled event
    // (a database restore, say) puts itself back rather than staying gone.
    SFAF_Cron::ensure_scheduled();

    // Register the post type and taxonomies directly so their rewrite rules
    // exist before we flush. (Calling register() only adds init hooks, which
    // won't fire again during activation.) register_taxonomies() includes
    // uc_series, whose archive is where the "Part of series" badge points, so
    // this is what makes that URL resolve on a fresh activation.
    $post_types = new SFAF_Post_Types();
    $post_types->register_post_type();
    $post_types->register_taxonomies();

    // Seed sample events + taxonomy terms on first activation (no-op if any exist).
    sfaf_install_sample_data();

    // Register the /caladmin portal rewrite rules before flushing.
    SFAF_Portal::add_rewrite_rules();

    flush_rewrite_rules();
}

/**
 * Create or update this plugin's own tables.
 *
 * dbDelta is additive: it adds missing tables, columns and indexes and never
 * drops anything, so this is safe to run repeatedly and safe on an install that
 * already has data.
 */
function sfaf_install_tables() {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();

    $rsvps = $wpdb->prefix . 'uc_rsvps';
    $sql   = array();

    // RSVPs. `status` is a plain string, not an enum: 'confirmed' (a real
    // registration, and the only value counted towards capacity),
    // 'subscribed' (pressed "Get Reminders", holds no place) and, since
    // 2.11.0, 'cancelled' (released their place through a reminder's cancel
    // link — kept rather than deleted so the history survives).
    //
    // event_title is the SNAPSHOT taken when an event is permanently deleted
    // (SFAF_RSVP::snapshot_event_title). The rows deliberately outlive their
    // event — attendance history is worth keeping and is the only evidence a
    // person ever registered — but they used to outlive it unreadable, because
    // the list joins on the post and rendered a blank Event column once the
    // post had gone. Written once, at the last moment the title can be known.
    $sql[] = "CREATE TABLE $rsvps (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        event_id bigint(20) unsigned NOT NULL,
        event_title varchar(255) NOT NULL DEFAULT '',
        name varchar(200) NOT NULL,
        email varchar(200) NOT NULL,
        phone varchar(50) DEFAULT '',
        status varchar(20) DEFAULT 'confirmed',
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY event_id (event_id),
        KEY email (email)
    ) $charset;";

    // THE REMINDER LEDGER, AND THE UNIQUE KEY THAT IS THE SEND-ONCE GUARANTEE.
    //
    // event_recipient is UNIQUE, and a send is claimed by inserting the row
    // before the mail goes out. A second attempt for the same pair — an
    // overlapping run, a manual re-run, a run resumed after a crash — fails
    // that insert and is skipped. The guarantee lives in the database, not in
    // any code path that could be bypassed.
    //
    // The key is on a sha256 of the lowercased address rather than the address
    // itself: a fixed 64 characters indexes cleanly on every MySQL version,
    // where a composite key over varchar(200) in utf8mb4 can exceed the older
    // 767-byte index limit. The readable address is kept alongside it.
    $reminders = $wpdb->prefix . 'uc_reminder_log';
    $sql[] = "CREATE TABLE $reminders (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        event_id bigint(20) unsigned NOT NULL,
        event_title varchar(255) NOT NULL DEFAULT '',
        email varchar(200) NOT NULL,
        recipient_hash char(64) NOT NULL,
        recipient_type varchar(20) NOT NULL DEFAULT 'rsvp',
        token char(32) NOT NULL,
        claimed_at datetime NULL,
        sent_at datetime NULL,
        result varchar(20) NOT NULL DEFAULT 'claimed',
        PRIMARY KEY (id),
        UNIQUE KEY event_recipient (event_id, recipient_hash),
        UNIQUE KEY token (token),
        KEY event_id (event_id)
    ) $charset;";

    // Marketing opt-ins. One row per consent, never updated in place: the
    // point is a record of when and where each consent was given.
    $optins = $wpdb->prefix . 'uc_optins';
    $sql[] = "CREATE TABLE $optins (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        email varchar(200) NOT NULL,
        name varchar(200) NOT NULL DEFAULT '',
        event_id bigint(20) unsigned NOT NULL DEFAULT 0,
        source_form varchar(40) NOT NULL DEFAULT '',
        consented_at datetime NULL,
        created_gmt datetime NULL,
        PRIMARY KEY (id),
        KEY email (email),
        KEY event_id (event_id)
    ) $charset;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    foreach ( $sql as $statement ) {
        dbDelta( $statement );
    }

    update_option( 'sfaf_db_version', SFAF_DB_VERSION );
}

/**
 * Run the installer when the stored schema version is behind the code's.
 *
 * The activation hook does not fire when a plugin is updated by overwriting
 * its folder, which is exactly how this one is deployed, so relying on it
 * alone would leave the new tables missing on every upgraded site.
 */
function sfaf_maybe_install_tables() {
    if ( get_option( 'sfaf_db_version' ) === SFAF_DB_VERSION ) {
        return;
    }
    sfaf_install_tables();
}

/**
 * Deactivation hook
 */
function sfaf_deactivate() {
    // A switched-off plugin must not leave a scheduled event behind firing at
    // a hook nothing listens to.
    SFAF_Cron::unschedule();
    flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'sfaf_deactivate' );

/**
 * Register REST API routes
 */
function sfaf_register_rest_routes() {
    register_rest_route( 'sfaf-calendar/v1', '/events', array(
        'methods'             => 'GET',
        'callback'            => 'sfaf_rest_get_events',
        'permission_callback' => 'sfaf_rest_events_permission',
    ) );
    register_rest_route( 'sfaf-calendar/v1', '/rsvp', array(
        'methods'             => 'POST',
        'callback'            => 'sfaf_rest_submit_rsvp',
        'permission_callback' => '__return_true',
    ) );
}
add_action( 'rest_api_init', 'sfaf_register_rest_routes' );

/**
 * Permission for the public events feed. If an API key has been generated it
 * must be supplied via the X-SFAF-API-Key header (satellites send it); if no
 * key is configured the feed is open so it works out of the box.
 */
function sfaf_rest_events_permission( $request ) {
    $key = SFAF_Credentials::get( 'multisite_api_key' );
    if ( $key === '' ) {
        return true;
    }
    $provided = (string) $request->get_header( 'x_sfaf_api_key' );
    if ( $provided && hash_equals( $key, $provided ) ) {
        return true;
    }
    return new WP_Error( 'sfaf_forbidden', 'A valid X-SFAF-API-Key header is required.', array( 'status' => 403 ) );
}

/**
 * REST: Get events
 */
function sfaf_rest_get_events( $request ) {
    $per_page = (int) $request->get_param( 'per_page' );
    if ( $per_page < 1 ) {
        $per_page = 20;
    }
    $per_page = min( $per_page, 100 );

    $page = (int) $request->get_param( 'page' );
    if ( $page < 1 ) {
        $page = 1;
    }

    $args = array(
        'post_type'      => 'uc_event',
        'posts_per_page' => $per_page,
        'paged'          => $page,
        'post_status'    => 'publish',
        'meta_key'       => '_uc_event_date',
        'orderby'        => 'meta_value',
        'order'          => 'ASC',
    );

    $category = $request->get_param( 'category' );
    if ( $category ) {
        $args['tax_query'] = array( array(
            'taxonomy' => 'uc_event_category',
            'field'    => 'slug',
            'terms'    => $category,
        ) );
    }

    $query = new WP_Query( $args );
    $events = array();

    $sync = new SFAF_Sync();
    foreach ( $query->posts as $post ) {
        // Full payload (content, status, series + source info) for multi-site sync.
        $events[] = $sync->event_to_array( $post->ID );
    }

    return new WP_REST_Response( array(
        'events' => $events,
        'total'  => $query->found_posts,
    ), 200 );
}

/**
 * REST: Submit RSVP
 */
function sfaf_rest_submit_rsvp( $request ) {
    $rsvp = new SFAF_RSVP();
    $result = $rsvp->submit( array(
        'event_id' => intval( $request->get_param( 'event_id' ) ),
        'name'     => sanitize_text_field( $request->get_param( 'name' ) ),
        'email'    => sanitize_email( $request->get_param( 'email' ) ),
        'phone'    => sanitize_text_field( $request->get_param( 'phone' ) ),
        // Absent means no. Consent has to arrive explicitly.
        'optin'    => (bool) $request->get_param( 'optin' ),
    ) );
    return new WP_REST_Response( $result, $result['success'] ? 200 : 400 );
}

/**
 * Helper: shared per-request store for confirmed RSVP counts.
 * Returned by reference so the batch primer and the single-event getter share it.
 */
function &sfaf_rsvp_count_store() {
    static $store = array();
    return $store;
}

/**
 * Helper: prime confirmed RSVP counts for many events in a single query.
 * List screens call this once with all visible event IDs so sfaf_get_rsvp_count()
 * never has to run one COUNT(*) per row.
 */
function sfaf_prime_rsvp_counts( $event_ids ) {
    $event_ids = array_filter( array_unique( array_map( 'intval', (array) $event_ids ) ) );
    if ( empty( $event_ids ) ) {
        return;
    }
    $store =& sfaf_rsvp_count_store();
    $need  = array_diff( $event_ids, array_keys( $store ) );
    if ( empty( $need ) ) {
        return;
    }
    global $wpdb;
    $table = $wpdb->prefix . 'uc_rsvps';
    $in    = implode( ',', array_map( 'intval', $need ) );
    $rows  = $wpdb->get_results(
        "SELECT event_id, COUNT(*) AS c FROM $table WHERE status = 'confirmed' AND event_id IN ($in) GROUP BY event_id",
        OBJECT_K
    );
    foreach ( $need as $id ) {
        $store[ $id ] = isset( $rows[ $id ] ) ? (int) $rows[ $id ]->c : 0;
    }
}

/**
 * Helper: Get RSVP count for event (served from the primed store when available).
 */
function sfaf_get_rsvp_count( $event_id ) {
    $event_id = (int) $event_id;
    $store    =& sfaf_rsvp_count_store();
    if ( isset( $store[ $event_id ] ) ) {
        return $store[ $event_id ];
    }
    global $wpdb;
    $table = $wpdb->prefix . 'uc_rsvps';
    $store[ $event_id ] = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM $table WHERE event_id = %d AND status = 'confirmed'",
        $event_id
    ) );
    return $store[ $event_id ];
}
