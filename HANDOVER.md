# HANDOVER.md, SFAF Calendar

**Where things stand today.** Drag this into a new chat to bring an assistant up
to speed. This file answers only "what is true right now". `PROJECT.md` is what
the plugin IS and why, `DESIGN.md` is color and layout, `TESTING.md` is what
still needs a person to check, and `CLAUDE.md` is the working rules. Anything
here that is still true in six months belongs in one of those instead.

**Last updated:** 2026-08-26, at 3.58.0.

---

## What shipped last

**3.58.0**, built as `sfaf-calendar-3.58.0.zip` in the project root, committed
and **pushed to `origin/production-2.0`**. The working tree is clean apart from
one stray PNG that is not part of the plugin.

> **3.53.0 ADDED A TABLE**, `uc_series_followers`, and took `SFAF_DB_VERSION` to
> `6`. 3.54.0 adds nothing, but if the site is still on 3.52.0 this still
> applies. The table is created on load as well as on activation, so overwriting
> the folder is enough; if anything about following throws "table doesn't
> exist", that is the check that did not run.

The last four releases, so a fresh chat knows what is recent:

| | |
|---|---|
| **3.58.0** | Only events are imported: GoFundMe Pro donation pages are refused by campaign type, and anything dateless or already past is refused everywhere. Expired rows leave the Pending and Dismissed queues on their own. Published events untouched. |
| **3.57.0** | The Pending screen says what the automatic fetch just did, per source, names a source that failed, and says when nothing has worked for an hour. Read from the run log the Automation screen already keeps. |
| **3.56.0** | A fifth email: somebody cancelled. The cancel page names which date. FAQ rows read as rows and removing one asks first, from one renderer where there were nine. |
| **3.55.0** | Weglot off every calendar surface and only those. The cancel page gets a stylesheet, sharing one renderer with the follow pages. "Release your place" becomes "cancel your registration". |

**Whether it is installed on resources.sfaf.org is not recorded anywhere in the
repo.** The tell is the Plugins screen: if it does not say 3.58.0, the
deployment is stale or partial, and that has explained a "fix that did not work"
before.

> **INSTALL BEFORE ANYBODY EDITS ANOTHER EVENT.** On every release from 3.36.0
> to 3.40.0, pressing Save in the caladmin event editor cancelled the event and
> emailed everybody registered that it was off, and the edit was discarded.
> Fixed in 3.41.0. See "Events cancelled by a save" below for the ones it hit.

**The scheduled path works end to end.** A morning-of reminder went out
unassisted at 6:58am on 2026-08-18. Cron is a reliability question from here,
not a "does it work" question.

## In flight

**Following a series is half built, and the half that is missing is the point of
it.** 3.53.0 established who the followers are and how somebody becomes one.
**Nothing sends a follower anything yet**, and nothing fires when a date is
added to a series.

**Part 2 is the organizer's announcement screen** and is not built: the list of
events in a series that have been added and not yet announced, an envelope state
per row, send and dismiss, and the email naming what was added.
`SFAF_Follow::active_followers()` is the audience it will read, and it is
uncalled on purpose. The reasoning, including why this is a person pressing a
button rather than a hook on occurrence creation, is in `PROJECT.md` §8.

**Somebody can follow a series today and will never hear anything until part 2
ships.** That is the expected state, not a fault. The confirmation email is real
and the unsubscribe link in it works.

**A DECISION FOR MARK'S TEAM, from 3.56.0.** There is now a fifth email: the
event's notification list is told when somebody cancels a registration, naming
**who cancelled, their email address, and the resulting count**. That is exactly
what the registration alert already tells the same list about the same person,
so it is consistent rather than a new disclosure, **but it is registrant data on
a calendar carrying HIV, substance use and trans health programming and somebody
should say out loud that it is wanted.** It is on by default like the other four
and is switched off per event in the same card. `PROJECT.md` §4 has what it
contains.

**Part D of that build was an investigation, and its answers are in `PROJECT.md`
§3** under "What a shared event link produces": what Open Graph and Twitter tags
an event page emits, what is in the JSON-LD, and at what size the image is
actually served. **Four of the findings are decisions rather than fixes** and are
listed under "Open decisions" below. Nothing was changed.

**Events cancelled by a save.** The bug is fixed; the damage is not. Nothing was
deleted, so each affected event reinstates from its cancel card. Find them two
ways and use both: anything cancelled that nobody meant to cancel, and asking
whoever edits events what they touched since 3.36.0. **A second save silently
un-cancelled it, so the cancelled list is not the whole list**, and
`_uc_cancelled_at` is the timestamp of the save that did it. **The emails cannot
be unsent and there is no route for a correction:** each event's registrations
screen lists who was told, and that has to come from a person.

**Some events may have lost an organizer.** Until 3.40.0 the caladmin editor
showed only the first and its save replaced the rest. If Eric knows of co-hosted
events from before then, open them and check. Nothing to do if organizers were
only ever set in caladmin.

**The external cron ping has not been created yet.** Until it is, the only thing
driving the runner is visitor traffic and the page-view nudge from sfaf.org.
`PROJECT.md` §4 has the order it has to be switched on in; getting that order
wrong leaves the site with no scheduler at all.

