# HANDOVER.md, SFAF Calendar

**Where things stand today.** Drag this into a new chat to bring an assistant up
to speed. This file answers only "what is true right now". `PROJECT.md` is what
the plugin IS and why, `DESIGN.md` is color and layout, `TESTING.md` is what
still needs a person to check, and `CLAUDE.md` is the working rules. Anything
here that is still true in six months belongs in one of those instead.

**Last updated:** 2026-09-11, at 3.74.0, released.

---

## What shipped last

**3.74.0**, built as `sfaf-calendar-3.74.0.zip` in the project root, committed,
**pushed to both repositories** and **released** on 2026-09-11. Working tree
clean. The asset on the release is that zip, 1,279,283 bytes, and its name
matches the pattern `asset_url()` looks for.

> **THE UPDATER LOOP IS CLOSED, AND IT IS THE FIRST THING ON THIS PAGE THAT IS
> FINISHED RATHER THAN WAITING.** Mark pressed **Check for updates** on 3.73.0,
> it offered 3.74.0, and 3.74.0 installed in one click. So the cache WAS the
> cause, the 3.73.0 fix works, and the whole cycle is confirmed end to end:
> build, release, the site offers it, he presses update. No release needs to go
> on by hand again. `PROJECT.md` 7 carries the diagnosis and what it cost.

| | |
|---|---|
| **3.74.0** | **THE FAQ ANSWERS THAT STAYED PLAIN WERE THE ONES A FAQ SET PUT THERE**, and that was a third path nobody had counted: the set picker clones the same template and never asked for an editor, so pressing Add FAQ afterwards swept the whole document and turned every set row into one at once. The console was silent because nothing ever got as far as initialising. Also: **Approve and Reject are on the event editor**, and they are the queue's own two forms, because Publish from there set the status and told the submitter nothing; **the buttons follow the event's state** and there is a **Delete** card under the cancel card; **the search box stopped tearing itself down** (it was navigating, not searching too eagerly); **the community form asks for one set of details**, has a **picture chooser** at last, and says "Enter location manually" and "Capacity"; **caladmin has an Images screen** tagged by series; and the duplicate control names the event it copies. |
| **3.73.0** | **THREE CONTROLS HAD BEEN DEAD SINCE 3.72.0 AND ONE OF THEM WAS APPROVE.** One cause: a helper declared inside one of portal.js's four top-level IIFEs and called from two others, which cannot see into it. Also: the locked FAQ answers read as prose; the events list is three icons on one line; **Cancel** lands on the cancel card; **bulk add a category**; the Display RSVP tick greys while Accept RSVPs is off; **a fifth message, "this event is back on"**; and the duplicate seed is computed once. |
| **3.73.0 (updater)** | **The updater could not be told to look, and nothing ever told it.** `latest()` has taken a `$force` argument since 3.70.0 and no call site had ever passed `true`. There is a **Check for updates** link on the Plugins screen now, and `forget()` clears on install as well as update. `.claude/updater-test.php` runs the thing and plants six regressions. |
| **3.72.0** | **The cancel and remove cluster, and the batch that was waiting.** One action in the cancel dialog, a backdrop that dismisses, Remove refused on a registered event, "Cancelled" on every surface, the Listing detail contact box as three boxes, five-minute time steps, the real repeat question on the staff form, and three submission notifications. |

## What has actually been seen on the site

- **3.73.0 IS INSTALLED, BY HAND.** Confirmed 2026-09-11. **Approve works**: the
  dialog appears, names the submitter and offers two ticks. It had been dead for
  the whole of 3.72.0's life on the site.
- **THE WHOLE SUBMISSION PATH IS CONFIRMED END TO END, for the first time.**
  Submit, alert to the submissions address, approve with both ticks, publish,
  published notice to the submitter, event live on the public calendar.
  **Registration is confirmed too**, both the attendee's confirmation and the
  organizer's alert.
- **The forced update check works.** Mark pressed it and it correctly reported
  the installed version as current.
- **The locked FAQ rows read as prose.**
- **THE IMPORT HAS RUN.** 2026-09-03. The site holds **287 drafts across 32
  series** and Mark has confirmed they look accurate. Trash emptied.
  **`TESTING.md` 2.18 and 2.19 are deliberately still open:** deleting the
  import folder from the server and reading a no-mail notification card are
  separate actions and neither has been reported back on.
- **3.71.0's BULK PUBLISH READS CORRECTLY** on a real series. 3.72.0 put
  per-row ticks on it and those have not been seen.
- **THE UPDATER WORKS, CONFIRMED ON 3.74.0.** Check for updates on the Plugins
  screen offered it and it installed in one click. 3.72.0 and 3.73.0 both had to
  go on by hand; nothing does now. `PROJECT.md` 7.

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

**THE CACHE DIAGNOSIS WAS RIGHT, AND IT IS SETTLED.** It sat unconfirmed for
three releases and doubtful for one, because two releases in a row were cut
correctly and never offered. Pressing the forced check on 3.73.0 offered 3.74.0
and it installed in one click. Nothing here is waiting on it any more.

## Outstanding testing

**`TESTING.md` holds the manual testing backlog.** The count is at the top of
that file and moves with it. Nothing in the build can settle any of them.

**3.74.0 IS ON THE SITE AND MOST OF IT IS STILL UNEXERCISED.** Three want doing
first: **1.62**, because the FAQ set answers are the fault Mark reported and
3.74.0 is the release that claims to fix it; **1.63**, because the editor's
buttons changed on every event and Approve there is a route that sends mail; and
**1.66**, because the Images screen is entirely new and nothing in it has ever
run.

**EVERYTHING IN 3.74.0 IS UNEXERCISED**, and so is most of 3.73.0. What 3.73.0
has confirmed is listed above and is genuinely confirmed; the rest of it, the
icon actions, the bulk category control, the cancel landing and the RSVP tick,
has not been reported back on. **Assume unverified rather than assuming the
reported faults were the only ones.**

## Open decisions

**TWO PIECES OF COPY HAVE NOT BEEN READ BY MARK, AND BOTH GO TO THE PUBLIC.**
The **rejection notice**, which is the only message this calendar sends that
tells somebody no, and the **"this event is back on"** message added in 3.73.0.
Both are built, both are unticked by default, and neither can send without an
explicit yes, so nothing is at risk while they wait. Each is quoted in full:
`TESTING.md` 2.21 for the first, `TESTING.md` 2.24 for the second.

**THE COMMUNITY FORM'S AGE RESTRICTION OPTIONS ARE MARK'S CALL.** Reported and
deliberately not changed, because two of the five were named in isolation and
one of them opens a required field. The five, what each does, and a proposed
replacement set are in `PROJECT.md` 8. It is one array, read by the control and
the validator alike, so the stored values do not move and no event changes.

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

**Four things the calendar publicly asserts that are untrue or incomplete**,
from reading `SFAF_Seo`, listed in full in `PROJECT.md` 3. **The one worth
taking first is that a cancelled event still tells the world `EventScheduled`**,
which 3.72.0 and 3.73.0 have made conspicuous by marking cancellation on every
other surface.

**Three smaller calls, each recorded in `PROJECT.md` with its reasoning.**
Whether saving an imported event should keep it in the queue (one line, and
deliberately not made; use **Save these fields** on the queue meanwhile);
whether ticketed events are worth building when GoFundMe Pro already handles
payment; and whether "open events at their source" should be the default,
which would change where every imported card sends a visitor.

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
