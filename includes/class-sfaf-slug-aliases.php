<?php
/**
 * A slug that changes keeps working (3.110.3).
 *
 * An organizer, a category or a series is addressed by its slug in public: the
 * archive at /event-organizer/<slug>/, the filter links (uc_cat, uc_org,
 * uc_group), a shortcode's organizer="" and an embed's data-organizer on
 * somebody else's site. Changing the slug would break every one of those, and
 * the embed ones are on pages nobody here can edit.
 *
 * SO A CHANGED SLUG LEAVES ITS OLD ONE BEHIND, pointing at the term by id:
 *
 *     sfaf_slug_aliases = array( taxonomy => array( old slug => term id ) )
 *
 * By id, so a slug changed twice resolves the oldest to the newest, and a
 * live slug always wins over an alias of the same spelling. rename() is the
 * only writer; the Organizers, Series and Categories screens call it.
 *
 * WHAT THE OLD SLUG DOES. A public page asked for it is sent to the new
 * address with a 301 (redirect()); a shortcode or an embed that names it gets
 * the term's events, quietly, through resolve(). Nothing is stored on any
 * event: an event holds its terms by id and never knew the slug.
 *
 * @package SFAF_Calendar
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SFAF_Slug_Aliases {

    const OPTION = 'sfaf_slug_aliases';

    /** The taxonomies a slug can be changed on, and their public filter key. */
    const PARAMS = array(
        'uc_event_category' => 'uc_cat',
        'uc_organizer'      => 'uc_org',
        'uc_series'         => 'uc_group',
    );

    public static function register() {
        add_action( 'template_redirect', array( __CLASS__, 'redirect' ), 1 );
        // After every taxonomy is registered, so the term can be found.
        add_action( 'init', array( __CLASS__, 'rename_magnet_once' ), 99 );
    }

    /** @return array<string,array<string,int>> */
    public static function all() {
        $v = get_option( self::OPTION, array() );
        return is_array( $v ) ? $v : array();
    }

    /**
     * The live slug for a slug somebody asked for: itself when a term has it,
     * the term's current slug when it is an old one, itself otherwise.
     *
     * @param string $taxonomy
     * @param string $slug
     * @return string
     */
    public static function resolve( $taxonomy, $slug ) {
        $slug = (string) $slug;
        if ( '' === $slug || ! isset( self::PARAMS[ $taxonomy ] ) ) {
            return $slug;
        }
        $all = self::all();
        if ( empty( $all[ $taxonomy ][ $slug ] ) ) {
            return $slug;
        }
        if ( get_term_by( 'slug', $slug, $taxonomy ) ) {
            return $slug;
        }
        $term = get_term( (int) $all[ $taxonomy ][ $slug ], $taxonomy );
        return ( $term && ! is_wp_error( $term ) ) ? (string) $term->slug : $slug;
    }

    /**
     * resolve() over a comma-separated list, keeping its order, once each.
     *
     * @param string $taxonomy
     * @param string $csv
     * @return string
     */
    public static function resolve_list( $taxonomy, $csv ) {
        if ( '' === (string) $csv ) {
            return '';
        }
        $out = array();
        foreach ( explode( ',', (string) $csv ) as $one ) {
            $out[] = self::resolve( $taxonomy, trim( $one ) );
        }
        return implode( ',', array_values( array_unique( array_filter( $out ) ) ) );
    }

    /**
     * Change a term's slug, keeping the old one as an alias.
     *
     * Refused, with a sentence, when the new slug is empty or another term in
     * the same taxonomy has it. An alias of the new spelling is dropped, since
     * the term now answers to it itself.
     *
     * @param int    $term_id
     * @param string $taxonomy
     * @param string $wanted   As typed; sanitize_title() makes it a slug.
     * @return true|WP_Error True when nothing needed changing, too.
     */
    public static function rename( $term_id, $taxonomy, $wanted ) {
        $term = get_term( (int) $term_id, $taxonomy );
        if ( ! $term || is_wp_error( $term ) || ! isset( self::PARAMS[ $taxonomy ] ) ) {
            return new WP_Error( 'uc_slug', 'That is not there any more.' );
        }
        $new = sanitize_title( (string) $wanted );
        if ( '' === $new ) {
            return new WP_Error( 'uc_slug', 'Give it a slug: letters, numbers and hyphens.' );
        }
        $old = (string) $term->slug;
        if ( $new === $old ) {
            return true;
        }
        $taken = get_term_by( 'slug', $new, $taxonomy );
        if ( $taken && (int) $taken->term_id !== (int) $term->term_id ) {
            return new WP_Error( 'uc_slug', '"' . $new . '" is already the slug of ' . $taken->name . '.' );
        }
        $done = wp_update_term( (int) $term->term_id, $taxonomy, array( 'slug' => $new ) );
        if ( is_wp_error( $done ) ) {
            return $done;
        }
        $all = self::all();
        $all[ $taxonomy ][ $old ] = (int) $term->term_id;
        unset( $all[ $taxonomy ][ $new ] );
        update_option( self::OPTION, $all, false );
        return true;
    }

    /**
     * A public address carrying an old slug goes to the new one, with a 301.
     *
     * The archive (/event-organizer/magnet/ is a 404 once the term is strut)
     * and the three filter parameters on any page. Nothing happens on an
     * address that carries no alias, which is every address but a handful.
     */
    public static function redirect() {
        if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
            return;
        }
        $all = self::all();
        if ( ! $all ) {
            return;
        }

        /* The archive: WordPress has already looked for the term and missed. */
        if ( is_404() ) {
            foreach ( array_keys( self::PARAMS ) as $tax ) {
                $asked = (string) get_query_var( $tax );
                if ( '' === $asked || empty( $all[ $tax ][ $asked ] ) ) {
                    continue;
                }
                $term = get_term( (int) $all[ $tax ][ $asked ], $tax );
                if ( $term && ! is_wp_error( $term ) ) {
                    $link = get_term_link( $term, $tax );
                    if ( ! is_wp_error( $link ) ) {
                        wp_safe_redirect( $link, 301 );
                        exit;
                    }
                }
            }
        }

        /* The filter parameters, on whatever page carries them. */
        $changed = false;
        $args    = array();
        foreach ( self::PARAMS as $tax => $param ) {
            if ( ! isset( $_GET[ $param ] ) || empty( $all[ $tax ] ) ) {
                continue;
            }
            $raw = wp_unslash( $_GET[ $param ] );
            $list = is_array( $raw ) ? array_map( 'strval', $raw ) : explode( ',', (string) $raw );
            $list = array_map( 'sanitize_title', $list );
            $now  = array();
            foreach ( $list as $one ) {
                $now[] = self::resolve( $tax, $one );
            }
            if ( $now !== $list ) {
                $changed = true;
                $args[ $param ] = is_array( $raw ) ? $now : implode( ',', $now );
            }
        }
        if ( $changed ) {
            $url = remove_query_arg( array_keys( $args ) );
            wp_safe_redirect( add_query_arg( array_map( function ( $v ) { return is_array( $v ) ? $v : rawurlencode( $v ); }, $args ), $url ), 301 );
            exit;
        }
    }

    /**
     * The Strut organizer was seeded as "Magnet" and kept that slug when it was
     * renamed (3.110.3). Once: an organizer whose slug is magnet and whose name
     * is Strut becomes strut, leaving magnet as its alias. Anything else, a
     * Magnet that is not Strut or a strut already taken, is left alone and
     * recorded, so the Organizers screen's list can show it.
     */
    public static function rename_magnet_once() {
        if ( false !== get_option( 'sfaf_slug_magnet_done', false ) ) {
            return;
        }
        $term = get_term_by( 'slug', 'magnet', 'uc_organizer' );
        $said = 'no organizer has the slug magnet';
        if ( $term && ! is_wp_error( $term ) ) {
            if ( 0 !== strcasecmp( trim( $term->name ), 'Strut' ) ) {
                $said = 'the organizer with the slug magnet is named ' . $term->name . ', not Strut';
            } else {
                $r    = self::rename( (int) $term->term_id, 'uc_organizer', 'strut' );
                $said = is_wp_error( $r ) ? $r->get_error_message() : 'renamed';
            }
        }
        update_option( 'sfaf_slug_magnet_done', array( 'said' => $said, 'at' => current_time( 'mysql' ) ), false );
    }

    /**
     * Terms whose slug is not their own name: what an old name looks like.
     * WordPress's own -2 on a duplicate does not count. For the Organizers
     * and Categories screens to list, so a slug left from a retired name is
     * seen and changed there.
     *
     * @param string $taxonomy
     * @return WP_Term[]
     */
    public static function not_their_name( $taxonomy ) {
        $terms = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) );
        $out   = array();
        foreach ( is_array( $terms ) ? $terms : array() as $t ) {
            $want = sanitize_title( $t->name );
            $have = preg_replace( '/-\d+$/', '', (string) $t->slug );
            if ( '' !== $want && $have !== $want && (string) $t->slug !== $want ) {
                $out[] = $t;
            }
        }
        return $out;
    }
}
