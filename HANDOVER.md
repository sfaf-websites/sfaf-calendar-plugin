# HANDOVER.md, SFAF Calendar

**Where things stand today.** Drag this into a new chat. It answers only "what
is true right now": `PROJECT.md` is what the plugin IS, `DESIGN.md` is color and
layout, `TESTING.md` needs a person, `CLAUDE.md` is the working rules.

**Last updated:** 2026-10-15, at 3.112.2, built on 3.112.1. **The live site is on 3.111.0.**

---

## What shipped last

> **3.112.2 FIXES WHY 3.112.x TOOK sfaf.org/calendar DOWN** (`PROJECT.md` 7): old blocks
> got the Location row, a query per event. 3.112.0/3.112.1 are GitHub pre-releases.
> Install with `TESTING.md` **1.0**. **resources.sfaf.org is LiteSpeed on AWS with
> LiteSpeed Cache; only www.sfaf.org is WP Engine.** The Events Calendar is still on (1.283).
**3.112.1** (in 3.112.2): the embed loader, cancel retires the cache, dialogs, Tags
Archive and Delete, three Age ranges, Asana copies. **3.112.0**: Tags, Location, multi-day.
> **3.110.1: EDITORS NO LONGER HAVE EVERY EVENT.** A team decides which events a
> person has; their level decides what they can do on them (CLAUDE.md 7,
> `PROJECT.md` 5). An editor has what they created and what **Team and
> access** gives them, by team or by name, on the event or its series default.
> Check every editor's My events first: `TESTING.md` **1.225** and **2.44**.
> Notification teams still grant nothing, on Mark's decision.
**3.110.1** also: **Register on another site**; Opt-ins an admin's. **3.110.0**: check-in, **schema 13**.
> **THE WAITLIST NO LONGER OFFERS** (3.110.0): old offers rejoin the queue, `TESTING.md` **2.37**.
> **CAPACITY: an empty box is no limit, 0 is no places** (3.107.0); schema 12
> emptied every stored 0 once: `TESTING.md` **1.200**, `PROJECT.md` 2.
**3.106.x**: questions (schema 11), waitlist, Email Templates, Language (`PROJECT.md` 3).
> **THE SPANISH HAS NOT BEEN READ BY A SPANISH SPEAKER.** `EMAILS.md` is for that
> review (`TESTING.md` 3); nothing is Spanish until an event or series is set to it.
> **A BUILD NEEDS `.claude/fixtures/bylines/people.local.json`**, ignored by git,
> holding the real name, logins and Windows account; without it the build
> refuses. `PROJECT.md` 3.
Earlier: **`readme.txt` is the changelog**. **3.101.0 IMPORTS EVERYACTION**, **Auto-Import off**.

> **AN IMPORTED EVENT NEVER TAKES RSVPS HERE (3.97.0).** The tick is locked off,
> a one-time pass switched it off where it was on, and **it deleted nobody**. A
> hand-made event is untouched whatever links it carries. `PROJECT.md` 3.

> **THE EDITOR'S BUTTON ROW IS OUTSIDE THE EVENT FORM**, so every button in it
> lives or dies by its `form=` attribute and says its `type`; `form-owner-audit.php`
> sweeps both. Green and largest is the main action, yellow the in-between save
> (**Mark's decision**, in `DESIGN.md`). **Delete must never be first in the
> MARKUP**: Enter presses the first submit, and the order on screen is CSS `order`.

**FOUR FOLDERS OF PICTURES, NONE INSIDE ANOTHER**: `calendar/` series pictures,
`calendar-other/` Other images, `calendar-submissions/`, `calendar-descriptions/`;
and from 3.111.0 `calendar-files/` for PDFs, beside them.

> **HYBRID EVENTS ARE SHIPPED, AND THREE THINGS ARE DELIBERATELY NOT IN THEM.**
> A registrant cannot change format after registering, neither public form
> offers hybrid, and there is no per-registrant approval. **These are decisions,
> not gaps**, so do not re-open them as oversights. One capacity PER FORMAT.
> **EVERY PERSON OBJECT REACHING A MAIL BUILDER CARRIES `format` (3.97.3)**, or
> the gates refuse the link to the one person it is for. `PROJECT.md` 2 and 7.

> **EVERY UPLOAD IS 1200 BY 675 AND 500KB** from 3.110.3, the public forms too;
> the editor uploads into Other images. **CHECK THE INSTALLED VERSION BEFORE BUILDING
> ANYTHING REPORTED MISSING**: two 3.94.0 items were already built.

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

**`TESTING.md` holds the manual testing backlog: 345 items.** **1.0** (installing
3.112.2 and its first hour), then **1.277** (old snippets) and **1.281** (the hidden age values) first,
then **1.271** (a multi-day event) and **2.55** (closing a day with events) first, then **1.249** (signed out
with a form open), then **1.260** (that schema 14 ran), **1.255** (no count
anywhere public) and **1.256** (a review team), then **1.235**, **1.245**
and **1.236** (Strut's slug, See all events, retired names), then **1.225**,
that editors have their events, then **1.218**,
that schema 13 ran, then **2.37**, the old offers moved, then **1.200**, that
schema 12 ran and no event became waitlist-only, then **1.197**, that
schema 11 ran, then **1.192**, the byline check on the site, **1.176**, the
donate line, and **1.189**, that schema 10 ran. Then two, in this order:
**1.74**, naming and tagging the six pictures, which is what makes every
picker's work visible at all, and **1.80**, the preview's two targets. Then
**1.171**, which settles whether Add category on the events list was erroring
live. Assume everything else unverified.

**Waiting on a decision or an address**, each with its reasoning in `PROJECT.md`
8 unless another section is named:

- **Two pieces of public copy nobody has read**, the rejection notice and the
  "back on" message. Unticked, so nothing sends. `TESTING.md` 2.21 and 2.24.
- **Español as a category** gives its colour and icon to every event it is on,
  because it sorts first. The rule working.
- **The community form's two email fields.** The age options are settled: All
  ages, 18+ and 21+ (3.112.1).
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
