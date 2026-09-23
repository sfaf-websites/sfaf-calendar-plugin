<?php
/**
 * EVERY TIME IN EVERY MESSAGE CARRIES THE ZONE, AND NOTHING ELSE DOES (3.99.0).
 *
 *     php .claude/email-zone-test.php
 *     php .claude/email-zone-test.php --self-test
 *
 * TWO HALVES, BECAUSE EITHER ALONE PASSES A REAL FAULT.
 *
 *   The formatter. sfaf_ap_time_range() with the 'zone' style is sliced out of
 *   the shipped file and run: "12–2 pm PT", generic all year, the other US
 *   zones, and nothing when PHP can only offer an offset. Without the style it
 *   must be exactly what it always was, because the event page and the cards
 *   call it that way and are not to change.
 *
 *   The call sites. email-render-test.php renders the notification messages
 *   and reads their clocks, but the request form, the submission form, the
 *   published notice and the change announcement build their mail somewhere it
 *   does not reach. So every call to the range formatter in the plugin is found
 *   with the tokenizer, and the function it sits in decides what it must pass:
 *   a function that builds mail passes 'zone', and one that does not must not.
 *   The second half is the "event page and cards are unchanged" rule, checked.
 *
 * WHAT COUNTS AS BUILDING MAIL. A function whose body calls SFAF_Email:: or
 * wp_mail(), plus the two helpers named in $MAIL_HELPERS, which hand their
 * clock to a message without touching the mail class themselves. A new helper
 * like them is the case this cannot see, and the list is where it goes.
 */

$root = dirname( __DIR__ );
$self = in_array( '--self-test', $argv, true );
$fails = array();

/* ------------------------------------------------------------------ 1. value */
function date_i18n( $format, $ts = false, $gmt = false ) {
    return date( $format, false === $ts ? time() : $ts );
}
$tpl = file_get_contents( $root . '/includes/sfaf-template-functions.php' );
$slice = function ( $src, $name ) {
    $at = strpos( $src, 'function ' . $name . '(' );
    if ( false === $at ) { return ''; }
    $d = 0;
    for ( $i = $at; $i < strlen( $src ); $i++ ) {
        if ( '{' === $src[ $i ] ) { $d++; }
        if ( '}' === $src[ $i ] ) { $d--; if ( 0 === $d ) { return substr( $src, $at, $i - $at + 1 ); } }
    }
    return '';
};
foreach ( array( 'sfaf_ap_time', 'sfaf_ap_time_range', 'sfaf_ap_time_zone', 'sfaf_ap_zoned' ) as $fn ) {
    $code = $slice( $tpl, $fn );
    if ( '' === $code ) { echo "FAIL: $fn() is not in the formatter.\n"; exit( 1 ); }
    eval( $code );
}

$dash = "\xE2\x80\x93";
$eq = function ( $got, $want, $why ) use ( &$fails ) {
    if ( $got !== $want ) { $fails[] = "$why: got \"$got\", wanted \"$want\""; }
};

date_default_timezone_set( 'America/Los_Angeles' );
$eq( sfaf_ap_time_range( '12:00', '14:00', 'zone' ), "12{$dash}2 pm PT", 'the example in the brief' );
$eq( sfaf_ap_time_range( '11:00', '13:00', 'zone' ), "11 am{$dash}1 pm PT", 'a range across noon' );
$eq( sfaf_ap_time_range( '18:00', '', 'zone' ), '6 pm PT', 'a start with no end' );
$eq( sfaf_ap_time_range( '', '', 'zone' ), '', 'no time at all gets no zone either' );
$eq( sfaf_ap_time_range( '12:00', '14:00' ), "12{$dash}2 pm", 'no style is the page, unchanged' );
$eq( sfaf_ap_zoned( "6{$dash}7:30 pm" ), "6{$dash}7:30 pm PT", 'a phrase already formatted' );
$eq( sfaf_ap_zoned( '' ), '', 'an empty phrase stays empty' );

foreach ( array( 'America/New_York' => 'ET', 'America/Chicago' => 'CT', 'America/Denver' => 'MT',
                 'America/Phoenix' => 'MT', 'America/Anchorage' => 'AKT', 'Pacific/Honolulu' => 'HT',
                 'UTC' => 'UTC', 'Asia/Dubai' => '' ) as $tz => $want ) {
    date_default_timezone_set( $tz );
    $eq( sfaf_ap_time_zone(), $want, "the zone in $tz" );
}
date_default_timezone_set( 'America/Los_Angeles' );

/* Generic all year: the abbreviation PHP gives changes with the season and the
 * answer must not. Asked of the mapping directly with both seasons' strings. */
