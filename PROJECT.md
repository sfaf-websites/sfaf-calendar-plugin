# PROJECT.md, SFAF Calendar

What this software is and how it fits together. The things that stay true
between sessions.

This is **not** a changelog. `readme.txt` is the changelog and is authoritative
for what shipped when. This document does not repeat `DESIGN.md` (color,
typography, layout, CSS failure modes) or `CLAUDE.md` (standing working rules,
the build gate, shell rules). When something here contradicts one of those,
those win in their own remit and this file is wrong and should be fixed.

Current version at last update of this file: **3.32.0**.

---

## 1. Architecture

### Two sites, one plugin

The plugin is installed on **resources.sfaf.org** only. It registers the
`uc_event` post type, the `/caladmin` front-end portal, the public
`[sfaf_calendar]` shortcode, and one REST route that serves the embed.

**sfaf.org runs on a locked platform where nothing can be installed.** No
plugin, no PHP, no theme file. sfaf.org gets the calendar by pasting an embed
snippet that loads `embed.js` from resources and calls the REST route
cross-domain. That single constraint explains most of what follows.

### Why the back link is a referrer with a fallback

Event pages are served from **resources.sfaf.org**, because that is where the
post type lives. But the calendar people actually browse is a page on sfaf.org
carrying a shortcode, or an embed of it on some third site.

So "back to all events" cannot be `get_post_type_archive_link()`: that is a
resources URL, and a visitor who clicked through from sfaf.org would land on a
site they have never seen and that is not a public surface.

The resolution order is **referrer, then a configured URL, then the archive**
(`sfaf_calendar_return_url()`, over `sfaf_calendar_referrer()` and
`sfaf_calendar_home_url()`, in `includes/sfaf-template-functions.php`). The
referrer is the only thing that knows *which* calendar page they came from, and
there can be several. The
configured URL in settings covers a shared link, a search result or a bookmark,
where there is no referrer at all. The archive is a last resort so the link is
never broken, not because it is a good destination.

The plugin does not guess which page holds the shortcode. Searching
`post_content` would find drafts, revisions and the wrong page as readily as the
right one.

The referrer is attacker-supplied input and is validated as such: it must parse,
must be http or https, must be this site's host or the configured calendar
host or a host sharing the last two labels of this site's domain, must not
itself be an event page, and the URL is **rebuilt** from the validated parts
rather than passed through. That is what stops it becoming an open redirect.

### Why no new REST route can be added

`SFAF_Embed::is_embed_request()` compares `$request->get_route()` against the
namespace and route **as an exact string**. Every CORS mechanism in the embed
hangs off that comparison: the preflight answer, the response headers, and the
serve fallback.

A second REST route would therefore match **none** of them. It would work
perfectly in local testing and on resources itself, and be blocked by the
browser the moment sfaf.org asked for it, which is the one place it has to
work.

**So new embed parameters go on the existing route.** This has already been the
answer three times: the sidebar heading, the category selection, and the cron
nudge. The cron nudge is on `admin-ajax` rather than REST for exactly this
reason.

### Why several layouts stack, permanently

The sfaf.org template is locked at roughly **770px** of content width and
cannot be widened. It is not a setting on our side.

Anything whose two-column layout needs more than 770px will stack on sfaf.org
forever. That is a fact to design around, not a bug to fix:

- The combined mode goes side by side at **888px**, so on sfaf.org it is always
  the month grid above the sidebar card.
- The month grid drops its cell entries at 560px of its own column. Side by side
  at 770px would hand it 413px, so side by side would be *worse* than stacking.
  Flex bases are therefore set from the breakpoints they must clear, not chosen
  for looks.

**The rule that came out of this: side by side must never be worse than
stacking.** Check any new flex basis against every container query the item's
own contents use.

Embeds measure **their own column** with `@container`, not the window, because
an embed in a 280px sidebar inside a 1440px window is 280px wide and every
`@media` query about the window is a lie there.

---

## 2. Data model

The reasoning matters more than the shape here. Each of these was arrived at by
a defect, and knowing why is what stops the next person undoing it.

### A series is a taxonomy term, not an event

