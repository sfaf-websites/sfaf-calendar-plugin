<?php
/**
 * THE REQUIRED MARKS, IN A REAL BROWSER, ON ALL THREE FORMS (3.99.0).
 *
 *     php .claude/required-marks-live.php          writes the four pages
 *     php .claude/required-marks-live.php --run    writes them and drives Chrome
 *
 * WHAT IT RENDERS. The community submission form, the staff request form with
 * organizers and without, and a new event in the caladmin editor, each through
 * its real renderer inside wp-kit.php, with the real portal.css and the real
 * portal.js. Nothing on these pages is retyped.
 *
 * WHAT DECIDES "RIGHT". Not the browser engine being tested. For every state
 * the page is driven into, PHP asks the form's own required_fields() list, via
 * SFAF_Submissions::required_applies(), which fields are required in that
 * state, and hands the answer to the page. The page then reports what is
 * actually marked and what actually carries `required` or aria-required, and
 * the two are compared here. So the engine in portal.js is checked against the
 * server's reading of the same list, and a mark that is right only because the
 * engine and its test share a mistake cannot pass.
 *
 * WHAT IT CHECKS, IN EACH STATE:
 *
 *   every required field's label carries a visible mark, and it is on the
 *   label of the field that is required (the mark's own field wrapper holds
 *   that field's control);
 *   no other label carries one, so no optional field is marked;
 *   every required control carries `required` or aria-required, as its entry
 *   says, and a legend says "(required)" where the controls can carry neither;
 *   no control outside the list carries either;
 *   the mark is the label's own colour and weight;
 *   the one line at the top says what the mark means.
 *
 * AND THE CONDITIONS MOVE. Cost to "Something else" and back, age likewise,
 * "use my details" ticked and unticked, a venue chosen and then "enter it by
 * hand". The mark has to arrive and leave with each.
 *
 * WHAT IT CANNOT SEE. TinyMCE does not load here, so the description is its
 * plain textarea; that the editor's own body announces required is in
 * TESTING.md.
 */

require __DIR__ . '/wp-kit.php';

$fails = array();
function rm_check( $ok, $why ) {
    global $fails;
    if ( ! $ok ) { $fails[] = $why; }
}

/* ------------------------------------------------------------ the pages */

function rm_capture( $fn ) {
    ob_start();
    $fn();
    return (string) ob_get_clean();
}

/**
 * The expected state of a public form, from its list, for a set of answers.
 * Inputs are named as the page names them: a time control is two selects.
 */
function rm_expect( $fields, $values ) {
    $visible = array(); $required = array(); $aria = array(); $legend = array();
    foreach ( $fields as $key => $entry ) {
        if ( ! SFAF_Submissions::required_applies( $entry, $values ) ) { continue; }
        $visible[] = $key;
        $carry = isset( $entry['carry'] ) ? $entry['carry'] : 'required';
        foreach ( $entry['inputs'] as $in ) {
            $names = in_array( $in, array( 'start_time', 'end_time' ), true ) ? array( $in . '_h', $in . '_m' ) : array( $in );
            foreach ( $names as $n ) {
                if ( 'required' === $carry ) { $required[] = $n; }
                if ( 'aria' === $carry ) { $aria[] = $n; }
            }
        }
        if ( 'legend' === $carry ) { $legend[] = $key; }
    }
    sort( $visible ); sort( $required ); sort( $aria ); sort( $legend );
    return array( 'visible' => $visible, 'required' => array_values( array_unique( $required ) ),
                  'aria' => array_values( array_unique( $aria ) ), 'legend' => $legend,
                  'inputs' => rm_inputs_of( $fields ) );
}

/** Every key's inputs as the page names them, so the probe can find a mark's field. */
function rm_inputs_of( $fields ) {
    $out = array();
    foreach ( $fields as $key => $entry ) {
        $out[ $key ] = array();
        foreach ( $entry['inputs'] as $in ) {
            if ( in_array( $in, array( 'start_time', 'end_time' ), true ) ) { $out[ $key ][] = $in . '_h'; } else { $out[ $key ][] = $in; }
        }
    }
    return $out;
}

$pages = array();

