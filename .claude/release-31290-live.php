<?php
/**
 * 3.129.0 IN A BROWSER: WHO GETS THESE EMAILS AND REPLIES GO TO.
 *
 *     php .claude/release-31290-live.php --run
 *     php .claude/release-31290-live.php --run --verbose   and print what was measured
 *
 * REAL: Edit event rendered through wp-kit.php with the real portal.css and
 * portal.js, in Chrome at 1280px and 390px, with the script and without it.
 *
 * With the script:
 *   - the fold of people and teams is hidden; the creator is a chip
 *   - typing "ana" offers Ana Ruiz, and Enter makes her a chip; "prog" offers
 *     the Programs team and a click makes it a chip; a whole address and Enter
 *     makes an address chip; a calendar person's address is that person; a
 *     name nobody has says so; a chip pressed comes off
 *   - what the form would post is exactly the chips
 *   - the suggestions sit under the box, inside the card, and the page does
 *     not scroll sideways
 *   - Replies go to: a box over the hidden select, showing the creator; it
 *     lists everybody when focused, narrows to "ben", sets the select, and
 *     shows the address box only for Someone else
 * Without it:
 *   - the fold is there to open, the select and the address box are there
 *
 * PLANT in plant-31290.php.
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
$portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();
$me = new WP_User();
$GLOBALS['kit_users'] = array( $me );
$GLOBALS['kit_umeta'][ $me->ID ]['_uc_calendar_role'] = 'admin';
foreach ( array( 21 => 'Ana Ruiz', 22 => 'Ben Young', 23 => 'Cy Ortiz' ) as $uid => $name ) {
    $u = new WP_User(); $u->ID = $uid; $u->display_name = $name; $u->user_email = strtolower( strtok( $name, ' ' ) ) . '@example.org'; $u->user_login = 'u' . $uid;
    $GLOBALS['kit_users'][] = $u;
    $GLOBALS['kit_umeta'][ $uid ]['_uc_calendar_role'] = 'editor';
}
update_option( SFAF_Teams::OPTION, array( 'tprog' => array( 'name' => 'Programs', 'users' => array( 22, 23 ) ) ) );
$ev = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_title' => 'Support group', 'post_author' => $me->ID, 'post_content' => '<p>Weekly.</p>' ) );
foreach ( array( '_uc_event_date' => date( 'Y-m-d', strtotime( '+7 days' ) ), '_uc_start_time' => '18:00', '_uc_end_time' => '19:30', '_uc_location' => '1035 Market Street', '_uc_rsvp_enabled' => '1' ) as $k => $v ) { update_post_meta( $ev, $k, $v ); }

$edit = rl_cap( function () use ( $portal, $me, $ev ) { $_GET = array(); kit_call( 'SFAF_Portal', 'render_event_form', $portal, array( $me, $ev ) ); } );
rl( 0 !== strpos( $edit, 'THREW' ), "Edit event did not render: $edit" );
rl( false !== strpos( $edit, 'portal.js' ), 'the editor page loads no portal.js, so the script half cannot be tested' );
$ME = array( 'name' => $me->display_name, 'email' => $me->user_email );

$probe = <<<'JS'
(function () {
var out = { errors: [] };
window.addEventListener('beforeunload', function (e) { e.stopImmediatePropagation(); }, true);
window.addEventListener('error', function (e) { out.errors.push(String(e.message)); });
function q(s, r) { return (r || document).querySelector(s); }
function qa(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }
function shown(el) { return !!el && !el.hidden && el.getClientRects().length > 0 && getComputedStyle(el).visibility !== 'hidden'; }
function rect(el) { if (!el) { return null; } var r = el.getBoundingClientRect(); return { left: Math.round(r.left), right: Math.round(r.right), top: Math.round(r.top), bottom: Math.round(r.bottom), h: Math.round(r.height) }; }
function wait(ms, fn) { setTimeout(fn, ms); }
function type(el, text) { el.focus(); el.value = text; el.dispatchEvent(new Event('input', { bubbles: true })); }
function key(el, k) { el.dispatchEvent(new KeyboardEvent('keydown', { key: k, bubbles: true, cancelable: true })); }
function options(list) { return qa('[role="option"]', list).map(function (li) { return li.textContent.replace(/\s+/g, ' ').trim(); }); }
function chips(root) { return qa('[data-uc-rcpt-chips] [data-uc-rcpt-box]', root).filter(function (b) { return b.checked; }).map(function (b) { return b.name + '=' + b.value; }); }
function posted(form) {
  var fd = new FormData(form), o = {};
  ['notify_author', 'notify_users[]', 'notify_teams[]', 'notify_emails[]', 'notify_email_new', 'event_replyto_pick', 'event_replyto'].forEach(function (n) { o[n] = fd.getAll(n); });
  return o;
}
function finish() { out.pageWidth = document.documentElement.scrollWidth; window.parent.postMessage(JSON.stringify(out), '*'); }
var mode = window.RL_MODE;

document.addEventListener('DOMContentLoaded', function () { wait(300, function () {
  var root = q('[data-uc-recipients]'), card = q('[data-uc-card="notifications"]');
  var input = root && q('[data-uc-rcpt-input]', root), list = root && q('[data-uc-rcpt-list]', root);
  var more = root && q('[data-uc-rcpt-more]', root);
  var reply = q('[data-uc-replyto]'), select = reply && q('[data-uc-replyto-pick]', reply), other = reply && q('[data-uc-replyto-other]', reply);
  out.has = { root: !!root, input: !!input, list: !!list, reply: !!reply };
  out.card = rect(card);
  out.subheads = card ? qa('.uc-notify-subhead', card).filter(shown).map(function (h) { return h.textContent.trim(); }) : [];
  out.label = root ? (q('.uc-field-label', root) || {}).textContent : null;
  out.start = root ? chips(root) : null;
  out.creatorChipShown = root ? shown(q('[data-uc-rcpt-chips] [data-uc-rcpt="author"]', root)) : false;
  out.foldShown = shown(more);
  out.selectShown = shown(select);
  out.otherShown = shown(other);
  out.hint = reply ? (q('.uc-hint', reply) || {}).textContent : null;
  if (mode === 'nojs') {
    out.poolBoxes = root ? qa('[data-uc-rcpt-pool] [data-uc-rcpt-box]', root).length : 0;
    finish(); return;
  }
  var form = input.form;
  type(input, 'ana');
  wait(60, function () {
    out.anaOptions = options(list);
    out.listShown = shown(list);
    out.listBox = rect(list); out.inputBox = rect(input);
    out.optionH = list.children[0] ? Math.round(list.children[0].getBoundingClientRect().height) : 0;
    out.expanded = input.getAttribute('aria-expanded');
    key(input, 'Enter');
    wait(60, function () {
      out.afterAna = chips(root); out.inputAfterAna = input.value; out.listAfterAna = shown(list);
      type(input, 'prog');
      wait(60, function () {
        out.progOptions = options(list);
        var li = q('[role="option"]', list);
        if (li) { li.dispatchEvent(new MouseEvent('mousedown', { bubbles: true, cancelable: true })); li.click(); }
        wait(60, function () {
          out.afterProg = chips(root);
          type(input, 'new@example.org');
          wait(60, function () {
            out.addOption = options(list);
            key(input, 'Enter');
            wait(60, function () {
              out.afterNew = chips(root);
              type(input, 'ben@example.org');
              wait(60, function () {
                key(input, 'Enter');
                wait(60, function () {
                  out.afterBen = chips(root);
                  type(input, 'zzz');
                  wait(60, function () {
                    out.zzzOptions = options(list);
                    key(input, 'Enter');
                    var err = q('[data-uc-rcpt-error]', root);
                    out.zzzError = shown(err) ? err.textContent : null;
                    input.value = '';
                    var creator = q('[data-uc-rcpt-chips] [data-uc-rcpt="author"] input', root);
                    if (creator) { creator.click(); }
                    wait(60, function () {
                      out.afterCreatorOff = chips(root);
                      out.creatorStillShown = shown(q('[data-uc-rcpt="author"]', root));
                      out.posted = posted(form);
                      /* Replies go to */
                      var box = q('[data-uc-replyto-search]', reply);
                      var rlist = box ? document.getElementById(box.getAttribute('aria-controls')) : null;
                      out.replyBox = box ? box.value : null;
                      out.replyLabelFor = (q('.uc-field-label', reply) || {}).htmlFor;
                      out.replyBoxId = box ? box.id : null;
                      out.selectShownJs = shown(select);
                      out.otherShownJs = shown(other);
                      if (!box) { finish(); return; }
                      box.focus(); box.dispatchEvent(new FocusEvent('focus'));
                      wait(60, function () {
                        out.replyAll = options(rlist);
                        type(box, 'ben');
                        wait(60, function () {
                          out.replyBen = options(rlist);
                          key(box, 'Enter');
                          wait(60, function () {
                            out.replyPicked = [select.value, box.value, shown(other)];
                            type(box, 'someone');
                            wait(60, function () {
                              key(box, 'Enter');
                              wait(60, function () {
                                out.replyOther = [select.value, shown(other)];
                                finish();
                              });
                            });
                          });
                        });
                      });
                    });
                  });
                });
              });
            });
          });
        });
      });
    });
  });
}); });
})();
JS;