`uc_series` is a taxonomy. Until 3.0.0 a series was a `uc_event` post that was
simultaneously the template every occurrence was generated from **and** its own
first occurrence.

**A term cannot be an event, and that is the entire argument.** As a post, the
series appeared in the Events list because it *was* an event; deleting it
orphaned every occurrence pointing at it; canceling one week had to be recorded
on the parent as a date because a flag on the occurrence died with the post. The
old code excluded series from event queries in about a dozen places and got it
wrong in some of them. A term has no date, is never returned by a `uc_event`
query, and cannot be found as an event, because it is not one.

A series carries name, description, image and a default FAQ set. **A series with
no events is valid** and is not cleaned up.

In practice there is **one series per repeating event**. The series screen is
where the schedule is edited, and schedule writes are **upcoming-only**.

### The recurrence group is separate from series membership

`_uc_recurrence_group` is a random string marking "these events were generated
together from one pattern".

The two groupings answer different questions and must not be merged:

- **Series** is the umbrella: filtering, browsing, page embeds. It may hold
  *different kinds of event* (an educational session one week, a social the
  next), so it is **never** a target for a bulk edit.
- **Recurrence group** is what "edit all upcoming occurrences" targets, and is
  the only reason the marker exists.

The group ID is a random string rather than a post ID, deliberately: an ID would
imply one event in the group is the important one, which is precisely the
mistake the old parent post was.

**A group is a marker, not a container.** No settings, no screen, no lifecycle.
Remove every event carrying the ID and the group is gone.

Within a group, dates are of two kinds. A date generated by the pattern carries
no marker; a date added by hand carries `_uc_recurrence_extra`. A weekly
Wednesday group may also meet on one Saturday: that Saturday is in the group, a
bulk edit reaches it, a time change applies to it. But a later pattern edit
moving Wednesdays to Tuesdays must **leave it exactly where it is**. Without the
marker the Saturday would be shifted to a Sunday nobody chose.

### Categories are multi-select

An event has zero or more categories. Color and icon are term meta
(`_uc_category_color`, plus a stored icon key) edited in `/caladmin`.

Before 3.15.0 the color had no form field anywhere: only the sample-data
seeder ever wrote it, so every hand-created category was default teal,
permanently. The icon was not stored at all but mapped from five exact category
*names*, so a sixth category could not have an icon and renaming one silently
took its icon away.

**A name map that has to be edited in PHP every time somebody invents a category
is not a feature, it is a list of the categories that existed when it was
written.** Color is a closed palette and every icon key is validated.

### Venues are stored by reference and resolved at display

`uc_venue` is a taxonomy. The address is **term meta on the venue**, read at
display time by `sfaf_event_location()`. An event that names a venue stores the
term and nothing else: no address, no snapshot.

So when a venue moves, or somebody corrects a suite number, every event held
there is correct immediately, including past and already-published ones. The
alternative, copying the address onto each event at save time, is easier to
write and wrong in the way that matters: a corrected address would fix the venue
and none of the forty events pointing at it, and nothing could tell an event
whose address is deliberately different from one whose address is simply out of
date.

**An event has either a venue reference or its own location text, never both.**
Choosing a venue clears the text; choosing "a different location" clears the
term. One source of truth per event, so nothing has to decide which wins. A
one-off in somebody's back garden is not a venue and should not become one.

An **imported** event keeps its text: the platform owns that field and writes it
on every fetch, so the venue picker is not offered there.

### Private events are unlisted links, not access control

One checkbox on the event, default off (`_uc_private`).

**It is an unlisted-link scheme and everybody involved knows a link can be
forwarded.** What it must guarantee is that the event cannot be *found* by
somebody who was not sent the link. That is a claim about *every* route into an
event, not the routes anybody happened to think of. The permitted routes are
enumerated
in the readme and asserted as a **whitelist** in
`.claude/private-events-test.php`, so a query added later cannot quietly surface
a private event.

**Privacy lives on the event and never on the series.** There is deliberately no
series-level setting: two settings that can contradict each other are worse than
one rule, because the moment a series says private and an event says public,
something has to decide, and whatever it decides is wrong for somebody. A wholly
private series is every event in it marked private.

