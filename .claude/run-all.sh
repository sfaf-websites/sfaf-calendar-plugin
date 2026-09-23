#!/usr/bin/env bash
# Run every check in .claude and report one line each. Non-zero exit if any fail.
cd "$(dirname "$0")/.." || exit 1
fails=0
pass=0

run() { # label, command...
  local label="$1"; shift
  local out rc
  out="$("$@" 2>&1)"; rc=$?
  if [ $rc -eq 0 ]; then
    printf 'PASS  %-32s %s\n' "$label" "$(printf '%s' "$out" | tail -1 | cut -c1-72)"
    pass=$((pass+1))
  else
    printf 'FAIL  %-32s rc=%s\n' "$label" "$rc"
    # HEAD AND TAIL, BECAUSE NEITHER ALONE IS THE FAILURE. This showed only the
    # tail, and the checks here disagree about where they put the bad news: the
    # test harnesses print their problems last, date-callsite-sweep prints its
    # count FIRST and then two long benign listings. So a tail of that sweep is
    # a page of things that are fine, which is how 3.57.0 came to report its own
    # regression as a pre-existing failure in files it had never touched.
    lines=$( printf '%s\n' "$out" | wc -l )
    if [ "$lines" -le 26 ]; then
      printf '%s\n' "$out" | sed 's/^/        /'
    else
      printf '%s\n' "$out" | head -10 | sed 's/^/        /'
      printf '        ... %s more lines ...\n' "$(( lines - 24 ))"
      printf '%s\n' "$out" | tail -14 | sed 's/^/        /'
    fi
    fails=$((fails+1))
  fi
}

