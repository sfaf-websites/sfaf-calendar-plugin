<?php
/**
 * 3.113.4, THE PARTS A TEST CAN SETTLE WITHOUT A BROWSER.
 *
 *     php .claude/release-31134-test.php
 *
 * Through wp-kit.php and core-media.php, which is WordPress 7.0.1's own media
 * lookup logic and not a stub that answers what the check wants.
 *
 *   1  the flyer finds the preview WordPress made: the metadata is the live
 *      site's, copied from attachment 62056, the PDF on "Immigrant services,
 *      rights & access to care", which 3.113.3 drew as the card; a missing
 *      preview is made once, the first time the event page asks, and a file
 *      that cannot be previewed keeps the card and is not tried again; only
 *      a calendar PDF an event points at is ever touched
 *   2  the editor's bar is the grey --p-bg under a Light Gray rule; Cancel
 *      event's edge is --p-muted; Save changes green, Delete the red link
 *
 * The public calendar is embed-identity-test.php's. PLANTs in plant-31134.php.
 */

require __DIR__ . '/wp-kit.php';
require __DIR__ . '/core-media.php';

$fails = array();
$ran   = 0;
function rt( $label, $got, $want ) {
    global $fails, $ran;
    $ran++;
    if ( $got !== $want ) { $fails[] = $label . ': got ' . json_encode( $got ) . ', wanted ' . json_encode( $want ); }
}
$root = $GLOBALS['kit_root'];

function rt_event( $att ) {
    $id = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_title' => 'Immigrant services', 'post_author' => 1 ) );
    update_post_meta( $id, SFAF_Attachments::META_ID, $att );
    return $id;
}
function rt_pdf( $id, $file, $meta, $mime = 'application/pdf' ) {
    kit_write_post( array( 'ID' => $id, 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => $mime ) );
    update_post_meta( $id, '_wp_attached_file', $file );
    $GLOBALS['kit_files'][ $id ] = 'https://resources.sfaf.org/wp-content/uploads/' . $file;
    if ( null !== $meta ) { update_post_meta( $id, '_wp_attachment_metadata', $meta ); }
}
$up = 'https://resources.sfaf.org/wp-content/uploads/calendar-files/';

/* ======================================================================== 1 */
/* The live site's metadata for 62056, as its media endpoint gave it on 2026-10-20. */
$live = array(
    'sizes'    => array(
        'full'      => array( 'file' => 'Access-to-Care-event-flyers-Oct2026-v1-2-3-pdf.jpg', 'width' => 1088, 'height' => 1408, 'mime_type' => 'application/pdf' ),
        'medium'    => array( 'file' => 'Access-to-Care-event-flyers-Oct2026-v1-2-3-pdf-232x300.jpg', 'width' => 232, 'height' => 300, 'filesize' => 88594, 'mime_type' => 'image/jpeg' ),
        'large'     => array( 'file' => 'Access-to-Care-event-flyers-Oct2026-v1-2-3-pdf-791x1024.jpg', 'width' => 791, 'height' => 1024, 'filesize' => 208454, 'mime_type' => 'image/jpeg' ),
        'thumbnail' => array( 'file' => 'Access-to-Care-event-flyers-Oct2026-v1-2-3-pdf-116x150.jpg', 'width' => 116, 'height' => 150, 'filesize' => 72758, 'mime_type' => 'image/jpeg' ),
    ),
    'filesize' => 3807524,
);
rt_pdf( 62056, 'calendar-files/Access-to-Care-event-flyers-Oct2026-v1-2-3.pdf', $live );
$ev = rt_event( 62056 );

$med = image_get_intermediate_size( 62056, 'medium' );
rt( '1: the trap 3.113.3 fell in: core gives a PDF\'s size no url', is_array( $med ) && ! isset( $med['url'] ), true );

$GLOBALS['cm_generated'] = 0;
$f = SFAF_Attachments::flyer_html( $ev );
rt( '1: the live PDF shows its first page', (bool) preg_match( '#<img src="' . preg_quote( $up, '#' ) . 'Access-to-Care-event-flyers-Oct2026-v1-2-3-pdf-232x300\.jpg" srcset="[^"]*-232x300\.jpg 232w, [^"]*-791x1024\.jpg 791w" sizes="232px" width="232" height="300" alt=""#', $f ), true );
rt( '1: and not the card', false === strpos( $f, '<div class="uc-flyer-card"' ), true );
rt( '1: the solid View flyer (PDF) under it', (bool) preg_match( '#</a><a class="uc-actionbtn uc-actionbtn-primary" href="' . preg_quote( $up . 'Access-to-Care-event-flyers-Oct2026-v1-2-3.pdf', '#' ) . '"[^>]*><span class="uc-actionbtn-label">View flyer \(PDF\)</span>#', $f ), true );
rt( '1: a preview that is there is never made again', $GLOBALS['cm_generated'], 0 );