function rl_page( $html, $probe, $mode ) {
    if ( 'nojs' === $mode ) { $html = preg_replace( '#<script[^>]*src="[^"]*portal\.js[^"]*"[^>]*></script>#', '', $html ); }
    // The session hold has no server here (caladmin-posts-are-held-for-a-check).
    $html = str_replace( 'id="uc-session-config"', 'id="uc-session-config-off"', $html );
    return str_replace( '</body>', '<script>window.RL_MODE=' . json_encode( $mode ) . ';' . $probe . '</script></body>', $html );
}
$pages = array();
foreach ( array( 'js-desktop' => array( 'js', 1280 ), 'js-phone' => array( 'js', 390 ), 'nojs-desktop' => array( 'nojs', 1280 ) ) as $name => $c ) {
    $pages[ $name ] = array( 'w' => $c[1], 'mode' => $c[0], 'html' => rl_page( $edit, $probe, $c[0] ) );
}
$files = array();
foreach ( $pages as $name => $p ) {
    $inner = __DIR__ . '/release-31290-live-' . $name . '.html';
    file_put_contents( $inner, $p['html'] );
    $files[ $name ] = __DIR__ . '/release-31290-live-' . $name . '-frame.html';
    file_put_contents( $files[ $name ], '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="margin:0">'
        . '<iframe src="' . basename( $inner ) . '" style="display:block;width:' . (int) $p['w'] . 'px;height:900px;border:0"></iframe>'
        . '<script>window.addEventListener("message",function(e){var p=document.createElement("pre");p.id="out";p.textContent=e.data;document.body.appendChild(p);});</script></body></html>' );
}
if ( ! in_array( '--run', array_slice( $argv, 1 ), true ) ) { echo 'wrote ' . count( $files ) . " pages; --run drives them.\n"; exit( $fails ? 1 : 0 ); }
$chrome = getenv( 'SFAF_CHROME' ) ?: 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
if ( ! file_exists( $chrome ) ) { echo "Chrome not found at $chrome\n"; exit( 1 ); }

