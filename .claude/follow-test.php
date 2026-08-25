<?php
/**
 * FOLLOWING A SERIES, EXERCISED.
 *
 * There is no WordPress and no database here, so this cannot prove a row lands
 * in MySQL or that an email arrives. What it CAN prove is every decision made
 * before either of those: that a submission is PENDING and in no audience, that
 * confirming is what puts somebody in one, that following twice leaves one
 * record, that case is not a second person, that a confirm token cannot be
 * replayed, that an unsubscribe link exists in the very first email and works,
 * that an out-of-time pending record is inert whether or not it has been swept,
 * and that neither a registration nor a marketing opt-in is touched by any of
 * it.
 *
 *     php .claude/follow-test.php
 *
 * WHAT IT STUBS. WordPress, $wpdb, and the four classes SFAF_Follow calls out
 * to. SFAF_Follow itself is the real file.
 *
 * THE $wpdb STUB READS ITS INPUT. It parses the WHERE clause rather than
 * matching whole query strings, because a stub that answers the same way
 * whatever it is asked does not test the asking. That was learned the hard way
 * on the cancellation harness, where a stub reading only `status = 'x'` quietly
 * stopped filtering at all the day the real query changed shape.
 */

$root = dirname( __DIR__ );

define( 'ABSPATH', __DIR__ );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
date_default_timezone_set( 'America/Los_Angeles' );

/* ---------------------------------------------------------------------------
 * WordPress, in miniature.
 * ------------------------------------------------------------------------ */
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES, 'UTF-8' ); }
function is_email( $e ) { return (bool) filter_var( (string) $e, FILTER_VALIDATE_EMAIL ); }
function sanitize_email( $e ) { return (string) $e; }
function sanitize_text_field( $t ) { return trim( strip_tags( (string) $t ) ); }
function wp_unslash( $v ) { return $v; }
function current_time( $type, $gmt = 0 ) { return 'mysql' === $type ? date( 'Y-m-d H:i:s' ) : time(); }
function home_url( $path = '', $scheme = null ) { return 'https://resources.example.org' . $path; }
function add_query_arg( $key, $value = '', $url = '' ) {
    return $url . ( false === strpos( (string) $url, '?' ) ? '?' : '&' ) . rawurlencode( $key ) . '=' . rawurlencode( $value );
}
function nocache_headers() {}
function check_ajax_referer( $action, $q = false, $die = true ) { return true; }
function wp_send_json( $data, $status = null ) { $GLOBALS['json'] = $data; throw new SFAF_Sent(); }
function wp_die( $message = '', $title = '', $args = array() ) { $GLOBALS['page'] = $message; throw new SFAF_Sent(); }
function add_action( $hook, $cb, $priority = 10, $args = 1 ) {}

/** Both wp_send_json() and wp_die() end the request. This is that. */
class SFAF_Sent extends Exception {}

/* ---------------------------------------------------------------------------
 * The four classes SFAF_Follow calls out to.
 * ------------------------------------------------------------------------ */
class SFAF_Series {
    const TAXONOMY = 'uc_series';
    public static $terms = array();
    public static function get( $term_id ) {
        $id = (int) $term_id;
        return isset( self::$terms[ $id ] ) ? self::$terms[ $id ] : null;
    }
}

class SFAF_Reminders {
    public static $n = 0;
    /** Sequential rather than random, so a test can name the token it expects. */
    public static function new_token() {
        self::$n++;
        return str_pad( 'tok' . self::$n, 32, '0', STR_PAD_LEFT );
    }
}

/**
 * PRESENT ONLY SO ITS ABSENCE IS PROVABLE. Nothing in SFAF_Follow may call
 * this. It is stubbed so that a build which starts recording consent as a side
 * effect of following fails on the assertion that says so, rather than on an
 * undefined class, which reads as a broken harness.
 */
