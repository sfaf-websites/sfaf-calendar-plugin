<?php
/**
 * EVENT VIDEO: one link, two services, and one resolver everything reads.
 *
 * WHAT THIS IS. A "Video" field on the event and the same field on the series.
 * Each takes ONE link, and only a YouTube or Vimeo page address. The event page
 * embeds whatever the resolver returns, above the description, and nothing else
 * anywhere shows a video at all.
 *
 * WHY A SERIES CARRIES ONE. A recurring group runs the same session every week
 * and the video introducing it is the same video every week. Entering it once on
 * the series and having every occurrence show it is the whole point; the
 * alternative is 52 copies of one URL and 52 places to correct it.
 *
 * SO THE RESOLUTION HAS THREE STEPS AND THE THIRD IS THE ONE THAT MATTERS:
 *
 *     the event's own video          wins
 *     else the series' video         inherited
 *     unless the event says NO       nothing renders
 *
 * "NO VIDEO" IS A CONTROL, NOT AN EMPTY FIELD, and it has to be. Inheritance
 * means an empty event field is the request to inherit, so with only a text box
 * there is no way to say "this one occurrence has no video" that is not also
 * "use the series' one". That is the same shape as a default that cannot be
 * turned off, and the answer is the same: an explicit opt out. `video_none()`
 * is read BEFORE the event's own URL, so a tick beats a link and clearing the
 * tick restores whatever was underneath.
 *
 * NEVER EMBED CODE, AND THAT IS A SECURITY DECISION RATHER THAN A TIDINESS ONE.
 * ---------------------------------------------------------------------------
 * An <iframe> pasted into a field is markup somebody else wrote, served from our
 * own domain, and a caladmin editor is not the right place to decide whether a
 * third party's frame attributes are safe. So the field takes an ADDRESS, the
 * address is parsed to an id, and this file builds the frame itself. Anything
 * carrying a tag is refused outright rather than stripped, because stripping
 * teaches somebody that pasting embed code nearly works.
 *
 * The URL is stored EXACTLY AS GIVEN. What is parsed out of it is the service
 * and the id, and those are re-derived on every render rather than stored
 * beside it: two copies of one fact is how a corrected link goes on playing the
 * old video. The stored string is what somebody typed, so the editor shows them
 * back what they entered.
 *
 * YOUTUBE THROUGH THE NO-COOKIE HOST. youtube-nocookie.com serves the same
 * player and sets no cookie until somebody presses play. This calendar carries
 * HIV, substance use and trans health programming, and a visitor reading an
 * event page has not asked to be tracked by Google for having read it. Vimeo's
 * player host is the only one it has.
 *
 * FOUR ACCEPTED SHAPES, and they are the four somebody actually has in their
 * clipboard:
 *
 *     https://www.youtube.com/watch?v=ID
 *     https://youtu.be/ID
 *     https://www.youtube.com/shorts/ID
 *     https://vimeo.com/123456789
 *
 * A YouTube id is 11 characters of an alphabet that includes - and _, so it is
 * matched on shape rather than length alone; a Vimeo page id is digits. An
 * address that is neither service is refused with a message naming both,
 * because "invalid URL" tells somebody holding a Facebook link nothing about
 * what to do next.
 *
 * @package SFAF_Calendar
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SFAF_Video {

    /** The event's own video URL, exactly as it was typed. */
    const META = '_uc_video_url';

    /**
     * "This event has no video", which is the only way to refuse an inherited
     * one. Stored as '1' when on and deleted when off, so an absent value is
     * off and no event needs migrating.
     */
    const META_NONE = '_uc_video_none';

    /** What the refusal says. It names both services, because that is the fix. */
    const REFUSAL = 'That is not a YouTube or Vimeo link. Paste the address from the browser bar, like https://www.youtube.com/watch?v=… or https://vimeo.com/…';

    /** What the refusal says when somebody pasted a frame instead of a link. */
    const REFUSAL_EMBED = 'That is embed code, not a link. Paste the address from the browser bar instead, like https://www.youtube.com/watch?v=… or https://vimeo.com/…';

    /**
     * Take an address apart, or say it is not one of ours.
     *
     * THE ONE PLACE A URL BECOMES A SERVICE AND AN ID. Validation, rendering
     * and the tests all come through here, so "what counts as a YouTube link"
     * has one answer and cannot drift between the thing that accepts a value
     * and the thing that draws it.
     *
     * @param string $url
     * @return array|false array( 'service' => 'youtube'|'vimeo', 'id' => string, 'embed' => string )
     */
    public static function parse( $url ) {
        $url = trim( (string) $url );
        if ( '' === $url ) {
            return false;
        }

        /*
         * EMBED CODE IS REFUSED BEFORE ANYTHING ELSE LOOKS AT IT. A pasted
         * <iframe> contains a perfectly good src, so a parser that went hunting
         * for a video id would find one and accept the markup around it. The
         * presence of a tag is the whole test.
         */
        if ( false !== strpos( $url, '<' ) || false !== strpos( $url, '>' ) ) {
            return false;
        }

        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
            return false;
        }

        $host = strtolower( $parts['host'] );
        $host = preg_replace( '/^www\./', '', $host );
        $path = isset( $parts['path'] ) ? (string) $parts['path'] : '';

        /*
         * A YOUTUBE ID IS MATCHED ON SHAPE. Eleven characters of
         * [A-Za-z0-9_-], which is what the service issues. Anchored at both
         * ends so a path with anything after the id is not quietly truncated
         * into a different video.
         */
        $yt = '[A-Za-z0-9_-]{11}';

        if ( 'youtube.com' === $host || 'm.youtube.com' === $host || 'music.youtube.com' === $host ) {
            // watch?v=ID
            if ( '/watch' === $path && ! empty( $parts['query'] ) ) {
                parse_str( (string) $parts['query'], $q );
                if ( ! empty( $q['v'] ) && preg_match( '#^' . $yt . '$#', (string) $q['v'] ) ) {
                    return self::youtube( (string) $q['v'] );
                }
                return false;
            }
            // shorts/ID
            if ( preg_match( '#^/shorts/(' . $yt . ')$#', $path, $m ) ) {
                return self::youtube( $m[1] );
            }
            /*
             * /embed/ID IS NOT ACCEPTED, and that is deliberate. It is the
             * address out of embed code rather than one out of a browser bar,
             * so accepting it is accepting the thing the field refuses one line
             * at a time. Somebody holding one still has the watch page.
             */
            return false;
        }

        if ( 'youtu.be' === $host ) {
            if ( preg_match( '#^/(' . $yt . ')$#', $path, $m ) ) {
                return self::youtube( $m[1] );
            }
            return false;
        }

        if ( 'vimeo.com' === $host ) {
            /*
             * A VIMEO PAGE IS DIGITS, and an unlisted one carries a second
             * hash segment. Both are page addresses somebody copies out of the
             * bar, so both resolve to the same player id; the hash is a
             * viewing credential for the page and the player does not take it
             * in this position.
             */
            if ( preg_match( '#^/(\d{6,})(?:/[A-Za-z0-9]+)?$#', $path, $m ) ) {
                return self::vimeo( $m[1] );
            }
            return false;
        }

        /*
         * player.vimeo.com IS NOT ACCEPTED, for the reason /embed/ is not:
         * it is the address inside a frame rather than the page address.
         */
        return false;
    }

    /**
     * @param string $id
     * @return array
     */
    private static function youtube( $id ) {
        return array(
            'service' => 'youtube',
            'id'      => $id,
            // The no-cookie host, for the reason in this file's header.
            'embed'   => 'https://www.youtube-nocookie.com/embed/' . rawurlencode( $id ),
        );
    }

    /**
     * @param string $id
     * @return array
     */
    private static function vimeo( $id ) {
        return array(
            'service' => 'vimeo',
            'id'      => $id,
            'embed'   => 'https://player.vimeo.com/video/' . rawurlencode( $id ),
        );
    }

    /**
     * Is this something we will take, and if not, what do we say?
     *
     * An EMPTY value is valid: it is how somebody clears the field. The caller
     * decides whether empty is allowed, which is the same split every other
     * optional field here uses.
     *
     * @param string $url
     * @return true|WP_Error
     */
    public static function validate( $url ) {
        $url = trim( (string) $url );
        if ( '' === $url ) {
            return true;
        }
        if ( false !== strpos( $url, '<' ) || false !== strpos( $url, '>' ) ) {
            return new WP_Error( 'sfaf_video_embed', self::REFUSAL_EMBED );
        }
        if ( ! self::parse( $url ) ) {
            return new WP_Error( 'sfaf_video_bad', self::REFUSAL );
        }
        return true;
    }

    /**
     * The event's own stored video URL, whatever the series says.
     *
     * @param int $event_id
     * @return string
     */
    public static function own( $event_id ) {
        $event_id = (int) $event_id;
        if ( ! $event_id ) {
            return '';
        }
        return (string) get_post_meta( $event_id, self::META, true );
    }

    /**
     * Has this event been told to show no video at all?
     *
     * @param int $event_id
     * @return bool
     */
    public static function is_none( $event_id ) {
        $event_id = (int) $event_id;
        if ( ! $event_id ) {
            return false;
        }
        return '1' === (string) get_post_meta( $event_id, self::META_NONE, true );
    }

    /**
     * THE RESOLVER. Every surface that shows a video reads this and nothing else.
     *
     * The order is the design and is stated in this file's header: the opt out
     * beats everything, then the event's own link, then the series'. Returning
     * the URL rather than the parsed parts keeps this answering one question;
     * whoever renders asks parse() for the rest.
     *
     * @param int $event_id
     * @return string '' when there is nothing to show.
     */
    public static function resolve( $event_id ) {
        $event_id = (int) $event_id;
        if ( ! $event_id ) {
            return '';
        }

        if ( self::is_none( $event_id ) ) {
            return '';
        }

        $own = self::own( $event_id );
        if ( '' !== $own ) {
            return $own;
        }

        if ( ! class_exists( 'SFAF_Series' ) ) {
            return '';
        }
        $term_id = SFAF_Series::id_for_event( $event_id );
        if ( ! $term_id ) {
            return '';
        }
        return SFAF_Series::video( $term_id );
    }

    /**
     * Where a resolved video came from, for the editor to say so.
     *
     * @param int $event_id
     * @return string 'own', 'series', 'none' or ''
     */
    public static function source( $event_id ) {
        $event_id = (int) $event_id;
        if ( ! $event_id ) {
            return '';
        }
        if ( self::is_none( $event_id ) ) {
            return 'none';
        }
        if ( '' !== self::own( $event_id ) ) {
            return 'own';
        }
        return ( '' !== self::resolve( $event_id ) ) ? 'series' : '';
    }

    /**
     * The frame, or nothing.
     *
     * LAZY, AND TITLED WITH THE EVENT'S NAME. The title attribute is what a
     * screen reader announces for a frame, and "YouTube video player" on every
     * event page tells somebody nothing about which video they have landed on.
     *
     * THE ASPECT RATIO IS CSS, NOT A WIDTH AND A HEIGHT. The frame is given
     * width and height attributes all the same, because they are what stops a
     * browser reserving the wrong box before the stylesheet arrives.
     *
     * @param int $event_id
     * @return string
     */
    public static function embed_html( $event_id ) {
        $event_id = (int) $event_id;
        $url      = self::resolve( $event_id );
        if ( '' === $url ) {
            return '';
        }
        $v = self::parse( $url );
        if ( ! $v ) {
            /*
             * A STORED VALUE THAT NO LONGER PARSES RENDERS NOTHING. The same
             * rule 3.90.0 set for a stored id that no longer resolves: treat it
             * as absent rather than drawing a broken frame.
             */
            return '';
        }

        $title = get_the_title( $event_id );
        if ( '' === trim( (string) $title ) ) {
            $title = 'Event video';
        }

        return '<div class="uc-video" data-uc-video="' . esc_attr( $v['service'] ) . '">'
             . '<iframe class="uc-video-frame" src="' . esc_url( $v['embed'] ) . '"'
             . ' title="' . esc_attr( $title ) . '"'
             . ' width="560" height="315" loading="lazy"'
             . ' frameborder="0"'
             . ' allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"'
             . ' allowfullscreen></iframe>'
             . '</div>';
    }
}
