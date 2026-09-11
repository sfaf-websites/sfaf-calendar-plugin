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
 * WHERE AN EVENT'S LINKS GO: here, or straight to the source.
 *
 * THE DEFAULT IS UNCHANGED AND DELIBERATE. A third-party event opens the
 * resources event page, which carries the map, the series context and add to
 * calendar, and hands off to the source when somebody registers. That page
 * earns its place for most blocks.
 *
 * It earns nothing for some. A Cycle to Zero block exists to send people to
 * donate.sfaf.org, and an intermediate page there is a step that costs
 * conversions and adds nothing. So a block may opt in, per block, and the
 * setting travels on the pasted snippet as a data attribute rather than through
 * a new REST route: SFAF_Embed::is_embed_request() matches the route string
 * exactly, so a new route would fail CORS from sfaf.org.
 *
 * FLIPPING THE DEFAULT IS ONE LINE, in sfaf_source_links_default() below. Mark
 * may make this the default after talking to Eric, and when that happens the
 * generator checkbox, the shortcode attribute and every renderer follow from
 * that one function because none of them carries its own idea of the default.
 * ---------------------------------------------------------------------- */

/**
 * The shipped default for "open events to their source listing".
 *
 * ONE LINE, ON PURPOSE. Return true and every surface changes together.
 *
 * @return bool
 */
function sfaf_source_links_default() {
    return false;
}

/** Shared flag store for the current render's link destination. */
function &sfaf_source_links_flag() {
    static $on = null;
    return $on;
}

/**
 * Turn source linking on or off for the current render.
 *
 * Pass null to go back to the shipped default, which is what a render that
 * says nothing gets.
 */
function sfaf_set_source_links( $on ) {
    $flag =& sfaf_source_links_flag();
    $flag = ( null === $on ) ? null : (bool) $on;
}

/** Whether this render sends events to their source listing. */
function sfaf_use_source_links() {
    $flag =& sfaf_source_links_flag();
    return ( null === $flag ) ? sfaf_source_links_default() : $flag;
}

/**
 * The URL of this event's listing on the platform it came from, or ''.
 *
 * A native event has none, which is the whole reason the setting can only ever
 * apply to some of a block's events.
 *
 * @param int $post_id
 * @return string
 */
function sfaf_event_source_url( $post_id ) {
    return (string) get_post_meta( (int) $post_id, '_uc_source_url', true );
}

/**
 * WHERE THIS EVENT'S LINKS POINT. One function, every renderer.
 *
 * Every card title, card image, View event button, compact card, sidebar row
 * and month-grid day link goes through this, which is what makes the setting
 * reach every display mode rather than the ones somebody remembered. A renderer
 * that called get_permalink() directly would be a mode where the setting
 * silently did nothing.
 *
 * A NATIVE EVENT ALWAYS GETS ITS EVENT PAGE, whatever the setting says. It has
 * nowhere else to go, so there is no decision to make and no explaining to do
 * on a block that mixes native and imported events.
 *
 * @param int $post_id
 * @return string
 */
function sfaf_event_link( $post_id ) {
    if ( sfaf_use_source_links() ) {
        $source = sfaf_event_source_url( $post_id );
        if ( '' !== $source ) {
            return $source;
        }
    }
    return (string) get_permalink( (int) $post_id );
}

/**
 * Whether sfaf_event_link() is about to send somebody off this site.
 *
 * Drives the small external marker on the source byline. Asked as its own
 * question rather than by comparing URLs, because a renderer comparing strings
 * is a renderer that can get it wrong.
 *
 * @param int $post_id
 * @return bool
 */
function sfaf_event_link_is_external( $post_id ) {
    return sfaf_use_source_links() && '' !== sfaf_event_source_url( $post_id );
}

/**
 * The "opens on the platform" marker, or ''.
 *
 * SMALL, AND ONLY WHERE IT IS TRUE. A visitor should not be surprised to land
 * on donate.sfaf.org, and that is the whole of the requirement: one arrow
 * beside the name of the platform they are going to, on the cards that actually
 * leave. Repeating it on the button as well would be the noise this is trying
 * not to be.
 *
 * @param int $post_id
 * @return string
 */
function sfaf_external_marker( $post_id ) {
    if ( ! sfaf_event_link_is_external( $post_id ) ) {
        return '';
    }
    // aria-hidden with a real sentence beside it: the arrow is decoration and
    // the words are what a screen reader announces.
    return ' <span class="uc-external-mark" aria-hidden="true">&#8599;</span>'
        . '<span class="uc-sr-only"> (opens on the event\'s own site)</span>';
}

/**
 * What makes a link to an event open in a new tab.
 *
 * ONE PLACE, FOR THE SAME REASON sfaf_event_link() IS ONE PLACE. Four
 * renderers draw a link to an event, and a rule written into three of them is
 * a display mode where the behaviour silently differs.
 *
 * WHY A NEW TAB AT ALL. The calendar renders inside somebody else's page, and
 * a visitor reading a list of events keeps their place by closing the tab
 * rather than by finding their way back. That is also why this is `_blank` and
 * never `_top`: `_top` would replace the whole window the embed is sitting in.
 *
 * rel="noopener" AND DELIBERATELY NOT "noreferrer". noopener is the security
 * half: it stops the opened page reaching back through window.opener. noreferrer
 * ALSO suppresses the Referer header, and the event page reads exactly that
 * header to work out which calendar somebody came from. See
 * sfaf_calendar_referrer(). Adding it would break "All Events" on every event
 * opened from a card, which is the thing 3.66.0 was for.
 *
 * @return string Attributes, with a leading space, ready to concatenate.
 */
function sfaf_new_tab_attrs() {
    return ' target="_blank" rel="noopener"';
}

/**
 * The sentence that says a link opens a new tab.
 *
 * A LINK THAT MOVES SOMEBODY TO A NEW TAB HAS TO SAY SO. It is the same
 * pattern sfaf_external_marker() already uses on this codebase: a real
 * sentence in a visually hidden span, clipped rather than display:none so it
 * is announced, sitting inside the link so it becomes part of the link's own
 * name.
 *
 * NOT A TITLE ATTRIBUTE. A title is not announced reliably, is not reachable
 * by touch, and vanishes on a keyboard. NOT AN ICON EITHER: the arrow this
 * codebase already draws means "this leaves the site", which is a different
 * fact, and drawing it on every card would say the wrong thing on most of them.
 *
 * @return string
 */
function sfaf_new_tab_note() {
    return '<span class="uc-sr-only"> (opens in a new tab)</span>';
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
        'help'      => '<circle cx="12" cy="12" r="9"/><path d="M9.6 9.4a2.5 2.5 0 1 1 3.3 2.4c-.6.2-.9.8-.9 1.4v.6"/><path d="M12 17h.01"/>',
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
        // A picture: frame, sun, horizon. For the caladmin Images screen.
        'image'     => '<rect x="3" y="5" width="18" height="14" rx="2.5"/><circle cx="8.5" cy="10" r="1.5"/><path d="m3.5 16.5 4.5-4 3.5 3 3-2.5 6 5"/>',

        /*
         * NINE MORE FOR CATEGORIES (3.75.0), and the reason is a collision
         * rather than a shortage. Seven categories were sharing five icons:
         * Espanol and Program Groups both drew 'community', so two kinds of
         * event were indistinguishable on every placeholder they produced.
         *
         * WHAT THESE ARE FOR, one line each, because an icon set with no
         * intent behind it grows into a clipart folder:
         *
         *   speech   a conversation. Support groups, drop-ins, a language.
         *   globe    a language or somewhere else. The other half of speech.
         *   book     something taught. Workshops, training, education.
         *   meal     food is the event. Community meals, breakfasts.
         *   music    the arts, and a social night.
         *   bike     a ride. This calendar runs Cycle to Zero.
         *   shield   prevention, safety, testing.
         *   star     a highlight, when nothing else fits and it matters.
         *   flag     advocacy and a campaign.
         *
         * Same 2px stroke, round caps and joins, no fill, on the 24 grid, so
         * they sit beside the existing ones rather than beside each other.
         */
        'speech'    => '<path d="M20.5 12.5a7.5 7.5 0 0 1-7.5 7.5 8.4 8.4 0 0 1-3.3-.66L4 21l1.7-4.6A7.3 7.3 0 0 1 5 12.5 7.5 7.5 0 0 1 12.5 5 7.5 7.5 0 0 1 20.5 12.5z"/>',
        'globe'     => '<circle cx="12" cy="12" r="9"/><path d="M3.5 9.5h17M3.5 14.5h17"/><path d="M12 3a15 15 0 0 1 0 18 15 15 0 0 1 0-18z"/>',
        'book'      => '<path d="M4 4.5h6a3 3 0 0 1 3 3V20a2.5 2.5 0 0 0-2.5-2.5H4z"/><path d="M20 4.5h-6a3 3 0 0 0-3 3V20a2.5 2.5 0 0 1 2.5-2.5H20z"/>',
        'meal'      => '<path d="M6 3v8a2.5 2.5 0 0 0 5 0V3"/><path d="M8.5 11v10"/><path d="M17.5 3c-1.7 1-2.5 3-2.5 5.5s.8 3.5 2.5 3.5"/><path d="M17.5 12v9"/>',
        'music'     => '<path d="M9 18V6.5l10-2V16"/><circle cx="6.5" cy="18" r="2.5"/><circle cx="16.5" cy="16" r="2.5"/>',
        'bike'      => '<circle cx="5.5" cy="17.5" r="3.5"/><circle cx="18.5" cy="17.5" r="3.5"/><path d="M5.5 17.5 10 8h4"/><path d="m10 8 4.5 9.5M14 8h3.5"/>',
        'shield'    => '<path d="M12 3 5 6v6c0 4.2 2.9 7.6 7 9 4.1-1.4 7-4.8 7-9V6z"/>',
        'star'      => '<path d="m12 3.5 2.7 5.6 6.1.8-4.5 4.2 1.2 6-5.5-3-5.5 3 1.2-6L3.2 9.9l6.1-.8z"/>',
        'flag'      => '<path d="M5 21V4"/><path d="M5 4.5h10.5l-1.5 3.5 1.5 3.5H5z"/>',

        /*
         * WHERE 'pin' WOULD GO ON AN ONLINE EVENT. A map pin next to the words
         * "Online Event" says the opposite of what the line says, so the fact
         * row swaps the glyph rather than dropping it and leaving the row
         * ragged against the ones above it. Same 24 grid, same 2px stroke.
         */
        'video'     => '<rect x="3" y="6.5" width="12.5" height="11" rx="2.5"/><path d="M15.5 11 21 8v8l-5.5-3z"/>',
        'ticket'    => '<path d="M4 8.5a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v1.6a2.4 2.4 0 0 0 0 4.8v1.6a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-1.6a2.4 2.4 0 0 0 0-4.8z"/><path d="M14 7v2M14 11v2M14 15v2"/>',
        'mail'      => '<rect x="3" y="5.5" width="18" height="13" rx="2.5"/><path d="m3.8 7 8.2 5.8L20.2 7"/>',
        'check'     => '<path d="M20 6.5 9.5 17 4 11.5"/>',
        'x'         => '<path d="M5.5 5.5 18.5 18.5M18.5 5.5 5.5 18.5"/>',
        'home'      => '<path d="M4 11 12 4l8 7"/><path d="M6.5 9.5V20h11V9.5"/>',
        'users'     => '<circle cx="9" cy="8" r="3"/><path d="M3.5 19a5.5 5.5 0 0 1 11 0"/><path d="M16 5.5a3 3 0 0 1 0 5"/><path d="M17 13.2a5.5 5.5 0 0 1 3.5 5.8"/>',
        'menu'      => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'palette'   => '<path d="M12 3.5a8.5 8.5 0 1 0 0 17c1.3 0 2-.9 2-2s.6-2 2-2h1.6a2.9 2.9 0 0 0 2.9-3A8.6 8.6 0 0 0 12 3.5z"/><circle cx="7.5" cy="11" r="1"/><circle cx="12" cy="8" r="1"/><circle cx="16.5" cy="11" r="1"/>',
        'cross'     => '<path d="M9.8 3.5h4.4v6.3h6.3v4.4h-6.3v6.3H9.8v-6.3H3.5V9.8h6.3z"/>',
        'community' => '<circle cx="8" cy="8" r="2.6"/><circle cx="16" cy="8" r="2.6"/><circle cx="8" cy="16" r="2.6"/><circle cx="16" cy="16" r="2.6"/>',
        'arrow'     => '<path d="M4.5 12h14"/><path d="m12.5 6 6 6-6 6"/>',
        // "Edit this one date", on every upcoming row of a series schedule, and
        // Edit on every row of the events list from 3.73.0.
        'pencil'    => '<path d="M4 20h4L19.5 8.5a2.1 2.1 0 0 0-3-3L5 17z"/><path d="M14.5 6.5l3 3"/>',

        /*
         * ONE SHEET BEHIND ANOTHER (3.73.0). The events list's Duplicate.
         *
         * NOT THE 'copy' METAPHOR OF A CLIPBOARD, which means "take this text",
         * and not 'repeat', which on this calendar means "this happens again on
         * a schedule" and sits on the same screen. Two overlapping panels is the
         * one shape that reads as "make another of these" without colliding with
         * either.
         */
        'duplicate' => '<rect x="8.5" y="8.5" width="12" height="12" rx="2.5"/><path d="M15.5 5.5H6A2.5 2.5 0 0 0 3.5 8v9.5"/>',

        /*
         * THE BELL, STRUCK THROUGH (3.73.0). "Cancel this event", on a row
         * whose event has registrations and therefore cannot be removed.
         *
         * IT MUST NOT BE A SECOND CROSS. Remove is 'x', and two crosses side by
         * side on one row would read as two ways of doing the same thing.
         * Cancelling is close to the opposite: the event stays, its
         * registrations stay, and the people who signed up get told. The bell
         * is this plugin's own glyph for "somebody is notified about this", so
         * a struck bell is "it is off, and they will hear".
         *
         * The slash runs corner to corner across the live area so it still
         * reads at 17px, which is the size the actions column draws it at.
         */
        'bell-off'  => '<path d="M18 10.5a6 6 0 0 0-9.3-5"/><path d="M6.2 8.4A6 6 0 0 0 6 10.5c0 4.5-2 6-2 6h12"/><path d="M13.7 19.5a2 2 0 0 1-3.4 0"/><path d="M3.5 3.5l17 17"/>',

        /*
         * A PADLOCK, FOR A ROW THAT CAN DO NEITHER (3.73.0). An imported event
         * with registrations is removed and cancelled at its source, so the
         * events list shows this as a STATE rather than offering a control that
         * would be refused.
         *
         * SFAF_Portal::icon_lock() draws a filled padlock of its own for the
         * locked FIELD badge. That one is 14px, solid, and lives inside a
         * sentence; this is the 2px stroked family member that sits in a row of
         * interface icons. Two glyphs, two contexts, and neither is a candidate
         * for the other's place.
         */
        'lock'      => '<rect x="4.5" y="10.5" width="15" height="10" rx="2.5"/><path d="M8 10.5V7.5a4 4 0 0 1 8 0v3"/><path d="M12 14.5v2"/>',

        /*
         * A CLOSED LOOP WITH ONE ARROWHEAD, not the two-arrow 'repeat' above.
         * 'repeat' means "this happens again on a schedule" and is what a
         * recurring event wears; this one means "pull it again, now", and the
         * two must not look alike on a screen that shows both. Used on Fetch
         * updates and Refresh from source.
         */
        'refresh'   => '<path d="M20 11.5a8 8 0 1 0-.9 5.2"/><path d="M20 4.5v7h-7"/>',

        // The disclosure marker. Points right when closed, and the rules that
        // use it rotate it rather than swapping the glyph, so the movement is
        // what says "this opened".
        'chevron'   => '<path d="m9 5 7 7-7 7"/>',

        // The field marker, and the counterpart to the one above: a search box
        // carries this and a dropdown carries the chevron, which is what tells
        // the two apart before either is clicked. Drawn on the same 24 grid.
        'search'    => '<circle cx="10.5" cy="10.5" r="6.5"/><path d="m15.4 15.4 4.6 4.6"/>',

        // Platform marks — solid, see note above.
        'facebook'  => '<path d="M13.3 21v-8h2.7l.4-3.1h-3.1V7.9c0-.9.25-1.5 1.55-1.5H16.5V3.6A21 21 0 0 0 14.1 3.5c-2.4 0-4 1.45-4 4.1v2.3H7.4V13h2.7v8z"/>',
        'linkedin'  => '<path d="M7.1 20H4.2V9.5h2.9zM5.65 8.2A1.7 1.7 0 1 1 5.65 4.8a1.7 1.7 0 0 1 0 3.4zM20 20h-2.9v-5.1c0-1.2 0-2.8-1.7-2.8s-2 1.35-2 2.7V20H10.5V9.5h2.8v1.45h.05A3.05 3.05 0 0 1 16.1 9.3c3 0 3.9 2 3.9 4.5z"/>',
    );
}

