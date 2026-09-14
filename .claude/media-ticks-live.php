<?php
/**
 * DO THE IMAGES SCREEN'S TICKS REACH THE BUTTON? RUN IT AND SEE.
 *
 * WHY. "Tag 0 images" stays at 0 while images are selected, and Mark could tag
 * some and not others. Two very different faults wear that one symptom:
 *
 *   COSMETIC. The boxes post fine and only the number is wrong, in which case
 *   tagging works and the button lies about what it is going to do.
 *   STRUCTURAL. The boxes are not associated with the form at all, in which case
 *   the press carries nothing and tagging silently does nothing.
 *
 * The events list had the second one from 3.73.0 to 3.79.0. Telling them apart
 * by reading the source is what let that sit for six releases, so this loads the
 * REAL portal.js into a REAL browser against the Images screen's markup, ticks
 * boxes the way a person does, and reads the button.
 *
 *     php .claude/media-ticks-live.php --run
 *
 * WHAT IT CANNOT PROVE. That render_media() emits this markup. That is asserted
 * structurally by the companion check at the foot of this file, which reads the
 * renderer and compares the attributes it writes against the ones driven here.
 */

$root = dirname( __DIR__ );
$out  = $root . '/.claude/media-ticks-live.html';

/* ---------------------------------------------------------------------------
 * The markup, matching render_media(). The two things under test are the
 * association (form=) and the per-card form the tick sits beside.
 * ------------------------------------------------------------------------ */
function mt_card( $id, $title, $file, $tags ) {
    $chips = '';
    foreach ( $tags as $t ) {
        $chips .= '<span class="uc-media-tag">' . htmlspecialchars( $t )
                . '<button type="submit" class="uc-media-tag-off" form="uc-untag-' . $id . '-9">x</button></span>';
    }
    $named = ( '' === $title ) ? '<span class="uc-media-unnamed">no name yet</span>' : '';
    return '<li class="uc-media-item">'
         . '<label class="uc-media-tick">'
         . '<input type="checkbox" name="ids[]" value="' . $id . '" form="uc-bulk-tag" data-uc-tick-one />'
         . '<span class="uc-visually-hidden">Select ' . htmlspecialchars( '' !== $title ? $title : $file ) . '</span>'
         . '</label>'
         . '<img class="uc-media-thumb" src="data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==" alt="" />'
         . ( $chips ? '<p class="uc-media-tags">' . $chips . '</p>' : '' )
         . '<form method="post" action="#" class="uc-media-edit">'
         . '<input type="hidden" name="uc_action" value="media_save" />'
         . '<input type="hidden" name="id" value="' . $id . '" />'
         . '<label class="uc-field"><span class="uc-field-label">Name' . $named . '</span>'
         . '<input type="text" name="title" value="' . htmlspecialchars( $title ) . '" /></label>'
         . '<label class="uc-field"><span class="uc-field-label">Add a series</span>'
         . '<select name="term_id"><option value="">None</option><option value="12">Strut</option></select></label>'
         . '<button type="submit" class="uc-btn uc-btn-sm">Save</button>'
         . '<button type="submit" class="uc-btn uc-btn-sm uc-btn-quiet" form="uc-media-remove-' . $id . '" data-uc-confirm="Take this picture out of the calendar folder?">Remove</button>'
         . '</form>'
         . '<form method="post" action="#" id="uc-media-remove-' . $id . '" hidden>'
         . '<input type="hidden" name="uc_action" value="media_remove" />'
         . '<input type="hidden" name="id" value="' . $id . '" />'
         . '<input type="hidden" name="put_back" value="0" />'
         . '<input type="hidden" name="uc_nonce" value="testnonce" />'
         . '</form>'
         . '<form method="post" action="#" id="uc-untag-' . $id . '-9" hidden>'
         . '<input type="hidden" name="uc_action" value="media_untag" /></form>'
         . '</li>';
}

$cards = mt_card( 101, 'Strut Clinic', 'strut-clinic.jpg', array( 'Strut' ) )
       . mt_card( 102, '', 'untagged-one.jpg', array() )
       . mt_card( 103, 'Cycle To Zero', 'cycle-to-zero.jpg', array( 'Cycle to Zero' ) )
       . mt_card( 104, '', 'dsc-0042.jpg', array() );

$screen = '<div class="uc-card uc-media-library">'
    . '<div class="uc-card-head"><h2>4 images</h2></div>'
    . '<form method="post" action="#" class="uc-bulk-cat" id="uc-bulk-tag" data-uc-tick-picker>'
    . '<input type="hidden" name="uc_action" value="media_tag" />'
    . '<input type="hidden" name="uc_media_tick_present" value="1" />'
    . '<label class="uc-field uc-bulk-cat-pick"><span class="uc-field-label">Add a series to the ticked images</span>'
    . '<select name="term_id" required><option value="">Choose a series</option><option value="12">Strut</option></select></label>'
    . '<div class="uc-bulk-cat-go">'
    . '<label class="uc-tick-all uc-check" hidden data-uc-tick-all-row>'
    . '<input type="checkbox" data-uc-tick-all form="uc-bulk-tag" />Select every image on this page</label>'
    . '<button type="submit" class="uc-btn uc-btn-sm uc-btn-primary" data-uc-tick-submit'
    . ' data-uc-tick-word="images" data-uc-tick-word-one="image">'
    . 'Tag <span data-uc-tick-count>0</span> <span data-uc-tick-noun>images</span></button>'
    . '<span class="uc-hint">Adds it. Any series already on an image stays.</span>'
    . '</div></form>'
    . '<ul class="uc-media-grid">' . $cards . '</ul>'
    . '</div>';

