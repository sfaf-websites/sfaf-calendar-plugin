<?php
/**
 * 3.141.0 IN A BROWSER: GREEN MAIN ACTIONS, + NEW EVENT, THE TEAM RULE, ONE ARROW.
 *
 *     php .claude/release-31410-live.php --run
 *     php .claude/release-31410-live.php --run --verbose   and print what was measured
 *
 * REAL: every caladmin screen and both public forms as caps-sweep-live.php
 * writes them, WP admin's Closures screen with admin.css, and Add event for an
 * editor on two teams, through wp-kit.php with the real portal.css and
 * portal.js, in Chrome.
 *
 *   G  at 1280px, on every page: no visible button has a yellow face, and
 *      every main action (.uc-btn-primary, .uc-add-toggle, .button-primary)
 *      is green with white words: caladmin and WP admin #15803D, the two
 *      public forms #46661F (PLANT G); the named ones are found and measured
 *   N  + New Event: green, white, 16px, the calendar beside the words, and its
 *      hover moves the button and the calendar; with reduced motion, neither
 *      is in its transition
 *   T  Add event for somebody on two teams: Save draft and Publish do not
 *      leave without a team, and the error shows; with one ticked, they do
 *   A  every summary carrying the chevron, the series page's Copy onto
 *      another date and the four series disclosures among them, has no list
 *      marker: one arrow each (PLANT A)
 *   and at 430px and 390px the changed pages do not scroll sideways
 *
 * PLANTs in plant-31410.php.
 */

require __DIR__ . '/wp-kit.php';
if ( ! function_exists( 'wpautop' ) ) { function wpautop( $s ) { return $s; } }
if ( ! function_exists( 'get_post_stati' ) ) { function get_post_stati( $a = array() ) { return array( 'publish' => 1, 'draft' => 1, 'pending' => 1, 'future' => 1, 'private' => 1, 'trash' => 1 ); } }

$fails = array();
function rl( $ok, $why ) { global $fails; if ( ! $ok ) { $fails[] = $why; } }
function rl_cap( $fn ) {
    $d = ob_get_level(); ob_start();
    try { $fn(); } catch ( Throwable $t ) { while ( ob_get_level() > $d ) { ob_end_clean(); } return 'THREW ' . $t->getMessage() . ' at ' . basename( $t->getFile() ) . ':' . $t->getLine(); }
    $h = ''; while ( ob_get_level() > $d ) { $h = ob_get_clean() . $h; } return $h;
}
function rl_page( $html, $name ) {
    // caladmin's post check is left out, so a save is caught by the probe.
    $html = str_replace( 'id="uc-session-config"', 'id="uc-session-config-off"', $html );
    if ( false === stripos( $html, '<html' ) ) {
        $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><link rel="stylesheet" href="../public/css/portal.css"></head><body class="uc-portal" style="margin:0"><div class="uc-portal-main"><div class="uc-portal-content">'
            . $html . '</div></div><script src="../public/js/portal.js"></script></body></html>';
    }
    return str_replace( '</body>', '<script>window.RL_PAGE=' . json_encode( $name ) . ';' . $GLOBALS['probe'] . '</script></body>', $html );
}

/* ---- caladmin and the two forms: caps-sweep-live writes them, no Chrome. ---- */
$out = (string) shell_exec( 'php ' . escapeshellarg( __DIR__ . '/caps-sweep-live.php' ) . ' 2>&1' );
rl( false !== strpos( $out, 'wrote ' ), 'caps-sweep-live did not write its pages: ' . substr( $out, -400 ) );
$src = array();
foreach ( glob( __DIR__ . '/caps-sweep-live-*.html' ) as $f ) {
    $short = substr( basename( $f, '.html' ), strlen( 'caps-sweep-live-' ) );
    // The public calendar, list and sidebar are the embed's, which did not change.
    if ( 0 === strpos( $short, 'public-' ) && ! in_array( $short, array( 'public-request-form', 'public-submit-form' ), true ) ) { continue; }
    if ( false !== strpos( $short, '-frame' ) ) { continue; }
    $src[ $short ] = (string) file_get_contents( $f );
}
rl( count( $src ) >= 20, 'too few screens to measure: ' . implode( ', ', array_keys( $src ) ) );

