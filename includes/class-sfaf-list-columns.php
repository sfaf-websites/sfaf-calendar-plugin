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
                    $ts   = strtotime( $date );
                    $time = get_post_meta( $post_id, '_uc_start_time', true );
                    echo '<strong>' . esc_html( date_i18n( 'M j, Y', $ts ) ) . '</strong>';
                    if ( $time ) {
                        echo '<br><span class="uc-col-muted">' . esc_html( date( 'g:i A', strtotime( $time ) ) ) . '</span>';
                    }
                } else {
                    echo '<span class="uc-col-muted">None</span>';
                }
                break;

            case 'uc_rsvp_count':
                $count = sfaf_get_rsvp_count( $post_id );
                $url   = admin_url( 'edit.php?post_type=uc_event&page=uc-rsvps&event_id=' . $post_id );
                if ( $count > 0 ) {
                    echo '<a href="' . esc_url( $url ) . '"><strong>' . (int) $count . '</strong></a>';
                } else {
                    echo '<span class="uc-col-muted">0</span>';
                }
                break;

            case 'uc_series':
                if ( sfaf_is_in_series( $post_id ) ) {
                    $parent = sfaf_series_parent_id( $post_id );
                    $name   = get_the_title( $parent );
                    $is_par = $parent === (int) $post_id;
                    echo '<a href="' . esc_url( get_edit_post_link( $parent ) ) . '">' . esc_html( $name ) . '</a>';
                    echo $is_par ? ' <span class="uc-col-muted">(parent)</span>' : '';
                    if ( get_post_meta( $post_id, '_uc_manually_edited', true ) === '1' ) {
                        echo '<br><span class="uc-col-muted">edited</span>';
                    }
                } else {
                    echo '<span class="uc-col-muted">None</span>';
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