echo "=== PHP harnesses ==="
for f in .claude/*.php; do
  case "$(basename "$f")" in
    audit-callables.php) continue ;;   # run separately, with its self-test
    plant-one.php) continue ;;         # a fault planter, not a check
    plant-follow.php) continue ;;      # likewise, for follow-test.php
    plant-filter-submit.php) continue ;; # likewise, and it drives Chrome a dozen times
    # Run below with --run instead. Without it the page is only written and the
    # thing it exists to measure, what a press does in a browser, never happens.
    filter-submit-live.php) continue ;;
    required-marks-live.php) continue ;;
    wp-kit.php) continue ;;            # the miniature WordPress the 3.99.0 checks load, not a check
  esac
  run "$(basename "$f" .php)" php "$f"
done

echo
echo "=== callable audit ==="
run "audit-callables --self-test" php .claude/audit-callables.php --self-test
run "audit-callables ."          php .claude/audit-callables.php .

echo
# The glob above already ran this one against the real sources. This is its
# self-test, which is the half that proves the specificity arithmetic under it,
# and it caught a wrong expectation of mine the first time it ran.
echo "=== cascade arithmetic ==="
run "filter-bar-test --self-test" php .claude/filter-bar-test.php --self-test

echo
# The DOM reader under request-picture-picker-test, for the same reason: the
# picker is decided by parsing what was rendered, so a parser that cannot see a
# control would report its absence rather than its own mistake.
echo "=== the picker's reader ==="
run "picture-picker --self-test" php .claude/request-picture-picker-test.php --self-test

echo
# The icon-link reader and the function slicer under self-built-pages-test.
echo "=== the self-built pages' reader ==="
run "self-built-pages --self-test" php .claude/self-built-pages-test.php --self-test

echo
# The stylesheet reader under section-layout-test, which checks for the ABSENCE
# of a declaration over a file that explains the absence at length in prose.
echo "=== the section reader ==="
run "section-layout --self-test" php .claude/section-layout-test.php --self-test

echo
# The bulk publish rule, which is the only thing on the schedule screen that
# reaches the public calendar and acts on a whole series at once.
echo "=== the bulk publish rule ==="
run "series-publish --self-test" php .claude/series-publish-test.php --self-test

echo
# The modal check, which is the only thing in the build that would notice the
# registration dialog going back under the theme's header. There is no browser
# here, so a <dialog> quietly becoming a <div> passes everything else.
echo "=== the modal reader ==="
run "modal-toplayer --self-test" php .claude/modal-toplayer-test.php --self-test

echo
# THE UPDATER, which is the one subsystem whose failure is invisible on the
# site: a release goes out, nothing offers it, and every static check passes.
# 3.72.0 shipped correctly and was not offered for exactly that reason, so this
# runs the forced check and reads the transients afterwards rather than reading
# the source and believing it.
echo "=== the updater ==="
run "updater --self-test" php .claude/updater-test.php --self-test
# The zone sweep reads every call to the range formatter with the tokenizer;
# its self-test proves it tells mail from a page, including a by-ref function.
run "email-zone --self-test" php .claude/email-zone-test.php --self-test

echo
# JAVASCRIPT SCOPE. portal.js is four top-level IIFEs and a helper declared in
# one is invisible to the others. 3.72.0 did exactly that and killed Approve,
# Reject and Get a form link; node --check proves a file parses and a
# ReferenceError is a runtime fact, so nothing else here could see it.
echo "=== javascript scope ==="
run "js-scope --self-test" node .claude/js-scope-test.js --self-test

echo
# The FAQ answers, at page load rather than after Add FAQ.
echo "=== rich text at load ==="
run "rich-text-start --self-test" node .claude/rich-text-start-test.js --self-test

echo
# "Fill this in from the last one" on the staff form, run rather than read.
# Its caladmin twin sat dead for twenty-six releases because nothing ran it.
echo "=== the request form's prefill ==="
run "request-prefill --self-test" node .claude/request-prefill-test.js --self-test

echo
# The month grid's hover preview: the top layer, desktop only, and no z-index.
echo "=== the hover preview ==="
run "hover-preview --self-test" php .claude/hover-preview-test.php --self-test

echo
# The category palette: every colour can carry its icon, and no two are a coin
# toss in the picker.
echo "=== the palette ==="
run "palette --self-test" php .claude/palette-audit.php --self-test

echo
# Series as image tags, and the guarantee that deleting a series leaves the
# images alone.
echo "=== image tags ==="
run "media-tags --self-test" php .claude/media-tags-test.php --self-test

echo
# An hour list beside a minute list, twelve five-minute entries, and the same
# value out as in. The glob above ran the renderer and the round trip against
# the real sources; this is its self-test, which is the half that proves the
# reader can see an off-grid value at all.
#
# time-step-test.php went with the control it was about in 3.98.0: it asserted
# that every <input type="time"> carried sfaf_time_step_attr(), and with no time
# inputs left it passed on zero controls.
echo "=== time controls ==="
run "time-control --self-test" php .claude/time-control-test.php --self-test

echo
# The event video: what the field accepts, and which of the three possible
# videos an event actually shows.
echo "=== the event video ==="
run "video --self-test" php .claude/video-test.php --self-test

echo
# The two extra pictures a submission may send, and the much longer list of
# things they are not.
echo "=== extra submitted pictures ==="
run "extra-images --self-test" php .claude/extra-images-test.php --self-test

echo
# The third format: three modes, two capacities, and the rule that no message
# carries both the address and the meeting link.
echo "=== hybrid events ==="
run "hybrid --self-test" php .claude/hybrid-test.php --self-test
# The JOIN between the registration row and the gate: the glob above ran this
# against the real sources, and this is its self-test, which is the half that
# proves the reader can tell a person object WITH a format from one without.
# The fault it was built for passed every check that read either end alone.
run "link-delivery --self-test" php .claude/hybrid-link-delivery-test.php --self-test

echo
# The RSVP toggle after the controls moved card: it still decides which of the
# two calendar routes an event has.
echo "=== the RSVP toggle and the calendar file ==="
run "rsvp-toggle-calendar --self-test" php .claude/rsvp-toggle-calendar-test.php --self-test

echo
# Pictures inside a description: three folders that do not overlap, and the
# rule that every picture in a description came through the button.
echo "=== pictures in descriptions ==="
run "desc-images --self-test" php .claude/desc-images-test.php --self-test

echo
# Registrations on a third-party event belong to the source, and the editor's
# button row reads destructive to primary without Delete taking the Enter key.
echo "=== third-party RSVPs, and the button row ==="
run "source-rsvps --self-test" php .claude/source-rsvps-test.php --self-test

echo
# Every submit button resolves to a form on its own page. The 3.94.0 fault was
# a relationship between two elements, which no question asked one element at a
# time could see.
echo "=== form owners ==="
run "form-owner --self-test" php .claude/form-owner-audit.php --self-test

echo
echo "=== PHP lint ==="
run "lint-php" bash .claude/lint-php.sh .

echo
echo "=== JS ==="
for f in .claude/*.js; do
  case "$(basename "$f")" in
    build-email-icons.js) continue ;;  # a generator, not a test
    build-favicon.js) continue ;;      # likewise; its check is --preview
  esac
  run "$(basename "$f")" node "$f"
done
for f in public/js/*.js admin/js/*.js; do
  run "node --check $(basename "$f")" node --check "$f"
done
# The DOM stub and the selector matcher under prefill-image-test.js, proved
# before the assertions above are allowed to rest on them. Same reason
# filter-bar-test carries one: a reader that cannot see the thing it is
# checking reports its absence rather than its own mistake.
run "prefill-image-test --self-test" node .claude/prefill-image-test.js --self-test
# And the DOM stub under repeater-max-test.js, for the same reason: it decides
# whether a button is IN the document, which is the whole assertion there.
run "repeater-max-test --self-test" node .claude/repeater-max-test.js --self-test

echo
# THE ONE THING ONLY A BROWSER CAN ANSWER. A <button> with no type submits the
# form round it, and there is no attribute to grep for, no handler to find and
# no navigation written anywhere: the fault is a DEFAULT. This clicks every
# control on the filter bar in headless Chrome, under each script in turn, and
# asserts that none of them takes the page anywhere.
echo "=== the filter bar, in a browser ==="
run "filter-submit-live --run" php .claude/filter-submit-live.php --run

echo
echo "=== the required marks, in a browser ==="
run "required-marks-live --run" php .claude/required-marks-live.php --run

echo
echo "=== shell guards ==="
run "guard-test" bash .claude/guard-test.sh .claude/guard-destructive.sh
run "private-slug-fault-check" bash .claude/private-slug-fault-check.sh

echo
echo "-------------------------------------------"
echo "passed: $pass   failed: $fails"
[ $fails -eq 0 ] || exit 1
