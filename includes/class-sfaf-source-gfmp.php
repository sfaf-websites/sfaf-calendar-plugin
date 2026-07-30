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
     * The fields GoFundMe Pro owns on an imported event.
     *
     * ONE LIST, TWO CONSUMERS. SFAF_Sources::update_event() writes only what
     * is named here, and the editor locks exactly what is named here. They
     * cannot drift into disagreement, because there is nothing to keep in
     * step — adding or removing a field changes both at once.
     *
     * Note what is NOT here: image, description, category and organizer. See
     * manager_fields().
     *
     * 'donate_url' IS here because normalize() sends _uc_gofundme_url in its
     * meta extras, so a fetch really does overwrite the Donate box on a
     * campaign. That is the test for this list: not "does the platform have a
     * version of this field" but "does update_event() write the meta key that
     * editor control writes". Eventbrite does not send it and so does not
     * declare it, which is why the two adapters differ here.
     *
     * @return string[]
     */
    public function owned_fields() {
        return array( 'title', 'date', 'start_time', 'end_time', 'end_date', 'location', 'source_url', 'donate_url', 'faqs' );
    }

    /**
     * Fields a manager owns permanently on a GoFundMe Pro event.
     *
     * THIS IS THE EXPLICIT VERSION OF SOMETHING THAT USED TO WORK BY ACCIDENT.
     *
     * GoFundMe Pro support has confirmed that the campaign banner image and
     * the About-section copy live in their design/theme layer and are NOT
     * exposed on the public API. It is a gap on their side, not a field we
     * have failed to find: there is no theme_id on the campaign object, no
     * story to read (/campaigns/{id}/stories returns 0), and page scraping is
     * not something this plugin does.
     *
     * So a person writes both, once, before approving the campaign from the
     * pending queue — and NOTHING in the import path may ever write them.
     * Until now that held only because description() happened to return ''
     * and _uc_image_url happened to outrank the source image. Both of those
     * are one well-meaning "fix the gap" commit away from silently
     * overwriting a manager's copy on the next fetch. Naming them here makes
     * the exclusion a rule the framework enforces rather than a coincidence.
     *
     * IF YOU ARE HERE TO ADD AN IMAGE OR DESCRIPTION MAPPING: check with
     * GoFundMe Pro first. As of this release they do not expose either.
     *
     * Category and Organizer are a different case with the same answer. They
     * are this calendar's own taxonomies, so no platform supplies them and
     * none ever will, and an event is not really ready to publish without
     * them. They are listed here so the editor asks for them in the same
     * place, in the same way, as the two GoFundMe Pro genuinely cannot give.
     *
     * @return string[]
     */
    public function manager_fields() {
        return array( 'image', 'description', 'category', 'organizer' );
    }

    /**
     * Why the manager-owned fields arrive empty, in the editor's own words.
     *
     * @return string
     */
    public function manager_fields_note() {
        return 'GoFundMe Pro does not provide a campaign image or description through its API. They live in the campaign\'s page design, which is not exposed. Whatever you enter here is kept and is never overwritten by a fetch.';
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
            return 'no client ID and secret stored, add them under Settings → GoFundMe Pro';
        }
        if ( '' === $creds['org_id'] ) {
            return 'no Organization ID stored: campaign calls are addressed to /organizations/{id}/campaigns and need it';
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

        // One-time correction of the descriptions imported from
        // default_page_appeal, using the campaigns already fetched. Runs
        // before the import loop, though the order does not matter: with the
        // mapping removed, an update sends no description and would not have
        // overwritten them anyway. See cleanup_appeal_descriptions().
        $cleanup = self::cleanup_appeal_descriptions( $result['items'] );
        if ( $cleanup['examined'] > 0 ) {
            $notes[] = sprintf(
                'One-time cleanup: %d imported description(s) matched the campaign\'s fundraiser-page appeal text and were cleared; %d differed and were left alone as hand-edited. Write a description when approving. It will survive later fetches.',
                (int) $cleanup['cleared'],
                (int) $cleanup['kept']
            );
        }

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

        // Raised amounts and FAQs — one extra call each per campaign, both
        // filterable off. Against a 300 requests/minute ceiling this is a
        // rounding error: ~20 campaigns is ~40 requests.
        if ( apply_filters( 'sfaf_gfmp_fetch_raised', true ) ) {
            $items = $this->attach_raised( $items, $notes );
        }
        if ( apply_filters( 'sfaf_gfmp_fetch_faqs', true ) ) {
            $items = $this->attach_faqs( $items, $notes );
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

        // A single refresh is worth the two extra calls.
        $token = SFAF_GFMP::get_access_token();
        if ( ! is_wp_error( $token ) ) {
            if ( apply_filters( 'sfaf_gfmp_fetch_raised', true ) ) {
                $overview = SFAF_GFMP::fetch_campaign_overview( $token, $external_id );
                if ( ! is_wp_error( $overview ) ) {
                    $raised = self::raised_amount( $overview );
                    if ( null !== $raised ) {
                        $campaign['sfaf_raised_amount'] = $raised;
                    }
                }
            }
            if ( apply_filters( 'sfaf_gfmp_fetch_faqs', true ) ) {
                $faqs = SFAF_GFMP::fetch_campaign_faqs( $token, $external_id );
                if ( ! is_wp_error( $faqs ) ) {
                    $campaign['sfaf_faqs']       = $faqs['items'];
                    $campaign['sfaf_faqs_clean'] = ! empty( $faqs['complete'] );
                }
            }
        }

        return $campaign;
    }

    /**
     * The raised figure from a campaign overview response.
     *
     * WHICH FIELD, AND WHY IT IS gross_amount.
     *
     * The overview returns gross_amount, total_gross_amount, net_amount,
     * fees_amount and percent_to_goal. Campaign 773343 returned gross 1833,
     * net 1817.22, fees 15.78 and percent_to_goal 1.833 against a 100,000
     * goal — and 1833/100000 is exactly 1.833%, while 1817.22/100000 is not.
     * GoFundMe Pro's own progress figure is therefore computed from GROSS, so
     * mapping gross_amount is what makes this calendar's bar agree with the
     * number a donor sees on the campaign page. Mapping net would quietly
     * show a smaller total here than the source shows there, and nobody
     * looking at the two would be able to explain the difference.
     *
     * total_gross_amount was identical on this account, but it is the less
     * specific of the two and its relationship to gross_amount is not
     * documented, so it is only a fallback for a response that omits
     * gross_amount rather than the first choice.
     *
     * net_amount is deliberately last: it is a real figure and better than
     * nothing, but it answers a different question (what reached the
     * organisation) than a public progress bar asks (what has been given).
     *
     * @param array $overview Decoded overview response.
     * @return float|null
     */
    public static function raised_amount( $overview ) {
        if ( ! is_array( $overview ) ) {
            return null;
        }
        // Some accounts wrap the payload; unwrap before reading.
        if ( isset( $overview['data'] ) && is_array( $overview['data'] ) ) {
            $overview = $overview['data'];
        }
        foreach ( array( 'gross_amount', 'total_gross_amount', 'net_amount' ) as $key ) {
            if ( isset( $overview[ $key ] ) && is_numeric( $overview[ $key ] ) ) {
                return (float) $overview[ $key ];
            }
        }
        return null;
    }

    /**
     * Look up the raised amount for each campaign, tolerating failure.
     *
     * THE CAP. This was 100, which was never the problem it looked like — the
     * organisation has around 20 campaigns, so it never engaged. It is raised
     * to 500 now that the real ceiling is known to be 300 requests per minute
     * per application: an hourly run over 20 campaigns is about 40 requests,
     * so the cap has nothing to do with rate limiting and is only a guard
     * against an unbounded loop if the campaign list ever returns something
     * absurd. It did NOT contribute to the missing raised amounts.
     *
     * @param array $items Campaign rows.
     * @param array $notes Collected by reference — one summary line, not one per campaign.
     * @return array
     */
    private function attach_raised( $items, &$notes ) {
        $token = SFAF_GFMP::get_access_token();
        if ( is_wp_error( $token ) ) {
            $notes[] = 'Raised amounts were not looked up: ' . $token->get_error_message();
            return $items;
        }

        $limit  = (int) apply_filters( 'sfaf_gfmp_raised_lookup_limit', 500 );
        $done   = 0;
        $ok     = 0;
        $failed = array();

        foreach ( $items as $i => $row ) {
            if ( $done >= $limit ) {
                break;
            }
            $id = isset( $row['id'] ) ? (string) $row['id'] : '';
            if ( '' === $id ) {
                continue;
            }

            $done++;
            $overview = SFAF_GFMP::fetch_campaign_overview( $token, $id );

            // PER CAMPAIGN, NOT PER ENDPOINT. Earlier releases reported a
            // single "this account did not answer the overview endpoint",
            // which was false — the endpoint is supported and answers fine,
            // and the real cause of the empty result was reading key names
            // that are not in the response (see raised_amount()). A lookup
            // that genuinely fails now names the campaign it failed for.
            if ( is_wp_error( $overview ) ) {
                $failed[] = sprintf( '%s (%s)', $this->campaign_label( $row ), $overview->get_error_message() );
                continue;
            }

            $raised = self::raised_amount( $overview );
            if ( null === $raised ) {
                $keys     = array_keys( is_array( $overview ) ? $overview : array() );
                $failed[] = sprintf(
                    '%s (the overview answered but carried no amount; fields returned: %s)',
                    $this->campaign_label( $row ),
                    empty( $keys ) ? '(none)' : implode( ', ', $keys )
                );
                continue;
            }

            $items[ $i ]['sfaf_raised_amount'] = $raised;
            $ok++;
        }

        if ( ! empty( $failed ) ) {
            $notes[] = sprintf(
                'Raised amount unavailable for %d of %d campaign(s): %s. The others imported normally.',
                count( $failed ),
                $done,
                implode( '; ', array_slice( $failed, 0, 5 ) ) . ( count( $failed ) > 5 ? '; …' : '' )
            );
        }
        if ( $done >= $limit && count( $items ) > $limit ) {
            $notes[] = sprintf( 'Raised amounts were looked up for the first %d campaigns only (sfaf_gfmp_raised_lookup_limit).', $limit );
        }

        return $items;
    }

    /**
     * Look up each campaign's FAQs, tolerating failure.
     *
     * A campaign whose FAQ call fails gets NO sfaf_faqs key at all, which the
     * framework reads as "this fetch has nothing to say about FAQs" and leaves
     * the event's existing rows completely alone. That is the difference
     * between a failed lookup and a campaign that really has no FAQs, and it
     * is why the key is absent rather than an empty array.
     *
     * @param array $items Campaign rows.
     * @param array $notes Collected by reference.
     * @return array
     */
    private function attach_faqs( $items, &$notes ) {
        $token = SFAF_GFMP::get_access_token();
        if ( is_wp_error( $token ) ) {
            $notes[] = 'FAQs were not looked up: ' . $token->get_error_message();
            return $items;
        }

        $limit  = (int) apply_filters( 'sfaf_gfmp_faq_lookup_limit', 500 );
        $done   = 0;
        $failed = array();

        foreach ( $items as $i => $row ) {
            if ( $done >= $limit ) {
                break;
            }
            $id = isset( $row['id'] ) ? (string) $row['id'] : '';
            if ( '' === $id ) {
                continue;
            }

            $done++;
            $faqs = SFAF_GFMP::fetch_campaign_faqs( $token, $id );
            if ( is_wp_error( $faqs ) ) {
                $failed[] = sprintf( '%s (%s)', $this->campaign_label( $row ), $faqs->get_error_message() );
                continue;
            }

            $items[ $i ]['sfaf_faqs']       = $faqs['items'];
            $items[ $i ]['sfaf_faqs_clean'] = ! empty( $faqs['complete'] );
        }

        if ( ! empty( $failed ) ) {
            $notes[] = sprintf(
                'FAQs could not be read for %d of %d campaign(s): %s. Their existing FAQ rows were left untouched.',
                count( $failed ),
                $done,
                implode( '; ', array_slice( $failed, 0, 5 ) ) . ( count( $failed ) > 5 ? '; …' : '' )
            );
        }

        return $items;
    }

    /** A campaign's name for a report line, falling back to its ID. */
    private function campaign_label( $row ) {
        foreach ( array( 'name', 'internal_name' ) as $key ) {
            if ( ! empty( $row[ $key ] ) && is_string( $row[ $key ] ) ) {
                return $row[ $key ];
            }
        }
        return isset( $row['id'] ) ? '#' . $row['id'] : '(unnamed campaign)';
    }

    /**
     * Map one campaign's FAQ rows to the framework's FAQ shape.
     *
     * WEIGHT DRIVES ORDER. GoFundMe Pro returns a weight per FAQ and that is
     * the order they appear in on the campaign page, so it is sorted on here
     * rather than trusting the order the API happened to return them in.
     * Ties keep the order they arrived in, which is what a stable sort of
     * equal weights should do.
     *
     * `tag` is deliberately dropped: this calendar's FAQ block has a question
     * and an answer and nowhere sensible to put a category, and inventing a
     * place for it would be a display change nobody asked for.
     *
     * @param array $rows Raw FAQ rows.
     * @return array[] Each array( question, answer, source_faq_id ).
     */
    private function map_faqs( $rows ) {
        $mapped = array();

        foreach ( (array) $rows as $index => $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $question = isset( $row['question'] ) && is_string( $row['question'] ) ? trim( $row['question'] ) : '';
            $answer   = isset( $row['answer'] ) && is_string( $row['answer'] ) ? trim( $row['answer'] ) : '';
            $faq_id   = isset( $row['id'] ) && is_scalar( $row['id'] ) ? (string) $row['id'] : '';

            // No ID means nothing downstream can ever match this row again on
            // a later fetch, so it would be imported afresh every time. Skip.
            if ( '' === $faq_id || ( '' === $question && '' === $answer ) ) {
                continue;
            }

            $mapped[] = array(
                'question'      => $question,
                'answer'        => $answer,
                'source_faq_id' => $faq_id,
                'weight'        => isset( $row['weight'] ) && is_numeric( $row['weight'] ) ? (float) $row['weight'] : 0,
                'order'         => $index,
            );
        }

        usort( $mapped, function ( $a, $b ) {
            if ( $a['weight'] === $b['weight'] ) {
                return $a['order'] - $b['order'];
            }
            return ( $a['weight'] < $b['weight'] ) ? -1 : 1;
        } );

        // weight and order have done their job; they are not stored.
        foreach ( $mapped as $i => $row ) {
            unset( $mapped[ $i ]['weight'], $mapped[ $i ]['order'] );
        }

        return array_values( $mapped );
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

        // FAQs, and whether this fetch is entitled to conclude anything about
        // FAQ rows that are missing from it. The key is absent — not empty —
        // when the lookup failed or was switched off, which is what tells the
        // framework to leave the event's existing rows completely alone.
        $faqs       = null;
        $faqs_clean = false;
        if ( isset( $item['sfaf_faqs'] ) && is_array( $item['sfaf_faqs'] ) ) {
            $faqs       = $this->map_faqs( $item['sfaf_faqs'] );
            $faqs_clean = ! empty( $item['sfaf_faqs_clean'] );
        }

        $event = array(
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

        if ( null !== $faqs ) {
            $event['faqs']       = $faqs;
            $event['faqs_clean'] = $faqs_clean;
        }

        return $event;
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
        // INTERIM: no candidates, on purpose — campaigns fall through to the
        // calendar's own branded placeholder.
        //
        // team_cover_photo_url returned artwork that is neither the campaign
        // banner nor any image on the campaign page, and logo_url is the small
        // logo mark. A wrong image on a public calendar is worse than no image,
        // so nothing is chosen until the probe shows which field is right.
        //
        // The filter is unchanged, so a candidate list can be put back on a
        // live site the moment we know the answer, without a rebuild:
        //
        //     add_filter( 'sfaf_gfmp_image_fields', function () {
        //         return array( 'the_right_field' );
        //     } );
        $candidates = (array) apply_filters( 'sfaf_gfmp_image_fields', array(), $item );

        foreach ( $candidates as $field ) {
            $field = (string) $field;
            if ( ! empty( $item[ $field ] ) && is_string( $item[ $field ] ) ) {
                $value = trim( $item[ $field ] );
                if ( '' !== $value ) {
                    return array( 'url' => $value, 'field' => $field );
                }
            }
        }

        return array( 'url' => '', 'field' => 'none, placeholder will show' );
    }

    /* ---------------------------------------------------------------------
     * One-time cleanup of the appeal-text descriptions
     * ------------------------------------------------------------------- */

    /** Set once the appeal-text cleanup has run. */
    const CLEANUP_OPTION = 'sfaf_gfmp_appeal_description_cleanup_done';

    /**
     * Clear descriptions that were imported from default_page_appeal.
     *
     * WHY THIS IS NEEDED. Simply removing the mapping is not enough: 2.7.0's
     * "empty in, leave alone" rule means a refetch now sends no description at
     * all, which PROTECTS the wrong text already sitting on the events rather
     * than replacing it. Left alone it would stay there permanently.
     *
     * THE HEURISTIC, same as migrate_image_split(): clear the description only
     * where the stored value is byte-identical to that campaign's current
     * default_page_appeal. That is what the importer would have written, so an
     * exact match identifies machine-written text. Anything a person has
     * touched differs by even a character and is left alone.
     *
     * The comparison allows for the fact that import_event() ran the value
     * through wp_kses_post(), so both the raw and the filtered forms count as
     * a match.
     *
     * Runs once, from fetch(), using the campaigns already in hand — no extra
     * API calls. Not a new rule: a one-time correction keyed to one specific
     * bad value.
     *
     * @param array $campaigns Raw campaigns from this fetch.
     * @return array{cleared:int,kept:int,examined:int}
     */
    public static function cleanup_appeal_descriptions( $campaigns ) {
        $out = array( 'cleared' => 0, 'kept' => 0, 'examined' => 0 );

        if ( get_option( self::CLEANUP_OPTION ) ) {
            return $out;
        }

        // Every status the importer can see. Trash is left out — a trashed
        // event is gone as far as anyone is concerned.
        $statuses = array_values( array_diff( SFAF_Sources::all_statuses(), array( 'trash' ) ) );

        foreach ( $campaigns as $campaign ) {
            if ( ! is_array( $campaign ) || empty( $campaign['id'] ) ) {
                continue;
            }
            $appeal = ( isset( $campaign['default_page_appeal'] ) && is_string( $campaign['default_page_appeal'] ) )
                ? $campaign['default_page_appeal']
                : '';
            if ( '' === trim( $appeal ) ) {
                continue;
            }

            $query = new WP_Query( array(
                'post_type'              => 'uc_event',
                'post_status'            => $statuses,
                'posts_per_page'         => 10,
                'fields'                 => 'ids',
                'no_found_rows'          => true,
                'ignore_sticky_posts'    => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
                'meta_query'             => array(
                    'relation' => 'AND',
                    array( 'key' => SFAF_Sources::META_SOURCE, 'value' => 'gofundme_pro' ),
                    array( 'key' => SFAF_Sources::META_EXTERNAL_ID, 'value' => (string) $campaign['id'] ),
                ),
            ) );

            foreach ( $query->posts as $post_id ) {
                $content = (string) get_post_field( 'post_content', $post_id );
                if ( '' === trim( $content ) ) {
                    continue;
                }
                $out['examined']++;

                if ( $content === $appeal || $content === wp_kses_post( $appeal ) ) {
                    wp_update_post( array( 'ID' => $post_id, 'post_content' => '' ) );
                    $out['cleared']++;
                } else {
                    $out['kept']++;
                }
            }
        }

        update_option( self::CLEANUP_OPTION, '1', false );
        return $out;
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
        // INTERIM: nothing is mapped, on purpose.
        //
        // This used to return default_page_appeal. That is GoFundMe Pro's
        // default appeal text for INDIVIDUAL fundraiser pages, not a
        // description of the campaign — on Santa Skivvies it imported
        // "I'm Bar(e)ing It All for San Francisco AIDS Foundation", which is
        // personal-page template copy and reads as nonsense on a calendar.
        //
        // Campaign has no description field at all, and every other text field
        // on it (default_team_appeal, default_page_post_body,
        // classy_mode_appeal, default_thank_you_text …) is likewise a default
        // for some page or team rather than the campaign's own copy. Guessing
        // again would just import different wrong text.
        //
        // So GoFundMe Pro descriptions are left empty until the campaign probe
        // shows where the real copy lives. A manager writes one at approval,
        // and 2.7.0's empty-in-leave-alone rule then protects it from every
        // later refetch.
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
