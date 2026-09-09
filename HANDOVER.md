# HANDOVER.md, SFAF Calendar

**Where things stand today.** Drag this into a new chat to bring an assistant up
to speed. This file answers only "what is true right now". `PROJECT.md` is what
the plugin IS and why, `DESIGN.md` is color and layout, `TESTING.md` is what
still needs a person to check, and `CLAUDE.md` is the working rules. Anything
here that is still true in six months belongs in one of those instead.

**Last updated:** 2026-09-09, at 3.72.0.

> **THIS FILE SAT THREE RELEASES STALE**, describing 3.69.0 while 3.70.0, 3.70.1
> and 3.71.0 had shipped. None of those three commits touched it or `TESTING.md`,
> which is the `CLAUDE.md` 8 rule missed three times running. If you are reading
> a hand-off that does not name the version in `sfaf-calendar.php`, trust the
> code.

---

## What shipped last

**3.72.0**, built as `sfaf-calendar-3.72.0.zip` in the project root, committed
and **pushed to `origin/production-2.0`**. Working tree clean.

| | |
|---|---|
| **3.72.0** | **The cancel and remove cluster, and the batch that was waiting.** The cancel dialog offered two buttons reading "Cancel the event" and "Cancel the event"; there is one action now, and clicking outside a caladmin dialog dismisses it, which was the thing actually missing rather than Escape. **Remove on the events list promised a permanence the code never had** and is refused on a registered event, so the row says so and offers Cancel instead. **A cancelled event now says "Cancelled" on the list card, the month grid and the sidebar**, not just its own page. A second message box in the confirmation, for registrants only. **Remove from the calendar** beside Put it back on, which sends nothing. Tick boxes on the bulk publish. The empty FAQ set card is gone. **The Listing detail contact box wrote a key nothing read**, and is three boxes now. Five-minute time steps. **The staff form asks the real repeat question**, captured and not armed. The series is asked first on every screen. "Use this image" on the pending row, and uploads under 1200 wide refused. Three submission notifications, one of them new. |
| **3.71.0** | **A series' upcoming drafts publish in one press.** The count and the date range are on the button, four kinds of row are never touched, and the set is re-decided at the press rather than taken from a hidden field. 3.72.0 added the per-row ticks. |
| **3.70.1** | **The registration dialog opened under the site header, and it was one cause with two symptoms.** Both modals are real `<dialog>` elements in the top layer now, immune to whatever the theme declares. |
| **3.70.0** | **The plugin updates from inside WordPress**, from GitHub releases on a public repository, no token on the site. `build-zip.sh` refuses to build unless the three version strings agree. Also took GoFundMe Pro's 2.2 MB spec and two personal identifiers out of the tracked tree. |

## Three things that are done, and were recorded as pending

- **THE IMPORT HAS RUN.** 2026-09-03, out of order and twice, followed by two
  partial clears. The site holds **287 drafts across 32 series** and Mark has
  confirmed they look accurate. Trash emptied. Nothing pending.
  **`TESTING.md` 2.18 and 2.19 are deliberately still there:** deleting the
  import folder from the server and reading a no-mail notification card are
  separate actions and neither has been reported back on.
- **THE UPDATER HAS COMPLETED A REAL CYCLE.** 3.70.1 and 3.71.0 were released,
  the Plugins screen offered 3.71.0, Mark pressed update. **resources.sfaf.org
  runs 3.71.0** as of this writing. The empty 3.70.0 release is deleted.
- **3.71.0's BULK PUBLISH HAS BEEN SEEN** on a real series and reads correctly.

**The scheduled path works end to end.** A morning-of reminder went out
unassisted at 6:58am on 2026-08-18. Cron is a reliability question from here.

## In flight

**FOLLOWING A SERIES IS HALF BUILT AND PART 2 IS NOT STARTED.** 3.53.0
established who the followers are; **nothing sends them anything**. Somebody can
follow today and hear nothing, which is the expected state, and the whole of the
design is in `PROJECT.md` 8. Its one dependency was met in 3.64.1;
`TESTING.md` 1.22 should be done before part 2 is designed on top of it.

**THE EXTERNAL CRON PING DOES NOT EXIST YET.** Until it does, the only things
driving the runner are visitor traffic and the page-view nudge from sfaf.org.
`PROJECT.md` 4 has the order it has to be switched on in; getting that order
wrong leaves the site with no scheduler at all.

**AUTOMATED FETCHING IS ON, AND ITS OWN COPY STILL SAYS IT SHOULD NOT BE.** The
toggle under **Settings > Scheduled Tasks** reads "Leave this off for now ...
switch it on by hand once one removal has been seen go through correctly", and
`TESTING.md` 2.8 says the same. **Either that removal has been watched and both
should be updated, or the switch is ahead of its safeguard.** Only Mark can say
which. The risk is real: a fetch can unpublish a live event when its source stops
returning it, unattended, four times an hour.