/* A PDF with no preview, that Ghostscript can read: made once, then shown. */
$disk = tempnam( sys_get_temp_dir(), 'uc-flyer' );
file_put_contents( $disk, "%PDF-1.4\n" );
rt_pdf( 701, 'calendar-files/older-flyer.pdf', array( 'filesize' => 9 ) );
$GLOBALS['kit_attached'][701] = $disk;
$ev2 = rt_event( 701 );
$GLOBALS['cm_generate'] = function ( $id, $file ) {
    return array( 'sizes' => array(
        'full'   => array( 'file' => 'older-flyer-pdf.jpg', 'width' => 1088, 'height' => 1408, 'mime_type' => 'image/jpeg' ),
        'medium' => array( 'file' => 'older-flyer-pdf-232x300.jpg', 'width' => 232, 'height' => 300, 'mime_type' => 'image/jpeg' ),
    ), 'filesize' => 9 );
};
$GLOBALS['cm_generated'] = 0;
$f2 = SFAF_Attachments::flyer_html( $ev2 );
rt( '1: a missing preview is made the first time the page asks', $GLOBALS['cm_generated'], 1 );
rt( '1: and shown', false !== strpos( $f2, '<img src="' . $up . 'older-flyer-pdf-232x300.jpg"' ) && false === strpos( $f2, '<div class="uc-flyer-card"' ), true );
rt( '1: with no large size, the srcset offers the full first page', false !== strpos( $f2, 'srcset="' . $up . 'older-flyer-pdf-232x300.jpg 232w, ' . $up . 'older-flyer-pdf.jpg 1088w" ' ), true );
rt( '1: and kept, as WordPress keeps it', isset( wp_get_attachment_metadata( 701 )['sizes']['medium'] ), true );
SFAF_Attachments::flyer_html( $ev2 );
rt( '1: and not made again', $GLOBALS['cm_generated'], 1 );

/* A PDF that cannot be previewed: the card, and one attempt only. */
rt_pdf( 702, 'calendar-files/broken.pdf', array( 'filesize' => 9 ) );
$GLOBALS['kit_attached'][702] = $disk;
$ev3 = rt_event( 702 );
$GLOBALS['cm_generate'] = function ( $id, $file ) { return array( 'filesize' => 9 ); };
$GLOBALS['cm_generated'] = 0;
$f3 = SFAF_Attachments::flyer_html( $ev3 );
rt( '1: a PDF that cannot be previewed keeps the card', false !== strpos( $f3, '<div class="uc-flyer-card" aria-hidden="true"><svg' ) && false === strpos( $f3, '<img' ) && false !== strpos( $f3, 'uc-actionbtn-primary' ), true );
SFAF_Attachments::flyer_html( $ev3 );
SFAF_Attachments::flyer_html( $ev3 );
rt( '1: and is tried once, not on every view', $GLOBALS['cm_generated'], 1 );
rt( '1: the attempt is recorded on the file', (bool) get_post_meta( 702, SFAF_Attachments::META_PREVIEW_TRIED, true ), true );
rt( '1: nothing it could not make is written over the metadata', wp_get_attachment_metadata( 702 ), array( 'filesize' => 9 ) );

/* A file missing from disk: the card, and nothing asked of Ghostscript. */
rt_pdf( 703, 'calendar-files/gone.pdf', array( 'filesize' => 9 ) );
$ev4 = rt_event( 703 );
$GLOBALS['cm_generated'] = 0;
$f4 = SFAF_Attachments::flyer_html( $ev4 );
rt( '1: a file not on disk keeps the card and is not sent to be made', false !== strpos( $f4, '<div class="uc-flyer-card"' ) && 0 === $GLOBALS['cm_generated'], true );

/* Outside calendar-files/: not the event's file at all, so never touched. */
rt_pdf( 704, '2026/10/annual-report.pdf', array( 'filesize' => 9 ) );
$GLOBALS['kit_attached'][704] = $disk;
$ev5 = rt_event( 704 );
$GLOBALS['cm_generate'] = null;
$GLOBALS['cm_generated'] = 0;
rt( '1: a library PDF outside calendar-files/ draws nothing and is never made', array( SFAF_Attachments::flyer_html( $ev5 ), $GLOBALS['cm_generated'], get_post_meta( 704, SFAF_Attachments::META_PREVIEW_TRIED, true ) ), array( '', 0, '' ) );

/* Not a PDF, though in the folder: never sent to be made. */
rt_pdf( 705, 'calendar-files/odd.bin', array( 'filesize' => 9 ), 'application/octet-stream' );
$GLOBALS['kit_attached'][705] = $disk;
$GLOBALS['cm_generated'] = 0;
SFAF_Attachments::flyer_html( rt_event( 705 ) );
rt( '1: only a PDF is sent to be made', $GLOBALS['cm_generated'], 0 );
@unlink( $disk );