/* ---- WP admin's Closures, with admin.css. ---- */
$admin = ( new ReflectionClass( 'SFAF_Admin' ) )->newInstanceWithoutConstructor();
$h = rl_cap( function () use ( $admin ) { $_GET = array(); $admin->render_closures_page(); } );
rl( 0 !== strpos( $h, 'THREW' ), 'WP admin Closures did not render: ' . substr( $h, 0, 300 ) );
$src['wp-closures'] = '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><link rel="stylesheet" href="../admin/css/admin.css"></head><body class="wp-admin" style="margin:0;background:#f0f0f1;font-family:sans-serif">' . $h . '</body></html>';

/* ---- Add event for an editor on two teams. ---- */
kit_reset();
$GLOBALS['kit_users'] = array();
$eve = new WP_User(); $eve->ID = 33; $eve->display_name = 'Eve Two'; $eve->user_email = 'eve@sfaf.org'; $eve->user_login = 'eve';
$GLOBALS['kit_users'][] = $eve;
$GLOBALS['kit_umeta'][33]['_uc_calendar_role'] = 'editor';
$GLOBALS['kit_refused'][] = 33;
$GLOBALS['kit_user_id'] = 33;
update_option( SFAF_Teams::OPTION, array(
    'housing' => array( 'name' => 'Housing', 'users' => array( 33 ) ),
    'harm'    => array( 'name' => 'Harm reduction', 'users' => array( 33 ) ),
) );
$portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();
$team = rl_cap( function () use ( $portal, $eve ) { $_GET = array(); kit_call( 'SFAF_Portal', 'render_event_form', $portal, array( $eve, 0 ) ); } );
rl( 0 !== strpos( $team, 'THREW' ), 'Add event for two teams did not render: ' . substr( $team, 0, 300 ) );
rl( false !== strpos( $team, 'data-uc-access-need-team' ), 'Add event for two teams does not ask for a team, so the gate cannot be tested' );
$src['team-add'] = $team;

/* ---- The series page's four disclosures. The sweep's series has no group and
   no past dates, so only Copy onto another date is drawn there; these are the
   four summaries exactly as render_series_edit() writes them (the release
   test holds the source to this shape), on the series page's own sheet. ---- */
$chev = sfaf_icon( 'chevron', array( 'size' => '15px' ) );
$four = '';
foreach ( array( 'uc-schedule-edit' => 'Change the pattern', 'uc-schedule-extend' => 'Extend the series', 'uc-schedule-pastfold' => '12 past dates', 'uc-schedule-add' => 'Copy &ldquo;Walk&rdquo; onto another date' ) as $cls => $words ) {
    $four .= '<details class="' . $cls . '"><summary><span class="uc-disclosure-chevron" aria-hidden="true">' . $chev . '</span>' . $words . '</summary><p class="uc-hint">Inside.</p></details>';
}
$src['series-four'] = '<div class="uc-card">' . $four . '</div>';
$CHANGED_EXTRA = array( 'series-four' );

