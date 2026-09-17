# HANDOVER.md, SFAF Calendar

**Where things stand today.** Drag this into a new chat. It answers only "what
is true right now": `PROJECT.md` is what the plugin IS, `DESIGN.md` is color and
layout, `TESTING.md` needs a person, `CLAUDE.md` is the working rules.

**Last updated:** 2026-09-17. **Released: 3.95.1. In the tree: 3.96.0, half
built.**

---

## 3.96.0 IS HALF BUILT AND NOT RELEASED. READ THIS FIRST.

**The version is bumped in all three places and the branch is pushed, but
`publish.sh` has NOT been run and no zip exists.** Nothing is on sfaf.org.

```
F  dropdown edge         DONE, measured before and after
C  event video           DONE, 10 plants caught
E  two extra pictures    DONE, 11 plants caught
A  hybrid events         MOSTLY. Model, registration, capacity and messages
                         done, 16 plants caught. NOT done: the display
                         wording, the alert naming the format, the summary
                         grouping by format
B  capacity into Location  PART. Both capacities exist and save; the CARD
                         MOVE is not done, so they still draw from Capacity
D  pictures in descriptions  NOT STARTED
```

**WHAT IS SAFE ABOUT STOPPING HERE.** Nothing half-written is reachable by a
visitor and the hybrid tick works end to end. The credential gates were finished
first and tested hardest, the calendar file included, found open on the way past.

**THREE HYBRID DECISIONS, NOT GAPS**, recorded so they are not re-opened as
oversights: a registrant cannot change format after registering, neither public
form offers hybrid, and there is no per-registrant approval. **And Mark's open
question answered itself: it is one capacity PER FORMAT**, because the form has
to be able to say "online is full, there are still places in person" and one
number cannot say that.
**WHAT TO DO NEXT.** Finish A's three display items and B's card move, build D,
then release. `readme.txt` has no 3.96.0 entry yet: the release is not cut.

---

## What shipped last

**3.95.1**, the last release, fixed the public list view at the embed width and
made the calendar and list views one width. **3.94.0 is the release with the
substance in it**: the dropdown counts, pasted fonts, the editor's picture
picker and its three bottom actions. **`readme.txt` is the changelog** and has
the reasoning for every one of them.

**Three things a new session needs, each retiring or reversing something:**

> **UPLOADING A PICTURE FROM THE EVENT EDITOR IS GONE**, deliberately, with the
> media modal. **So is the 3.87.0 tagging that ran on upload from that path**: a
> picture added on the Images screen is not tagged to a series automatically.
> Both public forms keep their own upload and are unaffected.

> **CHECK THE INSTALLED VERSION BEFORE BUILDING ANYTHING REPORTED MISSING.** Two
> items in the 3.94.0 brief were already built, and were asked for again.

---

## What is actually confirmed on the site

- **The whole submission path, end to end**, and registration with both
  messages.
- **The scheduled path works unassisted**, 2026-08-18. Cron is a reliability
  question from here, not a correctness one.
- **The hover preview works on sfaf.org.** The import has run: **287 drafts
  across 32 series**, confirmed accurate.
- **The block has 700px on sfaf.org**, measured 2026-09-14, so the combined view
  STACKS there and the list runs its three-column shape. Comments naming 770px
  in two `.claude` files are out of date in the number only.

> **THE CALENDAR FOLDER HOLDS SIX PICTURES AND NONE IS TAGGED OR NAMED.** Every
> picker narrows by series, so every series shows "No images are available for
> that series yet". **That is the feature working against an untagged folder, not
> a broken picker**, and from 3.94.0 the event editor shows it too. `TESTING.md`
> 1.74 clears it, and no picker should be judged until it is done.

---

## In flight

- **Following a series is half built.** 3.53.0 established the followers; nothing
  sends them. `PROJECT.md` 8, then `TESTING.md` 1.22.
- **The external cron ping does not exist.** Visitor traffic and the page-view
  nudge drive the runner. `PROJECT.md` 4 has the order to switch it on in; the
  wrong order leaves the site with no scheduler.
- **Automated fetching is ON and its own copy says it should not be**, and only
  Mark can say which. A fetch can unpublish a live event unattended, four times
  an hour. `TESTING.md` 2.8.
- **Online events are a first pass (3.62.0).** Per-registrant approval was
  deliberately not half built. `PROJECT.md` 2.

---

## Outstanding, and only Mark can move it

**`TESTING.md` holds the manual testing backlog: 178 items.** Two want doing
first, in this order: **1.74**, naming and tagging the six pictures, which is
what makes every picker's work visible at all, and **1.80**, the preview's two
targets. Assume everything else unverified.

**Waiting on a decision or an address**, each with its reasoning in `PROJECT.md`
8 unless another section is named:

- **The calendar home URL.** Until it is set, "All Events" falls back to the
  archive on resources.sfaf.org, which is not a public surface. Settings,
  Display.
- **Two pieces of public copy nobody has read**: the rejection notice and the
  "this event is back on" message. Both unticked, so nothing sends while they
  wait. `TESTING.md` 2.21 and 2.24.
- **Español as a category** supplies the colour and icon to every event it is on,
  because it sorts first. The rule working. Three answers costed.
- **The community form's age options, its two email fields**, and what either
  public form should require.
- **222 published event addresses die with The Events Calendar.** A redirect
  table from the export is the cheapest answer.
- **Four things the calendar publicly asserts that are untrue**, from
  `SFAF_Seo`. Take first: a cancelled event still says `EventScheduled`.
  `PROJECT.md` 3.

**Named in a brief and designed nowhere yet**, so this is the only
record of them:

- **The help icon audit.** Nobody has walked the screens.
- **A help section in caladmin**, for somebody creating an event rather than an
  administrator. **The content is the work.**
- **Whether an event with no description falls back to its series description.**
  Nobody knows, and the answer changes how Mark uses both fields.
- **The nine images on disk that are not in the Calendar folder**, and how to
  retrofit them.

---

## Queued work, and before touching anything

**The three standing jobs are in `PROJECT.md` 8**: the caladmin design audit,
simplifying the event editor, and the Tailwind greys in `portal.css`.

**Read `PROJECT.md` 7 first**: four standing hazards, then the lessons that each
cost more than one build.

---

*Update this file in the same commit as the change it describes and keep it
under the 150 line cap; it was 569 lines in 3.93.0. `CLAUDE.md` 8 has the rule.*