**The URL is the credential, so it has to be unguessable.** Making an event
private replaces its slug with 32 hex characters from `random_bytes()`. The
previous slug is kept so making it public again restores the old address.

**Each occurrence of a private series gets its own token.** Occurrence slugs are
normally `{seed-slug}-{date}`, so one shared token would mean that being sent
one date hands you every other date by editing the URL.

Everything else works normally for anybody holding the link: registration, all
four emails, add-to-calendar, the map, capacity, cancellation. The `.ics` export
needed its own gate.

---

## 3. Integrations

### The import framework

Both platforms import through one framework (`SFAF_Sources`). Imported events
land in a **pending queue**; somebody approves them; a fetch may run again
afterwards.

Each adapter declares two lists, and they are the whole contract:

- **`owned_fields()`**: what the platform writes on every fetch. The editor
  locks exactly these.
- **`manager_fields()`**: what a person owns permanently. `update_event()`
  **refuses** these outright, whatever a payload sends.

The test for `owned_fields()` is not "does the platform have a version of this
field" but **"does `update_event()` write the meta key that editor control
writes"**. That is why GFMP declares `donate_url` and Eventbrite does not:
GFMP's `normalize()` really does send `_uc_gofundme_url`.

**Why a refetch must never overwrite a manager field.** A fetch runs hourly and
unattended. A manager's decision was taken once, by a person, on purpose. If the
fetch can win, a manager who turned something off finds it back on an hour
later, with nobody present to notice. Declaring the field is what makes the
exclusion a rule the framework enforces rather than a coincidence of the current
mapping. That is what it used to be, and it was one well-meaning "fix the gap"
commit away from silently overwriting somebody's copy.

### GoFundMe Pro

Supplies: title, dates and times, location, source URL, donate URL, FAQs, and
campaign aggregates.

Does **not** supply: campaign image, or the About-section copy. GoFundMe Pro
support confirmed both live in their design/theme layer and are not exposed on
the public API. There is no `theme_id` on the campaign object and
`/campaigns/{id}/stories` returns zero. This plugin does not scrape pages.
**If you are here to add an image or description mapping, check with GoFundMe
Pro first.**

Manager-owned: `image`, `description`, `category`, `organizer`,
`fundraising_progress`, `private`.

`fundraising_progress` is the most important entry in that list, because it is
the only one where a fetch could plausibly want to write and must not. Whether
this calendar repeats a campaign's raised-and-goal figures in public is an
editorial decision about a fundraiser's page. The hourly fetch has an opinion
about the numbers and **no standing whatsoever** on whether to publish them.

**The GFMP OpenAPI spec understates what the live API does, and has been wrong
or silent four times:** the token endpoint, the raised amounts, the campaign
image, and the campaign description. Two specifics worth keeping:

- The host. The live account answers on `api.classy.org/2.0/resource` while the
  spec says `pro.gofundme.com/api/2.0`. The spec's value is the default and the
  host is editable in settings.
- `/campaigns/{id}/overview` **is supported**. Support confirmed that its
  absence from the spec is a documentation gap they are fixing. It returns
  `gross_amount`, `total_gross_amount`, `net_amount`, `fees_amount`,
  `percent_to_goal`. There is **no** `raised_amount`, `progress_bar_amount` or
  `total_online_funds_raised`; those are `CampaignAggregates` names from the
  spec, and reading them was the actual cause of the "no raised amounts were
  available" report. The request succeeded every time and the body was then
  searched for keys that were never in it.

There is a read-only `[PROBE]` tool that reports what the account actually
returns, verbatim, mapping nothing. It exists because the spec cannot be
trusted, and it is marked for deletion in one commit once the payloads are
settled.

### Eventbrite

Supplies: title, dates and times, location, source URL, image, description.

Manager-owned: `category`, `organizer`, `private`, and only those.

Category and Organizer are **this calendar's own taxonomies**. No platform
supplies them and none ever can. Eventbrite has an organizer of its own, but it
is Eventbrite's record rather than a term in our Organizers taxonomy, and
guessing a mapping between the two would create duplicate terms nobody asked
for.

### Pardot / Salesforce, pending

