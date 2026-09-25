# HANDOVER.md, SFAF Calendar

**Where things stand today.** Drag this into a new chat. It answers only "what
is true right now": `PROJECT.md` is what the plugin IS, `DESIGN.md` is color and
layout, `TESTING.md` needs a person, `CLAUDE.md` is the working rules.

**Last updated:** 2026-09-25, at 3.104.0, built and not yet released.

---

## What shipped last

**3.104.0**: the Pending bar's three setters are **one panel with one Apply**,
which leaves a blank control alone. `PROJECT.md` 1.
**3.103.0**: a series names **default categories and organizers**, copied once
into an event that joins it with none (`SFAF_Series::join()`, every join site);
**bulk actions on Pending**; and **one publish rule everywhere**: Approve and every bulk publish
hold an incomplete event and name it. The events list's two bulk messages had
never shown, and Add category may have errored on PHP 8. `PROJECT.md` 1 and 2.
**3.102.0**: **Preferences** and the **digest**, the **day-before count**, and
**registering without an email**. `PROJECT.md` 4 and 5.
**3.101.0 IMPORTS EVERYACTION** into Pending, keyed on UUID, **Auto-Import off**;
the description is the manager's. `PROJECT.md` 3.
**3.99.0**: both public forms mark every required field from the validator's
own list; **the editor publishes only a complete event** (a draft needs only a
title, a live event is saved as it is); every email time says "PT".
`PROJECT.md` 1 and 4. **3.98.1** shipped inside 3.99.0. **3.96.0 is the big
one**: hybrid events, the event video, pictures inside descriptions.
**`readme.txt` is the changelog** and has the reasoning for all of it.

> **AN IMPORTED EVENT NEVER TAKES RSVPS HERE (3.97.0).** The tick is locked off,
> a one-time pass switched it off where it was on, and **it deleted nobody**. A
> hand-made event is untouched whatever links it carries. `PROJECT.md` 3.

> **THE EDITOR'S BUTTON ROW IS OUTSIDE THE EVENT FORM**, so every button in it
> lives or dies by its `form=` attribute; **never add one without it**.
> `.claude/form-owner-audit.php` sweeps all 78. **TWO ROWS (3.98.0)**: a draft
> is Delete, Save draft, Publish; an existing event is Delete, Cancel event,
> Save changes. Green and largest marks the main action on both, yellow the
> in-between save, so yellow is on the draft row only. That inverts DESIGN.md
> and is **Mark's decision**, recorded there by name.
> **Delete must never be first in the MARKUP**: Enter in a text field presses
> the form's first submit, and the left-to-right order is CSS `order`, which
> moves neither the document nor the keyboard.
> **AND EVERY BUTTON SAYS ITS TYPE (3.98.1)**: one with none is a submit, which
> is how the category pills reloaded the page for thirteen releases.
> `form-owner-audit.php` asks that too now.

**THREE FOLDERS OF PICTURES, NONE INSIDE ANOTHER**: `calendar/` for featured,
`calendar-submissions/` for strangers, `calendar-descriptions/` for prose.

> **HYBRID EVENTS ARE SHIPPED, AND THREE THINGS ARE DELIBERATELY NOT IN THEM.**
> A registrant cannot change format after registering, neither public form
> offers hybrid, and there is no per-registrant approval. **These are decisions,
> not gaps**, so do not re-open them as oversights. One capacity PER FORMAT.
> **EVERY PERSON OBJECT REACHING A MAIL BUILDER CARRIES `format` (3.97.3)**, or
> the gates refuse the link to the one person it is for. `PROJECT.md` 2 and 7.

**Three things a new session needs, each retiring or reversing something:**

> **UPLOADING A PICTURE FROM THE EVENT EDITOR IS GONE**, with the media modal,
> and so is the 3.87.0 tagging on it. Both public forms keep their own upload.

> **CHECK THE INSTALLED VERSION BEFORE BUILDING ANYTHING REPORTED MISSING.** Two
> items in the 3.94.0 brief were already built, and were asked for again.

---

## What is actually confirmed on the site

- **The whole submission path, end to end**, and registration with both mails.
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
- **Automated fetching is ON and its own copy says it should not be**, and only
  Mark can say which: it can unpublish a live event unattended, four times an
  hour. `TESTING.md` 2.8.

---

## Outstanding, and only Mark can move it

**`TESTING.md` holds the manual testing backlog: 204 items.** Two want doing
first, in this order: **1.74**, naming and tagging the six pictures, which is
what makes every picker's work visible at all, and **1.80**, the preview's two
targets. Then **1.171**, which settles whether Add category on the events list
was erroring live. Assume everything else unverified.

**Waiting on a decision or an address**, each with its reasoning in `PROJECT.md`
8 unless another section is named:

- **The calendar home URL.** Until it is set, "All Events" falls back to the
  resources.sfaf.org archive, not a public surface. Settings, Display.
- **Two pieces of public copy nobody has read**, the rejection notice and the
  "back on" message. Unticked, so nothing sends. `TESTING.md` 2.21 and 2.24.
- **Español as a category** gives its colour and icon to every event it is on,
  because it sorts first. The rule working.
- **The community form's age options and two email fields.** What the public
  forms require is settled: unchanged, now shown (3.99.0).
- **222 published event addresses die with The Events Calendar.** A redirect
  table from the export is cheapest.
- **Four things the calendar publicly asserts that are untrue** (`SFAF_Seo`).
  First: a cancelled event says `EventScheduled`. `PROJECT.md` 3.
- **Existing online and hybrid events with a link delivery tick OFF.** 3.98.0
  defaults both ON for new and newly switched events only. `TESTING.md` 1.157.
- **EVERYACTION: VAL CLEANS UP BEFORE AUTO-IMPORT GOES ON.** The adapter
  de-duplicates nothing: the tracker holds **the 2027 Saturdays twice** under two
  UUID runs, and the list has **the coffee social under two names**. Then do the
  **first fetch by hand with Auto-Import off** (Fetch updates on Pending) and
  look at the queue before anything repeats. `TESTING.md` 2.30.

**Named in a brief and designed nowhere yet**, so this is the only record:

- **The help icon audit.** Nobody has walked the screens.
- **A help section in caladmin**, for an event creator. The content is the work.
- **The nine images on disk that are not in the Calendar folder.**

---

## Queued work, and before touching anything

**Three standing jobs in `PROJECT.md` 8**: the caladmin design audit, the event
editor, the Tailwind greys in `portal.css`.

**Read `PROJECT.md` 7 first**: four standing hazards, then the lessons.

---

*Update this file in the same commit as the change it describes and keep it
under the 150 line cap; it was 569 lines in 3.93.0. `CLAUDE.md` 8 has the rule.*
