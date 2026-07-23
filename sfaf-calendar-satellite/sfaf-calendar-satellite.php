<?php
/**
 * Plugin Name: SFAF Calendar Satellite
 * Plugin URI: https://marketingmarksolutions.com
 * Description: Pulls events from a main SFAF Calendar site via REST and displays them locally with the same calendar UI, RSVP, donate, share, add-to-calendar, reminders, FAQ, single template, and SEO. Read-only mirror — never sends data back.
 * Version: 1.0.9
 * Author: Marketing Mark Solutions
 * Author URI: https://marketingmarksolutions.com
 * License: GPL v2 or later
 * Text Domain: sfaf-calendar-satellite
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/*
 * Safety: the satellite shares class/function names with the main SFAF Calendar
 * plugin by design (so synced events render identically). Running both on the
 * same site would redeclare them. If the main plugin is already loaded, bail
 * with a notice instead of fataling. Install the satellite on satellite sites only.
 */
if ( defined( 'SFAF_VERSION' ) ) {
    add_action( 'admin_notices', function () {
        echo '<div class="notice notice-error"><p><strong>SFAF Calendar Satellite</strong> can\'t run alongside the main <strong>SFAF Calendar</strong> plugin on the same site. Deactivate one of them.</p></div>';
    } );
    return;
}

define( 'SFAF_VERSION', '1.0.9' );
define( 'SFAF_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SFAF_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Shared frontend code (copied from the main plugin) + satellite-only pieces.
require_once SFAF_PLUGIN_DIR . 'includes/sfaf-template-functions.php';
require_once SFAF_PLUGIN_DIR . 'includes/class-sfaf-shortcodes.php';
require_once SFAF_PLUGIN_DIR . 'includes/class-sfaf-rsvp.php';
require_once SFAF_PLUGIN_DIR . 'includes/class-sfaf-seo.php';
require_once SFAF_PLUGIN_DIR . 'includes/class-sfaf-sat-sync.php';
require_once SFAF_PLUGIN_DIR . 'includes/class-sfaf-sat-admin.php';

/**
 * Register the read-only event post type + taxonomies (no admin create/edit UI).
 */
function sfaf_sat_register_post_types() {
    register_post_type( 'uc_event', array(
        'labels'       => array( 'name' => 'Events', 'singular_name' => 'Event' ),
        'public'       => true,
        'show_ui'      => false,
        'show_in_menu' => false,
        'has_archive'  => true,
        'rewrite'      => array( 'slug' => 'events' ),
        'supports'     => array( 'title', 'editor', 'thumbnail', 'excerpt' ),
        'show_in_rest' => false,
    ) );

    $tax = array(
        'uc_event_category' => array( 'hierarchical' => true,  'slug' => 'event-category' ),
        'uc_organizer'      => array( 'hierarchical' => false, 'slug' => 'event-organizer' ),
        'uc_venue'          => array( 'hierarchical' => false, 'slug' => 'event-venue' ),
    );
    foreach ( $tax as $name => $args ) {
        register_taxonomy( $name, 'uc_event', array(
            'hierarchical' => $args['hierarchical'],
            'public'       => true,
            'show_ui'      => false,
            'rewrite'      => array( 'slug' => $args['slug'] ),
            'show_in_rest' => false,
        ) );
    }
}

/**
 * Initialize the satellite.
 */
function sfaf_sat_init() {
    sfaf_sat_register_post_types();

    $shortcodes = new SFAF_Shortcodes();
    $shortcodes->register();

    $rsvp = new SFAF_RSVP();
    $rsvp->register();

    $seo = new SFAF_SEO();
    $seo->register();

    $sync = new SFAF_Sat_Sync();
    $sync->register();

    if ( is_admin() ) {
        $admin = new SFAF_Sat_Admin();
        $admin->register();
    }
}
add_action( 'init', 'sfaf_sat_init' );

/**
 * Frontend assets (same CSS/JS as the main site so events render identically).
 */
function sfaf_sat_enqueue_frontend_assets() {
    wp_enqueue_style( 'sfaf-calendar-public', SFAF_PLUGIN_URL . 'public/css/calendar.css', array(), SFAF_VERSION );
    wp_enqueue_script( 'sfaf-calendar-public', SFAF_PLUGIN_URL . 'public/js/calendar.js', array( 'jquery' ), SFAF_VERSION, true );
    wp_localize_script( 'sfaf-calendar-public', 'ucData', array(
        'ajaxUrl' => admin_url( 'admin-ajax.php' ),
        'nonce'   => wp_create_nonce( 'uc_nonce' ),
    ) );
}
add_action( 'wp_enqueue_scripts', 'sfaf_sat_enqueue_frontend_assets' );

/**
 * Load the bundled single event template unless the theme overrides it.
 */
function sfaf_sat_template_include( $template ) {
    if ( is_singular( 'uc_event' ) ) {
        $theme = locate_template( array( 'single-uc_event.php' ) );
        if ( $theme ) {
            return $theme;
        }
        $plugin = SFAF_PLUGIN_DIR . 'templates/single-uc_event.php';
        if ( file_exists( $plugin ) ) {
            return $plugin;
        }
    }
    return $template;
}
add_filter( 'template_include', 'sfaf_sat_template_include' );

/**
 * .ics download for a single event: /?uc_ics=ID
 */
function sfaf_sat_output_ics() {
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
    $lines[] = 'LOCATION:' . sfaf_ics_escape( get_post_meta( $post_id, '_uc_location', true ) );
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
add_action( 'template_redirect', 'sfaf_sat_output_ics' );

/**
 * Helper: shared per-request store for confirmed RSVP counts.
 */
function &sfaf_rsvp_count_store() {
    static $store = array();
    return $store;
}

/**
 * Helper: prime confirmed RSVP counts for many events in one query.
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
 * Helper: confirmed RSVP count for an event (local table). Used by shared code.
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

/**
 * Activation: RSVP table + register types + schedule sync + flush rewrites.
 */
function sfaf_sat_activate() {
    global $wpdb;
    $table   = $wpdb->prefix . 'uc_rsvps';
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        event_id bigint(20) unsigned NOT NULL,
        name varchar(200) NOT NULL,
        email varchar(200) NOT NULL,
        phone varchar(50) DEFAULT '',
        status varchar(20) DEFAULT 'confirmed',
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY event_id (event_id),
        KEY email (email)
    ) $charset;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );

    sfaf_sat_register_post_types();
    SFAF_Sat_Sync::ensure_cron_static();
    flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'sfaf_sat_activate' );

/**
 * Deactivation: clear the sync cron + flush rewrites.
 */
function sfaf_sat_deactivate() {
    SFAF_Sat_Sync::clear_cron();
    flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'sfaf_sat_deactivate' );