$tz = new DateTimeZone( 'America/Los_Angeles' );
foreach ( array( '2026-01-15', '2026-07-15' ) as $d ) {
    $abbr = ( new DateTime( $d, $tz ) )->format( 'T' );
    if ( ! in_array( $abbr, array( 'PST', 'PDT' ), true ) ) {
        $fails[] = "PHP gave $abbr for Los Angeles on $d, so the season test is not testing the season";
    }
}
if ( false === strpos( $slice( $tpl, 'sfaf_ap_time_zone' ), "'PST'  => 'PT', 'PDT'  => 'PT'" ) ) {
    $fails[] = 'the formatter no longer maps both Pacific seasons to PT';
}

/* ------------------------------------------------------------- 2. call sites */
$MAIL_HELPERS = array(
    'includes/class-sfaf-notifications.php' => array( 'facts' ),
    'includes/sfaf-template-functions.php'  => array( 'sfaf_replace_tokens' ),
);

/**
 * Every call to $callee in $src: the enclosing function, whether that function
 * builds mail, and whether the call passes the 'zone' style.
 */
function sfaf_zone_calls( $src, $callee, $helpers ) {
    $t = token_get_all( $src );
    $n = count( $t );
    $funcs = array(); // name, start, end, body text
    for ( $i = 0; $i < $n; $i++ ) {
        if ( ! is_array( $t[ $i ] ) || T_FUNCTION !== $t[ $i ][0] ) { continue; }
        $j = $i + 1;
        while ( $j < $n && ( ( is_array( $t[ $j ] ) && T_WHITESPACE === $t[ $j ][0] ) || '&' === $t[ $j ] || ( is_array( $t[ $j ] ) && T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG === $t[ $j ][0] ) ) ) { $j++; }
        if ( ! is_array( $t[ $j ] ) || T_STRING !== $t[ $j ][0] ) { continue; }
        $name = $t[ $j ][1];
        while ( $j < $n && '{' !== $t[ $j ] && ';' !== $t[ $j ] ) { $j++; }
        if ( $j >= $n || ';' === $t[ $j ] ) { continue; }
        $depth = 0; $body = '';
        for ( $k = $j; $k < $n; $k++ ) {
            $tok = $t[ $k ];
            $txt = is_array( $tok ) ? $tok[1] : $tok;
            $body .= $txt;
            if ( '{' === $tok || ( is_array( $tok ) && ( T_CURLY_OPEN === $tok[0] || T_DOLLAR_OPEN_CURLY_BRACES === $tok[0] ) ) ) { $depth++; }
            if ( '}' === $tok ) { $depth--; if ( 0 === $depth ) { break; } }
        }
        $funcs[] = array( 'name' => $name, 'start' => $j, 'end' => $k, 'body' => $body );
    }

    $out = array();
    for ( $i = 0; $i < $n; $i++ ) {
        if ( ! is_array( $t[ $i ] ) || T_STRING !== $t[ $i ][0] || $callee !== $t[ $i ][1] ) { continue; }
        $p = $i + 1;
        while ( $p < $n && is_array( $t[ $p ] ) && T_WHITESPACE === $t[ $p ][0] ) { $p++; }
        if ( '(' !== $t[ $p ] ) { continue; }
        $b = $i - 1;
        while ( $b >= 0 && is_array( $t[ $b ] ) && T_WHITESPACE === $t[ $b ][0] ) { $b--; }
        if ( is_array( $t[ $b ] ) && T_FUNCTION === $t[ $b ][0] ) { continue; } // the definition

        /* The arguments, read to the matching bracket. */
        $depth = 0; $args = '';
        for ( $k = $p; $k < $n; $k++ ) {
            $txt = is_array( $t[ $k ] ) ? $t[ $k ][1] : $t[ $k ];
            if ( '(' === $t[ $k ] ) { $depth++; }
            if ( ')' === $t[ $k ] ) { $depth--; if ( 0 === $depth ) { break; } }
            $args .= $txt;
        }

        /* The innermost function holding this call. */
        $in = null;
        foreach ( $funcs as $f ) {
            if ( $f['start'] < $i && $f['end'] > $i && ( null === $in || $f['start'] > $in['start'] ) ) { $in = $f; }
        }
        $fname = $in ? $in['name'] : '(file scope)';
        $mail  = $in && ( false !== strpos( $in['body'], 'SFAF_Email::' ) || false !== strpos( $in['body'], 'wp_mail(' ) || in_array( $fname, $helpers, true ) );

        $out[] = array(
            'line' => $t[ $i ][2],
            'func' => $fname,
            'mail' => $mail,
            'zone' => (bool) preg_match( "/,\s*'zone'\s*$/", trim( $args ) ),
        );
    }
    return $out;
}

$files = array_merge(
    glob( $root . '/includes/*.php' ),
    glob( $root . '/admin/*.php' ),
    array( $root . '/sfaf-calendar.php' )
);

