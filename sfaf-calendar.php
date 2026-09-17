<?php
/**
 * Plugin Name: SFAF Calendar
 * Plugin URI: https://sfaf.org
 * Description: The San Francisco AIDS Foundation event calendar. Staff manage events, RSVPs, reminders, and recurring series in one place, through the WordPress admin or the /caladmin front-end portal, and display them on this site with the [sfaf_calendar] shortcode or embed them on any other site with a small block of HTML.
 * Version: 3.96.0
 * Author: San Francisco AIDS Foundation
 * Author URI: https://sfaf.org
 * License: GPL v2 or later
 * Text Domain: sfaf-calendar
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'SFAF_VERSION', '3.96.0' );

/**
 * Schema version for the plugin's own tables.
 *
 * Bumped whenever a CREATE TABLE below changes. Checked on every load so a
 * plugin updated by overwriting its folder — which never fires the activation
 * hook — still gets its new tables, instead of throwing "table doesn't exist"
 * the first time the runner looks for one.
 */
define( 'SFAF_DB_VERSION', '6' );
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
 * NEVER RUNS IN NORMAL OPERATION, AND KEPT ON PURPOSE. DO NOT DELETE THIS OR
 * sfaf_render_fatal_notice() IN A CLEANUP. A sweep for uncalled code will not
 * flag them, because both are hooked, but a sweep for "has this ever executed"
 * would: the only thing that calls sfaf_fail_safe() is the catch block around
 * the includes, and that has never caught anything. The release it exists for
 * is the one where it does.
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
    // Online events. After SFAF_Venues, whose set_for_event() it calls to clear
    // the place, and after the template functions that hold the address parts.
    'includes/class-sfaf-online.php',
    'includes/class-sfaf-organizers.php',
    'includes/class-sfaf-closures.php',
    'includes/class-sfaf-categories.php',
    'includes/class-sfaf-post-types.php',
    'includes/class-sfaf-shortcodes.php',
    'includes/class-sfaf-embed.php',
    'includes/class-sfaf-rsvp.php',
    'includes/class-sfaf-optins.php',
    'includes/class-sfaf-privacy.php',
    'includes/class-sfaf-media-folder.php',
    // The calendar's images, tagged by series. After the folder rule it reads.
    'includes/class-sfaf-media.php',
    // Files sent by people with no account, and what the two public forms
    // share. Both load before the forms that call them.
    'includes/class-sfaf-uploads.php',
    'includes/class-sfaf-turnstile.php',
    'includes/class-sfaf-submissions.php',
    'includes/class-sfaf-request.php',
    'includes/class-sfaf-submit.php',
    'includes/class-sfaf-cancellation.php',
    // The mail layer, before anything that sends: SFAF_Email builds and hands
    // to wp_mail(), SFAF_Notifications decides who gets what, and
    // sfaf-notify-consent.php answers whether a manager asked for any of it.
    'includes/class-sfaf-rich-text.php',
    'includes/sfaf-notify-consent.php',
    'includes/class-sfaf-email.php',
    'includes/class-sfaf-notifications.php',
    'includes/class-sfaf-announce.php',
    'includes/class-sfaf-reminders.php',
    // Following a series. After SFAF_Email, which builds its confirmation, and
    // after SFAF_Reminders, whose new_token() is the one token generator.
    'includes/class-sfaf-follow.php',
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
    'includes/class-sfaf-portal.php',
    'includes/class-sfaf-orphans.php',
    'includes/sfaf-sample-data.php',
    // Updates from GitHub releases. Last, because it depends on SFAF_VERSION
    // and on nothing else in the plugin.
    'includes/class-sfaf-updater.php',
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

