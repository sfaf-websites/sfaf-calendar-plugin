<?php
/**
 * EveryAction as a source adapter (3.101.0), the third beside Eventbrite and
 * GoFundMe Pro, through the same framework and the same field contract.
 *
 * WHERE THE ROWS COME FROM. Val's tracker on the hub, read with the session
 * SFAF_EveryAction holds: log in, read every page, log out. PROJECT.md 8 has
 * why it is the tracker and not the EveryAction API, and the row shape.
 *
 * WHAT A ROW BECOMES.
 *
 *   UUID                 the stable key. An event is found by it and by
 *                        nothing else, never by its title.
 *   Title                the title.
 *   Start_Time/End_Time  "MM/DD/YYYY hh:mm AM +0000". UTC, and converted to
 *                        the site's timezone. The +0000 is correct: the coffee
 *                        social reads 5 pm in October and 6 pm in November,
 *                        which is 10 am Pacific either side of the clock change.
 *   Location_Name        "Place, street, city, state zip". The text before the
 *                        first comma is the place name.
 *   Location_Street,
 *   _City, _State, _Zip  the address, as its four parts.
 *   Series_ID            the program, and so the calendar series.
 *   Description          seeds the description once; after that it is the
 *                        manager's. Usually empty at the source.
 *   Visibility           empty means public. Anything else is said in the
 *                        fetch report and decided by a person.
 *   Capacity, SignUps    not read: registration is at the source.
 *   RowId, CreatedAt,
 *   Submitted*           the hub's own, not event data.
 *
 * THE SIGNUP LINK is the event's own page on the public list, found by the
 * UUID, which is the list's data-event-id. Until the list shows it, the button
 * goes to the list filtered to the event's day. See after_save().
 *
 * @package SFAF_Calendar
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Source_EveryAction extends SFAF_Source_Adapter {

    const SLUG = 'everyaction';

    /** Term meta on a series: the Series_ID it was made for. */
    const SERIES_META = '_sfaf_series_everyaction_id';

    /** Rows this run marked with a visibility, for the report. */
    private $marked = array();

    public function slug() {
        return self::SLUG;
    }

    public function label() {
        return 'EveryAction';
    }

    /**
     * The fields the tracker owns.
     *
     * NO 'description'. It is usually empty at the source, an owned field is
     * locked in the editor, and an event with no description cannot be
     * published, so owning it would leave most of these events unpublishable.
     * The first import writes the tracker's when there is one (after_save).
     *
     * 'location' is owned, and written by after_save() rather than the
     * framework, because the tracker's place is a name and four parts, not
     * one line.
     *
     * @return string[]
     */
    public function owned_fields() {
        return array( 'title', 'date', 'start_time', 'end_time', 'end_date', 'location', 'source_url' );
    }

    /** @return string[] */
    public function manager_fields() {
        return array( 'description', 'image', 'category', 'organizer', 'private' );
    }

    public function manager_fields_note() {
        return 'The tracker has no picture and rarely a description. Set them here once and a fetch will never change them.';
    }

    public function is_active() {
        return SFAF_EveryAction::configured();
    }

    public function inactive_reason() {
        return 'no hub login stored, add one under Settings & Integrations, EveryAction';
    }

    public function runs_unattended() {
        return SFAF_EveryAction::auto_import();
    }

    public function unattended_off_reason() {
        return 'Auto-Import is off.';
    }

    /**
     * Log in, read every page, log out.
     *
     * @return array|WP_Error
     */
    public function fetch() {
        $this->marked = array();

        $cfg  = SFAF_EveryAction::config();
        $miss = SFAF_EveryAction::missing( $cfg );
        if ( ! empty( $miss ) ) {
            return new WP_Error( 'sfaf_ea_setup', 'Fill in ' . implode( ', ', $miss ) . ' first.' );
        }

        $token = SFAF_EveryAction::login( $cfg );
        if ( is_wp_error( $token ) ) {
            return new WP_Error( 'sfaf_ea_login', SFAF_EveryAction::scrub( SFAF_EveryAction::sentence( 'Login failed: ' . $token->get_error_message() ), $cfg ) );
        }
        $read = SFAF_EveryAction::read_rows( $cfg, $token );
        SFAF_EveryAction::logout( $cfg, $token );

        if ( '' !== $read['error'] ) {
            return new WP_Error( 'sfaf_ea_read', SFAF_EveryAction::scrub( $read['error'], $cfg, $token ) );
        }

        // The first fetch, or one after a day without a read, gets links first,
        // so new events are made with their own page rather than their day.
        $notes = array();
        if ( ( time() - SFAF_EveryAction::links_read_at() ) >= SFAF_EveryAction::LINKS_EVERY ) {
            $run = SFAF_EveryAction::refresh_links();
            if ( ! $run['ok'] ) {
                $notes[] = $run['message'];
            }
        }

        /*
         * WHETHER ANYTHING MISSING FROM THIS RUN HAS REALLY GONE. Only if every
         * page was read AND something came back. A hub that answers with an
         * empty list is not a tracker that deleted everything.
         */
        $complete = $read['complete'] && ! empty( $read['rows'] );
        $reason   = '';
        if ( ! $read['complete'] ) {
            $reason = $read['reason'];
        } elseif ( empty( $read['rows'] ) ) {
            $reason = 'the tracker returned no rows, which is never treated as "everything was deleted"';
        }

        return array(
            'items'           => $read['rows'],
            'notes'           => $notes,
            'complete'        => $complete,
            'complete_reason' => $reason,
            'filtered_ids'    => array(),
        );
    }

    /**
     * One tracker time, in the site's timezone.
     *
     * @param string $value "10/03/2026 05:00 PM +0000"
     * @return DateTimeImmutable|null
     */
    public static function to_local( $value ) {
        $value = trim( (string) $value );
        if ( ! preg_match( '#^(\d{1,2})/(\d{1,2})/(\d{4})\s+(\d{1,2}):(\d{2})\s*([AaPp][Mm])\s*([+-]\d{2}:?\d{2})?$#', $value, $m ) ) {
            return null;
        }
        $hour = (int) $m[4] % 12;
        if ( 'p' === strtolower( $m[6][0] ) ) {
            $hour += 12;
        }
        try {
            $utc = new DateTimeImmutable(
                sprintf( '%04d-%02d-%02d %02d:%02d:00', (int) $m[3], (int) $m[1], (int) $m[2], $hour, (int) $m[5] ),
                new DateTimeZone( isset( $m[7] ) && '' !== $m[7] ? str_replace( ':', '', $m[7] ) : '+0000' )
            );
        } catch ( Exception $e ) {
            return null;
        }
        $local = $utc->setTimezone( wp_timezone() );
        return $local;
    }

    /** A field of a row, trimmed. */
    private static function field( $row, $key ) {
        return ( isset( $row[ $key ] ) && is_scalar( $row[ $key ] ) ) ? trim( (string) $row[ $key ] ) : '';
    }

    /**
     * The place: a name and the four parts of an address.
     *
     * The name is the text before the first comma of Location_Name. When the
     * parts are empty, the rest of Location_Name is read as the address, with
     * the venues parser, which puts what it cannot place into the street whole.
     *
     * @return array{name:string,parts:array}
     */
    public static function place( $row ) {
        $composed = self::field( $row, 'Location_Name' );
        $parts    = array(
            'street' => self::field( $row, 'Location_Street' ),
            'city'   => self::field( $row, 'Location_City' ),
            'state'  => self::field( $row, 'Location_State' ),
            'zip'    => self::field( $row, 'Location_Zip' ),
        );
        $cut  = strpos( $composed, ',' );
        $name = trim( false === $cut ? $composed : substr( $composed, 0, $cut ) );
        if ( '' === implode( '', $parts ) && false !== $cut ) {
            $parts = SFAF_Venues::parse_address( trim( substr( $composed, $cut + 1 ) ) );
        }
        // An address with no place in front of it: the "name" is the street.
        if ( '' !== $name && 0 === strcasecmp( $name, $parts['street'] ) ) {
            $name = '';
        }
        return array( 'name' => $name, 'parts' => $parts );
    }

    /**
     * One row in the common shape.
     *
     * @param array $item
     * @return array|null
     */
    public function normalize( $item ) {
        if ( ! is_array( $item ) ) {
            return null;
        }
        $uuid  = self::field( $item, 'UUID' );
        $start = self::to_local( self::field( $item, 'Start_Time' ) );
        if ( '' === $uuid || ! $start ) {
            return null;
        }
        $end = self::to_local( self::field( $item, 'End_Time' ) );

        $title      = self::field( $item, 'Title' );
        $start_date = $start->format( 'Y-m-d' );
        $visibility = self::field( $item, 'Visibility' );
        if ( '' !== $visibility && 'public' !== strtolower( $visibility ) ) {
            $this->marked[] = array( 'title' => $title, 'date' => $start_date, 'visibility' => $visibility );
        }

        $event = array(
            'external_source' => self::SLUG,
            'external_id'     => $uuid,
            'title'           => $title,
            // Manager-owned: the framework writes nothing from here.
            'description'     => '',
            'start_date'      => $start_date,
            'start_time'      => $start->format( 'H:i' ),
            'end_date'        => $end ? $end->format( 'Y-m-d' ) : '',
            'end_time'        => $end ? $end->format( 'H:i' ) : '',
            'timezone'        => wp_timezone_string(),
            // Written by after_save(), which knows whether a venue took over.
            'location'        => '',
            'source_type'     => 'event',
            /*
             * THE EVENT'S OWN PAGE, OR NOTHING, HERE. Blank leaves the stored
             * address alone, so a page the list stops showing is not traded for
             * the list. after_save() puts the day's list in when there is no
             * page at all, or when the date moved.
             */
            'source_url'      => SFAF_EveryAction::matched_url( $uuid ),
            'image_url'       => '',
            'everyaction'     => array(
                'day_url'     => SFAF_EveryAction::day_url( $start_date ),
                'series_id'   => self::field( $item, 'Series_ID' ),
                'description' => self::field( $item, 'Description' ),
                'place'       => self::place( $item ),
            ),
        );

        /*
         * PAST ROWS ARE NOT IMPORTED, judged on the end time and not the day.
         * Refused only where a new row would be made (import_refusal()), so an
         * event already here is still refreshed and still counts as present at
         * the source, and the removal step cannot mistake it for deleted.
         */
        $last = $end ? $end : $start;
        if ( $last->getTimestamp() < time() ) {
            $event['not_an_event'] = 'its end time has passed';
        }

        return $event;
    }

    /**
     * The place and the series, which the common shape cannot carry, and the
     * description the first time.
     *
     * @return array Label => {from, to}
     */
    public function after_save( $post_id, $event, $is_new ) {
        $post_id = (int) $post_id;
        $x       = ( isset( $event['everyaction'] ) && is_array( $event['everyaction'] ) ) ? $event['everyaction'] : array();
        $changed = array();

        if ( $is_new && ! empty( $x['description'] ) && '' === trim( (string) get_post_field( 'post_content', $post_id ) ) ) {
            wp_update_post( array( 'ID' => $post_id, 'post_content' => wp_kses_post( $x['description'] ) ) );
        }

        // No page of its own yet: the list, on the event's day.
        if ( '' === (string) ( isset( $event['source_url'] ) ? $event['source_url'] : '' ) && ! empty( $x['day_url'] ) ) {
            $current = (string) get_post_meta( $post_id, SFAF_Sources::META_SOURCE_URL, true );
            if ( ( '' === $current || SFAF_EveryAction::is_list_url( $current ) ) && $current !== $x['day_url'] ) {
                update_post_meta( $post_id, SFAF_Sources::META_SOURCE_URL, esc_url_raw( $x['day_url'] ) );
                if ( ! $is_new ) {
                    $changed['Source URL'] = array( 'from' => $current, 'to' => $x['day_url'] );
                }
            }
        }

        /*
         * THE PLACE, UNLESS A PERSON MADE IT A VENUE. Promoting a place to a
         * venue deletes the event's own text, and an event has one or the
         * other, never both. Writing the text back would give it both.
         */
        if ( ! empty( $x['place'] ) && ! SFAF_Venues::id_for_event( $post_id ) ) {
            $was = function_exists( 'sfaf_event_location' ) ? sfaf_event_location( $post_id ) : '';
            if ( $this->write_place( $post_id, $x['place'] ) && ! $is_new ) {
                $changed['Location'] = array( 'from' => $was, 'to' => function_exists( 'sfaf_event_location' ) ? sfaf_event_location( $post_id ) : '' );
            }
        }

        /*
         * THE SERIES, WHEN THE EVENT HAS NONE. Set once; a series a person
         * moves the event to afterwards is theirs.
         */
        if ( ! empty( $x['series_id'] ) && ! SFAF_Series::id_for_event( $post_id ) ) {
            $term_id = self::series_for( $x['series_id'], isset( $event['title'] ) ? $event['title'] : '' );
            if ( $term_id ) {
                SFAF_Series::set_for_event( $post_id, $term_id );
                if ( ! $is_new ) {
                    $term = SFAF_Series::get( $term_id );
                    $changed['Series'] = array( 'from' => '', 'to' => $term ? $term->name : '' );
                }
            }
        }

        return $changed;
    }

    /**
     * Write the place name, the parts and the line they compose. A blank from
     * the source leaves what is there, as every other field does.
     *
     * @return bool Whether anything changed.
     */
    private function write_place( $post_id, $place ) {
        $parts  = isset( $place['parts'] ) && is_array( $place['parts'] ) ? $place['parts'] : array();
        $values = array( '_uc_location_name' => isset( $place['name'] ) ? (string) $place['name'] : '' );
        foreach ( sfaf_location_part_keys() as $part => $key ) {
            $values[ $key ] = isset( $parts[ $part ] ) ? (string) $parts[ $part ] : '';
        }
        $values['_uc_location'] = SFAF_Venues::compose( $parts );

        $moved = false;
        foreach ( $values as $key => $value ) {
            $value = sanitize_text_field( $value );
            if ( '' === $value || (string) get_post_meta( $post_id, $key, true ) === $value ) {
                continue;
            }
            update_post_meta( $post_id, $key, $value );
            $moved = true;
        }
        return $moved;
    }

    /**
     * The series for one Series_ID, made the first time it is seen.
     *
     * Named from the program's title. A series of that name that no program
     * has claimed is used, as a repeat does with its own title
     * (SFAF_Series::create_for_event()); one another Series_ID has claimed is
     * not, and the new one carries the code after its name.
     *
     * @param string $code
     * @param string $title
     * @return int Term ID, or 0.
     */
    public static function series_for( $code, $title ) {
        $code = trim( (string) $code );
        if ( '' === $code ) {
            return 0;
        }
        $found = get_terms( array(
            'taxonomy'   => SFAF_Series::TAXONOMY,
            'hide_empty' => false,
            'fields'     => 'ids',
            'number'     => 1,
            'meta_key'   => self::SERIES_META,
            'meta_value' => $code,
        ) );
        if ( is_array( $found ) && ! empty( $found ) ) {
            return (int) reset( $found );
        }

        $name = trim( sanitize_text_field( (string) $title ) );
        if ( '' === $name ) {
            $name = 'EveryAction ' . $code;
        }
        $term = get_term_by( 'name', $name, SFAF_Series::TAXONOMY );
        if ( $term && ! is_wp_error( $term ) ) {
            if ( '' === (string) get_term_meta( (int) $term->term_id, self::SERIES_META, true ) ) {
                update_term_meta( (int) $term->term_id, self::SERIES_META, $code );
                return (int) $term->term_id;
            }
            $name = $name . ' (' . $code . ')';
        }

        $made = SFAF_Series::create( $name );
        if ( is_wp_error( $made ) || ! $made ) {
            return 0;
        }
        update_term_meta( (int) $made, self::SERIES_META, $code );
        return (int) $made;
    }

    /** The visibility note, and the numbers the panel shows. */
    public function after_run( &$result ) {
        if ( ! empty( $this->marked ) ) {
            $values = array_values( array_unique( wp_list_pluck( $this->marked, 'visibility' ) ) );
            $result['notes'][] = sprintf(
                '%d %s marked "%s" at the source. Check %s before publishing.',
                count( $this->marked ),
                1 === count( $this->marked ) ? 'row is' : 'rows are',
                implode( '", "', $values ),
                1 === count( $this->marked ) ? 'it' : 'them'
            );
        }
        SFAF_EveryAction::record_fetch( $result );
        if ( SFAF_EveryAction::links_read_at() ) {
            $run = SFAF_EveryAction::links_run();
            $run = array_merge( $run, SFAF_EveryAction::count_matches() );
            update_option( SFAF_EveryAction::LINKS_RUN_OPTION, $run, false );
        }
    }
}