$g = array();
foreach ( $files as $name => $file ) {
    $dom = shell_exec( '"' . $chrome . '" --headless --disable-gpu --run-all-compositor-stages-before-draw --allow-file-access-from-files --window-size=1300,1000 --virtual-time-budget=20000 --dump-dom "file:///' . str_replace( '\\', '/', $file ) . '" 2>NUL' );
    if ( ! preg_match( '#<pre id="out">(.*?)</pre>#s', (string) $dom, $m ) ) { rl( false, "$name: Chrome returned no probe block" ); continue; }
    $g[ $name ] = json_decode( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ), true );
    rl( empty( $g[ $name ]['errors'] ), "$name threw: " . implode( '; ', (array) $g[ $name ]['errors'] ) );
    rl( (int) $g[ $name ]['pageWidth'] <= $pages[ $name ]['w'], "$name: the page scrolls sideways, " . $g[ $name ]['pageWidth'] . 'px in ' . $pages[ $name ]['w'] );
    if ( in_array( '--verbose', $argv, true ) ) { echo $name . ': ' . json_encode( $g[ $name ], JSON_UNESCAPED_SLASHES ) . "\n"; }
}

$creator_line = $ME['name'] . ' (creator), ' . $ME['email'];
foreach ( $g as $name => $r ) {
    rl( array( 'Sent to registrants', 'Sent to the people above' ) === $r['subheads'], "$name: the card's subheads are " . json_encode( $r['subheads'] ) );
    rl( 'Who gets these emails' === trim( (string) $r['label'] ), "$name: the field is labelled " . json_encode( $r['label'] ) );
    rl( array( 'notify_author=1' ) === $r['start'] && $r['creatorChipShown'], "$name: it does not open on the creator's chip alone: " . json_encode( $r['start'] ) );
    rl( 'Email replies come here. Anyone who replies to a text or WhatsApp is asked to email this address.' === trim( (string) $r['hint'] ), "$name: the line under Replies go to is " . json_encode( $r['hint'] ) );
    if ( 0 === strpos( $name, 'nojs' ) ) {
        rl( $r['foldShown'] && 4 === $r['poolBoxes'], "$name: with no script the fold of people and teams is not there to open: " . json_encode( array( $r['foldShown'], $r['poolBoxes'] ) ) );
        rl( $r['selectShown'] && $r['otherShown'], "$name: with no script the reply picker or its address box is missing" );
        continue;
    }
    rl( ! $r['foldShown'], "PLANT L: $name: the fold still shows beside the typing box" );
    rl( array( 'Ana Ruiz' ) === $r['anaOptions'] && $r['listShown'] && 'true' === $r['expanded'], "PLANT L: $name: typing ana offers " . json_encode( $r['anaOptions'] ) );
    rl( $r['listBox']['top'] >= $r['inputBox']['bottom'] && $r['listBox']['left'] >= $r['card']['left'] && $r['listBox']['right'] <= $r['card']['right'],
        "$name: the suggestions are not under the box, inside the card: " . json_encode( array( $r['listBox'], $r['inputBox'], $r['card'] ) ) );
    rl( $r['optionH'] >= 32, "$name: a suggestion is " . $r['optionH'] . 'px tall' );
    rl( array( 'notify_author=1', 'notify_users[]=21' ) === $r['afterAna'] && '' === $r['inputAfterAna'] && ! $r['listAfterAna'], "PLANT L: $name: Enter did not make Ana a chip: " . json_encode( $r['afterAna'] ) );
    rl( array( 'Programs, team, 2 people' ) === $r['progOptions'], "$name: typing prog offers " . json_encode( $r['progOptions'] ) );
    rl( array( 'notify_author=1', 'notify_users[]=21', 'notify_teams[]=tprog' ) === $r['afterProg'], "PLANT L: $name: a click did not make Programs a chip: " . json_encode( $r['afterProg'] ) );
    rl( array( 'Add new@example.org' ) === $r['addOption'], "$name: a whole address offers " . json_encode( $r['addOption'] ) );
    rl( in_array( 'notify_emails[]=new@example.org', $r['afterNew'], true ), "PLANT L: $name: Enter did not make the address a chip: " . json_encode( $r['afterNew'] ) );
    rl( in_array( 'notify_users[]=22', $r['afterBen'], true ) && ! in_array( 'notify_emails[]=ben@example.org', $r['afterBen'], true ), "$name: Ben's address did not choose Ben: " . json_encode( $r['afterBen'] ) );
    rl( array() === $r['zzzOptions'] && 'Choose a name from the list, or type a whole email address.' === $r['zzzError'], "$name: a name nobody has: " . json_encode( array( $r['zzzOptions'], $r['zzzError'] ) ) );
    rl( ! in_array( 'notify_author=1', $r['afterCreatorOff'], true ) && ! $r['creatorStillShown'], "PLANT L: $name: pressing the creator's chip did not take it off: " . json_encode( $r['afterCreatorOff'] ) );
    $p = $r['posted'];
    rl( array() === $p['notify_author'] && array( '21', '22' ) === $p['notify_users[]'] && array( 'tprog' ) === $p['notify_teams[]'] && array( 'new@example.org' ) === $p['notify_emails[]'] && array( '' ) === $p['notify_email_new'],
        "PLANT L: $name: the form would post " . json_encode( $p ) );
    rl( $creator_line === $r['replyBox'] && $r['replyLabelFor'] === $r['replyBoxId'] && ! $r['selectShownJs'] && ! $r['otherShownJs'],
        "PLANT R: $name: Replies go to does not open as a box on the creator: " . json_encode( array( $r['replyBox'], $r['replyLabelFor'], $r['selectShownJs'], $r['otherShownJs'] ) ) );
    rl( array( $creator_line, 'Ana Ruiz, ana@example.org', 'Ben Young, ben@example.org', 'Cy Ortiz, cy@example.org', 'Someone else' ) === $r['replyAll'], "PLANT R: $name: focused, it lists " . json_encode( $r['replyAll'] ) );
    rl( array( 'Ben Young, ben@example.org', 'Someone else' ) === $r['replyBen'], "$name: ben narrows it to " . json_encode( $r['replyBen'] ) );
    rl( array( 'ben@example.org', 'Ben Young, ben@example.org', false ) === $r['replyPicked'], "PLANT R: $name: choosing Ben gives " . json_encode( $r['replyPicked'] ) );
    rl( array( '__other', true ) === $r['replyOther'], "PLANT R: $name: Someone else does not show the address box: " . json_encode( $r['replyOther'] ) );
}

echo "\n3.129.0: Who gets these emails and Replies go to, in Chrome\n" . str_repeat( '=', 72 ) . "\n";
if ( $fails ) { echo count( $fails ) . " problem(s):\n  - " . implode( "\n  - ", $fails ) . "\n"; exit( 1 ); }
echo "One field takes a name, a team or an address as chips and posts exactly them; Replies go to is a searchable box with\nSomeone else, at 1280px and 390px; with no script the fold, the select and the address box are all there.\n";
exit( 0 );