/* The community form, and the states it is driven through. */
$sub_fields = SFAF_Submit::required_fields();
$sub_base   = SFAF_Submit::required_values( array() );
$sub_steps  = array();
$steps_def  = array(
    array( 'label' => 'as it opens',                  'act' => array(),                                             'vals' => array() ),
    array( 'label' => 'cost is Something else',       'act' => array( array( 'cost', 'other' ) ),                   'vals' => array( 'cost' => 'other' ) ),
    array( 'label' => 'cost is Free again',           'act' => array( array( 'cost', 'free' ) ),                    'vals' => array( 'cost' => 'free' ) ),
    array( 'label' => 'age is Something else',        'act' => array( array( 'age_restriction', 'other' ) ),        'vals' => array( 'cost' => 'free', 'age_restriction' => 'other' ) ),
    array( 'label' => 'age is back on a real answer', 'act' => array( array( 'age_restriction', 'all' ) ),          'vals' => array( 'cost' => 'free', 'age_restriction' => 'all' ) ),
    array( 'label' => 'use my details is ticked',     'act' => array( array( 'contact_same', true ) ),              'vals' => array( 'cost' => 'free', 'age_restriction' => 'all', 'contact_same' => '1' ) ),
    array( 'label' => 'use my details is unticked',   'act' => array( array( 'contact_same', false ) ),             'vals' => array( 'cost' => 'free', 'age_restriction' => 'all' ) ),
    array( 'label' => 'a venue is chosen',            'act' => array( array( 'venue', '11' ) ),                     'vals' => array( 'cost' => 'free', 'age_restriction' => 'all', 'venue' => '11' ) ),
    array( 'label' => 'back to entering it by hand',  'act' => array( array( 'venue', '0' ) ),                      'vals' => array( 'cost' => 'free', 'age_restriction' => 'all', 'venue' => '' ) ),
);
/* The age keys the form actually offers, so the test drives real options. */
$age_keys = array_keys( SFAF_Submit::age_options() );
$age_real = null;
foreach ( $age_keys as $k ) { if ( 'other' !== $k && '' !== (string) $k ) { $age_real = (string) $k; break; } }
$cost_keys = array_keys( SFAF_Submit::cost_options() );
rm_check( in_array( 'other', $cost_keys, true ) && in_array( 'free', $cost_keys, true ), 'the cost list no longer offers free and other, so this test drives options that do not exist' );
rm_check( null !== $age_real && in_array( 'other', $age_keys, true ), 'the age list has no real answer and no other' );
foreach ( $steps_def as $s ) {
    foreach ( $s['act'] as $i => $a ) { if ( 'age_restriction' === $a[0] && 'all' === $a[1] ) { $s['act'][ $i ][1] = $age_real; } }
    if ( isset( $s['vals']['age_restriction'] ) && 'all' === $s['vals']['age_restriction'] ) { $s['vals']['age_restriction'] = $age_real; }
    $sub_steps[] = array( 'label' => $s['label'], 'act' => $s['act'], 'expect' => rm_expect( $sub_fields, array_merge( $sub_base, $s['vals'] ) ) );
}
$pages['submit'] = array(
    'html'  => rm_capture( function () { kit_call( 'SFAF_Submit', 'render_form', null, array( (object) array( 'slug' => 'cycle-to-zero', 'name' => 'Cycle to Zero', 'term_id' => 11 ), array(), array() ) ); } ),
    'note'  => 'Fields marked * are required.',
    'steps' => $sub_steps,
);

/* The request form, with organizers to tick and without. */
$GLOBALS['kit_organizers'] = true;
$req_fields = SFAF_Request::required_fields();
rm_check( isset( $req_fields['organizer'] ), 'with organizers on the site, the request form does not require one' );
$pages['request'] = array(
    'html'  => rm_capture( function () { kit_call( 'SFAF_Request', 'render_form', null, array( 'tok', 'someone@sfaf.org', array(), array() ) ); } ),
    'note'  => 'Fields marked * are required.',
    'steps' => array( array( 'label' => 'as it opens', 'act' => array(), 'expect' => rm_expect( $req_fields, SFAF_Request::required_values( array() ) ) ) ),
);
$GLOBALS['kit_organizers'] = false;
$req_none = SFAF_Request::required_fields();
rm_check( ! isset( $req_none['organizer'] ), 'with no organizers on the site, the request form still requires one' );
$pages['request-no-organizers'] = array(
    'html'  => rm_capture( function () { kit_call( 'SFAF_Request', 'render_form', null, array( 'tok', 'someone@sfaf.org', array(), array() ) ); } ),
    'note'  => 'Fields marked * are required.',
    'steps' => array( array( 'label' => 'as it opens', 'act' => array(), 'expect' => rm_expect( $req_none, SFAF_Request::required_values( array() ) ) ) ),
);
$GLOBALS['kit_organizers'] = true;