/*
 * THE FIFTEEN-MINUTE INTERVAL IS REGISTERED HERE, NOT IN SFAF_Cron::register().
 *
 * WordPress hangs wp_cron() on `init` at priority 10, and default-filters.php
 * adds it long before this plugin adds sfaf_init() at the same priority, so
 * wp_cron() runs FIRST. If the interval is not already on the filter by then,
 * wp_cron() cannot find the recurrence its own stored event names, and its
 * answer to that is to unschedule the event: the runner would delete itself,
 * quietly, on the first request after an update. File scope is the only place
 * early enough to be certain, and the callback is a pure array literal, so
 * running it on every request costs nothing.
 */
add_filter( 'cron_schedules', array( 'SFAF_Cron', 'add_schedule' ) );

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

    // Retire any satellite API key left from setup. Runs once, then costs one
    // autoloaded option read. Same pattern as the two above.
    sfaf_retire_satellite_key();

    // Tables, for the install that was updated by overwriting the folder.
    sfaf_maybe_install_tables();

    // The one posts_clauses filter that widens an event search beyond the post
    // table. Inert on every query that does not ask for it. See SFAF_Search.
    SFAF_Search::register();

    // Private events: the hooks for the routes WordPress owns (search, the
    // archives, feeds, both sitemaps, core REST and the robots tag). The routes
    // this plugin owns are excluded at the query builders themselves.
    SFAF_Privacy::register();

    // The caladmin picker offers images from the calendar folder only, and
    // sends its own uploads there. Filters on core's own _wp_attached_file, so
    // it does not depend on WP Media Folder staying installed.
    SFAF_Media_Folder::register();

    // Series as image tags, which is what the caladmin media screen and the
    // picker on both public forms read. It attaches attachments to uc_series on
    // a later init than SFAF_Series registers it, so the taxonomy exists by the
    // time anything is added to it.
    SFAF_Media::register();

    // The public event request form. A front-end query var, like the cancel
    // link, so no rewrite rule and no REST route: see the note in the class.
    SFAF_Request::register();

    // The public community submission form. Same arrangement, and the same
    // reason for it: the query var's VALUE names the series, so a second
    // campaign is a URL and a series rather than another registration.
    SFAF_Submit::register();

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

    // Following a series: the dialog's ajax route, and the confirm and
    // unsubscribe links. A front-end query var, like the cancel link, for the
    // reason given in the class.
    $follow = new SFAF_Follow();
    $follow->register();

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

    /*
     * UPDATES FROM GITHUB RELEASES. Registered for every request rather than
     * admin only: WordPress runs its update check on cron, which has no admin
     * screen, and a check that only ran in wp-admin would only ever notice a
     * new version while somebody was looking.
     */
    $updater = new SFAF_Updater();
    $updater->register();

    /*
     * THE 3.0.0 SERIES MIGRATION IS GONE, AND IT WAS NEVER RUN.
     *
     * It converted old series PARENT POSTS into series terms. Those were
     * uc_event posts carrying _uc_series_parent, so clearing the calendar of
     * test data removed every one of them, and the calendar had not launched,
     * so there was no other copy of that data anywhere. What remains cannot
     * contain an old-model series: nothing writes _uc_series_parent, nothing
     * reads it, and no import produces it.
     *
     * It was a ONE-WAY DESTRUCTIVE BUTTON sitting permanently in a menu, which
     * is not a thing to leave behind once it has nothing to convert. Removed in
     * 3.27.0: the class, the screen, the admin notice and this call.
     *
     * SFAF_Series::resolve() is NOT part of this and stays. It maps a legacy
     * parent post ID onto a term for old embed snippets, reading term meta the
     * migration would have written. With no migrated terms it simply finds
     * nothing and falls through to the term ID, which is the correct answer.
     *
     * Recoverable from git at 3.26.1 if an old database ever turns up.
     */

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
    // uc-rsvps and uc-series are gone from this list because the screens are
    // gone: 3.27.0 left the WordPress admin holding administrator concerns only
    // and moved both to /caladmin, which loads its own stylesheet.
    // uc-shortcode-generator stays: the page is still registered and still
    // reachable, it just has no menu entry, and it needs these assets when it
    // is opened.
    $plugin_pages   = array( 'uc-settings', 'uc-shortcode-generator', 'uc-embed', 'uc-automation', 'uc-users' );
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

    /*
     * A PRIVATE EVENT'S .ics IS ADDRESSED BY ITS TOKEN, NOT BY ITS ID.
     *
     * THIS IS THE ROUTE THAT ALMOST GOT MISSED, and it is worth writing down
     * because of the shape of the mistake rather than the fix. Making the page
     * unguessable does nothing for a second door into the same event that is
     * addressed differently: ?uc_ics=417 is four digits, and walking them
     * returns a file carrying the title, the date, the time and the address of
     * every private event on the calendar. The page was unguessable and the
     * event was not.
     *
     * So a private event requires k=<its slug>, which is the same 128-bit token
     * the URL carries and is therefore knowable by exactly the people who were
     * sent the link. sfaf_ics_url() adds it. Public events are unchanged and
     * still answer to a bare id, because there is nothing to protect.
     *
     * Add to calendar keeps working for somebody holding the link, which
     * section 3 requires: they reached the page by its token, so the button on
     * it is built with that token.
     */
    if ( SFAF_Privacy::is_private( $post_id ) ) {
        $key = isset( $_GET['k'] ) ? sanitize_title( wp_unslash( $_GET['k'] ) ) : '';
        if ( '' === $key || ! hash_equals( (string) $post->post_name, $key ) ) {
            status_header( 404 );
            exit;
        }
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
    /*
     * FLATTENED, NOT STRIPPED. wp_strip_all_tags() joins the text either
     * side of a tag with nothing between, so a two-paragraph description
     * arrived in the calendar file as "...the firstThe second...".
     */
    $description = sfaf_flatten_html( get_the_excerpt( $post_id ) );

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
    /*
     * THE MEETING LINK, ON THE CONFIRMATION'S COPY OF THIS FILE AND NO OTHER.
     *
     * This endpoint is public and addressed by post id, so nothing about "the
     * event is online and has a link" may be enough on its own to get one: four
     * digits would walk the calendar. `j` is an HMAC keyed on the site's auth
     * salt (SFAF_Online::ics_join_token), and only SFAF_Online::ics_url_with_link()
     * builds one, which only build_confirmation() calls. A request without it,
     * or with a wrong one, gets the same file everybody else gets.
     *
     * SO THIS IS DELIBERATELY WIDER THAN THE EMAIL, AND IT IS RECORDED RATHER
     * THAN ASSUMED. A calendar entry syncs to the person's phone, their laptop
     * and any calendar they have shared with somebody else, so a link that
     * lands here can be read by people who never registered. Mark has decided
     * that, for the case where the person already holds the link because the
     * confirmation carried it. It is not done for the morning-of reminder,
     * because the .ics is offered by the confirmation and by nothing else.
     *
     * LOCATION STAYS "Online Event". The link goes in the description and in
     * the RFC 7986 CONFERENCE property, which is the property that means "the
     * URI you join at" and is what a client offers as a Join button.
     */
    $join = '';
    if ( SFAF_Online::is_online( $post_id ) && SFAF_Online::has_link( $post_id ) ) {
        $asked = isset( $_GET[ SFAF_Online::ICS_JOIN_ARG ] )
            ? sanitize_text_field( wp_unslash( $_GET[ SFAF_Online::ICS_JOIN_ARG ] ) )
            : '';
        if ( SFAF_Online::sends_with( $post_id, 'confirmation' ) && SFAF_Online::ics_join_ok( $post_id, $asked ) ) {
            $join = SFAF_Online::link( $post_id );
        }
    }
    if ( '' !== $join ) {
        $description = 'Join: ' . $join . ( '' !== $description ? "\n\n" . $description : '' );
    }

    $lines[] = 'SUMMARY:' . sfaf_ics_escape( get_the_title( $post_id ) );
    $lines[] = 'DESCRIPTION:' . sfaf_ics_escape( $description );
    $lines[] = 'LOCATION:' . sfaf_ics_escape( sfaf_event_location( $post_id ) );
    if ( '' !== $join ) {
        $lines[] = 'CONFERENCE;VALUE=URI;FEATURE=VIDEO;LABEL=Join the event:' . $join;
    }
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
 *
 * ALSO MARKS THE PAGE AS A CALENDAR SURFACE. See sfaf_is_calendar_surface().
 */
function sfaf_body_class( $classes ) {
    $settings = get_option( 'uc_settings', array() );
    $style    = isset( $settings['brand_card_style'] ) ? $settings['brand_card_style'] : '';
    if ( $style ) {
        $classes[] = 'sfaf-card-' . sanitize_html_class( $style );
    }
    if ( sfaf_is_calendar_surface() ) {
        $classes[] = 'uc-calendar-page';
    }
    return $classes;
}
add_filter( 'body_class', 'sfaf_body_class' );

/**
 * Is the page being rendered one of the calendar's own surfaces?
 *
 * WHAT THIS IS FOR, AND THE ONLY THING IT IS FOR: it stamps `uc-calendar-page`
 * on the body so calendar.css can suppress Weglot's language switcher on the
 * pages this plugin owns, and on no others.
 *
 * THE SWITCHER IS NOT OURS AND IS NOT BEING CHANGED. It belongs to Weglot, a
 * site-wide plugin the rest of resources.sfaf.org depends on. Weglot appends it
 * after </html>, which every browser reparents into <body>, so it floats over
 * whatever document is on screen. This marks which of those documents are ours.
 * Nothing here detects a locale, switches one, or translates anything: 3.16.0
 * read an instruction about this control as licence to build a language feature
 * the plugin never had and 3.17.0 removed all of it, and the note at the deleted
 * set_language case in SFAF_Portal::dispatch_post() is the long version.
 *
 * THE THREE SURFACES THAT ARE NOT HERE ARE NOT OMISSIONS. caladmin and both
 * public forms emit their own documents with `uc-portal` on the body, and
 * portal.css has suppressed the switcher on that class since 3.17.0. The follow
 * and cancel pages emit their own documents too, with `uc-notice-page`. None of
 * the four passes through `body_class` at all, because none of them is a theme
 * template. This function is only for the pages that ARE rendered by the theme.
 *
 * A SHORTCODE IS FOUND IN THE CONTENT, not by watching it render. `body_class`
 * fires in the theme's header, before the loop reaches the shortcode, so there
 * is nothing to have seen yet. The cost is that a calendar placed by a widget or
 * a page builder that stores content elsewhere is not detected; that is a page
 * keeping its switcher, which is the safe direction to be wrong in.
 *
 * @return bool
 */
function sfaf_is_calendar_surface() {
    // The event page, and the series archive the "Part of series" badge links
    // to. Both are rendered by this plugin's own templates.
    if ( is_singular( 'uc_event' ) || is_tax( SFAF_Series::TAXONOMY ) ) {
        return true;
    }

    if ( ! is_singular() ) {
        return false;
    }

    $post = get_post();
    if ( ! $post || ! isset( $post->post_content ) ) {
        return false;
    }

    // Both shortcodes, because either one makes the page a calendar.
    return has_shortcode( $post->post_content, 'sfaf_calendar' )
        || has_shortcode( $post->post_content, 'upcoming_events' );
}

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

    /*
     * AND RETIRE EVERY CACHED EMBED PAYLOAD (3.88.0).
     *
     * SFAF_Embed's own hooks are all content events, so nothing told it that
     * the code building the markup had changed. Announced rather than called
     * directly: this function runs during activation, when SFAF_Embed may not
     * be constructed, and an action lets the class decide whether it is
     * listening. See its register() for why both this and
     * upgrader_process_complete are hooked.
     */
    do_action( 'sfaf_calendar_activated' );
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
    // registration, and the only value counted towards capacity) and, since
    // 2.11.0, 'cancelled' (released their place through a reminder's cancel
    // link — kept rather than deleted so the history survives).
    //
    // THERE IS NO THIRD VALUE. 'subscribed' was one until 3.53.0: the "Get
    // Reminders" button wrote a row here against a single event id for somebody
    // who held no place, which put them on the morning-of reminder list and
    // made every query that asked who was registered have to remember to say
    // IN ('confirmed','subscribed') or quietly disagree with the next one.
    // Following a series lives in uc_series_followers now, and the whole of
    // this table is registrations again.
    //
    // event_title is the SNAPSHOT taken when an event is permanently deleted
    // (SFAF_RSVP::snapshot_event_title). The rows deliberately outlive their
    // event — attendance history is worth keeping and is the only evidence a
    // person ever registered — but they used to outlive it unreadable, because
    // the list joins on the post and rendered a blank Event column once the
    // post had gone. Written once, at the last moment the title can be known.
    //
    // token IS THE CANCEL LINK'S ONLY CREDENTIAL, and it is on the registration
    // rather than on the reminder ledger because the confirmation email goes out
    // the moment somebody registers, hours or weeks before any reminder row
    // exists. 32 hex characters is 128 bits from random_bytes: not guessable,
    // and it identifies exactly one registration, so it can cancel that one and
    // nothing else. Rows written before 3.25.0 have an empty token and simply
    // have no cancel link, which is what they had before.
    //
    // THE KEY ON token IS NOT UNIQUE, AND IT CANNOT BE. Every row that already
    // exists gets the default '', so a unique index would refuse to be created
    // on any site with more than one registration in it and dbDelta would report
    // nothing wrong. The uniqueness that matters is enforced where it can be:
    // the value is 128 random bits, and every lookup rejects an empty token
    // before it queries, so '' can never match the legacy rows it is shared by.
    //
    // cancelled_at records WHEN somebody released their place. The status column
    // already says that they did; this says when, which is the question an
    // organizer looking at a half-empty room actually asks.
    //
    // FIRST AND LAST ARE TWO COLUMNS, AND LAST IS OPTIONAL.
    //
    // The form used to ask for one full name. Somebody registering for an HIV
    // testing session or a trans health group has good reason to give a first
    // name and no more, and some people have one name; requiring a surname
    // costs registrations rather than gaining data. So first_name is what the
    // form requires and last_name is genuinely allowed to be empty, at every
    // level: the field, the validator, this column, and the CSV.
    //
    // `name` STAYS, AND IT IS DERIVED. It is written once, at insert, as the
    // two joined with a space, and nothing else ever writes it. It cannot drift
    // from the pair because it has exactly one writer and is computed from
    // them. It is kept because it is NOT NULL with no default on every install
    // that already has this table, dbDelta does not drop columns, and an insert
    // that omitted it would fail under strict mode. Reads go through
    // SFAF_RSVP::display_name(), which builds from the pair.
    $sql[] = "CREATE TABLE $rsvps (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        event_id bigint(20) unsigned NOT NULL,
        event_title varchar(255) NOT NULL DEFAULT '',
        name varchar(200) NOT NULL,
        first_name varchar(100) NOT NULL DEFAULT '',
        last_name varchar(100) NOT NULL DEFAULT '',
        email varchar(200) NOT NULL,
        phone varchar(50) DEFAULT '',
        status varchar(20) DEFAULT 'confirmed',
        token char(32) NOT NULL DEFAULT '',
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        cancelled_at datetime NULL,
        PRIMARY KEY (id),
        KEY event_id (event_id),
        KEY email (email),
        KEY token (token)
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

    // SERIES FOLLOWERS. Somebody who wants to hear when new dates are added to
    // one programme, and who will never receive anything else.
    //
    // ITS OWN TABLE, AND THAT IS THE POINT OF 3.53.0. This used to be a row in
    // uc_rsvps at status 'subscribed', which put a person who held no place
    // into every query that asked who was registered, and made three pieces of
    // email copy branch on the difference to undo what the storage claimed. A
    // follower is not a registration and shares no query with one.
    //
    // NOT AN OPT-IN EITHER. Nothing here is written to uc_optins and nothing
    // here reaches the newsletter. Following a programme's dates is a narrow
    // operational subscription; the mailing list is a separate consent with its
    // own table and its own evidence.
    //
    // email_key IS THE UNIQUENESS, and it is a sha256 of the LOWERCASED
    // address. Case is not a second person, so the index cannot be on the
    // readable column; and a composite key over varchar(200) in utf8mb4 can
    // exceed the older 767-byte index limit, which is why the reminder ledger
    // hashes for the same reason.
    //
    // TWO TOKENS, AND THEY ARE NOT INTERCHANGEABLE. `confirm_token` turns a
    // pending row active and is CLEARED the moment it is used, so it cannot be
    // replayed. `token` unsubscribes, is issued at the same moment, is sent in
    // the very first email, and never expires: a credential whose only power is
    // to take somebody off a list has to still work the day they go looking
    // for it. Neither key is unique — every confirmed row holds confirm_token
    // '' — so every lookup rejects an empty token before it queries, exactly as
    // the RSVP cancel link does.
    $followers = $wpdb->prefix . 'uc_series_followers';
    $sql[] = "CREATE TABLE $followers (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        term_id bigint(20) unsigned NOT NULL,
        email varchar(200) NOT NULL,
        email_key char(64) NOT NULL,
        status varchar(20) NOT NULL DEFAULT 'pending',
        token char(32) NOT NULL DEFAULT '',
        confirm_token char(32) NOT NULL DEFAULT '',
        created_at datetime NULL,
        confirmed_at datetime NULL,
        PRIMARY KEY (id),
        UNIQUE KEY term_email (term_id, email_key),
        KEY token (token),
        KEY confirm_token (confirm_token),
        KEY term_status (term_id, status)
    ) $charset;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    foreach ( $sql as $statement ) {
        dbDelta( $statement );
    }

    sfaf_migrate_notification_lists();

    update_option( 'sfaf_db_version', SFAF_DB_VERSION );
}

