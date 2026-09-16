# HANDOVER.md, SFAF Calendar

**Where things stand today.** Drag this into a new chat to bring an assistant up
to speed. This file answers only "what is true right now". `PROJECT.md` is what
the plugin IS and why, `DESIGN.md` is color and layout, `TESTING.md` is what
still needs a person to check, and `CLAUDE.md` is the working rules. Anything
here that is still true in six months belongs in one of those instead.

**Last updated:** 2026-09-16, at 3.94.0, released.

---

## What shipped last

**3.94.0 IS RELEASED.** The filter dropdown's counts moved inside the name and
onto its baseline, pasted fonts are normalised on event pages, the event editor
uses the request form's picture picker instead of the WordPress media modal, the
editor's three bottom actions are one row coloured by consequence, a ticked row
in the dropdown is visible, and "See all events" clears the card's rounded
corner. **`readme.txt` is the changelog** and carries the reasoning for each.

**Three things from it a new session needs, because each retires or reverses
something:**

> **UPLOADING A PICTURE FROM THE EVENT EDITOR IS GONE**, deliberately, and the
> media modal went with it. Pictures are added on the Images screen and chosen
> here. **What went with it is the 3.87.0 tagging that ran on upload from this
> path**: a picture added on the Images screen is not tagged to a series
> automatically. Both public forms keep their own upload and are unaffected.

> **"USE THIS IMAGE" ON THE PENDING ROW IS BROKEN AND WAS NOT FIXED.** It sets
> the thumbnail; the calendar folder rule then refuses it, because the file is in
> `calendar-submissions/` and the rule is anchored on `calendar/`; the event falls
> back to its series picture. The row then says "This is the event's picture."
> It also deletes any typed image URL on the way past. **Reported and awaiting
> Mark's answer on the remedy.** `PROJECT.md` 8, "Agreed, not built".

> **TWO ITEMS IN THAT BRIEF WERE ALREADY BUILT.** The five minute time step has
> been on all twelve time controls since 3.72.0, and the dashboard's count of
> published events with no organizer has been in Needs attention since 3.86.0:
> organizers only, linked, and saves do not refuse. Both were asked for again.
> **Check the installed version before building anything reported missing.**

---

## What is actually confirmed on the site

- **The whole submission path, end to end**: submit, alert, approve with both
  ticks, publish, notice to the submitter, live on the calendar. Registration
  too, both messages.
- **The scheduled path works unassisted.** A morning-of reminder went out at
  6:58am on 2026-08-18. Cron is a reliability question from here, not a
  correctness one.
- **The hover preview works on sfaf.org.** The import has run: **287 drafts
  across 32 series**, confirmed accurate, trash emptied.
- **The block has 700px on sfaf.org**, measured 2026-09-14, so the combined view
  STACKS there. Anything reported about that layout should be read as the stacked
  shape first. Comments naming 770px in `.claude/combined-panel-parity.php` and
  `.claude/embed-modes-test.php` are out of date in the number only.

> **THE CALENDAR FOLDER HOLDS SIX PICTURES AND NONE IS TAGGED OR NAMED.** Every
> picker narrows by series, so every series shows "No images are available for
> that series yet" and no pictures at all. **That is the feature working against
> an untagged folder, not a broken picker**, and from 3.94.0 it is what the event
> editor shows too. `TESTING.md` 1.74 clears it, and no picker should be judged
> until it is done.

> **NO TICK PICKER WORKED IN A BROWSER BEFORE 3.81.0.** `portal.js` threw on
> every page from 3.77.0. Anything asserted about a tick picker before 3.81.0 was
> asserted about markup, not behaviour.

---

## In flight

- **Following a series is half built.** 3.53.0 established who the followers
  are; nothing sends them anything. The design is in `PROJECT.md` 8. `TESTING.md`
  1.22 before part 2 is designed on top of it.
- **The external cron ping does not exist.** Only visitor traffic and the
  page-view nudge drive the runner. `PROJECT.md` 4 has the order it has to be
  switched on in; the wrong order leaves the site with no scheduler at all.
- **Automated fetching is ON and its own copy says it should not be.** Either the
  removal it was waiting on has been watched, or the switch is ahead of its
  safeguard. Only Mark can say. A fetch can unpublish a live event unattended,
  four times an hour. `TESTING.md` 2.8.
- **Online events are a first pass (3.62.0).** Per-registrant approval was
  deliberately not half built. Two things want saying to the team, `PROJECT.md` 2.

---

## Outstanding, and only Mark can move it

**`TESTING.md` holds the manual testing backlog and its count.** Two want doing
first, in this order: **1.74**, naming and tagging the six pictures, which is
what makes every picker's work visible at all, and **1.80**, the preview's two
targets. Assume everything else unverified.

**Waiting on a decision or an address**, each with its reasoning in `PROJECT.md`
8 unless another section is named:

- **The calendar home URL.** Until it is filled in, "All Events" on an event page
  falls back to the archive on resources.sfaf.org, which is not a public surface.
  Settings, Display, Calendar home URL.
- **What "Use this image" should do**, now that it is known to be broken: copy
  the file into the calendar folder on use, or remove the button.
- **Two pieces of public copy nobody has read**: the rejection notice and the
  "this event is back on" message. Both unticked by default, so nothing can send
  while they wait. `TESTING.md` 2.21 and 2.24.
- **Español as a category.** It supplies the colour and icon to every event it is
  on, because it sorts first. That is the rule working. Three answers costed.
- **The community form's age options**, its **two email fields**, and **what
  either public form should require**.
- **222 published event addresses die with The Events Calendar.** A redirect
  table built from the export is the cheapest answer.
- **Four things the calendar publicly asserts that are untrue**, from
  `SFAF_Seo`. Take first: a cancelled event still says `EventScheduled`.
  `PROJECT.md` 3.

**Named in the 3.94.0 brief and designed nowhere yet**, so this is the only
record of them:

- **The help icon audit.** Which sections already carry a help icon and which
  need one. Nobody has walked the screens.
- **A help section in caladmin**, searchable, written for somebody creating an
  event rather than for an administrator. **The content is the work, not the
  screen.**
- **Hybrid events**: attendable in person or online, a separate RSVP for each,
  its own capacity for each, and the meeting link reaching only the online ones.
  That is a change to the registration model, not a display option.
- **Whether an event with no description falls back to its series description.**
  Nobody knows, and the answer changes how Mark uses both fields.
- **The nine images on disk that are not in the media library's Calendar
  folder**, and the options for retrofitting them.

---

## Queued work

**The three standing jobs are in `PROJECT.md` 8**: the rest of the caladmin
design audit, simplifying the event editor, and the Tailwind greys still in
`portal.css`. None is about today.

## Before touching anything

**Read `PROJECT.md` 7.** It opens with the four standing hazards and continues
with the lessons that each cost more than one build.

---

*When the situation changes, update this file in the same commit. A build that
ships moves something out of "in flight"; a decision moves out of "outstanding";
a finished test is a deletion from `TESTING.md` and its count moves with it. If
what you are writing would still be true in six months, it belongs in
`PROJECT.md`. This file was 569 lines in 3.93.0 against a 150 cap, because every
release added a block and none ever left. A history belongs in `readme.txt`.*
