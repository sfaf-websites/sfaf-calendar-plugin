<?php
/**
 * THE 3.106.0 MESSAGE CATALOGUE, FOR A TEST THAT BUILDS ITS OWN WORLD.
 *
 *     require_once __DIR__ . '/lang-shim.php';   // after SFAF_Email, before the builders
 *
 * Not a check. The mail builders take their words from SFAF_Messages and ask
 * sfaf_event_language() which language to use. A test that loads the builders
 * by hand, with its own stubbed template functions, loads the real catalogue
 * here and gets the answers an event with no language and no donation link
 * gives: English, and nothing to donate to. Every definition is guarded, so a
 * test that already has the real function keeps it.
 */

if ( ! defined( 'SFAF_DONATE_DEFAULT' ) ) {
    define( 'SFAF_DONATE_DEFAULT', 'https://donate.sfaf.org/campaign/773029/donate' );
}
if ( ! function_exists( 'sfaf_event_language' ) ) {
    function sfaf_event_language( $event_id ) { return 'en'; }
}
if ( ! function_exists( 'sfaf_ap_restate' ) ) {
    function sfaf_ap_restate( $kind, $text, $lang ) { return (string) $text; }
}
if ( ! function_exists( 'sfaf_donate_url' ) ) {
    function sfaf_donate_url( $event_id ) { return ''; }
}
if ( ! function_exists( 'sfaf_ap_datetime' ) ) {
    function sfaf_ap_datetime( $when, $style = 'short_year', $lang = 'en', $zone = false ) { return (string) $when; }
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
    function wp_strip_all_tags( $t ) { return trim( strip_tags( (string) $t ) ); }
}
if ( ! function_exists( 'sanitize_key' ) ) {
    function sanitize_key( $t ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $t ) ); }
}
if ( ! class_exists( 'SFAF_Messages' ) ) {
    require dirname( __DIR__ ) . '/includes/class-sfaf-messages.php';
}
if ( ! function_exists( 'sfaf_ap_zoned' ) ) {
    function sfaf_ap_zoned( $clock, $lang = 'en' ) { return trim( (string) $clock ); }
}
if ( ! function_exists( 'get_option' ) ) {
    function get_option( $n, $d = false ) { return $d; }
}
