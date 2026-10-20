<?php
/**
 * ONE PDF ATTACHMENT PER EVENT (3.111.0).
 *
 * Event details on Add event and Edit event has an Attachment control: a PDF
 * upload, a label box (placeholder "Download event flyer") and Remove. The
 * public event page, and the event's row in a list, show a button with that
 * label opening the file in a new tab, in Register's outlined variant.
 *
 * WHERE THE FILE LIVES. calendar-files/, beside the picture folders and
 * inside none of them, so no picture picker, the Images screen or the
 * description's Insert image ever offers a PDF. Told apart by the file path,
 * as every other calendar folder is.
 *
 * WHAT IS CHECKED. SFAF_Uploads::inspect() with the 'pdf' rule: 5MB or less,
 * opening with the PDF signature, finfo agreeing. The same path every upload
 * here takes, so there is one guard between a form and the disk.
 *
 * THE EVENT POINTS AT THE FILE. _uc_attachment_id and _uc_attachment_label
 * are copied onto every occurrence of a repeating event and onto a copy, so
 * all of them share one file. A series has no attachment and nothing is
 * inherited from one.
 *
 * DELETED WITH THE LAST EVENT THAT USES IT. When WordPress deletes an event
 * for good (emptying the trash), the file goes too, unless another event
 * still points at it. Remove only takes it off the event.
 *
 * @package SFAF_Calendar
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SFAF_Attachments {

    const FOLDER        = 'calendar-files';
    const META_ID       = '_uc_attachment_id';
    const META_LABEL    = '_uc_attachment_label';
    const DEFAULT_LABEL = 'Download event flyer';
    const LABEL_MAX     = 60;
    const FIELD         = 'uc_attachment_file';

    public static function register() {
        add_action( 'before_delete_post', array( __CLASS__, 'delete_with_event' ) );
    }

    /** The uploads-relative folder, with its trailing slash. */
    public static function prefix() {
        return self::FOLDER . '/';
    }

    /** Is this attachment a calendar file? Anchored at the front. */
    public static function holds( $attachment_id ) {
        $file = (string) get_post_meta( (int) $attachment_id, '_wp_attached_file', true );
        return 0 === strpos( ltrim( $file, '/' ), self::prefix() );
    }

    /** The event's attachment id, or 0 when it has none that still exists. */
    public static function id( $event_id ) {
        $att = (int) get_post_meta( (int) $event_id, self::META_ID, true );
        return ( $att && 'attachment' === get_post_type( $att ) && self::holds( $att ) ) ? $att : 0;
    }

    /** The file's address, or ''. */
    public static function url( $event_id ) {
        $att = self::id( $event_id );
        if ( ! $att ) {
            return '';
        }
        $url = wp_get_attachment_url( $att );
        return $url ? (string) $url : '';
    }

    /** The label typed for the button, or the default. */
    public static function label( $event_id ) {
        $typed = trim( (string) get_post_meta( (int) $event_id, self::META_LABEL, true ) );
        return '' !== $typed ? $typed : self::DEFAULT_LABEL;
    }

    /** The label as stored, '' when none was typed. */
    public static function typed_label( $event_id ) {
        return trim( (string) get_post_meta( (int) $event_id, self::META_LABEL, true ) );
    }

    /** The file's own name, for the editor. */
    public static function file_name( $event_id ) {
        $att = self::id( $event_id );
        return $att ? basename( (string) get_post_meta( $att, '_wp_attached_file', true ) ) : '';
    }

    /**
     * The public button, or '' with no attachment. Register's outlined
     * variant, in a new tab with the hidden "(opens in a new tab)".
     *
     * @param int    $event_id
     * @param string $class Extra class for where it is drawn.
     * @return string
     */
    public static function button_html( $event_id, $class = '' ) {
        $url = self::url( $event_id );
        if ( '' === $url ) {
            return '';
        }
        return '<div class="uc-attachment' . ( '' !== $class ? ' ' . esc_attr( $class ) : '' ) . '" data-uc-attachment>'
            . sfaf_action_button( array(
                'label'    => self::label( $event_id ),
                'href'     => $url,
                'variant'  => 'secondary',
                'external' => true,
            ) )
            . '</div>';
    }

    /**
     * The first page's preview WordPress made on upload, or null. Made only
     * where Imagick reads PDFs (Ghostscript); resources.sfaf.org does, measured
     * for 3.113.3. A file uploaded where it could not has none, and keeps the card.
     *
     * @param int $event_id
     * @return array{url:string,width:int,height:int,large:string,large_w:int}|null
     */
    public static function thumb( $event_id ) {
        $att = self::id( $event_id );
        if ( ! $att || ! function_exists( 'image_get_intermediate_size' ) ) {
            return null;
        }
        $med = image_get_intermediate_size( $att, 'medium' );
        if ( empty( $med['url'] ) ) {
            return null;
        }
        $big = image_get_intermediate_size( $att, 'large' );
        return array(
            'url'     => (string) $med['url'],
            'width'   => (int) $med['width'],
            'height'  => (int) $med['height'],
            'large'   => ! empty( $big['url'] ) ? (string) $big['url'] : '',
            'large_w' => ! empty( $big['width'] ) ? (int) $big['width'] : 0,
        );
    }

    /**
     * THE EVENT PAGE'S FLYER (3.113.3), or '' with no attachment.
     *
     * The first page as a picture with a solid "View flyer (PDF)" under it,
     * or, where no preview was made, a dark card with a PDF mark and the same
     * button. A label typed on the event replaces the button's words.
     *
     * ITS STYLES TRAVEL WITH IT, NOT IN calendar.css, because the embed loads
     * calendar.css and 3.113.3 changes nothing the embed receives. The list
     * row keeps button_html().
     *
     * @param int $event_id
     * @return string
     */
    public static function flyer_html( $event_id ) {
        $url = self::url( $event_id );
        if ( '' === $url ) {
            return '';
        }
        $typed = self::typed_label( $event_id );
        $thumb = self::thumb( $event_id );
        $out   = '<style>'
            . '.uc-single .uc-flyer{margin:24px 0 0;display:flex;flex-direction:column;align-items:flex-start;gap:14px}'
            . '.uc-single .uc-flyer-thumb{display:block;line-height:0;border:1px solid #D1D3D4;border-radius:8px;overflow:hidden;background:#fff;box-shadow:var(--uc-lift)}'
            . '.uc-single .uc-flyer-thumb img{display:block;width:232px;max-width:100%;height:auto;margin:0}'
            . '.uc-single .uc-flyer-card{display:flex;align-items:center;gap:14px;width:232px;max-width:100%;box-sizing:border-box;padding:20px;border-radius:8px;background:#373433;color:#fff;font-family:var(--uc-font-heading);font-size:18px;font-weight:800;line-height:1.2}'
            . '.uc-single .uc-flyer-card svg{flex:0 0 auto;width:44px;height:44px}'
            . '.uc-single .uc-flyer .uc-actionbtn{white-space:normal;max-width:100%}'
            . '</style>';
        $out .= '<div class="uc-flyer uc-attachment-single" data-uc-attachment data-uc-flyer>';
        if ( $thumb ) {
            $srcset = esc_url( $thumb['url'] ) . ' ' . (int) $thumb['width'] . 'w'
                . ( '' !== $thumb['large'] ? ', ' . esc_url( $thumb['large'] ) . ' ' . (int) $thumb['large_w'] . 'w' : '' );
            // The button below is the link's name; the picture is the same link, so it is skipped by keyboard and reader.
            $out .= '<a class="uc-flyer-thumb" href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer" tabindex="-1" aria-hidden="true">'
                . '<img src="' . esc_url( $thumb['url'] ) . '" srcset="' . $srcset . '" sizes="232px"'
                . ' width="' . (int) $thumb['width'] . '" height="' . (int) $thumb['height'] . '" alt="" loading="lazy" decoding="async" />'
                . '</a>';
        } else {
            $out .= '<div class="uc-flyer-card" aria-hidden="true">'
                . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" focusable="false">'
                . '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/>'
                . '<text x="12" y="17.5" text-anchor="middle" font-size="5.2" font-weight="700" fill="currentColor" stroke="none" font-family="Montserrat, Arial, sans-serif">PDF</text>'
                . '</svg><span>Event flyer</span></div>';
        }
        $out .= sfaf_action_button( array(
            'label'    => '' !== $typed ? $typed : 'View flyer (PDF)',
            'href'     => $url,
            'variant'  => 'primary',
            'external' => true,
        ) );
        $out .= '</div>';
        return $out;
    }

    /** The absolute folder, made if needed, with the same guard files as submissions. */
    public static function dir() {
        $uploads = wp_upload_dir();
        if ( ! empty( $uploads['error'] ) ) {
            return new WP_Error( 'uc_upload_dir', 'The uploads folder is not writable.' );
        }
        $path = rtrim( $uploads['basedir'], '/\\' ) . '/' . self::FOLDER;
        if ( ! is_dir( $path ) && ! wp_mkdir_p( $path ) ) {
            return new WP_Error( 'uc_upload_dir', 'The files folder could not be made.' );
        }
        if ( ! file_exists( $path . '/index.html' ) ) {
            @file_put_contents( $path . '/index.html', '' );
        }
        if ( ! file_exists( $path . '/.htaccess' ) ) {
            @file_put_contents( $path . '/.htaccess', "# Written by SFAF Calendar. Event attachments.\nphp_flag engine off\nAddType text/plain .php .php3 .php4 .php5 .php7 .phtml .phar .cgi .pl .py .sh .html .htm .svg\n" );
        }
        return $path;
    }

    /** Send this request's upload into calendar-files/. Added and removed around the one move. */
    public static function upload_to_folder( $dirs ) {
        if ( empty( $dirs['basedir'] ) || empty( $dirs['baseurl'] ) ) {
            return $dirs;
        }
        $dirs['subdir'] = '/' . self::FOLDER;
        $dirs['path']   = $dirs['basedir'] . $dirs['subdir'];
        $dirs['url']    = $dirs['baseurl'] . $dirs['subdir'];
        return $dirs;
    }

    /**
     * Store the uploaded PDF, or say why not.
     *
     * @param int $user_id Who uploaded it.
     * @return array{id:int,error:string} id 0 with error '' means nothing was sent.
     */
    public static function store( $user_id ) {
        $seen = SFAF_Uploads::inspect( self::FIELD, null, 'pdf' );
        if ( ! $seen['ok'] ) {
            return array( 'id' => 0, 'error' => (string) $seen['error'] );
        }
        $dir = self::dir();
        if ( is_wp_error( $dir ) ) {
            return array( 'id' => 0, 'error' => 'That file could not be saved. Try it again.' );
        }
        if ( ! function_exists( 'media_handle_upload' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }
        add_filter( 'upload_dir', array( __CLASS__, 'upload_to_folder' ), 20 );
        $att = media_handle_upload( self::FIELD, 0, array( 'post_author' => (int) $user_id ), array(
            'test_form' => false,
            'mimes'     => array( 'pdf' => 'application/pdf' ),
        ) );
        remove_filter( 'upload_dir', array( __CLASS__, 'upload_to_folder' ), 20 );

        if ( is_wp_error( $att ) || ! $att ) {
            return array( 'id' => 0, 'error' => SFAF_Uploads::PDF_RULE_MESSAGE );
        }
        $att = (int) $att;
        if ( ! self::holds( $att ) ) {
            wp_delete_attachment( $att, true );
            return array( 'id' => 0, 'error' => 'That file could not be saved. Try it again.' );
        }
        return array( 'id' => $att, 'error' => '' );
    }

    /**
     * Save the control from the event form: a new file, the label, Remove.
     *
     * ONLY WHEN THE CONTROL WAS ON THE FORM (uc_attachment_present), so a
     * screen that does not draw it cannot take an attachment off by saving.
     *
     * @param int $event_id
     * @param int $user_id
     * @return string '' when nothing was refused; the refusal otherwise.
     */
    public static function save_from_post( $event_id, $user_id ) {
        $event_id = (int) $event_id;
        if ( empty( $_POST['uc_attachment_present'] ) ) {
            return '';
        }

        $label = isset( $_POST['attachment_label'] )
            ? trim( sanitize_text_field( wp_unslash( $_POST['attachment_label'] ) ) )
            : '';
        $label = function_exists( 'mb_substr' ) ? mb_substr( $label, 0, self::LABEL_MAX ) : substr( $label, 0, self::LABEL_MAX );
        if ( '' === $label ) {
            delete_post_meta( $event_id, self::META_LABEL );
        } else {
            update_post_meta( $event_id, self::META_LABEL, $label );
        }

        if ( ! empty( $_POST['attachment_remove'] ) ) {
            delete_post_meta( $event_id, self::META_ID );
        }

        $sent = isset( $_FILES[ self::FIELD ] ) && is_array( $_FILES[ self::FIELD ] )
            && isset( $_FILES[ self::FIELD ]['error'] ) && UPLOAD_ERR_NO_FILE !== (int) $_FILES[ self::FIELD ]['error'];
        if ( ! $sent ) {
            return '';
        }
        $stored = self::store( $user_id );
        if ( ! $stored['id'] ) {
            return '' !== $stored['error'] ? $stored['error'] : SFAF_Uploads::PDF_RULE_MESSAGE;
        }
        update_post_meta( $event_id, self::META_ID, (int) $stored['id'] );
        return '';
    }

    /**
     * THE FILE GOES WITH THE LAST EVENT THAT USES IT (3.111.0), when an event
     * is deleted for good. Occurrences share one file, so it stays while any
     * other event still points at it.
     *
     * @param int $post_id
     */
    public static function delete_with_event( $post_id ) {
        $post_id = (int) $post_id;
        if ( 'uc_event' !== get_post_type( $post_id ) ) {
            return;
        }
        $att = (int) get_post_meta( $post_id, self::META_ID, true );
        if ( ! $att || 'attachment' !== get_post_type( $att ) || ! self::holds( $att ) ) {
            return;
        }
        $others = get_posts( array(
            'post_type'      => 'uc_event',
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'post__not_in'   => array( $post_id ),
            'meta_query'     => array(
                array( 'key' => self::META_ID, 'value' => (string) $att ),
            ),
        ) );
        if ( empty( $others ) ) {
            wp_delete_attachment( $att, true );
        }
    }

    /**
     * The editor's control, for Add event and Edit event alike.
     *
     * @param int $event_id 0 on Add event.
     */
    public static function render_control( $event_id ) {
        $event_id = (int) $event_id;
        $name     = $event_id ? self::file_name( $event_id ) : '';
        $typed    = $event_id ? self::typed_label( $event_id ) : '';
        ?>
        <fieldset class="uc-field uc-attachment-field" data-uc-attachment-field>
            <legend class="uc-field-label">Attachment</legend>
            <input type="hidden" name="uc_attachment_present" value="1" />
            <?php if ( '' !== $name ) : ?>
                <p class="uc-attachment-current" data-uc-attachment-current>
                    <a href="<?php echo esc_url( self::url( $event_id ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $name ); ?><span class="uc-visually-hidden"> (opens in a new tab)</span></a>
                </p>
                <label class="uc-check uc-attachment-remove">
                    <input type="checkbox" name="attachment_remove" value="1" data-uc-attachment-remove />
                    Remove
                </label>
            <?php endif; ?>
            <?php // The picture upload's outlined control and its hidden input (3.110.3). ?>
            <label class="uc-outline-btn uc-image-upload uc-attachment-upload">
                <span><?php echo '' !== $name ? 'Replace the PDF' : '+ Upload a PDF'; ?></span>
                <input type="file" name="<?php echo esc_attr( self::FIELD ); ?>" accept="application/pdf,.pdf"
                       class="uc-visually-hidden" data-uc-attachment-file
                       data-uc-max-bytes="<?php echo (int) SFAF_Uploads::PDF_BYTES; ?>"
                       data-uc-rule-message="<?php echo esc_attr( SFAF_Uploads::PDF_RULE_MESSAGE ); ?>" />
            </label>
            <p class="uc-hint" data-uc-attachment-chosen hidden></p>
            <p class="uc-hint uc-hint-error" role="alert" data-uc-attachment-error hidden></p>
            <p class="uc-hint"><?php echo esc_html( SFAF_Uploads::PDF_RULE_MESSAGE ); ?></p>
            <label class="uc-field">
                <span class="uc-field-label">Button label</span>
                <input type="text" name="attachment_label" maxlength="<?php echo (int) self::LABEL_MAX; ?>"
                       value="<?php echo esc_attr( $typed ); ?>" placeholder="<?php echo esc_attr( self::DEFAULT_LABEL ); ?>" />
            </label>
        </fieldset>
        <?php
    }
}