/**
 * Fold the old single registration-alert address into the notification list.
 *
 * 3.25.0 merged two lists into one. Until then, "email somebody when an RSVP
 * comes in" was a checkbox plus one address field, and the reminder copy went to
 * the picker of people, teams and typed addresses. Both are now the picker.
 *
 * NOTHING IS DELETED AND NOTHING IS INVENTED. The old address is APPENDED to the
 * event's typed-address list if it is not already reachable, and the old
 * checkbox is translated rather than ignored: an event that said "no, do not
 * email me when somebody registers" still says that afterwards, recorded as the
 * new per-event switch. An event that never expressed a preference gets the new
 * default, which is on.
 *
 * The legacy meta is left in place. It is the evidence for what this did, it
 * costs two rows, and reading it is the only way to answer "why is this address
 * on the list" in six months.
 *
 * Runs once, from the schema upgrade, and is idempotent: a second run finds the
 * address already present and the switch already recorded.
 */
function sfaf_migrate_notification_lists() {
    /*
     * A PERMANENT NO-OP ON THIS INSTALL, AND KEPT ON PURPOSE. DO NOT DELETE.
     *
     * Nothing has written _uc_organizer_email or _uc_notify_organizer since
     * 3.25.0 removed the controls, so on a site already past that release this
     * query matches nothing and always will. That makes it look like dead code
     * in a sweep, and it is not: it is the upgrade path for any site still
     * BELOW 3.25.0, where those two fields are the only record of who was being
     * told about a registration. Deleting it would mean such a site upgrading
     * straight past the migration and silently losing its notification list.
     *
     * These are also the only two reads of that meta left in the plugin, which
     * is why a "read but never written" scan surfaces them.
     */
    $ids = get_posts( array(
        'post_type'      => 'uc_event',
        'post_status'    => 'any',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
        'meta_query'     => array(
            'relation' => 'OR',
            array( 'key' => '_uc_organizer_email', 'compare' => 'EXISTS' ),
            array( 'key' => '_uc_notify_organizer', 'compare' => 'EXISTS' ),
        ),
    ) );

    $moved = 0;
    foreach ( (array) $ids as $id ) {
        $legacy = trim( (string) get_post_meta( $id, '_uc_organizer_email', true ) );
        if ( $legacy && is_email( $legacy ) ) {
            $emails = get_post_meta( $id, SFAF_Reminders::NOTIFY_EMAILS_META, true );
            $emails = is_array( $emails ) ? $emails : array();

            // Already reachable? notify_list() resolves people and teams as well
            // as typed addresses, so the test is against the resolved list
            // rather than against the typed one.
            $already = array_key_exists( strtolower( $legacy ), SFAF_Reminders::notify_list( $id ) );
            if ( ! $already ) {
                $emails[] = $legacy;
                update_post_meta( $id, SFAF_Reminders::NOTIFY_EMAILS_META, array_values( array_unique( $emails ) ) );
                $moved++;
            }
        }

        // The old checkbox wrote '0' on every save, so an explicit "off" is a
        // real answer and is carried over. Absent meta means nobody ever said,
        // and that becomes the new default of on.
        $flag = get_post_meta( $id, '_uc_notify_organizer', true );
        if ( '0' === (string) $flag ) {
            $off = get_post_meta( $id, SFAF_Notifications::OFF_META, true );
            $off = is_array( $off ) ? $off : array();
            if ( ! in_array( 'alert', $off, true ) ) {
                $off[] = 'alert';
                update_post_meta( $id, SFAF_Notifications::OFF_META, array_values( $off ) );
            }
        }
    }

    /*
     * NO sfaf_notify_merge_moved BREADCRUMB. It recorded how many events this
     * moved, in an option nothing ever read, and it was write-only from the day
     * it was added. Removed in 3.28.0. The evidence for what this did is the
     * legacy meta, which is still on every event it touched and is the thing
     * somebody would actually look at.
     */
    unset( $moved );
}

