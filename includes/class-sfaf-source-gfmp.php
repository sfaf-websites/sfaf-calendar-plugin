<?php
/**
 * GoFundMe Pro as a source adapter — the second implementation of the import
 * framework in class-sfaf-sources.php, and the proof it is a framework.
 *
 * Nothing in SFAF_Sources changed to add this platform. It registers alongside
 * Eventbrite, joins the same "Fetch updates" run, lands in the same Pending
 * queue, and is de-duplicated by the same external_source + external_id rules.
 *
 * ENDPOINT — from apiv2-public-gfmp.json, listOrganizationCampaigns:
 *
 *   GET {data_base}/organizations/{organization_id}/campaigns?page=N&per_page=100
 *       Authorization: Bearer <access token>
 *       x-integration-id: <integration ID>
 *
 * The response is the spec's PaginatedResponse: rows under `data`, with
 * `current_page` / `last_page` driving the loop. All of that lives in
 * SFAF_GFMP::fetch_campaigns().
 *
 * CAMPAIGNS ARE NOT QUITE EVENTS, and the mapping is honest about it:
 *
 *   - Many campaigns have no date at all. started_at and ended_at are nullable
 *     in the spec, and a general fundraiser has neither. Those are imported
 *     anyway, with an empty date, for a manager to fill in when approving —
 *     skipping them would hide real campaigns.
 *   - There is no `description` field on Campaign. The closest thing the spec
 *     offers is default_page_appeal, the appeal text shown on fundraising
 *     pages, which is what gets used.
 *   - Location is assembled from venue + address parts, none of which are
 *     required.
 *   - `goal` is on the campaign record and is imported. The RAISED amount is
 *     not: the spec defines CampaignAggregates but documents no path returning
 *     it, so it is fetched from a pattern-matched overview endpoint that fails
 *     soft. See SFAF_GFMP::fetch_campaign_overview().
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Source_GFMP extends SFAF_Source_Adapter {

    /** Campaign statuses worth importing, unless filtered. */
    const DEFAULT_STATUSES = 'active,published';

    public function slug() {
        return 'gofundme_pro';
    }

    public function label() {
        return 'GoFundMe Pro';
    }

    /**
     * Active once there are credentials and an organization to address.
     *
     * The org ID is part of the test because campaign calls are literally
     * addressed to /organizations/{id}/campaigns — without it the adapter
     * could authenticate and still have nowhere to go.
     */
    public function is_active() {
        $creds = SFAF_GFMP::credentials();
        return ( '' !== $creds['client_id'] && '' !== $creds['client_secret'] && '' !== $creds['org_id'] );
    }

    /**
     * Why this source was skipped, for the fetch report.
     *
     * @return string
     */
    public function inactive_reason() {
        $creds = SFAF_GFMP::credentials();
        if ( '' === $creds['client_id'] || '' === $creds['client_secret'] ) {
            return 'no client ID and secret stored — add them under Settings → GoFundMe Pro';
        }
        if ( '' === $creds['org_id'] ) {
            return 'no Organization ID stored — campaign calls are addressed to /organizations/{id}/campaigns and need it';
        }
        return 'not configured';
    }

    /**
     * Fetch every campaign for the configured organization.
     *
     * @return array|WP_Error {items, notes}
     */
    public function fetch() {
        $result = SFAF_GFMP::fetch_campaigns();
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $notes = array();
        if ( $result['truncated'] ) {
            $notes[] = $result['truncated_reason'];
        }

        $items        = $result['items'];
        $filtered_ids = array();

        // Statuses worth importing. Blank means "take everything".
        //
        // The IDs dropped here are kept and handed back: a campaign that was
        // closed or unpublished at the source disappears from the fetched set
        // for a KNOWN reason, and the framework counts that separately from a
        // campaign that vanished outright.
        $wanted = trim( (string) apply_filters( 'sfaf_gfmp_import_statuses', self::DEFAULT_STATUSES ) );
        if ( '' !== $wanted ) {
            $allowed = array_filter( array_map( 'trim', explode( ',', strtolower( $wanted ) ) ) );
            $kept    = array();
            foreach ( $items as $row ) {
                $status = isset( $row['status'] ) && is_string( $row['status'] ) ? strtolower( trim( $row['status'] ) ) : '';
                if ( '' === $status || in_array( $status, $allowed, true ) ) {
                    $kept[] = $row;
                } elseif ( ! empty( $row['id'] ) ) {
                    $filtered_ids[] = (string) $row['id'];
                }
            }
            $items = $kept;
            if ( ! empty( $filtered_ids ) ) {
                $notes[] = sprintf(
                    '%d campaign(s) skipped for not being %s. Change this with the sfaf_gfmp_import_statuses filter.',
                    count( $filtered_ids ),
                    implode( ' or ', $allowed )
                );
            }
        }

        // Raised amounts, best effort. One extra call per campaign, so it is
        // both filterable off and capped — and a failure is silent by design,
        // since the endpoint is not in the supplied spec.
        if ( apply_filters( 'sfaf_gfmp_fetch_raised', true ) ) {
            $items = $this->attach_raised( $items, $notes );
        }

        // Clean enough to conclude that a missing campaign has really gone?
        // Only when pagination reached the last page and something came back.
        // A WP_Error above has already returned, so every request succeeded by
        // the time we are here.
        $complete = true;
        $reason   = '';
        if ( $result['truncated'] ) {
            $complete = false;
            $reason   = 'the paged results were cut short, so the list may be incomplete';
        } elseif ( empty( $result['items'] ) ) {
            $complete = false;
            $reason   = 'the source returned no campaigns at all, which is never treated as "everything was deleted"';
        }

        return array(
            'items'           => $items,
            'notes'           => $notes,
            'complete'        => $complete,
            'complete_reason' => $reason,
            'filtered_ids'    => $filtered_ids,
        );
    }

    /**
     * Re-fetch one campaign: GET {data_base}/campaigns/{id}
     *
     * The spec returns a bare Campaign object here, not a paginated wrapper,
     * but a `data` envelope is unwrapped too in case this account differs.
     *
     * @param string $external_id
     * @return array|WP_Error
     */
    public function fetch_one( $external_id ) {
        $campaign = SFAF_GFMP::fetch_campaign( $external_id );
        if ( is_wp_error( $campaign ) ) {
            return $campaign;
        }

        // A single refresh is worth one extra call for the raised figure.
        if ( apply_filters( 'sfaf_gfmp_fetch_raised', true ) ) {
            $token = SFAF_GFMP::get_access_token();
            if ( ! is_wp_error( $token ) ) {
                $overview = SFAF_GFMP::fetch_campaign_overview( $token, $external_id );
                if ( ! is_wp_error( $overview ) ) {
                    foreach ( array( 'raised_amount', 'progress_bar_amount', 'total_online_funds_raised' ) as $key ) {
                        if ( isset( $overview[ $key ] ) && is_numeric( $overview[ $key ] ) ) {
                            $campaign['sfaf_raised_amount'] = (float) $overview[ $key ];
                            break;
                        }
                    }
                }
            }
        }

        return $campaign;
    }

    /**
     * Look up the raised amount for each campaign, tolerating failure.
     *
     * @param array $items Campaign rows.
     * @param array $notes Collected by reference — one summary line, not one per campaign.
     * @return array
     */
    private function attach_raised( $items, &$notes ) {
        $token = SFAF_GFMP::get_access_token();
        if ( is_wp_error( $token ) ) {
            return $items;
        }

        $limit = (int) apply_filters( 'sfaf_gfmp_raised_lookup_limit', 100 );
        $done  = 0;
        $ok    = 0;
        $tried = 0;

        foreach ( $items as $i => $row ) {
            if ( $done >= $limit ) {
                break;
            }
            $id = isset( $row['id'] ) ? (string) $row['id'] : '';
            if ( '' === $id ) {
                continue;
            }

            $done++;
            $tried++;
            $overview = SFAF_GFMP::fetch_campaign_overview( $token, $id );
            if ( is_wp_error( $overview ) ) {
                continue;
            }

            foreach ( array( 'raised_amount', 'progress_bar_amount', 'total_online_funds_raised' ) as $key ) {
                if ( isset( $overview[ $key ] ) && is_numeric( $overview[ $key ] ) ) {
                    $items[ $i ]['sfaf_raised_amount'] = (float) $overview[ $key ];
                    $ok++;
                    break;
                }
            }
        }

        if ( $tried > 0 && 0 === $ok ) {
            $notes[] = 'No raised amounts were available — the campaign overview endpoint is not in the supplied API spec and this account did not answer it. '
                . 'Goals were still imported; the progress bar will show a goal without a raised figure.';
        }
        if ( $done >= $limit && count( $items ) > $limit ) {
            $notes[] = sprintf( 'Raised amounts were looked up for the first %d campaigns only (sfaf_gfmp_raised_lookup_limit).', $limit );
        }

        return $items;
    }

    /**
     * Map one campaign to the common event shape.
     *
     * @param array $item Raw campaign.
     * @return array|null
     */
    public function normalize( $item ) {
        if ( ! is_array( $item ) || empty( $item['id'] ) ) {
            return null;
        }

        $title = '';
        foreach ( array( 'name', 'internal_name' ) as $key ) {
            if ( ! empty( $item[ $key ] ) && is_string( $item[ $key ] ) ) {
                $title = $item[ $key ];
                break;
            }
        }

        // Dates are nullable and frequently absent — a general fundraiser has
        // no start or end. Empty is a valid outcome here, not a reason to skip.
        $start = $this->split_datetime( isset( $item['started_at'] ) ? $item['started_at'] : '' );
        $end   = $this->split_datetime( isset( $item['ended_at'] ) ? $item['ended_at'] : '' );

        $image  = $this->image( $item );
        $goal   = ( isset( $item['goal'] ) && is_numeric( $item['goal'] ) ) ? (float) $item['goal'] : 0;
        $raised = ( isset( $item['sfaf_raised_amount'] ) && is_numeric( $item['sfaf_raised_amount'] ) ) ? (float) $item['sfaf_raised_amount'] : null;
        $url    = $this->source_url( $item );

        // Platform-specific extras written straight to the meta this calendar
        // already uses for a campaign, so the donate block and progress bar
        // pick an imported campaign up with no special-casing.
        $meta = array();
        if ( '' !== $url ) {
            $meta['_uc_gofundme_url'] = $url;
        }
        if ( $goal > 0 ) {
            $meta['_uc_gofundme_goal'] = (string) $goal;
        }
        if ( null !== $raised ) {
            $meta['_uc_gofundme_raised']    = (string) $raised;
            $meta['_uc_gofundme_raised_at'] = (string) time();
        }

        return array(
            'external_source' => $this->slug(),
            'external_id'     => (string) $item['id'],
            'title'           => $title,
            'description'     => $this->description( $item ),
            'start_date'      => $start['date'],
            'start_time'      => $start['time'],
            'end_date'        => $end['date'],
            'end_time'        => $end['time'],
            'timezone'        => isset( $item['timezone_identifier'] ) && is_string( $item['timezone_identifier'] ) ? $item['timezone_identifier'] : '',
            'location'        => $this->location( $item ),
            'source_url'      => $url,
            'image_url'       => $image['url'],
            // Which of the two candidate fields this resolved to, so the fetch
            // report can show what was actually picked up rather than just
            // that something was. See image().
            'image_field'     => $image['field'],
            'meta'            => $meta,
        );
    }

    /**
     * The campaign image, and which field it came from.
     *
     * The Campaign schema exposes exactly two image URLs — logo_url and
     * team_cover_photo_url — and no hero or banner field at all.
     *
     * logo_url is the small logo mark, which is what 2.6.0 used and why
     * imported campaigns showed a logo where a banner belonged.
     * team_cover_photo_url is preferred instead, but THIS IS A BEST GUESS:
     * the spec describes it as the default cover photo for the campaign's
     * fundraising Teams, inherited from the Theme, not explicitly as the
     * campaign page banner. It is shipped to find out what actually comes
     * back, which is why the field used is reported per campaign rather than
     * quietly chosen.
     *
     * Falls back to logo_url, then to nothing — at which point the calendar's
     * own branded placeholder shows, as it does for any event with no image.
     *
     * @param array $item Raw campaign.
     * @return array{url:string,field:string}
     */
    private function image( $item ) {
        $candidates = (array) apply_filters(
            'sfaf_gfmp_image_fields',
            array( 'team_cover_photo_url', 'logo_url' ),
            $item
        );

        foreach ( $candidates as $field ) {
            $field = (string) $field;
            if ( ! empty( $item[ $field ] ) && is_string( $item[ $field ] ) ) {
                $value = trim( $item[ $field ] );
                if ( '' !== $value ) {
                    return array( 'url' => $value, 'field' => $field );
                }
            }
        }

        return array( 'url' => '', 'field' => 'none — placeholder will show' );
    }

    /* ---------------------------------------------------------------------
     * Mapping helpers
     * ------------------------------------------------------------------- */

    /**
     * Split an ISO-8601 campaign timestamp into date and time.
     *
     * Campaign timestamps are UTC ("2021-04-23T08:23:21Z"), unlike
     * Eventbrite's local wall-clock. They are converted to the site's timezone
     * so the date shown is the date a person in this office would call it —
     * an 8pm Pacific start would otherwise land on the following day.
     *
     * @param mixed $value
     * @return array{date:string,time:string}
     */
    private function split_datetime( $value ) {
        $out = array( 'date' => '', 'time' => '' );

        if ( ! is_string( $value ) ) {
            return $out;
        }
        $value = trim( $value );
        if ( '' === $value ) {
            return $out;
        }

        $timestamp = strtotime( $value );
        if ( false === $timestamp ) {
            return $out;
        }

        $out['date'] = wp_date( 'Y-m-d', $timestamp );
        $out['time'] = wp_date( 'H:i', $timestamp );
        return $out;
    }

    /**
     * The public campaign page.
     *
     * canonical_url is relative in the spec's own example ("/campaign/c0"), so
     * absolute values are preferred and a relative one is resolved against the
     * configured data host rather than being stored as a broken link.
     *
     * @param array $item
     * @return string
     */
    private function source_url( $item ) {
        foreach ( array( 'external_url', 'custom_url', 'canonical_url' ) as $key ) {
            if ( empty( $item[ $key ] ) || ! is_string( $item[ $key ] ) ) {
                continue;
            }
            $value = trim( $item[ $key ] );
            if ( '' === $value ) {
                continue;
            }
            if ( 0 === stripos( $value, 'http://' ) || 0 === stripos( $value, 'https://' ) ) {
                return $value;
            }
            // Relative: resolve against the host serving the API.
            $host = wp_parse_url( SFAF_GFMP::api_base() );
            if ( ! empty( $host['scheme'] ) && ! empty( $host['host'] ) ) {
                return $host['scheme'] . '://' . $host['host'] . '/' . ltrim( $value, '/' );
            }
        }
        return '';
    }

    /**
     * Campaign description.
     *
     * There is no description field on Campaign. default_page_appeal is the
     * appeal shown on fundraising pages and is the nearest equivalent the spec
     * offers; the thank-you text is a distant fallback.
     *
     * @param array $item
     * @return string
     */
    private function description( $item ) {
        foreach ( array( 'default_page_appeal', 'default_team_appeal', 'default_thank_you_text' ) as $key ) {
            if ( ! empty( $item[ $key ] ) && is_string( $item[ $key ] ) ) {
                return $item[ $key ];
            }
        }
        return '';
    }

    /**
     * A single-line location from the campaign's venue and address fields.
     *
     * Every part is optional, so this assembles whatever is there and returns
     * an empty string rather than a trail of commas when nothing is.
     *
     * @param array $item
     * @return string
     */
    private function location( $item ) {
        $get = function ( $key ) use ( $item ) {
            return ( isset( $item[ $key ] ) && is_string( $item[ $key ] ) ) ? trim( $item[ $key ] ) : '';
        };

        $parts = array();
        foreach ( array( 'venue', 'address1', 'city', 'state', 'postal_code' ) as $key ) {
            $value = $get( $key );
            if ( '' !== $value ) {
                $parts[] = $value;
            }
        }

        return implode( ', ', $parts );
    }
}
