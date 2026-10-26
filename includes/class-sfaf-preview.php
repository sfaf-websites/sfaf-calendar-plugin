<?php
/**
 * The event page before it is public (3.122.0).
 *
 * Edit event's "View event page" opens a published event's page and Preview
 * opens a draft's or a pending event's. A scheduled event's View event page is
 * its public address, which WordPress answers with a 404 until it goes live.
 * This class lets the people who can edit an event see that page early, marked
 * as a preview, and nobody else.
 *
 * HOW. WP_Query finds the event, then drops it for anybody without
 * WordPress's own edit capability, which calendar staff do not have: their
 * access is SFAF_Portal::user_can_edit_event(). posts_results holds the event
 * before the drop and the_posts puts it back when that gate says yes. Nothing
 * is widened for a visitor: signed out, or signed in without the event, the
 * page is the 404 it always was.
 *
 * A DRAFT HAS NO ADDRESS YET, so its preview is ?uc_preview=ID on the home
 * URL, which request() turns into the query for that one event. Not ?p=, which
 * redirect_canonical() would rewrite.
 *
 * Registration on a preview is refused by the RSVP handler, which takes
 * registrations for published events only.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SFAF_Preview {

    const QUERY_VAR = 'uc_preview';

    /** @var WP_Post|null The main query's event, held before WP_Query drops it. */
    private static $held = null;

    public static function register() {
        add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
        add_filter( 'request', array( __CLASS__, 'request' ) );
        add_filter( 'posts_results', array( __CLASS__, 'hold' ), 10, 2 );
        add_filter( 'the_posts', array( __CLASS__, 'restore' ), 10, 2 );
        add_filter( 'redirect_canonical', array( __CLASS__, 'no_canonical' ) );
        add_filter( 'wp_robots', array( __CLASS__, 'robots' ) );
        add_action( 'template_redirect', array( __CLASS__, 'no_cache' ), 0 );
    }

    /** The statuses a preview is for. */
    public static function statuses() {
        return array( 'draft', 'pending', 'future' );
    }

    /**
     * What Edit event's button opens, or array() before the first save.
     *
     * 'mode' is 'view' for a published or scheduled event, whose link is its
     * public address (a private event's is its private link), and 'preview'
     * for a draft or a pending event.
     *
     * @param int $event_id
     * @return array{mode:string,url:string}|array
     */
    public static function link( $event_id ) {
        $post = get_post( (int) $event_id );
        if ( ! $post || 'uc_event' !== $post->post_type ) {
            return array();
        }
        if ( 'publish' === $post->post_status ) {
            return array( 'mode' => 'view', 'url' => (string) get_permalink( $post ) );
        }
        if ( 'future' === $post->post_status ) {
            return array( 'mode' => 'view', 'url' => self::address_once_live( $post ) );
        }
        if ( in_array( $post->post_status, array( 'draft', 'pending' ), true ) ) {
            return array( 'mode' => 'preview', 'url' => add_query_arg( self::QUERY_VAR, (int) $post->ID, home_url( '/' ) ) );
        }
        return array();
    }

    /**
     * A scheduled event's address as it will be once live. get_permalink()
     * gives a scheduled post the ?p= form, so the post is asked as published.
     */
    private static function address_once_live( $post ) {
        $live = clone $post;
        $live->post_status = 'publish';
        return (string) get_permalink( $live );
    }

    public static function query_vars( $vars ) {
        $vars[] = self::QUERY_VAR;
        return $vars;
    }

    /** ?uc_preview=ID asks for that one event. */
    public static function request( $vars ) {
        if ( empty( $vars[ self::QUERY_VAR ] ) ) {
            return $vars;
        }
        $id = absint( $vars[ self::QUERY_VAR ] );
        if ( ! $id ) {
            return $vars;
        }
        return array( 'post_type' => 'uc_event', 'p' => $id, self::QUERY_VAR => $id );
    }

    /** The main query's single event, before the status check can drop it. */
    public static function hold( $posts, $q ) {
        if ( is_admin() || ! $q->is_main_query() || ! $q->is_single ) {
            return $posts;
        }
        self::$held = ( is_array( $posts ) && 1 === count( $posts ) && $posts[0] instanceof WP_Post && 'uc_event' === $posts[0]->post_type )
            ? $posts[0]
            : null;
        return $posts;
    }

    /** The event put back for somebody who can edit it. */
    public static function restore( $posts, $q ) {
        if ( is_admin() || ! $q->is_main_query() || ! empty( $posts ) || ! self::$held ) {
            return $posts;
        }
        $post       = self::$held;
        self::$held = null;
        return self::can_preview( $post ) ? array( $post ) : $posts;
    }

    /**
     * Signed in, on the calendar, and able to edit this event, which is not
     * yet public.
     *
     * @param WP_Post $post
     * @return bool
     */
    public static function can_preview( $post ) {
        if ( ! $post || 'uc_event' !== $post->post_type || ! in_array( $post->post_status, self::statuses(), true ) ) {
            return false;
        }
        $uid = get_current_user_id();
        if ( ! $uid || ! class_exists( 'SFAF_Portal' ) || ! SFAF_Portal::get_role( $uid ) ) {
            return false;
        }
        return SFAF_Portal::user_can_edit_event( $uid, $post );
    }

    /** Whether this request is an event page shown before it is public. */
    public static function showing() {
        if ( ! is_singular( 'uc_event' ) ) {
            return false;
        }
        $post = get_queried_object();
        return ( $post instanceof WP_Post ) && in_array( $post->post_status, self::statuses(), true );
    }

    public static function no_canonical( $redirect ) {
        return self::showing() ? false : $redirect;
    }

    public static function robots( $robots ) {
        if ( ! self::showing() ) {
            return $robots;
        }
        $robots['noindex']  = true;
        $robots['nofollow'] = true;
        unset( $robots['index'], $robots['follow'], $robots['max-snippet'], $robots['max-image-preview'], $robots['max-video-preview'] );
        return $robots;
    }

    /** Never cached: a page cache would hand a staff preview to the next visitor. */
    public static function no_cache() {
        if ( ! self::showing() ) {
            return;
        }
        nocache_headers();
        if ( ! defined( 'DONOTCACHEPAGE' ) ) {
            define( 'DONOTCACHEPAGE', true );
        }
        if ( ! headers_sent() ) {
            header( 'X-LiteSpeed-Cache-Control: no-cache' );
        }
    }

    /**
     * The band at the top of a preview, or ''.
     *
     * Its style travels with it, because calendar.css is the embed's
     * stylesheet too and this release does not change the embed. The band is
     * the cancelled notice's shape in the accent: --uc-teal-text on --uc-band
     * is 4.94:1, --uc-text on it 15.59:1.
     *
     * @param int $post_id
     * @return string
     */
    public static function banner( $post_id ) {
        $post = get_post( (int) $post_id );
        if ( ! $post || ! in_array( $post->post_status, self::statuses(), true ) ) {
            return '';
        }
        if ( 'future' === $post->post_status ) {
            $note = 'Only signed-in staff can see this page until it goes live on ' . sfaf_ap_datetime( $post->post_date, 'full' ) . '.';
        } else {
            $note = 'Only signed-in staff can see this page. Visitors will see it once the event is published.';
        }
        $css = '.uc-single-preview{margin:0 0 24px;padding:16px 20px;border:1px solid var(--uc-teal-text,#0E7680);border-radius:12px;background:var(--uc-band,#E8F9FA)}'
            . '.uc-single-preview .uc-preview-head{margin:0;font-size:18px;font-weight:800;line-height:1.3;color:var(--uc-teal-text,#0E7680)}'
            . '.uc-single-preview .uc-preview-note{margin:8px 0 0;font-size:14px;line-height:1.6;color:var(--uc-text,#1A1D21);max-width:70ch}';
        return '<style id="uc-preview-css">' . $css . '</style>'
            . '<div class="uc-single-preview" role="status" data-uc-preview>'
            . '<p class="uc-preview-head">Preview</p>'
            . '<p class="uc-preview-note">' . esc_html( $note ) . '</p>'
            . '</div>';
    }
}
