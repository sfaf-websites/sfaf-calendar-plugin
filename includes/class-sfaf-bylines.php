<?php
/**
 * NO AUTHOR ON ANY PUBLIC EVENT SURFACE (3.106.1).
 *
 * An event's post_author is the person who made it in caladmin, and on this
 * site that is a staff account whose login can be an email address. The theme
 * prints it on every event it lists: a byline linked to
 * /collections/author/{login}. Nobody reading the calendar needs it, and the
 * link hands out a login.
 *
 * FOUR PLACES IT CAN REACH A VISITOR, AND ONE MEASURE EACH:
 *
 *   the theme's byline   stripped from the page, inside an event's <article>
 *                        only, because the theme offers no filter for it
 *   author archives      a user with a calendar role redirects to the
 *                        calendar home, before WordPress's canonical redirect
 *                        can spell out the address of a numeric ?author=
 *   oEmbed and feeds     no author name or address on an event
 *   the public REST API  users with a calendar role are not listed to a
 *                        visitor who is not signed in, nor fetched by id
 *
 * THE BYLINE IS STRIPPED BY MARKUP, AND THAT IS A KNOWN WEAKNESS. The theme
 * (wp-content/themes/sfaf) is not in this repository and prints the byline
 * itself, as <div class="sfaf-entry-athors"> holding <ul class=
 * "sfaf-authors-list">. A theme update that renames either class leaves a
 * byline the first two patterns miss; the third, any link to one of this
 * site's author archives inside an event's article, still takes the name and
 * the login. The
 * check that a release did not miss is .claude/byline-live.php --live, run
 * against the site. PROJECT.md 3 records it.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Bylines {

    public function register() {
        // Before redirect_canonical() at 10, which would turn ?author=55 into
        // the address with the login in it.
        add_action( 'template_redirect', array( __CLASS__, 'redirect_author_archive' ), 1 );
        // After every handler that renders its own page and exits.
        add_action( 'template_redirect', array( __CLASS__, 'start_buffer' ), 99 );
        add_filter( 'oembed_response_data', array( __CLASS__, 'oembed' ), 10, 2 );
        add_filter( 'the_author', array( __CLASS__, 'event_author' ) );
        add_filter( 'rest_user_query', array( __CLASS__, 'rest_user_query' ), 10, 2 );
        add_filter( 'rest_request_before_callbacks', array( __CLASS__, 'rest_single_user' ), 10, 3 );
    }

    /* ---------------------------------------------------------------------
     * The theme's byline
     * ------------------------------------------------------------------- */

    /** Every front-end page, so a listing nobody named is covered too. */
    public static function start_buffer() {
        if ( is_admin() || is_feed() || is_robots() || is_trackback() || is_embed() ) {
            return;
        }
        ob_start( array( __CLASS__, 'strip' ) );
    }

    /**
     * The page with every event's byline taken out. Any other post's byline
     * is left alone: a date archive or a search lists articles too.
     *
     * @param string $html
     * @return string
     */
    public static function strip( $html ) {
        if ( ! is_string( $html ) || false === strpos( $html, 'type-uc_event' ) ) {
            return $html;
        }
        $out = preg_replace_callback(
            '#<article\b[^>]*\bclass="[^"]*\btype-uc_event\b[^"]*"[^>]*>.*?</article>#is',
            function ( $m ) { return SFAF_Bylines::strip_article( $m[0] ); },
            $html
        );
        return is_string( $out ) ? $out : $html;
    }

    /**
     * One event's article without its byline: the theme's footer; any author
     * list left, whatever wraps it; and any link to one of this site's author
     * archives, text and all. The rule above the footer stays: on a listing it
     * is the only line between one event and the next.
     *
     * @param string $a
     * @return string
     */
    public static function strip_article( $a ) {
        $host  = preg_quote( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ), '#' );
        $steps = array(
            // The footer, only while it holds no <div>, so a nested one can
            // never leave a stray closing tag behind.
            '#<div\b[^>]*\bclass="[^"]*\bsfaf-entry-athors\b[^"]*"[^>]*>(?:(?!<div\b).)*?</div>#is',
            '#<ul\b[^>]*\bclass="[^"]*\bsfaf-authors-list\b[^"]*"[^>]*>.*?</ul>#is',
            // This site's author archives only, relative or absolute.
            '#<a\b[^>]*\bhref=["\'](?:https?://' . $host . ')?/[^"\']*author/[^"\']*["\'][^>]*>.*?</a>#is',
        );
        foreach ( $steps as $re ) {
            $next = preg_replace( $re, '', $a );
            if ( is_string( $next ) ) {
                $a = $next;
            }
        }
        return $a;
    }

    /* ---------------------------------------------------------------------
     * Author archives
     * ------------------------------------------------------------------- */

    /** Whether this user holds a calendar role, administrators included. */
    public static function has_calendar_role( $user_id ) {
        return (int) $user_id > 0 && '' !== SFAF_Portal::get_role( (int) $user_id );
    }

    public static function redirect_author_archive() {
        if ( ! is_author() ) {
            return;
        }
        $obj = get_queried_object();
        $id  = ( $obj instanceof WP_User ) ? (int) $obj->ID : (int) get_query_var( 'author' );
        if ( ! self::has_calendar_role( $id ) ) {
            return;
        }
        wp_redirect( self::calendar_home(), 302 );
        exit;
    }

    /** The calendar home in Settings, else the events archive. */
    public static function calendar_home() {
        $to = sfaf_calendar_home_url();
        if ( '' === $to ) {
            $to = (string) get_post_type_archive_link( 'uc_event' );
        }
        return '' !== $to ? $to : home_url( '/' );
    }

    /* ---------------------------------------------------------------------
     * oEmbed, feeds
     * ------------------------------------------------------------------- */

    /** An event's oEmbed answer carries no author. */
    public static function oembed( $data, $post ) {
        if ( $post && 'uc_event' === get_post_type( $post ) ) {
            unset( $data['author_name'], $data['author_url'] );
        }
        return $data;
    }

    /**
     * An event's author, wherever a template or a feed asks for it on the
     * public side, is SFAF.
     */
    public static function event_author( $name ) {
        if ( is_admin() ) {
            return $name;
        }
        $post = get_post();
        return ( $post && 'uc_event' === $post->post_type ) ? get_bloginfo( 'name' ) : $name;
    }

    /* ---------------------------------------------------------------------
     * The public REST API
     * ------------------------------------------------------------------- */

    /** @return int[] Everybody with a calendar role, administrators included. */
    public static function calendar_user_ids() {
        static $ids = null;
        if ( null === $ids ) {
            $ids = array_merge(
                get_users( array( 'meta_key' => '_uc_calendar_role', 'meta_compare' => 'EXISTS', 'fields' => 'ID', 'number' => 500 ) ),
                get_users( array( 'capability' => 'manage_options', 'fields' => 'ID', 'number' => 500 ) )
            );
            $ids = array_values( array_filter( array_unique( array_map( 'intval', $ids ) ), array( __CLASS__, 'has_calendar_role' ) ) );
        }
        return $ids;
    }

    /** /wp/v2/users, to a visitor not signed in, lists none of them. */
    public static function rest_user_query( $args, $request ) {
        if ( is_user_logged_in() ) {
            return $args;
        }
        $hide = self::calendar_user_ids();
        if ( $hide ) {
            $args['exclude'] = array_merge( isset( $args['exclude'] ) ? (array) $args['exclude'] : array(), $hide );
        }
        return $args;
    }

    /** /wp/v2/users/{id}, to a visitor not signed in, does not find one. */
    public static function rest_single_user( $response, $handler, $request ) {
        if ( is_user_logged_in() || ! is_object( $request ) || 'GET' !== $request->get_method() ) {
            return $response;
        }
        if ( preg_match( '#^/wp/v2/users/(\d+)$#', (string) $request->get_route(), $m ) && self::has_calendar_role( (int) $m[1] ) ) {
            return new WP_Error( 'rest_user_invalid_id', 'Invalid user ID.', array( 'status' => 404 ) );
        }
        return $response;
    }
}
