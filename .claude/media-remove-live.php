<?php
/**
 * DOES PRESSING REMOVE ON THE IMAGES SCREEN REACH THE HANDLER? (3.82.0)
 *
 * WHY. "Pressing Remove refreshes the page and the image is still there." A
 * refresh means something posted, so the two candidates are that the handler
 * ran and the removal did not take, or that it never ran and a DIFFERENT form
 * posted. Reading the source settles neither: both are correct on the page.
 *
 * The class half is settled by .claude/media-remove-test.php, which drives
 * SFAF_Media::set_removed() and pictures() against a stubbed store. This is the
 * other half: a real browser, the real portal.js, and the real shape of the
 * card, asked which form a press actually submits.
 *
 *     php .claude/media-remove-live.php --run
 *
 * THE SHAPE UNDER TEST IS THE ONE THAT MATTERS. The Remove button sits INSIDE
 * the card's own Save form and carries form="uc-media-remove-N", so it belongs
 * to a form it is not inside. That is legal and it is exactly the arrangement
 * the tick boxes use, but it is also the arrangement that has gone wrong twice
 * on this project, so it is asserted rather than assumed.
 */

$root = dirname( __DIR__ );
$out  = $root . '/.claude/media-remove-live.html';

$js = file_get_contents( $root . '/public/js/portal.js' );

/* The card, cut to the two controls and the form they argue over. */
$markup = <<<'HTML'
<ul class="uc-media-grid"><li class="uc-media-item">
  <form method="post" action="#" class="uc-media-edit">
    <input type="hidden" name="uc_action" value="media_save" />
    <input type="hidden" name="id" value="101" />
    <input type="hidden" name="uc_nonce" value="save-nonce" />
    <div class="uc-media-edit-go">
      <button type="submit" class="uc-btn uc-btn-sm">Save</button>
      <button type="submit" class="uc-btn uc-btn-sm uc-btn-quiet"
              form="uc-media-remove-101"
              data-uc-confirm="Take this picture out of the calendar folder? The file is not deleted.">Remove</button>
    </div>
  </form>
</li></ul>
<form method="post" action="#" id="uc-media-remove-101" hidden>
  <input type="hidden" name="uc_action" value="media_remove" />
  <input type="hidden" name="id" value="101" />
  <input type="hidden" name="put_back" value="0" />
  <input type="hidden" name="uc_nonce" value="remove-nonce" />
</form>
HTML;

$probe = <<<'JS'
window.addEventListener('load', function () {
  setTimeout(function () {
    var L = [];
    function say(s) { L.push(s); }
    /* EVERYTHING IN A try, AND THE CATCH WRITES THE BLOCK OUT.
     *
     * The first version of this file printed "not run" and I read that as the
     * page not loading. It was the probe throwing partway through, which leaves
     * the <pre> holding its placeholder and looks identical. A harness that
     * cannot report its own failure is a harness that reports nothing. */
    try {

    say('script errors: ' + (window.__errs.length ? window.__errs.join(' | ') : 'none'));

    var rm = document.querySelector('[form="uc-media-remove-101"]');
    say('remove button found          ' + (rm ? 'yes' : 'NO'));
    if (!rm) { document.getElementById('out').textContent = L.join('\n'); return; }

    /* THE QUESTION. A submit button inside form A with form="B" belongs to B.
     * If this says the card form, the press saves instead of removing, and the
     * page refreshes with the image untouched, which is the reported symptom
     * exactly. */
    say('  its form owner             ' + (rm.form ? rm.form.id || rm.form.className : 'NONE'));
    say('  sits inside the card form  ' + (rm.closest('form.uc-media-edit') ? 'yes' : 'no'));
    say('  confirm handler bound      ' + (rm.getAttribute('data-uc-confirm-bound') ? 'yes' : 'no'));

    var rf = document.getElementById('uc-media-remove-101');
    var parts = [];
    new FormData(rf).forEach(function (v, k) { parts.push(k + '=' + v); });
    say('  the remove form carries    ' + parts.join(' & '));

    /* Press it. The confirmation intercepts, so what should happen on the FIRST
     * press is a dialog and no submit; the submit comes on the replay. */
    var submitted = [];
    document.addEventListener('submit', function (e) {
      submitted.push(e.target.id || e.target.className);
      e.preventDefault();
    }, true);

    rm.click();
    say('  first press submits        ' + (submitted.length ? submitted.join(', ') : 'nothing yet'));
    var dlg = document.querySelector('dialog[open]') || document.querySelector('.uc-confirm-modal');
    say('  a confirmation appeared    ' + (dlg ? 'yes' : 'no'));

    /* Accept it the way the modal does, which is the documented replay. */
    rm.setAttribute('data-uc-confirmed', '1');
    rm.click();
    say('  after accepting, submits   ' + (submitted.length ? submitted.join(', ') : 'NOTHING'));

    var wrong = submitted.filter(function (s) { return s.indexOf('uc-media-edit') !== -1; });
    say('');
    say(wrong.length
      ? 'FAIL: the press reached the card Save form, so it saves rather than removes.'
      : (submitted.indexOf('uc-media-remove-101') !== -1
          ? 'The press reaches the remove form with uc_action=media_remove.'
          : 'FAIL: the press reached no form at all.'));

    } catch (err) {
      L.push('');
      L.push('THE PROBE THREW: ' + (err && err.message ? err.message : err));
      L.push('Everything above it is still true; everything after it never ran.');
    }
    document.getElementById('out').textContent = L.join('\n');
  }, 80);
});
JS;

$html = "<!doctype html>\n<html lang=\"en\">\n<head>\n<meta charset=\"utf-8\">\n"
    . "<title>SFAF Calendar: does Remove reach its form</title>\n"
    . "<!-- GENERATED BY .claude/media-remove-live.php. Do not edit; regenerate. -->\n"
    . "<link rel=\"stylesheet\" href=\"../public/css/portal.css\">\n"
    . "<style>body{margin:0;padding:20px;font:13px/1.5 system-ui,sans-serif}"
    . "pre#out{margin-top:20px;background:#fff;padding:14px;border:1px solid #ccc;"
    . "font:12px/1.5 ui-monospace,Consolas,monospace}</style>\n</head>\n"
    . "<body class=\"uc-portal\">\n"
    . "<script>window.__errs=[];window.addEventListener('error',function(e){window.__errs.push(e.message);});</script>\n"
    . $markup . "\n<pre id=\"out\">not run</pre>\n"
    . "<script>\n" . $js . "\n</script>\n<script>\n" . $probe . "\n</script>\n</body>\n</html>\n";

file_put_contents( $out, $html );
echo "wrote: $out\n";

if ( in_array( '--run', array_slice( $argv, 1 ), true ) ) {
    $chrome = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
    $url = 'file:///' . str_replace( '\\', '/', $out );
    $dom = shell_exec( '"' . $chrome . '" --headless --disable-gpu --virtual-time-budget=8000 --dump-dom "' . $url . '" 2>NUL' );
    if ( preg_match( '#<pre id="out">(.*?)</pre>#s', (string) $dom, $m ) ) {
        echo html_entity_decode( strip_tags( $m[1] ), ENT_QUOTES, 'UTF-8' ) . "\n";
    } else {
        echo "Chrome returned no probe block.\n";
        exit( 1 );
    }
}