**AUTOMATED FETCHING IS ON, AND TWO PLACES STILL SAY IT SHOULD NOT BE.** That
it is on is what 3.57.0 was built for, and the Pending screen now reports it.
But the toggle's own copy under **Settings > Scheduled Tasks** reads "Leave this
off for now ... switch it on by hand once one removal has been seen go through
correctly", and `TESTING.md` 2.8 says the same thing. **Either that removal has
been watched and both should be updated, or the switch is ahead of its
safeguard.** Only Mark can say which, so neither was changed. The risk the
wording is about is real and unchanged: a fetch can unpublish a live event when
its source stops returning it, and now it can do so unattended, four times an
hour.

**WHAT 3.58.0 REFUSES HAS NEVER BEEN SEEN AGAINST THE REAL CAMPAIGN LIST.** The
rules and the safeguard are proved in the suite, but which campaigns GoFundMe
Pro actually returns for this organization is not knowable here. Press **Fetch
updates** once: the report names every refusal and its reason, and a real event
refused as "past" would mean `started_at` on a ticketed campaign is the
ticket-sales opening rather than the event, which is the one thing the
platform's spec does not settle. `TESTING.md` 2.13.

Four smaller things waiting on somebody here:

- **The GFMP campaign image is deliberately unmapped**, so campaigns show the
  branded placeholder. Run the `[PROBE]` in `class-sfaf-gfmp.php` against a real
  campaign, fix it live through `sfaf_gfmp_image_fields`, delete the probe.
- **The Turnstile keys are not in.** Both, or no widget is drawn at all and
  nothing says so except that panel. Before the community form is shared.
- **The Cycle to Zero series does not exist.** The form's address is
  `/?uc_event_submit=<series-slug>` and its banner is that series' image, so it
  needs a picture before anybody gets the link.
- **One test event is live:** post 60379, `pending`, badged Community
  submission. Reject it once it has been seen.

## Outstanding testing

**`TESTING.md` holds the manual testing backlog, 27 items.** Quick 11, needs
real conditions 13, blocked on other people 3. Nothing in the build can settle
any of them.

## Open decisions

**Four things the calendar publicly asserts that are untrue or incomplete.**
Found by reading `SFAF_Seo` during 3.56.0, and deliberately not changed: each is
a decision rather than a defect with an obvious fix. The full detail, including
exactly which tags and fields, is in `PROJECT.md` §3 under "What a shared event
link produces".

- **Shared images go out at 1024 wide, not 1200.** Only on the featured-image
  path, which passes through WordPress's `large` size. That is under Facebook's
  and LinkedIn's 1200 recommendation, and every other image source returns its
  URL untouched, so the width an unfurl gets depends on where the picture came
  from. **This is separate from the 16:9 question** and worth settling first.
- **No image dimensions are declared in the sharing tags.** No `og:image:width`
  or `og:image:height`, so Facebook and LinkedIn fetch the image to work them
  out, which is why a first share sometimes unfurls with no picture.
- **A cancelled event still says it is going ahead.** `eventStatus` is always
  `EventScheduled` in the structured data, including on an event
  `SFAF_Cancellation` has marked cancelled, where schema.org has
  `EventCancelled`.
- **The address falls back to San Francisco and CA**, by splitting the location
  on commas, so an event elsewhere with a one-part location is asserted to be in
  San Francisco. `postalCode` is hardcoded empty.

**Should saving an imported event keep it in the queue? Mark has not made this
call.** Today the left button on an imported event is **Save Draft**, which sets
the status to `draft` and takes the event **out of the pending queue it was
being reviewed on**; the other button publishes it. There is no third option and
no warning. The fix is one line, adding `uc_imported` to `$keep_status`, and it
was deliberately not made in 3.49.1 because it changes which events stay in the
queue after a save. **Workaround until then:** fill an imported event in one
sitting, or use the **Save these fields** panel on the Pending queue itself,
which writes those fields without touching the status. `PROJECT.md` §1.

**Are ticketed events worth building at all?** GFMP already handles payment and
a paid event can be a campaign imported here. Building it would mean money
handling, refunds and PCI questions this plugin has never had.

**Should "open events at their source" be the default?** Opt-in per block today.
Switching it changes where every imported event's card sends a visitor on every
existing embed, which is why it has not simply been done.

## Queued work

1. **The `/caladmin` design audit.** 106 findings against `portal.css`, never
   written down. Enumerate first, split by mechanism.
2. **Simplify the event editor.** A parade of checkboxes, and four more cards
   since 3.35.0. A rendering-order and disclosure problem, not a data-model one.

## Before touching anything

**Read `PROJECT.md` §7.** It opens with the four standing hazards that used to
be listed here (what Approve sends and to whom, no unsaved-work warning anywhere
in caladmin, a cancelled event still being `publish`, and why the confirm and
unsubscribe pages can have no stylesheet enqueued onto them), and continues with
the lessons that each cost more than one build. They moved in 3.54.0 because
none of them is about today.

---

*When the situation changes, update this file in the same commit. A build that
ships moves something out of "in flight". A decision moves out of "open". An
answer from Aaron or Val, and a finished test, are both deletions from
`TESTING.md`, and its count at the top of that file moves with them. If what you
are writing would still be true in six months, it belongs in `PROJECT.md`
instead.*