$probe = <<<'JS'
(function () {
var P = window.RL_PAGE, out = { page: P, errors: [], yellow: [], mains: [], markers: [] };
window.addEventListener('beforeunload', function (e) { e.stopImmediatePropagation(); }, true);
window.addEventListener('error', function (e) { out.errors.push(String(e.message)); });
function q(s, r) { return (r || document).querySelector(s); }
function qa(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }
function text(el) { return el ? (el.textContent || '').replace(/\s+/g, ' ').trim() : ''; }
function seen(el) { if (!el || el.closest('[hidden]') || !el.getClientRects().length) { return false; } var r = el.getBoundingClientRect(); if (r.width < 2 || r.height < 2) { return false; } var cs = getComputedStyle(el); return cs.display !== 'none' && cs.visibility !== 'hidden'; }
function rgb(s) { var m = String(s).match(/[\d.]+/g); return m ? [+m[0], +m[1], +m[2], m.length > 3 ? +m[3] : 1] : null; }
function isYellow(s) { var c = rgb(s); return !!c && c[3] > 0.5 && c[0] > 215 && c[1] > 180 && c[2] < 90; }
function finish() { out.pageWidth = document.documentElement.scrollWidth; window.parent.postMessage(JSON.stringify(out), '*'); }
var sent = 0;
document.addEventListener('submit', function (e) { if (!e.defaultPrevented) { sent++; } e.preventDefault(); }, false);
setTimeout(function () {
  // G: no yellow button; every main action green.
  qa('button, input[type="submit"], input[type="button"], a.uc-btn, a.button, summary.uc-add-toggle, summary.uc-btn, .uc-form-links-open').forEach(function (b) {
    if (!seen(b)) { return; }
    var cs = getComputedStyle(b);
    if (isYellow(cs.backgroundColor) || isYellow(cs.borderTopColor)) { out.yellow.push({ label: text(b).slice(0, 40), cls: (b.getAttribute('class') || '').slice(0, 80), face: cs.backgroundColor }); }
  });
  qa('.uc-btn-primary, .uc-add-toggle, .button-primary, .uc-form-links-open').forEach(function (b) {
    if (!seen(b) || b.disabled) { return; }
    var cs = getComputedStyle(b);
    out.mains.push({ label: text(b).slice(0, 40), face: cs.backgroundColor, ink: cs.color });
  });
  // A: one arrow on every summary that carries the chevron.
  qa('summary').forEach(function (s) {
    var chev = s.querySelector(':scope > .uc-disclosure-chevron');
    if (!chev) { return; }
    var cs = getComputedStyle(s);
    out.markers.push({ label: text(s).slice(0, 50), list: cs.listStyleType, display: cs.display, chevrons: s.querySelectorAll(':scope > .uc-disclosure-chevron').length,
      inSeries: !!s.closest('.uc-schedule-edit, .uc-schedule-extend, .uc-schedule-add, .uc-schedule-pastfold') });
  });
  // N: + New Event.
  var nb = q('[data-uc-new-event]');
  if (nb) {
    var cs = getComputedStyle(nb), ic = q('.uc-new-event-icon', nb), ics = ic ? getComputedStyle(ic) : null;
    out.newEvent = { face: cs.backgroundColor, ink: cs.color, size: cs.fontSize, words: text(nb), icon: !!(ic && ic.querySelector('svg')), iconLeft: ic ? ic.getBoundingClientRect().left < nb.getBoundingClientRect().left + 40 : false,
      trans: cs.transitionProperty, iconTrans: ics ? ics.transitionProperty : '', h: Math.round(nb.getBoundingClientRect().height), reduced: matchMedia('(prefers-reduced-motion: reduce)').matches };
    var pl = q('.uc-btn-primary:not([data-uc-new-event])');
    out.otherPrimaryH = pl && seen(pl) ? Math.round(pl.getBoundingClientRect().height) : 0;
  }
  // T: the team gate.
  var box = q('[data-uc-access-need-team]');
  if (box && P.indexOf('team-add') === 0) {
    var form = box.closest('form');
    var draft = q('button[name="save_mode"][value="draft"]'), pub = q('button[name="save_mode"][value="publish"]');
    var err = q('[data-uc-access-team-error]', box);
    out.team = { draftFace: draft ? getComputedStyle(draft).backgroundColor : '', pubFace: pub ? getComputedStyle(pub).backgroundColor : '' };
    var before = sent;
    form.requestSubmit(draft);
    setTimeout(function () {
      out.team.blockedDraft = sent === before;
      out.team.errorShown = !!err && !err.hidden && seen(err);
      var b2 = sent;
      if (pub) { form.requestSubmit(pub); }
      setTimeout(function () {
        out.team.blockedPublish = sent === b2;
        var t = q('input[name="access_teams[]"]', box);
        if (t) { t.click(); }
        out.team.errorHiddenAfterTick = !!err && err.hidden;
        var b3 = sent;
        form.requestSubmit(draft);
        setTimeout(function () { out.team.wentWithTeam = sent > b3; finish(); }, 150);
      }, 150);
    }, 150);
    return;
  }
  finish();
}, 300);
})();
JS;
$GLOBALS['probe'] = $probe;

