<?php
/**
 * Tags: what is included at an event (3.112.0).
 *
 * "Food", "Childcare", "ASL interpretation": a short list a person reads to
 * decide whether an event suits them, and a filter on the public calendar
 * under the label "What's included". A taxonomy beside categories and
 * organizers, on the same terms: several per event, a series default the event
 * inherits when it joins, and a pill on the event page and its card.
 *
 * MANAGED ON THE SERIES & CATEGORIES SCREEN, BY ADMINS. Add, rename, retire.
 * Nothing is seeded: the list is what calendar admins make.
 *
 * RETIRED, NOT DELETED. A tag that is no longer offered stays on the events
 * that carry it, past ones included, so what an event said it included is
 * never rewritten after the fact. Retired tags are left out of every picker
 * and every filter, public or not, and still print on the events they are on.
 * The editor still shows a retired tag an event already holds, ticked, so a
 * save cannot drop it by not offering it (the $offered rule, CLAUDE.md 7).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Tags {

    const TAXONOMY = 'uc_tag';

    /** Term meta: '1' when the tag is retired. */
    const META_RETIRED = '_sfaf_tag_retired';

    /** The series' default tags, as term ids (series term meta). */
    const META_SERIES_DEFAULT = '_sfaf_series_tags';

    public static function register_taxonomy() {
        register_taxonomy( self::TAXONOMY, 'uc_event', array(
            'labels' => array(
                'name'          => 'Tags',
                'singular_name' => 'Tag',
                'add_new_item'  => 'Add New Tag',
                'menu_name'     => 'Tags',
            ),
            'hierarchical' => false,
            'public'       => true,
            'show_ui'      => false,
            'show_in_menu' => false,
            'rewrite'      => array( 'slug' => 'event-tag' ),
            'show_in_rest' => true,
        ) );
    }

    /**
     * Every tag, by name, retired ones included unless asked otherwise.
     *
     * @param bool $with_retired
     * @return WP_Term[]
     */
    public static function all( $with_retired = true ) {
        $terms = get_terms( array(
            'taxonomy'   => self::TAXONOMY,
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ) );
        if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
            return array();
        }
        if ( $with_retired ) {
            return $terms;
        }
        return array_values( array_filter( $terms, function ( $t ) {
            return ! SFAF_Tags::is_retired( $t->term_id );
        } ) );
    }

    /** The tags a picker or a filter may offer: every one not retired. */
    public static function active() {
        return self::all( false );
    }

    public static function get( $term_id ) {
        $term = get_term( (int) $term_id, self::TAXONOMY );
        return ( $term && ! is_wp_error( $term ) ) ? $term : null;
    }

    public static function exists( $term_id ) {
        return null !== self::get( $term_id );
    }

    public static function is_retired( $term_id ) {
        return '1' === (string) get_term_meta( (int) $term_id, self::META_RETIRED, true );
    }

    /**
     * An event's tags, by name.
     *
     * @param int $post_id
     * @return WP_Term[]
     */
    public static function for_event( $post_id ) {
        // From the term cache the listing's query primed (3.112.2): wp_get_post_terms()
        // was a query per card.
        $terms = get_the_terms( (int) $post_id, self::TAXONOMY );
        if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
            return array();
        }
        usort( $terms, function ( $a, $b ) { return strcasecmp( $a->name, $b->name ); } );
        return $terms;
    }

    /** How many events carry a tag, in any editable status. */
    public static function events_using( $term_id ) {
        $q = new WP_Query( array(
            'post_type'      => 'uc_event',
            'post_status'    => SFAF_Series::editable_statuses(),
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'tax_query'      => array(
                array( 'taxonomy' => self::TAXONOMY, 'field' => 'term_id', 'terms' => (int) $term_id ),
            ),
        ) );
        return (int) $q->found_posts;
    }

    /**
     * How many events carry a tag in ANY status, past, draft and trashed
     * included (3.112.1): the number a Delete removes it from.
     */
    public static function events_tagged( $term_id ) {
        $stati = array( 'publish', 'future', 'draft', 'pending', 'private', 'trash' );
        $q = new WP_Query( array(
            'post_type'      => 'uc_event',
            'post_status'    => $stati,
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'tax_query'      => array(
                array( 'taxonomy' => self::TAXONOMY, 'field' => 'term_id', 'terms' => (int) $term_id ),
            ),
        ) );
        return (int) $q->found_posts;
    }

    /**
     * Delete a tag (3.112.1). Admins only, decided by the route.
     *
     * It goes from every event that has it (wp_delete_term() takes the
     * relationships), from every series default, and its old slugs are
     * forgotten, so an address naming it reaches nothing, as an archived one
     * does. The embed cache is retired: the filter and the pills change.
     *
     * @param int $term_id
     * @return bool Whether it was there and is gone.
     */
    public static function delete( $term_id ) {
        $term_id = (int) $term_id;
        if ( ! $term_id || ! self::exists( $term_id ) ) {
            return false;
        }
        $series = get_terms( array( 'taxonomy' => SFAF_Series::TAXONOMY, 'hide_empty' => false ) );
        foreach ( is_array( $series ) ? $series : array() as $s ) {
            $raw = get_term_meta( (int) $s->term_id, self::META_SERIES_DEFAULT, true );
            if ( ! is_array( $raw ) ) {
                continue;
            }
            $ids = array_map( 'intval', $raw );
            if ( ! in_array( $term_id, $ids, true ) ) {
                continue;
            }
            $left = array_values( array_diff( $ids, array( $term_id ) ) );
            if ( $left ) {
                update_term_meta( (int) $s->term_id, self::META_SERIES_DEFAULT, $left );
            } else {
                delete_term_meta( (int) $s->term_id, self::META_SERIES_DEFAULT );
            }
        }
        SFAF_Slug_Aliases::forget( self::TAXONOMY, $term_id );
        $done = wp_delete_term( $term_id, self::TAXONOMY );
        if ( is_wp_error( $done ) || false === $done ) {
            return false;
        }
        if ( class_exists( 'SFAF_Embed' ) ) {
            SFAF_Embed::retire_all();
        }
        return true;
    }

    /**
     * Create or rename a tag. An existing tag of the same name is reused
     * rather than duplicated.
     *
     * @param int    $term_id 0 to create.
     * @param string $name
     * @return int|WP_Error Term id.
     */
    public static function save( $term_id, $name ) {
        $term_id = (int) $term_id;
        $name    = trim( sanitize_text_field( (string) $name ) );
        if ( '' === $name ) {
            return new WP_Error( 'sfaf_tag_no_name', 'Type a name for the tag.' );
        }
        if ( $term_id ) {
            if ( ! self::exists( $term_id ) ) {
                return new WP_Error( 'sfaf_tag_missing', 'That tag no longer exists.' );
            }
            $updated = wp_update_term( $term_id, self::TAXONOMY, array( 'name' => $name ) );
            return is_wp_error( $updated ) ? $updated : $term_id;
        }
        $existing = get_term_by( 'name', $name, self::TAXONOMY );
        if ( $existing && ! is_wp_error( $existing ) ) {
            return (int) $existing->term_id;
        }
        $created = wp_insert_term( $name, self::TAXONOMY );
        return is_wp_error( $created ) ? $created : (int) $created['term_id'];
    }

    /**
     * Retire a tag, or bring it back.
     *
     * @param int  $term_id
     * @param bool $retired
     * @return bool Whether the tag exists.
     */
    public static function set_retired( $term_id, $retired ) {
        if ( ! self::exists( $term_id ) ) {
            return false;
        }
        if ( $retired ) {
            update_term_meta( (int) $term_id, self::META_RETIRED, '1' );
        } else {
            delete_term_meta( (int) $term_id, self::META_RETIRED );
        }
        return true;
    }

    /**
     * Keep only real, offered tag ids; plus the ones the event already holds,
     * so a retired tag survives a save of a form that did not offer it.
     *
     * @param int[] $posted
     * @param int[] $held   The event's tags before the save.
     * @return int[]
     */
    public static function clean_ids( $posted, $held = array() ) {
        $active = array_map( 'intval', wp_list_pluck( self::active(), 'term_id' ) );
        $out    = array();
        foreach ( (array) $posted as $id ) {
            $id = (int) $id;
            if ( $id && ( in_array( $id, $active, true ) || in_array( $id, array_map( 'intval', (array) $held ), true ) ) && ! in_array( $id, $out, true ) ) {
                $out[] = $id;
            }
        }
        return $out;
    }

    /**
     * The "What's included" fieldset both public forms draw (3.112.0): one tick
     * per tag that is not retired. Nothing when there are no tags.
     *
     * @param int[] $chosen
     */
    public static function form_fieldset( $chosen = array() ) {
        $tags = self::active();
        if ( empty( $tags ) ) {
            return;
        }
        $chosen = array_map( 'intval', (array) $chosen );
        ?>
        <fieldset class="uc-field" data-uc-form-tags>
            <legend class="uc-field-label"><?php echo esc_html( SFAF_Messages::label( 'whats_included', 'en' ) ); ?></legend>
            <div class="uc-check-grid">
                <?php foreach ( $tags as $t ) : ?>
                    <label class="uc-check">
                        <input type="checkbox" name="tags[]" value="<?php echo (int) $t->term_id; ?>"
                            <?php checked( in_array( (int) $t->term_id, $chosen, true ) ); ?> />
                        <?php echo esc_html( $t->name ); ?>
                    </label>
                <?php endforeach; ?>
            </div>
        </fieldset>
        <?php
    }

    /**
     * The tags a public form posted, as ids a form may set: real tags that are
     * not retired.
     *
     * @param array $post
     * @return int[]
     */
    public static function from_form( $post ) {
        $raw = isset( $post['tags'] ) ? (array) $post['tags'] : array();
        if ( empty( $raw ) ) {
            return array();
        }
        return self::clean_ids( array_map( 'intval', $raw ) );
    }

    /** A series' default tags: ids that still exist and are not retired. */
    public static function series_default( $series_id ) {
        $raw = get_term_meta( (int) $series_id, self::META_SERIES_DEFAULT, true );
        $out = array();
        foreach ( (array) $raw as $id ) {
            $id = (int) $id;
            if ( $id && self::exists( $id ) && ! self::is_retired( $id ) && ! in_array( $id, $out, true ) ) {
                $out[] = $id;
            }
        }
        return $out;
    }

    /**
     * Where a tag pill links: the calendar, filtered to that tag.
     *
     * @param string $slug
     * @return string
     */
    public static function filter_url( $slug ) {
        return add_query_arg( 'uc_tag', rawurlencode( (string) $slug ), sfaf_calendar_home_url() );
    }

    /**
     * The pills for an event: its tags, each a link to the calendar filtered to
     * it. '' when it has none. The group's name is the "What's included" public
     * string, in the event's language; tag names print as typed.
     *
     * @param int    $post_id
     * @param string $context 'page' or 'card'.
     * @return string
     */
    public static function pills_html( $post_id, $context = 'page' ) {
        $tags = self::for_event( $post_id );
        if ( empty( $tags ) ) {
            return '';
        }
        $label = SFAF_Messages::label( 'whats_included', sfaf_event_language( (int) $post_id ) );
        $out   = '<ul class="uc-tag-pills uc-tag-pills-' . esc_attr( $context ) . '" aria-label="' . esc_attr( $label ) . '">';
        foreach ( $tags as $t ) {
            $out .= '<li><a class="uc-tag-pill" href="' . esc_url( self::filter_url( $t->slug ) ) . '">' . esc_html( $t->name ) . '</a></li>';
        }
        return $out . '</ul>';
    }
}