Events carry `_uc_pardot_campaigns`, a multi-select of campaign IDs, and there
is a settings panel to define the campaign list. **Nothing talks to Pardot.** It
is storage only, with a hook (`SFAF_Optins`) for a future integration to attach
to.

This is **blocked on Salesforce credentials**, not on code.

---

## 4. Email

### Delivery is not this plugin's problem, and must not become one

The site has the **ActiveCampaign Postmark** plugin active, which overrides
`wp_mail()` and hands the message to Postmark. A WordPress password reset
already arrives cleanly by that path.

So every message here calls `wp_mail()` and stops. No SMTP settings, no
transport, no library, no second delivery mechanism to keep in step with the
first. If delivery ever moves, it moves underneath `class-sfaf-email.php`
without a line changing in it.

**From address:** currently `websites@sfaf.org`, as
`SFAF_Email::DEFAULT_FROM_EMAIL`. `events@calendar.sfaf.org` is **pending DNS**.
It is a *setting with a default* rather than a constant used directly, precisely
so that when the mailbox exists somebody types it into Settings and nothing is
deployed.

Reply-To is per event: the event's address, or the person who created it, or the
site default. The registration alert is the one exception and replies to the
person who just registered, because that is who a staff member pressing reply
means to write to.

**The one caveat that cannot be resolved from here.** Every message is built
twice, HTML and plain text. WordPress has exactly one supported way to attach
the text alternative: set `$phpmailer->AltBody` on `phpmailer_init`. That works
with core's mailer and with any SMTP plugin routing through PHPMailer. It
**cannot** work with a plugin that replaces `wp_mail()` outright and posts to an
HTTP API, because PHPMailer is never constructed and the action never fires.
Postmark is the second kind. The hook is attached anyway because it is correct
wherever PHPMailer is involved, and whether a `text/plain` part actually arrives
is visible in a delivered message and nowhere else.

### The four message types

All four are **on** by default. `_uc_notify_off` records only what somebody has
switched off, so a default costs no writes and an event created before any of
this existed behaves like one created after.

| Kind | To | When |
|---|---|---|
| `confirmation` | the person registering | immediately |
| `alert` | the event's notification list | one per registration, as it happens |
| `reminder` | everybody registered, list copied in | 6am on the day (midnight if the event starts earlier) |
| `summary` | the notification list | two hours before; nothing sent if nobody registered |

### The notification list, and why two messages ask different questions

The list is gated on **nothing**. It holds calendar contributors, people reached
through a team, and typed addresses that are not accounts at all.

So the `alert` and the `summary` are both built **per recipient**, because both
carry a link into a gated screen:

- `alert` links to `/caladmin/rsvps`, gated on `can_view_all`.
- `summary` links to `/caladmin/events/edit/N`, gated on `can_edit_event`, which
  is `can_view_all` **or** being the event's author.

Testing the summary against `can_view_all` would leak nothing and still take the
link away from the contributor whose own event starts in two hours, the person
most likely to want it. Anybody without the capability gets the public event
page instead.

**The capability is asked of the resolved user record, never of the address.** A
team is resolved to people and each person is checked individually, because a
team is a set of names and not a permission. A free-text address is never
offered the link even when it happens to match somebody's account, because it is
a string typed in a box rather than that person.

There are only ever two versions of each message, so a list of thirty people
costs two builds.

### Cancellation tokens

A registration gets a **128-bit token** (`SFAF_Reminders::new_token()`). The
cancel link is `?uc_rsvp_cancel={token}` on the site root. The token is the only
credential, which is what lets cancellation work with no account.

**A GET never cancels anything.** Mail clients, security scanners and link
previewers fetch the URLs in an email without a person ever clicking, so a
one-request cancel would drop people's places for them. The link opens a page
that asks; the POST from that page acts.

### The cron trigger, and why WordPress cron is not enough

There is **one** hourly runner, `SFAF_Cron`, for every unattended job. Currently
that is the reminder pass and the third-party fetch. Anything added later
registers there rather than scheduling its own event, so there is one lock, one
log, and one place to look when something happened overnight.

**WordPress cron is not a scheduler. It is a check that runs when somebody
visits the site.** On a calendar nobody visits at 6am, a 6am job simply does not
happen. This is a low-traffic site, which is exactly the case the pseudo-cron
fails.