class SFAF_Optins {
    public static $records = array();
    public static function record( $email, $name, $event_id, $source ) {
        self::$records[] = compact( 'email', 'name', 'event_id', 'source' );
        return true;
    }
}

class SFAF_Submissions {
    public static $refuse = false;
    public static function allow( $bucket, $who, $limit, $window ) { return ! self::$refuse; }
    public static function client() { return '203.0.113.9'; }
}

class SFAF_Email {
    const C_TEAL = '#0E7680';
    const POSTAL = 'San Francisco AIDS Foundation, 940 Howard Street, San Francisco, CA 94103';
    public static $sent = array();
    public static function heading( $t ) { return '<h1>' . esc_html( $t ) . '</h1>'; }
    public static function para( $t ) { return '<p>' . esc_html( $t ) . '</p>'; }
    public static function small_para( $h ) { return '<p class="small">' . $h . '</p>'; }
    public static function rule() { return '<hr />'; }
    public static function button( $url, $label, $style = 'primary', $icon = false, $fill = false ) {
        return '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
    }
    public static function button_row( $buttons ) { return implode( '', $buttons ); }
    public static function shell( $preheader, $content ) { return '<html><body>' . $content . '</body></html>'; }
    public static function send( $to, $subject, $html, $text, $reply_to = '' ) {
        self::$sent[] = compact( 'to', 'subject', 'html', 'text', 'reply_to' );
        return true;
    }
}

/* ---------------------------------------------------------------------------
 * $wpdb, holding one table in memory and reading the WHERE clause it is given.
 * ------------------------------------------------------------------------ */
class SFAF_Wpdb_Stub {
    public $prefix     = 'wp_';
    public $insert_id  = 0;
    public $rows       = array();
    private $next_id   = 1;

    public function prepare( $sql, ...$args ) {
        // Flatten a single array argument, the way $wpdb::prepare() does.
        if ( 1 === count( $args ) && is_array( $args[0] ) ) {
            $args = $args[0];
        }
        $out = '';
        $i   = 0;
        $len = strlen( $sql );
        for ( $p = 0; $p < $len; $p++ ) {
            if ( '%' === $sql[ $p ] && $p + 1 < $len && ( 'd' === $sql[ $p + 1 ] || 's' === $sql[ $p + 1 ] ) ) {
                $v = isset( $args[ $i ] ) ? $args[ $i ] : '';
                $i++;
                $out .= ( 'd' === $sql[ $p + 1 ] ) ? (string) (int) $v : "'" . str_replace( "'", "''", (string) $v ) . "'";
                $p++;
                continue;
            }
            $out .= $sql[ $p ];
        }
        return $out;
    }

    /**
     * Every `col op value` in the WHERE, as a list. Only the operators the
     * queries under test actually use, and anything else is a hard failure
     * rather than a silently ignored condition.
     */
    private function conditions( $sql ) {
        if ( ! preg_match( '/WHERE\s+(.*?)(?:\s+ORDER\s+BY|\s+LIMIT|$)/is', $sql, $m ) ) {
            return array();
        }
        $out = array();
        foreach ( preg_split( '/\s+AND\s+/i', trim( $m[1] ) ) as $part ) {
            if ( ! preg_match( "/^([a-z_]+)\s*(=|<>|<)\s*(?:'((?:[^']|'')*)'|(\d+))$/i", trim( $part ), $c ) ) {
                throw new RuntimeException( 'the stub cannot read this condition: ' . trim( $part ) );
            }
            $value = isset( $c[4] ) && '' !== $c[4] ? $c[4] : str_replace( "''", "'", $c[3] );
            $out[] = array( $c[1], $c[2], $value );
        }
        return $out;
    }