**TWO THINGS ABOUT THE QUEUES WERE NEVER REPORTED BACK ON.** What 3.58.0 refuses
has never been seen against the real campaign list: press **Fetch updates** once
and read the report (`TESTING.md` 2.13). And 3.59.0 predicted Pending would go
from 6 rows to 3 and Dismissed would stop showing SFAF Board Impact. **If a row
with a past start date is still there the diagnosis was wrong**, and the cause is
something other than the end date.

**3.62.0 IS A FIRST PASS AT ONLINE EVENTS.** Everybody who registers gets the
meeting link if the manager ticked the message carrying it; per-registrant
approval was deliberately not half built. **Two things about it want saying to
the team**, both in `PROJECT.md` 2 under "Online events, and a meeting link that
is a credential".

**Events cancelled by a save.** The 3.36.0 to 3.40.0 bug is fixed and the damage
is not. Each affected event reinstates from its cancel card, but **a second save
silently un-cancelled it, so the cancelled list is not the whole list**;
`_uc_cancelled_at` is the timestamp of the save that did it. The emails cannot
be unsent.

Four smaller things waiting on somebody: the **GFMP campaign image** is unmapped,
so campaigns show the placeholder (run the `[PROBE]` in `class-sfaf-gfmp.php`);
the **Turnstile keys** are not in, and without both there is no widget at all;
the **Cycle to Zero series does not exist** and the community form's address is
that series' slug; and **one test event is live**, post 60379, `pending`, badged
Community submission.

## Outstanding testing

**`TESTING.md` holds the manual testing backlog, 76 items.** Quick 53, needs real
conditions 20, blocked on other people 3. Nothing in the build can settle any of
them. **Fourteen are new in 3.72.0**, and two want doing on the day it installs:
1.46, because the FAQ editors' console message is the only route to diagnosing
that fault, and 2.23, because 3.72.0 changes who receives an existing email.

## Open decisions

**THE REJECTION NOTICE'S WORDING HAS NOT BEEN READ BY MARK.** It is built and
unticked by default, so nothing sends without an explicit choice, but it is the
only message this calendar sends that tells somebody no and it goes to a member
of the public. `TESTING.md` 2.21 and the 3.72.0 hand-off quote it in full.

**222 PUBLISHED EVENT ADDRESSES DIE WITH THE EVENTS CALENDAR.** They are live at
`resources.sfaf.org/event/<slug>/` and stop resolving when TEC is removed; the
import creates events at different slugs and nothing maps one to the other.
Whether that matters depends on what links to them, which the repository cannot
say. The cheapest answer is a redirect table built from the export.

**MARK HAS TO FILL IN THE CALENDAR HOME URL SETTING.** Until he does, "All
Events" on an event page lands on the event archive, which is not a public
surface. Settings, Display, Calendar home URL. `PROJECT.md` 1.

**WHAT THE TWO PUBLIC FORMS REQUIRE IS STILL MARK'S CALL.** The location half is
answered: both forms land in Pending either way and 3.68.0 marks a request that
arrived with no location. The rest of the inventory is in `PROJECT.md` 8. The
constraint is that a field somebody cannot answer means an abandoned form.

**THE COMMUNITY FORM'S TWO EMAIL FIELDS STILL NEED NAMING.** Both do unrelated
jobs. `PROJECT.md` 8 has the table.

**Four things the calendar publicly asserts that are untrue or incomplete**, from
reading `SFAF_Seo`: shared images go out at 1024 rather than 1200 on the
featured-image path, no image dimensions are declared, **a cancelled event's
structured data still says `EventScheduled`**, and the address falls back to San
Francisco and CA. `PROJECT.md` 3. The third of those is worth taking now that
3.72.0 has made cancellation visible everywhere else.

**Should saving an imported event keep it in the queue?** Today **Save Draft**
takes it out of the queue it was being reviewed on. The fix is one line and was
deliberately not made. Workaround: use **Save these fields** on the queue itself.

**Are ticketed events worth building at all?** GFMP already handles payment.
**Should "open events at their source" be the default?** Opt-in per block today,
and switching it changes where every imported card sends a visitor.

## Queued work

**The three standing jobs moved to `PROJECT.md` 8 in 3.72.0**, under "The
three jobs queued behind everything else": the rest of the caladmin design
audit, simplifying the event editor, and the Tailwind greys still in
`portal.css`. None of them is about today, and carrying them here release after
release is what kept this file at twice its cap.

## Before touching anything

**Read `PROJECT.md` 7.** It opens with the four standing hazards and continues
with the lessons that each cost more than one build.

---

*When the situation changes, update this file in the same commit. A build that
ships moves something out of "in flight". A decision moves out of "open". An
answer from Aaron or Val, and a finished test, are both deletions from
`TESTING.md`, and its count at the top of that file moves with them. If what you
are writing would still be true in six months, it belongs in `PROJECT.md`.*