$CHANGED = array( 'series-four', 'caladmin-events', 'caladmin-new-event', 'caladmin-organizers', 'caladmin-venues', 'caladmin-series-edit', 'caladmin-login', 'caladmin-registrant-mail', 'public-request-form', 'public-submit-form', 'wp-closures', 'team-add' );
$pages = array();
foreach ( $src as $k => $html ) {
    $pages[ $k . '-1280' ] = array( 1280, rl_page( $html, $k . '-1280' ), array() );
    if ( in_array( $k, $CHANGED, true ) ) {
        $pages[ $k . '-430' ] = array( 430, rl_page( $html, $k . '-430' ), array() );
        $pages[ $k . '-390' ] = array( 390, rl_page( $html, $k . '-390' ), array() );
    }
}
$pages['caladmin-events-reduced'] = array( 1280, rl_page( $src['caladmin-events'], 'caladmin-events-reduced' ), array( '--force-prefers-reduced-motion' ) );
$files = array();
foreach ( $pages as $name => $p ) {
    $inner = __DIR__ . '/release-31410-live-' . $name . '.html';
    file_put_contents( $inner, $p[1] );
    $files[ $name ] = __DIR__ . '/release-31410-live-' . $name . '-frame.html';
    file_put_contents( $files[ $name ], '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="margin:0">'
        . '<iframe src="' . basename( $inner ) . '" style="display:block;width:' . (int) $p[0] . 'px;height:900px;border:0"></iframe>'
        . '<script>window.addEventListener("message",function(e){var p=document.createElement("pre");p.id="out";p.textContent=e.data;document.body.appendChild(p);});</script></body></html>' );
}
if ( ! in_array( '--run', array_slice( $argv, 1 ), true ) ) { echo 'wrote ' . count( $files ) . " pages; --run drives them.\n"; exit( $fails ? 1 : 0 ); }
$chrome = getenv( 'SFAF_CHROME' ) ?: 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
if ( ! file_exists( $chrome ) ) { echo "Chrome not found at $chrome\n"; exit( 1 ); }