Three things drive the runner, and it does not care which:

1. **Real system cron** (cPanel, or an external pinger) hitting `wp-cron.php`,
   with `DISABLE_WP_CRON` set. This is the intended setup.
2. **The embed script.** Any page carrying a calendar, including sfaf.org, which
   has far more traffic than resources, pings `admin-ajax` (`sfaf_cron_ping`),
   throttled to once every 15 minutes. It is on `admin-ajax` and not a REST route
   because of the exact-route-string CORS gate in §1.
3. **"Run now"** in the admin.

The 15-minute ping interval exists for the **pre-event summary**, which is due
two hours before an event starts; an hourly run can be up to an hour late for
it. Every job is idempotent and most passes find nothing due, so a run with no
work costs one `WP_Query`.

**Two independent layers stop a double send.** The run lock is an
`add_option()` claim (`option_name` is UNIQUE, so the second run stands down),
with a 15-minute TTL so a fatal mid-run cannot stop the runner permanently and
silently. Under it, the reminder ledger has a UNIQUE key on
`(event_id, recipient_hash)` and the row is **inserted before the send**, so a
second attempt fails the insert and skips. The second layer needs no cooperation
from the first and holds even with the lock broken.

A send that fails is recorded as failed and **not retried**: `wp_mail()`
returning false does not reliably mean nothing was delivered, and retrying risks
the exact duplicate the design exists to prevent.

**Imported events are excluded from reminders outright.** The platform holds the
registrations and sends its own. The check is on the *presence of a source*, not
on a named platform, so a new adapter is excluded the moment it exists rather
than the day somebody remembers to add it.

---

## 5. Permissions

### Two concepts, deliberately separate

**ACCESS** derives from `manage_options`, asked first, in
`SFAF_Portal::get_role()`. A WordPress administrator has full calendar access
and the stored record **cannot reduce them**. Below that, `_uc_calendar_role`
holds one of `admin` / `editor` / `contributor`; any other value is no access.

This order is a fix. It used to read the meta first and fall back to
`manage_options`, which meant an administrator who added himself on the Users
screen so he could join a team got the form's default level written against his
account and was then held to it. His WordPress role was never touched; the
calendar simply stopped believing it.

**VISIBILITY** comes from *having a calendar user record at all*, which is what
Users and Permissions creates. Only people with a record appear in the
notification picker and the team picker.

So an administrator who has not added themselves has full access and does **not**
appear in pickers, and that is correct: **a picker is a list of the people who
work on this calendar, not a list of everybody who could.** 3.14.0 got this
wrong and "fixed" missing administrators by widening the picker, which quietly
made the two questions one list. The real fault was that nothing recorded which
question the picker asks.

Access is never written as a side effect. No `set_role`, `add_cap` or
`wp_capabilities` write except from a control whose only purpose is that.

### This is a known weak point. Five defects, same family.

Five separate times, attendee data or a destructive action has been found behind
the wrong gate. **Treat any new screen, route, export or email link that touches
registrations as guilty until the gate is named.**

1. **The `/caladmin` registrations screen was ungated** (found 3.5.0). It relied
   on not appearing in the sidebar, which is not a permission. A contributor who
   typed the URL could read every registration on the calendar.
2. **The dashboard leaked registrant names.** Two of its seven elements ignored
   access level: Total RSVPs counted every confirmed registration on the
   calendar, and Recent activity read the whole registration table, which is
   the worse of the two, because it *names people*. A contributor read that a
   named person had registered for an event they cannot open or edit.
3. **`uc_export_rsvps` was gated on `edit_posts`.** It was the download link on
   the WordPress RSVP page, so any Author on the site could fetch the full
   registration list as CSV by calling it directly. Removed with its screen.
4. **`POST /sfaf-calendar/v1/rsvp` had `permission_callback => '__return_true'`**
   was an unauthenticated public write. Nothing had ever called it; it had also
   been broken since 3.26.0. Being broken is the proof nobody called it, not the
   reason it was removed. A public write path that nothing uses is not made safe
   by being broken.
5. **The registration alert and the pre-event summary emailed gated links to an
   ungated list** (3.26.0, 3.26.1). See §4.