    private function matching( $sql ) {
        $conds = $this->conditions( $sql );
        $hits  = array();
        foreach ( $this->rows as $row ) {
            $ok = true;
            foreach ( $conds as $c ) {
                list( $col, $op, $want ) = $c;
                $have = isset( $row->$col ) ? (string) $row->$col : '';
                if ( '=' === $op && $have !== (string) $want ) { $ok = false; break; }
                if ( '<>' === $op && $have === (string) $want ) { $ok = false; break; }
                if ( '<' === $op && ! ( $have < (string) $want ) ) { $ok = false; break; }
            }
            if ( $ok ) { $hits[] = $row; }
        }
        return $hits;
    }

    /**
     * A READ RETURNS A COPY, BECAUSE THE REAL ONE DOES.
     *
     * $wpdb hydrates a fresh object out of the result set, so writing to what a
     * read handed back changes nothing in the database. Returning the stored
     * object instead made this stub quietly stronger than MySQL: confirm()
     * assigns $row->confirm_token = '' on the object it is holding, purely so
     * the value it RETURNS is current, and with a shared reference that
     * assignment also cleared the stored row. A fault that removed the actual
     * UPDATE was then invisible. Clone on the way out and it is not.
     */
    private static function copies( $rows ) {
        $out = array();
        foreach ( $rows as $row ) { $out[] = clone $row; }
        return $out;
    }

    public function get_row( $sql, $output = OBJECT, $y = 0 ) {
        $hits = self::copies( $this->matching( $sql ) );
        return $hits ? $hits[0] : null;
    }
    public function get_results( $sql, $output = OBJECT ) { return self::copies( $this->matching( $sql ) ); }
    public function get_var( $sql, $x = 0, $y = 0 ) {
        $hits = $this->matching( $sql );
        return preg_match( '/COUNT\(\*\)/i', $sql ) ? count( $hits ) : ( $hits ? reset( (array) $hits[0] ) : null );
    }
    public function query( $sql ) {
        if ( ! preg_match( '/^\s*DELETE/i', $sql ) ) {
            throw new RuntimeException( 'the stub only handles DELETE through query()' );
        }
        $doomed = $this->matching( $sql );
        $ids    = array();
        foreach ( $doomed as $d ) { $ids[] = (int) $d->id; }
        $kept = array();
        foreach ( $this->rows as $row ) {
            if ( ! in_array( (int) $row->id, $ids, true ) ) { $kept[] = $row; }
        }
        $this->rows = $kept;
        return count( $ids );
    }
    public function insert( $table, $data, $format = null ) {
        // The unique key on (term_id, email_key), enforced here because it is
        // the thing that makes "following twice leaves one record" true.
        foreach ( $this->rows as $row ) {
            if ( (int) $row->term_id === (int) $data['term_id'] && $row->email_key === $data['email_key'] ) {
                return false;
            }
        }
        $data['id']           = $this->next_id++;
        $data['confirmed_at'] = null;
        $this->rows[]         = (object) $data;
        $this->insert_id      = (int) $data['id'];
        return 1;
    }
    public function update( $table, $data, $where, $f = null, $wf = null ) {
        $n = 0;
        foreach ( $this->rows as $row ) {
            if ( (int) $row->id !== (int) $where['id'] ) { continue; }
            foreach ( $data as $k => $v ) { $row->$k = $v; }
            $n++;
        }
        return $n;
    }
    public function delete( $table, $where, $f = null ) {
        $kept = array();
        $n    = 0;
        foreach ( $this->rows as $row ) {
            if ( (int) $row->id === (int) $where['id'] ) { $n++; continue; }
            $kept[] = $row;
        }
        $this->rows = $kept;
        return $n;
    }
}

if ( ! defined( 'OBJECT' ) ) { define( 'OBJECT', 'OBJECT' ); }
$GLOBALS['wpdb'] = new SFAF_Wpdb_Stub();

require_once $root . '/includes/class-sfaf-follow.php';

/* ---------------------------------------------------------------------------
 * Assertions.
 * ------------------------------------------------------------------------ */
