<?php
/**
 * Shared frontend rendering helpers.
 *
 * These are used by both the [sfaf_calendar] cards and the single event
 * template so the markup stays in one place.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/* -------------------------------------------------------------------------
 * Embed context
 *
 * The same card markup is served two ways: inline on this site, and over the
 * public embed endpoint into a page on another domain. They are deliberately
 * the same markup so the two can't drift — but a few controls cannot work from
 * another origin and have to render differently.
 *
 * Anything that posts back to admin-ajax carries a WordPress nonce, which a
 * page on another domain can neither obtain nor have validated. RSVP and
 * reminder signup are therefore rendered as links to the event's page here,
 * where the real form lives. That also keeps every registration in one place.
 *
 * Set around a render, never globally:
 *
 *     sfaf_set_embed_context( true );
 *     $html = ...render...;
 *     sfaf_set_embed_context( false );
 * ---------------------------------------------------------------------- */

/**
 * Shared flag store for the embed rendering context.
 *
 * @return bool
 */
function &sfaf_embed_context_flag() {
    static $on = false;
    return $on;
}

/**
 * Turn embed rendering on or off for the current render.
 */
function sfaf_set_embed_context( $on ) {
    $flag =& sfaf_embed_context_flag();
    $flag = (bool) $on;
}

/**
 * Whether the current render is going out over the embed endpoint.
 */
function sfaf_is_embed_context() {
    $flag =& sfaf_embed_context_flag();
    return $flag;
}

/* -------------------------------------------------------------------------
 * SFAF icon set (brand guide v3.0, p.14)
 *
 * DESIGN SPEC — match these when adding an icon so the set stays one family:
 *
 *   Grid          24 x 24 viewBox.
 *   Live area     20 x 20 centred; keep artwork within x/y 2-22 so every icon
 *                 optically sizes the same next to text.
 *   Stroke        2px, round caps, round joins. No fills on interface icons.
 *   Corners       Rounded throughout — arcs and round joins, never mitred.
 *   Detail        Simplest shapes that still read; no interior detail that
 *                 collapses below ~16px.
 *   Color         currentColor only. Never hardcode a fill or stroke, so an
 *                 icon inherits whatever text color its context sets. This is
 *                 what lets the same icon sit on white, light gray and teal.
 *   Sizing        1em square by default; scales with the surrounding font-size.
 *
 * Third-party platform marks (Facebook, LinkedIn) are the one intentional
 * exception: they are solid rather than stroked, because those marks are
 * defined as solid shapes and stop being recognisable when redrawn as
 * outlines. They still use the same 24 grid, live area and currentColor.
 * ---------------------------------------------------------------------- */

/**
 * Inner markup for each icon, drawn to the spec above.
 *
 * @return array<string,string>
 */
function sfaf_icon_paths() {
    return array(
        // Interface icons — 2px stroke, round caps/joins, no fill.
        'clock'     => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/>',
        'pin'       => '<path d="M12 21c4.3-4.7 6.5-8.2 6.5-11a6.5 6.5 0 0 0-13 0c0 2.8 2.2 6.3 6.5 11z"/><circle cx="12" cy="10" r="2.5"/>',
        'calendar'  => '<rect x="3" y="5.5" width="18" height="15.5" rx="2.5"/><path d="M3 10.5h18M8 3v5M16 3v5"/>',
        'bell'      => '<path d="M18 10.5a6 6 0 0 0-12 0c0 4.5-2 6-2 6h16s-2-1.5-2-6z"/><path d="M13.7 19.5a2 2 0 0 1-3.4 0"/>',
        'heart'     => '<path d="M12 20.5 4.8 13.3a4.6 4.6 0 0 1 6.5-6.5l.7.7.7-.7a4.6 4.6 0 0 1 6.5 6.5z"/>',
        'handshake' => '<path d="M3 10.5 6.6 7a2.2 2.2 0 0 1 3.1 0L12 9.3l2.3-2.3a2.2 2.2 0 0 1 3.1 0L21 10.5"/><path d="M3 10.5v2.9l5.5 5a2 2 0 0 0 2.8 0l.7-.7"/><path d="M21 10.5v2.9l-5.5 5a2 2 0 0 1-2.8 0L12 17"/>',
        'repeat'    => '<path d="M17 2.5 21 6.5l-4 4"/><path d="M3 12.5v-2a4 4 0 0 1 4-4h14"/><path d="M7 21.5 3 17.5l4-4"/><path d="M21 11.5v2a4 4 0 0 1-4 4H3"/>',
        'hand'      => '<path d="M9 12V6.5a1.5 1.5 0 0 1 3 0V11"/><path d="M12 11V5.5a1.5 1.5 0 0 1 3 0V11"/><path d="M15 11.5V9a1.5 1.5 0 0 1 3 0v5.5A6.5 6.5 0 0 1 11.5 21 6.5 6.5 0 0 1 5 14.5V13a1.5 1.5 0 0 1 3 0v1"/>',
        'cloud'     => '<path d="M7 19a4.5 4.5 0 0 1-.6-9A6 6 0 0 1 17.7 9.7 4.2 4.2 0 0 1 17.2 19z"/>',
        'link'      => '<path d="M10.5 13.5a4.5 4.5 0 0 0 6.8.5l2.4-2.4a4.5 4.5 0 0 0-6.4-6.4l-1.4 1.4"/><path d="M13.5 10.5a4.5 4.5 0 0 0-6.8-.5L4.3 12.4a4.5 4.5 0 0 0 6.4 6.4l1.4-1.4"/>',
        'bolt'      => '<path d="M13 2.5 4.5 14H11l-1 7.5L19.5 10H13z"/>',
        'venue'     => '<path d="M3 21h18"/><path d="M12 3.5 4 8.5h16z"/><path d="M6.5 21v-9M10.2 21v-9M13.8 21v-9M17.5 21v-9"/>',
        'mail'      => '<rect x="3" y="5.5" width="18" height="13" rx="2.5"/><path d="m3.8 7 8.2 5.8L20.2 7"/>',
        'check'     => '<path d="M20 6.5 9.5 17 4 11.5"/>',
        'x'         => '<path d="M5.5 5.5 18.5 18.5M18.5 5.5 5.5 18.5"/>',
        'home'      => '<path d="M4 11 12 4l8 7"/><path d="M6.5 9.5V20h11V9.5"/>',
        'users'     => '<circle cx="9" cy="8" r="3"/><path d="M3.5 19a5.5 5.5 0 0 1 11 0"/><path d="M16 5.5a3 3 0 0 1 0 5"/><path d="M17 13.2a5.5 5.5 0 0 1 3.5 5.8"/>',
        'menu'      => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'palette'   => '<path d="M12 3.5a8.5 8.5 0 1 0 0 17c1.3 0 2-.9 2-2s.6-2 2-2h1.6a2.9 2.9 0 0 0 2.9-3A8.6 8.6 0 0 0 12 3.5z"/><circle cx="7.5" cy="11" r="1"/><circle cx="12" cy="8" r="1"/><circle cx="16.5" cy="11" r="1"/>',

        // Platform marks — solid, see note above.
        'facebook'  => '<path d="M13.3 21v-8h2.7l.4-3.1h-3.1V7.9c0-.9.25-1.5 1.55-1.5H16.5V3.6A21 21 0 0 0 14.1 3.5c-2.4 0-4 1.45-4 4.1v2.3H7.4V13h2.7v8z"/>',
        'linkedin'  => '<path d="M7.1 20H4.2V9.5h2.9zM5.65 8.2A1.7 1.7 0 1 1 5.65 4.8a1.7 1.7 0 0 1 0 3.4zM20 20h-2.9v-5.1c0-1.2 0-2.8-1.7-2.8s-2 1.35-2 2.7V20H10.5V9.5h2.8v1.45h.05A3.05 3.05 0 0 1 16.1 9.3c3 0 3.9 2 3.9 4.5z"/>',
    );
}