The standing consequence: **a permission-sensitive view gets a separate
renderer, never a flag.** A method with no capability to leak cannot leak:
absent columns rather than hidden ones, nothing clickable, widening stated
explicitly.

Two supporting rules that came out of the same family. Attendee data is **not
searchable and cannot become searchable by accident**: `SFAF_Search` whitelists
what to include rather than listing what to skip, so a field added later is
unsearchable until somebody adds it deliberately. And registrations are reached
only from the event they belong to.

**On why this matters more than usual here:** this calendar carries HIV,
substance use and trans health programming. The *size of a group* is itself
worth not saying.

---

## 6. Built but never exercised

Be honest about this in any hand-off. Almost everything in this plugin has been
**statically verified and never executed** in the situation it was written for.

**Never run in production at all:**

- **Reminders have never been sent by the scheduled path.** The send-once
  design, the ledger's unique key, the 6am/midnight timing rule, the failure
  accounting: none of it has ever run unattended against real registrations.
- **The cron trigger has never fired in production.** No confirmed real system
  cron, and the embed ping has never been observed driving a run on the live
  site.
- **No email has been confirmed as delivered.** `class-sfaf-email.php` says so at
  the top of the file. Whether Postmark forwards Reply-To, and whether a
  `text/plain` part arrives at all, are facts about the transport visible only in
  a received message. `send_test()` exists to find out and the answer has not
  been recorded.
- **Neither import adapter has been observed running unattended.** GFMP and
  Eventbrite have been exercised by hand; the hourly fetch path has not.

**Tested only by their author, once, by hand:** most `/caladmin` workflows.
Event creation and editing, the schedule editor, bulk edits across a recurrence
group, the pending queue, teams, venues, categories, private events. There is no
second user, no QA pass, and no automated coverage of the portal.

**Dormant on purpose, not dead:** the satellite REST feed. With no key it
returns 403, and that reversal is what makes clearing the key a safe way to turn
it off.

What *is* actually proven: the PHP linter and the callable audit run on every
build (see `CLAUDE.md` §2), and the committed test scripts in `.claude/`: the
recurrence cross-check, the email render test, the private-events whitelist, the
date sweep, the rendered parity tests, the embed width probe.

---

## 7. The lessons that cost time

These are recorded because each cost more than one build and each recurred.

