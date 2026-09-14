# HANDOVER.md, SFAF Calendar

**Where things stand today.** Drag this into a new chat to bring an assistant up
to speed. This file answers only "what is true right now". `PROJECT.md` is what
the plugin IS and why, `DESIGN.md` is color and layout, `TESTING.md` is what
still needs a person to check, and `CLAUDE.md` is the working rules. Anything
here that is still true in six months belongs in one of those instead.

**Last updated:** 2026-09-14, at 3.80.0, built and not released.

---

## What shipped last

**3.80.0**, built as `sfaf-calendar-3.80.0.zip` in the project root, committed
and **pushed to both repositories**. Working tree clean. 3.78.0 was released on
2026-09-14 and is the version sites are being offered.

> **NEITHER 3.79.0 NOR 3.80.0 IS RELEASED, AND THAT IS WAITING ON MARK'S WORD.**
> Releasing is a separate press: `bash .claude/publish.sh --release`, which cuts
> the version currently in the tree. It goes on from the Plugins screen
> afterwards: Check for updates, then Update now.

> **THE HOVER PREVIEW WORKS ON sfaf.org**, confirmed 2026-09-11: it appears, it
> positions itself, it clears the site header and it stays open while the
> pointer moves onto it. The top layer, the positioning and the embed copy are
> settled. **What has never been pressed is what it goes to**: 3.77.0 gave it a
> destination and 3.78.0 made that two targets rather than one.

| | |
|---|---|
| **3.80.0** | **The picker hides by series now**, which it did not: it grouped, so a series with two tagged pictures still showed all eight. A series with nothing tagged gets a sentence naming MarCom rather than the whole folder. **The banner is a live preview** on both public forms, and an upload gets its own thumbnail with a line saying an approver decides. **The month arrows had no border at all**, which the stylesheet appeared to declare and a later rule at equal specificity removed; they are one segmented control at the right now, on the 3.64.0 control standard, 44px on touch. **The sidebar card was not overflowing**: in the combined view it has no box by design, and two lone hairlines read as one that closes early. |
| **3.79.0** | **The bulk category control never had tick boxes**, on any screen, for any viewer. The cell was built into the dashboard's read-only table instead of the events list's, and both tables have carried half a fault since 3.73.0. Also **bulk publish on the events list**, sharing those ticks, asking `SFAF_Series::publish_skip_reason()` rather than restating it, with each button counting only the rows it can reach. |
| **3.78.0** | **The preview is two targets, the picture and the pill**, and not the whole panel: one anchor round everything tinted every line in it with the theme's link teal. Also **the staff form's chosen picture is bigger than the rows it chooses from**, which 3.76.0 inverted; **the Images screen is rebuilt**, three cards across rather than six, one Save per card rather than two, and a disabled primary that stops wearing yellow; and **a name typed on that screen now sticks even when it matches its own file**, which it did not, so the remedy 3.76.0 added did not work for the commonest case. |
| **3.77.0** | **The preview's button went nowhere.** The address was never missing: the tile it describes IS the anchor, and nothing read its href. Also **Fill this in from the last one on the staff request form**, which caladmin has had since 3.64.0: one data source, two appliers, nothing posts, the date never filled in, and proved by RUNNING it rather than by the call being present. |
| **3.76.0** | **The hover preview reaches the embed**, which is the only surface that exists. Also: the Images screen can **name a picture**, which is what "the picker shows file names" actually needed; the chooser's thumbnails are **twice the size**; the community form knows about the **series default picture**; the picture section sits **under the series**; the staff form has an **organizer selector, first**; the community form **derives its organizer from the series**; the **FAQ set shows its questions**; the **icon picker draws the icons**; and the Series and Categories lists **fold**. |
| **3.75.0** | **SIX SHADES JOIN THE PALETTE**, all measured by `.claude/palette-audit.php`; **the icon set goes from sixteen offered to thirty**, because Español and Program Groups were drawing the same one. **A block opens on the month grid**, which it did in none of the four places that decide it. And **four mobile faults**, three reported and one found on the way. Confirmed working by Mark, except the preview. |
| **3.74.0** | **THE FAQ ANSWERS THAT STAYED PLAIN WERE THE ONES A FAQ SET PUT THERE**, a third path nobody had counted. Also **Approve and Reject on the event editor**, the buttons following the event's state, a **Delete** card, the search box no longer tearing itself down, one set of details on the community form, and **caladmin's Images screen**. |
| **3.73.0** | **THREE CONTROLS HAD BEEN DEAD SINCE 3.72.0 AND ONE OF THEM WAS APPROVE**, from a helper declared in one of portal.js's four top-level IIFEs and called from two others. The **updater** work is in this release too, and is confirmed working. |

## What has actually been seen on the site

- **THE HOVER PREVIEW WORKS ON sfaf.org.** It appears, positions itself, clears
  the site header, and stays open while the pointer moves onto it. The top
  layer, the positioning and the embed copy are all settled.
- **3.75.0 AND 3.76.0 ARE INSTALLED, THROUGH THE UPDATER.** The palette, the
  icon set, calendar-as-default and the mobile fixes are all confirmed working.
