# HANDOVER.md, SFAF Calendar

**Where things stand today.** Drag this into a new chat to bring an assistant up
to speed. It answers only "what is true right now": `PROJECT.md` is what the
plugin IS and why, `DESIGN.md` is color and layout, `TESTING.md` is what needs a
person, and `CLAUDE.md` is the working rules. Anything still true in six months
belongs in one of those instead.

**Last updated:** 2026-09-17, at 3.95.1, released.

---

## What shipped last

**3.95.1** fixed the public list view at the embed width: the date breaks after
the weekday, the chip and the organizer always share a line, and every column in
a row takes one vertical rule. The ticked row in the filter dropdown took a
straight edge, **the calendar and list views are now the same width**, and **a
closed day shows its whole note** on every surface, the day panel included.
**3.95.0** removed the "Use this image" button.

**3.94.0 IS THE RELEASE WITH THE SUBSTANCE IN IT**: the dropdown counts moved
inside the name, pasted fonts are normalised on event pages, the event editor
uses the request form's picture picker instead of the media modal, and its three
bottom actions are one row coloured by consequence. **`readme.txt` is the
changelog** and has the reasoning.

**Three things a new session needs, each retiring or reversing something:**

> **UPLOADING A PICTURE FROM THE EVENT EDITOR IS GONE**, deliberately, and the
> media modal went with it. Pictures are added on the Images screen and chosen
> here. **What went with it is the 3.87.0 tagging that ran on upload from this
> path**: a picture added on the Images screen is not tagged to a series
> automatically. Both public forms keep their own upload and are unaffected.

> **"USE THIS IMAGE" IS GONE (3.95.0), AND THAT IS SETTLED.** It never worked,
> and it deleted any typed image URL on the way past. **Submitted pictures stay
> outside the calendar folder, off the Images screen and out of every picker**,
> because most are one-off events: download, size and add it on the Images
> screen. `PROJECT.md` 8 has the reasoning, and **the rejected option was
> copying them into the folder on use**, so do not propose it again.

> **TWO ITEMS IN THE 3.94.0 BRIEF WERE ALREADY BUILT**: the five minute time
> step, on all twelve controls since 3.72.0, and the dashboard count of events
> with no organizer, in Needs attention since 3.86.0. Both were asked for again.
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
  STACKS there and the list runs its three-column shape. Read anything reported
  about either that way first. Comments naming 770px in two `.claude` files are
  out of date in the number only.

> **THE CALENDAR FOLDER HOLDS SIX PICTURES AND NONE IS TAGGED OR NAMED.** Every
> picker narrows by series, so every series shows "No images are available for
> that series yet". **That is the feature working against an untagged folder, not
> a broken picker**, and from 3.94.0 the event editor shows it too. `TESTING.md`
> 1.74 clears it, and no picker should be judged until it is done.

---

## In flight

- **Following a series is half built.** 3.53.0 established who the followers are;
  nothing sends them. Design in `PROJECT.md` 8, `TESTING.md` 1.22 first.
- **The external cron ping does not exist.** Only visitor traffic and the
  page-view nudge drive the runner. `PROJECT.md` 4 has the order to switch it on
  in; the wrong order leaves the site with no scheduler at all.
- **Automated fetching is ON and its own copy says it should not be.** Either the
  removal it was waiting on has been watched, or the switch is ahead of its
  safeguard, and only Mark can say. A fetch can unpublish a live event
  unattended, four times an hour. `TESTING.md` 2.8.
- **Online events are a first pass (3.62.0).** Per-registrant approval was
  deliberately not half built. `PROJECT.md` 2.

---

## Outstanding, and only Mark can move it

**`TESTING.md` holds the manual testing backlog: 175 items.** Two want doing
first, in this order: **1.74**, naming and tagging the six pictures, which is
what makes every picker's work visible at all, and **1.80**, the preview's two
targets. Assume everything else unverified.

**Waiting on a decision or an address**, each with its reasoning in `PROJECT.md`
8 unless another section is named:

- **The calendar home URL.** Until it is filled in, "All Events" on an event page
  falls back to the archive on resources.sfaf.org, which is not a public surface.
  Settings, Display, Calendar home URL.
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

**Named in a brief and designed nowhere yet**, so this is the only
record of them:

- **The help icon audit.** Which sections carry one and which need one. Nobody
  has walked the screens.
- **A help section in caladmin**, searchable, written for somebody creating an
  event rather than for an administrator. **The content is the work.**
- **Hybrid events, and Mark has given the shape.** One checkbox beside "This is
  an online event" marks the event hybrid. **Registration then asks the
  registrant which they are doing, in person or online, and from there follows
  that format's existing path**: the address goes to in-person registrants, the
  meeting link only to online ones. Nothing new is invented downstream.
  **Open, and Mark's to decide: one capacity for the event, or one per format.**
  Not built.
- **Whether an event with no description falls back to its series description.**
  Nobody knows, and the answer changes how Mark uses both fields.
- **The nine images on disk that are not in the media library's Calendar
  folder**, and the options for retrofitting them.

---

## Queued work, and before touching anything

**The three standing jobs are in `PROJECT.md` 8**: the rest of the caladmin
design audit, simplifying the event editor, and the Tailwind greys still in
`portal.css`. None is about today.

**Read `PROJECT.md` 7 first.** It opens with the four standing hazards and
continues with the lessons that each cost more than one build.

---

*Update this file in the same commit as the change it describes, and keep it
under the 150 line cap. It was 569 lines in 3.93.0 because every release added a
block and none ever left. Anything still true in six months belongs in
`PROJECT.md`; a history belongs in `readme.txt`. `CLAUDE.md` 8 has the rule.*