/* The editor. Its marks carry no key, so they are found by the label they sit in. */
$portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();
$labels = array(
    'title' => 'Title', 'date' => 'Date', 'start_time' => 'Start', 'end_time' => 'End',
    'organizer' => 'Organizer', 'category' => 'Categories', 'description' => 'Description', 'location' => 'Location',
);
rm_check( array_keys( $labels ) === array_keys( SFAF_Sources::publish_fields() ) || count( array_diff( array_keys( SFAF_Sources::publish_fields() ), array_keys( $labels ) ) ) === 0,
    'publish_fields() names a field this test does not know the label of' );
$want_labels = array();
foreach ( array_keys( SFAF_Sources::publish_fields() ) as $k ) { $want_labels[] = isset( $labels[ $k ] ) ? $labels[ $k ] : $k; }
sort( $want_labels );
$pages['editor'] = array(
    'html'   => rm_capture( function () use ( $portal ) { kit_call( 'SFAF_Portal', 'render_event_form', $portal, array( new WP_User(), 0 ) ); } ),
    'note'   => 'Fields marked * are required to publish.',
    'editor' => $want_labels,
    'steps'  => array( array( 'label' => 'a new event', 'act' => array(), 'expect' => null ) ),
);

/* -------------------------------------------------------------- the probe */
$probe = <<<'JS'
(function () {
var DATA = window.RM_DATA, out = { steps: [], note: '', errors: [] };
window.addEventListener('error', function (e) { out.errors.push(String(e.message)); });
function form() {
  var fs = document.querySelectorAll('form');
  for (var i = 0; i < fs.length; i++) { if (fs[i].querySelector('.uc-req, .uc-required-note')) { return fs[i]; } }
  return fs[0];
}
function shown(el) { return el && !el.hidden && el.getClientRects().length > 0; }
function base(n) { return String(n).replace(/_(h|m)$/, ''); }
function read() {
  var f = form(), r = { visible: [], misplaced: [], required: [], aria: [], legend: [], colour: [], labels: [] };
  f.querySelectorAll('.uc-req').forEach(function (m) {
    if (m.hidden) { return; }
    var key = m.getAttribute('data-uc-req-mark');
    if (key) { r.visible.push(key); } else { r.visible.push('(no key)'); }
    /* On the right label: its own field wrapper holds the field's control. */
    if (key && DATA.inputs && DATA.inputs[key]) {
      var wrap = m.closest('.uc-field, fieldset, label');
      var ok = DATA.inputs[key].some(function (n) { return wrap && wrap.querySelector('[name="' + n + '"]'); });
      if (!ok) { r.misplaced.push(key); }
    }
    var lab = m.parentElement, a = getComputedStyle(m), b = getComputedStyle(lab);
    if (a.color !== b.color || a.fontWeight !== b.fontWeight) { r.colour.push((key || lab.textContent.trim()) + ' ' + a.color + '/' + b.color); }
    r.labels.push(lab.textContent.replace(/\s+/g, ' ').replace('*', '').trim().split(' ')[0]);
  });
  f.querySelectorAll('input, select, textarea').forEach(function (el) {
    if (!el.name || el.type === 'hidden') { return; }
    if (el.required) { r.required.push(el.name); }
    if (el.getAttribute('aria-required') === 'true') { r.aria.push(el.name); }
  });
  f.querySelectorAll('[data-uc-req-said]').forEach(function (s) {
    if (!s.hidden && /required/.test(s.textContent)) { r.legend.push(s.getAttribute('data-uc-req-said')); }
  });
  ['visible', 'required', 'aria', 'legend', 'labels'].forEach(function (k) { r[k] = r[k].filter(function (v, i, a) { return a.indexOf(v) === i; }).sort(); });
  return r;
}
function act(a) {
  var f = form(), el = f.querySelector('[name="' + a[0] + '"]');
  if (!el) { out.errors.push('no control named ' + a[0]); return; }
  if (el.type === 'checkbox') { el.checked = !!a[1]; } else { el.value = a[1]; }
  el.dispatchEvent(new Event('change', { bubbles: true }));
}
window.addEventListener('load', function () { setTimeout(function () {
  var n = document.querySelector('.uc-required-note');
  out.note = n ? n.textContent.trim() : '';
  out.noteShown = shown(n);
  DATA.steps.forEach(function (s) {
    s.act.forEach(act);
    var r = read(); r.label = s.label; out.steps.push(r);
  });
  var pre = document.createElement('pre'); pre.id = 'out';
  pre.textContent = JSON.stringify(out); document.body.appendChild(pre);
}, 400); });
})();
JS;

