<?php
/**
 * WORDPRESS'S OWN MEDIA LOOKUPS, FOR THE CHECKS (3.113.4). Require after wp-kit.php.
 *
 * Transcribed from WordPress 7.0.1, wp-includes/media.php, for the named sizes
 * only (no array sizes, no filters). They are core's logic, NOT a stub that
 * answers what the check wants: 3.113.3's check faked image_get_intermediate_size()
 * and handed back a url, which core never does for a PDF, so the flyer passed
 * here and drew the card on the site.
 *
 * The metadata lives where core keeps it, in _wp_attachment_metadata; the
 * attachment's type in the post's post_mime_type. cm_generate (a callable,
 * or null) stands for what Imagick and Ghostscript would make of the file,
 * and $GLOBALS['cm_generated'] counts the attempts.
 */

if ( ! function_exists( 'wp_basename' ) ) {
    function wp_basename( $path, $suffix = '' ) {
        return urldecode( basename( str_replace( array( '%2F', '%5C' ), '/', urlencode( $path ) ), $suffix ) );
    }
}
if ( ! function_exists( 'path_join' ) ) {
    function path_join( $base, $path ) {
        if ( '' !== $path && '/' === $path[0] ) { return $path; }
        return rtrim( $base, '/' ) . '/' . $path;
    }
}
if ( ! function_exists( 'get_post_mime_type' ) ) {
    function get_post_mime_type( $post = null ) {
        $p = get_post( $post );
        return ( $p && isset( $p->post_mime_type ) ) ? (string) $p->post_mime_type : false;
    }
}
if ( ! function_exists( 'wp_attachment_is_image' ) ) {
    function wp_attachment_is_image( $post = null ) {
        return 0 === strpos( (string) get_post_mime_type( $post ), 'image/' );
    }
}
if ( ! function_exists( 'wp_get_attachment_metadata' ) ) {
    function wp_get_attachment_metadata( $id = 0, $unfiltered = false ) {
        $m = get_post_meta( (int) $id, '_wp_attachment_metadata', true );
        return is_array( $m ) ? $m : false;
    }
}
if ( ! function_exists( 'wp_update_attachment_metadata' ) ) {
    function wp_update_attachment_metadata( $id, $data ) {
        return (bool) update_post_meta( (int) $id, '_wp_attachment_metadata', $data );
    }
}
if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
    function wp_generate_attachment_metadata( $id, $file ) {
        $GLOBALS['cm_generated'] = ( isset( $GLOBALS['cm_generated'] ) ? $GLOBALS['cm_generated'] : 0 ) + 1;
        $make = isset( $GLOBALS['cm_generate'] ) ? $GLOBALS['cm_generate'] : null;
        return $make ? call_user_func( $make, $id, $file ) : array( 'filesize' => 1 );
    }
}

/* Core 7.0.1, media.php 780: a named size. Note the url: only with a top-level 'file'. */
if ( ! function_exists( 'image_get_intermediate_size' ) ) {
    function image_get_intermediate_size( $post_id, $size = 'thumbnail' ) {
        $imagedata = wp_get_attachment_metadata( $post_id );
        if ( ! $size || ! is_array( $imagedata ) || empty( $imagedata['sizes'] ) ) {
            return false;
        }
        $data = ! empty( $imagedata['sizes'][ $size ] ) ? $imagedata['sizes'][ $size ] : array();
        if ( empty( $data ) ) {
            return false;
        }
        if ( empty( $data['path'] ) && ! empty( $data['file'] ) && ! empty( $imagedata['file'] ) ) {
            $file_url     = wp_get_attachment_url( $post_id );
            $data['path'] = path_join( dirname( $imagedata['file'] ), $data['file'] );
            $data['url']  = path_join( dirname( $file_url ), $data['file'] );
        }
        return $data;
    }
}

/* Core 7.0.1, image_downsize(): a file that is not an image answers from sizes.full. */
if ( ! function_exists( 'image_downsize' ) ) {
    function image_downsize( $id, $size = 'medium' ) {
        $is_image         = wp_attachment_is_image( $id );
        $img_url          = wp_get_attachment_url( $id );
        $meta             = wp_get_attachment_metadata( $id );
        $width            = 0;
        $height           = 0;
        $is_intermediate  = false;
        $img_url_basename = wp_basename( $img_url );
        if ( ! $is_image ) {
            if ( ! empty( $meta['sizes']['full'] ) ) {
                $img_url          = str_replace( $img_url_basename, $meta['sizes']['full']['file'], $img_url );
                $img_url_basename = $meta['sizes']['full']['file'];
                $width            = $meta['sizes']['full']['width'];
                $height           = $meta['sizes']['full']['height'];
            } else {
                return false;
            }
        }
        $intermediate = image_get_intermediate_size( $id, $size );
        if ( $intermediate ) {
            $img_url         = str_replace( $img_url_basename, $intermediate['file'], $img_url );
            $width           = $intermediate['width'];
            $height          = $intermediate['height'];
            $is_intermediate = true;
        }
        if ( ! $width && ! $height && isset( $meta['width'], $meta['height'] ) ) {
            $width  = $meta['width'];
            $height = $meta['height'];
        }
        return $img_url ? array( $img_url, $width, $height, $is_intermediate ) : false;
    }
}

if ( ! function_exists( 'wp_get_attachment_image_src' ) ) {
    function wp_get_attachment_image_src( $attachment_id, $size = 'thumbnail', $icon = false ) {
        $image = image_downsize( $attachment_id, $size );
        return $image ? $image : false;
    }
}