/**
 * Render an inline SVG icon from the SFAF set.
 *
 * Icons use currentColor, so they take the text color of wherever they sit.
 * Output is safe to echo directly — the markup is authored here, not user data.
 *
 * @param string $name Icon name, see sfaf_icon_paths().
 * @param array  $args {
 *     @type string $class Extra class names.
 *     @type string $size  CSS length for width/height. Default '1em'.
 *     @type string $label Accessible label. When empty the icon is decorative
 *                         and hidden from assistive tech.
 * }
 * @return string SVG markup, or '' for an unknown icon.
 */
function sfaf_icon( $name, $args = array() ) {
    $paths = sfaf_icon_paths();
    if ( ! isset( $paths[ $name ] ) ) {
        return '';
    }

    $args = array_merge( array( 'class' => '', 'size' => '1em', 'label' => '' ), (array) $args );

    // Platform marks are solid; every interface icon is stroked.
    $solid = in_array( $name, array( 'facebook', 'linkedin' ), true );

    $classes = trim( 'sfaf-icon sfaf-icon-' . $name . ' ' . $args['class'] );

    $svg  = '<svg class="' . esc_attr( $classes ) . '"';
    $svg .= ' width="' . esc_attr( $args['size'] ) . '" height="' . esc_attr( $args['size'] ) . '"';
    $svg .= ' viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"';
    if ( $solid ) {
        $svg .= ' fill="currentColor"';
    } else {
        $svg .= ' fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"';
    }
    if ( '' !== $args['label'] ) {
        $svg .= ' role="img" aria-label="' . esc_attr( $args['label'] ) . '"';
    } else {
        $svg .= ' aria-hidden="true" focusable="false"';
    }
    $svg .= '>' . $paths[ $name ] . '</svg>';

    return $svg;
}

/**
 * Whether a per-event display feature should be shown.
 * Defaults to true when the meta has never been saved (new events).
 *
 * @param int    $post_id Event ID.
 * @param string $feature One of: rsvp, donate, social, calendar, reminders.
 * @return bool
 */
function sfaf_show_feature( $post_id, $feature ) {
    $val = get_post_meta( $post_id, '_uc_show_' . $feature, true );
    if ( $val === '' ) {
        return true; // default on for new / never-saved events
    }
    return $val === '1';
}

/**
 * Build the start/end DateTime objects (in the site timezone) for an event.
 *
 * @return array|null array( DateTime $start, DateTime $end ) or null when no date.
 */