/**
 * THE ONE BUTTON. Every primary action the calendar renders, anywhere.
 *
 * WHY THIS IS A FUNCTION AND NOT A CLASS NAME IN FOUR TEMPLATES. Until 3.2.0
 * the list card had one button, the event page had four, and no two of them
 * agreed: the card's was a teal outline pill with a sliding arrow, RSVP was a
 * solid teal rectangle with dark text, Donate was solid green, Sign Up was
 * solid purple, and each had its own hover, its own radius and its own idea
 * of a focus state. A visitor moving from the list to an event page met a
 * different button language on arrival.
 *
 * Everything now comes through here, so a change to the family is one edit
 * and cannot be applied to three of the five by accident.
 *
 * TWO WEIGHTS, AND THEY DO NOT COMPETE.
 *   primary   solid fill, white label. The money or commitment action:
 *             Donate, RSVP, Sign Up to Volunteer. One per surface.
 *   secondary outline, coloured label. Navigation: View event.
 * Both are measured against WCAG AA at 13px; the numbers are in calendar.css
 * beside the rules that set them.
 *
 * THE ARROW is decorative, aria-hidden, and slides in on hover and on focus.
 * There is no hover on a touch screen, so the label alone has to say what the
 * button does. That is why "View event" and "Donate" are the words rather
 * than "Learn more".
 *
 * @param array $args {
 *     @type string $label    Visible text. Required; an empty label renders ''.
 *     @type string $href     Destination. With one, this is an <a>; without,
 *                            a type="button" for a script to pick up.
 *     @type string $variant  'primary' or 'secondary'. Default 'secondary'.
 *     @type string $class    Extra classes, e.g. a JS hook.
 *     @type array  $attrs    Extra attributes as name => value.
 *     @type bool   $external Off this site: a new tab, noopener and noreferrer.
 *     @type bool   $new_tab  A new tab on this site: noopener, and the referrer
 *                            kept, because the event page reads it. See
 *                            sfaf_new_tab_attrs().
 * }
 * @return string
 */