**A correct rule that never reached the screen. Six times.** A component rule
written as one class, `(0,1,0)`, sitting under a host-proofing base rule written
as class plus element, `(0,1,1)`. One class does not beat one class plus one
type, so the base rule wins every property it declares and the component rule is
dead without looking dead. It has hit the sidebar nav (link teal at **2.30:1**
for two releases, under a measured contrast table that never applied), the
sidebar headings, every pill on the public calendar (`border-radius: 99px`
losing to a reset's `0`), the RSVP consent checkbox, and thumbnails. The two
conventions are each correct and collide silently.

> **Compute the cascade before rewriting.** Then pick a remedy deliberately:
> raise the component to two classes, drop the base to zero with `:where()` when
> it is meant as a floor, or *split* the base rule when its properties want
> different things (a defense stays high, a floor goes low).

**A test fixture that removed the property under test.** The first draft of
`embed-combined-panels-test.js` put `data-view` on the block instead of the
container, so `viewFor()` answered `'list'` for every mode and the test passed
while the mode rendered nothing. The parity test had the same shape from the
other side: with only one card in the fixture, the height cap that squashed
twelve cards to 40px strips left nothing wrong to see.

> **Make a new checker fail on purpose before trusting it.** Plant the fault it
> is supposed to catch. Every committed test in `.claude/` has been proved this
> way.

**An instruction reported complete without touching the file. Twice.** The RSVP
sensitivity banner and the series removal paragraph were each reported removed
and were each still on screen. Both times `git log -S"<exact string>"` returned
exactly one commit: the one that added it. Nothing had overridden it or
duplicated it. The edit simply never happened.

> **"It did not take" means run `git log -S` first**, before touching anything.
> One command settles in seconds what the alternative explanations (wrong
> element, second copy, specificity) cost hours to chase. Then say plainly
> which it was.

**Containment removing an intrinsic floor.** `container-type: inline-size`
applies *size* containment, which computes the element's intrinsic size as if it
had no contents. Harmless when the width comes from the parent, which is the
only case anyone tests. Fatal when the parent sizes from its contents: a table
cell, a float, an inline-block, a flex item. On the live page `.uc-sidebar` was
in a table cell; its min-content fell from ~150px to 30px; every column
collapsed. The CSS was correct and the deployed stylesheet was byte-identical to
the repo. **The element it was measuring had been erased.**

The comment above it read "SAFE, BECAUSE OF WHAT IS NOT IN HERE" and reasoned
carefully and correctly about absolutely positioned descendants. All true. None
of it about size.

> **One mechanism verified is not "safe".** When a property brings several
> behaviors, the checklist is the behaviors, not the one that came to mind.
> Write down which you checked, so the unchecked ones read as a gap rather than
> as covered. Corollary: do not publish a number derived by arithmetic. Measure
> it and commit the probe.

**Two correct comparisons that together hid every panel.** `showView()` had
`list.hidden = (view !== 'list')` beside `cal.hidden = (view !== 'calendar')`.
Correct for the two views that existed. For `'combined'`, both are true, and the
mode rendered its search box, filter bar and count and then nothing. The server
markup was right the whole time, which is why the panels were in the inspector.

> **Default to keep, not hide.** A mode added later should render too much,
> which gets reported, rather than nothing, which reads as the plugin being
> broken. And the on-site twin (`calendar.js`) and the embed (`embed.js`) are
> documented twins that drift silently: the same trap sat unreached in the
> other runtime.

A shared thread runs through most of these: **a verified change is not a
verified outcome.** `git log -S` answers "was my edit applied"; it does not
answer "why does this still look like that". Start from the element as rendered
and hunt the effect by any mechanism.

---

## 8. Agreed, not built

Decisions settled in conversation that have no code yet. They live here because
a chat ends and this file does not. Move an entry into the body of this document
when it ships, and delete it here.

### EveryAction event import, agreed 2026-08-17

**Why not an API.** EveryAction API keys **cannot be scoped to events only**.
Any key that returns event listings also exposes client and attendee records
from the **Aging Services** program. The API route was rejected on **privacy
grounds, not technical ones**. It would work, and the exposure is unacceptable.
Do not revisit this by finding a cleverer set of API calls; the constraint is
the key's scope.

**The agreed mechanism.**

- **Val**, who manages EveryAction, runs a **cron job on the hour** that writes a
  **JSON file of events** to the shared host.
- The plugin **fetches it over plain HTTPS at half past the hour**. No
  authentication layer, no API client. This has already been proven with an
  earlier PHP file, so the fetch path is known to work.
- Imported events land in the **pending queue**, exactly like GFMP and
  Eventbrite, and go through the same `owned_fields()` / `manager_fields()`
  contract in §3.

**"Half past" means the first visit after that.** WordPress cron does not fire on
a schedule on its own (§4), so the fetch happens on the first run at or after the
half hour. For hourly data that is fine, and no special handling is needed.

**Fields requested from Val:**

| Field | Why |
|---|---|
| A **stable unique ID per occurrence** | So a refetch updates rather than duplicates. Without it, every fetch creates a new event. |
| Title | |
| Start and end **date and time, with timezone** | |
| **The public signup URL for that specific occurrence** | Each date has its own. **This is the field that makes the integration worth building at all.** A generic program URL does not do the job. |
| Location name and address | |
| Description | |
| Image URL | |
| Public or internal | Maps to the private flag. |
| Capacity, if available | |
| **Something identifying the recurring group** an occurrence belongs to | So a weekly coffee social groups as one series rather than arriving as 52 unrelated events. |

**Open questions, both to be settled before the adapter is written:**

1. **A sample file with real events has been requested.** Do not build the
   adapter against a guessed shape. The GFMP spec has been wrong or silent four
   times (§3) and every one of those cost a release.
2. **Does Val's job write atomically** (temp name, then rename), so a fetch
   cannot catch a half-written file? A partial JSON read on the hour is a
   plausible and silent failure mode.