$fails = array();
function expect( $what, $got, $want ) {
    global $fails;
    if ( $got === $want ) {
        printf( "  ok    %s\n", $what );
        return;
    }
    $fails[] = sprintf( '%s: expected %s, got %s', $what, var_export( $want, true ), var_export( $got, true ) );
    printf( "  FAIL  %s (expected %s, got %s)\n", $what, var_export( $want, true ), var_export( $got, true ) );
}

SFAF_Series::$terms[ 7 ] = (object) array( 'term_id' => 7, 'name' => 'Wednesday Support Group' );
SFAF_Series::$terms[ 9 ] = (object) array( 'term_id' => 9, 'name' => 'Cycle to Zero' );

/* ---------------------------------------------------------------------------
 * 1. PENDING UNTIL CONFIRMED, AND A PENDING RECORD IS IN NO AUDIENCE.
 * ------------------------------------------------------------------------ */
echo "Pending until confirmed\n";

expect( 'a submission is accepted', SFAF_Follow::follow( 7, 'dana@example.org' ), true );
expect( 'one record exists', count( $GLOBALS['wpdb']->rows ), 1 );
expect( 'and it is pending', $GLOBALS['wpdb']->rows[0]->status, 'pending' );
expect( 'a pending record is in no audience', count( SFAF_Follow::active_followers( 7 ) ), 0 );
expect( 'and is not counted', SFAF_Follow::count_followers( 7 ), 0 );
expect( 'one email went out', count( SFAF_Email::$sent ), 1 );

/*
 * THE UNSUBSCRIBE LINK IS IN THE FIRST EMAIL. This is the fault the mechanism
 * being replaced had: it minted a token at subscribe time and then delivered it
 * to nobody, so the only route out arrived on the morning of the event.
 */
$first   = SFAF_Email::$sent[0];
$pending = $GLOBALS['wpdb']->rows[0];
expect( 'it went to the address given', $first['to'], 'dana@example.org' );
expect(
    'the confirm link is in it',
    false !== strpos( $first['text'], SFAF_Follow::confirm_url( $pending->confirm_token ) ),
    true
);
expect(
    'and so is the unsubscribe link',
    false !== strpos( $first['text'], SFAF_Follow::stop_url( $pending->token ) ),
    true
);
expect( 'the two tokens are different', $pending->confirm_token !== $pending->token, true );
expect(
    'the text part says nothing else is sent',
    false !== strpos( $first['text'], 'Nothing else is sent' ),
    true
);

/* ---------------------------------------------------------------------------
 * 2. CONFIRMING IS WHAT PUTS SOMEBODY IN THE AUDIENCE.
 * ------------------------------------------------------------------------ */
echo "Confirming\n";

$confirm_token = $pending->confirm_token;
$stop_token    = $pending->token;

$done = SFAF_Follow::confirm( $confirm_token );
expect( 'confirming returns the row', is_object( $done ), true );
expect( 'the record is active', $GLOBALS['wpdb']->rows[0]->status, 'active' );
expect( 'and is now in the audience', count( SFAF_Follow::active_followers( 7 ) ), 1 );
expect( 'and is counted', SFAF_Follow::count_followers( 7 ), 1 );

/*
 * THE CONFIRM TOKEN CANNOT BE REPLAYED. It is cleared at confirm, and every
 * lookup rejects an empty token before it queries — otherwise '' would match
 * every confirmed row on the site, which is the same trap the RSVP cancel link
 * was written to avoid.
 */
expect( 'the confirm token is cleared', $GLOBALS['wpdb']->rows[0]->confirm_token, '' );
expect( 'confirming again does nothing', SFAF_Follow::confirm( $confirm_token ), null );
expect( 'an empty confirm token matches nothing', SFAF_Follow::confirm( '' ), null );

/* ---------------------------------------------------------------------------
 * 3. FOLLOWING TWICE LEAVES ONE RECORD, AND CASE IS NOT A SECOND PERSON.
 * ------------------------------------------------------------------------ */
echo "One person, one record\n";