if ( $self ) {
    /* The checker has to see all four shapes, or a clean run means nothing. */
    $fixture = <<<'PHP'
<?php
class X {
    public static function mail_ok() { $a = sfaf_ap_time_range( $s, $e, 'zone' ); SFAF_Email::send( 1, 2, 3, 4 ); }
    public static function mail_bare() { $a = sfaf_ap_time_range( $s, $e ); SFAF_Email::send( 1, 2, 3, 4 ); }
    public static function page_ok() { echo sfaf_ap_time_range( $s, $e ); }
    public static function page_zoned() { echo sfaf_ap_time_range( $s, $e, 'zone' ); }
    public static function &byref() { $b = "{$x}"; return sfaf_ap_time_range( $s, trim( $e ), 'zone' ); wp_mail( 1 ); }
}
PHP;
    $got = array();
    foreach ( sfaf_zone_calls( $fixture, 'sfaf_ap_time_range', array() ) as $c ) {
        $got[ $c['func'] ] = ( $c['mail'] ? 'mail' : 'page' ) . '/' . ( $c['zone'] ? 'zone' : 'bare' );
    }
    $want = array( 'mail_ok' => 'mail/zone', 'mail_bare' => 'mail/bare', 'page_ok' => 'page/bare',
                   'page_zoned' => 'page/zone', 'byref' => 'mail/zone' );
    if ( $got !== $want ) {
        echo "SELF-TEST FAIL: the checker misreads its own fixture\n";
        var_export( $got );
        echo "\n";
        exit( 1 );
    }
    echo "self-test: all five shapes read correctly, including a by-reference function and an interpolated brace.\n";
    exit( 0 );
}

$mail_calls = 0;
$page_calls = 0;
foreach ( $files as $file ) {
    $rel     = str_replace( '\\', '/', substr( $file, strlen( $root ) + 1 ) );
    $helpers = isset( $MAIL_HELPERS[ $rel ] ) ? $MAIL_HELPERS[ $rel ] : array();
    foreach ( sfaf_zone_calls( file_get_contents( $file ), 'sfaf_ap_time_range', $helpers ) as $c ) {
        if ( $c['mail'] ) {
            $mail_calls++;
            if ( ! $c['zone'] ) {
                $fails[] = "$rel:{$c['line']} {$c['func']}() builds mail and prints a time with no zone";
            }
        } else {
            $page_calls++;
            if ( $c['zone'] ) {
                $fails[] = "$rel:{$c['line']} {$c['func']}() is not mail and asks for the zone; the page and cards are unchanged";
            }
        }
    }
    /* A lone sfaf_ap_time() in mail is a time the range formatter never saw. */
    foreach ( sfaf_zone_calls( file_get_contents( $file ), 'sfaf_ap_time', $helpers ) as $c ) {
        if ( $c['mail'] && 'sfaf_replace_tokens' !== $c['func'] ) {
            $fails[] = "$rel:{$c['line']} {$c['func']}() builds mail with sfaf_ap_time(), which has no zone";
        }
    }
}

/* The helpers named above must still exist, or the list is guarding nothing. */
foreach ( $MAIL_HELPERS as $rel => $names ) {
    $src = file_get_contents( $root . '/' . $rel );
    foreach ( $names as $name ) {
        if ( ! preg_match( '/function\s+' . preg_quote( $name, '/' ) . '\s*\(/', $src ) ) {
            $fails[] = "$rel: $name() is named as a mail helper and no longer exists";
        }
    }
}

/* The change announcement carries times as phrases, so the zone goes on there. */
$announce = file_get_contents( $root . '/includes/class-sfaf-announce.php' );
if ( false === strpos( $announce, 'sfaf_ap_zoned( $pair[ $end ] )' ) ) {
    $fails[] = 'class-sfaf-announce.php: the moved-time pair in the change email is not zoned';
}

/* The calendar file is data and is unchanged: no zone style anywhere near it. */
foreach ( array( 'includes/class-sfaf-shortcodes.php', 'includes/class-sfaf-post-types.php' ) as $rel ) {
    $src = file_get_contents( $root . '/' . $rel );
    if ( preg_match( '/sfaf_ap_zoned|sfaf_ap_time_zone/', $src ) ) {
        $fails[] = "$rel: the zone reached a page or the .ics";
    }
}

if ( $mail_calls < 12 ) {
    $fails[] = "only $mail_calls mail call sites found; there were 12 when this was written, so the reader has lost some";
}

if ( $fails ) {
    echo 'FAIL: ' . count( $fails ) . "\n";
    foreach ( $fails as $f ) { echo '  . ' . $f . "\n"; }
    exit( 1 );
}
echo "formatter: \"12{$dash}2 pm PT\", generic all year, every US zone, nothing for a bare offset\n";
echo "call sites: $mail_calls in mail, every one zoned; $page_calls on pages, none zoned.\n";
exit( 0 );
