<?php
/**
 * The one place this plugin builds and sends an email.
 *
 * NOTHING HERE HAS EVER RUN AGAINST A REAL MAILBOX. Read every claim in this
 * file as "this is what the code does", not "this is what arrives". SFAF_Email
 * carries a test-send tool for exactly that reason: see send_test().
 *
 * DELIVERY IS NOT THIS PLUGIN'S PROBLEM, AND MUST NOT BECOME IT.
 * ---------------------------------------------------------------------------
 * The site running this plugin has the ActiveCampaign Postmark plugin active,
 * which overrides wp_mail() and hands the message to Postmark. A WordPress
 * password reset already arrives cleanly from that path. So everything here
 * calls wp_mail() and stops: no SMTP settings, no transport, no library, no
 * second delivery mechanism to keep in step with the first. If delivery ever
 * moves, it moves underneath this file without a line changing in it.
 *
 * THE PLAIN-TEXT ALTERNATIVE, AND THE ONE THING THAT MIGHT NOT SURVIVE.
 * ---------------------------------------------------------------------------
 * Every message here is built twice, HTML and plain text, and that is not
 * optional: a text/html-only email is a blank message in a text-only client and
 * scores badly with spam filters.
 *
 * WordPress has exactly one supported way to attach the text alternative to an
 * HTML message: set $phpmailer->AltBody on the `phpmailer_init` action. That
 * works with core's mailer and with every SMTP plugin that routes through
 * PHPMailer. It CANNOT work with a plugin that replaces wp_mail() outright and
 * talks to an HTTP API, because PHPMailer is never constructed and the action
 * never fires. The Postmark plugin is the second kind.
 *
 * So the AltBody hook is attached (it costs nothing and is correct wherever
 * PHPMailer is involved), and it is written down here that it may be a no-op on
 * this site, in which case Postmark generates the text part from the HTML
 * itself. WHICH OF THOSE IS HAPPENING IS A THING TO LOOK AT IN A DELIVERED
 * MESSAGE, NOT TO ASSUME: view source on a received email and check for a
 * text/plain part. There is no way to find out from here.
 *
 * The hook is added immediately before the send and removed immediately after,
 * so this plugin never puts its text on another plugin's email.
 *
 * REPLY-TO IS PASSED AS A HEADER AND IS NOT SET ANY OTHER WAY.
 * ---------------------------------------------------------------------------
 * Same reasoning: a header is what wp_mail() documents, and it is what a
 * transport is expected to honour. Whether this particular transport forwards
 * it is a fact about the transport, and send_test() exists to find out.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Email {

    /**
     * The shipped from address, used until Settings says otherwise.
     *
     * events@calendar.sfaf.org is being set up and will replace this. That is
     * why it is a setting with a default rather than a constant used directly:
     * when the new mailbox exists, somebody types it into Settings and nothing
     * is deployed. This constant is only what a site gets before anybody has
     * typed anything.
     */
    const DEFAULT_FROM_EMAIL = 'websites@sfaf.org';
    const DEFAULT_FROM_NAME  = 'San Francisco AIDS Foundation';

    /** The postal address in the footer of every message. */
    const POSTAL = 'San Francisco AIDS Foundation, 940 Howard Street, San Francisco, CA 94103';

    /* Brand values, per DESIGN.md. Hard-coded here on purpose: an email cannot
       read a stylesheet, so these are the one place the palette exists for
       mail, and they are the audited values rather than eyeballed ones.
         #FFD900 with #373433 text is the only permitted text-on-yellow pair.
         #0E7680 is the teal that clears 4.5:1 on white; brand #16BECF does not
         and is banned for text. */
    const C_YELLOW = '#FFD900';
    const C_INK    = '#373433';
    const C_TEAL   = '#0E7680';
    const C_MUTED  = '#6B6764';
    const C_RULE   = '#D1D3D4';
    const C_CANVAS = '#F5F6F7';

    /** Montserrat first, then what a mail client will actually have. */
    const FONT = "Montserrat, 'Helvetica Neue', Helvetica, Arial, sans-serif";

    /** The text alternative for the send currently in flight. */
    private static $alt_body = '';

    /* =====================================================================
     * Identity
     * ================================================================== */

    public static function from_name() {
        $settings = get_option( 'uc_settings', array() );
        $name     = isset( $settings['email_from_name'] ) ? trim( (string) $settings['email_from_name'] ) : '';
        return '' !== $name ? $name : self::DEFAULT_FROM_NAME;
    }

    public static function from_address() {
        $settings = get_option( 'uc_settings', array() );
        $addr     = isset( $settings['email_from_address'] ) ? trim( (string) $settings['email_from_address'] ) : '';
        return ( '' !== $addr && is_email( $addr ) ) ? $addr : self::DEFAULT_FROM_EMAIL;
    }

    /** The banner, served from this plugin so it travels with it. */
    public static function banner_url() {
        return SFAF_PLUGIN_URL . 'public/images/sfaf-email-header.png';
    }

    /**
     * A button glyph, as a raster, because an SVG does not render in email.
     *
     * WHY A PICTURE AND NOT A CHARACTER. The two candidates were an inline
     * image and a Unicode calendar (U+1F4C5). The character always renders and
     * can never be blocked, which is a real advantage, and it loses on the
     * requirement that decided this: it is the reader's emoji font, not this
     * plugin's icon language, so it arrives as a different mark in every client
     * and as a colour picture next to a brand-coloured label. The image is the
     * SAME mark sfaf_icon() draws on the website, rasterised from the same path
     * data by .claude/build-email-icons.js.
     *
     * WHY NOT THE PLATFORM LOGOS. The buttons say "Google" and "Apple or
     * Outlook" and the obvious icons are each vendor's. Those are registered
     * trademarks with published brand terms and nothing here has been cleared
     * to reproduce them. One generic calendar glyph on both buttons says the
     * same thing and asks nobody's permission.
     *
     * HOW IT DEGRADES WITH IMAGES OFF, which is the default in many clients: it
     * carries alt="" and is therefore decorative, so a client that blocks it
     * shows an empty 16px box and the button reads "Google". The glyph is never
     * the only thing carrying a meaning, so nothing is lost but the decoration.
     * width and height are stated as attributes as well as in the style, so the
     * blocked box is 16px rather than whatever the client guesses, and both
     * buttons stay the same height whether or not pictures loaded.
     *
     * @param string $on 'ink' for the yellow button, 'teal' for the outline.
     */
    public static function icon( $on = 'ink' ) {
        $file = ( 'teal' === $on ) ? 'icon-calendar-teal.png' : 'icon-calendar-ink.png';
        return '<img src="' . esc_url( SFAF_PLUGIN_URL . 'public/images/' . $file ) . '"'
            . ' width="16" height="16" alt=""'
            . ' style="width:16px; height:16px; border:0; outline:none; vertical-align:middle; margin-right:7px;" />';
    }

    /* =====================================================================
     * Sending
     * ================================================================== */

    /**
     * Send one message.
     *
     * @param string $to       One address.
     * @param string $subject  Already token-replaced.
     * @param string $html     Full HTML document.
     * @param string $text     Plain-text alternative. Required.
     * @param string $reply_to Optional single address.
     * @return bool wp_mail()'s answer, which means "handed off", not "delivered".
     */
    public static function send( $to, $subject, $html, $text, $reply_to = '' ) {
        if ( ! is_email( $to ) ) {
            return false;
        }

        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            sprintf( 'From: %s <%s>', self::from_name(), self::from_address() ),
        );
        if ( $reply_to && is_email( $reply_to ) ) {
            $headers[] = 'Reply-To: ' . $reply_to;
        }

        self::$alt_body = (string) $text;
        add_action( 'phpmailer_init', array( __CLASS__, 'attach_alt_body' ) );
        $ok = wp_mail( $to, $subject, $html, $headers );
        remove_action( 'phpmailer_init', array( __CLASS__, 'attach_alt_body' ) );
        self::$alt_body = '';

        return (bool) $ok;
    }

    /** Attach the text alternative. See the note at the top of this file. */
    public static function attach_alt_body( $phpmailer ) {
        if ( '' !== self::$alt_body ) {
            $phpmailer->AltBody = self::$alt_body;
        }
    }

    /* =====================================================================
     * The shell
     * ================================================================== */

    /**
     * Wrap content blocks in the branded 600px shell.
     *
     * TABLES AND INLINE STYLES, AND IT IS NOT A STYLE PREFERENCE. Outlook on
     * Windows renders with Word's engine: no flexbox, no grid, no reliable
     * border-radius, and stylesheets in <head> are unreliable across clients
     * generally. Every rule that matters is therefore an inline style on a
     * table cell. Anything written as a div with modern CSS renders as a stack
     * of full-width blocks in the client a large share of these recipients use.
     *
     * THE BANNER IS THE HEADER. No yellow rule under it: the band would be a
     * second header competing with the first, and yellow means "act on this",
     * which a decorative stripe is not.
     *
     * IT READS WITH IMAGES OFF. Many clients block images by default, so the
     * banner carries real alt text and nothing below it depends on a picture:
     * every detail is text in a table.
     *
     * @param string $preheader The line clients show beside the subject.
     * @param string $content   Inner HTML, already escaped by its builder.
     * @return string
     */
    public static function shell( $preheader, $content ) {
        $font = self::FONT;

        $html  = '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">';
        $html .= '<html xmlns="http://www.w3.org/1999/xhtml"><head>';
        $html .= '<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />';
        $html .= '<meta name="viewport" content="width=device-width, initial-scale=1" />';
        $html .= '<title>' . esc_html( wp_strip_all_tags( $preheader ) ) . '</title>';
        $html .= '</head>';
        $html .= '<body style="margin:0; padding:0; background-color:' . self::C_CANVAS . ';">';

        // The preheader: shown in the inbox list beside the subject, hidden in
        // the message itself. Without one, clients quote the alt text of the
        // banner, so every email would preview as "San Francisco AIDS
        // Foundation".
        $html .= '<div style="display:none; font-size:1px; line-height:1px; max-height:0; max-width:0; opacity:0; overflow:hidden; mso-hide:all;">'
              . esc_html( $preheader ) . '</div>';

        $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:' . self::C_CANVAS . ';">';
        $html .= '<tr><td align="center" style="padding:24px 12px;">';
        $html .= '<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px; max-width:600px; background-color:#ffffff; border:1px solid ' . self::C_RULE . ';">';

        // The banner. Supplied at 1200x212 and displayed at 600 wide, so it is
        // sharp on a retina screen and 600 in the layout.
        $html .= '<tr><td style="padding:0; line-height:0;">'
              . '<img src="' . esc_url( self::banner_url() ) . '" width="600" alt="San Francisco AIDS Foundation"'
              . ' style="display:block; width:100%; max-width:600px; height:auto; border:0; outline:none; text-decoration:none;" />'
              . '</td></tr>';

        $html .= '<tr><td style="padding:28px 32px 8px 32px; font-family:' . $font . '; font-size:16px; line-height:1.5; color:' . self::C_INK . ';">';
        $html .= $content;
        $html .= '</td></tr>';

        $html .= '<tr><td style="padding:20px 32px 26px 32px; border-top:1px solid ' . self::C_RULE . '; font-family:' . $font . '; font-size:12px; line-height:1.5; color:' . self::C_MUTED . ';">';
        $html .= esc_html( self::POSTAL );
        $html .= '</td></tr>';

        $html .= '</table></td></tr></table></body></html>';

        return $html;
    }

    /** An h1-weight line, since a real <h1> is styled unpredictably. */
    public static function heading( $text ) {
        return '<p style="margin:0 0 14px 0; font-family:' . self::FONT . '; font-size:22px; line-height:1.3; font-weight:700; color:' . self::C_INK . ';">'
            . esc_html( $text ) . '</p>';
    }

    /** A paragraph. */
    public static function para( $text ) {
        return '<p style="margin:0 0 14px 0; font-family:' . self::FONT . '; font-size:16px; line-height:1.5; color:' . self::C_INK . ';">'
            . esc_html( $text ) . '</p>';
    }

    /**
     * The event's details as a label/value table.
     *
     * A TABLE, NOT A PARAGRAPH, because this is the part somebody comes back to
     * the email to re-read. Labels in the muted grey, values in body ink at
     * normal weight: contrast by weight and size, not by rules and colour.
     *
     * @param array $rows label => value. Empty values are dropped rather than
     *                    rendered as a blank line.
     */
    public static function details( $rows ) {
        $out = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 20px 0;">';
        foreach ( $rows as $label => $value ) {
            if ( '' === trim( (string) $value ) ) {
                continue;
            }
            $out .= '<tr>'
                . '<td valign="top" style="padding:0 12px 8px 0; font-family:' . self::FONT . '; font-size:13px; line-height:1.5; color:' . self::C_MUTED . '; white-space:nowrap;">'
                . esc_html( $label ) . '</td>'
                . '<td valign="top" style="padding:0 0 8px 0; font-family:' . self::FONT . '; font-size:16px; line-height:1.5; color:' . self::C_INK . ';">'
                . esc_html( $value ) . '</td>'
                . '</tr>';
        }
        $out .= '</table>';
        return $out;
    }

    /**
     * A small section label, for a pair of controls that need naming.
     *
     * Sentence case and quiet: it is a signpost above something, not a heading
     * competing with the one at the top of the message.
     */
    public static function label( $text ) {
        return '<p style="margin:0 0 8px 0; font-family:' . self::FONT . '; font-size:13px; line-height:1.4; font-weight:700; color:' . self::C_MUTED . ';">'
            . esc_html( $text ) . '</p>';
    }

    /**
     * A button, drawn as a table cell so it is a real rectangle in Outlook.
     *
     * ONE PRIMARY PER EMAIL. Yellow means "this is the thing to act on", and a
     * second yellow button means neither of them does.
     *
     * @param string $url
     * @param string $label
     * @param string $style 'primary' (yellow, dark text) | 'outline' (teal).
     * @param bool   $icon  Put the calendar glyph on it. See icon().
     * @param bool   $fill  Stretch to the width of whatever contains it, which
     *                      is what makes a pair of these equal width.
     */
    public static function button( $url, $label, $style = 'primary', $icon = false, $fill = false ) {
        $primary = ( 'primary' === $style );
        $bg      = $primary ? self::C_YELLOW : '#ffffff';
        $fg      = $primary ? self::C_INK : self::C_TEAL;
        $border  = $primary ? self::C_YELLOW : self::C_TEAL;

        // A filled button's <a> is display:block so the whole rectangle is the
        // target rather than the text inside it. An unfilled one stays
        // inline-block and shrinks to its label, which is what every other
        // button in these messages wants.
        $width   = $fill ? ' width="100%" style="width:100%;"' : '';
        $display = $fill ? 'block' : 'inline-block';
        $pad     = $fill ? '13px 10px' : '12px 22px';

        return '<table role="presentation" cellpadding="0" cellspacing="0" border="0"' . $width . '>'
            . '<tr><td align="center" bgcolor="' . $bg . '" style="background-color:' . $bg . '; border:2px solid ' . $border . ';">'
            . '<a href="' . esc_url( $url ) . '"'
            . ' style="display:' . $display . '; padding:' . $pad . '; font-family:' . self::FONT . '; font-size:15px; font-weight:700; line-height:1.2; color:' . $fg . '; text-decoration:none; white-space:nowrap;">'
            . ( $icon ? self::icon( $primary ? 'ink' : 'teal' ) : '' )
            . esc_html( $label ) . '</a>'
            . '</td></tr></table>';
    }

    /**
     * Two buttons side by side and the SAME WIDTH AS EACH OTHER.
     *
     * WHY EQUAL WIDTH IS A METHOD AND NOT A STYLE ON A CALLER. The pair used to
     * be two shrink-to-fit buttons in two shrink-to-fit cells, so their size was
     * whatever their labels happened to measure. "Add to Google Calendar"
     * wrapped to three lines and "Add to Apple or Outlook" to two, which is how
     * two buttons meant to be a matched pair ended up different heights. The
     * labels were shortened to fix that at the root; this makes the geometry
     * stop depending on the labels at all, so a longer one later cannot bring
     * the fault back.
     *
     * Each cell is width="50%" as an attribute as well as a style: Word's
     * engine honours the attribute and ignores a percentage in CSS.
     *
     * @param array $buttons Up to two, each already built by button().
     */
    public static function button_row( $buttons ) {
        $buttons = array_values( $buttons );
        $count   = count( $buttons );
        if ( ! $count ) {
            return '';
        }
        if ( 1 === $count ) {
            return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 8px 0;">'
                . '<tr><td valign="top">' . $buttons[0] . '</td></tr></table>';
        }

        $pct = (int) floor( 100 / $count );
        $out = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%; margin:0 0 8px 0;"><tr>';
        foreach ( $buttons as $i => $b ) {
            // A gutter between, and none on the outside, so the pair lines up
            // with the text above and below it on both edges.
            $pad  = ( 0 === $i ) ? '0 6px 0 0' : ( ( $count - 1 === $i ) ? '0 0 0 6px' : '0 6px' );
            $out .= '<td valign="top" width="' . $pct . '%" style="width:' . $pct . '%; padding:' . $pad . ';">' . $b . '</td>';
        }
        return $out . '</tr></table>';
    }

    /** A plain text link line, teal and underlined. */
    public static function link_para( $url, $label ) {
        return '<p style="margin:0 0 14px 0; font-family:' . self::FONT . '; font-size:15px; line-height:1.5; color:' . self::C_INK . ';">'
            . '<a href="' . esc_url( $url ) . '" style="color:' . self::C_TEAL . '; text-decoration:underline;">' . esc_html( $label ) . '</a>'
            . '</p>';
    }

    /** A quiet line, for the cancel sentence and similar. */
    public static function small_para( $html ) {
        return '<p style="margin:0 0 14px 0; font-family:' . self::FONT . '; font-size:13px; line-height:1.5; color:' . self::C_MUTED . ';">'
            . $html . '</p>';
    }

    /** A hairline. */
    public static function rule() {
        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:4px 0 18px 0;">'
            . '<tr><td style="border-top:1px solid ' . self::C_RULE . '; font-size:0; line-height:0;">&nbsp;</td></tr></table>';
    }

    /**
     * A list of registered people, for the staff summary.
     *
     * @param object[] $rows uc_rsvps rows.
     */
    public static function people_table( $rows ) {
        $out = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 20px 0;">';
        $out .= '<tr>'
            . '<th align="left" style="padding:0 12px 6px 0; border-bottom:1px solid ' . self::C_RULE . '; font-family:' . self::FONT . '; font-size:12px; font-weight:700; color:' . self::C_MUTED . ';">Name</th>'
            . '<th align="left" style="padding:0 12px 6px 0; border-bottom:1px solid ' . self::C_RULE . '; font-family:' . self::FONT . '; font-size:12px; font-weight:700; color:' . self::C_MUTED . ';">Email</th>'
            . '<th align="left" style="padding:0 0 6px 0; border-bottom:1px solid ' . self::C_RULE . '; font-family:' . self::FONT . '; font-size:12px; font-weight:700; color:' . self::C_MUTED . ';">Registered</th>'
            . '</tr>';
        foreach ( $rows as $row ) {
            // First and last, joined by the one function that joins them. A row
            // with no name at all was written outside submit(), and saying so is
            // better than an empty cell.
            $name = SFAF_RSVP::display_name( $row );
            $name = ( '' !== $name ) ? $name : 'No name given';
            // Registered without an email (3.102.0): said, not a blank cell.
            $addr = ( '' !== trim( (string) $row->email ) ) ? (string) $row->email : 'No email';
            $out .= '<tr>'
                . '<td valign="top" style="padding:8px 12px 0 0; font-family:' . self::FONT . '; font-size:14px; line-height:1.4; color:' . self::C_INK . ';">' . esc_html( $name ) . '</td>'
                . '<td valign="top" style="padding:8px 12px 0 0; font-family:' . self::FONT . '; font-size:14px; line-height:1.4; color:' . self::C_INK . ';">' . esc_html( $addr ) . '</td>'
                . '<td valign="top" style="padding:8px 0 0 0; font-family:' . self::FONT . '; font-size:14px; line-height:1.4; color:' . self::C_MUTED . ';">' . esc_html( sfaf_ap_date( $row->created_at, 'short' ) ) . '</td>'
                . '</tr>';
        }
        $out .= '</table>';
        return $out;
    }

    /* =====================================================================
     * The test send
     * ================================================================== */

    /**
     * Send one of each message to a real address, so somebody can look.
     *
     * THIS EXISTS BECAUSE NONE OF THIS HAS EVER RUN. Every claim about
     * rendering, about Reply-To surviving Postmark, and about whether a
     * text/plain part arrives is unverifiable from here and answerable in about
     * two minutes with a delivered message.
     *
     * It builds the real message through the real builders against a real
     * event, so what arrives is what a registrant would get. The only
     * difference is the recipient, and a line saying so.
     *
     * @param string $to      Where to send.
     * @param string $type    confirmation|reminder|alert|summary
     * @param int    $event_id Event to build against.
     * @return array{sent:bool,message:string}
     */
    public static function send_test( $to, $type, $event_id ) {
        if ( ! is_email( $to ) ) {
            return array( 'sent' => false, 'message' => 'That is not a valid email address.' );
        }
        $event = get_post( $event_id );
        if ( ! $event || 'uc_event' !== $event->post_type ) {
            return array( 'sent' => false, 'message' => 'Pick an event to build the test against.' );
        }

        $person = (object) array(
            'name'       => 'Test Person',
            'first_name' => 'Test',
            'last_name'  => 'Person',
            'email'      => $to,
            'created_at' => current_time( 'mysql' ),
            'token'      => 'test-token-not-a-real-registration',
        );

        /*
         * THE TEST MESSAGES LINK INTO CALADMIN, because whoever pressed the
         * button is on the Settings screen, which needs manage_options, and
         * that is can_view_all by definition, which is also can_edit_event for
         * every event. Both keys are set because the two messages ask different
         * ones: the alert opens the RSVP list, the summary opens the event.
         * They are also the half of each message worth looking at.
         *
         * WHAT THE TEST CANNOT SHOW is the count. "0 of 12 places taken" on a
         * test is correct: no row was written, so nothing was added to the
         * number. A real registration reads one higher, because submit() clears
         * the request's cached count the moment the row lands and this builder
         * counts afterwards. The note at the foot of the message says a place
         * was not held.
         */
        $built = SFAF_Notifications::build( $type, $event_id, $person, array(
            'can_view_all'   => true,
            'can_edit_event' => true,
        ) );
        if ( ! $built ) {
            return array( 'sent' => false, 'message' => 'There is no message of that kind.' );
        }

        $note = 'This is a test message. Nobody has registered and no place has been held.';
        $built['html'] = str_replace( '</body>', '', $built['html'] )
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td align="center" style="padding:0 12px 28px 12px;">'
            . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px; max-width:600px;">'
            . '<tr><td style="padding:12px 0; font-family:' . self::FONT . '; font-size:12px; color:' . self::C_MUTED . ';">' . esc_html( $note ) . '</td></tr>'
            . '</table></td></tr></table></body>';
        $built['text'] = $built['text'] . "\n\n" . $note;

        $sent = self::send(
            $to,
            '[Test] ' . $built['subject'],
            $built['html'],
            $built['text'],
            SFAF_Reminders::reply_to_for( $event_id )
        );

        return array(
            'sent'    => $sent,
            'message' => $sent
                ? sprintf( 'Handed to wp_mail() for %s. Open it and check: the banner, the Reply-To address, the buttons, and whether there is a text/plain part in the source.', $to )
                : 'wp_mail() refused it. Check the mail plugin on this site.',
        );
    }
}