SFAF_Email::$sent = array();
expect( 'somebody already following is not written to again', SFAF_Follow::follow( 7, 'dana@example.org' ), false );
expect( 'and no second email goes out', count( SFAF_Email::$sent ), 0 );
expect( 'and there is still one record', count( $GLOBALS['wpdb']->rows ), 1 );

expect( 'a different spelling is the same person', SFAF_Follow::follow( 7, 'DANA@Example.ORG' ), false );
expect( 'and still one record', count( $GLOBALS['wpdb']->rows ), 1 );

// The same address on a DIFFERENT series is a different subscription, because
// each one is a separate standing instruction about a separate programme.
expect( 'the same address may follow another series', SFAF_Follow::follow( 9, 'dana@example.org' ), true );
expect( 'which is a second record', count( $GLOBALS['wpdb']->rows ), 2 );
expect( 'and it is pending on its own', SFAF_Follow::count_followers( 9 ), 0 );

/* A second ask before confirming replaces the link rather than adding a row. */
SFAF_Email::$sent = array();
$before = $GLOBALS['wpdb']->rows[1]->confirm_token;
expect( 'asking again while pending is accepted', SFAF_Follow::follow( 9, 'dana@example.org' ), true );
expect( 'and still leaves one record for that series', count( $GLOBALS['wpdb']->rows ), 2 );
expect( 'with a fresh link', $GLOBALS['wpdb']->rows[1]->confirm_token !== $before, true );
expect( 'and the old one dead', SFAF_Follow::confirm( $before ), null );

/* ---------------------------------------------------------------------------
 * 4. STOPPING.
 * ------------------------------------------------------------------------ */
echo "Stopping\n";

expect( 'the unsubscribe token resolves', is_object( SFAF_Follow::stop( $stop_token ) ), true );
expect( 'the record is gone', SFAF_Follow::count_followers( 7 ), 0 );
expect( 'and the token no longer resolves', SFAF_Follow::stop( $stop_token ), null );
expect( 'an empty unsubscribe token matches nothing', SFAF_Follow::stop( '' ), null );

/* ---------------------------------------------------------------------------
 * 5. AN UNCONFIRMED RECORD EXPIRES.
 *
 * Inert BEFORE it is swept, and gone after. The two halves are separate: a row
 * out of time must not be confirmable even on a site where nothing has yet
 * triggered the sweep.
 * ------------------------------------------------------------------------ */
echo "Expiry\n";

$GLOBALS['wpdb']->rows = array();
SFAF_Email::$sent      = array();

SFAF_Follow::follow( 7, 'stale@example.org' );
$stale = $GLOBALS['wpdb']->rows[0];
$stale->created_at = date( 'Y-m-d H:i:s', time() - ( ( SFAF_Follow::CONFIRM_DAYS + 1 ) * DAY_IN_SECONDS ) );

expect( 'an out-of-time record cannot be confirmed', SFAF_Follow::confirm( $stale->confirm_token ), null );
expect( 'and is still there until something sweeps', count( $GLOBALS['wpdb']->rows ), 1 );

SFAF_Follow::purge_expired();
expect( 'the sweep removes it', count( $GLOBALS['wpdb']->rows ), 0 );

/* An ACTIVE record is never swept, however old it is. */
SFAF_Follow::follow( 7, 'old@example.org' );
SFAF_Follow::confirm( $GLOBALS['wpdb']->rows[0]->confirm_token );
$GLOBALS['wpdb']->rows[0]->created_at = date( 'Y-m-d H:i:s', time() - ( 900 * DAY_IN_SECONDS ) );
SFAF_Follow::purge_expired();
expect( 'a confirmed follower is never swept', SFAF_Follow::count_followers( 7 ), 1 );

/* ---------------------------------------------------------------------------
 * 6. WHAT THE FORM SAYS BACK.
 *
 * THE SAME SENTENCE WHATEVER HAPPENED. Sent, already following, and refused by
 * a rate limit are three different outcomes and one answer, because a different
 * screen for any of them answers "is that address known here".
 * ------------------------------------------------------------------------ */