function sfaf_event_datetimes( $post_id ) {
    $date = get_post_meta( $post_id, '_uc_event_date', true );
    if ( ! $date ) {
        return null;
    }
    $start_time = get_post_meta( $post_id, '_uc_start_time', true );
    $end_time   = get_post_meta( $post_id, '_uc_end_time', true );
    $tz         = wp_timezone();

    try {
        $start = new DateTime( $date . ' ' . ( $start_time ?: '00:00' ), $tz );
    } catch ( Exception $e ) {
        return null;
    }

    $end = null;
    if ( $end_time ) {
        try {
            $end = new DateTime( $date . ' ' . $end_time, $tz );
        } catch ( Exception $e ) {
            $end = null;
        }
    }
    if ( ! $end ) {
        $end = clone $start;
        $end->modify( '+1 hour' );
    }

    return array( $start, $end );
}

/**
 * URL that triggers the .ics download for a single event.
 */
function sfaf_ics_url( $post_id ) {
    return add_query_arg( 'uc_ics', (int) $post_id, home_url( '/' ) );
}

/**
 * "Add to Google Calendar" URL for an event.
 */
function sfaf_google_calendar_url( $post_id ) {
    $dt = sfaf_event_datetimes( $post_id );
    if ( ! $dt ) {
        return '';
    }
    list( $start, $end ) = $dt;

    $utc       = new DateTimeZone( 'UTC' );
    $start_utc = clone $start; $start_utc->setTimezone( $utc );
    $end_utc   = clone $end;   $end_utc->setTimezone( $utc );

    $args = array(
        'action'   => 'TEMPLATE',
        'text'     => get_the_title( $post_id ),
        'dates'    => $start_utc->format( 'Ymd\THis\Z' ) . '/' . $end_utc->format( 'Ymd\THis\Z' ),
        'details'  => wp_strip_all_tags( get_the_excerpt( $post_id ) ),
        'location' => get_post_meta( $post_id, '_uc_location', true ),
    );

    return 'https://calendar.google.com/calendar/render?' . http_build_query( $args );
}

/**
 * Escape a value for inclusion in an .ics text field (RFC 5545).
 */
function sfaf_ics_escape( $value ) {
    $value = (string) $value;
    $value = str_replace( array( "\\", ";", "," ), array( "\\\\", "\\;", "\\," ), $value );
    $value = str_replace( array( "\r\n", "\n", "\r" ), "\\n", $value );
    return $value;
}

/**
 * Social share buttons (Facebook, X, LinkedIn, email).
 *
 * @param int  $post_id Event ID.
 * @param bool $compact Render the compact (card) variant.
 */
