<?php
/**
 * EMAILS.md: EVERY REGISTRANT MESSAGE, ENGLISH AND SPANISH SIDE BY SIDE (3.106.0).
 *
 *     php .claude/emails-md.php --write    write EMAILS.md from the shipped text
 *     php .claude/emails-md.php --check    exit 1 when EMAILS.md is not what --write would write
 *     php .claude/emails-md.php --self-test
 *
 * For a Spanish reviewer who is not a developer. Every message and every
 * variant, subject and body, filled with the sample event, rendered through
 * SFAF_Messages::render_sample(), which is the builder that sends. So the file
 * cannot say one thing while the plugin sends another: build-zip.sh writes it
 * and refuses the build if that changed a tracked file, and the suite runs
 * --check.
 *
 * THE SHIPPED TEXT, NOT A SITE'S. It is generated with no overrides stored, so
 * an edit made on the Templates screen of one site never reaches the file.
 */

require __DIR__ . '/wp-kit.php';

function emd_cell( $s ) {
    $s = str_replace( array( "\r\n", "\r" ), "\n", trim( (string) $s ) );
    $s = str_replace( '|', '\|', $s );
    return str_replace( "\n", '<br>', $s );
}

/** The whole file, as it should be. */
function emd_render() {
    $GLOBALS['kit_options'] = array();   // the shipped text: no overrides
    $out   = array();
    $out[] = '# EMAILS.md, SFAF Calendar';
    $out[] = '';
    $out[] = 'Every message the calendar sends to somebody who registers, and the two pages they land on, in English and Spanish side by side. For the Spanish review.';
    $out[] = '';
    $out[] = 'The event in every example is the same sample: "Coffee and Conversation", Thursday, November 12, 2026, 6 to 7:30 pm, at the SFAF Main Office. Links are samples too.';
    $out[] = '';
    $out[] = 'This file is written by `php .claude/emails-md.php --write` from the text the plugin ships, and a build refuses to go out if the two differ. To change a message, change it in `includes/class-sfaf-messages.php` or on the Email Templates screen, not here.';
    $out[] = '';
    $out[] = 'Words in a message that come from the event, such as its title, location and organizer, are printed as they were entered and are not translated.';
    $out[] = '';

    foreach ( SFAF_Messages::catalog() as $key => $m ) {
        $out[] = '## ' . $m['label'];
        $out[] = '';
        foreach ( $m['variants'] as $variant => $vlabel ) {
            if ( '' !== $vlabel && count( $m['variants'] ) > 1 ) {
                $out[] = '### ' . $vlabel;
                $out[] = '';
            }
            $en = SFAF_Messages::render_sample( $key, $variant, 'en' );
            $es = SFAF_Messages::render_sample( $key, $variant, 'es' );
            $out[] = '| | English | Spanish |';
            $out[] = '|---|---|---|';
            if ( '' !== trim( $en['subject'] ) || '' !== trim( $es['subject'] ) ) {
                $out[] = '| ' . ( 'page' === $m['kind'] ? 'Title' : 'Subject' ) . ' | ' . emd_cell( $en['subject'] ) . ' | ' . emd_cell( $es['subject'] ) . ' |';
            }
            $out[] = '| ' . ( 'page' === $m['kind'] ? 'Page' : 'Text' ) . ' | ' . emd_cell( $en['text'] ) . ' | ' . emd_cell( $es['text'] ) . ' |';
            $out[] = '';
        }
    }

    /*
     * EMAIL REGISTRANTS (3.110.2). Not a template, so not in catalog() and not
     * on the Templates screen: the organizer writes the subject and body for
     * each send. This is a sample of one, through the builder that sends, so
     * the layout around their words is what is read here.
     */
    $out[] = '## Email registrants (a sample message)';
    $out[] = '';
    $out[] = 'The organizer writes the subject and the message on the registrations list for each send. The subject is the heading; the message follows, then the event details and See the event page. This sample shows the layout around their words.';
    $out[] = '';
    $rm = array();
    foreach ( array( 'en', 'es' ) as $lang ) {
        $smp = SFAF_Messages::sample( $lang );
        $person = (object) array( 'first_name' => $smp['first_name'], 'last_name' => $smp['last_name'], 'name' => $smp['first_name'] . ' ' . $smp['last_name'],
            'email' => 'alex@example.org', 'token' => 'SAMPLE', 'format' => '' );
        $rm[ $lang ] = SFAF_Notifications::build( 'registrant_message', 0, $person, array( 'sample' => $smp,
            'subject' => 'Meeting point for {title}',
            'body'    => "Hi {first_name},\n\nWe will meet at the <strong>front desk</strong>. The route is on <a href=\"https://www.sfaf.org/\">sfaf.org</a>." ) );
    }
    $out[] = '| | English | Spanish |';
    $out[] = '|---|---|---|';
    $out[] = '| Subject | ' . emd_cell( $rm['en']['subject'] ) . ' | ' . emd_cell( $rm['es']['subject'] ) . ' |';
    $out[] = '| Text | ' . emd_cell( $rm['en']['text'] ) . ' | ' . emd_cell( $rm['es']['text'] ) . ' |';
    $out[] = '';
    $out[] = '## The fixed words around the text';
    $out[] = '';
    $out[] = 'Labels, buttons and the calendar file\'s words. These are not edited on the Templates screen.';
    $out[] = '';
    $out[] = '| English | Spanish |';
    $out[] = '|---|---|';
    foreach ( SFAF_Messages::labels() as $pair ) {
        $out[] = '| ' . emd_cell( $pair[0] ) . ' | ' . emd_cell( $pair[1] ) . ' |';
    }
    $out[] = '';
    return implode( "\n", $out );
}

$file = dirname( __DIR__ ) . '/EMAILS.md';
$arg  = isset( $argv[1] ) ? $argv[1] : '--check';

if ( '--self-test' === $arg ) {
    // The check must fail on a file that differs, and pass on one that does not.
    $good = emd_render();
    $ok   = ( $good === emd_render() ) && ( $good !== $good . ' ' );
    echo $ok ? "the check sees a one-character difference and nothing else.\n" : "THE CHECK IS BROKEN.\n";
    exit( $ok ? 0 : 1 );
}
if ( '--write' === $arg ) {
    file_put_contents( $file, emd_render() );
    echo "wrote EMAILS.md\n";
    exit( 0 );
}
$want = emd_render();
$have = is_file( $file ) ? str_replace( "\r\n", "\n", (string) file_get_contents( $file ) ) : '';
if ( $want !== $have ) {
    echo "EMAILS.md is not what the plugin sends. Run: php .claude/emails-md.php --write\n";
    exit( 1 );
}
echo "EMAILS.md matches the shipped text, message for message.\n";