/**
 * Retire any stored satellite API key, once.
 *
 * WHY THIS IS A WRITE AND NOT A NOTE. The satellite feed is dormant (see
 * SFAF_Sync). Dormant has to mean an old credential is worthless, not merely
 * unused, because a key generated during setup and pasted into a satellite that
 * no longer exists is a live credential nobody is tracking. Clearing it is the
 * only way to be sure of that from here.
 *
 * IT IS SAFE ONLY BECAUSE THE GATE WAS INVERTED IN THE SAME RELEASE. Until
 * 3.28.0 an empty key meant the feed was OPEN, so this exact write would have
 * published the events feed to the world. sfaf_rest_events_permission() now
 * refuses an empty key, so clearing it closes the feed rather than opening it.
 * The two changes are one change and must not be separated.
 *
 * ONCE, AND ONLY ONCE. Keyed on its own option, so an administrator who
 * deliberately generates a new key later keeps it: this runs on the upgrade to
 * 3.28.0 and never again.
 */
function sfaf_retire_satellite_key() {
    if ( get_option( 'sfaf_satellite_key_retired' ) ) {
        return;
    }
    // Recorded before the clear, so an interrupted run cannot repeat it.
    update_option( 'sfaf_satellite_key_retired', '1', false );

    if ( '' !== (string) SFAF_Credentials::get( 'multisite_api_key' ) ) {
        SFAF_Credentials::set( 'multisite_api_key', '' );
    }
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
 * Register REST API routes.
 *
 * ONE ROUTE HERE. /events is the DORMANT satellite feed, see the note on
 * SFAF_Sync. The embed has its own route and registers it itself, in
 * SFAF_Embed::REST_ROUTE, so nothing on this line serves a public page.
 *
 * THE /rsvp ROUTE WAS REMOVED IN 3.28.0, and it is worth saying why rather than
 * simply not being here. It was a POST with permission_callback =>
 * '__return_true', which is an unauthenticated public write, and nothing had
 * ever called it: it was built so a satellite site could post registrations
 * back. It had also been BROKEN since 3.26.0, because it passed 'name' where
 * submit() requires 'first_name', so every call it ever received would have
 * returned "First name and email are required". That is the proof nobody called
 * it, and it is not the reason it went.
 *
 * It went because it is the fourth thing found behind the wrong gate on this
 * project, after the ungated RSVP screen, the dashboard leaking registrant
 * names, and the uc_export_rsvps endpoint. A public write path that nothing
 * uses is not made safe by being broken; it is made safe by not existing. The
 * registration path that remains is admin-ajax uc_submit_rsvp, which checks a
 * nonce.
 */
function sfaf_register_rest_routes() {
    register_rest_route( 'sfaf-calendar/v1', '/events', array(
        'methods'             => 'GET',
        'callback'            => 'sfaf_rest_get_events',
        'permission_callback' => 'sfaf_rest_events_permission',
    ) );
}
add_action( 'rest_api_init', 'sfaf_register_rest_routes' );

/**
 * Permission for the satellite events feed.
 *
 * NO KEY NOW MEANS CLOSED. THIS REVERSES THE ORIGINAL DEFAULT, DELIBERATELY.
 *
 * It used to read: no key configured, feed open, "so it works out of the box".
 * That was a reasonable default for a feature somebody was setting up. It is
 * the wrong default for a feature nobody is using, and it is exactly backwards
 * from the reason the key was cleared in 3.28.0: an empty key was the state
 * that made the feed answer EVERYONE rather than nobody, so clearing the key to
 * retire an old credential would have thrown the door open instead of shutting
 * it.
 *
 * Dormant has to mean nothing answers. So the two states are now:
 *
 *   no key stored     403. The feature is off. This is the shipped state.
 *   a key stored      the X-SFAF-API-Key header must match it.
 *
 * TURNING IT BACK ON IS STILL ONE ACTION: generate a key on Events > Settings >
 * Multisite and paste it into each satellite, which is what the setup was
 * always going to be. Nothing else about the feed changed, and SFAF_Sync is
 * untouched. See the header note there.
 */
function sfaf_rest_events_permission( $request ) {
    $key = SFAF_Credentials::get( 'multisite_api_key' );
    if ( '' === $key ) {
        return new WP_Error(
            'sfaf_feed_off',
            'The events feed is switched off. An administrator can turn it on by generating a key under Events, Settings, Multisite.',
            array( 'status' => 403 )
        );
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

    /*
     * PRIVATE EVENTS DO NOT LEAVE THIS SITE, even though this feed is dormant
     * and gated on a key. A satellite renders what it is given on a public
     * page, so an event that reached one would be findable on a site this
     * plugin does not control and cannot fix. The key says who may read the
     * feed; it says nothing about where the answer ends up.
     */
    SFAF_Privacy::exclude( $args );

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
 * Helper: shared per-request store for confirmed RSVP counts.
 * Returned by reference so the batch primer and the single-event getter share it.
 */
function &sfaf_rsvp_count_store() {
    static $store = array();
    return $store;
}

/**
 * Forget one event's cached count.
 *
 * The store exists so a list screen does not run one COUNT per row, and it
 * lives for one request. That is fine for reading, and wrong the moment the
 * same request CHANGES the number: a cancellation reads the count, releases
 * the place and then reports capacity from the copy it took beforehand. One
 * caller, cancel_rsvp(), and it is the only place a count moves down.
 */
function sfaf_clear_rsvp_count_cache( $event_id ) {
    $store =& sfaf_rsvp_count_store();
    unset( $store[ (int) $event_id ] );
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