$written = array();
foreach ( $pages as $name => $page ) {
    $inputs = ( 'submit' === $name ) ? rm_inputs_of( $sub_fields ) : ( ( 'request' === $name ) ? rm_inputs_of( $req_fields ) : ( ( 'request-no-organizers' === $name ) ? rm_inputs_of( $req_none ) : array() ) );
    $data = array( 'steps' => array_map( function ( $s ) { return array( 'label' => $s['label'], 'act' => $s['act'] ); }, $page['steps'] ), 'inputs' => (object) $inputs );
    $inject = '<script>window.RM_DATA = ' . json_encode( $data ) . ';</script><script>' . $probe . '</script>';
    $html = $page['html'];
    $html = ( false !== stripos( $html, '</body>' ) ) ? preg_replace( '#</body>#i', $inject . '</body>', $html, 1 ) : $html . $inject;
    $file = __DIR__ . '/required-marks-live-' . $name . '.html';
    file_put_contents( $file, $html );
    $written[ $name ] = $file;
}
echo 'wrote ' . count( $written ) . " pages\n";

if ( ! in_array( '--run', array_slice( $argv, 1 ), true ) ) {
    exit( 0 );
}

$chrome = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
if ( ! file_exists( $chrome ) ) { echo "Chrome not found at $chrome\n"; exit( 1 ); }

foreach ( $pages as $name => $page ) {
    $url = 'file:///' . str_replace( '\\', '/', $written[ $name ] );
    $dom = shell_exec( '"' . $chrome . '" --headless --disable-gpu --allow-file-access-from-files --window-size=1200,3000 --virtual-time-budget=8000 --dump-dom "' . $url . '" 2>NUL' );
    if ( ! preg_match( '#<pre id="out">(.*?)</pre>#s', (string) $dom, $m ) ) {
        $fails[] = "$name: Chrome returned no probe block";
        continue;
    }
    $got = json_decode( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ), true );
    if ( ! is_array( $got ) ) { $fails[] = "$name: the probe block is not JSON"; continue; }

    rm_check( empty( $got['errors'] ), "$name: the page threw: " . implode( '; ', (array) $got['errors'] ) );
    rm_check( $page['note'] === $got['note'] && ! empty( $got['noteShown'] ), "$name: the line at the top is \"{$got['note']}\", wanted \"{$page['note']}\", shown" );

    foreach ( $page['steps'] as $i => $step ) {
        $r = isset( $got['steps'][ $i ] ) ? $got['steps'][ $i ] : null;
        $at = "$name, {$step['label']}";
        if ( ! $r ) { $fails[] = "$at: no reading"; continue; }
        rm_check( empty( $r['colour'] ), "$at: a mark is not the label's own colour and weight: " . implode( ', ', $r['colour'] ) );

        if ( isset( $page['editor'] ) ) {
            rm_check( $r['labels'] === $page['editor'], "$at: marked labels are [" . implode( ', ', $r['labels'] ) . '], wanted [' . implode( ', ', $page['editor'] ) . ']' );
            rm_check( $r['required'] === array( 'title' ), "$at: the editor carries `required` on [" . implode( ', ', $r['required'] ) . '], and only the title may, because a draft needs only that' );
            continue;
        }

        $e = $step['expect'];
        rm_check( $r['visible'] === $e['visible'], "$at: marked [" . implode( ', ', $r['visible'] ) . '], wanted [' . implode( ', ', $e['visible'] ) . ']' );
        rm_check( empty( $r['misplaced'] ), "$at: a mark sits on the wrong label: " . implode( ', ', $r['misplaced'] ) );
        rm_check( $r['required'] === $e['required'], "$at: `required` on [" . implode( ', ', $r['required'] ) . '], wanted [' . implode( ', ', $e['required'] ) . ']' );
        rm_check( $r['aria'] === $e['aria'], "$at: aria-required on [" . implode( ', ', $r['aria'] ) . '], wanted [' . implode( ', ', $e['aria'] ) . ']' );
        rm_check( $r['legend'] === $e['legend'], "$at: legends saying required [" . implode( ', ', $r['legend'] ) . '], wanted [' . implode( ', ', $e['legend'] ) . ']' );
    }
}

if ( $fails ) {
    echo 'FAIL: ' . count( $fails ) . "\n";
    foreach ( $fails as $f ) { echo '  . ' . $f . "\n"; }
    exit( 1 );
}
echo "community form: every required field marked, nothing optional marked, through " . count( $sub_steps ) . " states\n";
echo "                (cost, age, use my details, venue), each mark and attribute arriving and leaving with its condition\n";
echo "request form:   the same, with organizers (a legend that says it) and without (no organizer asked for)\n";
echo "editor:         the eight publish fields marked, `required` on the title alone, and the line that says so\n";
echo "all four:       the mark is the label's own colour and weight, and every required control announces itself.\n";
exit( 0 );