- **THE WHOLE SUBMISSION PATH IS CONFIRMED END TO END.** Submit, alert to the
  submissions address, approve with both ticks, publish, published notice to the
  submitter, event live on the public calendar. **Registration is confirmed
  too**, both the attendee's confirmation and the organizer's alert.
- **On a phone the calendar shows with the list below it, and tapping a date
  updates that list.**
- **THE IMAGE FOLDER HOLDS SIX PICTURES AND NONE IS TAGGED OR NAMED.** That is
  why the chooser shows one ungrouped list of file names: the filter has nothing
  to filter on and the title rule has nothing to prefer. Both are correct and
  both read as broken. `TESTING.md` 1.74 is Mark naming and tagging the six,
  which is the thing that makes 3.74.0's and 3.76.0's picture work visible.

  > **AND FROM 3.80.0 THIS CHANGES WHAT THE PUBLIC FORMS SHOW, SO READ IT BEFORE
  > REPORTING A FAULT.** The picker HIDES by series now instead of grouping. With
  > none of the six tagged, every series will show **"No images are available for
  > that series yet. Contact MarCom for an event image to be added."** and no
  > pictures at all. That is the feature working against an untagged folder, not
  > a broken picker, and it is the exact state 1.74 clears. **Tag at least one
  > picture to one series before judging 3.80.0's picker**, or the only path the
  > screen can take is the empty one.
- **THE IMPORT HAS RUN.** 2026-09-03. The site holds **287 drafts across 32
  series** and Mark has confirmed they look accurate. Trash emptied.
  **`TESTING.md` 2.18 and 2.19 are deliberately still open:** deleting the
  import folder from the server and reading a no-mail notification card are
  separate actions and neither has been reported back on.
- **3.71.0's BULK PUBLISH READS CORRECTLY** on a real series. 3.72.0 put
  per-row ticks on it and those have not been seen.

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

**Two things that were here and are now in `PROJECT.md` 6**, because neither is
about the current situation and both will still be true in a year: **the events
a save cancelled between 3.36.0 and 3.40.0**, where the damage outlives the fix
because a second save silently un-cancelled one, and **the four things waiting
on somebody** (the GFMP campaign image, the Turnstile keys, the Cycle to Zero
series, and the live test event).

## Outstanding testing

**`TESTING.md` holds the manual testing backlog.** The count is at the top of
that file and moves with it. Nothing in the build can settle any of them.

**TWO WANT DOING FIRST AND THEY ARE IN ORDER**, because the second is what makes
most of the picture work visible at all:

- **1.80**, the preview's two targets and the tint. The panel and its
  positioning are confirmed; what has never been pressed is the picture and the
  pill, which were not links at all until 3.77.0 and were the wrong shape of
  link until 3.78.0.
- **1.74**, Mark naming and tagging the six pictures, and **1.81** with it,
  which is the case that did not work: a picture whose file is already named
  after it, like `cycle-to-zero.jpg` given the name "Cycle To Zero". Until the
  six are named and tagged the chooser correctly shows one ungrouped list of
  file names, which is what has been reported twice as a fault and is the empty
  state of two rules working.

Then **1.82** (the Images screen laid out, which is where the naming happens),
**1.79** (the new prefill control on the staff form, whose caladmin twin sat
dead for twenty-six releases, so it is worth pressing rather than glancing at),
**1.76** (the organizer on that form, which writes a term) and **1.62** (the FAQ
set answers).

**Assume unverified rather than assuming the reported faults were the only
ones.** What 3.73.0 and 3.74.0 have confirmed is listed above and is genuinely
confirmed; the rest of both, the icon actions, the cancel landing and the RSVP
tick, has not been reported back on.

> **AND ONE OF THOSE UNVERIFIED THINGS HAD NEVER WORKED AT ALL.** The bulk
> category control was in that list from 3.73.0 to 3.79.0, and it could not have
> been used by anybody: the ticks were absent. A build that ships a control
> nobody has pressed is a build whose test list is load bearing. `TESTING.md`
> 1.83 is the first press of both bulk actions.

## Open decisions

**TWO PIECES OF COPY HAVE NOT BEEN READ BY MARK, AND BOTH GO TO THE PUBLIC.**
The **rejection notice**, which is the only message this calendar sends that
tells somebody no, and the **"this event is back on"** message added in 3.73.0.
Both are built, both are unticked by default, and neither can send without an
explicit yes, so nothing is at risk while they wait. Each is quoted in full:
`TESTING.md` 2.21 for the first, `TESTING.md` 2.24 for the second.

**ESPAÑOL IS A LANGUAGE AND THE OTHER SIX SAY WHAT AN EVENT IS**, and because
the first category alphabetically supplies the colour and the icon, and Español
sorts before every other category this calendar has, **a Spanish-language
support group is drawn as Español everywhere**: card, placeholder, month tile
and chip. That is the rule working rather than failing. Whether a language
belongs as a category at all is Mark's call, and the three answers with the cost
of each are in `PROJECT.md` 8. Nothing was changed.

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