/* Reached only from the event page's flyer, so nothing else in the library.
   Counted in code, comments taken out by the tokenizer. */
function rt_code( $file ) {
    $out = '';
    foreach ( token_get_all( (string) file_get_contents( $file ) ) as $tk ) {
        if ( is_array( $tk ) && in_array( $tk[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) { continue; }
        $out .= is_array( $tk ) ? $tk[1] : $tk;
    }
    return $out;
}
$src = rt_code( $root . '/includes/class-sfaf-attachments.php' );
$all = '';
foreach ( array_merge( glob( $root . '/includes/*.php' ), glob( $root . '/templates/*.php' ), glob( $root . '/admin/*.php' ) ?: array(), array( $root . '/sfaf-calendar.php' ) ) as $file ) { $all .= rt_code( $file ); }
rt( '1: ensure_preview() has one caller, thumb()', array( substr_count( $all, 'ensure_preview(' ), substr_count( $all, 'self::ensure_preview( $att );' ) ), array( 2, 1 ) );
rt( '1: thumb() has one caller, the flyer', array( preg_match_all( '#(::|function )thumb[(]#', $all ), substr_count( $all, '$thumb = self::thumb( $event_id );' ) ), array( 2, 1 ) );
rt( '1: no lookup that loses a PDF\'s url', false === strpos( $src, 'image_get_intermediate_size(' ), true );
rt( '1: nothing in the stylesheet the embed loads', false === strpos( (string) file_get_contents( $root . '/public/css/calendar.css' ), 'uc-flyer' ), true );

/* ======================================================================== 2 */
$css = (string) file_get_contents( $root . '/public/css/portal.css' );
$bar = preg_match( '#\.uc-portal \.uc-form-actions-primary\.uc-editor-bar \{([^}]*)\}#', $css, $bm ) ? $bm[1] : '';
rt( '2: the bar is the grey --p-bg', false !== strpos( $bar, 'background: var(--p-bg);' ), true );
rt( '2: under a thin Light Gray line', false !== strpos( $bar, 'border-top: 1px solid #D1D3D4;' ), true );
rt( '2: --p-bg is the canvas grey', false !== strpos( $css, '--p-bg: #F5F6F7;' ), true );
rt( '2: Cancel event\'s edge is --p-muted', false !== strpos( $css, '.uc-editor-actions .uc-cancel-open { border-color: var(--p-muted); }' ), true );
rt( '2: --p-muted is #6B6764', false !== strpos( $css, '--p-muted: #6B6764;' ), true );
rt( '2: Cancel event still has no face', false !== strpos( $css, ".uc-editor-actions .uc-cancel-open,\n.uc-editor-actions .uc-cancel-open:hover { background: transparent; }" ), true );
rt( '2: and turns teal on hover', false !== strpos( $css, '.uc-editor-actions .uc-cancel-open:hover { border-color: var(--uc-accent-text); }' ), true );
rt( '2: Delete stays at the far left', false !== strpos( $css, '.uc-editor-actions .uc-editor-delete  { order: -1; }' ), true );

/* Contrast on the new grey, measured, so a later change to either token is seen. */
function rt_lum( $h ) { $c = array(); foreach ( array( 1, 3, 5 ) as $i ) { $v = hexdec( substr( $h, $i, 2 ) ) / 255; $c[] = $v <= 0.03928 ? $v / 12.92 : pow( ( $v + 0.055 ) / 1.055, 2.4 ); } return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2]; }
function rt_ratio( $a, $b ) { $x = rt_lum( $a ); $y = rt_lum( $b ); return ( max( $x, $y ) + 0.05 ) / ( min( $x, $y ) + 0.05 ); }
rt( '2: Cancel event\'s edge clears 4.5:1 on the bar', rt_ratio( '#6B6764', '#F5F6F7' ) >= 4.5, true );
rt( '2: Delete\'s red clears 4.5:1 on the bar', rt_ratio( '#c0392b', '#F5F6F7' ) >= 4.5, true );
rt( '2: Save changes\' white on green clears 4.5:1', rt_ratio( '#FFFFFF', '#15803D' ) >= 4.5, true );

/* ======================================================================== */
echo "\nRelease 3.113.4\n" . str_repeat( '=', 72 ) . "\nran $ran assertions.\n";
if ( $fails ) {
    echo count( $fails ) . " failure(s):\n";
    foreach ( $fails as $f ) { echo '  - ' . $f . "\n"; }
    exit( 1 );
}
echo "The flyer finds the preview WordPress made, and makes one once where it is missing; the bar is grey and Cancel event outlined in --p-muted.\n";