$GREEN_ADMIN = 'rgb(21, 128, 61)';
$GREEN_FORMS = 'rgb(70, 102, 31)';
$g = array();
$said = array();
$named = array();
foreach ( $files as $name => $file ) {
    $extra = implode( ' ', $pages[ $name ][2] );
    $dom = shell_exec( '"' . $chrome . '" --headless --disable-gpu --run-all-compositor-stages-before-draw --allow-file-access-from-files ' . $extra . ' --window-size=1300,1000 --virtual-time-budget=20000 --dump-dom "file:///' . str_replace( '\\', '/', $file ) . '" 2>NUL' );
    if ( ! preg_match( '#<pre id="out">(.*?)</pre>#s', (string) $dom, $m ) ) { rl( false, "$name: Chrome returned no probe block" ); continue; }
    $r = json_decode( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ), true );
    $g[ $name ] = $r;
    if ( in_array( '--verbose', $argv, true ) ) { echo $name . ': ' . json_encode( $r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n"; }
    rl( empty( $r['errors'] ), "$name threw: " . implode( '; ', (array) $r['errors'] ) );
    rl( (int) $r['pageWidth'] <= $pages[ $name ][0], "$name: the page scrolls sideways, " . $r['pageWidth'] . 'px in ' . $pages[ $name ][0] );
    rl( empty( $r['yellow'] ), "PLANT G: $name: a yellow button: " . json_encode( $r['yellow'] ) );
    $want = ( 0 === strpos( $name, 'public-' ) ) ? $GREEN_FORMS : $GREEN_ADMIN;
    foreach ( (array) $r['mains'] as $mn ) {
        rl( $want === $mn['face'] && 'rgb(255, 255, 255)' === $mn['ink'], "PLANT G: $name: the main action \"{$mn['label']}\" is " . $mn['face'] . ' with ' . $mn['ink'] . ", not $want with white" );
        if ( false !== strpos( $name, '-1280' ) ) { $named[ $mn['label'] ] = $mn['face']; }
    }
    foreach ( (array) $r['markers'] as $mk ) {
        // NOT THIS RELEASE'S: the Pending queue's import panel sets list-style: revert
        // and shows two arrows. Mark's brief named the series page's four and
        // "nothing else", so it is reported in the 3.141.0 hand-off, not changed.
        if ( 0 === strpos( $name, 'caladmin-pending' ) && 'Set the fields this platform does not supply' === $mk['label'] ) { continue; }
        rl( 'none' === $mk['list'] && 1 === (int) $mk['chevrons'], "PLANT A: $name: \"{$mk['label']}\" shows a second arrow: list-style " . $mk['list'] );
    }
}

// Publish is .uc-btn-go, green since 3.98.0; the team pages measure it beside Save draft.
foreach ( array( '+ New Event', 'Save draft', 'Sign In', 'Add organizer', 'Add a venue', 'Send the request', 'Submit this event', 'Add closure' ) as $label ) {
    $hit = '';
    foreach ( $named as $l => $face ) { if ( 0 === strpos( $l, $label ) || false !== strpos( $l, $label ) ) { $hit = $face; break; } }
    rl( '' !== $hit, "G: \"$label\" was not found as a main action on any page" );
    if ( '' !== $hit ) { $said[] = sprintf( '%-18s %s', $label, $hit ); }
}

foreach ( array( '1280', '430', '390' ) as $w ) {
    $ne = $g[ 'caladmin-events-' . $w ]['newEvent'] ?? null;
    rl( is_array( $ne ), "N: + New Event is not on the Events page at {$w}px" );
    if ( ! is_array( $ne ) ) { continue; }
    rl( $GREEN_ADMIN === $ne['face'] && 'rgb(255, 255, 255)' === $ne['ink'] && '16px' === $ne['size'] && $ne['icon'] && $ne['iconLeft'] && '+ New Event' === $ne['words'],
        "PLANT N: {$w}px: + New Event is " . json_encode( $ne ) );
    rl( false !== strpos( $ne['trans'], 'transform' ) && false !== strpos( $ne['iconTrans'], 'transform' ), "N: {$w}px: the hover does not move the button and the calendar: " . json_encode( $ne ) );
    if ( '1280' === $w ) { $said[] = sprintf( '+ New Event: %s, %s, %dpx tall against %dpx for the next primary, transition %s', $ne['face'], $ne['size'], $ne['h'], $g['caladmin-events-1280']['otherPrimaryH'] ?? 0, $ne['trans'] ); }
}
$rd = $g['caladmin-events-reduced']['newEvent'] ?? null;
rl( is_array( $rd ) && $rd['reduced'] && false === strpos( $rd['trans'], 'transform' ) && false === strpos( (string) $rd['iconTrans'], 'transform' ),
    'PLANT N: with reduced motion the hover still moves: ' . json_encode( $rd ) );
if ( is_array( $rd ) ) { $said[] = '+ New Event with reduced motion: transition ' . $rd['trans'] . ', calendar ' . ( '' === $rd['iconTrans'] ? 'none' : $rd['iconTrans'] ); }

foreach ( array( '1280', '430', '390' ) as $w ) {
    $t = $g[ 'team-add-' . $w ]['team'] ?? null;
    rl( is_array( $t ) && $t['blockedDraft'] && $t['blockedPublish'] && $t['errorShown'], "PLANT T: {$w}px: Save draft or Publish left without a team: " . json_encode( $t ) );
    rl( is_array( $t ) && $t['errorHiddenAfterTick'] && $t['wentWithTeam'], "T: {$w}px: with a team ticked the save does not go: " . json_encode( $t ) );
    rl( is_array( $t ) && $GREEN_ADMIN === $t['draftFace'] && $GREEN_ADMIN === $t['pubFace'], "G: {$w}px: Save draft and Publish are not both green: " . json_encode( $t ) );
}
$series_marks = 0;
foreach ( $g as $name => $r ) { foreach ( (array) $r['markers'] as $mk ) { if ( $mk['inSeries'] ) { $series_marks++; } } }
rl( $series_marks >= 12, 'A: the four series disclosures were not measured at every width: ' . $series_marks );
$total_marks = 0; foreach ( $g as $r ) { $total_marks += count( (array) $r['markers'] ); }
$said[] = sprintf( 'one arrow: %d summaries with the chevron measured, %d of them on the series page', $total_marks, $series_marks );

echo "\n3.141.0: green main actions, + New Event, the team rule and one arrow, in Chrome\n" . str_repeat( '=', 72 ) . "\n";
foreach ( $said as $s ) { echo "  $s\n"; }
echo '  pages: ' . count( $g ) . ' of ' . count( $files ) . "\n";
if ( $fails ) { echo count( $fails ) . " problem(s):\n  - " . implode( "\n  - ", $fails ) . "\n"; exit( 1 ); }
echo "No button is yellow and every main action is green, + New Event is larger with its calendar and still under reduced motion, a new event waits for a team, and every disclosure shows one arrow, at 1280px, 430px and 390px.\n";
exit( 0 );