function sfaf_action_button( $args = array() ) {
    $args = array_merge( array(
        'label'    => '',
        'href'     => '',
        'variant'  => 'secondary',
        'class'    => '',
        'attrs'    => array(),
        'external' => false,
        'new_tab'  => false,
    ), (array) $args );

    $label = trim( (string) $args['label'] );
    if ( '' === $label ) {
        return '';
    }

    $classes = 'uc-actionbtn uc-actionbtn-' . ( 'primary' === $args['variant'] ? 'primary' : 'secondary' );
    if ( '' !== trim( (string) $args['class'] ) ) {
        $classes .= ' ' . trim( (string) $args['class'] );
    }

    $is_link = ( '' !== (string) $args['href'] );
    $tag     = $is_link ? 'a' : 'button';

    $out     = '<' . $tag . ' class="' . esc_attr( $classes ) . '"';
    $new_tab = false;
    if ( $is_link ) {
        $out .= ' href="' . esc_url( $args['href'] ) . '"';
        if ( ! empty( $args['external'] ) ) {
            $out    .= ' target="_blank" rel="noopener noreferrer"';
            $new_tab = true;
        } elseif ( ! empty( $args['new_tab'] ) ) {
            $out    .= sfaf_new_tab_attrs();
            $new_tab = true;
        }
    } else {
        $out .= ' type="button"';
    }
    foreach ( (array) $args['attrs'] as $name => $value ) {
        $out .= ' ' . esc_attr( $name ) . '="' . esc_attr( $value ) . '"';
    }
    $out .= '>';
    $out .= '<span class="uc-actionbtn-label">' . esc_html( $label ) . '</span>';
    /* A button that opens a new tab says so, in the link's own name, whichever
     * of the two reasons it is doing it for. */
    if ( $new_tab ) {
        $out .= sfaf_new_tab_note();
    }
    $out .= '<span class="uc-actionbtn-arrow" aria-hidden="true">' . sfaf_icon( 'arrow', array( 'size' => '15px' ) ) . '</span>';
    $out .= '</' . $tag . '>';

    return $out;
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
 * A post status as a person would say it.
 *
 * WordPress stores statuses as machine slugs and the portal was printing them
 * with ucfirst(), which produced "Publish" and "Future" in a column headed
 * Status. "Publish" is an instruction, not a state, and "Future" tells nobody
 * that the event is scheduled to appear.
 *
 * The plugin's own two statuses take the wording they were registered with in
 * SFAF_Sources::register_statuses(), so the queue screens and this label can
 * never disagree about what an imported event is called. Anything unrecognised
 * falls back to the registered object's label, and only then to a tidied slug,
 * so a status added later reads properly without touching this function.
 *
 * @param string $status Raw status slug.
 * @return string
 */
function sfaf_status_label( $status ) {
    $status = (string) $status;

    $known = array(
        'publish'      => 'Published',
        'draft'        => 'Draft',
        'pending'      => 'Pending',
        'future'       => 'Scheduled',
        'private'      => 'Private',
        'trash'        => 'Trash',
        'auto-draft'   => 'Not started',
        'inherit'      => 'Revision',
        'uc_imported'  => 'Imported, pending review',
        'uc_dismissed' => 'Dismissed',
    );
    if ( isset( $known[ $status ] ) ) {
        return $known[ $status ];
    }

    $object = get_post_status_object( $status );
    if ( $object && ! empty( $object->label ) ) {
        return $object->label;
    }
    return ucfirst( str_replace( array( '_', '-' ), ' ', $status ) );
}

/**
 * How an RSVP row's status reads. Same idea, different vocabulary: these are
 * this plugin's own values in its own table, not WordPress post statuses.
 *
 * @param string $status
 * @return string
 */
function sfaf_rsvp_status_label( $status ) {
    /*
     * 'subscribed' IS NOT HERE ANY MORE, and the catch-all below is why nothing
     * had to be migrated. That value went in 3.53.0 with the button that wrote
     * it; the calendar has not launched, so there are no rows carrying it. A row
     * that somehow did would render as "Subscribed" rather than as a blank cell,
     * which is the catch-all doing the job it is last in the list for.
     */
    $known = array(
        'confirmed' => 'Registered',
        'cancelled' => 'Canceled',
    );
    $status = (string) $status;
    return isset( $known[ $status ] ) ? $known[ $status ] : ucfirst( str_replace( '_', ' ', $status ) );
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
 * Whether this event takes registrations.
 *
 * ONE PREDICATE, THREE CALLERS, AND A FOURTH IN CALADMIN (3.64.0). Two things
 * have to be true and they are different kinds of thing: the manager switched
 * registration on, and the Display card is showing the control. Both were
 * written out longhand in sfaf_rsvp_block(), sfaf_rsvp_spots_text() and the
 * volunteer variant, so a question asked in four places had four answers that
 * only happened to agree. It is asked in a fifth now, by the Add to Calendar
 * button, which must not appear when this is true, so the four have to become
 * one before they can disagree.
 *
 * IT IS NOT CANCELLATION-AWARE, deliberately. A cancelled event still takes no
 * registrations, but the reason is the cancellation and the refusal is
 * SFAF_RSVP's at the write. This answers only "is this an event people sign up
 * for", which is what decides whether the calendar file goes out with the
 * confirmation instead of from the page.
 *
 * @param int $post_id
 * @return bool
 */
function sfaf_event_takes_rsvps( $post_id ) {
    return '1' === (string) get_post_meta( $post_id, '_uc_rsvp_enabled', true )
        && sfaf_show_feature( $post_id, 'rsvp' );
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
 * HTML to one line of readable text, with the block boundaries kept as spaces.
 *
 * WHY THIS EXISTS. wp_trim_words() strips tags by calling strip_tags(), which
 * joins whatever was either side of a tag with nothing in between:
 * "<p>One</p><p>Two</p>" comes out as "OneTwo". Event descriptions were plain
 * text until 3.38.0, so no card ever showed it. The day rich text shipped, every
 * card summary spanning two paragraphs would have run the last word of one into
 * the first word of the next, on the public calendar and in every embed.
 *
 * A block tag becomes a space BEFORE the tags are stripped, which is the only
 * order that works: afterwards there is nothing left to tell where the boundary
 * was. Inline tags are left alone, because a space inside <strong>one</strong>
 * word would be just as wrong in the other direction.
 *
 * @param string $html
 * @return string
 */
function sfaf_flatten_html( $html ) {
    $html = (string) $html;
    if ( '' === $html ) {
        return '';
    }

    $blocks = 'p|div|br|li|ul|ol|h[1-6]|blockquote|tr|td|th|section|article|header|footer|hr|pre';
    $html   = preg_replace( '#<\s*/?\s*(' . $blocks . ')\b[^>]*>#i', ' ', $html );

    $text = wp_strip_all_tags( $html );

    // Whatever the collapsing produced, one space between words.
    return trim( preg_replace( '/\s+/u', ' ', $text ) );
}

/**
 * URL that triggers the .ics download for a single event.
 */
function sfaf_ics_url( $post_id ) {
    $url = add_query_arg( 'uc_ics', (int) $post_id, home_url( '/' ) );

    // A private event's .ics is addressed by its token as well as its id, so an
    // id nobody was given cannot be walked. See sfaf_output_ics(). The token is
    // the slug, which whoever is looking at this page already has.
    if ( SFAF_Privacy::is_private( $post_id ) ) {
        $post = get_post( (int) $post_id );
        if ( $post && '' !== $post->post_name ) {
            $url = add_query_arg( 'k', $post->post_name, $url );
        }
    }

    return $url;
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
        // Flattened, not stripped: see sfaf_flatten_html(). Google shows this
        // as the event's notes, and joined words are what stripping gives.
        'details'  => sfaf_flatten_html( get_the_excerpt( $post_id ) ),
        'location' => sfaf_event_location( $post_id ),
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
 * Donate / GoFundMe block: progress bar (only with real figures) + outbound
 * button. Only renders when a campaign URL is set and the feature is enabled.
 *
 * NO INVENTED FUNDRAISING NUMBERS. EVER.
 * ---------------------------------------------------------------------------
 * Until 2.9.0 this block fell back to a hardcoded 65% when it had no raised
 * figure, and then multiplied the goal by it to print "$65,000 raised". That
 * number was a design placeholder from before the GoFundMe Pro integration
 * existed. It was not an estimate, a projection or a rounding: it was made up,
 * and it was rendered to donors, on a nonprofit's public pages, next to a real
 * goal, in a way nobody reading it could tell from a real total.
 *
 * How it got there does not matter. It is gone.
 *
 * The rule now: a raised amount is displayed if and only if a real one has
 * been stored, and the progress bar is drawn if and only if there is a real
 * amount AND a goal to measure it against. There is no fallback percentage, no
 * assumed figure, and no bar drawn from one. An event with a goal and no
 * raised figure shows the goal by itself, which is a true statement; an event
 * with neither shows the Donate button alone.
 *
 * _uc_gofundme_raised is only ever written by the GoFundMe Pro importer from
 * the campaign's own gross_amount (see SFAF_Source_GFMP::raised_amount), so
 * "we have a figure" and "the platform told us the figure" are the same thing.
 */
function sfaf_donate_block( $post_id ) {
    $url = get_post_meta( $post_id, '_uc_gofundme_url', true );
    if ( ! $url || ! sfaf_show_feature( $post_id, 'donate' ) ) {
        return '';
    }

    ob_start();
    ?>
    <div class="uc-donate-block">
        <?php echo sfaf_fundraising_progress( $post_id ); ?>
        <?php echo sfaf_action_button( array(
            'label'    => 'Donate',
            'href'     => $url,
            'variant'  => 'primary',
            'external' => true,
        ) ); ?>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * The meta key holding the per-event fundraising progress choice.
 *
 * Named once so the editor, the pending queue, the save routine and the two
 * display helpers cannot disagree about where the answer lives.
 *
 * @return string
 */
function sfaf_fundraising_progress_meta_key() {
    return '_uc_show_fund_progress';
}

/**
 * Whether this event shows its fundraising figures. OFF unless switched on.
 *
 * OPT IN, NOT OPT OUT, AND THE DEFAULT IS THE POINT. Until 3.2.0 a bar
 * appeared on any event that had a goal, which meant importing a campaign was
 * enough to publish its fundraising position on a page nobody had reviewed.
 * A goal is a number GoFundMe Pro happens to hold; whether this calendar
 * should be repeating it in public is a decision, and decisions are made by
 * people. An unset value is therefore no, permanently, and stays no until a
 * manager says otherwise on the event.
 *
 * The site-wide switch is still respected on top of this. It is a master off
 * for the whole calendar, not a default on for each event.
 *
 * @param int $post_id
 * @return bool
 */
function sfaf_show_fundraising_progress( $post_id ) {
    $settings = get_option( 'uc_settings', array() );
    if ( isset( $settings['gofundme_show_progress'] ) && $settings['gofundme_show_progress'] !== '1' ) {
        return false;
    }
    return '1' === (string) get_post_meta( $post_id, sfaf_fundraising_progress_meta_key(), true );
}

/**
 * "Add to Calendar" dropdown: Google Calendar link + .ics download.
 *
 * NOT ON AN EVENT THAT TAKES REGISTRATIONS (3.64.0). It sat directly under the
 * RSVP button, and the two are a pair of things to press with no obvious order
 * between them, so somebody could press this one believing it was how you sign
 * up and come away with a calendar entry and no place held. There is no wording
 * that fixes that: two buttons is the problem.
 *
 * NOTHING IS LOST, WHICH IS WHY THIS IS A REMOVAL AND NOT A WARNING. The
 * registration confirmation already carries both destinations, the Google link
 * and the .ics, and it carries them at the moment the place is actually held.
 * On an event that takes no registrations there is no confirmation and no other
 * route, so the button is the only thing available and stays exactly as it was.
 *
 * THE TICK IN CALADMIN IS NOT WHAT DECIDES THIS. `_uc_show_calendar` still says
 * what the manager wants when the event is not taking names, and the editor
 * greys the control and says so rather than leaving it looking settable. The
 * save does not read it while registrations are on, so the behaviour does not
 * depend on the disabled attribute surviving in the browser.
 */
function sfaf_add_to_calendar( $post_id ) {
    if ( sfaf_event_takes_rsvps( $post_id ) ) {
        return '';
    }
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
 * "Follow this series" button (opens the dialog handled in calendar.js).
 *
 * ONLY ON AN EVENT THAT IS IN A SERIES, and the gate is the whole reason this
 * function starts with a lookup. What following offers is hearing about dates
 * that do not exist yet; a one-off has none coming, so on a one-off the control
 * is an offer of nothing and is simply absent.
 *
 * THE LOOKUP IS FREE HERE. SFAF_Series::for_event() is get_the_terms(), which
 * reads WordPress's object term cache, and this template has already resolved
 * the same terms for the "Part of series" link above the title. So gating on
 * series membership costs no query on the page this is drawn on.
 *
 * NOT CANCELLED-AWARE, AND THAT IS THE CHANGE. The button this replaces hid
 * itself on a cancelled event, because it promised a morning-of reminder that
 * the reminder job would skip. Following says nothing about this date: it is
 * about the series' future ones, and one cancelled occurrence is no reason to
 * stop somebody hearing about the rest.
 *
 * @param int $post_id
 * @return string
 */
function sfaf_follow_series_button( $post_id ) {
    $term_id = SFAF_Series::id_for_event( $post_id );
    if ( ! $term_id ) {
        return '';
    }

    /*
     * THE PER-EVENT SWITCH IS THE ONE THAT ALREADY EXISTED, and its meta key is
     * deliberately unchanged. `_uc_show_reminders` is the "Reminders" tick in
     * the Display card of the caladmin event editor, one of five that decide
     * which blocks the public page draws; it defaults to ON when it has never
     * been saved, it is copied by the recurrence generator and by Duplicate,
     * and it travels in the embed payload.
     *
     * A NEW KEY WOULD HAVE SILENTLY RE-ENABLED THE CONTROL on every event where
     * somebody had turned it off, because an absent value means on. The label
     * on the checkbox changes; what it governs does not.
     */
    if ( ! sfaf_show_feature( $post_id, 'reminders' ) ) {
        return '';
    }

    /*
     * In an embed the modal cannot submit cross-origin, so this becomes a link
     * to the event page where the form works. See the embed context notes above.
     *
     * UNREACHABLE TODAY, AND KEPT ON PURPOSE. DO NOT DELETE IN A CLEANUP.
     *
     * This function is called only from templates/single-uc_event.php, and
     * SFAF_Embed::build_payload() renders the month grid, the card list or the
     * calendar block and never that template. So sfaf_is_embed_context() is
     * always false here and this branch never runs.
     *
     * It is a guard against a change that has not happened yet rather than a
     * leftover from one that has. The day anything renders a single event into
     * an embed payload, this is what stops the modal being offered on a page
     * that cannot post to admin-ajax. The same is true of the identical branch
     * in sfaf_rsvp_block() and of sfaf_add_to_calendar(): all three are dead for
     * exactly this one reason, and all three become live together.
     */
    if ( sfaf_is_embed_context() ) {
        return '<a class="uc-follow-btn uc-embed-link" href="' . esc_url( get_permalink( $post_id ) ) . '">'
            . sfaf_icon( 'bell' ) . ' Follow this series</a>';
    }

    ob_start();
    ?>
    <button type="button" class="uc-follow-btn"
            data-series-id="<?php echo (int) $term_id; ?>"
            data-series-name="<?php echo esc_attr( SFAF_Series::name_for_event( $post_id ) ); ?>"><?php echo sfaf_icon( 'bell' ); ?> Follow this series</button>
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
    /*
     * A CANCELLED EVENT SHOWS NO REGISTER BUTTON.
     *
     * The refusal that matters is in SFAF_RSVP, at the write, because a form
     * removed from a template is not a permission and an open tab still posts.
     * This is the other half: not offering somebody a control that is going to
     * refuse them. Both halves are needed and neither is sufficient.
     */
    if ( SFAF_Cancellation::is_cancelled( $post_id ) ) {
        return '';
    }

    if ( ! sfaf_event_takes_rsvps( $post_id ) ) {
        return '';
    }

    $capacity   = (int) get_post_meta( $post_id, '_uc_capacity', true );
    $rsvp_count = sfaf_get_rsvp_count( $post_id );

    // In an embed the modal cannot post cross-origin, so the control becomes a
    // link to the event page where the form works. Same button family either
    // way: the difference is the element, never the appearance.
    //
    // UNREACHABLE TODAY AND KEPT ON PURPOSE, for the reason set out in full on
    // sfaf_follow_series_button() above. Do not delete in a cleanup.
    $button = sfaf_is_embed_context()
        ? sfaf_action_button( array(
            'label'   => 'RSVP',
            'href'    => get_permalink( $post_id ),
            'variant' => 'primary',
            'class'   => 'uc-embed-link',
        ) )
        : sfaf_action_button( array(
            'label'   => 'RSVP',
            'variant' => 'primary',
            'class'   => 'uc-rsvp-btn',
            // data-event-title carried on the button, exactly as the reminders
            // button already carries it. The modal used to read the event name
            // out of the .uc-card-title beside it, which exists on a list card
            // and does not exist on the event page, so a visitor registering
            // from the page itself met a modal whose subtitle was blank. The
            // button knows what event it is for; nothing else has to guess.
            'attrs'   => array(
                'data-event-id'    => (int) $post_id,
                'data-event-title' => get_the_title( $post_id ),
            ),
        ) );

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
        <?php echo $button; ?>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Replace {tokens} in email templates with event/attendee data.
 *
 * @param string $text     Template text.
 * @param int    $event_id Event ID.
 * @param array  $data     RSVP data (name, email...) plus, for the morning-of
 *                         reminder, a per-recipient cancel_url.
 */
function sfaf_replace_tokens( $text, $event_id, $data = array() ) {
    /*
     * AP STYLE, NOT THE SITE'S DATE FORMAT SETTING.
     *
     * This was date_i18n( get_option( 'date_format' ) ), so what a registrant
     * read in their reminder email depended on a WordPress setting that nobody
     * involved in the brand had ever looked at, and any site whose format
     * carried an ordinal put "August 4th" in front of them. The email is a
     * public surface and takes the same rules the cards do.
     */
    $date     = get_post_meta( $event_id, '_uc_event_date', true );
    $date_fmt = sfaf_ap_date( $date, 'full' );

    // THE PHRASE, not a comma-joined list: this is the {organizer} token a
    // manager writes into an email body and the ORGANIZER line on an .ics, and
    // both are read by a person. Ordered by name, like every other reader.
    $organizer = SFAF_Organizers::phrase( $event_id );

    // Start and end as one readable phrase. An event with no end time says just
    // the start rather than inventing one, and an event with neither says
    // nothing at all instead of printing an empty dash.
    $start_raw  = (string) get_post_meta( $event_id, '_uc_start_time', true );
    $end_raw    = (string) get_post_meta( $event_id, '_uc_end_time', true );
    $start_fmt  = sfaf_ap_time( $start_raw );
    $end_fmt    = sfaf_ap_time( $end_raw );
    $time_range = sfaf_ap_time_range( $start_raw, $end_raw );

    // The cancel link is a whole sentence, not a bare URL, so a template can
    // drop it in without having to word it — and it collapses to nothing for a
    // recipient who has no registration to cancel (staff on the notification
    // list), rather than offering them a link that would only confuse.
    $cancel_url  = isset( $data['cancel_url'] ) ? (string) $data['cancel_url'] : '';
    $cancel_line = $cancel_url ? "Can't make it? Cancel your registration: " . $cancel_url : '';

    $replacements = array(
        '{event_name}'       => get_the_title( $event_id ),
        // {attendee_name} IS STILL THE WHOLE NAME. The form asks for first and
        // last separately now, and a template that already says
        // "Hello {attendee_name}" must keep meaning what it meant. {first_name}
        // is the new one, and it is what the shipped greeting uses.
        '{attendee_name}'    => isset( $data['name'] ) ? $data['name'] : '',
        '{first_name}'       => isset( $data['first_name'] ) ? $data['first_name'] : '',
        '{last_name}'        => isset( $data['last_name'] ) ? $data['last_name'] : '',
        '{event_date}'       => $date_fmt,
        '{event_time}'       => $start_fmt ? $start_fmt : $start_raw,
        '{event_end_time}'   => $end_fmt,
        '{event_time_range}' => $time_range,
        '{event_location}'   => sfaf_event_location( $event_id ),
        // Cast: get_permalink() returns false for a post that has gone, and
        // strtr wants strings.
        '{event_url}'        => (string) get_permalink( $event_id ),
        '{organizer_name}'   => $organizer,
        '{cancel_url}'       => $cancel_url,
        '{cancel_link}'      => $cancel_line,
    );

    return strtr( $text, $replacements );
}

/* -------------------------------------------------------------------------
 * Recurring series helpers
 * ---------------------------------------------------------------------- */

/**
 * True when the event belongs to a series.
 *
 * A SERIES CANNOT GO MISSING ANY MORE. This used to need a second function
 * beside it (sfaf_series_parent_id) whose entire job was to notice that the
 * parent post had been deleted and degrade to "not in a series", plus
 * sfaf_is_orphaned_occurrence() to name that state, plus two repair screens to
 * get out of it. A term relationship is removed with its term, so an event is
 * either in a series or it is not and there is no third condition to detect.
 *
 * NO MINIMUM COUNT, either. The old version required more than one occurrence,
 * because a "series" of one was really just an event wearing a parent flag. A
 * series is now a container somebody deliberately created and wrote a
 * description for, so an event is part of it from its first date.
 *
 * KEPT ALONGSIDE SFAF_Series, which is what the plugin itself asks. This and
 * sfaf_get_series_name() are the theme-facing pair: same names, same
 * signatures and same return shapes as before 3.0.0, so a theme template that
 * calls either of them keeps working across the model change.
 *
 * @param int $post_id
 * @return bool
 */
function sfaf_is_in_series( $post_id ) {
    return SFAF_Series::id_for_event( $post_id ) > 0;
}

/**
 * Display name of the series an event is in, or ''.
 */
function sfaf_get_series_name( $post_id ) {
    return SFAF_Series::name_for_event( $post_id );
}

/**
 * Published events in a series, ordered by date.
 *
 * @param int  $term_id       Series term ID.
 * @param bool $upcoming_only Limit to today-and-later.
 * @return int[] Post IDs.
 */
function sfaf_get_series_events( $term_id, $upcoming_only = false ) {
    // Memoized per series (+ upcoming flag) for the request: the event list and
    // the single-event page ask this once per card, which would otherwise fire
    // a separate query for every event in the same series.
    static $cache = array();
    $key = (int) $term_id . '|' . ( $upcoming_only ? '1' : '0' );
    if ( ! isset( $cache[ $key ] ) ) {
        $cache[ $key ] = SFAF_Series::events( (int) $term_id, array(
            'upcoming' => (bool) $upcoming_only,
            'status'   => array( 'publish' ),
            'limit'    => 50,
            // THE PUBLIC SIDE OF A SERIES. This is what the event page's
            // "Upcoming in this series" list is built from, and it is a theme
            // facing function, so it must never hand back a private event.
            'public_only' => true,
        ) );
    }
    return $cache[ $key ];
}

/**
 * "Part of series: Name" — the badge on a card and on the single event page.
 *
 * WHERE IT NOW POINTS, AND WHY. It used to link to the series PARENT POST,
 * which no longer exists. The two candidates were a filtered calendar view and
 * the taxonomy term archive; this is the term archive.
 *
 * The filtered calendar view was the tempting one and it is the wrong choice
 * for a reason that has nothing to do with taste: the plugin does not know
 * which page holds a [sfaf_calendar] shortcode. There may be several, there may
 * be none, and the answer can change without the plugin hearing about it — so
 * the link would be a guess that breaks quietly. A term archive is a real URL
 * WordPress routes and canonicalises, it exists whether or not anybody has
 * built a calendar page, and it is the only destination that can show what the
 * series IS: the description and image a series carries, which a filtered list
 * of dates has nowhere to put. Section 2 asks for a series with no events at
 * all to still be readable; only the archive can do that.
 *
 * The badge itself is unchanged — same class, same icon, same words.
 *
 * @param int $post_id
 * @return string
 */
function sfaf_series_link( $post_id ) {
    $term = SFAF_Series::for_event( $post_id );
    if ( ! $term ) {
        return '';
    }
    $url = SFAF_Series::url( $term->term_id );

    // An anchor with an empty href and an empty label is worse than no anchor,
    // so a series that cannot produce a link renders nothing.
    if ( '' === $url || '' === $term->name ) {
        return '';
    }

    return '<a class="uc-series-link" href="' . esc_url( $url ) . '">'
        . sfaf_icon( 'repeat' ) . ' Part of series: ' . esc_html( $term->name ) . '</a>';
}

/**
 * "Upcoming in this series" list for the single event template.
 */
function sfaf_series_list_html( $post_id ) {
    $term_id = SFAF_Series::id_for_event( $post_id );
    if ( ! $term_id ) {
        return '';
    }
    $events = sfaf_get_series_events( $term_id, true );

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
                    <a href="<?php echo esc_url( get_permalink( $eid ) ); ?>"<?php echo sfaf_new_tab_attrs(); ?>>
                        <span class="uc-series-date"><?php echo $ts ? esc_html( sfaf_ap_date( $ts, 'short' ) ) : ''; ?></span>
                        <span class="uc-series-title"><?php echo esc_html( get_the_title( $eid ) ); ?></span>
                        <?php if ( $st ) : ?><span class="uc-series-time"><?php echo esc_html( sfaf_ap_time( $st ) ); ?></span><?php endif; ?><?php echo sfaf_new_tab_note(); ?>
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

    $signup = $portal
        ? trailingslashit( $portal ) . 'need/detail/?need_id=' . rawurlencode( $need_id )
        : '';

    ob_start();
    ?>
    <div class="uc-galaxy-block">
        <?php // Spots remaining come from shifts[].slots in production; the
              // sentence is built in one place so the card and this block can
              // never quote different numbers. ?>
        <span class="uc-galaxy-spots"><?php echo sfaf_icon( 'hand' ); ?> <?php echo esc_html( sfaf_volunteer_spots_text( $post_id ) ); ?></span>
        <?php if ( $signup ) : ?>
            <?php echo sfaf_action_button( array(
                'label'    => 'Sign Up to Volunteer',
                'href'     => $signup,
                'variant'  => 'primary',
                'external' => true,
            ) ); ?>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

/* -------------------------------------------------------------------------
 * FAQ helpers
 *
 * ONE KEY. FAQs live on the event, in _uc_faqs, and that is the whole storage
 * model. There is no inheritance, no override flag, and no display logic
 * deciding whether a series' questions appear above an event's or instead of
 * them.
 *
 * WHAT THIS REPLACES. Until 3.0.0 the same questions could be in three places:
 * _uc_series_faq on the parent (shared), _uc_event_faq on an occurrence (its
 * own), and _uc_faq_override deciding which of the two a visitor saw — and, to
 * make it properly confusing, a STANDALONE event kept its own questions in
 * _uc_series_faq despite not being a series, because the Series Manager and the
 * event editor shared one repeater. Answering "what FAQs does this event have"
 * meant knowing which of three shapes the event was.
 *
 * REUSE COMES FROM SAVED SETS, which are copies and have been since 2.9.0, and
 * from a series naming a default set applied when an event is created into it.
 * Both put real rows on the event at a moment somebody chose; neither leaves a
 * live link that could rewrite an event later. See SFAF_FAQ_Sets.
 * ---------------------------------------------------------------------- */

/**
 * The one meta key FAQ rows live in, on the event.
 *
 * A function rather than a bare constant because every reader and writer in the
 * plugin goes through it, which is what made collapsing three keys into one a
 * change to this line instead of a search across the codebase.
 *
 * @return string
 */
function sfaf_faq_meta_key() {
    return '_uc_faqs';
}

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
        $row = array( 'question' => $q, 'answer' => $a );

        // The ID an imported row carries at its platform. It MUST survive this
        // function: it is the only thing that tells an imported row from one a
        // person typed, and this rebuilds every row from scratch — so dropping
        // it here would silently reclassify every imported FAQ as hand-written
        // the first time anything read the meta. See
        // SFAF_Sources::sync_faqs().
        if ( ! empty( $f[ SFAF_Sources::FAQ_SOURCE_ID ] ) ) {
            $row[ SFAF_Sources::FAQ_SOURCE_ID ] = (string) $f[ SFAF_Sources::FAQ_SOURCE_ID ];
        }

        $out[] = $row;
    }
    return $out;
}

/**
 * Whether an FAQ row came from a platform rather than a person.
 *
 * @param array $row
 * @return bool
 */
function sfaf_faq_is_imported( $row ) {
    return is_array( $row ) && ! empty( $row[ SFAF_Sources::FAQ_SOURCE_ID ] );
}

/**
 * An event's FAQ rows. There is exactly one place to look.
 *
 * @param int $post_id
 * @return array[] List of array( 'question' => string, 'answer' => string ).
 */
function sfaf_get_faqs( $post_id ) {
    return sfaf_normalize_faqs( get_post_meta( (int) $post_id, sfaf_faq_meta_key(), true ) );
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
                    <div class="uc-faq-a"><?php echo SFAF_Rich_Text::display( $f['answer'] ); ?></div>
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
    // THE FIRST CATEGORY, and "first" is decided in one place so this picture
    // cannot come out one colour on the list and another in the month grid.
    // See sfaf_event_categories().
    $first = sfaf_event_primary_category( $post_id );
    $name  = $first ? $first->name : '';

    // Approved brand palette (brand guide v3.0, p.9). The third value is the
    // foreground: light brand backgrounds take the dark gray, dark ones take
    // white, so the label and icon stay legible.
    /*
     * READ FROM THE CATEGORY, NOT FROM A LIST IN THIS FILE.
     *
     * This used to hold its own map of five category NAMES to a colour and an
     * icon, which meant three separate things were wrong at once. It ignored
     * `_uc_category_color` entirely, so setting a category's colour changed the
     * chip and left the placeholder alone. It could not describe a sixth
     * category, which fell through to dark gray whatever colour it had. And two
     * of its five icon names, 'people' and 'hands', are not in
     * sfaf_icon_paths() and therefore drew nothing at all, which nobody
     * noticed because the rectangle behind them still looked deliberate.
     *
     * SFAF_Categories::icon() keeps those five categories on the icons they
     * have always had, so nothing on the calendar changes appearance today, and
     * it checks every key against the icon set before returning it.
     */
    $term_id = $first ? (int) $first->term_id : 0;
    $label   = ( '' !== $name ) ? $name : 'Event';

    if ( $term_id ) {
        $bg   = sfaf_category_color( $term_id );
        $icon = SFAF_Categories::icon( $term_id, $name );
        $fg   = sfaf_on_color( $bg );
    } else {
        $bg = '#373433'; $icon = 'calendar'; $fg = '#FFD900';
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
 * Series-level image URL, from the series term (attachment or manual URL).
 *
 * Memoized per series: every card in a list resolves its image through the same
 * series, so without this each one re-queries the attachment.
 *
 * @param int $term_id
 * @return string
 */
function sfaf_series_image_url( $term_id ) {
    $term_id = (int) $term_id;
    if ( ! $term_id ) {
        return '';
    }
    static $cache = array();
    if ( ! isset( $cache[ $term_id ] ) ) {
        $cache[ $term_id ] = SFAF_Series::image_url( $term_id );
    }
    return $cache[ $term_id ];
}

/**
 * The ten approved SFAF brand colors (brand guide v3.0, p.9), in the order the
 * palette is presented on p.18.
 *
 * @return array<string,string> hex => human label.
 */
function sfaf_brand_palette() {
    return array(
        '#FFD900' => 'Yellow',       // Pantone 109 CP
        '#F7921E' => 'Orange',       // Pantone 144 CP
        '#FBCE9A' => 'Light Orange', // shade of Orange (3.75.0)
        '#A16827' => 'Deep Orange',  // shade of Orange (3.75.0)
        '#F04937' => 'Red',          // Pantone 179 CP
        '#A30C33' => 'Burgundy',     // Pantone 201 CP
        '#F1668C' => 'Pink',         // Pantone 1915 CP
        '#8D54A2' => 'Purple',       // Pantone 258 CP
        '#C0A1CC' => 'Light Purple', // shade of Purple (3.75.0)
        '#16BECF' => 'Teal',         // Pantone 7710 UP
        '#2A686E' => 'Deep Teal',    // shade of Teal (3.75.0)
        '#8CC745' => 'Green',        // Pantone 3561 UP
        '#CBE6AB' => 'Light Green',  // shade of Green (3.75.0)
        '#607B3C' => 'Deep Green',   // shade of Green (3.75.0)
        '#D1D3D4' => 'Light Gray',   // Cool Gray 3 CP
        '#373433' => 'Dark Gray',    // Cool Gray 11 CP
    );
}

/**
 * The default category color when none is set.
 */
function sfaf_default_category_color() {
    return '#16BECF';
}

/**
 * Which of the two brand neutrals is legible ON a given brand colour.
 *
 * MEASURED, NOT PICKED. Every one of the ten approved colours was checked
 * against Dark Gray and against white, and this returns whichever wins:
 *
 *   Yellow      8.92 dark   Orange   5.34 dark   Red        3.68 white
 *   Burgundy    7.90 white  Pink     4.13 dark   Purple     5.35 white
 *   Green       6.10 dark   Teal     5.47 dark   Light Gray 8.22 dark
 *   Dark Gray  12.34 white
 *
 * FOR LARGE TEXT ONLY, and the caller has to keep that true. Red and Pink have
 * no foreground that clears 4.5:1 either way; both clear the 3:1 that WCAG
 * asks of text at 24px or 18.7px bold. The one place this is used is the
 * placeholder SVG, whose label is 92px bold on a 1600-unit canvas, so it is
 * large by any reading. Small text on a category colour goes through
 * sfaf_category_shades() instead, which is a tint-and-ink pair built for it.
 *
 * @param string $hex A brand colour.
 * @return string '#373433' or '#FFFFFF'.
 */
function sfaf_on_color( $hex ) {
    $lum = function ( $h ) {
        $h = ltrim( (string) $h, '#' );
        if ( 6 !== strlen( $h ) ) {
            return 0.0;
        }
        $c = array();
        foreach ( array( 0, 2, 4 ) as $i ) {
            $v   = hexdec( substr( $h, $i, 2 ) ) / 255;
            $c[] = ( $v <= 0.03928 ) ? $v / 12.92 : pow( ( $v + 0.055 ) / 1.055, 2.4 );
        }
        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    };
    $ratio = function ( $a, $b ) use ( $lum ) {
        $la = $lum( $a );
        $lb = $lum( $b );
        return ( max( $la, $lb ) + 0.05 ) / ( min( $la, $lb ) + 0.05 );
    };
    return ( $ratio( '#373433', $hex ) >= $ratio( '#FFFFFF', $hex ) ) ? '#373433' : '#FFFFFF';
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

/* -----------------------------------------------------------------------------
 * An event's categories, in ONE order, decided in ONE place.
 *
 * AN EVENT HAS ALWAYS BEEN ABLE TO HAVE SEVERAL. uc_event_category is an
 * ordinary hierarchical taxonomy and the WordPress editor has always offered
 * checkboxes; the REST payload satellites read has always carried an array. What
 * enforced one was the /caladmin editor, which rendered a single <select> and
 * wrote wp_set_object_terms() with an array of one, so opening a two-category
 * event there and pressing Save silently deleted the second category.
 *
 * WHY THE ORDER IS FIXED HERE. Several things pick "the first" category: the
 * card's colour, the branded placeholder's colour and icon, the accent stripe on
 * a compact card. get_the_terms() and wp_get_post_terms() do not promise an
 * order, and they do not agree with each other, so the same event could take one
 * colour on the list and another in the month grid, and could change colour when
 * a cache was rebuilt. Every one of those call sites now comes through here.
 *
 * THE ORDER IS ALPHABETICAL BY NAME, term ID breaking a tie. Alphabetical
 * because it is the order the chips are already printed in, so "the first one"
 * is a thing a manager can see rather than a hidden property; term ID as the
 * tie-break because two categories may share a name only by accident and the
 * answer still has to be stable. Renaming a category can move it, which is
 * correct: the chips move with it, and what is on the card still matches what is
 * on the event.
 * -------------------------------------------------------------------------- */

/**
 * An event's categories, alphabetical, memoized for the request.
 *
 * @param int $post_id
 * @return WP_Term[]
 */
function sfaf_event_categories( $post_id ) {
    static $cache = array();
    $post_id = (int) $post_id;
    if ( isset( $cache[ $post_id ] ) ) {
        return $cache[ $post_id ];
    }

    $terms = wp_get_post_terms( $post_id, 'uc_event_category' );
    if ( is_wp_error( $terms ) || empty( $terms ) ) {
        $cache[ $post_id ] = array();
        return $cache[ $post_id ];
    }

    usort( $terms, function ( $a, $b ) {
        $by_name = strcasecmp( $a->name, $b->name );
        return ( 0 !== $by_name ) ? $by_name : ( (int) $a->term_id - (int) $b->term_id );
    } );

    $cache[ $post_id ] = $terms;
    return $terms;
}

/**
 * The category that supplies an event's colour and icon, or null.
 *
 * @param int $post_id
 * @return WP_Term|null
 */
function sfaf_event_primary_category( $post_id ) {
    $cats = sfaf_event_categories( $post_id );
    return empty( $cats ) ? null : $cats[0];
}

/**
 * The colour an event is drawn in: its first category's, or the default.
 *
 * @param int $post_id
 * @return string
 */
function sfaf_event_category_color( $post_id ) {
    $first = sfaf_event_primary_category( $post_id );
    return $first ? sfaf_category_color( $first->term_id ) : sfaf_default_category_color();
}

/* -----------------------------------------------------------------------------
 * Where a visitor goes when they leave an event page.
 *
 * THE PROBLEM THIS SOLVES. The event page is served from the resources site; the
 * calendar people actually browse is a page on sfaf.org carrying a shortcode, or
 * an embed of it on some other site entirely. "All Events" used to point at
 * get_post_type_archive_link(), which is a resources URL: a visitor who clicked
 * an event on sfaf.org and then pressed Back-to-the-list landed on a site they
 * had never seen and that is not a public surface. Category chips would have
 * done exactly the same thing.
 *
 * THE ANSWER IS THE REFERRER, THEN A CONFIGURED URL, THEN THE ARCHIVE. The
 * referrer is the only thing that knows WHICH calendar page they came from, and
 * there can be several. The setting is what covers a shared link, a search
 * result or a bookmark, where there is no referrer at all. The archive is the
 * last resort and exists so a link is never broken, not because it is a good
 * destination.
 *
 * THE PLUGIN DOES NOT GUESS. It cannot know which page on sfaf.org holds a
 * shortcode, and searching post_content for one would find drafts, revisions and
 * the wrong page as readily as the right one. With several calendar pages the
 * configured fallback picks one, deliberately, and the referrer covers the rest.
 * -------------------------------------------------------------------------- */

/**
 * The configured calendar home, or '' when nobody has set one.
 *
 * @return string
 */
function sfaf_calendar_home_url() {
    $settings = get_option( 'uc_settings', array() );
    $url      = is_array( $settings ) && isset( $settings['calendar_home_url'] ) ? trim( (string) $settings['calendar_home_url'] ) : '';
    if ( '' === $url ) {
        return '';
    }
    $parts = wp_parse_url( $url );
    if ( empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
        return '';
    }
    return in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ? $url : '';
}

/**
 * The registrable-looking part of a host: the last two labels.
 *
 * Deliberately not a public-suffix implementation. It exists to answer one
 * question, "is resources.sfaf.org the same organisation as sfaf.org", and it
 * answers that correctly. It would be wrong for a multi-part suffix such as
 * co.uk, which is why it is only ever used to WIDEN an allow-list that already
 * contains this site's own host and the configured calendar host, never as the
 * only check and never to authorise a redirect off this organisation's domains.
 *
 * @param string $host
 * @return string
 */
function sfaf_host_base( $host ) {
    $host  = strtolower( trim( (string) $host ) );
    $parts = array_values( array_filter( explode( '.', $host ) ) );
    $n     = count( $parts );
    return ( $n >= 2 ) ? $parts[ $n - 2 ] . '.' . $parts[ $n - 1 ] : $host;
}

/**
 * The referrer, but only when it is plausibly a calendar page on our own sites.
 *
 * WHAT IS CHECKED, IN ORDER, AND WHY EACH ONE IS THERE:
 *
 *  1. It parses, and has a scheme and a host. A referrer is attacker-supplied
 *     input like any other header.
 *  2. The scheme is http or https. This closes javascript:, data: and every
 *     other scheme that is not a page.
 *  3. The host is one of: this site's host, the configured calendar home's host,
 *     or a host sharing this site's last-two-label domain. This is the check
 *     that stops it becoming an open redirect: a link back to somebody else's
 *     site can never be built from this, whatever the header says.
 *  4. It is not an event page. An event linking "back to the calendar" and
 *     landing on another event is not going back to anything, and the event
 *     someone came from is the one they are already leaving.
 *  5. The URL is REBUILT from the validated scheme, host, path and query rather
 *     than the raw string being passed through. Credentials, ports, fragments
 *     and anything else exotic in the header do not survive.
 *
 * @return string A safe URL, or ''.
 */
function sfaf_calendar_referrer() {
    $raw = isset( $_SERVER['HTTP_REFERER'] ) ? (string) wp_unslash( $_SERVER['HTTP_REFERER'] ) : '';
    if ( '' === $raw ) {
        return '';
    }

    $parts = wp_parse_url( $raw );
    if ( empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
        return '';
    }
    if ( ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
        return '';
    }

    $host    = strtolower( $parts['host'] );
    $allowed = array();

    $self = wp_parse_url( home_url() );
    if ( ! empty( $self['host'] ) ) {
        $allowed[] = strtolower( $self['host'] );
    }
    $configured = sfaf_calendar_home_url();
    if ( '' !== $configured ) {
        $chome = wp_parse_url( $configured );
        if ( ! empty( $chome['host'] ) ) {
            $allowed[] = strtolower( $chome['host'] );
        }
    }

    $same_org = ! empty( $self['host'] ) && sfaf_host_base( $host ) === sfaf_host_base( $self['host'] );
    if ( ! in_array( $host, $allowed, true ) && ! $same_org ) {
        return '';
    }

    $path = isset( $parts['path'] ) ? $parts['path'] : '/';

    /*
     * AN ORIGIN IS NOT A PAGE (3.66.0), AND THIS IS THE COMMON CASE RATHER
     * THAN AN EDGE ONE.
     *
     * The event page is on resources.sfaf.org and the calendar is a page on
     * sfaf.org, so every real click through to an event is CROSS-ORIGIN. Every
     * current browser defaults to a referrer policy of
     * strict-origin-when-cross-origin, which sends the ORIGIN ONLY:
     * "https://sfaf.org/", with no path at all. Nothing here can recover which
     * calendar page that was, because it was never sent.
     *
     * Everything below then passed it. The host is ours, the scheme is https,
     * the path has no segments so it is not an event page, and the function
     * handed back "https://sfaf.org/". "All Events" took a visitor who was
     * reading a calendar to the SITE ROOT, and it looked like a lost referrer
     * or an unfilled setting when it was neither: the referrer arrived, was
     * valid, and named nothing.
     *
     * So a referrer with no path is a referrer we do not have, and the caller
     * falls through to the configured Calendar home URL, which is the setting
     * written for exactly this case. FILLING THAT SETTING IN IS HALF THE FIX
     * and this is the other half; neither works alone.
     *
     * A SAME-ORIGIN REFERRER IS UNAFFECTED and still carries its full path, so
     * an event reached from this site's own archive still goes back to it.
     */
    if ( '' === trim( $path, '/' ) ) {
        return '';
    }

    // An event page is not a calendar. The single-event rewrite is /events/{slug},
    // so a path with a segment under the archive base is one of ours; the bare
    // archive is not, and stays usable.
    $archive_base = 'events';
    $obj          = get_post_type_object( 'uc_event' );
    if ( $obj && ! empty( $obj->rewrite['slug'] ) ) {
        $archive_base = trim( (string) $obj->rewrite['slug'], '/' );
    }
    $segments = array_values( array_filter( explode( '/', trim( $path, '/' ) ) ) );
    if ( count( $segments ) >= 2 && $segments[0] === $archive_base ) {
        return '';
    }

    $url = strtolower( $parts['scheme'] ) . '://' . $host . $path;
    if ( ! empty( $parts['query'] ) ) {
        $url .= '?' . $parts['query'];
    }
    return esc_url_raw( $url );
}

/**
 * Where "back to the calendar" should go from an event page.
 *
 * @param string $category_slug Optional: filter the calendar to this category.
 * @return string
 */
function sfaf_calendar_return_url( $category_slug = '' ) {
    $slug = sanitize_title( (string) $category_slug );

    $url = sfaf_calendar_referrer();
    if ( '' === $url ) {
        $url = sfaf_calendar_home_url();
    }
    if ( '' === $url ) {
        /*
         * Nothing configured and nowhere to go back to. A category still has a
         * real archive on this site and the post type still has one, so the link
         * works; it is simply not the calendar anybody was reading. Filling in
         * the Calendar home URL setting is what stops this happening.
         */
        if ( '' !== $slug ) {
            $term = get_term_by( 'slug', $slug, 'uc_event_category' );
            if ( $term && ! is_wp_error( $term ) ) {
                $link = get_term_link( $term );
                if ( ! is_wp_error( $link ) ) {
                    return $link;
                }
            }
        }
        return (string) get_post_type_archive_link( 'uc_event' );
    }

    // Our own parameter, never doubled: a referrer that already carried one was
    // itself filtered, and this click is choosing a different filter.
    $url = remove_query_arg( 'uc_cat', $url );
    return ( '' !== $slug ) ? add_query_arg( 'uc_cat', $slug, $url ) : $url;
}

/**
 * Where a category chip printed INSIDE a calendar block points.
 *
 * Scripts on both surfaces intercept these and filter the list in place, so this
 * href is what happens when they cannot: no JavaScript, a middle-click, or an
 * embed on a page whose script failed. It therefore has to be a real calendar
 * showing that category, not the current page, because on an embed the current
 * page belongs to somebody else.
 *
 * @param string $slug
 * @return string
 */
function sfaf_category_filter_url( $slug ) {
    $slug = sanitize_title( (string) $slug );
    $home = sfaf_calendar_home_url();
    if ( '' === $home ) {
        $term = $slug ? get_term_by( 'slug', $slug, 'uc_event_category' ) : null;
        if ( $term && ! is_wp_error( $term ) ) {
            $link = get_term_link( $term );
            if ( ! is_wp_error( $link ) ) {
                return $link;
            }
        }
        return (string) get_post_type_archive_link( 'uc_event' );
    }
    return $slug ? add_query_arg( 'uc_cat', $slug, $home ) : $home;
}

/**
 * The category chips for one event, as links.
 *
 * TWO RENDERERS, ONE COLOUR CONTRACT, AND UNTIL 3.22.0 THAT WAS NOT TRUE.
 *
 * The card branch prints .uc-lc-chip and takes its colours from --uc-cat-tint
 * and --uc-cat-ink, which the card wrapper sets from sfaf_category_shades().
 * The event-page branch printed .uc-badge and passed the RAW category colour in
 * a property of its own, --badge-color, which the stylesheet used both as a 12%
 * tint AND, unchanged, as the text colour. On the Yellow family that is #FFD900
 * on #FFFAE0: 1.32:1. Every one of the ten families failed, the best of them at
 * 2.63:1, on a chip whose entire job is to be read as a word.
 *
 * The tint and ink pair is contrast-checked per family and always has been. It
 * simply was not being asked for here. Both branches now emit the same two
 * custom properties from the same function, so the pair cannot be honoured on
 * one surface and skipped on the other, and a new surface that wants a chip has
 * one thing to copy rather than two to choose between.
 *
 * PER CHIP, NOT PER EVENT, and that is the difference from the card. The card
 * wrapper carries ONE set of these properties, taken from the first category,
 * because the card is drawn in one colour; the chips inherit it, so the second
 * chip on a two-category event wears the first category's colour. On the event
 * page there is no card to colour, so each chip states its own pair and an
 * event tagged Fundraising and Workshops shows one yellow chip and one of
 * whatever Workshops is.
 *
 * @param int    $post_id
 * @param string $context 'card' inside a calendar block, 'single' on an event page.
 * @return string
 */
function sfaf_category_chips_html( $post_id, $context = 'card' ) {
    $cats = sfaf_event_categories( $post_id );
    if ( empty( $cats ) ) {
        return '';
    }

    $out = '';
    foreach ( $cats as $cat ) {
        $url = ( 'single' === $context )
            ? sfaf_calendar_return_url( $cat->slug )
            : sfaf_category_filter_url( $cat->slug );

        if ( 'single' === $context ) {
            $shades = sfaf_category_shades( sfaf_category_color( $cat->term_id ) );
            $out   .= '<a class="uc-badge uc-badge-link" href="' . esc_url( $url ) . '"'
                . ' data-uc-cat="' . esc_attr( $cat->slug ) . '"'
                . ' style="--uc-cat-tint: ' . esc_attr( $shades['tint'] ) . '; --uc-cat-ink: ' . esc_attr( $shades['ink'] ) . '">'
                . esc_html( $cat->name ) . '</a>';
        } else {
            $out .= '<a class="uc-lc-chip uc-lc-chip-link" href="' . esc_url( $url ) . '"'
                . ' data-uc-cat="' . esc_attr( $cat->slug ) . '">'
                . esc_html( $cat->name ) . '</a>';
        }
    }
    return $out;
}

/**
 * A "?" that opens a paragraph of explanation, for a form label.
 *
 * WHY THIS EXISTS. Several editor cards carried a paragraph longer than the
 * field it explained, which is most of why cards in one row ended up wildly
 * different heights, and all of it is read once and never again. The
 * explanation is still worth having; it is not worth three lines of permanent
 * screen furniture beside a one-line control.
 *
 * A REAL BUTTON, AND NOT A TOOLTIP. Hover is unreachable on a touch screen and
 * unreachable from the keyboard, and `title` is announced inconsistently, cannot
 * be styled, and cannot hold a sentence anybody wants to read. This is the
 * standard disclosure pattern: a button carrying aria-expanded and aria-controls,
 * and a region that is genuinely hidden when closed. It works on click, on
 * Enter, on Space and on tap, and with the script gone the region is simply
 * visible, so nothing is ever unreachable.
 *
 * @param string $id   Unique id for the region.
 * @param string $text The explanation. Plain text.
 * @param string $what What it is about, for the button's accessible name.
 * @return string
 */
function sfaf_help( $id, $text, $what = '' ) {
    $id   = sanitize_html_class( (string) $id );
    $text = trim( (string) $text );
    if ( '' === $id || '' === $text ) {
        return '';
    }

    $label = ( '' !== $what ) ? 'More about ' . $what : 'More about this field';

    return '<button type="button" class="uc-help-btn" data-uc-help-toggle'
        . ' aria-expanded="false" aria-controls="' . esc_attr( $id ) . '"'
        . ' aria-label="' . esc_attr( $label ) . '">?</button>'
        . '<span class="uc-help-body" id="' . esc_attr( $id ) . '" data-uc-help-body>'
        . esc_html( $text ) . '</span>';
}

/**
 * Where an event happens, as one line.
 *
 * ONE READER FOR TWO STORAGE SHAPES, AND THE REFERENCE IS RESOLVED HERE.
 *
 * An event either names a venue, in which case its address lives on the venue
 * and is looked up now, or it holds its own location text. It never holds both:
 * saving one clears the other, so this cannot have to decide which wins.
 *
 * Resolving at read time is the entire point of storing a reference. Correct a
 * suite number on the venue and every event held there is right immediately,
 * including the ones already published and the ones already past. Nothing was
 * copied, so there is nothing to go and re-copy. See class-sfaf-venues.php.
 *
 * @param int $post_id
 * @return string
 */
/**
 * The four meta keys an event's OWN location is kept in.
 *
 * An event either names a venue, in which case the address lives on the venue
 * term, or it holds its own location. Its own location used to be one free-text
 * line, exactly as a venue's address used to be, and for the same reason it was
 * not enough: nothing could tell a complete address from half of one.
 *
 * `_uc_location` IS STILL THE COMPOSED LINE and is still what everything reads.
 * The parts are composed into it on every save, which is why sfaf_event_location()
 * below is unchanged and why none of its call sites had to learn about parts.
 *
 * @return array<string,string> part => meta key
 */
function sfaf_location_part_keys() {
    return array(
        'street' => '_uc_location_street',
        'city'   => '_uc_location_city',
        'state'  => '_uc_location_state',
        'zip'    => '_uc_location_zip',
    );
}

/**
 * An event's own location as its four parts.
 *
 * FALLS BACK TO PARSING THE STORED LINE, which is how every location written
 * before this existed still fills the form in. That is deliberately a read-time
 * fallback rather than a migration pass: nothing is rewritten until somebody
 * saves, so a line that the parser would split badly is not touched until a
 * person is looking at the result. The parser is the venues one, unchanged, so
 * there is one set of rules about what an address is: a trailing ZIP and a bare
 * two-letter state code are the only things treated as certain, and anything
 * else goes into street whole rather than being guessed at.
 *
 * @param int $post_id
 * @return array{street:string,city:string,state:string,zip:string}
 */
function sfaf_event_location_parts( $post_id ) {
    $post_id = (int) $post_id;
    $parts   = array();
    foreach ( sfaf_location_part_keys() as $part => $key ) {
        $parts[ $part ] = $post_id ? (string) get_post_meta( $post_id, $key, true ) : '';
    }

    if ( '' === implode( '', $parts ) ) {
        $line = $post_id ? trim( (string) get_post_meta( $post_id, '_uc_location', true ) ) : '';
        if ( '' !== $line ) {
            return SFAF_Venues::parse_address( $line );
        }
    }
    return $parts;
}

/**
 * The shortest honest answer to "where is this".
 *
 * WHY THIS EXISTS. sfaf_event_location() returns "Strut, 470 Castro St, San
 * Francisco, CA 94114", which is right on an event page where somebody is
 * deciding whether they can get there, and wrong in a queue of twenty imported
 * events that are mostly at the same address. Repeated in full on every row it
 * is the longest thing on the row and carries the least new information.
 *
 * So: the venue's NAME where the event names a venue, because that is what
 * distinguishes one row from another; otherwise the first line of the address,
 * which is the street or the building. Never the postcode, never the state.
 *
 * @param int $post_id
 * @return string '' when the event has no location at all.
 */
function sfaf_event_location_short( $post_id ) {
    $post_id = (int) $post_id;

    // An online event has no place to shorten. Same answer as the long form,
    // because two words is already the short form. See SFAF_Online.
    if ( SFAF_Online::is_online( $post_id ) ) {
        return SFAF_Online::LABEL;
    }

    $venue_id = SFAF_Venues::id_for_event( $post_id );
    if ( $venue_id ) {
        $venue = SFAF_Venues::get( $venue_id );
        if ( $venue && '' !== $venue->name ) {
            return $venue->name;
        }
    }

    $full = sfaf_event_location( $post_id );
    if ( '' === $full ) {
        return '';
    }
    // The first comma-separated part: "470 Castro St" out of the full postal
    // address, "Online" out of "Online", and the whole thing when there are no
    // commas to cut on.
    $first = explode( ',', $full );
    return trim( $first[0] );
}

function sfaf_event_location( $post_id ) {
    $post_id = (int) $post_id;

    /*
     * ONLINE IS ANSWERED FIRST AND ANSWERED HERE.
     *
     * This function is the single reader every surface goes through: the event
     * page, the cards, the sidebar, the month grid, the four emails, the .ics,
     * the JSON-LD, the satellite payload, the WordPress admin box and the
     * caladmin lists. Answering "Online Event" in one place is what makes that
     * phrase appear in all of them without a condition being written thirteen
     * times, and without one of the thirteen being forgotten.
     *
     * IT IS NEVER THE MEETING LINK. Everything downstream prints what this
     * returns, several of those things are public, and one of them is a Google
     * Maps query string. The link lives behind SFAF_Online::link() and reaches
     * only the paths that class's whitelist names.
     */
    if ( SFAF_Online::is_online( $post_id ) ) {
        return SFAF_Online::LABEL;
    }

    $venue_id = SFAF_Venues::id_for_event( $post_id );
    if ( $venue_id ) {
        $display = SFAF_Venues::display( $venue_id );
        if ( '' !== $display ) {
            return $display;
        }
    }

    return trim( (string) get_post_meta( $post_id, '_uc_location', true ) );
}

/**
 * The four facts a community submission adds, and nothing else reads.
 *
 * ONE ACCESSOR EACH, RATHER THAN A get_post_meta() AT EVERY CALL SITE. Each of
 * these is BLANK BY DEFAULT and blank means "say nothing", not "say nothing is
 * known": an event with no cost line is not a free event, it is an event whose
 * cost was not given. So every one of them returns '' and the template tests
 * for '' in exactly one way, which is what keeps "not displayed when blank"
 * true in every place the value appears.
 *
 * They are INFORMATIONAL. Nothing here takes a payment, checks an age or
 * validates a phone number. See SFAF_Submit for what is actually collected.
 *
 * @param int $post_id
 * @return string
 */
function sfaf_event_cost( $post_id ) {
    return trim( (string) get_post_meta( (int) $post_id, SFAF_Submit::META_COST, true ) );
}

/** @param int $post_id @return string */
function sfaf_event_age_restriction( $post_id ) {
    return trim( (string) get_post_meta( (int) $post_id, SFAF_Submit::META_AGE, true ) );
}

/**
 * The contact the submitter gave FOR THE PUBLIC.
 *
 * NOT THE SUBMITTER'S OWN ADDRESS. That is kept internally under
 * SFAF_Request::META_EMAIL and is deliberately unreachable from any template:
 * somebody proposing an event to a campaign calendar has not agreed to have
 * their own address published, and this field is the one they answered knowing
 * it would be.
 *
 * @param int $post_id
 * @return string
 */
function sfaf_event_public_contact( $post_id ) {
    $post_id = (int) $post_id;

    /*
     * THREE FIELDS SINCE 3.47.0, AND ONE BEFORE THAT.
     *
     * The single open box is still read, because events submitted on 3.46.0
     * have one and there is no migration: a stored value that predates a
     * change is not a value to throw away. The three-part answer wins where
     * it exists, which is every submission from 3.47.0 onwards.
     *
     * ONE FORMATTER, in SFAF_Submit, so the event page, the admin email and
     * the submitter's copy cannot phrase the same three fields three ways.
     */
    $line = SFAF_Submit::contact_line( array(
        'contact_name'  => (string) get_post_meta( $post_id, SFAF_Submit::META_CONTACT_NAME, true ),
        'contact_email' => (string) get_post_meta( $post_id, SFAF_Submit::META_CONTACT_EMAIL, true ),
        'contact_phone' => (string) get_post_meta( $post_id, SFAF_Submit::META_CONTACT_PHONE, true ),
    ) );
    if ( '' !== $line ) {
        return $line;
    }
    return trim( (string) get_post_meta( $post_id, SFAF_Submit::META_CONTACT, true ) );
}

/**
 * The venue's own website, when one was given.
 *
 * BESIDE THE ADDRESS, AND NOT ON THE CARD. A card already carries a date, a
 * time, a place, a category and often a photo; a second link on it competes
 * with the one that opens the event. Somebody who wants the venue's hours is
 * already on the event page by the time they want them.
 *
 * Re-checked on the way out, for the reason sfaf_event_rsvp_url() is.
 *
 * @param int $post_id
 * @return string
 */
function sfaf_event_venue_website( $post_id ) {
    $url = trim( (string) get_post_meta( (int) $post_id, SFAF_Submit::META_VENUE_URL, true ) );
    if ( '' === $url ) {
        return '';
    }
    $scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
    return ( 'http' === $scheme || 'https' === $scheme ) ? $url : '';
}

/**
 * Where people register, when it is somewhere else.
 *
 * RE-CHECKED ON THE WAY OUT. It was checked on the way in, and this checks it
 * again before it becomes an href, because a stored value can predate the rule
 * that would have refused it and a link is the one place that matters.
 *
 * @param int $post_id
 * @return string
 */
function sfaf_event_rsvp_url( $post_id ) {
    $url = trim( (string) get_post_meta( (int) $post_id, SFAF_Submit::META_RSVP_URL, true ) );
    if ( '' === $url ) {
        return '';
    }
    $scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
    return ( 'http' === $scheme || 'https' === $scheme ) ? $url : '';
}
/**
 * The three usable stops of a category colour's own family.
 *
 * WHY THIS IS A TABLE AND NOT A CALCULATION.
 * ---------------------------------------------------------------------------
 * A category chip is a coloured pill with words in it, so it has to clear
 * 4.5:1, and a brand colour is almost never legible against itself. Brand
 * teal on brand teal is 1:1; the old chip used a 12% tint with the full
 * saturation colour as text, which measures 2.05:1 and was unreadable by any
 * standard. Reaching for black or grey text would fix the contrast and lose
 * the category, which is the one thing the chip exists to say.
 *
 * So every approved colour gets a DARKEST STOP OF ITS OWN FAMILY: same hue,
 * dropped in lightness until it clears the tint it sits on with headroom.
 * Every pair below is measured, not estimated: the worst of the eight
 * chromatic families is 6.01:1 on the chip tint and 5.54:1 on the deeper
 * media tint, against a 4.5:1 requirement.
 *
 *   family      chip bg   media bg  ink       chip ratio  media ratio
 *   Yellow      #FFFAE0   #FFF8D1   #705F00   6.01        5.89
 *   Orange      #FEF2E4   #FEEBD7   #8A4C05   6.11        5.80
 *   Red         #FDE9E7   #FCDEDB   #AD1C0D   6.10        5.63
 *   Burgundy    #F4E2E7   #EED3DA   #A30C33   6.35        5.63
 *   Pink        #FDEDF1   #FCE3EA   #B3103D   6.07        5.66
 *   Purple      #F1EAF4   #EAE0EE   #7F3A98   6.02        5.54
 *   Teal        #E3F7F9   #D5F3F6   #0C666F   6.02        5.72
 *   Green       #F1F8E9   #EAF5DE   #46661F   6.08        5.85
 *
 * The two neutrals are the exception that proves the rule: grey has no
 * chromatic family to darken into, so both take brand Dark Gray, which IS
 * their own family's darkest stop rather than a fallback to black.
 *
 * sfaf_sanitize_brand_color() is enforced on save, so a stored category
 * colour is always one of these ten. The computed fallback exists for legacy
 * rows written before that rule and is deliberately conservative: it mixes
 * toward white for the backgrounds and toward near-black for the ink, which
 * keeps the hue and cannot land on an illegible pair.
 *
 * @param string $hex Category colour.
 * @return array{tint:string,media:string,ink:string}
 */
function sfaf_category_shades( $hex ) {
    $hex = strtoupper( trim( (string) $hex ) );

    $table = array(
        '#FFD900' => array( '#FFFAE0', '#FFF8D1', '#705F00' ), // Yellow
        '#F7921E' => array( '#FEF2E4', '#FEEBD7', '#8A4C05' ), // Orange
        '#F04937' => array( '#FDE9E7', '#FCDEDB', '#AD1C0D' ), // Red
        '#A30C33' => array( '#F4E2E7', '#EED3DA', '#A30C33' ), // Burgundy
        '#F1668C' => array( '#FDEDF1', '#FCE3EA', '#B3103D' ), // Pink
        '#8D54A2' => array( '#F1EAF4', '#EAE0EE', '#7F3A98' ), // Purple
        '#16BECF' => array( '#E3F7F9', '#D5F3F6', '#0C666F' ), // Teal
        '#8CC745' => array( '#F1F8E9', '#EAF5DE', '#46661F' ), // Green
        '#D1D3D4' => array( '#F9FAFA', '#F7F7F7', '#373433' ), // Light Gray
        '#373433' => array( '#E7E7E7', '#DBDADA', '#373433' ), // Dark Gray
    );

    if ( isset( $table[ $hex ] ) ) {
        return array(
            'tint'  => $table[ $hex ][0],
            'media' => $table[ $hex ][1],
            'ink'   => $table[ $hex ][2],
        );
    }

    $rgb = sfaf_hex_to_rgb( $hex );
    if ( null === $rgb ) {
        return sfaf_category_shades( sfaf_default_category_color() );
    }

    return array(
        'tint'  => sfaf_mix_hex( $rgb, array( 255, 255, 255 ), 0.12 ),
        'media' => sfaf_mix_hex( $rgb, array( 255, 255, 255 ), 0.18 ),
        'ink'   => sfaf_mix_hex( $rgb, array( 26, 29, 33 ), 0.42 ),
    );
}

/**
 * '#RRGGBB' (or '#RGB') to an array( r, g, b ), or null when it is not a hex.
 *
 * @param string $hex
 * @return array|null
 */
function sfaf_hex_to_rgb( $hex ) {
    $hex = ltrim( trim( (string) $hex ), '#' );
    if ( 3 === strlen( $hex ) ) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    if ( ! preg_match( '/^[0-9a-fA-F]{6}$/', $hex ) ) {
        return null;
    }
    return array(
        hexdec( substr( $hex, 0, 2 ) ),
        hexdec( substr( $hex, 2, 2 ) ),
        hexdec( substr( $hex, 4, 2 ) ),
    );
}

/**
 * Mix two RGB triples in sRGB and return '#RRGGBB'.
 *
 * @param array $rgb    Foreground triple.
 * @param array $toward Triple to mix toward.
 * @param float $weight How much of $rgb survives, 0..1.
 * @return string
 */
function sfaf_mix_hex( $rgb, $toward, $weight ) {
    $weight = max( 0.0, min( 1.0, (float) $weight ) );
    $out    = '#';
    for ( $i = 0; $i < 3; $i++ ) {
        $v    = (int) round( $rgb[ $i ] * $weight + $toward[ $i ] * ( 1 - $weight ) );
        $out .= str_pad( dechex( max( 0, min( 255, $v ) ) ), 2, '0', STR_PAD_LEFT );
    }
    return strtoupper( $out );
}

/**
 * Effective image URL for an event, in priority order:
 * 1) the event's own featured image or _uc_image_url (both set by hand)
 * 2) _uc_external_image — whatever the third-party source last supplied
 * 3) the series-level image (term meta on the event's series)
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
    $series = sfaf_series_image_url( SFAF_Series::id_for_event( $post_id ) );
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
    if ( sfaf_series_image_url( SFAF_Series::id_for_event( $post_id ) ) ) {
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

/* -------------------------------------------------------------------------
 * LIST CARD helpers (3.1.0)
 *
 * These serve the LIST display mode only: the one renderer behind both the
 * [sfaf_calendar] shortcode and the embed, so anything here has to hold on
 * sfaf.org's stylesheet as well as on this site. The month grid, the sidebar
 * rows and the mobile day-detail list are untouched and keep their own
 * helpers.
 * ---------------------------------------------------------------------- */

/**
 * The platform an event was imported from ('Eventbrite', 'GoFundMe Pro'), or
 * '' for a native event.
 *
 * GUARDED ON PURPOSE. This is called once per card in a list that the public
 * embed endpoint serves, and an undefined static call there is a fatal on a
 * cross-origin response nobody can read the error out of. A missing sources
 * class costs the byline, not the page.
 *
 * @param int $post_id
 * @return string
 */
function sfaf_event_source_label( $post_id ) {
    if ( ! class_exists( 'SFAF_Sources' ) || ! method_exists( 'SFAF_Sources', 'provenance' ) ) {
        return '';
    }
    $provenance = SFAF_Sources::provenance( $post_id );
    return isset( $provenance['label'] ) ? (string) $provenance['label'] : '';
}

/**
 * Which icon stands in for a category on the placeholder tile.
 *
 * Keyed on the category NAME, the same way sfaf_event_placeholder_svg() has
 * always keyed its branded banner, so an event that has no image shows the
 * same symbol wherever it appears.
 *
 * @param string $name Category name.
 * @return string An icon name from sfaf_icon_paths().
 */
function sfaf_category_icon_key( $name ) {
    $map = array(
        'Support Groups'  => 'users',
        'Fundraising'     => 'heart',
        'Health Services' => 'cross',
        'Volunteer'       => 'handshake',
        'Program Groups'  => 'community',
    );
    $name = (string) $name;
    return isset( $map[ $name ] ) ? $map[ $name ] : 'calendar';
}

/**
 * The list card's media box: the event's image, or a branded category tile.
 *
 * THE PLACEHOLDER IS HTML, NOT AN SVG CANVAS, and that is the fix rather than
 * a preference. sfaf_event_placeholder_svg() is a 1600x900 drawing with
 * preserveAspectRatio="slice": correct in a 16:9 banner, wrong in the ~4:1
 * box this card uses, where slicing would crop the icon and the label off the
 * top and bottom. Switching to meet instead would letterbox and leave the
 * background unfilled. A flex box with an icon and a word in it is the right
 * shape at every width, needs no ratio to be true, and reads as a deliberate
 * category tile rather than an image that failed to load.
 *
 * Roughly half of imported GoFundMe Pro events will never have an image,
 * because their API does not expose one, so this is the normal case.
 *
 * @param int    $post_id
 * @param string $cat_name Category name, '' when uncategorised.
 * @return string
 */
/* =============================================================================
 * DATES AND TIMES, AP STYLE. ONE IMPLEMENTATION.
 *
 * The brand guide (v3.0, p.21) follows the AP Stylebook and calls out four
 * rules for times and one for dates. Every one of them was being broken, in
 * eleven separate places, because each place formatted its own:
 *
 *   ORDINALS.     "January 10, 2024", never "January 10th, 2024".
 *   CASE.         Lowercase am and pm.
 *   SPACING.      One space between the number and the meridiem: "10:30 pm".
 *   ":00".        Dropped, "especially in time ranges".
 *   RANGES.       An en dash, and if the meridiem is the same at both ends the
 *                 first mention is omitted: "10-10:30 am".
 *
 * WHY THESE ARE FUNCTIONS AND NOT A FORMAT STRING REPEATED ELEVEN TIMES. The
 * ":00" and shared-meridiem rules are decisions about a PAIR of times, which no
 * date() format string can express; the moment one has to be written out by
 * hand, eleven call sites means eleven chances to write it differently, which
 * is precisely what had happened. "6:00 PM to 7:30 PM" was on the cards,
 * "6:00pm" in the month grid and "6:00 PM" in the sidebar, all from the same
 * two meta fields. Same rule as the shared field lists: one list, one render.
 *
 * AN EN DASH IS NOT AN EM DASH. The guide asks for the first and discourages
 * the second; U+2013 is what a range takes and is used below.
 *
 * WHAT IS DELIBERATELY NOT DONE HERE: AP also abbreviates Jan., Aug., Sept.,
 * Oct., Nov. and Dec. with a full stop when they carry a specific date. The
 * guide does not call that out, and a full stop inside a 44px date badge on a
 * card reads as a typo rather than as style, so the compact badges stay
 * "Aug 4". Said here rather than left for somebody to find.
 * ========================================================================== */

/**
 * One clock time, AP style. "6 pm", "6:30 pm", or '' when there is none.
 *
 * @param string $raw       An H:i string out of post meta, or ''.
 * @param bool   $meridiem  false drops the am/pm, for the open end of a range.
 * @return string
 */
function sfaf_ap_time( $raw, $meridiem = true ) {
    $raw = trim( (string) $raw );
    if ( '' === $raw ) {
        return '';
    }
    $ts = strtotime( $raw );
    if ( false === $ts ) {
        return '';
    }
    // ":00" dropped: "6 pm", not "6:00 pm".
    $clock = ( '00' === date_i18n( 'i', $ts ) ) ? date_i18n( 'g', $ts ) : date_i18n( 'g:i', $ts );
    if ( ! $meridiem ) {
        return $clock;
    }
    return $clock . ' ' . strtolower( date_i18n( 'A', $ts ) );
}

/**
 * The `step` attribute for a time control, five minutes, when it is safe.
 *
 * WHY FIVE MINUTES. `<input type="time">` steps by one minute by default, so
 * setting an event to 6:30 with the stepper is thirty presses. Nothing on this
 * calendar starts at 6:07.
 *
 * WHAT HAPPENS TO A TIME OFF THE BOUNDARY: THE BROWSER REFUSES IT, and it does
 * so before the form posts, naming the two nearest valid times itself. That is
 * `step` doing what `step` means, and it is the honest outcome to pick: rounding
 * on save would change somebody's answer without telling them, and accepting it
 * would make the attribute a decoration.
 *
 * SO IT IS NOT SET ON A CONTROL ALREADY HOLDING SUCH A TIME. That is the whole
 * of what this function is for. A stored value can predate the rule, and the
 * import wrote its times from an export nobody here has read every row of; a
 * control carrying 6:07 with step="300" on it is a form that cannot be
 * submitted at all until somebody works out that the time field is the reason.
 * The event stays editable and its own time stays sayable, and the moment
 * somebody moves it onto a boundary the control gets the stepper.
 *
 * THE SERVER'S RULE IS UNCHANGED AND STAYS UNCHANGED. SFAF_Request::clean_time()
 * accepts any valid H:i, which is what makes the paragraph above work. This is a
 * property of a CONTROL, not of the data.
 *
 * @param string $value Current H:i value, or ''.
 * @return string ' step="300"' or ''.
 */
function sfaf_time_step_attr( $value ) {
    $value = trim( (string) $value );
    if ( '' === $value ) {
        return ' step="300"';
    }
    if ( ! preg_match( '/^(\d{1,2}):(\d{2})/', $value, $m ) ) {
        return '';
    }
    return ( 0 === ( (int) $m[2] % 5 ) ) ? ' step="300"' : '';
}

/**
 * A start and end as one phrase, AP style.
 *
 *   6 pm and 7:30 pm   ->  "6-7:30 pm"     (same meridiem, first one omitted)
 *   11 am and 1 pm     ->  "11 am-1 pm"    (different, so both are said)
 *   6 pm and nothing   ->  "6 pm"
 *
 * @param string $start
 * @param string $end
 * @return string
 */
function sfaf_ap_time_range( $start, $end = '' ) {
    $start = trim( (string) $start );
    $end   = trim( (string) $end );
    if ( '' === $start ) {
        return '';
    }
    if ( '' === $end ) {
        return sfaf_ap_time( $start );
    }

    $sts = strtotime( $start );
    $ets = strtotime( $end );
    if ( false === $sts || false === $ets ) {
        return sfaf_ap_time( $start );
    }

    $dash = "\xE2\x80\x93"; // en dash, U+2013
    $same = ( date_i18n( 'A', $sts ) === date_i18n( 'A', $ets ) );

    // The rule is "it's OK to omit the FIRST mention", so the meridiem is
    // dropped from the start and kept on the end.
    return sfaf_ap_time( $start, ! $same ) . $dash . sfaf_ap_time( $end );
}

/**
 * A stored date or datetime string, as a real timestamp on the site's clock.
 *
 * TWO KINDS OF STRING REACH THE FORMATTER AND THEY NEED DIFFERENT TREATMENT.
 *
 *   '2026-08-04'            an event date. A calendar day, no time in it.
 *   '2026-08-04 21:30:00'   a stored moment, written by current_time( 'mysql' ),
 *                           which is the site's WALL CLOCK with no zone on it.
 *
 * WordPress runs PHP in UTC, so strtotime() reads both as UTC. For the first
 * that is harmless as long as the time of day is nowhere near midnight, which
 * is why this has always anchored a bare date at MIDDAY: midday UTC is the same
 * calendar day everywhere anybody reading this lives.
 *
 * FOR THE SECOND IT WAS WRONG, and the wrongness had shipped for a while. The
 * old code appended ' 12:00:00' to whatever it was given, so a datetime came
 * out as "2026-08-04 21:30:00 12:00:00", which strtotime() cannot read at all:
 * the RSVP table's "Registered" column rendered EMPTY. Where it did parse, via
 * the separate date_i18n( ..., strtotime( $mysql ) ) call sites, the string was
 * read as UTC and then rendered in the site's zone, moving a 9pm registration
 * to 2am the next day.
 *
 * So a datetime is parsed IN THE SITE'S TIMEZONE, which is the zone it was
 * written in, and the resulting instant renders back to the same wall clock it
 * started as. A round trip that changes nothing is the correct behaviour for a
 * value that was never in any other zone.
 *
 * @param string $when
 * @return int|false
 */
function sfaf_local_timestamp( $when ) {
    $when = trim( (string) $when );
    if ( '' === $when ) {
        return false;
    }

    // Does it carry a time? A bare Y-m-d does not, and neither does anything
    // else without a colon in it.
    $has_time = ( false !== strpos( $when, ':' ) );

    try {
        $dt = new DateTime( $has_time ? $when : $when . ' 12:00:00', wp_timezone() );
    } catch ( Exception $e ) {
        return false;
    }
    return $dt->getTimestamp();
}

/**
 * A date, AP style. No ordinal, ever.
 *
 * 'month' AND 'daynum' EXIST FOR THE DATE TILE, which stacks "Aug" over "4" as
 * two separate elements and therefore cannot use a single formatted string.
 * They are here rather than at the call site because the call site was doing
 * `date( 'M', $ts )`, which is PHP's date() and not date_i18n(), so it read the
 * SERVER clock instead of the site's and an evening event could render on the
 * wrong day. Splitting a date into two spans is still formatting a date.
 *
 * 'short_year' AND 'month_year' WERE ADDED IN 3.24.2 BECAUSE THE CALL SITES
 * ALREADY EXISTED. Fourteen places were spelling out 'M j, Y' or 'F Y' by hand,
 * which is what "one formatter" is supposed to prevent; a style missing from
 * this list is the reason a call site writes its own, so the list is what has to
 * grow. Both are AP style already: no ordinal, month abbreviated in the short
 * form, spelled out in the month-and-year heading.
 *
 * @param int|string $when  Timestamp, or a Y-m-d string.
 * @param string     $style 'full' | 'day' | 'short' | 'short_year' | 'month_year'
 *                          | 'weekday' | 'month' | 'daynum'
 * @return string
 */
function sfaf_ap_date( $when, $style = 'full' ) {
    if ( is_string( $when ) ) {
        $when = sfaf_local_timestamp( $when );
    }
    if ( ! $when ) {
        return '';
    }
    $formats = array(
        'full'       => 'l, F j, Y',
        'day'        => 'F j',
        'short'      => 'M j',
        'short_year' => 'M j, Y',
        'month_year' => 'F Y',
        'weekday'    => 'D',
        'month'      => 'M',
        'daynum'     => 'j',
    );
    $fmt = isset( $formats[ $style ] ) ? $formats[ $style ] : $formats['full'];
    return date_i18n( $fmt, (int) $when );
}

/**
 * A date and a clock time as one phrase. "Aug 4, 2026 at 6 pm".
 *
 * FOR THE THREE PLACES THAT RECORD WHEN SOMETHING HAPPENED: the RSVP tables in
 * wp-admin and in the portal, and the reminder email. Each was formatting both
 * halves itself, and one of them was doing it with PHP's date() rather than
 * date_i18n(), which reads the SERVER clock. That is the same defect as the
 * compact card tile fixed in 3.21.1 and it is not cosmetic: a registration taken
 * at nine in the evening in San Francisco is already tomorrow on a UTC server,
 * so the table showed the wrong day.
 *
 * The time half is sfaf_ap_time()'s rules, applied to a timestamp: ":00" is
 * dropped and the meridiem is lowercase.
 *
 * @param int|string $when  Timestamp, or anything strtotime() reads.
 * @param string     $style Any sfaf_ap_date() style; the date half.
 * @return string
 */
function sfaf_ap_datetime( $when, $style = 'short_year' ) {
    if ( is_string( $when ) ) {
        // The site's clock, not UTC. See sfaf_local_timestamp(): these strings
        // come from current_time( 'mysql' ) and carry no zone, and reading them
        // as UTC is what moved a late-evening registration to the next day.
        $when = sfaf_local_timestamp( $when );
    }
    if ( ! $when ) {
        return '';
    }
    $when  = (int) $when;
    $date  = sfaf_ap_date( $when, $style );
    $clock = ( '00' === date_i18n( 'i', $when ) ) ? date_i18n( 'g', $when ) : date_i18n( 'g:i', $when );

    return $date . ' at ' . $clock . ' ' . strtolower( date_i18n( 'A', $when ) );
}

/**
 * A date range, AP style, with an en dash. "1-31 Aug".
 *
 * @param string $start Y-m-d
 * @param string $end   Y-m-d
 * @return string
 */
function sfaf_ap_date_range( $start, $end ) {
    $s = strtotime( trim( (string) $start ) . ' 12:00:00' );
    $e = strtotime( trim( (string) $end ) . ' 12:00:00' );
    if ( ! $s || ! $e ) {
        return '';
    }
    $dash = "\xE2\x80\x93";
    // Same month: the month is said once, at the end. "1-31 Aug".
    if ( date_i18n( 'Y-m', $s ) === date_i18n( 'Y-m', $e ) ) {
        return date_i18n( 'j', $s ) . $dash . date_i18n( 'j M', $e );
    }
    return date_i18n( 'j M', $s ) . $dash . date_i18n( 'j M', $e );
}

/**
 * The small square thumbnail used by the sidebar rows.
 *
 * The event's image, or the branded category tile at a size where a word will
 * not fit. The full tile (sfaf_list_card_media) carries the category name as
 * visible text; at 44px there is room for the icon and nothing else, so the
 * name moves to the accessible name instead of being dropped.
 *
 * @param int $post_id
 * @return string
 */
function sfaf_thumb_media( $post_id ) {
    $url = sfaf_event_image_url( $post_id );
    if ( '' !== $url ) {
        // aria-hidden and an empty alt: the title is right beside it and is the
        // same link, so a screen reader announcing the picture as well would
        // read the event twice.
        return '<img class="uc-thumb-img" src="' . esc_url( $url ) . '" alt="" aria-hidden="true"'
            . ' loading="lazy" decoding="async" />';
    }

    $primary  = sfaf_event_primary_category( $post_id );
    $name     = $primary ? $primary->name : '';
    $icon_key = $primary ? SFAF_Categories::icon( (int) $primary->term_id, $primary->name )
                         : sfaf_category_icon_key( $name );
    $color    = sfaf_event_category_color( $post_id );
    $shades   = sfaf_category_shades( $color );

    return '<span class="uc-thumb-ph" aria-hidden="true"'
        . ' style="--uc-cat-media: ' . esc_attr( $shades['media'] ) . '; --uc-cat-ink: ' . esc_attr( $shades['ink'] ) . ';">'
        . sfaf_icon( $icon_key, array( 'size' => '20px' ) )
        . '</span>';
}

/**
 * THE TILE NAMES THE EVENT'S OWN CATEGORY, AND FOR THREE RELEASES IT SAID
 * "Event".
 *
 * $cat_name was optional and the only caller, the list card, passed a variable
 * that had never been assigned. So every image-less card fell through to the
 * 'Event' fallback and raised an undefined-variable notice while doing it. About
 * half of imported events never get an image, so this is the tile most visitors
 * saw most often, and 3.31.0's readme claimed it carried the category name.
 *
 * THE NAME IS RESOLVED HERE RATHER THAN ASKED FOR. sfaf_thumb_media() and
 * sfaf_day_event_thumb() both derive it from sfaf_event_primary_category()
 * immediately above; this one took it as an argument and trusted the caller,
 * which is the one of the three that could be got wrong. The parameter stays for
 * a caller that genuinely knows better, and no longer has to be supplied.
 */
function sfaf_list_card_media( $post_id, $cat_name = '' ) {
    $url = sfaf_event_image_url( $post_id );
    if ( '' !== $url ) {
        return '<img class="uc-lc-img" src="' . esc_url( $url ) . '"'
            . ' alt="' . esc_attr( get_the_title( $post_id ) ) . '" loading="lazy" decoding="async" />';
    }

    // The icon comes from the category itself where there is one, so setting it
    // on the Categories screen changes the tile as well as the placeholder. The
    // tint and ink behind it are sfaf_category_shades(), unchanged: they are a
    // contrast-checked pair and small text must not sit on a raw brand colour.
    $primary = sfaf_event_primary_category( $post_id );
    if ( '' === $cat_name ) {
        $cat_name = $primary ? $primary->name : '';
    }

    // 'Event' is now only for an event in no category at all, which is what it
    // was always meant to cover.
    $label    = ( '' !== $cat_name ) ? $cat_name : 'Event';
    $icon_key = $primary ? SFAF_Categories::icon( (int) $primary->term_id, $primary->name )
                         : sfaf_category_icon_key( $cat_name );

    return '<span class="uc-lc-ph" role="img" aria-label="' . esc_attr( $label ) . '">'
        . '<span class="uc-lc-ph-icon">' . sfaf_icon( $icon_key, array( 'size' => '30px' ) ) . '</span>'
        . '<span class="uc-lc-ph-name">' . esc_html( $label ) . '</span>'
        . '</span>';
}

/**
 * The 32px thumbnail on a month grid day event.
 *
 * WHAT THIS REPLACED, AND WHY IT HAD TO GO. Until 3.31.0 each entry carried a
 * 3px accent bar down its left edge in the raw category colour. That is
 * decoration that cannot carry what it implies: six of the ten category hues
 * measure under the 3:1 WCAG asks of a non-text graphic on white (Yellow 1.38,
 * Light Gray 1.50, Green 2.02, Teal 2.26, Orange 2.31, Pink 2.99), so on the
 * densest category surface in the product the hues could not reliably be told
 * apart. A stripe of colour that a reader cannot resolve is a decoration
 * pretending to be information.
 *
 * THE RING IS INK, NOT THE BRAND HUE. sfaf_category_shades() returns a
 * contrast-checked pair and the ink half is what the chips already use for
 * their text. Every one of the ten clears 3:1 on both surfaces a ring is drawn
 * on, white and the out-of-month grey, with the lowest at 5.84. Measured by
 * .claude/category-ring-contrast.php, which fails the build if that stops
 * being true.
 *
 * THE PLACEHOLDER AT 32px IS THE CATEGORY ICON ON ITS TINT, WITH NO TEXT, and
 * that is a different composition from the list card's placeholder rather than
 * the same one shrunk. The list card's tile carries the category NAME beside
 * the icon; at 32 square there is no room for a word, and a scaled-down tile
 * with unreadable text in it is precisely what a broken image looks like. An
 * icon centred on its own category tint reads as a deliberate mark: it is the
 * same icon the category carries everywhere else, in the same colours as the
 * chip, at a size the icon was drawn for.
 *
 * @param int $post_id
 * @return string
 */
function sfaf_day_event_thumb( $post_id ) {
    $url = sfaf_event_image_url( $post_id );
    if ( '' !== $url ) {
        // aria-hidden: the title is right beside it and says the same thing.
        return '<span class="uc-de-thumb">'
            . '<img src="' . esc_url( $url ) . '" alt="" aria-hidden="true" loading="lazy" decoding="async" />'
            . '</span>';
    }

    $primary  = sfaf_event_primary_category( $post_id );
    $cat_name = $primary ? $primary->name : '';
    $icon_key = $primary ? SFAF_Categories::icon( (int) $primary->term_id, $primary->name )
                         : sfaf_category_icon_key( $cat_name );

    return '<span class="uc-de-thumb uc-de-thumb-ph" aria-hidden="true">'
        . sfaf_icon( $icon_key, array( 'size' => '17px' ) )
        . '</span>';
}

/**
 * Fundraising progress for the list card: the bar and the sentence, without
 * the Donate button (which is now the card's footer action).
 *
 * NO INVENTED FUNDRAISING NUMBERS. EVER. This is the same rule sfaf_donate_block()
 * documents at length, enforced identically here because this is a second
 * place the figures reach a donor. A bar needs two real numbers. A goal with
 * no total behind it states the goal and draws nothing, because a bar at zero
 * is a claim about how the appeal is going, and we do not have that fact.
 *
 * @param int $post_id
 * @return string
 */
function sfaf_fundraising_progress( $post_id ) {
    if ( ! get_post_meta( $post_id, '_uc_gofundme_url', true ) || ! sfaf_show_feature( $post_id, 'donate' ) ) {
        return '';
    }

    // Off unless a manager switched it on for this event. See
    // sfaf_show_fundraising_progress() for why the default is no.
    if ( ! sfaf_show_fundraising_progress( $post_id ) ) {
        return '';
    }

    $goal = (float) get_post_meta( $post_id, '_uc_gofundme_goal', true );
    if ( $goal <= 0 ) {
        return '';
    }

    /*
     * SWITCHED ON WITH NOTHING TO SHOW IS SILENCE, NOT A GOAL ON ITS OWN.
     *
     * The previous behaviour printed "$50,000 goal" when no raised figure had
     * arrived. Read on a fundraiser's page, a goal with no progress beside it
     * does not read as "we have not been told the total". It reads as zero
     * raised, which is a claim about how the appeal is going and one we have
     * no basis for. The rule from sfaf_donate_block() has not changed, only
     * hardened: a figure is displayed if and only if a real one is stored, and
     * where there is nothing real to say the section does not appear.
     */
    $raised_raw = get_post_meta( $post_id, '_uc_gofundme_raised', true );
    if ( '' === $raised_raw || ! is_numeric( $raised_raw ) ) {
        return '';
    }

    $raised  = (float) $raised_raw;
    $percent = (int) min( 100, round( $raised / $goal * 100 ) );

    return '<div class="uc-lc-fund">'
        . '<span class="uc-lc-fund-bar"><span class="uc-lc-fund-fill" style="width: ' . (int) $percent . '%"></span></span>'
        . '<span class="uc-lc-fund-text">$' . number_format( $raised ) . ' raised of $' . number_format( $goal ) . ' goal</span>'
        . '</div>';
}

/**
 * How many RSVP places are left, phrased for a visitor, or ''.
 *
 * "12 of 20 spots left" rather than "8/20 spots filled": the number somebody
 * is deciding on is what remains, and making them subtract is a small tax on
 * every card. Only events with a capacity we hold produce a sentence: an
 * imported event's remaining places live on the platform that sold them.
 *
 * @param int $post_id
 * @return string
 */
function sfaf_rsvp_spots_text( $post_id ) {
    if ( ! sfaf_event_takes_rsvps( $post_id ) ) {
        return '';
    }
    $capacity = (int) get_post_meta( $post_id, '_uc_capacity', true );
    if ( $capacity <= 0 ) {
        return '';
    }
    $left = max( 0, $capacity - sfaf_get_rsvp_count( $post_id ) );
    if ( $left < 1 ) {
        return 'Fully booked';
    }
    return sprintf(
        '%s of %s spots left',
        number_format_i18n( $left ),
        number_format_i18n( $capacity )
    );
}

/**
 * Volunteer places left on an imported Galaxy Digital need, or ''.
 *
 * The card's supporting line and the event page's volunteer block say the
 * same thing from the same place, so the two can never disagree about how
 * many places are left.
 *
 * @param int $post_id
 * @return string
 */
function sfaf_volunteer_spots_text( $post_id ) {
    if ( ! sfaf_is_galaxy_need( $post_id ) ) {
        return '';
    }
    $slots = get_post_meta( $post_id, '_uc_galaxy_slots', true );
    $slots = ( '' !== $slots ) ? (int) $slots : 8;
    if ( $slots < 1 ) {
        return 'No volunteer spots left';
    }
    return sprintf(
        _n( '%s volunteer spot remaining', '%s volunteer spots remaining', $slots ),
        number_format_i18n( $slots )
    );
}

/* -------------------------------------------------------------------------
 * Location and map
 *
 * NOTHING REACHES GOOGLE UNTIL SOMEBODY ASKS IT TO, AND THAT IS THE FEATURE.
 *
 * These pages carry HIV services, substance use programmes and trans health
 * groups. A Google Maps iframe placed in the markup is fetched on page view,
 * which hands Google the page URL, the visitor's IP and their referrer for
 * every single person who lands on one, whether or not they wanted a map. For
 * this calendar that is not an analytics footnote; it is a record of who
 * looked at which service.
 *
 * So the iframe does not exist in the document. It is created by script,
 * after a click, and only then. The address is a plain link to Google Maps at
 * all times, which meets the actual need (getting directions) at zero
 * third-party cost to anyone who does not press the button.
 *
 * IF YOU ARE HERE TO MAKE THE MAP LOAD AUTOMATICALLY: do not. There is no
 * lazy-loading attribute, no IntersectionObserver and no "only on desktop"
 * variant of this that keeps the property above. The click is the consent.
 * ---------------------------------------------------------------------- */

/**
 * The Google Maps Embed API key, or ''.
 *
 * Stored with the platform credentials rather than in uc_settings, for the
 * reason set out at the top of class-sfaf-credentials.php: uc_settings is
 * rebuilt from scratch on every settings save, so a key living there survives
 * only as long as the sanitize callback keeps remembering it.
 *
 * @return string
 */
function sfaf_google_maps_key() {
    if ( ! class_exists( 'SFAF_Credentials' ) ) {
        return '';
    }
    return (string) SFAF_Credentials::get( 'google_maps_embed_key' );
}

/**
 * A Google Maps link for a free-text location.
 *
 * Locations are typed by people and routinely carry a parenthetical:
 * "940 Howard Street, San Francisco, CA 94103 (SFAF Main Office)". Google's
 * search handles that perfectly well, so the whole string is passed through
 * as a query rather than being parsed into an address here. Guessing at which
 * part is the address is how "(SFAF Main Office)" becomes the destination.
 *
 * @param string $location
 * @return string Empty for an empty location.
 */
function sfaf_map_search_url( $location ) {
    $location = trim( (string) $location );
    if ( '' === $location ) {
        return '';
    }
    return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $location );
}

/**
 * The location section for the single event page: an address link always, and
 * the map itself when a key is configured.
 *
 * THE MAP LOADS ITSELF. THIS REVERSES THE 3.2.0 DECISION, ON INSTRUCTION.
 * ---------------------------------------------------------------------------
 * From 3.2.0 to 3.26.1 there was no iframe in this markup at all: an empty
 * placeholder carried a "Show map" button, and calendar.js built the frame when
 * somebody pressed it. The reasoning was recorded here and it was sound, so it
 * is recorded here still rather than deleted, because a note that vanishes is
 * one somebody restores the old behaviour from six months later without knowing
 * it was ever weighed:
 *
 *   A Google iframe loading on page view tells Google that this browser viewed
 *   this page. These pages cover HIV services, substance use programmes and
 *   trans health groups, so the page a visit is attributed to is itself
 *   sensitive. Deferring the frame behind a press made loading the map a choice
 *   the visitor made, and scrolling past something is not that choice.
 *
 * THAT COST HAS BEEN WEIGHED AND ACCEPTED. Mark has decided the map being
 * immediately visible is worth it. It is his call, it has been made with the
 * above in front of him, and this note exists so that anybody minded to "fix"
 * the automatic load knows it is the current answer to a question already
 * asked, and not an oversight.
 *
 * WHAT REMAINS TRUE. loading="lazy" is kept: the request fires when the section
 * comes near the viewport rather than at the top of the document. That is the
 * ordinary performance default and it changes nothing about what a visitor
 * sees, since this block sits well down the page. It is not a consent
 * mechanism and must not be described as one.
 *
 * WHAT RENDERS, IN EACH OF THE THREE STATES.
 *   no location        nothing at all.
 *   location, no key   the address as a Google Maps link. No map, no error, no
 *                      broken frame, no admin notice. A missing key is a
 *                      configuration fact and not a visitor's problem.
 *   location and key   the same link, plus the map.
 *
 * THE ADDRESS LINK IS NOT PART OF THE MAP and never has been. It is an ordinary
 * link that requests nothing until it is clicked, it is what somebody uses to
 * get directions on their phone, and it renders in both of the last two states.
 *
 * The key is in the iframe src now rather than in a data attribute, which is
 * the same exposure by a different route: both are in the served HTML and
 * readable by anybody who opens the source. It is a referrer restricted browser
 * key, and the readme sets out the restrictions it must carry.
 *
 * NO JAVASCRIPT. The map is server-rendered, so it works with scripts off and
 * there is no initialiser to fail. initMaps() and the data-uc-map attributes it
 * read are gone.
 *
 * @param int $post_id
 * @return string
 */
function sfaf_event_map_html( $post_id ) {
    /*
     * AN ONLINE EVENT HAS NO "GETTING THERE", AND THIS IS NOT COSMETIC.
     *
     * sfaf_event_location() answers "Online Event" for one, which would
     * otherwise be handed to Google twice: once as a maps search link and once
     * as the `q` of an embedded frame that loads on page view. The frame is the
     * part that matters. It would tell Google this browser viewed this page,
     * and these pages cover HIV services, substance use programmes and trans
     * health groups. Requesting a map of two words that are not a place buys
     * nothing and costs that.
     */
    if ( SFAF_Online::is_online( $post_id ) ) {
        return '';
    }

    $location = sfaf_event_location( $post_id );
    if ( '' === $location ) {
        return '';
    }

    $link = sfaf_map_search_url( $location );
    $key  = sfaf_google_maps_key();

    // Assembled here rather than in the template so the escaping is in one
    // expression next to the values it escapes.
    $src = '' !== $key
        ? 'https://www.google.com/maps/embed/v1/place?key=' . rawurlencode( $key ) . '&q=' . rawurlencode( $location )
        : '';

    ob_start();
    ?>
    <section class="uc-map" aria-labelledby="uc-map-heading-<?php echo (int) $post_id; ?>">
        <h2 class="uc-map-heading" id="uc-map-heading-<?php echo (int) $post_id; ?>">Getting there</h2>

        <p class="uc-map-address">
            <?php echo sfaf_icon( 'pin', array( 'size' => '16px' ) ); ?>
            <a href="<?php echo esc_url( $link ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $location ); ?></a>
        </p>

        <?php if ( '' !== $src ) : ?>
            <div class="uc-map-embed">
                <?php
                /*
                 * THE TITLE IS THE ACCESSIBLE NAME OF THE FRAME. An iframe with
                 * no title is announced as "frame" and nothing else, which on a
                 * page with one frame is a dead end. It names the place rather
                 * than saying "map", because the heading above already says
                 * that and a screen reader user tabbing into it wants to know
                 * which map.
                 *
                 * referrerpolicy is unchanged from the version calendar.js
                 * built: the frame needs nothing from this page.
                 */
                ?>
                <div class="uc-map-frame">
                    <iframe
                        src="<?php echo esc_url( $src ); ?>"
                        title="<?php echo esc_attr( 'Map of ' . $location ); ?>"
                        width="100%"
                        height="320"
                        style="border:0"
                        loading="lazy"
                        referrerpolicy="no-referrer-when-downgrade"
                        allowfullscreen></iframe>
                </div>
            </div>
        <?php endif; ?>
    </section>
    <?php
    return ob_get_clean();
}

/**
 * The list card's series row: the series by NAME, then "see all dates".
 *
 * WHY THE NAME AND NOT A LABEL. The old badge read "Part of series: Name" on
 * the card and a generic "Event Series" elsewhere, and a link whose text is a
 * category rather than a destination tells a screen reader user nothing about
 * where it goes: every series on the page produced the same announcement.
 * The name is the only part that identifies anything, so the name leads and
 * carries the link.
 *
 * Points at the term archive for the reasons set out on sfaf_series_link():
 * it is a real URL WordPress routes, it exists whether or not anyone has built
 * a calendar page, and it can show what the series IS rather than only when it
 * next meets.
 *
 * @param int $post_id
 * @return string Empty when the event is in no series.
 */
function sfaf_series_dates_link( $post_id ) {
    $term = SFAF_Series::for_event( $post_id );
    if ( ! $term ) {
        return '';
    }
    $url = SFAF_Series::url( $term->term_id );
    if ( '' === $url || '' === $term->name ) {
        return '';
    }

    return '<a class="uc-lc-series" href="' . esc_url( $url ) . '">'
        . '<span class="uc-lc-series-name">' . esc_html( $term->name ) . '</span>'
        . '<span class="uc-lc-series-all">see all dates</span>'
        . '</a>';
}

/**
 * The page a link in an email opens: confirm, unsubscribe, cancel a place.
 *
 * ONE RENDERER FOR ALL OF THEM, AND THAT IS THE POINT. SFAF_Follow::page() and
 * SFAF_Reminders::cancel_page() each had their own, and the second still went
 * out through wp_die() three releases after the first was fixed, which is what
 * two copies of a document shape buys. There is one now and both call it.
 *
 * WHY NOT wp_die(), AND WHY NO ENQUEUE WOULD HAVE FIXED IT. These pages had no
 * stylesheet at all, and it is worth being exact about which of the two possible
 * faults that was, because they want opposite fixes and look identical on
 * screen. It was NOT a stylesheet losing to something else. None ever reached
 * the page, for two independent reasons:
 *
 *   1. sfaf_enqueue_frontend_assets() is on `wp_enqueue_scripts`, which fires
 *      from inside wp_head(). These links are handled on `template_redirect`,
 *      which runs BEFORE the template, so the enqueue never ran at all.
 *   2. wp_die()'s front-end handler writes its own complete document with an
 *      inline <style> and never calls wp_head(). So even a stylesheet that had
 *      been enqueued would not have been printed.
 *
 * Raising specificity, reordering, or enqueueing harder would have changed
 * nothing, because there was no cascade to win. The page emits its own document
 * instead, which is what caladmin and both public forms already do.
 *
 * IT IS THE PUBLIC LOOK, NOT caladmin's. Somebody reading this clicked a link in
 * an email. They are a visitor, and the surface they came from is the event
 * page, so it loads calendar.css.
 *
 * THE BODY CLASS IS LOAD-BEARING. calendar.css hangs both this page's styling
 * and the Weglot suppression off `uc-notice-page`. Losing it puts an
 * English/Espanol control the calendar never asked for back on the page.
 *
 * $title is escaped here. $html is markup built by its caller, which has escaped
 * anything variable inside it, so it is passed through.
 *
 * This does not return.
 *
 * @param string $title
 * @param string $html
 */
/**
 * The calendar's favicon links, for a page that builds its own <head>.
 *
 * THIS PLUGIN OWNS THE ICON. It is not the site's and it is not the theme's:
 * `public/images/favicon-caladmin.*` is bundled with the code and drawn by
 * `.claude/build-favicon.js` from `sfaf_icon_paths()['calendar']`, in Dark Gray
 * #373433 on brand Yellow #FFD900. It travels with the plugin and cannot be
 * deleted from the media library by somebody tidying up.
 *
 * WHY IT IS A FUNCTION NOW (3.66.0). caladmin declared these three lines
 * inline, and the comment beside them said the public forms did not need them
 * because they went through `wp_head()`. They do not. `SFAF_Submissions::page_open()`
 * and `sfaf_notice_page()` write their own documents with hand-written heads and
 * call `wp_head()` nowhere, which is the same reason those pages loaded no
 * stylesheet until 3.44.0. So they had no icon from either direction: nothing in
 * their document pointed at one, and there was no `wp_head()` to supply the
 * site's.
 *
 * FOUR SURFACES, ONE DECLARATION. caladmin, the staff request form, the
 * community submission form, and the notice page the follow links and the
 * registration cancel link land on. A second copy of three <link> tags is three
 * more things to update when the icon changes, which is the fault this project
 * has paid for with the FAQ row nine times over.
 *
 * SVG FIRST, PNG SECOND, AND THE ORDER IS THE FALLBACK. A browser that
 * understands image/svg+xml takes the first and stops; one that does not
 * ignores it and takes the PNG. Safari is why the PNG is not optional. The
 * 180px one is what iOS uses for a home-screen shortcut.
 */
function sfaf_favicon_links() {
    $base = SFAF_PLUGIN_URL . 'public/images/';
    $ver  = '?ver=' . SFAF_VERSION;
    ?>
<link rel="icon" type="image/svg+xml" href="<?php echo esc_url( $base . 'favicon-caladmin.svg' . $ver ); ?>" />
<link rel="icon" type="image/png" sizes="32x32" href="<?php echo esc_url( $base . 'favicon-caladmin.png' . $ver ); ?>" />
<link rel="apple-touch-icon" href="<?php echo esc_url( $base . 'favicon-caladmin-180.png' . $ver ); ?>" />
<?php
}

function sfaf_notice_page( $title, $html ) {
    nocache_headers();
    status_header( 200 );
    header( 'Content-Type: text/html; charset=utf-8' );
    /* Nothing here should ever be framed or indexed: the URL is a token. */
    header( 'X-Frame-Options: SAMEORIGIN' );
    ?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<meta name="robots" content="noindex, nofollow" />
<title><?php echo esc_html( $title ); ?></title>
<?php sfaf_favicon_links(); // Hand-written head, no wp_head(). See 3.66.0. ?>
<link rel="stylesheet" href="<?php echo esc_url( SFAF_PLUGIN_URL . 'public/css/calendar.css?ver=' . SFAF_VERSION ); ?>" />
</head>
<body class="uc-notice-page">
<main class="uc-notice">
    <div class="uc-notice-card">
        <h1 class="uc-notice-title"><?php echo esc_html( $title ); ?></h1>
        <?php echo $html; ?>
    </div>
</main>
</body>
</html>
    <?php
    exit;
}

/**
 * ONE FAQ REPEATER ROW, AND THERE IS ONLY THIS ONE.
 *
 * NINE COPIES BEFORE 3.56.0, across five files: the caladmin event editor and
 * its template, the FAQ Sets screen and its template, the wp-admin series box
 * and its template, and both public forms with theirs. They had already drifted
 * on three axes, the index token, the maxlength and the remove button's
 * classes, and each of the last three FAQ changes had to be made nine times or
 * be made incompletely. This project has paid for the two-copies pattern more
 * than once; nine was the record.
 *
 * WHAT LEGITIMATELY VARIES is passed in, and it is only this: the field name,
 * the index (a real one for a stored row, a token for a <template>), the values,
 * whether the answer is a rich editor or a plain textarea, the maxlength the
 * public forms impose, and the button classes wp-admin needs.
 *
 * THE REMOVE CONTROL IS A BUTTON UNDER THE ROW, not a small red x in the
 * corner. Removing somebody's typed work should be findable and deliberate,
 * and the corner x was neither. Whether it asks first is decided in the
 * browser, on whether the row holds anything: see the handler in portal.js.
 * Nothing in caladmin warns about unsaved work and there is no undo, so a
 * mis-pressed remove on a written row cannot be taken back.
 *
 * @param array $args
 *   name        string  Field base, e.g. 'faq' or 'faq_set_rows'.
 *   index       string  Real index, or the template token ('__I__', '__INDEX__').
 *   question    string  Stored value.
 *   answer      string  Stored value.
 *   rich        bool    Rich editor (default) or a plain textarea.
 *   maxlength   int     On the question. 0 for none.
 *   remove_class string Classes for the remove button.
 *   rows        int     Editor/textarea rows.
 * @return void Echoes.
 */
function sfaf_faq_row( $args = array() ) {
    $args = array_merge( array(
        'name'         => 'faq',
        'index'        => '__I__',
        'question'     => '',
        'answer'       => '',
        'rich'         => true,
        'maxlength'    => 0,
        'remove_class' => 'uc-btn uc-btn-sm',
        'rows'         => 8,
    ), $args );

    // The index is a real integer or a template token, and both go into a
    // field name, so neither may be escaped as if it were prose.
    $field = $args['name'] . '[' . $args['index'] . '][%s]';
    $q     = sprintf( $field, 'question' );
    $a     = sprintf( $field, 'answer' );
    $max   = (int) $args['maxlength'] > 0 ? ' maxlength="' . (int) $args['maxlength'] . '"' : '';
    ?>
    <div class="uc-repeater-row uc-faq-row">
        <input type="text" name="<?php echo esc_attr( $q ); ?>"<?php echo $max; ?>
               value="<?php echo esc_attr( (string) $args['question'] ); ?>"
               placeholder="Question" aria-label="Question" />
        <?php
        if ( $args['rich'] ) {
            SFAF_Rich_Text::deferred( $a, (string) $args['answer'], array( 'rows' => (int) $args['rows'], 'placeholder' => 'Answer' ) );
        } else {
            printf(
                '<textarea name="%s" rows="%d" placeholder="Answer" aria-label="Answer">%s</textarea>',
                esc_attr( $a ),
                (int) $args['rows'],
                esc_textarea( (string) $args['answer'] )
            );
        }
        ?>
        <div class="uc-faq-row-actions">
            <button type="button" class="<?php echo esc_attr( $args['remove_class'] ); ?> uc-repeater-remove uc-faq-remove">Remove this question</button>
        </div>
    </div>
    <?php
}