function sfaf_social_share_buttons( $post_id, $compact = false ) {
    if ( ! sfaf_show_feature( $post_id, 'social' ) ) {
        return '';
    }

    $url       = get_permalink( $post_id );
    $title     = get_the_title( $post_id );
    $enc_url   = rawurlencode( $url );
    $enc_title = rawurlencode( $title );

    $links = array(
        'facebook' => array(
            'label' => 'Facebook',
            'icon'  => 'facebook',
            'url'   => 'https://www.facebook.com/sharer/sharer.php?u=' . $enc_url,
        ),
        'x' => array(
            'label' => 'X',
            'icon'  => 'x',
            'url'   => 'https://twitter.com/intent/tweet?url=' . $enc_url . '&text=' . $enc_title,
        ),
        'linkedin' => array(
            'label' => 'LinkedIn',
            'icon'  => 'linkedin',
            'url'   => 'https://www.linkedin.com/sharing/share-offsite/?url=' . $enc_url,
        ),
        'email' => array(
            'label' => 'Email',
            'icon'  => 'mail',
            'url'   => 'mailto:?subject=' . $enc_title . '&body=' . rawurlencode( 'Check out this event: ' ) . $enc_url,
        ),
    );

    ob_start();
    ?>
    <div class="uc-share<?php echo $compact ? ' uc-share-compact' : ''; ?>">
        <span class="uc-share-label">Share:</span>
        <?php foreach ( $links as $key => $l ) : ?>
            <a class="uc-share-btn uc-share-<?php echo esc_attr( $key ); ?>"
               href="<?php echo esc_url( $l['url'] ); ?>"
               target="_blank" rel="noopener noreferrer"
               aria-label="Share on <?php echo esc_attr( $l['label'] ); ?>">
                <span class="uc-share-icon"><?php echo sfaf_icon( $l['icon'], array( 'size' => '15px' ) ); ?></span>
            </a>
        <?php endforeach; ?>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Donate / GoFundMe block: mock progress bar + outbound button.
 * Only renders when a campaign URL is set and the feature is enabled.
 */
function sfaf_donate_block( $post_id ) {
    $url = get_post_meta( $post_id, '_uc_gofundme_url', true );
    if ( ! $url || ! sfaf_show_feature( $post_id, 'donate' ) ) {
        return '';
    }

    $settings      = get_option( 'uc_settings', array() );
    $show_progress = ! isset( $settings['gofundme_show_progress'] ) || $settings['gofundme_show_progress'] === '1';

    // Real figures when we have them. A campaign imported from GoFundMe Pro
    // brings its goal, and its raised amount when that was available; those
    // are used in preference to the placeholder below.
    //
    // The 65% is a demo number that predates the integration. It is kept only
    // for events with no real raised figure, so nothing that used to render
    // changes — but where real data exists it is never overridden by a
    // made-up percentage.
    $goal       = (float) get_post_meta( $post_id, '_uc_gofundme_goal', true );
    $raised_raw = get_post_meta( $post_id, '_uc_gofundme_raised', true );
    $has_real   = ( '' !== $raised_raw && is_numeric( $raised_raw ) );

    if ( $has_real ) {
        $raised  = (float) $raised_raw;
        $percent = $goal > 0 ? (int) min( 100, round( $raised / $goal * 100 ) ) : 0;
    } else {
        $percent = 65;
        $raised  = $goal ? round( $goal * $percent / 100 ) : 0;
    }

    ob_start();
    ?>
    <div class="uc-donate-block">
        <?php if ( $show_progress ) : ?>
            <div class="uc-donate-progress">
                <div class="uc-donate-stats">
                    <?php if ( $goal ) : ?>
                        <span class="uc-donate-raised">$<?php echo number_format( $raised ); ?> raised</span>
                        <span class="uc-donate-goal">of $<?php echo number_format( $goal ); ?> goal</span>
                    <?php else : ?>
                        <span class="uc-donate-raised"><?php echo (int) $percent; ?>% to goal</span>
                    <?php endif; ?>
                </div>
                <div class="uc-donate-bar"><div class="uc-donate-fill" style="width: <?php echo (int) $percent; ?>%"></div></div>
            </div>
        <?php endif; ?>
        <a class="uc-donate-btn" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo sfaf_icon( 'heart' ); ?> Donate</a>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * "Add to Calendar" dropdown: Google Calendar link + .ics download.
 */
function sfaf_add_to_calendar( $post_id ) {
    if ( ! sfaf_show_feature( $post_id, 'calendar' ) ) {
        return '';
    }
    $google = sfaf_google_calendar_url( $post_id );
    $ics    = sfaf_ics_url( $post_id );
    if ( ! $google && ! $ics ) {
        return '';
    }

    ob_start();
    ?>
    <div class="uc-addcal">
        <button type="button" class="uc-addcal-toggle"><?php echo sfaf_icon( 'calendar' ); ?> Add to Calendar</button>
        <div class="uc-addcal-menu">
            <?php if ( $google ) : ?>
                <a href="<?php echo esc_url( $google ); ?>" target="_blank" rel="noopener noreferrer">Google Calendar</a>
            <?php endif; ?>
            <a href="<?php echo esc_url( $ics ); ?>">Apple / Outlook (.ics)</a>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * "Get Reminders" button (opens the reminder modal handled in calendar.js).
 */
function sfaf_reminders_button( $post_id ) {
    if ( ! sfaf_show_feature( $post_id, 'reminders' ) ) {
        return '';
    }

    // In an embed the modal can't submit cross-origin, so this becomes a link to
    // the event page where the form works — see the embed context notes above.
    if ( sfaf_is_embed_context() ) {
        return '<a class="uc-reminder-btn uc-embed-link" href="' . esc_url( get_permalink( $post_id ) ) . '">'
            . sfaf_icon( 'bell' ) . ' Get Reminders</a>';
    }

    ob_start();
    ?>
    <button type="button" class="uc-reminder-btn"
            data-event-id="<?php echo (int) $post_id; ?>"
            data-event-title="<?php echo esc_attr( get_the_title( $post_id ) ); ?>"><?php echo sfaf_icon( 'bell' ); ?> Get Reminders</button>
    <?php
    return ob_get_clean();
}

/**
 * RSVP block (capacity bar + button). Shared by card and single template.
 *
 * Only aggregate counts are ever rendered here — no attendee name, email or
 * phone reaches the markup, which is what makes this block safe to serve over
 * the public embed endpoint. In an embed the RSVP button becomes a link to the
 * event page, where the modal can talk to admin-ajax.
 */
function sfaf_rsvp_block( $post_id ) {
    if ( get_post_meta( $post_id, '_uc_rsvp_enabled', true ) !== '1' || ! sfaf_show_feature( $post_id, 'rsvp' ) ) {
        return '';
    }

    $capacity   = (int) get_post_meta( $post_id, '_uc_capacity', true );
    $rsvp_count = sfaf_get_rsvp_count( $post_id );

    ob_start();
    ?>
    <div class="uc-card-rsvp">
        <?php if ( $capacity > 0 ) : ?>
            <div class="uc-capacity-bar">
                <div class="uc-capacity-fill" style="width: <?php echo esc_attr( min( ( $rsvp_count / $capacity ) * 100, 100 ) ); ?>%"></div>
            </div>
            <span class="uc-capacity-text"><?php echo (int) $rsvp_count; ?>/<?php echo (int) $capacity; ?> spots filled</span>
        <?php else : ?>
            <span class="uc-capacity-text"><?php echo (int) $rsvp_count; ?> registered</span>
        <?php endif; ?>
        <?php if ( sfaf_is_embed_context() ) : ?>
            <a class="uc-rsvp-btn uc-embed-link" href="<?php echo esc_url( get_permalink( $post_id ) ); ?>">RSVP</a>
        <?php else : ?>
            <button class="uc-rsvp-btn" data-event-id="<?php echo (int) $post_id; ?>">RSVP</button>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Replace {tokens} in email templates with event/attendee data.
 *
 * @param string $text     Template text.
 * @param int    $event_id Event ID.
 * @param array  $data     RSVP data (name, email...).
 */
function sfaf_replace_tokens( $text, $event_id, $data = array() ) {
    $date     = get_post_meta( $event_id, '_uc_event_date', true );
    $date_fmt = $date ? date_i18n( get_option( 'date_format' ), strtotime( $date ) ) : '';

    $organizers = wp_get_post_terms( $event_id, 'uc_organizer', array( 'fields' => 'names' ) );
    $organizer  = ( ! is_wp_error( $organizers ) && ! empty( $organizers ) ) ? implode( ', ', $organizers ) : '';

    $replacements = array(
        '{event_name}'     => get_the_title( $event_id ),
        '{attendee_name}'  => isset( $data['name'] ) ? $data['name'] : '',
        '{event_date}'     => $date_fmt,
        '{event_time}'     => get_post_meta( $event_id, '_uc_start_time', true ),
        '{event_location}' => get_post_meta( $event_id, '_uc_location', true ),
        '{organizer_name}' => $organizer,
    );

    return strtr( $text, $replacements );
}

/* -------------------------------------------------------------------------
 * Recurring series helpers
 * ---------------------------------------------------------------------- */

/**
 * The series parent ID for an event (the parent points at itself). 0 if standalone.
 */
function sfaf_get_series_parent( $post_id ) {
    $parent = get_post_meta( $post_id, '_uc_series_parent', true );
    return $parent ? (int) $parent : 0;
}

/**
 * True when the event belongs to a series with more than one occurrence.
 */
function sfaf_is_in_series( $post_id ) {
    $parent = sfaf_get_series_parent( $post_id );
    if ( ! $parent ) {
        return false;
    }
    return count( sfaf_get_series_events( $parent ) ) > 1;
}

/**
 * Display name of a series (its parent event's title).
 */
function sfaf_get_series_name( $post_id ) {
    $parent = sfaf_get_series_parent( $post_id );
    return $parent ? get_the_title( $parent ) : '';
}

/**
 * Published events in a series (parent + children), ordered by date.
 *
 * @param int  $parent_id     Series parent ID.
 * @param bool $upcoming_only Limit to today-and-later.
 * @return int[] Post IDs.
 */
function sfaf_get_series_events( $parent_id, $upcoming_only = false ) {
    // Memoize per parent (+ upcoming flag) for the request: the event list and
    // series views call this once per row/card, which would otherwise fire a
    // separate query for every event in the same series.
    static $cache = array();
    $key = (int) $parent_id . '|' . ( $upcoming_only ? '1' : '0' );
    if ( isset( $cache[ $key ] ) ) {
        return $cache[ $key ];
    }
    $args = array(
        'post_type'      => 'uc_event',
        'post_status'    => 'publish',
        'posts_per_page' => 50,
        'fields'         => 'ids',
        'meta_key'       => '_uc_event_date',
        'orderby'        => 'meta_value',
        'order'          => 'ASC',
        'no_found_rows'  => true,
        'meta_query'     => array(
            'relation' => 'AND',
            array( 'key' => '_uc_series_parent', 'value' => $parent_id ),
        ),
    );
    if ( $upcoming_only ) {
        $args['meta_query'][] = array(
            'key'     => '_uc_event_date',
            'value'   => current_time( 'Y-m-d' ),
            'compare' => '>=',
            'type'    => 'DATE',
        );
    }
    $q = new WP_Query( $args );
    $cache[ $key ] = $q->posts;
    return $cache[ $key ];
}

/**
 * "Part of series: Name" link (links to the series parent). '' if standalone.
 */
function sfaf_series_link( $post_id ) {
    if ( ! sfaf_is_in_series( $post_id ) ) {
        return '';
    }
    $parent = sfaf_get_series_parent( $post_id );
    $name   = get_the_title( $parent );

    return '<a class="uc-series-link" href="' . esc_url( get_permalink( $parent ) ) . '">'
        . sfaf_icon( 'repeat' ) . ' Part of series: ' . esc_html( $name ) . '</a>';
}

/**
 * "Upcoming in this series" list for the single event template.
 */
function sfaf_series_list_html( $post_id ) {
    if ( ! sfaf_is_in_series( $post_id ) ) {
        return '';
    }
    $parent = sfaf_get_series_parent( $post_id );
    $events = sfaf_get_series_events( $parent, true );

    // Drop the event we're currently viewing.
    $events = array_values( array_filter( $events, function( $id ) use ( $post_id ) {
        return (int) $id !== (int) $post_id;
    } ) );

    if ( empty( $events ) ) {
        return '';
    }

    ob_start();
    ?>
    <div class="uc-series-box" data-series>
        <h3 class="uc-series-heading">Upcoming in this series</h3>
        <ul class="uc-series-list">
            <?php foreach ( $events as $eid ) :
                $d  = get_post_meta( $eid, '_uc_event_date', true );
                $st = get_post_meta( $eid, '_uc_start_time', true );
                $ts = $d ? strtotime( $d ) : false; ?>
                <li>
                    <a href="<?php echo esc_url( get_permalink( $eid ) ); ?>">
                        <span class="uc-series-date"><?php echo $ts ? esc_html( date_i18n( 'M j', $ts ) ) : ''; ?></span>
                        <span class="uc-series-title"><?php echo esc_html( get_the_title( $eid ) ); ?></span>
                        <?php if ( $st ) : ?><span class="uc-series-time"><?php echo esc_html( date( 'g:i A', strtotime( $st ) ) ); ?></span><?php endif; ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php
    return ob_get_clean();
}

/* -------------------------------------------------------------------------
 * Galaxy Digital (volunteer needs) helpers
 * ---------------------------------------------------------------------- */

/**
 * Whether an event was imported as a Galaxy Digital volunteer need.
 */
function sfaf_is_galaxy_need( $post_id ) {
    return (bool) get_post_meta( $post_id, '_uc_galaxy_need_id', true );
}

/**
 * Volunteer signup block for imported Galaxy Digital needs:
 * spots remaining (mocked from shifts[].slots) + a "Sign Up" link.
 */
function sfaf_galaxy_block( $post_id ) {
    $need_id = get_post_meta( $post_id, '_uc_galaxy_need_id', true );
    if ( ! $need_id ) {
        return '';
    }

    $settings = get_option( 'uc_settings', array() );
    $portal   = isset( $settings['galaxy_portal_url'] ) ? $settings['galaxy_portal_url'] : '';

    // Spots remaining come from shifts[].slots in production; mocked here.
    $slots = get_post_meta( $post_id, '_uc_galaxy_slots', true );
    $slots = ( $slots !== '' ) ? (int) $slots : 8;

    $signup = $portal
        ? trailingslashit( $portal ) . 'need/detail/?need_id=' . rawurlencode( $need_id )
        : '';

    ob_start();
    ?>
    <div class="uc-galaxy-block">
        <span class="uc-galaxy-spots"><?php echo sfaf_icon( 'hand' ); ?> <?php echo (int) $slots; ?> spots remaining</span>
        <?php if ( $signup ) : ?>
            <a class="uc-galaxy-btn" href="<?php echo esc_url( $signup ); ?>" target="_blank" rel="noopener noreferrer">Sign Up to Volunteer</a>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

/* -------------------------------------------------------------------------
 * Series + event FAQ helpers
 *
 * Canonical series FAQ lives on the series parent in _uc_series_faq (edited in
 * the Series Manager). Each event may add its own _uc_event_faq, which appends
 * to the series FAQ — or replaces it when _uc_faq_override is set.
 * ---------------------------------------------------------------------- */

/** Normalize a stored FAQ array, dropping empty rows. */
function sfaf_normalize_faqs( $raw ) {
    if ( ! is_array( $raw ) ) {
        return array();
    }
    $out = array();
    foreach ( $raw as $f ) {
        $q = isset( $f['question'] ) ? $f['question'] : '';
        $a = isset( $f['answer'] ) ? $f['answer'] : '';
        if ( $q === '' && $a === '' ) {
            continue;
        }
        $out[] = array( 'question' => $q, 'answer' => $a );
    }
    return $out;
}

/** All series parent event IDs (events that are their own _uc_series_parent). */
function sfaf_get_series_parents() {
    $q = new WP_Query( array(
        'post_type'      => 'uc_event',
        'post_status'    => array( 'publish', 'pending', 'draft', 'future', 'private' ),
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
        'meta_query'     => array( array( 'key' => '_uc_series_parent', 'compare' => 'EXISTS' ) ),
    ) );
    $parents = array();
    foreach ( $q->posts as $id ) {
        if ( (int) get_post_meta( $id, '_uc_series_parent', true ) === (int) $id ) {
            $parents[] = (int) $id;
        }
    }
    return $parents;
}

/** Number of occurrences in a series (parent + children, any status). */
function sfaf_series_count( $parent_id ) {
    $q = new WP_Query( array(
        'post_type'      => 'uc_event',
        'post_status'    => array( 'publish', 'pending', 'draft', 'future', 'private' ),
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
        'meta_query'     => array( array( 'key' => '_uc_series_parent', 'value' => $parent_id ) ),
    ) );
    return count( $q->posts );
}

/** Canonical series FAQ for an event (from its series parent, or itself). */
function sfaf_get_series_faq( $post_id ) {
    $parent = sfaf_get_series_parent( $post_id );
    $source = $parent ? $parent : $post_id;
    return sfaf_normalize_faqs( get_post_meta( $source, '_uc_series_faq', true ) );
}

/** Event-specific FAQ entries. */
function sfaf_get_event_faq( $post_id ) {
    return sfaf_normalize_faqs( get_post_meta( $post_id, '_uc_event_faq', true ) );
}

/** Whether this event replaces (vs appends to) the series FAQ. */
function sfaf_faq_is_override( $post_id ) {
    return get_post_meta( $post_id, '_uc_faq_override', true ) === '1';
}

/**
 * Effective FAQ for display: series FAQ + event-specific FAQ (or event-only
 * when the event sets the replace override). The series parent itself shows
 * just the series FAQ.
 *
 * @return array[] List of array( 'question' => string, 'answer' => string ).
 */
function sfaf_get_faqs( $post_id ) {
    $parent   = sfaf_get_series_parent( $post_id );
    $is_self  = ( $parent === (int) $post_id ); // series parent editing itself
    $series   = sfaf_get_series_faq( $post_id );
    $event    = $is_self ? array() : sfaf_get_event_faq( $post_id );
    $override = $is_self ? false : sfaf_faq_is_override( $post_id );

    return $override ? $event : array_merge( $series, $event );
}

/**
 * FAQ accordion markup for the single event page.
 */
function sfaf_faq_accordion_html( $post_id ) {
    $faqs = sfaf_get_faqs( $post_id );
    if ( empty( $faqs ) ) {
        return '';
    }

    ob_start();
    ?>
    <div class="uc-faq">
        <h3 class="uc-faq-heading">Frequently Asked Questions</h3>
        <div class="uc-faq-list">
            <?php foreach ( $faqs as $f ) : ?>
                <div class="uc-faq-item">
                    <button type="button" class="uc-faq-q" aria-expanded="false">
                        <span class="uc-faq-q-text"><?php echo esc_html( $f['question'] ); ?></span>
                        <span class="uc-faq-toggle" aria-hidden="true">+</span>
                    </button>
                    <div class="uc-faq-a"><?php echo wpautop( esc_html( $f['answer'] ) ); ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

/* -------------------------------------------------------------------------
 * Thumbnails & branded SVG placeholders
 * ---------------------------------------------------------------------- */

/**
 * Inner SVG shapes for a category icon (16:9 canvas 1600x900, centred ~800,380).
 */
function sfaf_event_icon_svg( $key, $color ) {
    switch ( $key ) {
        case 'people':
            return '<circle cx="690" cy="382" r="64"/><circle cx="910" cy="382" r="64"/><circle cx="800" cy="322" r="76"/>';
        case 'heart':
            return '<path d="M800 474 C742 408 660 366 660 304 C660 266 692 240 730 240 C763 240 787 261 800 290 C813 261 837 240 870 240 C908 240 940 266 940 304 C940 366 858 408 800 474 Z"/>';
        case 'cross':
            return '<rect x="762" y="296" width="76" height="190" rx="14"/><rect x="705" y="353" width="190" height="76" rx="14"/>';
        case 'hands':
            return '<rect x="724" y="358" width="152" height="120" rx="28"/><rect x="734" y="300" width="26" height="78" rx="13"/><rect x="772" y="286" width="26" height="92" rx="13"/><rect x="810" y="288" width="26" height="90" rx="13"/><rect x="848" y="304" width="26" height="74" rx="13"/>';
        case 'community':
            return '<circle cx="726" cy="330" r="50"/><circle cx="874" cy="330" r="50"/><circle cx="726" cy="450" r="50"/><circle cx="874" cy="450" r="50"/>';
        case 'calendar':
        default:
            return '<rect x="664" y="302" width="272" height="218" rx="18" fill="none" stroke="' . esc_attr( $color ) . '" stroke-width="18"/>'
                . '<line x1="664" y1="358" x2="936" y2="358" stroke="' . esc_attr( $color ) . '" stroke-width="18"/>'
                . '<rect x="716" y="278" width="20" height="46" rx="10" fill="' . esc_attr( $color ) . '"/>'
                . '<rect x="864" y="278" width="20" height="46" rx="10" fill="' . esc_attr( $color ) . '"/>';
    }
}

/**
 * Branded, category-matched 16:9 SVG placeholder (inline, no external files).
 */
function sfaf_event_placeholder_svg( $post_id ) {
    $cats = wp_get_post_terms( $post_id, 'uc_event_category' );
    $name = ( ! is_wp_error( $cats ) && ! empty( $cats ) ) ? $cats[0]->name : '';

    // Approved brand palette (brand guide v3.0, p.9). The third value is the
    // foreground: light brand backgrounds take the dark gray, dark ones take
    // white, so the label and icon stay legible.
    $map = array(
        'Support Groups'  => array( '#16BECF', 'people',    '#373433' ), // Teal
        'Fundraising'     => array( '#F04937', 'heart',     '#ffffff' ), // Red
        'Health Services' => array( '#8CC745', 'cross',     '#373433' ), // Green
        'Volunteer'       => array( '#8D54A2', 'hands',     '#ffffff' ), // Purple
        'Program Groups'  => array( '#FFD900', 'community', '#373433' ), // Yellow
    );

    if ( $name !== '' && isset( $map[ $name ] ) ) {
        $bg = $map[ $name ][0]; $icon = $map[ $name ][1]; $fg = $map[ $name ][2]; $label = $name;
    } else {
        $bg = '#373433'; $icon = 'calendar'; $fg = '#FFD900'; $label = $name !== '' ? $name : 'Event';
    }

    // The yellow brand accent would vanish on the yellow background, so fall
    // back to the dark gray in that one case.
    $accent = ( $bg === '#FFD900' ) ? '#373433' : '#FFD900';

    $svg  = '<svg class="uc-thumb-svg" viewBox="0 0 1600 900" preserveAspectRatio="xMidYMid slice" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="' . esc_attr( $label ) . '">';
    $svg .= '<rect width="1600" height="900" fill="' . esc_attr( $bg ) . '"/>';
    // SFAF yellow brand accents: top-right corner + left edge stripe.
    $svg .= '<polygon points="1600,0 1600,150 1450,0" fill="' . esc_attr( $accent ) . '"/>';
    $svg .= '<rect x="0" y="0" width="14" height="900" fill="' . esc_attr( $accent ) . '"/>';
    $svg .= '<g fill="' . esc_attr( $fg ) . '">' . sfaf_event_icon_svg( $icon, $fg ) . '</g>';
    $svg .= '<text x="800" y="664" text-anchor="middle" font-family="-apple-system, Segoe UI, Roboto, sans-serif" font-size="92" font-weight="700" fill="' . esc_attr( $fg ) . '">' . esc_html( $label ) . '</text>';
    $svg .= '</svg>';

    return $svg;
}

/**
 * Series-level image URL set on the series parent (attachment or manual URL).
 */
function sfaf_series_image_url( $parent_id ) {
    if ( ! $parent_id ) {
        return '';
    }
    // Memoize per parent: every child card/row resolves its image through the
    // same series parent, so without this each card re-queries the attachment.
    static $cache = array();
    $parent_id = (int) $parent_id;
    if ( isset( $cache[ $parent_id ] ) ) {
        return $cache[ $parent_id ];
    }
    $resolved = '';
    $att      = get_post_meta( $parent_id, '_uc_series_image_id', true );
    if ( $att ) {
        $url = wp_get_attachment_image_url( (int) $att, 'large' );
        if ( $url ) {
            $resolved = $url;
        }
    }
    if ( ! $resolved ) {
        $url      = get_post_meta( $parent_id, '_uc_series_image_url', true );
        $resolved = $url ? $url : '';
    }
    $cache[ $parent_id ] = $resolved;
    return $resolved;
}

/**
 * The ten approved SFAF brand colors (brand guide v3.0, p.9), in the order the
 * palette is presented on p.18.
 *
 * @return array<string,string> hex => human label.
 */
function sfaf_brand_palette() {
    return array(
        '#FFD900' => 'Yellow',     // Pantone 109 CP
        '#F7921E' => 'Orange',     // Pantone 144 CP
        '#F04937' => 'Red',        // Pantone 179 CP
        '#A30C33' => 'Burgundy',   // Pantone 201 CP
        '#F1668C' => 'Pink',       // Pantone 1915 CP
        '#8D54A2' => 'Purple',     // Pantone 258 CP
        '#16BECF' => 'Teal',       // Pantone 7710 UP
        '#8CC745' => 'Green',      // Pantone 3561 UP
        '#D1D3D4' => 'Light Gray', // Cool Gray 3 CP
        '#373433' => 'Dark Gray',  // Cool Gray 11 CP
    );
}

/**
 * The default category color when none is set.
 */
function sfaf_default_category_color() {
    return '#16BECF';
}

/**
 * Coerce a value to an approved brand color, falling back to the default.
 * Enforced on save so an off-palette hex can never be stored from our UI.
 */
function sfaf_sanitize_brand_color( $hex ) {
    $hex = strtoupper( trim( (string) $hex ) );
    return array_key_exists( $hex, sfaf_brand_palette() ) ? $hex : sfaf_default_category_color();
}

/**
 * Category color (with the SFAF teal default), memoized per term for the request.
 * Cards and filter chips look this up repeatedly for the same handful of terms.
 */
function sfaf_category_color( $term_id ) {
    static $cache = array();
    $term_id = (int) $term_id;
    if ( ! isset( $cache[ $term_id ] ) ) {
        $color = $term_id ? get_term_meta( $term_id, '_uc_category_color', true ) : '';
        $cache[ $term_id ] = $color ? $color : sfaf_default_category_color();
    }
    return $cache[ $term_id ];
}

/**
 * Effective image URL for an event, in priority order:
 * 1) the event's own featured image or _uc_image_url (both set by hand)
 * 2) _uc_external_image — whatever the third-party source last supplied
 * 3) the series-level image (_uc_series_image_* on the series parent)
 * 4) _uc_remote_image_url (synced from another site)
 * 5) '' (caller falls back to the SVG placeholder)
 *
 * The split at 1/2 is the whole point: a manually chosen image always beats
 * the source's, and a fetch refreshes the source's without ever touching the
 * manual one. Clear the manual image and the current source image shows again.
 */
function sfaf_event_image_url( $post_id ) {
    if ( has_post_thumbnail( $post_id ) ) {
        return get_the_post_thumbnail_url( $post_id, 'large' );
    }
    $own = get_post_meta( $post_id, '_uc_image_url', true );
    if ( $own ) {
        return $own;
    }
    $external = get_post_meta( $post_id, '_uc_external_image', true );
    if ( $external ) {
        return $external;
    }
    $series = sfaf_series_image_url( sfaf_get_series_parent( $post_id ) );
    if ( $series ) {
        return $series;
    }
    $remote = get_post_meta( $post_id, '_uc_remote_image_url', true );
    return $remote ? $remote : '';
}

/**
 * Where the displayed image comes from:
 * 'event' | 'source' | 'series' | 'remote' | 'none'.
 * Used for the "From series" / "Event-specific" label on edit screens.
 */
function sfaf_event_image_source( $post_id ) {
    if ( has_post_thumbnail( $post_id ) || get_post_meta( $post_id, '_uc_image_url', true ) ) {
        return 'event';
    }
    if ( get_post_meta( $post_id, '_uc_external_image', true ) ) {
        return 'source';
    }
    if ( sfaf_series_image_url( sfaf_get_series_parent( $post_id ) ) ) {
        return 'series';
    }
    if ( get_post_meta( $post_id, '_uc_remote_image_url', true ) ) {
        return 'remote';
    }
    return 'none';
}

/**
 * The event's image markup following the inheritance chain, or the branded SVG
 * placeholder when nothing is available.
 */
function sfaf_event_thumbnail( $post_id, $size = 'large' ) {
    if ( has_post_thumbnail( $post_id ) ) {
        return get_the_post_thumbnail( $post_id, $size, array( 'class' => 'uc-thumb-img', 'loading' => 'lazy' ) );
    }
    $url = sfaf_event_image_url( $post_id ); // featured already handled above
    if ( $url ) {
        return '<img class="uc-thumb-img" src="' . esc_url( $url ) . '" alt="' . esc_attr( get_the_title( $post_id ) ) . '" loading="lazy" />';
    }
    return sfaf_event_placeholder_svg( $post_id );
}
