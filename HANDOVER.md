# HANDOVER.md, SFAF Calendar

**Where things stand today.** Drag this into a new chat. It answers only "what
is true right now": `PROJECT.md` is what the plugin IS, `DESIGN.md` is color and
layout, `TESTING.md` needs a person, `CLAUDE.md` is the working rules.

**Last updated:** 2026-09-18, at 3.98.0, released.

---

## What shipped last

**3.98.0** is nine pieces: the RSVP format question is the calendar's pick-one
control, capacity is one row, an existing event's buttons are Delete / Cancel
event / Save changes, the video box appears only where it can act and its
preview is live, an empty description falls back to the series', all twelve time
controls are an hour list beside a minute list, and the meeting link goes out by
default. **3.97.3** got that link to a hybrid event's online registrant at all,
broken since 3.96.0. **3.96.0 is the big one**: hybrid events end to end, the
event video, pictures inside descriptions.
**`readme.txt` is the changelog** and has the reasoning for all of it.

> **AN IMPORTED EVENT NEVER TAKES RSVPS HERE (3.97.0).** GoFundMe Pro and
> Eventbrite count their own places. The tick is locked and off, both capacity
> boxes are hidden, the event page offers the source's own link instead, and a
> one-time pass switched it off on events that already had it on. **It deleted
> nobody**: registrations already taken are still on the registrations screen.
> **A hand-made event is untouched whatever links it carries.** `PROJECT.md` 3.

> **THE EDITOR'S BUTTON ROW IS OUTSIDE THE EVENT FORM**, so every button in it
> lives or dies by its `form=` attribute; **never add one without it**.
> `.claude/form-owner-audit.php` sweeps all 78. The row is **Delete, Cancel,
> Save draft, Publish (3.97.0)**, yellow on Save draft and green on Publish,
> which inverts DESIGN.md and is **Mark's decision**, recorded there by name.
> **Delete must never be first in the MARKUP**: Enter in a text field presses
> the form's first submit, and the left-to-right order is CSS `order`, which
> moves neither the document nor the keyboard.

**THREE FOLDERS OF PICTURES NOW, AND NONE IS INSIDE ANOTHER**: `calendar/` for
featured pictures, `calendar-submissions/` for what strangers send,
`calendar-descriptions/` for what goes inside prose. `PROJECT.md` 1 has why the
names are siblings and why that is what keeps each out of the others' pickers.

> **HYBRID EVENTS ARE SHIPPED, AND THREE THINGS ARE DELIBERATELY NOT IN THEM.**
> A registrant cannot change format after registering, neither public form
> offers hybrid, and there is no per-registrant approval. **These are decisions,
> not gaps**, so do not re-open them as oversights. Mark's open question
> answered itself: it is one capacity PER FORMAT, because the form has to say
> "online is full, there are still places in person" and one number cannot.
> `PROJECT.md` 2 has the model and the credential rule.
> **A HYBRID EVENT ALWAYS TAKES RSVPS (3.97.2)**: the format question is asked
> on the registration form and nowhere else. **AND EVERY PERSON OBJECT REACHING
> A MAIL BUILDER CARRIES `format` (3.97.3)**, or the gates refuse the link to
> the one person it is for. `PROJECT.md` 7 has that shape.

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
  nudge drive the runner. `PROJECT.md` 4 has the order; the wrong order leaves
  the site with no scheduler.
- **Automated fetching is ON and its own copy says it should not be**, and only
  Mark can say which: it can unpublish a live event unattended, four times an
  hour. `TESTING.md` 2.8.

---

## Outstanding, and only Mark can move it

**`TESTING.md` holds the manual testing backlog: 182 items.** Two want doing
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
- **Existing online and hybrid events with a link delivery tick OFF.** 3.98.0
  defaults both ON for new events and for one switched to either format, and
  changed no existing event. `TESTING.md` 1.157 has the query that counts them.

**Named in a brief and designed nowhere yet**, so this is the only record:

- **The help icon audit.** Nobody has walked the screens.
- **A help section in caladmin**, for an event creator rather than an
  administrator. **The content is the work.**
- **The nine images on disk that are not in the Calendar folder**, and how to
  retrofit them.

---

## Queued work, and before touching anything

**The three standing jobs are in `PROJECT.md` 8**: the caladmin design audit,
simplifying the event editor, and the Tailwind greys in `portal.css`.

**Read `PROJECT.md` 7 first**: four standing hazards, then the lessons.

---

*Update this file in the same commit as the change it describes and keep it
under the 150 line cap; it was 569 lines in 3.93.0. `CLAUDE.md` 8 has the rule.*
