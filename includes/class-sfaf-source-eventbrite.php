<?php
/**
 * Eventbrite as a source adapter — the first implementation of the import
 * framework in class-sfaf-sources.php.
 *
 * Everything platform-specific about Eventbrite lives here. The framework
 * calls fetch() and normalize() and knows nothing else about it, which is what
 * makes GoFundMe Pro and EveryAction a matter of writing a sibling class
 * rather than editing the importer.
 *
 * ENDPOINTS — the current documented route. The old one-call shortcut
 * (/users/me/events/) is deprecated and is not used:
 *
 *   GET {api_base}/users/me/organizations/
 *   GET {api_base}/organizations/{organization_id}/events/
 *         ?time_filter=current_future
 *         &expand=venue,logo,organizer
 *         &status=live
 *
 * An account can own several organizations, so the second call runs once per
 * organization and the results are combined. Pagination is followed to the
 * end. All of that already lives in SFAF_Eventbrite::fetch_events(), built and
 * proven in step 2 — this adapter passes it the importer's arguments and maps
 * what comes back, so the preview screen and the importer are reading through
 * exactly the same code and cannot disagree about what Eventbrite returned.
 *
 * The bearer token, User-Agent and Accept headers all come from the shared
 * request-args helper from step 1, for the same reason.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Source_Eventbrite extends SFAF_Source_Adapter {

    /** Only upcoming and in-progress events are worth importing. */
    const TIME_FILTER = 'current_future';

    /** Published events only. */
    const STATUS = 'live';

    /** Venue for the location, logo for the image, organizer for later use. */
    const EXPAND = 'venue,logo,organizer';

    public function slug() {
        return 'eventbrite';
    }

    public function label() {
        return 'Eventbrite';
    }

    /**
     * The fields Eventbrite owns on an imported event.
     *
     * The only change this release makes to this adapter. It is the same set
     * of fields the refresh path was already writing, written down so the
     * editor can lock exactly what a fetch will overwrite instead of the two
     * being maintained separately and drifting apart.
     *
     * Eventbrite supplies a real description and a real logo, so unlike
     * GoFundMe Pro both are platform-owned here and there are no
     * manager_fields() to declare.
     *
     * @return string[]
     */
    public function owned_fields() {
        return array( 'title', 'description', 'date', 'start_time', 'end_time', 'end_date', 'location', 'image', 'source_url' );
    }

    /**
     * Active once a private token is stored.
     *
     * Deliberately not "and the connection test has passed" — a token that
     * works is proven by the fetch itself, and requiring the badge would mean
     * a silent skip in the one case worth reporting.
     */
    public function is_active() {
        return SFAF_Eventbrite::has_token();
    }

    /**
     * Why this source was skipped, for the fetch report.
     *
     * @return string
     */
    public function inactive_reason() {
        return 'no private token stored — add one under Settings → Eventbrite';
    }

    /**
     * Fetch upcoming published events across every organization.
     *
     * @return array|WP_Error {items, notes}
     */
    public function fetch() {
        $token = SFAF_Eventbrite::private_token();
        if ( '' === $token ) {
            return new WP_Error(
                'sfaf_eventbrite_missing',
                'No Eventbrite private token is stored. Add one under Settings → Eventbrite.'
            );
        }

        $result = SFAF_Eventbrite::fetch_events( $token, array(
            'status'      => (string) apply_filters( 'sfaf_eventbrite_import_status', self::STATUS ),
            'expand'      => (string) apply_filters( 'sfaf_eventbrite_import_expand', self::EXPAND ),
            'time_filter' => (string) apply_filters( 'sfaf_eventbrite_import_time_filter', self::TIME_FILTER ),
        ) );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        // Anything the fetch could not finish is passed up as a note rather
        // than swallowed: a truncated page run or one organization failing
        // both mean "this list is not everything", and the fetch report says so.
        $notes      = array();
        $org_errors = 0;
        foreach ( $result['notes'] as $note ) {
            $notes[] = (string) $note;
        }
        foreach ( $result['organizations'] as $org ) {
            if ( ! empty( $org['error'] ) ) {
                $org_errors++;
                $notes[] = sprintf(
                    'Organization %s could not be read: %s',
                    ( '' !== $org['name'] ) ? $org['name'] : $org['id'],
                    $org['error']
                );
            }
        }

        // Was this run good enough to conclude that anything missing from it
        // has genuinely gone? Only if every organization answered, no page run
        // was cut short, and something actually came back. Anything less and
        // the framework skips removal handling entirely.
        $complete = true;
        $reason   = '';
        if ( $org_errors > 0 ) {
            $complete = false;
            $reason   = sprintf( '%d organization(s) could not be read, so the list may be incomplete', $org_errors );
        } elseif ( ! empty( $result['notes'] ) ) {
            // fetch_events() only puts truncation warnings in notes.
            $complete = false;
            $reason   = 'the paged results were cut short, so the list may be incomplete';
        } elseif ( empty( $result['events'] ) ) {
            $complete = false;
            $reason   = 'the source returned no events, which is never treated as "everything was deleted"';
        }

        return array(
            'items'           => $result['events'],
            'notes'           => $notes,
            'complete'        => $complete,
            'complete_reason' => $reason,
            // Eventbrite is asked for live/current_future events only, so an
            // event that ends or is unpublished simply stops being returned.
            // There is no separately-observed "filtered out" set to report.
            'filtered_ids'    => array(),
        );
    }

    /**
     * Re-fetch one event: GET {api_base}/events/{id}/?expand=venue,logo,organizer
     *
     * Returned in the same flattened shape fetch() produces, so normalize()
     * cannot tell the difference between a bulk and a single fetch.
     *
     * @param string $external_id
     * @return array|WP_Error
     */
    public function fetch_one( $external_id ) {
        $token = SFAF_Eventbrite::private_token();
        if ( '' === $token ) {
            return new WP_Error( 'sfaf_eventbrite_missing', 'No Eventbrite private token is stored.' );
        }

        $raw = SFAF_Eventbrite::fetch_event( $token, $external_id, array(
            'expand' => (string) apply_filters( 'sfaf_eventbrite_import_expand', self::EXPAND ),
        ) );
        if ( is_wp_error( $raw ) ) {
            return $raw;
        }

        return SFAF_Eventbrite::normalize_event( $raw );
    }

    /**
     * Map one fetched event to the common shape.
     *
     * The rows handed here are what SFAF_Eventbrite::fetch_events() produced —
     * flattened, with the untouched API payload still attached under 'raw',
     * which is where the full description comes from.
     *
     * @param array $item One row from fetch()'s items.
     * @return array|null
     */
    public function normalize( $item ) {
        if ( ! is_array( $item ) || empty( $item['id'] ) ) {
            return null;
        }

        $raw = ( isset( $item['raw'] ) && is_array( $item['raw'] ) ) ? $item['raw'] : array();

        // An event with no start date cannot be placed on a calendar, and
        // guessing one would be worse than skipping it.
        $start = $this->split_datetime( isset( $item['start_local'] ) ? $item['start_local'] : '' );
        if ( '' === $start['date'] ) {
            return null;
        }
        $end = $this->split_datetime( isset( $item['end_local'] ) ? $item['end_local'] : '' );

        return array(
            'external_source' => $this->slug(),
            'external_id'     => (string) $item['id'],
            'title'           => isset( $item['name'] ) ? (string) $item['name'] : '',
            'description'     => $this->description( $item, $raw ),
            'start_date'      => $start['date'],
            'start_time'      => $start['time'],
            'end_date'        => $end['date'],
            'end_time'        => $end['time'],
            'timezone'        => isset( $item['start_timezone'] ) ? (string) $item['start_timezone'] : '',
            'location'        => $this->location( $item ),
            'source_url'      => isset( $item['url'] ) ? (string) $item['url'] : '',
            // The full-resolution original, not the cropped display version.
            // logo.url is Eventbrite's sized crop; logo.original.url is the
            // image as uploaded, which is what an event card wants to scale
            // down from rather than up.
            'image_url'       => $this->image_url( $item ),
        );
    }

    /* ---------------------------------------------------------------------
     * Mapping helpers
     * ------------------------------------------------------------------- */

    /**
     * The event image, preferring the full-resolution original.
     *
     * Eventbrite returns both: logo.url is a cropped, sized rendition and
     * logo.original.url is the image as uploaded. The original is used so the
     * calendar is scaling a large image down rather than a small one up.
     *
     * @param array $item Flattened row.
     * @return string
     */
    private function image_url( $item ) {
        foreach ( array( 'logo_original', 'logo_url' ) as $key ) {
            if ( ! empty( $item[ $key ] ) && is_string( $item[ $key ] ) ) {
                return $item[ $key ];
            }
        }
        return '';
    }

    /**
     * Split an Eventbrite local datetime into the date and time this calendar
     * stores separately.
     *
     * The format is '2026-08-15T18:00:00' — local wall-clock time in the
     * event's own timezone, which is exactly what _uc_event_date and
     * _uc_start_time hold, so no conversion is wanted here. The timezone is
     * kept alongside in its own meta rather than being applied.
     *
     * @param string $value
     * @return array{date:string,time:string}
     */
    private function split_datetime( $value ) {
        $value = trim( (string) $value );
        $out   = array( 'date' => '', 'time' => '' );

        if ( '' === $value ) {
            return $out;
        }

        // Only accept the shape we expect; anything else is left empty rather
        // than sliced into nonsense.
        if ( preg_match( '/^(\d{4}-\d{2}-\d{2})(?:[T ](\d{2}:\d{2}))?/', $value, $m ) ) {
            $out['date'] = $m[1];
            $out['time'] = isset( $m[2] ) ? $m[2] : '';
        }

        return $out;
    }

    /**
     * The event description.
     *
     * Eventbrite's description.text is the full body; summary is the short
     * blurb that replaced it in newer payloads. Preference goes to the fuller
     * one, and the preview row's excerpt is the last resort.
     *
     * @param array $item Flattened row.
     * @param array $raw  Untouched payload.
     * @return string
     */
    private function description( $item, $raw ) {
        if ( isset( $raw['description'] ) && is_array( $raw['description'] ) ) {
            if ( ! empty( $raw['description']['text'] ) && is_string( $raw['description']['text'] ) ) {
                return $raw['description']['text'];
            }
        }
        if ( ! empty( $raw['summary'] ) && is_string( $raw['summary'] ) ) {
            return $raw['summary'];
        }
        if ( isset( $item['description'] ) && is_array( $item['description'] ) && ! empty( $item['description']['excerpt'] ) ) {
            return (string) $item['description']['excerpt'];
        }
        return '';
    }

    /**
     * A single-line location from the venue expansion.
     *
     * Name and address are joined only when both are present and the name is
     * not already the start of the address, which Eventbrite's localized
     * display string sometimes repeats. An online event has no venue at all
     * and gets an empty location rather than an invented one.
     *
     * @param array $item Flattened row.
     * @return string
     */
    private function location( $item ) {
        $name    = isset( $item['venue_name'] ) ? trim( (string) $item['venue_name'] ) : '';
        $address = isset( $item['venue_address'] ) ? trim( (string) $item['venue_address'] ) : '';

        if ( '' === $name && '' === $address ) {
            return ! empty( $item['online_event'] ) ? 'Online' : '';
        }
        if ( '' === $address ) {
            return $name;
        }
        if ( '' === $name ) {
            return $address;
        }
        if ( 0 === stripos( $address, $name ) ) {
            return $address;
        }

        return $name . ', ' . $address;
    }
}