echo "One answer, three outcomes\n";

$GLOBALS['wpdb']->rows = array();
$follow = new SFAF_Follow();

function said( $follow, $series_id, $email ) {
    $_POST = array( 'series_id' => $series_id, 'email' => $email );
    unset( $GLOBALS['json'] );
    try { $follow->ajax_follow(); } catch ( SFAF_Sent $e ) {}
    return isset( $GLOBALS['json'] ) ? $GLOBALS['json'] : array();
}

$fresh = said( $follow, 7, 'new@example.org' );
expect( 'a new address is told to check its email', $fresh['success'], true );

$again = said( $follow, 7, 'new@example.org' );
expect( 'and so is one already known', $again['message'], $fresh['message'] );

SFAF_Submissions::$refuse = true;
$limited = said( $follow, 7, 'new@example.org' );
expect( 'and so is one that was rate limited', $limited['message'], $fresh['message'] );
SFAF_Submissions::$refuse = false;

/* A MALFORMED ADDRESS IS THE ONE THING SAID PLAINLY. It discloses nothing about
 * anybody, and somebody who mistyped their own needs telling. */
$bad = said( $follow, 7, 'not-an-address' );
expect( 'a malformed address is refused in as many words', $bad['success'], false );

/* A series that does not exist writes nothing and still says the same thing. */
$GLOBALS['wpdb']->rows = array();
$nowhere = said( $follow, 4242, 'someone@example.org' );
expect( 'an unknown series says the same sentence', $nowhere['message'], $fresh['message'] );
expect( 'and writes nothing', count( $GLOBALS['wpdb']->rows ), 0 );

/* ---------------------------------------------------------------------------
 * 7. THE THINGS THIS MUST NOT TOUCH, ASSERTED FROM THE SOURCE.
 *
 * These are cheap and they are the invariants the release exists for, so they
 * are worth asserting structurally rather than trusting to a reading.
 * ------------------------------------------------------------------------ */
echo "What it must not touch\n";

$src  = file_get_contents( $root . '/includes/class-sfaf-follow.php' );
$code = preg_replace( '#/\*.*?\*/#s', '', $src );

expect( 'no registration table is read or written', false !== strpos( $code, 'uc_rsvps' ), false );
expect( 'no marketing opt-in is recorded', false !== strpos( $code, 'SFAF_Optins' ), false );
expect( 'and none was recorded while running', count( SFAF_Optins::$records ), 0 );
expect( 'no REST route is registered', false !== strpos( $code, 'register_rest_route' ), false );

/*
 * A GET NEVER ACTS, IN EITHER DIRECTION. Both handlers must reach their
 * mutation only inside a posted() branch: a link previewer that followed the
 * unsubscribe URL would drop somebody off a list they wanted, and one that
 * followed the confirm URL would activate a record the person never answered,
 * which is precisely what confirming exists to prove.
 */
foreach ( array( 'handle_confirm' => 'self::confirm(', 'handle_stop' => 'self::stop(' ) as $fn => $mutation ) {
    if ( ! preg_match( '/function ' . $fn . '\((.*?)\n    \}/s', $code, $m ) ) {
        $fails[] = $fn . '() could not be found, so the check below proves nothing';
        continue;
    }
    $body  = $m[1];
    $gate  = strpos( $body, 'self::posted(' );
    $acts  = strpos( $body, $mutation );
    expect( $fn . '() gates its write on a POST', ( false !== $gate && false !== $acts && $gate < $acts ), true );
}

/* ------------------------------------------------------------------------ */
echo "\n";
if ( $fails ) {
    echo count( $fails ) . " failure(s):\n";
    foreach ( $fails as $f ) { echo '  - ' . $f . "\n"; }
    exit( 1 );
}
echo "following a series behaves: pending until confirmed, one record per person per series, a route out in the first email.\n";