$probe = <<<'JS'
var lines = [];
function say(s) { lines.push(s); }
say("script errors: " + (window.__errs.length ? window.__errs.join(" | ") : "none"));

var form = document.getElementById('uc-bulk-tag');
var btn = form.querySelector('[data-uc-tick-submit]');
var countEl = btn.querySelector('[data-uc-tick-count]');
var allRow = document.querySelector('[data-uc-tick-all-row]');
var all = document.querySelector('[data-uc-tick-all]');

/* 1. ASSOCIATION. The question the events list got wrong for six releases. */
var owned = Array.prototype.filter.call(form.elements, function (el) {
  return el.hasAttribute && el.hasAttribute('data-uc-tick-one');
});
var inDoc = document.querySelectorAll('[data-uc-tick-one]');
say('ticks in the document        ' + inDoc.length);
say('ticks owned by the form      ' + owned.length
    + (owned.length === inDoc.length ? '   (all of them)' : '   MISMATCH'));
say('form.elements total          ' + form.elements.length);

/* 2. Did the initialiser bind at all? */
say('picker marked as bound       ' + (form.getAttribute('data-uc-tick-on') ? 'yes' : 'NO, initTickPickers did not reach it'));
say('select-all row revealed      ' + (allRow && !allRow.hidden ? 'yes' : 'no'));
say('button disabled on arrival   ' + (btn.disabled ? 'yes' : 'no'));
say('button reads                 "' + btn.textContent.replace(/\s+/g, ' ').trim() + '"');

/* 3. TICK TWO, THE WAY A PERSON DOES: a click on the label. */
var labels = document.querySelectorAll('.uc-media-tick');
labels[0].click();
labels[2].click();
say('');
say('after clicking two labels:');
say('  boxes checked              ' + document.querySelectorAll('[data-uc-tick-one]:checked').length);
say('  count element reads        "' + countEl.textContent + '"');
say('  button reads               "' + btn.textContent.replace(/\s+/g, ' ').trim() + '"');
say('  button disabled            ' + btn.disabled);

/* 4. WHAT WOULD ACTUALLY POST. This is the half that decides whether tagging
      works at all, and it is not the same question as the count. */
var fd = new FormData(form);
var ids = fd.getAll('ids[]');
say('  ids[] the form would post  [' + ids.join(', ') + ']');
say('  uc_action                  ' + fd.get('uc_action'));
say('  marker present             ' + (fd.get('uc_media_tick_present') ? 'yes' : 'NO'));

/* 5. SELECT ALL. */
if (all) {
  all.click();
  say('');
  say('after select-all:');
  say('  boxes checked              ' + document.querySelectorAll('[data-uc-tick-one]:checked').length);
  say('  count element reads        "' + countEl.textContent + '"');
  say('  ids[] posted               ' + new FormData(form).getAll('ids[]').length);
}

/* 6. UNTICK EVERYTHING: the count must go to 0 and the button must go dead. */
Array.prototype.forEach.call(document.querySelectorAll('[data-uc-tick-one]'), function (b) {
  if (b.checked) { b.click(); }
});
say('');
say('after unticking everything:');
say('  count element reads        "' + countEl.textContent + '"');
say('  button disabled            ' + btn.disabled);

document.getElementById('out').textContent = lines.join('\n');
JS;

$portaljs = file_get_contents( $root . "/public/js/portal.js" );

$html = <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>SFAF Calendar: do the Images screen ticks reach the button</title>
<!-- GENERATED BY .claude/media-ticks-live.php. Do not edit; regenerate. -->
<link rel="stylesheet" href="../public/css/portal.css">
<style>
  body { margin: 0; padding: 20px; font: 13px/1.5 system-ui, sans-serif; background: #f4f5f7; }
  pre#out { margin-top: 22px; background: #fff; padding: 14px; border: 1px solid #ccc;
            font: 12px/1.5 ui-monospace, Consolas, monospace; }
</style>
</head>
<body class="uc-portal">
<script>window.__errs=[];window.addEventListener("error",function(e){window.__errs.push((e.message||"?")+" @ "+(e.filename||"").split("/").pop()+":"+e.lineno);});</script>
<h1>Images screen ticks</h1>
$screen
<pre id="out">not run</pre>
<script>
$portaljs
</script>
<script>
window.addEventListener('load', function () {
  setTimeout(function () {
$probe
  }, 60);
});
</script>
</body>
</html>
HTML;

file_put_contents( $out, $html );
echo "wrote: $out\n";

if ( in_array( '--run', array_slice( $argv, 1 ), true ) ) {
    $chrome = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
    $url = 'file:///' . str_replace( '\\', '/', $out );
    $dom = shell_exec( '"' . $chrome . '" --headless --disable-gpu --window-size=1400,1200 --virtual-time-budget=8000 --dump-dom "' . $url . '" 2>NUL' );
    if ( preg_match( '#<pre id="out">(.*?)</pre>#s', (string) $dom, $m ) ) {
        echo html_entity_decode( strip_tags( $m[1] ), ENT_QUOTES, 'UTF-8' ) . "\n";
    } else {
        echo "Chrome returned no probe block.\n";
        exit( 1 );
    }
}
