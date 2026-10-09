#!/usr/bin/env bash
# Run every check in .claude and report one line each. Non-zero exit if any fail.
cd "$(dirname "$0")/.." || exit 1
fails=0
pass=0

# THE SUITE RUNS WITH CLAUDE CODE OPEN. Every check that starts Chrome, which is
# every php check whose file names chrome.exe, runs through browser-guard.ps1:
# one at a time, inside a job the system will not let past BROWSER_CEILING_MB
# committed, and not started until the ceiling plus BROWSER_RESERVE_MB is free.
# The next check does not start until every process of the last one has ended.
# Measured on 3.110.2: the heaviest check peaks at 472 MB, most near 380, and
# nine of them left a Chrome process running after they returned. 1024 + 512
# could not start on this machine with Edge, Claude Code and Asana open (about
# 1 GB available), so 768 + 256: 1.6 times the heaviest peak, and 1 GB to start.
BROWSER_CEILING_MB=768
BROWSER_RESERVE_MB=256

run() { # label, command...
  local label="$1"; shift
  local out rc
  if [ "$1" = php ] && [ -f "$2" ] && grep -q 'chrome\.exe' "$2"; then
    set -- powershell.exe -NoProfile -ExecutionPolicy Bypass -File .claude/browser-guard.ps1 \
      -CeilingMB "$BROWSER_CEILING_MB" -ReserveMB "$BROWSER_RESERVE_MB" -Command "$*"
  fi
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
    everyaction-hub.php) continue ;;   # the model hub the EveryAction checks load, not a check
    everyaction-live.php) continue ;;  # run below with --run
    version-check.php) continue ;;     # the build gate itself; version-check-test.php runs it
    shrink-check.php) continue ;;      # likewise; shrink-check-test.php runs it
    mail-kit.php) continue ;;          # the miniature WordPress the 3.102.0 mail checks load, not a check
    rsvp-noemail-live.php) continue ;; # run below with --run
    preferences-live.php) continue ;;  # run below with --run
    publish-times-live.php) continue ;; # run below with --run
    series-defaults-live.php) continue ;; # run below with --run
    closure-live.php) continue ;;      # run below with --run
    caps-sweep-live.php) continue ;;   # run below with --run
    release-3106-live.php) continue ;; # run below with --run
    plant-3106.php) continue ;;        # a fault planter for release-3106-live, not a check
    release-31062-live.php) continue ;; # run below with --run
    release-31063-live.php) continue ;; # run below with --run
    release-3107-live.php) continue ;;  # run below with --run
    plant-3107.php) continue ;;        # a fault planter for the 3.107.0 checks, not a check
    plant-31071.php) continue ;;       # a fault planter for the 3.107.1 checks, not a check
    release-3108-live.php) continue ;;  # run below with --run
    plant-3108.php) continue ;;        # a fault planter for the 3.108.0 checks, not a check
    release-31081-live.php) continue ;; # run below with --run
    plant-31081.php) continue ;;       # a fault planter for the 3.108.1 checks, not a check
    release-3109-live.php) continue ;;  # run below with --run
    plant-3109.php) continue ;;        # a fault planter for the 3.109.0 checks, not a check
    release-3110-live.php) continue ;;  # run below with --run
    plant-3110.php) continue ;;        # a fault planter for the 3.110.0 checks, not a check
    release-31101-live.php) continue ;;  # run below with --run
    plant-31101.php) continue ;;       # a fault planter for the 3.110.1 checks, not a check
    release-31102-live.php) continue ;;  # run below with --run
    plant-31102.php) continue ;;       # a fault planter for the 3.110.2 checks, not a check
    login-check.php) continue ;;       # run last, after every capture is rewritten
    byline-live.php) continue ;;       # run below with --run; --live checks the site itself
  esac
  run "$(basename "$f" .php)" php "$f"
done

echo
echo "=== callable audit ==="
run "audit-callables --self-test" php .claude/audit-callables.php --self-test
# The matcher under the build's login check (3.106.2); the glob ran the check.
run "login-check --self-test" php .claude/login-check.php --self-test
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
# EMAILS.md. The glob above ran its --check; this proves the check can fail.
echo "=== the EMAILS.md check ==="
run "emails-md --self-test" php .claude/emails-md.php --self-test

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
echo "=== the EveryAction panel, in a browser ==="
run "everyaction-live --run" php .claude/everyaction-live.php --run

echo
echo "=== the RSVP form without an email, and Preferences, in a browser ==="
run "rsvp-noemail-live --run" php .claude/rsvp-noemail-live.php --run
run "preferences-live --run"  php .claude/preferences-live.php --run
run "series-defaults-live --run" php .claude/series-defaults-live.php --run
run "publish-times-live --run" php .claude/publish-times-live.php --run

echo
echo "=== closures, the warning, Remove, the donate list, in a browser ==="
run "closure-live --run" php .claude/closure-live.php --run
# Every caladmin screen, the public calendar and both public forms, read for
# text in capitals that is not a heading (3.105.0).
run "caps-sweep-live --run" php .claude/caps-sweep-live.php --run
# The Templates screen, the Volunteer button, the waitlist and the RSVP list
# (3.106.0); phone pages run in a 390px frame, since headless will not go under 504.
run "release-3106-live --run" php .claude/release-3106-live.php --run
# No author name or login on an event page (3.106.1): the captured category
# and event pages before and after the strip, in Chrome.
run "byline-live --run" php .claude/byline-live.php --run
# Questions for registrants, the Notifications card on Add event and the cancel
# dialog counting the waitlist (3.106.2), at desktop and 390px.
run "release-31062-live --run" php .claude/release-31062-live.php --run
# The questions card redrawn, and the series removal screen with a waitlist (3.106.3).
run "release-31063-live --run" php .claude/release-31063-live.php --run
# The editor's layout and behaviour pass (3.107.0): card order, Registration,
# the sticky bar, the picker and the weekday following, at 1280px and 390px.
run "release-3107-live --run" php .claude/release-3107-live.php --run
# A venue by default, and the editor's tour (3.108.0), at 1280px and 390px.
run "release-3108-live --run" php .claude/release-3108-live.php --run
# The editors' picture block, folded FAQs, one notice, the emails row (3.108.1).
run "release-31081-live --run" php .claude/release-31081-live.php --run
# Private in the Display card, the password pages and the two logos (3.109.0).
run "release-3109-live --run" php .claude/release-3109-live.php --run
# Who can find this event, the agreement dialog, check-in and the phone pass (3.110.0).
run "release-3110-live --run" php .claude/release-3110-live.php --run
# The RSVP list's order, Team and access, and Register on another site (3.110.1).
run "release-31101-live --run" php .claude/release-31101-live.php --run
# The rest of caladmin on a phone, the users table and Send (3.110.2).
run "release-31102-live --run" php .claude/release-31102-live.php --run

echo
# No real name or login in a tracked file (3.106.2). Last, because the browser
# checks above rewrite the captures it reads.
echo "=== the login check ==="
run "login-check" php .claude/login-check.php

echo
echo "=== shell guards ==="
run "guard-test" bash .claude/guard-test.sh .claude/guard-destructive.sh
run "browser-guard-test" bash .claude/browser-guard-test.sh
run "private-slug-fault-check" bash .claude/private-slug-fault-check.sh

echo
echo "-------------------------------------------"
echo "passed: $pass   failed: $fails"
[ $fails -eq 0 ] || exit 1
