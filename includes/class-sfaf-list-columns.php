<?php
/**
 * Custom columns on the Events (uc_event) list table in WP admin.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_List_Columns {

    public function register() {
        add_filter( 'manage_uc_event_posts_columns', array( $this, 'columns' ) );
        add_action( 'manage_uc_event_posts_custom_column', array( $this, 'render_column' ), 10, 2 );
        add_filter( 'manage_edit-uc_event_sortable_columns', array( $this, 'sortable' ) );
        add_action( 'pre_get_posts', array( $this, 'orderby' ) );
    }

    /**
     * Insert our columns after the title.
     */
    public function columns( $columns ) {
        $new = array();
        foreach ( $columns as $key => $label ) {
            $new[ $key ] = $label;
            if ( $key === 'title' ) {
                $new['uc_event_date']   = 'Event Date';
                $new['uc_rsvp_count']   = 'RSVPs';
                $new['uc_series']       = 'Series';
                $new['uc_source']       = 'Source';
                $new['uc_integrations'] = 'Integrations';
            }
        }
        return $new;
    }

    public function render_column( $column, $post_id ) {
        switch ( $column ) {

            case 'uc_event_date':
                $date = get_post_meta( $post_id, '_uc_event_date', true );
                if ( $date ) {
                    $time = get_post_meta( $post_id, '_uc_start_time', true );
                    // The stored string, not strtotime() of it: PHP runs in UTC
                    // here, so a timestamp taken from a bare Y-m-d is midnight
                    // UTC and renders as the previous day on the site's clock.
                    echo '<strong>' . esc_html( sfaf_ap_date( $date, 'short_year' ) ) . '</strong>';
                    if ( $time ) {
                        echo '<br><span class="uc-col-muted">' . esc_html( sfaf_ap_time( $time ) ) . '</span>';
                    }
                } else {
                    echo '<span class="uc-col-muted">None</span>';
                }
                break;

            case 'uc_rsvp_count':
                /*
                 * POINTS AT /caladmin NOW. The WordPress RSVP screen went in
                 * 3.27.0 and this was one of two links into it. The registration
                 * list is one screen again, and the one it goes to is gated on
                 * can_view_all rather than on edit_posts.
                 *
                 * A COUNT IS STILL A COUNT FOR EVERYBODY. The number renders for
                 * any user who can see this column; only the link goes somewhere
                 * that may refuse them, and /caladmin answers that by showing
                 * them their dashboard rather than an error. Same reasoning as
                 * the registration alert's per-recipient link: nothing here can
                 * check the capability cheaply, and unlike an email this one
                 * lands on a page that handles it.
                 */
                $count = sfaf_get_rsvp_count( $post_id );
                $url   = add_query_arg( 'event_id', $post_id, SFAF_Portal::link( 'rsvps' ) );
                if ( $count > 0 ) {
                    echo '<a href="' . esc_url( $url ) . '"><strong>' . (int) $count . '</strong></a>';
                } else {
                    echo '<span class="uc-col-muted">0</span>';
                }
                break;

            case 'uc_series':
                // No "(parent)" marker and no "edited" marker any more. Neither
                // has anything to mean: no event is a series, and nothing
                // regenerates, so nothing has to be defended from being
                // rewritten. What is left is the series it belongs to, and the
                // recurrence group that made it — two different facts, shown as
                // two different things. See SFAF_Recurrence's header note.
                $series = SFAF_Series::for_event( $post_id );
                if ( $series ) {
                    // /caladmin, for the reason above: the WordPress Series
                    // screen went in 3.27.0 and the portal's is the one that
                    // holds the schedule.
                    $url = SFAF_Portal::link( 'series/edit/' . (int) $series->term_id );
                    echo '<a href="' . esc_url( $url ) . '">' . esc_html( $series->name ) . '</a>';
                } else {
                    echo '<span class="uc-col-muted">None</span>';
                }
                $pattern = SFAF_Recurrence::pattern_of( $post_id );
                if ( '' !== $pattern ) {
                    echo '<br><span class="uc-col-muted">'
                        . esc_html( SFAF_Recurrence::pattern_label( $pattern, get_post_meta( $post_id, '_uc_event_date', true ) ) )
                        . '</span>';
                }
                break;

            case 'uc_source':
                $source = get_post_meta( $post_id, '_uc_source_site', true );
                if ( $source ) {
                    $host = wp_parse_url( $source, PHP_URL_HOST );
                    echo '<span class="uc-col-source" title="' . esc_attr( $source ) . '">' . sfaf_icon( 'link' ) . ' ' . esc_html( $host ? $host : $source ) . '</span>';
                } else {
                    echo '<span class="uc-col-muted">Local</span>';
                }
                break;

            case 'uc_integrations':
                $gofundme = get_post_meta( $post_id, '_uc_gofundme_url', true );
                $pardot   = (array) get_post_meta( $post_id, '_uc_pardot_campaigns', true );
                $pardot   = array_filter( $pardot );

                echo '<span class="uc-col-badge ' . ( $gofundme ? 'uc-col-on' : 'uc-col-off' ) . '" title="GoFundMe">' . sfaf_icon( 'heart' ) . '</span> ';
                echo '<span class="uc-col-badge ' . ( ! empty( $pardot ) ? 'uc-col-on' : 'uc-col-off' ) . '" title="Pardot">' . sfaf_icon( 'cloud' ) . '</span>';
                break;
        }
    }

    public function sortable( $columns ) {
        $columns['uc_event_date'] = 'uc_event_date';
        return $columns;
    }

    /**
     * Order the list by the event date meta when that column header is clicked.
     */
    public function orderby( $query ) {
        if ( ! is_admin() || ! $query->is_main_query() ) {
            return;
        }
        if ( $query->get( 'post_type' ) !== 'uc_event' ) {
            return;
        }
        if ( $query->get( 'orderby' ) === 'uc_event_date' ) {
            $query->set( 'meta_key', '_uc_event_date' );
            $query->set( 'orderby', 'meta_value' );
        }
    }
}
