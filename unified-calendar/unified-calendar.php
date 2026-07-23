<?php
/**
 * Plugin Name: Unified Calendar
 * Plugin URI: https://marketingmarksolutions.com
 * Description: A modern, multi-site event calendar with RSVP tracking and integrations for GoFundMe Pro, Pardot/Salesforce, Google Calendar, and more.
 * Version: 1.0.0
 * Author: Marketing Mark Solutions
 * Author URI: https://marketingmarksolutions.com
 * License: GPL v2 or later
 * Text Domain: unified-calendar
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'UC_VERSION', '1.0.0' );
define( 'UC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'UC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Core includes
require_once UC_PLUGIN_DIR . 'includes/class-uc-post-types.php';
require_once UC_PLUGIN_DIR . 'includes/class-uc-shortcodes.php';
require_once UC_PLUGIN_DIR . 'includes/class-uc-rsvp.php';
require_once UC_PLUGIN_DIR . 'admin/class-uc-admin.php';

/**
 * Initialize the plugin
 */
function uc_init() {
    $post_types = new UC_Post_Types();
    $post_types->register();

    $shortcodes = new UC_Shortcodes();
    $shortcodes->register();

    $rsvp = new UC_RSVP();
    $rsvp->register();

    if ( is_admin() ) {
        $admin = new UC_Admin();
        $admin->register();
    }
}
add_action( 'init', 'uc_init' );

/**
 * Enqueue frontend styles and scripts
 */
function uc_enqueue_frontend_assets() {
    wp_enqueue_style(
        'unified-calendar-public',
        UC_PLUGIN_URL . 'public/css/calendar.css',
        array(),
        UC_VERSION
    );
    wp_enqueue_script(
        'unified-calendar-public',
        UC_PLUGIN_URL . 'public/js/calendar.js',
        array( 'jquery' ),
        UC_VERSION,
        true
    );
    wp_localize_script( 'unified-calendar-public', 'ucData', array(
        'ajaxUrl' => admin_url( 'admin-ajax.php' ),
        'nonce'   => wp_create_nonce( 'uc_nonce' ),
        'restUrl' => rest_url( 'unified-calendar/v1/' ),
    ) );
}
add_action( 'wp_enqueue_scripts', 'uc_enqueue_frontend_assets' );

/**
 * Enqueue admin styles and scripts
 */
function uc_enqueue_admin_assets( $hook ) {
    $screen = get_current_screen();
    if ( strpos( $hook, 'unified-calendar' ) !== false || 
         ( $screen && $screen->post_type === 'uc_event' ) ) {
        wp_enqueue_style(
            'unified-calendar-admin',
            UC_PLUGIN_URL . 'admin/css/admin.css',
            array(),
            UC_VERSION
        );
        wp_enqueue_script(
            'unified-calendar-admin',
            UC_PLUGIN_URL . 'admin/js/admin.js',
            array( 'jquery' ),
            UC_VERSION,
            true
        );
    }
}
add_action( 'admin_enqueue_scripts', 'uc_enqueue_admin_assets' );

/**
 * Activation hook - create RSVP table
 */
function uc_activate() {
    global $wpdb;
    $table = $wpdb->prefix . 'uc_rsvps';
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

    // Flush rewrite rules
    $post_types = new UC_Post_Types();
    $post_types->register();
    flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'uc_activate' );

/**
 * Deactivation hook
 */
function uc_deactivate() {
    flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'uc_deactivate' );

/**
 * Register REST API routes
 */
function uc_register_rest_routes() {
    register_rest_route( 'unified-calendar/v1', '/events', array(
        'methods'             => 'GET',
        'callback'            => 'uc_rest_get_events',
        'permission_callback' => '__return_true',
    ) );
    register_rest_route( 'unified-calendar/v1', '/rsvp', array(
        'methods'             => 'POST',
        'callback'            => 'uc_rest_submit_rsvp',
        'permission_callback' => '__return_true',
    ) );
}
add_action( 'rest_api_init', 'uc_register_rest_routes' );

/**
 * REST: Get events
 */
function uc_rest_get_events( $request ) {
    $args = array(
        'post_type'      => 'uc_event',
        'posts_per_page' => $request->get_param( 'per_page' ) ?: 20,
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

    foreach ( $query->posts as $post ) {
        $events[] = array(
            'id'          => $post->ID,
            'title'       => $post->post_title,
            'description' => $post->post_excerpt ?: wp_trim_words( $post->post_content, 30 ),
            'date'        => get_post_meta( $post->ID, '_uc_event_date', true ),
            'start_time'  => get_post_meta( $post->ID, '_uc_start_time', true ),
            'end_time'    => get_post_meta( $post->ID, '_uc_end_time', true ),
            'location'    => get_post_meta( $post->ID, '_uc_location', true ),
            'recurrence'  => get_post_meta( $post->ID, '_uc_recurrence', true ),
            'capacity'    => get_post_meta( $post->ID, '_uc_capacity', true ),
            'rsvp_count'  => uc_get_rsvp_count( $post->ID ),
            'categories'  => wp_get_post_terms( $post->ID, 'uc_event_category', array( 'fields' => 'names' ) ),
            'organizer'   => wp_get_post_terms( $post->ID, 'uc_organizer', array( 'fields' => 'names' ) ),
            'permalink'   => get_permalink( $post->ID ),
        );
    }

    return new WP_REST_Response( array(
        'events' => $events,
        'total'  => $query->found_posts,
    ), 200 );
}

/**
 * REST: Submit RSVP
 */
function uc_rest_submit_rsvp( $request ) {
    $rsvp = new UC_RSVP();
    $result = $rsvp->submit( array(
        'event_id' => intval( $request->get_param( 'event_id' ) ),
        'name'     => sanitize_text_field( $request->get_param( 'name' ) ),
        'email'    => sanitize_email( $request->get_param( 'email' ) ),
        'phone'    => sanitize_text_field( $request->get_param( 'phone' ) ),
    ) );
    return new WP_REST_Response( $result, $result['success'] ? 200 : 400 );
}

/**
 * Helper: Get RSVP count for event
 */
function uc_get_rsvp_count( $event_id ) {
    global $wpdb;
    $table = $wpdb->prefix . 'uc_rsvps';
    return (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM $table WHERE event_id = %d AND status = 'confirmed'",
        $event_id
    ) );
}
