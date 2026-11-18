<?php
/**
 * ANNOUNCEMENTS ON THE CALENDAR (3.142.0).
 *
 * A title on a day or a run of days, with an optional icon, description,
 * picture and link. Drawn as a slim dark bar at the top of each day on the
 * month grid, as a banner in the list, and in the sidebar on its own days.
 *
 * AN OPTION, NOT A POST TYPE, FOR THE REASON CLOSURES ARE ONE. An
 * announcement is not an event: no page, no registration, no reminder, no
 * .ics entry, no place in search or the pending queue. Kept out of all of
 * those by storage rather than by a list of exclusions. See SFAF_Closures.
 *
 * IT DOES NOT MARK A DAY CLOSED, and nothing here reads or writes closures.
 *
 * THE ICON. A PNG goes into the Media Library like any other picture. An SVG
 * never becomes a file on this domain: it is checked, kept in the option as
 * text, and drawn as a data: URI inside an <img>, where a script in it could
 * not run even if one got past the check.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Announcements {

    const OPTION = 'sfaf_announcements';
    const MAX    = 200;

    /* The icon rule. */
    const ICON_MAX_PX      = 512;
    const ICON_PNG_BYTES   = 102400; // 100KB.
    const ICON_SVG_BYTES   = 10240;  // 10KB.
    const ICON_RULE_MESSAGE = 'Icons must be a square PNG or SVG with a transparent background, 512 by 512 pixels or smaller, under 100KB (10KB for an SVG).';

    const TITLE_MAX = 80;
    const DESC_MAX  = 300;

    /* ---------------------------------------------------------------------
     * Reading
     * ------------------------------------------------------------------- */

    /**
     * Every announcement, earliest first. Rebuilt from a fixed set of keys,
     * so a key save() writes and this list forgets is caught by the checks.
     *
     * @return array[]
     */
    public static function all() {
        $raw = get_option( self::OPTION, array() );
        if ( ! is_array( $raw ) ) {
            return array();
        }
        $out = array();
        foreach ( $raw as $id => $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $start = isset( $row['start'] ) ? self::clean_date( $row['start'] ) : '';
            $title = isset( $row['title'] ) ? self::clean_text( $row['title'], self::TITLE_MAX ) : '';
            if ( '' === $start || '' === $title ) {
                continue;
            }
            $end = isset( $row['end'] ) ? self::clean_date( $row['end'] ) : '';
            if ( '' === $end || $end < $start ) {
                $end = $start;
            }
            $out[] = array(
                'id'       => (string) $id,
                'title'    => $title,
                'start'    => $start,
                'end'      => $end,
                'desc'     => isset( $row['desc'] ) ? self::clean_text( $row['desc'], self::DESC_MAX ) : '',
                'link'     => isset( $row['link'] ) ? self::clean_link( $row['link'] ) : '',
                'icon_id'  => isset( $row['icon_id'] ) ? max( 0, (int) $row['icon_id'] ) : 0,
                'icon_svg' => ( isset( $row['icon_svg'] ) && '' === self::svg_icon_error( (string) $row['icon_svg'] ) ) ? (string) $row['icon_svg'] : '',
                'image_id' => isset( $row['image_id'] ) ? max( 0, (int) $row['image_id'] ) : 0,
            );
        }
        usort( $out, function ( $a, $b ) {
            $c = strcmp( $a['start'], $b['start'] );
            return 0 !== $c ? $c : strcmp( $a['id'], $b['id'] );
        } );
        return $out;
    }

    /** One announcement, or null. */
    public static function get( $id ) {
        foreach ( self::all() as $row ) {
            if ( $row['id'] === (string) $id ) {
                return $row;
            }
        }
        return null;
    }

    /**
     * Every announcement covering this date, earliest start first.
     *
     * @param string $date Y-m-d
     * @return array[]
     */
    public static function covering( $date ) {
        $date = self::clean_date( $date );
        if ( '' === $date ) {
            return array();
        }
        $out = array();
        foreach ( self::all() as $row ) {
            if ( $date >= $row['start'] && $date <= $row['end'] ) {
                $out[] = $row;
            }
        }
        return $out;
    }

    /**
     * Announcements overlapping a window, one entry each, for a list.
     *
     * @return array[]
     */
    public static function spans( $from, $to ) {
        $from = self::clean_date( $from );
        $to   = self::clean_date( $to );
        if ( '' === $from || '' === $to ) {
            return array();
        }
        $out = array();
        foreach ( self::all() as $row ) {
            if ( $row['end'] >= $from && $row['start'] <= $to ) {
                $out[] = $row;
            }
        }
        return $out;
    }

    /** The icon's address, or ''. */
    public static function icon_src( $row ) {
        if ( '' !== $row['icon_svg'] ) {
            return 'data:image/svg+xml;base64,' . base64_encode( $row['icon_svg'] );
        }
        if ( $row['icon_id'] ) {
            $src = wp_get_attachment_image_url( (int) $row['icon_id'], 'full' );
            return $src ? (string) $src : '';
        }
        return '';
    }

    /** The picture's address, or ''. */
    public static function image_src( $row ) {
        if ( ! $row['image_id'] ) {
            return '';
        }
        $src = wp_get_attachment_image_url( (int) $row['image_id'], 'full' );
        return $src ? (string) $src : '';
    }

    /**
     * The dates, short: "Wed, Nov 18", "Nov 18–19", "Nov 30–Dec 2", and with
     * the year when $year is true. The ranges take the en dash.
     *
     * @param array $row
     * @param bool  $year
     * @return string
     */
    public static function when( $row, $year = true ) {
        $s = $row['start'];
        $e = $row['end'];
        $y = $year ? ', ' . substr( $e, 0, 4 ) : '';
        if ( $s === $e ) {
            return sfaf_ap_date( $s, $year ? 'short_weekday_year' : 'short_weekday' );
        }
        $dash = "\xE2\x80\x93";
        if ( substr( $s, 0, 4 ) !== substr( $e, 0, 4 ) ) {
            return $year
                ? sfaf_ap_date( $s, 'short_year' ) . $dash . sfaf_ap_date( $e, 'short_year' )
                : sfaf_ap_date( $s, 'short' ) . $dash . sfaf_ap_date( $e, 'short' );
        }
        if ( substr( $s, 0, 7 ) === substr( $e, 0, 7 ) ) {
            return sfaf_ap_date( $s, 'short' ) . $dash . sfaf_ap_date( $e, 'daynum' ) . $y;
        }
        return sfaf_ap_date( $s, 'short' ) . $dash . sfaf_ap_date( $e, 'short' ) . $y;
    }

    /**
     * ONE BAR, FOR EVERY SURFACE. The grid cell, the list banner and the
     * sidebar all draw this, so the three cannot disagree.
     *
     * A button only when there is a card to open: with no picture,
     * description or link, the bar is a plain line, because a control that
     * opens an empty card is a control that does nothing.
     *
     * @param array  $row
     * @param string $where 'cell', 'list' or 'sidebar'.
     * @return string
     */
    public static function bar( $row, $where = 'cell' ) {
        $icon  = self::icon_src( $row );
        $img   = self::image_src( $row );
        $card  = ( '' !== $img || '' !== $row['desc'] || '' !== $row['link'] );
        $when  = self::when( $row );
        $class = 'uc-ann-bar uc-ann-' . ( 'cell' === $where ? 'cell' : ( 'sidebar' === $where ? 'sidebar' : 'banner' ) );

        $inner = '';
        if ( '' !== $icon ) {
            $inner .= '<img class="uc-ann-icon" src="' . esc_attr( $icon ) . '" alt="" width="16" height="16" decoding="async" />';
        }
        $inner .= '<span class="uc-ann-title">' . esc_html( $row['title'] ) . '</span>';
        if ( 'cell' !== $where ) {
            $inner .= '<span class="uc-ann-when">' . esc_html( self::when( $row, false ) ) . '</span>';
        }

        if ( ! $card ) {
            return '<span class="' . esc_attr( $class ) . '" data-uc-ann-id="' . esc_attr( $row['id'] ) . '">' . $inner . '</span>';
        }
        return '<button type="button" class="' . esc_attr( $class ) . '" data-uc-ann'
            . ' data-uc-ann-id="' . esc_attr( $row['id'] ) . '"'
            . ' aria-expanded="false" aria-haspopup="dialog"'
            . ' data-uc-ann-title="' . esc_attr( $row['title'] ) . '"'
            . ' data-uc-ann-when="' . esc_attr( $when ) . '"'
            . ( '' !== $img ? ' data-uc-ann-img="' . esc_url( $img ) . '"' : '' )
            . ( '' !== $row['desc'] ? ' data-uc-ann-desc="' . esc_attr( $row['desc'] ) . '"' : '' )
            . ( '' !== $row['link'] ? ' data-uc-ann-link="' . esc_url( $row['link'] ) . '"' : '' )
            . '>' . $inner . '</button>';
    }

    /* ---------------------------------------------------------------------
     * Writing
     * ------------------------------------------------------------------- */

    /**
     * Create or update an announcement.
     *
     * @param string $id '' to create.
     * @param array  $f  title, start, end, desc, link, icon_id, icon_svg, image_id.
     * @return string|WP_Error The id.
     */
    /**
     * What is wrong with these fields, or ''. Asked by save(), and by the
     * screen before any file is stored, so a refused save stores nothing.
     *
     * @param array $f
     * @return string
     */
    public static function field_error( $f ) {
        $title = self::clean_text( isset( $f['title'] ) ? $f['title'] : '', self::TITLE_MAX );
        $start = self::clean_date( isset( $f['start'] ) ? $f['start'] : '' );
        $end   = self::clean_date( isset( $f['end'] ) ? $f['end'] : '' );
        $link  = isset( $f['link'] ) ? trim( (string) $f['link'] ) : '';
        if ( '' === $title ) {
            return 'Give the announcement a title.';
        }
        if ( '' === $start ) {
            return 'Give the announcement a date.';
        }
        if ( '' !== $end && $end < $start ) {
            return 'The last day is before the first day.';
        }
        if ( '' !== $link && '' === self::clean_link( $link ) ) {
            return 'The link must be a full web address starting with https:// or http://.';
        }
        $svg = isset( $f['icon_svg'] ) ? (string) $f['icon_svg'] : '';
        if ( '' !== $svg && '' !== self::svg_icon_error( $svg ) ) {
            return self::ICON_RULE_MESSAGE;
        }
        return '';
    }

    public static function save( $id, $f ) {
        $broken = self::field_error( $f );
        if ( '' !== $broken ) {
            return new WP_Error( 'sfaf_ann_fields', $broken );
        }
        $title = self::clean_text( $f['title'], self::TITLE_MAX );
        $start = self::clean_date( $f['start'] );
        $end   = self::clean_date( isset( $f['end'] ) ? $f['end'] : '' );
        $link  = isset( $f['link'] ) ? trim( (string) $f['link'] ) : '';
        $svg   = isset( $f['icon_svg'] ) ? (string) $f['icon_svg'] : '';
        if ( '' === $end ) {
            $end = $start;
        }

        $raw = get_option( self::OPTION, array() );
        if ( ! is_array( $raw ) ) {
            $raw = array();
        }
        $id = trim( (string) $id );
        if ( '' === $id || ! isset( $raw[ $id ] ) ) {
            if ( count( $raw ) >= self::MAX ) {
                return new WP_Error( 'sfaf_ann_full', 'There are already too many announcements recorded.' );
            }
            $base = 'a' . str_replace( '-', '', $start );
            $id   = $base;
            $n    = 2;
            while ( isset( $raw[ $id ] ) ) {
                $id = $base . '-' . $n;
                $n++;
            }
        }

        $raw[ $id ] = array(
            'title'    => $title,
            'start'    => $start,
            'end'      => $end,
            'desc'     => self::clean_text( isset( $f['desc'] ) ? $f['desc'] : '', self::DESC_MAX ),
            'link'     => self::clean_link( $link ),
            'icon_id'  => '' !== $svg ? 0 : max( 0, (int) ( isset( $f['icon_id'] ) ? $f['icon_id'] : 0 ) ),
            'icon_svg' => $svg,
            'image_id' => max( 0, (int) ( isset( $f['image_id'] ) ? $f['image_id'] : 0 ) ),
        );
        update_option( self::OPTION, $raw );
        return $id;
    }

    /** Remove one. The pictures stay in the Media Library. */
    public static function delete( $id ) {
        $raw = get_option( self::OPTION, array() );
        if ( ! is_array( $raw ) || ! isset( $raw[ (string) $id ] ) ) {
            return new WP_Error( 'sfaf_ann_missing', 'That announcement no longer exists.' );
        }
        unset( $raw[ (string) $id ] );
        update_option( self::OPTION, $raw );
        return true;
    }

    /* ---------------------------------------------------------------------
     * The icon rule
     * ------------------------------------------------------------------- */

    /**
     * Does a PNG break the icon rule? ICON_RULE_MESSAGE when it does.
     *
     * Square, at most ICON_MAX_PX a side, at most ICON_PNG_BYTES, with an
     * alpha channel (colour type 4 or 6, or a tRNS chunk), and, where GD can
     * read it, at least one transparent corner, which is what a transparent
     * background looks like from the outside.
     *
     * @param string $path
     * @return string
     */
    public static function png_icon_error( $path ) {
        $bytes = @filesize( $path );
        if ( false === $bytes || $bytes < 1 || $bytes > self::ICON_PNG_BYTES ) {
            return self::ICON_RULE_MESSAGE;
        }
        $info = @getimagesize( $path );
        if ( ! is_array( $info ) || IMAGETYPE_PNG !== (int) $info[2] ) {
            return self::ICON_RULE_MESSAGE;
        }
        $w = (int) $info[0];
        $h = (int) $info[1];
        if ( $w < 1 || $w !== $h || $w > self::ICON_MAX_PX ) {
            return self::ICON_RULE_MESSAGE;
        }
        $head = (string) @file_get_contents( $path, false, null, 0, 65536 );
        if ( strlen( $head ) < 26 || "\x89PNG\r\n\x1a\n" !== substr( $head, 0, 8 ) ) {
            return self::ICON_RULE_MESSAGE;
        }
        $colour = ord( $head[25] );
        $alpha  = ( 4 === $colour || 6 === $colour ) || false !== strpos( $head, 'tRNS' );
        if ( ! $alpha ) {
            return self::ICON_RULE_MESSAGE;
        }
        if ( function_exists( 'imagecreatefrompng' ) ) {
            $im = @imagecreatefrompng( $path );
            if ( $im ) {
                $clear = false;
                foreach ( array( array( 0, 0 ), array( $w - 1, 0 ), array( 0, $h - 1 ), array( $w - 1, $h - 1 ) ) as $p ) {
                    $c = imagecolorsforindex( $im, imagecolorat( $im, $p[0], $p[1] ) );
                    if ( isset( $c['alpha'] ) && (int) $c['alpha'] >= 120 ) {
                        $clear = true;
                    }
                }
                imagedestroy( $im );
                if ( ! $clear ) {
                    return self::ICON_RULE_MESSAGE;
                }
            }
        }
        return '';
    }

    /**
     * Does an SVG break the icon rule? ICON_RULE_MESSAGE when it does.
     *
     * At most ICON_SVG_BYTES; an <svg> root; square by its viewBox, or by its
     * width and height when it has no viewBox; and nothing that can run or
     * fetch: no script, no foreignObject, no event attribute, no entity, and
     * no reference that is not to something inside the file itself.
     *
     * @param string $svg
     * @return string
     */
    public static function svg_icon_error( $svg ) {
        $svg = (string) $svg;
        if ( '' === trim( $svg ) || strlen( $svg ) > self::ICON_SVG_BYTES ) {
            return self::ICON_RULE_MESSAGE;
        }
        if ( preg_match( '/<\s*(script|foreignObject|iframe|object|embed|image|a)\b|<!ENTITY|\son[a-z]+\s*=|javascript:|data:|@import|url\s*\(\s*(?![\'"]?#)/i', $svg ) ) {
            return self::ICON_RULE_MESSAGE;
        }
        if ( preg_match( '/\bhref\s*=\s*(?![\'"]#)/i', $svg ) ) {
            return self::ICON_RULE_MESSAGE;
        }
        if ( ! preg_match( '/<svg\b([^>]*)>/i', $svg, $m ) ) {
            return self::ICON_RULE_MESSAGE;
        }
        $attrs = $m[1];
        if ( preg_match( '/\bviewBox\s*=\s*[\'"]\s*[-\d.]+[\s,]+[-\d.]+[\s,]+([\d.]+)[\s,]+([\d.]+)\s*[\'"]/i', $attrs, $vb ) ) {
            $w = (float) $vb[1];
            $h = (float) $vb[2];
        } elseif ( preg_match( '/\bwidth\s*=\s*[\'"]\s*([\d.]+)(?:px)?\s*[\'"]/i', $attrs, $wm )
            && preg_match( '/\bheight\s*=\s*[\'"]\s*([\d.]+)(?:px)?\s*[\'"]/i', $attrs, $hm ) ) {
            $w = (float) $wm[1];
            $h = (float) $hm[1];
        } else {
            return self::ICON_RULE_MESSAGE;
        }
        if ( $w <= 0 || abs( $w - $h ) > 0.001 ) {
            return self::ICON_RULE_MESSAGE;
        }
        return '';
    }

    /* ---------------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------------- */

    private static function clean_date( $date ) {
        $date = trim( (string) $date );
        if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m ) ) {
            return '';
        }
        return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ? $date : '';
    }

    private static function clean_text( $text, $max ) {
        $text = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $text ) ) );
        return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $max ) : substr( $text, 0, $max );
    }

    /** http or https only, or ''. */
    private static function clean_link( $link ) {
        $link = trim( (string) $link );
        if ( '' === $link || ! preg_match( '#^https?://[^\s/$.?\#].[^\s]*$#i', $link ) ) {
            return '';
        }
        return esc_url_raw( $link, array( 'http', 'https' ) );
    }
}
