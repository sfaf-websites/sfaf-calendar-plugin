=== SFAF Calendar ===
Contributors: sanfranciscoaidsfoundation
Tags: calendar, events, rsvp, nonprofit, embed
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 3.42.0
License: GPLv2 or later

The San Francisco AIDS Foundation event calendar: manage events, RSVPs, reminders, and recurring series in one place, display them on this site, and embed them on any other site with a small block of HTML.

== Description ==

SFAF Calendar is the San Francisco AIDS Foundation's own event calendar. Staff create and manage events: dates, recurring series, RSVPs with capacity, reminders, categories, organizers, and venues, either through the WordPress admin or the standalone /caladmin front-end portal, with role-based access for admins, editors, and contributors.

Events display on this site through the [sfaf_calendar] shortcode and a styled single-event page with SEO/JSON-LD markup. Where the calendar needs to appear on another site that cannot run the plugin, a small HTML embed block renders the same event cards from this site. Nothing is installed or stored remotely. A read-only REST feed (optionally gated by an API key) lets other SFAF sites mirror the events.

**Core Features:**

* Custom Event post type with full WordPress editor support
* Event Categories, Organizers, and Venues taxonomies
* Date, time, location, and recurrence fields
* RSVP system with capacity tracking
* Per-event social share, donate button, add-to-calendar (.ics + Google), and reminder signup
* Four branded emails: a confirmation when somebody registers, an alert to staff, the morning-of reminder, and a list of who is coming two hours before
* One notification list per event, holding people, teams, and outside addresses, resolved when the mail goes out
* A cancel link in every registrant email, working with no account, which frees the place
* An hourly scheduled runner with a run lock, a run log, and failure notices, nudged by any page carrying a calendar
* Styled single event template (auto-loaded, theme-overridable)
* Per-event display toggles, per-event email switches, and confirmation copy overrides
* Category filter buttons and search on the public calendar
* Shortcode generator and branding controls (logo, colors, card style)
* REST API for multi-site event sync (dormant, see below)
* Integration panels for GoFundMe Pro, Eventbrite, Pardot/Salesforce, Google Calendar, Galaxy Digital, and Webhooks
* CSV export of RSVPs (compatible with Google Sheets import)
* Responsive, modern UI

**Shortcodes:**

* `[sfaf_calendar]` - Full calendar with filters
* `[sfaf_calendar category="support-groups"]` - Filtered by category
* `[sfaf_calendar layout="compact" show_filters="no"]` - Compact list, no filter bar
* `[sfaf_calendar view="combined"]` - Month grid and the upcoming dates sidebar side by side, stacking below 888px
* `[sfaf_calendar source_links="yes"]` - Imported events open at their source listing
* `[upcoming_events count="5" category="fundraising"]` - Compact upcoming events widget

Build any of these visually with the Shortcode Generator. It has no menu entry
as of 3.27.0, because the WordPress menu now carries administrator screens only
and this one gets used about twice a year, but the page is still registered and
still works. It is at:

`/wp-admin/edit.php?post_type=uc_event&page=uc-shortcode-generator`

== Private events ==

**An event that cannot be found, and works normally for anybody holding its
link.** The use case is a reception for donors above a giving level. One
checkbox on the event, off by default.

**This is an unlisted link, not access control, and the difference matters.** A
link can be forwarded, and SFAF accepts that: it is true of every unlisted-link
system. What the feature guarantees is that the event cannot be FOUND by
somebody who was not sent the link.

**Privacy is on the event, never on the series.** A private event is hidden
whatever series it belongs to, and a private event in a public series does not
appear on that series page. A wholly private series is every event in it marked
private. There is deliberately no series-level setting: two settings that can
contradict each other are worse than one rule. The event still belongs to its
series for authoring, so creating one into a series still pulls in the series
details, and "use this event's details on another date" works normally.

**Every route it is hidden from:**

* The web address becomes 32 random hex characters, so a plausible address
  cannot be typed. Each date of a repeating private event gets its own token,
  so being sent one date does not hand somebody the others.
* `noindex, nofollow` on the page.
* Out of the calendar list, the month grid and the sidebar.
* Out of search, public and embedded.
* Out of the series page and the series term archive.
* Out of the REST payload the embed serves, and out of core's own
  `/wp-json/wp/v2/uc_event` collection.
* Out of the WordPress sitemap and out of Yoast's, by two independent
  mechanisms: the event is stamped with Yoast's own noindex meta, which takes it
  out of Yoast's sitemap by itself, and its id is also added to Yoast's
  exclusion filter.
* No JSON-LD, no Open Graph tags and no Twitter card, so a forwarded link does
  not unfurl into a titled preview in a public channel.
* Out of the dormant multi-site feed, because a satellite would render it on a
  page this plugin does not control.
* The `.ics` download requires the event's token as well as its id. Without that
  the page would be unguessable while `?uc_ics=417` was not.

**What does not change, for anybody with the link:** the page renders normally,
registration works, all four emails send, add to calendar, the map, capacity and
cancellation all behave as usual.

**In the portal**, private events appear in the Events list like any other, with
a "Private" marker beside the status. They are never hidden from managers.

**Imported events**: private is a manager-set field applied after approval, and
it is declared manager-owned so a refetch can never clear it, exactly like the
fundraising progress toggle.

== The multi-site events feed is dormant ==

**Unused as of August 14, 2026, and kept deliberately.** No satellite site is
pulling from this feed and none is planned: SFAF's other sites are on Teal
Media's managed platform. The code is retained on purpose for the case where
that stops being true, because rebuilding it later costs far more than keeping
it, and it is self-contained enough to cost nothing while it sits there.

**Do not remove it in a cleanup.** It is marked dormant rather than dead in
`includes/class-sfaf-sync.php` for exactly that reason.

What it is: a read-only `GET /wp-json/sfaf-calendar/v1/events` feed, plus the
API key that gates it, consumed by the separate SFAF Calendar Satellite plugin.
It is **not** the route the embed uses, which is `/sfaf-calendar/v1/embed`, so
the two cannot affect each other.

**It is switched off, not merely idle, and that changed in 3.28.0.** The feed
used to treat "no key configured" as open, so that it worked out of the box
during setup. It now treats it as closed and answers 403. Any key stored during
earlier setup was cleared once on upgrade, so an old credential pasted into a
satellite that no longer exists is worthless.

**To turn it back on:** generate a key under Events > Settings > Multisite and
paste it into each satellite. That is all of it.

== If the calendar portal is down ==

Event management lives at `/caladmin`. These are the routes that do not need it
to be working, for the case where it is not:

* **Access, roles and teams**: Events > Calendar Users. This is why that screen
  is in the WordPress admin and it is the first place to go when somebody cannot
  get into the portal.
* **Settings and credentials**: Events > Settings.
* **The scheduled runner and the test send**: Events > Automation.
* **Events themselves**: the ordinary WordPress Events list and editor.
* **Series**: unlisted, at
  `/wp-admin/edit.php?taxonomy=uc_series&post_type=uc_event`. It renames a
  series, fixes a slug and deletes one. It cannot show the image, the default
  FAQ set, the schedule or the events in the series, and it says so at the top
  of itself.
* **Categories and Organizers**: unlisted, at the same URL shape with
  `taxonomy=uc_event_category` or `taxonomy=uc_organizer`.

Venues, FAQ sets, email opt-ins, the registration list and the import review
queue have no WordPress route and are reachable only through the portal.

== Event Images ==

**Supply event images at 1200 x 675 pixels (16:9 landscape).**

Event cards crop the image to 16:9 and fill the box, so anything taller than
that loses its top and bottom. A portrait photograph of a group is the case
that goes wrong: the crop takes a landscape band out of the middle and can cut
through people's faces. Crop it to 16:9 before uploading and you decide what
stays in frame rather than the stylesheet deciding for you.

1200 x 675 is also the size a shared link's preview image wants, on Facebook,
LinkedIn, Slack and the rest, so one picture at this size is right in both
places and there is no second file to remember.

Where an event has no image, the card draws a branded placeholder tile in the
category's color, carrying the category icon and name, at the same 16:9 shape.
That is a designed state and not a failure: roughly half of imported GoFundMe
Pro campaigns will never have a picture, because the API does not expose one.

The same target is stated beside the image control in both editors: the
/caladmin event form and the Event Image box in the WordPress admin.

== Embed Widths ==

**Every display mode fills the column it is given. There is no width setting
and there should not be one.**

Paste an embed into a 280px sidebar, a 500px middle column or a full-width
section and it reflows to fit. Each mode measures its own container rather than
the browser window, so a narrow column inside a wide page gets the narrow
layout, which is the case a viewport media query cannot see and the case these
blocks are most often in.

**Minimum widths, measured rather than calculated:**

* Sidebar mode: **200px**
* List mode: **260px**
* Month grid: **260px**
* Combined mode: **260px**, and it goes side by side at **888px**

The month grid and combined views cap at **1200px** rather than 900px, because
a grid is seven columns and every pixel of column width is room for the title
inside it. At 1200 a column is 171.1px outer and 159.1px of content, which
leaves 109.1px of title once the 32px thumbnail and its 8px gap are taken.
Below 930px of container width the thumbnail is dropped and the entry is a
title and a time, which is what it was before 3.31.0.

The combined mode has no minimum of its own because below 888px it stops being
a two-column layout: the sidebar wraps under the grid and each takes the full
width, at which point it is the month grid and the sidebar, whose 260px and
200px apply unchanged. 888px is where the changeover happens, not a minimum.

That number is the two flex bases plus the gap, 576 + 288 + 24, because flex
line breaking uses each item's flex-basis rather than its shrunk width. It is
pinned in two places rather than trusted: `.claude/embed-modes-test.php` reads
the three values back out of the stylesheet and fails if they no longer add up
to the number published here, and the width probe renders the mode either side
of it and reports which state it is actually in.

**Both bases are breakpoints plus headroom rather than numbers anybody liked.**
Each panel's basis is the narrowest column its half can still be itself in, so
that going side by side is never worse than stacking:

* The grid's **576px** clears the **560px** at which the month grid drops its
  cell entries and becomes seven columns of dots with a day panel underneath.
* The sidebar's **288px** clears the **272px** at which the sidebar row stops
  putting its 44px thumbnail beside the text and stacks a 150px picture above it.

The grid's basis was 400px until 3.31.1, which put the changeover at 744px and
handed the grid a column under 560px at every width up to 1064px: a 770px page on
sfaf.org got a 413px grid of dots. The right panel's basis was the list card's
320px until 3.32.0, when the panel became the sidebar.

**The sidebar panel is capped at 380px, where the sidebar itself caps.** A panel
allowed to grow past that would reserve room its contents cannot fill and leave a
gap down the right of the block; capped, the surplus goes to the grid, which has
seven columns to spend it on. At the 1200px block cap: sidebar 380px, grid 796px.

These come from `.claude/embed-width-probe.html`, which renders each mode at
eleven widths in three kinds of parent and prints what it measures. The 3.24.0
numbers were arrived at by arithmetic and one of them was wrong in the same
build that published it: the sidebar was documented at 220px and lost its
thumbnail entirely at any column under 270px.

**Every mode enforces its minimum.** `.uc-calendar` carries `min-width: 260px`
and, since 3.24.2, `.uc-sidebar` carries `min-width: 200px`. In a column
narrower than that they overflow rather than shrinking, which is what publishing
a minimum means. For the calendar modes the reason is containment: they are
container query containers, so without a floor they contribute nothing to a
parent that sizes itself from its contents and do not get cramped in a table
cell, they collapse. The sidebar is not a query container and had no such
hazard, but it had no stated floor either: in a table cell or a flex track it
was sized by whatever its narrowest possible rendering happened to be, which was
the longest word in its heading plus the one date line that cannot break, in
whatever font the page loaded. That measured 196px, nobody chose it, and editing
the heading would have moved it.

**A width the column has not got cannot be honoured by any of this.** Four
columns asking 180, 220, 280 and 400px are asking for 1080px; in a content area
of around 700px the table algorithm has nothing to distribute and pins every
column at its floor, so all four render identically. That is arithmetic rather
than a fault in the block, and a floor cannot fix it. Given the room, the width
is honoured exactly: measured in a table cell and a flex track, a 400px column
renders the card at its 380px cap with the thumbnail beside the title, a 200px
column renders it at 200 with the thumbnail stacked above, and the changeover is
at 272px.

**What changes as the column narrows.** In sidebar mode the row reflows: above
271px the thumbnail sits left of the text as usual, and at 271px and below the
row stacks, with the thumbnail becoming a full-width 16:9 band above the title.
The picture gets more room as the column narrows, not less. It is never removed
at any width, because on real data most rows carry the branded category tile
rather than a photograph and that tile is the row's only category signal.

In list mode the card's header stacks so a category chip and a date are not
fighting over the same 200px. The month grid switches to the same treatment it
uses on a phone: a date number and one dot per event in each cell, with the
tapped day's events in full underneath.

The sidebar's reflow uses no container query and no media query at all, so
nothing about it depends on any element reporting a width correctly. The list
and the month grid do use container queries; browsers without support (Chrome
and Edge before 105, Safari before 16, Firefox before 110) get the desktop
layout at whatever width the column is. Phones still get the phone layout there,
because that has always been driven by the window as well.

== Installation ==

1. Upload `sfaf-calendar.zip` via Plugins > Add New > Upload Plugin
2. Activate the plugin
3. Go to Events > Add New Event to create your first event
4. Add `[sfaf_calendar]` to any page to display the calendar
5. Configure integrations under Events > Settings

== The WordPress screens behind caladmin ==

Organizers, categories and the series taxonomy are managed in /caladmin. Their
WordPress term screens still exist and are still reachable by URL, unlisted from
the menu since 3.27.0, as a fallback if a caladmin screen ever will not do what
is needed:

    /wp-admin/edit-tags.php?taxonomy=uc_organizer&post_type=uc_event
    /wp-admin/edit-tags.php?taxonomy=uc_event_category&post_type=uc_event
    /wp-admin/edit-tags.php?taxonomy=uc_series&post_type=uc_event

Nothing about those taxonomies changed when their caladmin screens were built.
This is recorded here rather than on the screens themselves, which a manager
reads while doing their work and where a URL they will never type is noise.

== Scheduled Tasks ==

Reminder emails go out at 6:00am on the day of the event, and the "who is
coming" summary two hours before it starts. WordPress cannot do that on its own.

**One runner, every 15 minutes, doing whichever jobs are due.** Fifteen rather
than sixty because the "who is coming" summary is due two hours before an event
starts, and an hourly runner is up to an hour late for it. A run with nothing
due costs one database query, so four an hour is not four times the work.
**Events > Automation** lists every job, when it last ran and when it is next
due, and says in a sentence at the top whether any of it is working.

**Why WordPress cannot do it on its own.** WP-Cron is not a scheduler. It is a
check that runs when somebody visits the site. On a calendar with no traffic at
6am, a 6am job simply does not happen; it waits until the first visitor, which
might be 10am, or the next day.

**Since 3.25.0 the plugin brings its own trigger, and it needs nothing set up.**
Every sfaf.org page carrying a calendar asks the calendar site to run its jobs,
once the page has finished loading and at most once every fifteen minutes. The
calendar site has almost no traffic of its own; sfaf.org has plenty, and this
lends it a heartbeat. Three things keep it cheap: it waits for an idle moment
after load, so it never competes with the page; a timestamp in the visitor's
browser means one person reading six programme pages sends one request rather
than six; and the endpoint answers in a few milliseconds when the interval has
not elapsed, which is almost every time. Nothing is configured, nothing is
installed, and it travels with the plugin.

Its status is on **Events > Automation > Page-view nudge**, which says when a
page last started a run.

**An external pinger is the intended setup, and there is an order to it.**
Nothing has to be configured on the server, which is the point: the plugin stays
portable and moving it to another host needs no server work. Do these three
things in this order. Doing them in a different order can leave nothing running
at all, and the cause is not obvious from anywhere on the site.

**a) Create the external ping.** At cron-jobs.org (or UptimeRobot, or any
service that will request a URL on a schedule), make a job that requests this
URL every 15 minutes:

    https://YOURSITE.org/wp-cron.php?doing_wp_cron

Replace YOURSITE.org with the real domain. The exact URL for this install is on
**Events > Automation > Cron URL**. Copy it from there rather than typing it.

It needs no parameter, no header, no key and no authentication. `wp-cron.php` is
a public endpoint by design; it starts no work that WordPress was not already
going to do, and it holds its own lock so two overlapping requests cannot double
anything up.

**Why that URL and not the admin-ajax one on the same screen.** The page-view
nudge endpoint runs THIS PLUGIN'S jobs and nothing else. `wp-cron.php` dispatches
WordPress's whole schedule: core's own maintenance, every other plugin's jobs,
and this plugin's among them. Once `DISABLE_WP_CRON` is set, nothing else is
dispatching that queue, so pointing the scheduler at the nudge would keep the
calendar working and quietly stop everything else on the site. The nudge also
throttles itself to one run every 15 minutes and answers 204 inside that window,
so an external 15-minute ping would land on the boundary and be turned away
about half the time.

**b) Confirm from the Automation screen that tasks are running.** Go to
**Events > Automation**. The banner at the top says, in words, whether scheduled
tasks are running, running late or stopped, and the Scheduled tasks table says
when each job last ran and when it is next due. Wait for two of the external
pings and check that the banner says running and that the run log has grown.

Do not skip this step. It is the only thing standing between a mistyped URL and
a site where reminders silently stop.

**c) Only then set `DISABLE_WP_CRON`.** Edit `wp-config.php` and add this line
**above** the `/* That's all, stop editing! */` comment:

    define( 'DISABLE_WP_CRON', true );

This stops WordPress firing scheduled tasks off visitor traffic, so the external
ping is the only thing that triggers a run and the same work cannot happen
twice. Setting it before step (b) means that if the ping is not working, nothing
is running at all and nothing says so.

`wp-cron.php` keeps working with the constant set: it never consults it. The
constant only suppresses the spawn that ordinary page loads would otherwise do.

**After this, scheduled work depends on an external service.** That is a real
trade for the portability, and it is worth writing on a sticky note: if reminders
ever stop, the cron-jobs.org job is the first place to look, before anything in
this plugin.

**The page-view nudge stays on, and it is not a second scheduler.** It is a
request from sfaf.org, which has traffic this site does not, and it arrives
whether or not the external service is alive. That is what lets this site notice
that the external service has stopped and send the alert email; a site that is
never visited cannot report that it is never visited. Leave it alone.

**What still cannot be caught.** If the external ping dies AND nothing else ever
requests this site, no code here runs, including the health check, so no alert is
sent. The nudge is what closes that in practice. The Automation screen is the
place to confirm it, and the alert email is the thing that should arrive first.

**Do not add a second scheduler.** One cron job or one pinger, not both, and not
a cPanel cron job as well. The run lock will stop two runs overlapping, but a
second trigger only ever produces log entries saying a run stood down.

**A timeout at the pinger does not mean the run failed.** `wp-cron.php` calls
`ignore_user_abort()` and keeps working after the connection is dropped, so a
service that gives up after a few seconds may record a failure for a run that
completed. **The run log under Events > Automation is the source of truth, not
the status code the pinger recorded.**

**Email deliverability.** Mail is sent through `wp_mail()`, so an SMTP plugin or
a `wp_mail` filter can take delivery over without any code change, and nothing
in the plugin depends on a particular provider. **WordPress mail sent through
Bluehost's own server has poor deliverability** and will often be filtered as
spam or dropped outright. Configure a transactional email service (SendGrid,
Postmark, Mailgun, Amazon SES or similar) before launch. The From name, From
address and Reply-To are settings under **Events > Settings > Email Templates**,
not constants in code, so pointing them at a real sending domain is a form
change and nothing more.

**Automated fetching is switched off by default and should stay off for now.**
A fetch can take an event off the calendar when its source stops returning it,
and that behavior has never been watched through a real removal. Switch it on
by hand, under Events > Settings > Scheduled Tasks, only after one real removal
has been seen go through correctly. Until then use "Fetch updates" on the
portal's Pending screen, which runs the same fetch with somebody watching.

== Email ==

Four emails, all on by default, all switchable per event.

1. **The confirmation**, to the person, the moment they register. Date, time,
   location, add-to-calendar links for Google and for Apple or Outlook, a link
   to the event page, and a link to cancel.
2. **The alert**, to the event's notification list, the moment somebody
   registers. Says who, and how many places are taken.
3. **The morning-of reminder**, to everybody registered, at 6:00am on the day
   (or at midnight when the event starts before 6). The notification list is
   copied in, so staff see what participants were sent.
4. **Who is coming**, to the notification list, two hours before the event
   starts, listing everybody registered. Nothing is sent when nobody has
   registered.

**One list, per event.** The same people are told about all three staff emails:
individuals, whole teams, and outside addresses, picked in one place on the
event. Teams resolve when the mail goes out, so somebody added to a team today
starts receiving mail for events chosen before they joined, and somebody removed
stops immediately. Nobody gets two copies of anything: the list is deduplicated
by address, so being picked individually and sitting in a chosen team is one
email.

**It starts with whoever created the event**, which is what makes the common
path free. Create an event with a title, a date, a time, a place, a category and
an image, press Save, and it has working email with a real recipient. Nothing on
the Notifications card has to be touched. Turning one of the four off, adding
people, or writing your own copy is behind a fold that says what the default is
currently doing.

**Cancelling.** Every email to somebody holding a place carries a cancel link
with a token in it. No account and no password. **The link opens a page that
asks**; it never cancels on being opened, because mail scanners and safe-link
rewriters fetch the URLs in an email before a person has read it, and a
one-click cancel would drop people's places for them. Cancelling marks the
registration cancelled rather than deleting it, so the organizer can see that
somebody registered and then cancelled, and the place is free immediately: it
leaves the count, the reminder send, and the who-is-coming list at the same
moment.

**Where it comes from.** Set the from name and address under **Events >
Settings > Email**. It ships as San Francisco AIDS Foundation
<websites@sfaf.org> and is a setting rather than a constant because
events@calendar.sfaf.org is being set up and will replace it: when the mailbox
exists, type it in and nothing is deployed. Replies go somewhere else on
purpose, per event: the event's own reply-to address, or the person who created
it, or the site default, whichever is set. The registration alert is the one
exception, and it replies to the person who just registered.

**Delivery is handled by the site's mail plugin.** Everything goes out through
wp_mail(), so the Postmark plugin already installed picks it up. This plugin
adds no SMTP settings and no second delivery path.

**Test it before you trust it.** **Events > Automation > Send a test email**
builds any of the four against a real event and sends it to you. What to check
in what arrives: press reply and read who it is addressed to; view the source
and look for a `text/plain` part; confirm the banner loads; and open it once in
Outlook on Windows, where the buttons should be rectangles rather than bare
links.

== Google Maps on the event page ==

Optional. Paste a Maps Embed API key under **Events > Settings > Integrations >
Google Maps** and each event page shows a map beneath its address. Leave the
field blank and the page shows the address as a Google Maps link and nothing
else: no map, no error, no admin notice on the public page.

**The map loads with the page, and that is a reversal made deliberately in
3.26.1.** From 3.2.0 the map sat behind a "Show map" button and there was no
iframe in the markup until it was pressed, so nothing was requested from Google
on page view. The reasoning was that these event pages cover HIV services,
substance use programs and trans health groups, and a Google iframe in the
markup is fetched on page view, which hands Google the page URL, the visitor's
IP and their referrer whether they wanted a map or not.

That cost has been weighed and accepted: the map being immediately visible is
worth it. The reasoning above is recorded rather than deleted, at
`sfaf_event_map_html()` in `includes/sfaf-template-functions.php`, so that the
automatic load reads as the current answer to a question already asked rather
than as something nobody thought about.

The frame carries `loading="lazy"`, so the request fires as the section comes
near the viewport rather than at the top of the document. That is the ordinary
performance default, not a consent mechanism, and it does not change what a
visitor sees. The address stays an ordinary link that requests nothing until it
is clicked, with or without a key.

**Restrict the key before you paste it in.** In the Google Cloud console:

1. *Application restrictions* > HTTP referrers, listing both
   `resources.sfaf.org/*` and `sfaf.org/*`. Both are needed: the calendar is
   served from one and embedded on the other.
2. *API restrictions* > Restrict key, with the **Maps Embed API** selected and
   nothing else.

The Maps Embed API has no usage cap. An unrestricted key is therefore a billing
exposure rather than a map risk: anybody who copies it out of the page can run
it against this account from their own site indefinitely. The referrer
restriction is what makes a key that is visible by design safe to have visible.

== Changelog ==

= 3.42.0 =

**Nobody registered gets an email because somebody did not notice a checkbox.**

**WHAT WAS WRONG.** Changing the date, the time or the location of an event with people signed up emailed all of them, and the only thing standing between a save and that mail was a checkbox on the form, ticked, sitting among thirty other controls. The cancel card had a second one exactly like it. A checkbox somebody scrolls past is not a decision, and mail cannot be recalled.

**THE QUESTION IS ASKED WHEN IT MATTERS, AND ONLY THEN.** On Save, if the date, the start time, the end time or the location actually changed AND at least one person is registered or subscribed, a dialog appears before anything is written. It names how many people would be told and what changed, old value to new value. It offers two answers, "Save and email them" and "Save without telling them", and a way out that saves nothing and sends nothing, leaving everything typed still on the form. With nobody registered, or with none of those four fields touched, there is no dialog: a click in the way of a save that cannot email anybody is a click that teaches people to dismiss dialogs.

**THE DEFAULT DIRECTION IS SILENCE, WHICH IS THE WHOLE POINT.** A ticked box fails open, so anything posting to the save route mailed everybody. Now only an explicit answer sends. No answer, an unrecognised answer, or a form posted with scripting off all mean nobody is written to. The costs are not symmetrical: not sending leaves a manager able to send, and sending cannot be taken back. So that nobody is left assuming, the screen a save lands on says which of the two happened, in the same number the dialog asked about: "3 people were emailed about the change", or "the date, time or location changed and nobody was emailed about it".

**CANCELLING ASKS ONCE, NOT TWICE.** Cancelling is already deliberate, so it does not get a dialog about cancelling and a second one about mail. The confirmation states how many people would be told and carries both answers. Cancelling a whole series is the same question on the screen that already exists for it, as two buttons rather than a tick above them. Worth saying: the cancel card's confirmation did not previously exist. `data-uc-confirm-cancel` was on the form and nothing in the JavaScript read it, so cancelling an event with registrations went straight through on one click.

**AUTOMATIC MAIL IS UNTOUCHED.** Registration confirmations, the morning-of reminder and the two-hour summary send exactly as they always have. They are not caused by somebody editing and are not asked about: they are the thing the person signed up for. Only mail a manager's edit would cause is behind this.

**Checked by exercising it, not by reading it.** The 3.36.0 tests for this area passed while every save was cancelling the event, because they asserted properties of the source. So the new one is written as the two sentences that matter, "no mail is written when the choice is do-not-send" and "mail is written when it is send", and decides each by running the real gate and the real mailer and counting what came out. It also holds the old checkbox name to zero occurrences, because that name failing open is what this release is about.

**The dialog and the plugin agree on what counts as a change.** Deciding whether to ask means comparing formatted values, which is what the server does: `18:00` and `6 pm` are one fact and nobody should be told about the difference. That needs a second copy of two formatters in the browser, so there is a cross-check holding them to the PHP ones over twenty-one cases including midnight, noon and a range that crosses from am to pm. Drift in one direction asks about changes nobody made; in the other it stays quiet about a real one, which is the silent failure.

= 3.41.0 =

**Saving an event cancelled it. Every save, on every release from 3.36.0 to 3.40.0, and everybody registered was emailed that the event was off.**

**IT IS OLDER THAN IT LOOKED.** It was reported against 3.39.0 and 3.40.0, which is where somebody happened to press Save on an event that mattered. The call site tells a longer story: the cancel card has sat inside the event form since 3.36.0, the release that added it, so it was never once correct. Five releases, not two, and the search for affected events has to go back that far.

**WHAT HAPPENED.** The Cancel this event card was rendered into the event editor's side column, and that column is echoed inside the event form. HTML forbids one form inside another: every browser drops the inner start tag and keeps its children, so the cancel card's inputs joined the event form. Pressing Save therefore posted `uc_action=save_event` AND `uc_action=cancel_event`, plus both nonces. PHP takes the last value of a repeated key, so what arrived was `cancel_event` with the matching cancel nonce, and the security check passed because both halves came from the same card. The event was cancelled, the cancellation email went to every confirmed registrant and every subscriber, and `save_event` never ran, so the edit was discarded as well.

**WHAT TO DO ABOUT THE EVENTS THIS HIT.** A cancelled event is a state, not a deletion, so nothing is gone. In caladmin, open each affected event and use Reinstate on the cancel card. To find them: they are the events showing as cancelled that nobody meant to cancel, and the ones a manager edited at any point from 3.36.0 to this release. `_uc_cancelled_at` on each event is the timestamp of the save that did it, which makes the list checkable rather than a guess. **A SECOND SAVE PUT IT BACK ON, SO THE CANCELLED LIST IS NOT THE WHOLE LIST.** Once an event was cancelled the card rendered its reinstate form instead, and that form's `uncancel` field joined the event form exactly as the first one had. So saving the same event again silently un-cancelled it. An event edited twice reads as perfectly normal today and its registrants were still emailed that it was off. Anything edited between 3.36.0 and this release is worth checking, not only what currently shows as cancelled.

**The emails cannot be unsent.** If registrants were told an event was cancelled and it was not, that needs a correction from a person, and this plugin has no route for one: send it yourself, from the list on the event's registrations screen.

**How many messages went out, per accidental save:** one for each distinct email address holding a `confirmed` or `subscribed` row on that event, which is one message per person however many dates were involved. An event nobody had signed up for sent none, and the cancellation still happened. The exact number is not recoverable from the repo, because the plugin keeps no log of sent mail.

**The fix is that the card is outside the form**, below it, with the reason written where somebody would put it back. Two checks now assert it: one that no renderer opening a form is called inside another form and that no form carries two actions or two nonces, and one that works out what the event form actually posts once forms are flattened and PHP has taken the last of every repeated key.

**A save was also able to unpublish a published event**, which is unrelated and was found by writing the assertion for the above. `save_mode` had three values and every one of them set a status, with `draft` as the fallback when nothing was posted. Save Draft was also the first submit button in the form, which is the one a browser presses when somebody hits Enter in a text field. On anything already published or pending that button is now **Save**, it keeps the status the event has, and a save naming no mode leaves the status alone rather than choosing one.

**The scope dialog no longer reappears after every save.** 3.39.0 removed the confirmation armed on the save buttons and fixed the dismiss comparison, both of which shipped and both of which are still in place. Neither was the cause: the second ask was never on the button, it was on the page a save redirects to, which is a fresh load of the editor and asked the question again as though it had never been answered. The answer now rides the redirect, the editor opens already answered with the banner stating which scope is in force, and Change reopens the question by dropping it from the address. It also works with scripting off now, which it did not before: the fieldset arrived disabled and only JavaScript unlocked it.

**Venue and series were checked for the same fault the organizer picker had. Both are one per event on purpose, and they differ in how safe that is.** The mechanism is identical to the organizer one either way: both read `reset( get_the_terms() )`, which is the alphabetically first, and both write through a `set_for_event( $post_id, $term_id )` that takes a single id and replaces. So an event holding two would keep one and drop the other on the next save, silently, exactly as organizers did.

What differs is whether anything can give an event two in the first place, and that is the question that matters.

**Venue is genuinely closed.** `uc_venue` registers with `show_ui => false`, so there is no WordPress screen at all, and the portal's control is a single select over a taxonomy nothing else writes. Nothing reachable can put two on an event.

**Series is not as closed as it looks, and this is worth one live check.** It registers with `show_ui => true` and `meta_box_cb => false`. That removes the CLASSIC editor's metabox, which is what the "no metabox" reading was based on, but `uc_event` registers with `show_in_rest => true` and supports the editor, so events open in the block editor, and the block editor decides which taxonomy panels to draw from `show_ui` rather than from `meta_box_cb`. A Series panel may therefore be sitting on the WordPress post editor, and that is the same route that put two organizers on an event. **Open an event in the WordPress post editor and look.** If a Series panel is there, series has the organizer fault and is one deliberate change away from being closed like venue; if it is not, the limit is real and this note is what stops it being re-derived.

Neither is being re-registered on a guess. Changing `show_in_rest` or `show_ui` on a public taxonomy moves its archive, its REST payload and what satellites read, and that is not a change to make from an inference about another program's editor.

**The Classification card no longer reads heavier than every other card in the editor.** Its heading was never the problem. The card is almost entirely checkbox labels, and those were set half a step above body text, then shrunk inside a grid to exactly the size of the field label above them. Twelve rules across the portal were sitting between two steps of the type scale for the same reason, and there is now a sweep that fails a build for a new one. A heading at the top of a bento card also takes its band structurally now, as it already did for the other kind of card.

**Creating an organizer from the event editor is gone**, reversing part of 3.37.0. The friction it removed was real at the time and is now small, because organizers got their own screen in the same release; what it added is permanent and lands on somebody else, because a name typed mid-event gets whatever spelling was in somebody's head and the list acquires three of the same organizer, each owning some events. Organizers are made on the Organizers screen.

= 3.40.0 =

**Events can be co-hosted, and checking for that first found the caladmin editor was silently deleting a second organizer.**

**SAVING AN EVENT WAS ALREADY DROPPING ONE, AND IT MAY ALREADY HAVE HAPPENED.** The taxonomy has always accepted several organizers: `uc_organizer` is non-hierarchical, and its `show_ui` defaults to `public`, which is true, so the WordPress post editor's own Organizers box has always been able to put two on an event. What limited an event to one was the caladmin picker, a single select that read `[0]` and whose save wrote an array of that one back through `wp_set_object_terms()`, whose default REPLACES. So an event holding two organizers showed one in caladmin and lost the other the moment anybody pressed Save, with nothing said and nothing logged.

That is exactly the fault categories had until 3.8.0, on a different taxonomy, reachable the same way. Anybody who set two organizers through the WordPress editor and later opened that event in caladmin has lost one, and there is no record of it: the term relationship is simply gone. Both halves are now asserted as gone, because either one surviving reintroduces the loss.

**No primary organizer, and nothing needs one.** A category's first alphabetically drives the card colour and the placeholder tile; an organizer carries no colour and no icon, so nothing downstream has a decision to make. **They are ordered by name**, through one method, because term relationships come back in an order nothing guarantees and the same event would otherwise name its hosts one way on its page and the other way on a card, in an email or in a satellite payload. Alphabetical is what a reader can predict and what the Organizers screen already lists.

**The wording, decided in one place so three surfaces cannot differ.** One: `The Stonewall Project`. Two: `Black Brothers Esteem and The Stonewall Project`. Three: `Black Brothers Esteem, Elizabeth Taylor 50 Plus Network and The Stonewall Project`. **No serial comma**, which is AP style for a simple series and therefore the house style. An event with ONE organizer renders exactly the string it always did, which is what makes this invisible unless somebody uses it.

**Everything checked, and what each needed.**

CHANGED: the event editor's picker, now checkboxes with a present marker, so unticking every box means "none" rather than "the form did not ask"; its save, which writes the ticked set; the event page, which already listed every organizer joined with a comma, so the names were right and the English was not; the card byline, which named the first only; the JSON-LD; the series prefill from 3.38.0, which offered the first; the caladmin events-list column, the `{organizer}` token and .ics field, and the satellite payload, all of which handled several already but in an order nothing guaranteed.

**The pending queue needed nothing**, because it renders through `render_manager_control()`, the same shared source as the editor, so it became checkboxes with it.

UNCHANGED, and each for a reason worth stating: the organizer FILTER on the public calendar and in both generators is a `tax_query`, which has always matched an event where the term is one of several, so a co-hosted event now appears under BOTH hosts and both counts include it, which is correct. Embed blocks scoped by organizer resolve through that same query, so a block scoped to one host includes an event if EITHER organizer matches. Search covers the taxonomy by term name and reads every term. Duplicate-as-template already copied all terms. The per-organizer count on the Organizers screen and the count named in the deletion confirmation are the same `tax_query` and have always counted "one of several".

**The JSON-LD is the one place that does NOT use the phrase**, deliberately. `schema.org/Event` declares `organizer` as accepting one value or many, so several hosts emit an ARRAY of Organization objects: a search engine reading "A and B" gets one organisation with a strange name. Prose joining is for people. One organizer still emits a single object rather than an array of one.

**Imported events are unaffected, structurally.** `organizer` is on `manager_fields()` for both adapters, so no fetch has ever written it and none can. If a source ever supplies several, nothing happens: the adapter path does not write this taxonomy at all, and a platform's organizer is its own record rather than a term here. Making one arrive would be deliberate mapping work, not something that starts happening. The slug and the public archive are untouched, which the test asserts, because embed blocks resolve by slug.

**Eight faults planted and the two that were not caught were both worth having.** The single select returning, the save writing one value, the control not posting an array, the present marker dropped, the ordering going away, and a serial comma creeping in were all caught by name. Deleting the two-name branch of the joining rule changed nothing, which is the plant finding dead code rather than a hole in the test: for two names the general case pops the last, imploding one name gives that name, and appending " and B" produces "A and B". The branch is gone. The other plant added `post__in` to a query and the test's `WP_Query` stub reads only `tax_query`, so it was blind to it and proved nothing; it was rewritten as the change somebody might actually make, a post-filter keeping only events where the organizer is the sole one, and that is caught.

VERIFIED: 58 PHP files parse under PHP 8.3; the callable audit resolves everything with nothing unresolved, self-test passing; the whole `.claude` suite runs green, 19 PHP harnesses plus the JS panel test, the guard test and two self-tests; eight faults planted and every real one caught by name; three stylesheets balance and five scripts pass `node --check`. Zip built with bsdtar, extracted, diffed file by file against the tree, and both the linter and the callable audit re-run from the extract.
= 3.39.0 =

**Three bugs found by hand on a live site, and three corrections.**

**Why the change prompt did not fire, and it was not the trigger list.** Mark opened an event he is registered for, chose "this event only", changed the end time from 2:30 to 2:31 and saved. Nothing appeared.

Ruling the suggested causes out first, because each would have needed a different fix: end time IS in the comparison, through `sfaf_ap_time_range()`, and reproducing the exact save shows the diff reporting `Time: 1-2:30 pm -> 1-2:31 pm`. Nothing normalises the minute away. The comparison runs on the ordinary Save path, after the write, and the prompt block renders inside the form and inside the scope fieldset, which the modal re-enables.

**The audience was too narrow.** `SFAF_Announce` counted `status = 'confirmed'` only. Somebody who pressed "Get Reminders" rather than registering is stored as `subscribed`, holds no place, and was invisible to it: `count_affected()` answered 0, so the prompt block never rendered, no marker was posted, and nothing was sent. `SFAF_Reminders::recipients()` has always used `status IN ('confirmed','subscribed')`, so there were two definitions of "who is told about this event" and the newer one was narrower. There is one now.

A subscriber gets the message worded for them: no "your registration has been kept" for a registration they never made, and "Stop reminders" rather than "Release your place" for a place they never held. The cancel link still reaches them, because that route moves a `subscribed` row to `cancelled` exactly as it does a `confirmed` one.

**The 3.36.0 tests seeded `confirmed` and `cancelled` rows and never a `subscribed` one**, which is exactly why they passed throughout. The new assertion seeds a subscriber and checks all three halves: counted, written to, and not offered a place they never held. Planting the original bug back is caught by name, as is reverting only the count query, as is letting a cancelled row through.

Correcting that exposed the same stub defect 3.36.0 already had to fix once: the `$wpdb` stand-in parsed `status = 'x'` and the queries now say `status IN (...)`, so it silently stopped filtering and a cancelled row came back from a query that excludes them. It reads both shapes now.

**The scope question is asked once.** Answering "this event only" at the modal and being asked again on Save is the redundancy the per-field pencils had before 3.23.0, and the answer is the same: the modal decides scope once, the banner above the form states it permanently with a Change control beside it, and everything after follows without asking. The save-button confirmation is gone, and `data-uc-scope-confirm` went with it rather than being left as an attribute nobody reads.

**Cancel no longer loops back into the dialog, and the cause was a full-URL comparison.** Dismissing the scope modal navigates to the referrer when it is a page on this site and not this same page, and "not this same page" compared complete URLs. A save redirects back to the editor with `?msg=saved` on it, so the referrer (the editor, no msg) did not equal the current URL (the editor, with msg), the guard passed, and Cancel navigated to the same editor again, which opened the modal again. There was no way out except answering. It compares the PATH now: the same screen is the same screen whatever query string it is wearing.

**Every other dialog in caladmin was checked and none traps.** `ucConfirm()`, which draws the delete and publish confirmations, closes and removes itself on Cancel and navigates nowhere. Its `window.confirm()` fallback for browsers without `<dialog>` is native and cannot loop. The raw `confirm()` calls on the delete buttons are the same. The scope modal was the only one that navigated on dismissal, which is what made it the only one that could return to itself.

**Red text on a red button, measured.** `.uc-btn-danger` was declared twice in `portal.css` at the same specificity. The older rule is a filled button, white on `#c0392b`. The rule 3.38.0 added for the cancel control was an outline: it set `color` and `border-color` and did NOT set `background`, so it won the ink and inherited the older rule's fill. The result measured **1.19:1** against a 4.5:1 floor. That is the category-chip mistake from before 3.15.0, one colour used as both tint and ink. The newer rule is deleted rather than patched, because the older one is correct at **5.44:1**.

**Measuring it found a second failure that was not reported and is older.** `--p-danger` is Red, Pantone 179 CP, which `DESIGN.md` has as a large-text-only colour, and every `.uc-link-danger` in the portal was setting it as body text: **3.68:1** on a card and **3.40:1** on the page. Both are under the floor. Same fix and same shape as the teal in 3.20.0: brand red stays what fills things, and `--p-danger-text: #c0392b` carries writing, measuring **5.44:1** and **5.03:1** on those two grounds. It is not a new colour; it is what the danger button has always been filled with.

`.claude/destructive-contrast.php` measures every pair and fails under 4.5:1. It also fails if `.uc-btn-danger` is declared twice again, or if the base rule sets an ink without saying what is behind it, which is the mechanism rather than the symptom. Brand red as body text stays in the table marked as rejected, with its number, because a measurement nobody can see is one somebody re-litigates.

**The Teams copy said a team is a notification list, and since 3.35.0 it is primarily access.** Somebody reading it would think adding a person meant more email for them, when it gives them the right to edit that team's events and read their registrations. Both descriptions now lead with access and mention notification second, and both flash messages follow. The sweep also found the notify picker still defining a team by what notification does with one, which now describes the pick rather than the team, and two pointers naming a sidebar item renamed in 3.38.0. "Untick to take Mark Sapoznikov out when this is saved" is gone: a tick means in.

**FAQ set creation starts with one row.** It shipped as three fixed pairs, which is too many for a set with one question, too few for a set with four, and offered no way to remove a row somebody had typed into except blanking it. It is the repeater the event editor's FAQ block already uses, markup for markup, so "+ Add FAQ" and the row remove behave identically in both places and `initRepeaters()` drives it with no new script. Empty rows are dropped by `clean_rows()` and were never stored as blank questions.

VERIFIED: 58 PHP files parse under PHP 8.3; the callable audit resolves everything with nothing unresolved, self-test passing; the whole `.claude` suite runs green, 19 PHP harnesses plus the JS panel test, the guard test and two self-tests; the live bug reproduced from the source before the fix and caught by name after it, along with two variants of it; every destructive pair measured against 4.5:1; three stylesheets balance and five scripts pass `node --check`. Zip built with bsdtar, extracted, diffed file by file against the tree, and both the linter and the callable audit re-run from the extract.
= 3.38.0 =

**The curved coloured accent border is gone, and it was not only on the Automation screen.** That pattern is the most recognisable tell of generated UI, and sweeping for it found the portal was built on it: `.uc-card` and `.uc-bento-card` each carried `border-left: 3px solid teal` with an asymmetric `4px 12px 12px 4px` radius, so EVERY card in caladmin wore one. An accent that every card has distinguishes nothing at all, which is the whole test.

**Removed:** both card classes, the repeat-summary band, the login card's yellow top rule, the four Automation health-banner edges, the three GFMP probe-result edges, the three fetch-report row edges, and the accent edges on 3.35.0's reassignment panel, 3.36.0's notify prompt and 3.36.0's cancelled-event banner. The fetch rows are the clearest case: `summarize()` already writes "failed." and "not connected." into the sentence, so the colour was repeating the words beside it.

**Kept, and each is on a whitelist with the fact it carries:** `.uc-single-header`, the category colour on an event page, because the category system is the one place in this plugin where colour IS the signal (the same colour is the card ring, the chip and the placeholder tile) and the page names the category nowhere in words; and `.uc-field-attention`, which of roughly thirty fields is still empty, because the publish banner names WHAT is missing and only this says WHERE. `.claude/decoration-audit.php` fails on anything else, so a new accent is caught by default rather than by somebody noticing the screens look generated again. Its own first cut over-reported by four of five, flagging a spinner whose 2px circle border IS the spinner, two hover underlines and a 1px hairline; it now matches thick edges only, because an audit that over-reports buries its real findings.

**Organizers, follow-up.** The add control on every list screen was a white outline disclosure on a white card, so nothing led; the primary submit was hidden INSIDE it, which is the wrong half. `.uc-add-toggle` gives the opening control the yellow the portal reserves for actions, on **Organizers and Venues**, with Teams and Series taking `.uc-btn-primary` on the links they use instead. Per-row Edit toggles stay quiet: twelve yellow controls on a screen of twelve organizers would mean none of them led either. Rows gained rhythm with an alternating surface and a heavier name. Delete is a proper danger button rather than red text.

**Does renaming an organizer change its slug? It does not, and since this release that is pinned rather than inferred.** `wp_update_term()` derives a slug from the name when its args carry no `slug` key, so the honest answer to the question as asked was "it depends on a WordPress internal". For a value that other people's embed blocks resolve through, and whose users this plugin cannot enumerate, that is the wrong thing to leave to inference, so `save()` now passes the existing slug back on every rename. With the value unable to move, the field comes off the screen entirely and the warning about emptying embed blocks goes with it: there is no longer an action to warn about. The slug still shows on the row as reference, which is reading rather than editing.

The note about the WordPress term screen still existing is gone from the screen and recorded in the readme instead. It told a manager about a URL they will never type, and justified a design decision rather than saying what to do.

**Access levels moved from a section to a help affordance.** The explanation shipped as its own full section on Users and Permissions, and it was asked for beside the add-user control. Help anywhere other than next to the control it explains is help nobody finds at the moment they need it. Same 3.10.0 disclosure, same content, closed by default, now inside the card it explains. The sidebar item is **Users & Teams**.

**Cancelling is for native events only.** An imported event is cancelled where it lives: if a GFMP campaign or an Eventbrite listing is called off at the source it leaves this calendar through the unpublish-on-removal path, and telling the people who signed up is that platform's job, because they registered there and this plugin holds none of their addresses. 3.36.0 offered the control with a warning that it would not reach the source, and that warning was a reason to remove the control rather than to caption it: what it produced was a half-cancellation that looks whole, off this calendar and still selling places at the source, with nobody told by anybody. Refused at the render AND at the write, since a form that is not drawn is not a refusal.

**The 3.36.0 refetch lock is removed rather than kept as a belt.** It refused `update_event()` on a cancelled imported event, and an imported event can no longer be cancelled, so it guarded a state that cannot be reached. A check that can never fire is a check nobody can reason about later: it reads as evidence the case is possible. An event disappearing at source is still an unpublish, still not a cancellation, and still emails nobody, which the test now asserts by name.

**Series prefill, at creation only.** The picker is the first card on a new event, because what it is one of decides most of the rest and it used to be two thirds of the way down. Choosing a series offers its location, times, description, image, category, organizer and FAQ set as checkboxes, all ticked, with a clear-them-all button. **The date is never offered and is not in the payload**, so there is no box to tick and nothing a later change could expose.

**Nothing posts.** The whole control writes into the fields already on the page, which is the FAQ set picker's shape and the 3.3.0 reason: a control that applies by posting and redirecting discards every unsaved edit, and people then stop using it. **It asks before overwriting** and names the fields: putting the picker first means there is usually nothing to overwrite, and "usually" is not a guarantee. Values are copied, not linked. Changing an existing event's series just changes its series: an existing event has real content, and prefill is a convenience for a blank form.

Two things the prefill needed and did not have: series terms carry a description, an image and a default FAQ set but no location, times, category or organizer, so those come from the series' most recent event. The first cut asked `events()` for `limit => 1` with `order => DESC`; that method takes no order argument and always returns ascending, so it would have handed back the series' OLDEST event while looking like it asked for the newest.

**Rich text descriptions.** `wp_editor()` in teeny mode with bold, italic, links, both list kinds and one heading, and nothing else. No colours, sizes or alignment: the brand guide governs those, and a full toolbar is how a calendar ends up with events in purple Comic Sans. The one heading is **h3**, below the page's h1 title and its h2 sections, so it cannot break the reading order for anybody navigating by headings.

**What it does to the emails: nothing, because the description is not in any of them.** Every message is built from the title, date, time and location plus an optional per-event body; `SFAF_Notifications` never reads `post_content`. **What it did break** is the card summary: `wp_trim_words()` strips tags with `strip_tags()`, which joins the text either side of a tag with nothing between, so `<p>One</p><p>Two</p>` becomes `OneTwo`. Descriptions have always been plain text so this never bit, and it would have bitten every card on the public calendar and in every embed the day this shipped. `sfaf_flatten_html()` turns block tags into spaces BEFORE stripping, which is the only order that works, and leaves inline tags alone so a bold word is not split. The regression was reproduced before the fix was written.

Existing plain text carries over as paragraphs with nothing migrated: `wp_editor()` and the event page's `the_content()` both run stored content through `wpautop()`. The prose island from 3.22.0 was verified rather than assumed: `.uc-single-body` already styles p, ul, ol, li and h2 through h4, so the output is covered. The editor degrades to a plain textarea if TinyMCE does not start in caladmin's standalone document, which is worse-looking and not destructive.

**Holidays and closures.** A date or a date range and a label, entered under Events > Closures. Marked on the month grid reading "Closed for Thanksgiving", shown in a list as a flat card, in the embed as well as on resources, and clickable nowhere.

**A multi-day closure is ONE entry spanning dates**, not one per day: somebody closing for the winter break enters it once and edits it once, and four rows would be four chances to type it differently. The two renderers ask different questions of that one entry. The month grid asks per day and marks four squares, because there a square IS a day. A list asks per span and shows one card reading the range, because four identical cards is four times the noise for one fact.

**They cannot leak into event machinery, and that is a property of the storage rather than a list of exclusions.** A closure is a row in an option, not a post. Every subsystem that touches events reaches them through a `WP_Query` over `uc_event` or through a post id that must resolve to one, so none of them CAN see it and none needed changing. Nineteen were enumerated and are checked for not having grown a reference: the import queue, RSVP, the morning-of reminder, the pre-event summary, search, privacy, SEO, recurrence, series, teams, organizers, venues, FAQ sets, cancellation, the cancelled and changed emails, the scheduled runner, the embed endpoint, the satellite feed and post-type registration, plus the .ics and REST feeds in the main plugin file. The load-bearing assertion is the one that fails if a closure ever becomes a post type, because everything else follows from it.

**The two FAQ set gaps were one omission.** The event editor's set picker already exists, is already the 3.3.0-compliant version that writes rows client-side with no post and no redirect, and is revealed by `portal.js`. What it does is render nothing when there are no sets, and there was no way to make the first one except from an event that already had questions typed on it. The FAQ Sets screen now has a create control. A plain form is correct there and is not a contradiction of the 3.3.0 rule, which is about a control posting from a screen that carries unsaved work. `faq_set_save` also stopped discarding what `save()` returned: with a create form on the screen, "submitted with every row blank" is an ordinary mistake and a success message for a set that was not made is worse than a refusal.

**Nine faults planted across the two new tests and all nine caught by name**: a closure becoming a post type, a closure card gaining a link, the reminder job consulting closures, February 31st being accepted, a multi-day closure losing its middle days, a list showing one card per day, the cancel card returning to imported events, the cancel write stopping its refusal, and removal at source starting to email registrants. The closures test's own post-type check first matched the class's OWN docblock, which explains why a closure is not a post type using those words; comments are stripped first, always, which is a false positive this project has now recorded three times.

Three earlier guards fired unprompted. The admin-menu whitelist failed until `uc-closures` was added deliberately. The route whitelist and the padding audit both passed unchanged, which is what they are for.

VERIFIED: 57 PHP files parse under PHP 8.3; the callable audit resolves everything with nothing unresolved, self-test passing; the whole `.claude` suite runs green, 18 PHP harnesses plus the JS panel test, the guard test and two self-tests; nine planted faults each caught by name; the `strip_tags` regression reproduced before its fix; three stylesheets balance and five scripts pass `node --check`. Zip built with bsdtar, extracted, diffed file by file against the tree, and both the linter and the callable audit re-run from the extract.
= 3.37.0 =

**Organizers get their own tab in caladmin, and can be created without leaving the event you are writing.** There was no way to add one from the portal at all. The event editor offered a picker of existing organizers and nothing else, and creating a new one meant the WordPress term screen, which 3.27.0 unlisted from the menu and left reachable only by URL. So a manager setting up an event for a new programme was stuck. Same reasoning that moved venues in 3.13.0: this is event management, not site configuration, and it belongs where the work happens.

**What an organizer actually carries is name, slug and description, and nothing else.** There is no term meta on `uc_organizer` at all, which makes it the simplest of the four taxonomies: categories carry a colour and an icon, venues four address parts, series an image and a default FAQ set. It appears publicly in five places, and the screen was designed against that list rather than against a guess: **Hosted by** on the event page; the byline on a card, but only when the event is not imported, since an imported event names its platform instead; the organizer filter on the public calendar and in both generators; `schema.org/Event` `organizer` in the JSON-LD a search engine reads; and the `/event-organizer/<slug>/` archive. It is also searchable and travels in the satellite feed payload.

**The taxonomy itself is untouched, and the test asserts that rather than trusting it.** `SFAF_Organizers` is a wrapper and registers nothing. Public, the `event-organizer` rewrite, `show_in_rest` and the WordPress fallback screen all stay exactly as they were, because embed blocks scoped by organizer carry the SLUG and those blocks are HTML on sites this plugin does not control. Planting a change to `public` and a change to the rewrite slug both failed the build.

**A rename never moves the slug**, and that is the rule on this screen that is not obvious. WordPress is happy to leave a slug alone when a name changes, and that is what is wanted: an embed block can be scoped `organizer="the-stonewall-project"` and that string is the whole of the link between the two sites. Renaming the display name is safe and is what somebody usually means. Changing the slug is a different act with a consequence this plugin cannot see, so it is offered as its own field with its own warning rather than derived from the name on every save.

**Deletion is ALLOWED, which is the category rule and not the venue rule, and the difference is real rather than stylistic.** A venue REFUSES deletion while events are held there because an event keeps no address of its own: the term is the only record of where it happens, and deleting it leaves the event with nowhere to be. A category ALLOWS it because the event keeps its date, time and location and is merely uncategorised. An organizer is the second kind. Every fact about the event survives and what is lost is a byline, so deletion is allowed.

The confirmation names the count either way, which is what makes an allowed deletion honest rather than merely permitted. It also names the one thing the category case does not have: an embed block on another site filtered by that organizer stops showing events, and this plugin cannot enumerate those blocks because they are HTML on somebody else's pages. That is not a reason to refuse, since refusing would make the screen useless for its main purpose, but it is a reason to say so. **The count comes from a query rather than from the term's own `count`**, because WordPress counts only published posts and a manager with three drafts against an organizer would be told "0 events" and then surprised by what the confirmation said.

**The list is plain text with actions, not a page of permanent inputs**, which is the shape Teams took in 3.20.0 and for the same reason: a screen made of live form fields invites an accidental edit on every visit and gives no reading of what is actually there. Editing is a disclosure per row, opened deliberately, and creating is a separate collapsed control rather than a form standing open at the top pushing the list down for something most visits do not need. Its own sidebar entry rather than folded into "Series & Categories": those two are grouped because a category is a property of a series' events, an organizer is not a property of either, and burying it inside a heading naming two other things is how it stays unfindable.

**Adding one from the event editor is a FIELD, not a button that posts.** The friction was never only the missing screen. It was that setting up an event for a new programme meant abandoning a half-typed event, going somewhere else, and coming back to start again. So `organizer_new` is an input on the event form, and the term is created inside the ordinary save in the same request: no second submit, no redirect, nothing typed is lost, and nothing happens at all if the box is left empty. That is deliberately not the shape of the FAQ set control before 3.3.0, which applied by posting and redirecting, discarded every unsaved edit on the screen, and taught people not to press it. It also needs no script and no ajax route, and a route would have been a new surface to gate.

A name that already exists returns the existing term rather than an error, which is what makes that path safe: somebody typing a name that is already there means "use that one", and failing their whole event save over it would be a poor trade while creating a second term with a `-2` slug would be worse. The select wins when both are filled, because an explicit choice from the list beats a leftover in a text box.

**Imported events are unaffected, and structurally so.** `organizer` is on `manager_fields()` for both the Eventbrite and GoFundMe Pro adapters, so no fetch has ever written it and none can. A platform's organizer is its own record rather than a term in this taxonomy, and guessing a mapping between the two would create duplicate terms nobody asked for. The test asserts it stays a manager field on both.

**Ten faults planted and all ten caught by name**: a rename re-deriving the slug, deletion refused instead of allowed, deletion no longer reporting its count, a duplicate name creating a second term, the count falling back to the term's published-only figure, the taxonomy losing `public`, the rewrite slug changed, the add-one control becoming a form with its own submit, the typed name overriding an explicit choice, and organizer dropping off `manager_fields()`.

Two guards from earlier releases fired unprompted, which is what they are for. The 3.35.0 access whitelist failed the build until `save_organizer` and `delete_organizer` were added deliberately, as calendar-wide `can_view_all` actions rather than the per-event gate, since an organizer is not owned by any one event. The private-events whitelist failed until `SFAF_Organizers::events_using()` was listed with its reason: it must count private events, because the deletion confirmation would otherwise understate what it affects and hiding a private event from the person running it is the failure that feature must not have.

One thing found and fixed rather than shipped: the "See events" link first pointed at `events?organizer=N`, and the events list has no organizer filter, so it would have quietly listed everything while looking like it worked. It goes through the search instead, which already covers the `uc_organizer` taxonomy by term name.

VERIFIED: 54 PHP files parse under PHP 8.3; the callable audit resolves 112 plugin functions across 37 files and 33 classes with nothing unresolved, self-test passing; the whole `.claude` suite runs green, 16 PHP harnesses plus the JS panel test, the guard test and two self-tests; ten planted faults each caught by name; three stylesheets balance. Zip built with bsdtar, extracted, diffed file by file against the tree, and both the linter and the callable audit re-run from the extract.
= 3.36.0 =

**Somebody could register for a session, have it moved to a different day, and never be told. Deleting an event with registrations stranded those people silently. Both are closed.**

**Cancelled is a state an event is in, not a deletion.** An event that is not happening still has to exist, because somebody registered for it and that registration is the record that they did. The organizer chooses what a cancelled event does on the public calendar: stay, clearly marked, or come off it. **Staying is the default**, and it is the better answer: somebody who registered may come looking, and an event that has simply vanished tells them nothing at all. Either way it stays in caladmin, keeps every registration, and takes no new ones.

**It is post meta, not a post status, and that is a decision rather than a shortcut.** The obvious shape is a `cancelled` status beside `publish`. It is the wrong one here for the reason `SFAF_Sources` already documents in another context: this plugin names `post_status => 'publish'` BY HAND in the shortcodes, the embed payload, the REST feed, the .ics, the reminder query, the summary query and the series listings. A new status is invisible to every one of those until each is found and changed, and the failure mode of missing one is an event that is cancelled everywhere except the place nobody checked. A meta flag inverts that: nothing changes about which queries return the event, so nothing silently drops it, and the two places that must behave differently ask. The organizer's stay-or-hide choice needs a second field anyway, which settles it, since a status cannot carry both without becoming two statuses.

**The reminder and the summary would both have gone out, and no query could have stopped them.** Both select on `post_status => 'publish'` and today's date, and a cancelled event has both. So the exclusion is an explicit first line in each loop, asking one shared method so the two jobs cannot come to different conclusions about the same event, and the test asserts it is present in both files AND that it runs before the other per-event checks. "Your event is today" for an event that is not happening is the worst message this plugin could send.

**Deleting is not a way around cancelling.** Deleting an event with registrations is refused, and the refusal links straight to cancelling rather than merely demanding it. Deleting a series whose events have registrations offers to cancel them all instead, with the same stay-or-hide choice and the same question about emailing. Once cancelled and the registrants told, a cancelled event or series can be deleted: cancel, notify, then delete. Deleting an event with nobody registered is unchanged.

**The prompt appears only when there is somebody to tell.** With nobody registered it is a click in the way, so the whole block is absent rather than present and disabled. It triggers on cancelling, on deleting per the above, and on a change to the DATE, the TIME or the LOCATION. Nothing else: description, category, series, capacity and image do not change whether somebody turns up, and a notification that goes out for those is one that gets filtered, taking the date change with it. What is compared is the FORMATTED value, so storing `18:00` as `6:00 pm` is not a change anybody is told about.

**It is a question, and there is no default.** Until 3.42.0 this was a checkbox, ticked, on the reasoning that somebody changing a date is thinking about the date rather than about who needs telling. That was the wrong way round: it made the irreversible outcome the one that happened when nobody was paying attention. The question is now put at the moment of saving, offering "Save and email them" and "Save without telling them", and neither happens by inaction. Nothing is sent unless one of them is pressed, so a form posted with scripting off, or by anything that is not this screen, tells nobody. Because of that, the screen the save lands on says which of the two happened.

**In the bulk case it asks once, and one person gets one email.** Changing a recurrence pattern across upcoming occurrences can touch twelve dates at once. The prompt names the total across all of them, not one per event, and counts DISTINCT PEOPLE as well as registrations, because twelve registrations across six moved dates may be four people and that is the number somebody needs before pressing send. `SFAF_Announce` then gathers every affected event, resolves every registrant across all of them, groups by ADDRESS and sends once: somebody registered for six of twelve occurrences gets one email listing six dates, not six emails. The address is the grouping key for the same reason it is the deduplication key in the reminder list, since one person may hold two registrations under two different names.

**Two messages, and they are different.** CANCELLED names the event, its date and time, says plainly it is cancelled, and carries **no cancel link**: there is no place to release, and offering one would read as though something were still required of them. CHANGED names WHAT MOVED, old value to new value, for whichever of date, time or location changed, because a registrant should not have to remember what it was before in order to work out what is different. It carries the full new details, a corrected Add to calendar pair, and **the cancel link**, since somebody who cannot make the new time should be able to release their place in one click. Both use the existing banner, the same table markup for Outlook, a plain text alternative, AP dates and times through the one formatter, and the event's reply-to.

**Teams are not notified**, and neither is the notification list. They were presumably part of the decision.

**A refetch cannot un-cancel an imported event or move it.** Two things would have to go wrong and both are closed. `update_event()` writes an explicit field list and `post_status` is deliberately not among them, so there is no code path from a payload to the cancellation meta at all: a source cannot clear a flag it has no way to address. And a cancelled event is now refused by the refresh outright, which is the part the first lock does not cover, because the source still lists the event and keeps sending a date, a time and a location. Without it a refetch would happily move an event that is not happening and the change detection would have a real diff to report about it.

**What cancelling an imported event does NOT do is cancel it at the source.** Eventbrite and GoFundMe Pro still hold their own copy, people may still be able to register there, and this plugin has no way to know or to stop it. The editor says so where somebody cancels one, because a silent half-cancellation is worse than a refusal.

**Registration records survive all of it**, with the event title snapshot, and private events cancel exactly like any other with their registrants told the same way.

**Nine faults planted, and the ninth found a hole in the test rather than in the code.** The reminder guard removed, the summary guard removed, the cancelled check reordered after the imported check, the refetch allowed through, the exclusion clause keyed on cancellation instead of visibility (which would hide every event meant to stay listed), a cancel link added to the cancellation email, the changed email reduced to only the new value, and one-email-per-date instead of per-person were all caught by name. The ninth removed `status = 'confirmed'` from the registrant query, and nothing failed: the `$wpdb` stub was applying that filter ITSELF, whatever the SQL said, so the rule was being enforced by the scaffolding rather than by the code under test. The stub now reads the query it is given, and the plant is caught.

The access whitelist from 3.35.0 did its job unprompted: `POST:cancel_event` failed the build until it was added deliberately, with the event gate, so a team member who may edit an event may also cancel it. The date sweep caught this release's own test stub spelling out the house date format.

VERIFIED: 52 PHP files parse under PHP 8.3; the callable audit resolves 112 plugin functions across 36 files and 32 classes with nothing unresolved, self-test passing; the whole `.claude` suite runs green, 15 PHP harnesses plus the JS panel test, the guard test and two self-tests; nine planted faults each caught by name; three stylesheets balance. Zip built with bsdtar, extracted, diffed file by file against the tree, and both the linter and the callable audit re-run from the extract.
= 3.35.0 =

**Teams now decide who can edit an event, not only who hears about it.** An event has an ORGANIZER, which is the person who created it and is `post_author` and nothing else: no second field, no snapshot, and it never changes implicitly. The organizer can always edit it and is always notified. An event with no team is editable by its organizer and calendar admins and nobody else. An event may name up to **two teams**, and everybody on either can edit it, see its details and read its registrations. Membership is **live**: joining a team grants access to every event that team already owns, including ones created long before, and leaving removes it, both without touching a single event. That is the same resolve-at-read-time rule teams already followed for notifications, and it is why a team is a set of user ids rather than a snapshot of anything.

**The access teams are a different meta key from the notification teams, permanently.** Reusing `_uc_notify_teams` would have made this a RETROACTIVE permission change: every event that had ever named a team so it would be emailed would have granted that team edit access the moment this version was activated, on data entered when the field meant something else, with nobody asked and nothing anywhere saying so. Access lives in `_uc_event_teams` and starts empty on every existing event.

**Assigning a team grants access and nothing else.** A team generally wants to log in and look at who has registered, not receive an email per registration. So a separate checkbox, **off by default**, also adds the team to the notification list, and everything else about that list is unchanged. `SFAF_Teams::events_using()` now consults both keys, because it is what refuses to delete a team that is in use: asking only about the notification key would have let somebody delete the team that was the only thing granting a group access to its events, silently, with the deletion reporting success.

**One function answers "may this person edit this event", and every route asks it.** Five defects on this project have been a permission check in the wrong place or absent, and four of the five were routes that HAD a gate. `SFAF_Portal::user_can_edit_event()` resolves in one order: admin or editor first (which asks `manage_options` first, so a calendar record can never reduce a WordPress administrator, the 3.7.0 fix), then the organizer, then a team that owns the event.

**A team member must have calendar access before team access counts, and that order is the whole of it.** A team may legitimately hold somebody with no calendar record: the `$offered` guarantee keeps them, and access may have been withdrawn while membership stayed. Answering yes for them would be worse than useless, because they cannot pass the portal's entrance gate, so every link the answer produces opens a "Denied" page. The pre-event summary asks exactly this question to decide whether to send such a link. That is defect five in `PROJECT.md` §5, rebuilt out of new parts, and it is asserted by name in the test.

**Registrations follow the same gate, scoped to the event.** `/caladmin/rsvps?event_id=N` and its CSV export now ask the event gate; unscoped, both stay on `can_view_all`, because "every registration on this site" is not a question about any event and no team owns it. The export asks the same question from the same query string as the screen, which is the thing `uc_export_rsvps` (defect three) got wrong: a download whose gate had drifted from its screen's. This also widens the scoped view to a contributor reading their OWN event's registrations, which it did not do before. Deliberate rather than incidental: the alternative is a second rule saying a team member may read an event's registrations but the person responsible for it may not, and a second rule is what this release exists to remove.

**Seeing is still not editing.** The events list shows everything through the existing All events toggle, drawn by `public_events_table()`, which has no code path that can emit a registration count, a link or a form. What changed is the narrow query: for somebody who is not an admin or editor, "My events" and the dashboard now mean their own events PLUS every event their teams own, which is exactly the set carrying edit controls. `author` and `post__in` AND together in WP_Query rather than OR, so that widening is a `posts_where` installed for one query and removed immediately, the same shape as the ordering filter beside it. "My events" for an admin or an editor is still a literal author filter, because that is a label a person reads.

**An event whose organizer has gone had nobody responsible for it and nothing said so.** Two defences, because the two ways it happens are different. Removing somebody on Users and Permissions now **refuses to finish** while they organize anything: it names the count, lists the events, and asks for a new organizer, who must be somebody with calendar access. It refuses rather than reassigning by itself, because picking who is responsible for a piece of work is a judgement and a default would be wrong often enough to matter. Same shape as refusing to delete a team that is in use.

The normal way staff leave is not that path. It is WordPress's own Users > Delete, which knows nothing about this plugin. `SFAF_Orphans` runs once a day from the ordinary runner and emails every calendar admin, naming the events and linking to each. **It alerts on change, not on state**: a message goes out when the set of orphaned events differs from the set last reported, and never otherwise. The same list every morning is noise, noise gets filtered, and then the one that mattered is filtered too. Four days of the same three events is one email; a fourth appearing on day five is a second naming all four; everything being fixed is a third saying so. A daily check rather than a `user_deleted` hook, because a hook fires once at a moment nobody is watching, cannot be retried if the mail fails, and cannot see the second cause at all: a calendar record withdrawn while the WordPress account stays.

**"Admin (fixed)" is now "Admin (WP Admin)".** Fixed reads as though something had been corrected, and said nothing about why it cannot be changed there. Naming the thing that decides it answers both. **What each access level can do** is now readable on the Users screen, in the 3.10.0 disclosure pattern: a `<summary>`, so click, tap, Enter and Space all work and it still opens if `portal.js` never runs. Not hover, which has no keyboard equivalent, no touch equivalent, and no way to read the contents at leisure.

**The test is a whitelist, in the shape private events uses.** `.claude/event-access-test.php` names all 43 routes that read or write an event or its registrations together with the gate each is allowed to use, and fails in both directions: a route in the dispatcher that is not on the list, and a list entry whose route has gone. A route added tomorrow is caught by DEFAULT rather than by somebody remembering. It also sweeps for a `post_author` comparison written anywhere outside the gate, which is how a second and quietly different answer gets in. The gate itself is not re-implemented in the test: the three methods are extracted from the real source and the extraction is asserted, so it cannot drift into testing a copy of a rule that has since changed.

**Nine faults planted, and the two that were not caught were the useful ones.** A contributor reaching another team's event, a team member with no calendar record getting access, a deleted team still granting access, the CSV export losing its per-event gate, a second `post_author` rule appearing, and a new POST route arriving unwhitelisted were all caught by name. Two were not, and neither was a hole in the code. One was a no-op plant: deleting an early `return false` on an empty array, which the loop below it already handled, so there was no fault to catch. The other removed one of two guards against a deleted team and the second guard held, which is defence in depth working and a plant proving nothing. Both were rewritten to be real, both are caught, and the test grew a direct assertion on `access_for_event()` so the two guards are now checked independently rather than only through their combined outcome.

`.claude/route-gate-inventory.php` is a companion that never fails: it prints what every route requires, for a person deciding whether a gate is the RIGHT one. No script can answer that. Its own first cut matched every `case` in a 10,000 line file and reported 53 POST routes where there are 31, the extra 22 being field renderers, recurrence patterns and screen slugs, so it now slices the two real dispatchers by brace-counting and refuses to run if it cannot find them.

VERIFIED: 49 PHP files parse under PHP 8.3; the callable audit resolves 112 plugin functions across 34 files and 30 classes with nothing unresolved, self-test passing; the whole `.claude` suite runs green, 13 PHP harnesses plus the JS panel test and the guard test; the access test covers the gate against every combination of role, organizer and membership including live join and leave, team removal, two teams, a ghost team id and the 3.7.0 rule; 43 routes whitelisted and checked in both directions; nine faults planted and every real one caught by name; three stylesheets balance. Zip built with bsdtar, extracted, diffed file by file against the tree, and both the linter and the callable audit re-run from the extract.
= 3.34.0 =

**An external scheduler is now the documented setup, the readme says which URL and in what order, and the runner fires every 15 minutes so that ping does something.** Reminders have never depended on anything but somebody visiting a page, and the plan is cron-jobs.org requesting a URL every quarter of an hour. Two candidate URLs behave very differently and only one is right. `wp-cron.php?doing_wp_cron` dispatches WordPress's WHOLE schedule: core's maintenance, every other plugin's jobs, and this plugin's among them. The admin-ajax endpoint built in 3.25.0 calls `SFAF_Cron::run()` directly, so it runs this plugin's jobs and nothing else, and once `DISABLE_WP_CRON` is set nothing else is dispatching that queue: pointing the scheduler at it would keep the calendar working and quietly stop everything else on the site. It also throttles itself to one run per 15 minutes and answers 204 inside that window, so an external 15-minute ping lands on the boundary and is turned away about half the time. `wp-cron.php` needs no parameter, no header and no key, and it never consults `DISABLE_WP_CRON`: that constant only suppresses the spawn from ordinary page loads.

Pinging it every 15 minutes was going to be **most of a no-op anyway**, and that is the part nobody had noticed. wp-cron.php dispatches events that are DUE, and `sfaf_cron_hourly` was scheduled hourly, so three of every four pings would have found nothing to run and the two-hour pre-event summary would still have been up to an hour late. The hook now runs on a registered 15-minute recurrence, which is the number the page-view nudge has used since 3.25.0 for exactly the same reason. Sites installed before this migrate on the next request: `wp_get_schedule()` is asked what the hook is actually on, and anything that is not ours is cleared and re-laid, because "is it scheduled?" would have found the hourly event present and left it there forever.

The interval is registered from **file scope in the main plugin file, not from `SFAF_Cron::register()`**, and that placement is load-bearing. Core hangs `wp_cron()` on `init` at priority 10 and adds it in default-filters.php long before this plugin adds its own `init` callback at the same priority, so wp_cron() runs first. A recurrence it cannot find is one it responds to by **unscheduling the event**, so registering the filter any later would have had the runner delete itself, silently, on the first request after the update.

The readme's advice to set up a cPanel cron job is gone rather than left beside the new advice, because two recommendations mean somebody follows the wrong one. It is replaced by an ordered a/b/c: create the ping, confirm from the Automation screen that tasks are running, and only then set `DISABLE_WP_CRON`. That order is the whole point. Setting the constant first, with a mistyped URL, leaves a site where nothing runs at all and nothing anywhere says so.

**The Automation screen can now answer "is this working?", which it could not.** It opened with a Status table whose first row was a health sentence and whose remaining rows were configuration, and there was no way to see when a job last ran or when it is next due. Two people need that: somebody who has just pointed a scheduler at this site and wants to know whether it worked, and somebody six months later wondering why reminders stopped. Neither knows or should need to know what cron is.

So the screen opens with a banner that answers it in a sentence, in one of four states. Working, running late (nothing completed for 45 minutes), stopped (nothing for three hours, or three failed runs in a row), and never run. The state is in the headline text as well as in the colour and a 4px bar, on the 3.20.0 reasoning: a banner that says "working" only by being green says nothing to a reader who cannot separate the greens, and this is the one thing on the screen somebody came to read. Under it, a **Scheduled tasks** table: each job, a sentence saying what it does, when it last actually ran, when it is next due, and what happened. "Last ran" means the last time the job DID something rather than the last time it was passed over, so a job that has been switched off still reports the last real run.

The forty-five minutes and the three hours are deliberately different numbers. Three hours decides when to wake somebody by email, so it has to be long enough that one hiccup at the pinger does not send one. Forty-five minutes decides what to say to somebody already looking at the screen, where being told early costs nothing.

Three jobs were three hand-written lines inside `run()` while the screen described two of them in prose. They are now **one list**, `SFAF_Cron::tasks()`, that both the runner and the screen iterate, so a job cannot be run without appearing on the screen and cannot appear without being run. The Status table lost the two rows that now duplicated it.

**The cron alert emails still work when cron is externally driven, and the one hole in them is now written down.** The health check hangs off `wp_loaded`, not off the runner, which was already the right call: anything hanging off the thing being monitored is a monitor that works right up until it is needed. `wp_loaded` fires on a wp-cron.php request too, so the external scheduler drives the check as well as the work, and a failing run or a broken task is reported exactly as before. What it cannot catch is the trigger dying while nobody visits: no request means no `wp_loaded`, which means no check and no email, and no monitor inside a site can report that the site is not being visited. **The page-view nudge is what closes that in practice** and is now documented as a reason to keep it rather than something to turn off once a real scheduler exists: it is a request from sfaf.org, which has traffic this site does not, and it arrives whether or not cron-jobs.org is alive.

**The Automation screen's headings and controls were rendering against the card border, and the reason was that no rule existed at all.** `.uc-admin-card` declared a background, a border and a radius and no padding, so a card looked correct only when every child inside it happened to carry padding of its own. A table does, through its cells. The Calendar Users explainer, search bar and pager do, through rules written for each of them. Headings, paragraphs and forms carry none, and Automation's cards are made of exactly those.

That is the 3.16.0 shape, styled by accident of the container, rather than the 3.20.0 one, a correct rule losing a cascade fight, and the `-actions` baseline written in 3.20.0 cannot help: that convention lives in `portal.css` and reaches no WordPress admin screen. **Which screens had no rule: Automation, all four of its cards, 14 elements. Calendar Users, whose one card also has no padding and only looks right because each of its five children carries its own.** The other five cards, on the shortcode generator and the embed screens, each declared padding under a name of their own, which is five copies of one decision at three different values.

The card now owns its padding at a single 20px gutter, the five per-card copies are deleted in favour of it, and the four full-bleed children name themselves and pull back out to the edge. Table cells moved from a 16px gutter to the same 20px, so a heading, a table cell and a search bar all start on one vertical line. `.claude/admin-padding-audit.php` is the committed check: it reads the renderers, finds every card, lists what each renders directly inside it and asks the stylesheet whether the card or that child declares horizontal padding. Its first cut counted only `<div>` for depth and reported forty problems, because every `<span>` in a table cell and every control inside a padded `<form>` came back as a direct child. Half of what it found was not there. It counts every container now and reports 14, and it refuses to run at all if it finds fewer than three cards, because an audit that silently matches nothing reports a clean result.

**"Add to Google Calendar" and "Add to Apple or Outlook" wrapped to three lines and two, so a matched pair of buttons rendered at different heights.** Fixed at the root rather than by forcing equal heights: the words that wrapped are the words both buttons shared, so they are said once as an **Add to calendar** heading above the pair, and each button carries only what tells it apart. **Google** and **Apple or Outlook**, one line each. The geometry stopped depending on the labels as well, so a longer one later cannot bring the fault back: the cells are `width="50%"` as an attribute (Word ignores a CSS percentage) and each button fills its cell.

Each carries a small calendar glyph, and it is **the plugin's own mark, not a platform logo**. Google, Apple and Outlook are registered trademarks with published brand terms, SFAF is a nonprofit with a brand guide of its own, and nothing here has been cleared to reproduce them. One generic glyph on both says the same thing and asks nobody's permission. An inline SVG does not render in Gmail, Outlook or Yahoo, so it is a raster: `.claude/build-email-icons.js` rasterises the same path data `sfaf_icon()` draws from, at 32px for a 16px slot, in the two button foregrounds, because an email has no `currentColor` to inherit.

**How it degrades with images off,** which is the default in many clients: the glyph carries `alt=""` and is therefore decorative, so a client that blocks it shows an empty 16px box and the button reads **Google**. The glyph is never the only thing carrying a meaning, so nothing is lost but the decoration. `width` and `height` are attributes as well as styles, so a blocked image reserves exactly 16px and both buttons stay the same height whether or not pictures loaded. A Unicode calendar character was the other candidate and was rejected: it can never be blocked, which is a real advantage, but it is the reader's emoji font rather than this plugin's icon language, so it arrives as a different mark in every client and as a colour picture beside a brand-coloured label.

The email render test grew a section for all of it, and the images-off claim is checked by **actually stripping the images and looking again** rather than by reasoning about alt text. Four faults were planted to prove the checks can fail: the long label returning, a button that does not fill its cell, a vendor logo in an image source, and a glyph carrying alt text instead of being decorative. Each was caught, by name. The platform-logo check is a whitelist of the three image files this plugin ships, so a vendor mark added later is caught by default rather than missed by default.

VERIFIED: 46 PHP files parse under PHP 8.3; the callable audit resolves 112 plugin functions across 33 files and 29 classes with nothing unresolved, and its own self-test finds every planted fault; 5 plugin scripts pass `node --check`; the whole `.claude` suite runs green, 12 PHP harnesses plus the JS panel test, the guard test and two self-tests; the admin padding audit goes from 14 elements against the border to 0; the browser parity test still matches element for element at 770px and 1000px; eight faults planted in total, four on the email checks and four on the cron ones, and the ninth found a hole in the test rather than in the code (two runs stamped in the same second made a "last ran" assertion pass either way, now back-dated); the confirmation email rendered in a browser and looked at. Zip built with bsdtar, extracted, diffed file by file against the tree, and both the linter and the callable audit re-run from the extract.

= 3.33.0 =

**A private event's old public address was still working, and it redirected to the secret one.** Confirmed on the live site in both directions. WordPress hooks `wp_check_for_changed_slugs()` to `post_updated`: when a published, non-hierarchical post's slug changes, the previous slug is kept as `_wp_old_slug`, and `wp_old_slug_redirect()` then 301s any 404 matching one of those to the post's current address. `uc_event` qualifies on every count, and nothing here had ever accounted for it. So ticking Private moved the event to its token and left `/events/donor-reception` answering, redirecting to the token, handing the secret address to anybody who tried the old one, in a Location header, as a permanent redirect that browsers and proxies cache. A crawler holding the old URL followed it to the new one.

**The same mechanism is correct in the other direction and is kept.** Making an event public again restores the readable address and core retains the token, so every link already sent to a donor keeps working and lands on the right page. That half was never broken and is now asserted rather than accidental.

**So the retained addresses are deleted in `randomize_slug()`, not in `set()`.** Every caller of `randomize_slug()` is by definition making an address unguessable, so keeping the previous one contradicts that everywhere, and putting the delete there also covers the occurrence path, which `set()` never touches. Clearing it in `set()` would run on the way back to public too and destroy the working links. The delete runs after the update rather than before, because the update is what creates the row.

**A second mechanism that does not depend on that write.** `old_slug_redirect_post_id` is filtered and returns 0 when the resolved post is private, so an old slug that exists by some other route, a slug edited by hand in the WordPress editor or a row restored from a backup, still cannot reach a private event. Same belt-to-the-braces reasoning as the two Yoast sitemap mechanisms. It is scoped to `uc_event`: that filter is global and every page on the site relies on the redirect working.

**An occurrence could be walked to from the seed's own link, which is the exact attack the independent tokens exist to prevent.** Occurrences were inserted at `{seed-slug}-{date}` and randomized a moment later. For a private seed the seed's slug is its token, so each date was briefly at `{seed-token}-2026-09-19`, and core kept that as an old slug: being sent one link handed you every other date by editing the date on the end of the URL. The slug is now decided before the insert by `SFAF_Privacy::occurrence_slug()`, from the address the seed would have if it were public, so no guessable address is ever the post's name and there is nothing to retain.

**A date made public again lands on words rather than staying a token forever.** Occurrences never recorded a readable address, because they were randomized directly rather than through `set()`. Each now stores the address it would have had. For an occurrence generated before this release there is a fallback: the title, plus the date when the event is in a recurrence group, because twelve occurrences share a title and would otherwise become `donor-reception-2`, `-3`, `-4`, which is a worse address than the date it actually is.

**Privacy follows the edit scope, like every other field on the form.** It used to touch only the row it was ticked on while its own label promised it hid the event everywhere, so a weekly reception made private left every other date public. The scope modal already asks "this event" or "all upcoming occurrences" and every other field respects the answer. It cannot be a plain meta copy, because copying `_uc_private` without replacing the target's slug produces an event claiming to be private at a guessable address, so it goes through `SFAF_Privacy::set()` per target, each keeping its own remembered address. Past occurrences are never touched, exactly as with every other bulk edit. No new label and no new question.

**The test that passed while all of this was broken is the lesson.** `.claude/private-events-test.php` asserted the round trip correctly and stubbed `wp_update_post()`, so it could never fire `post_updated` and the entire mechanism that decides what an old address does was invisible to it. It now models `wp_check_for_changed_slugs()`, `wp_old_slug_redirect()` and a real hook registry, so the filter is called rather than assumed, and it asserts both directions and the enumeration case. `.claude/private-slug-fault-check.sh` plants four faults in the real source and confirms each one fails: the first attempt planted the second fault before `wp_update_post()` instead of after and reported a fault it could not see, which is precisely the failure the file exists to catch.

= 3.32.0 =

**The combined mode's right-hand column is the sidebar display mode, not the card list.** Same compact rows: a 44px thumbnail, the title, and one quiet line carrying the date and time. Same heading, same fixed number of upcoming dates, same "See all events" link at the foot. It is `render_sidebar()`, the method the sidebar mode returns, so there is no third rendering of anything.

**Why.** A list card is the main content of a page at full width: a 16/9 photograph, a title, an excerpt, three lines of meta and a footer with a button, measured at 688px of height each. Beside a month grid that is one and a half events in view, and it read as heavy and unfinished. The sidebar row was designed for exactly this column and is already correct at this width because it is already shipped at this width.

**This removed code rather than adding it.** The combined mode no longer builds a card list at all, so it does not pay for twelve cards it then throws away; it asks the query for the total and no markup. No pagination, no "load more", no height cap and no scroll region anywhere in the mode. The right panel exists in one mode and is visible in it, so it carries no `hidden` attribute and there is no question for the view code to get wrong. The count of upcoming dates is resolved in one helper that both the sidebar mode and this one call.

**The changeover moves from 920px to 888px**, and only because the column did. It is still the two flex bases plus the gap: 576 for the grid, now 288 for the sidebar rather than 320 for the list, plus 24. Both bases are breakpoints plus headroom on the same principle, that going side by side must never be worse than stacking: 576 clears the 560px where the grid collapses to dots, and 288 clears the 272px where the sidebar row stops putting its thumbnail beside the text. The panel is capped at 380px, where the sidebar caps itself, so surplus width goes to the grid instead of into a gap. At the 1200px cap: sidebar 380px, grid 796px.

**On sfaf.org nothing about the stacking changes.** That template is locked at about 770px, which is below 888 as it was below 920, so the mode stacks there and always will: the month grid above, the sidebar card beneath it at its own 380px width. The two-column layout needs 888px and is for wider hosts.

**The parity test now compares the right panel against the sidebar mode.** `.claude/combined-panel-parity.php` renders one sidebar through `render_sidebar()` and puts that same string in a combined block and standalone, resizing the standalone host to the width the panel actually measured, at 770px stacked and 1000px side by side. It compares all 105 elements per panel for identity, computed display, rendered box **and typography**: nesting `.uc-sidebar` inside `.uc-calendar` exposes it to every `.uc-calendar`-scoped rule in the stylesheet, and a rule that repaints a heading changes nothing about its size. Result: no difference of any kind at either width. Planting a colour that only differs because of the nesting fails it, which is how that check was verified.

**The placeholder tile names its category again.** An event with no image showed a tile reading "Event" rather than its category name, on every list card in every mode, and raised an undefined-variable notice for each one. `sfaf_list_card_media()` took the name as an argument and its only caller passed a variable that was never assigned. The name is resolved inside the helper now, from `sfaf_event_primary_category()`, the same way `sfaf_thumb_media()` and `sfaf_day_event_thumb()` already did it; the argument stays for a caller that knows better and no longer has to be supplied. About half of imported events never get an image, so this is the tile most visitors see most often, and 3.31.0's readme has been claiming it carried the category name since it shipped. "Event" is now only for an event in no category at all, which is what it was always meant to cover.

= 3.31.2 =

**Every card in the combined mode's list panel was a 40px strip.** A category chip, a day abbreviation clipped against the right edge, and no title, no image, no meta and no button. The same cards in list mode on the same page at the same width were correct, which is the whole signature of the fault: the markup was identical and the layout was not.

**The cause was a height cap, and it needed three properties to line up.** 3.30.0 gave the panel's list `max-height: 640px; overflow-y: auto`, so that a long list would scroll inside its own column rather than make the row as tall as the longest of the two panels. But `.uc-event-list` is a column flex container; a flex item's `flex-shrink` defaults to 1, so a container with a definite max-height takes its negative free space out of the items instead of overflowing; and a flex item's automatic minimum size, which would have floored each card at its content height, is not applied when the item's own `overflow` is anything but `visible`. `.uc-event-card` is `overflow: hidden`, for the border radius over the image. So the floor was zero and twelve cards were squashed to (640 - 11 x 14) / 12 = 40.5px each, with every part still in the DOM, still the right size, and clipped out of the box.

**The cap is gone rather than fixed.** `flex-shrink: 0` on the cards would have kept it and rendered the cards correctly, and it is still wrong: the cap's purpose only exists when the two panels are side by side, and no rule here can tell that apart from stacked, where the panel is the full block width. sfaf.org's template is locked at about 770px, so that page always stacks, and the cap there put twelve cards into a 640px scroller showing two of them. In a mode whose entire premise is composing the two existing renderers, a divergence from list mode is a bug by definition. Removed, the row is as tall as the list and the grid sits at the top of its own column, which is what a two-column layout does.

`flex-shrink: 0` on the list's children stays, as a guard rather than the fix. The `overflow: hidden` that disabled the cards' automatic minimum size was added for a rounded corner, and any height constraint above the list, ours or a host theme's, would squash them again identically and just as quietly. Declared, a constrained list overflows where it can be seen.

**A rendered comparison, because the two source tests could not see this.** `.claude/combined-card-parity.php` stubs enough WordPress to call the card renderer for real, writes a page holding the same twelve cards in a list-mode block and a combined-mode block at 770px, and compares all 396 elements: identity, computed display, rendered box, and whether anything is clipped out of its own card. Confirmed at 770px: 396 elements, no difference of any kind, first card 688px in both. The earlier tests both pass while this shipped, and both now say in their own output which question they do not answer.

**Does the mode earn its place at 770px?** Honestly: only partly, and Mark should know it. sfaf.org's template cannot be widened, so the combined mode there will always stack, and stacked it is the month grid above the card list with no toggle between them. That is not nothing: both views are on the page at once, so nobody has to switch to see what is coming up after looking at a date, and there is no state to lose. But the thing the mode was designed for, scanning a month beside a column of detail, needs 920px and is not available on that page. On sfaf.org, choosing combined over calendar-with-the-toggle buys "both, always open, one above the other" and nothing else. On a wider host it is the two-column layout.

= 3.31.1 =

**The combined mode rendered its search box, its filter bar, its count and no events at all.** Two separate faults, one of which made the mode empty at every width and one of which made it useless at the widths sfaf.org uses.

**Both panels were in the document, and the browser hid both of them.** `showView()` in embed.js decided visibility with two comparisons, `list.hidden = (view !== 'list')` beside `cal.hidden = (view !== 'calendar')`. Each is correct for the two views that existed when they were written, and together they hide EVERY panel for any third view. `bindViewToggle()` calls `showView()` on every render to reconcile a remembered choice with what the server sent, `viewFor()` had been taught that 'combined' is a configuration rather than a choice, and so every combined block on a live page hid the grid and the list a frame after they arrived. The server markup was correct throughout, which is why the panels were there to be found in the inspector.

The decision is now one function, `panelHiddenFor( view, panel )`, and its default is to keep a panel rather than hide it: a mode added later renders too much, which somebody reports, instead of rendering nothing, which reads as the calendar being broken. `calendar.js` has the same treatment, where the same trap existed and nothing had reached it. The class rewrite names every mode too, so a combined block no longer ends up wearing `uc-view-combined` twice.

**The grid's flex basis goes from 400px to 576px, which moves the changeover from 744px to 920px.** The month grid drops its cell entries and becomes seven columns of dots with a day panel underneath at 560px of its own column, which is the right treatment for a phone. At the old basis the two panels went side by side from 744px, and from there up to 1064px the grid was handed a column narrower than 560px, so the mode's whole left half was dots. sfaf.org constrains this block to about 770px and the theme is locked: side by side, that is a 413px grid; stacked, it is a 770px grid with its entries. So side by side must never be worse than stacking, and the basis is now the breakpoint plus a 16px step rather than a number chosen for looks.

Both panels still grow equally. Giving the grid two thirds of the surplus gains it about 6px per column and costs the list 47px, which at the 1200px cap puts it under the 420px where its card header starts giving up the date size.

**Two assertions, because the ones already here passed the entire time.** `.claude/embed-modes-test.php` proved the server builds both panels, marks neither hidden, and that embed.js knows 'combined' is not a remembered view. All true, all passing, and none of them the thing that broke. `.claude/embed-combined-panels-test.js` now slices the real view functions out of embed.js, runs them over a block and counts the events a visitor can actually see, so chrome-with-no-events fails by name. The width probe measures the changeover from rendered output at eleven widths including 770px, and fails if going side by side ever costs the grid its entries.

= 3.31.0 =

**The month grid's event card is rebuilt. The coloured accent bar is gone.** It was a 3px stripe down the left edge in the raw category colour, and this project's own audit measured six of the ten hues under the 3:1 that WCAG asks of a non-text graphic on white: Yellow 1.38, Light Gray 1.50, Green 2.02, Teal 2.26, Orange 2.31, Pink 2.99. The densest category surface in the product was the one where the categories could not reliably be told apart, which makes the bar decoration standing in for information it could not carry.

**In its place: a 32px thumbnail of the event image, rounded, with a 2px ring in the category colour.** The ring is the INK from `sfaf_category_shades()`, the darkened half of the contrast-checked pair the chips already use, never the raw hue. Every one of the ten clears 3:1 on both surfaces a ring is drawn on, and the lowest is 5.84 on white. `.claude/category-ring-contrast.php` is committed, prints the table, and fails the build if that stops being true or if the raw colours stop being the wrong choice.

Measured ink on white: Yellow 6.31, Orange 6.74, Red 7.12, Burgundy 7.90, Pink 6.87, Purple 7.09, Teal 6.68, Green 6.60, Light Gray 12.34, Dark Gray 12.34. On the out-of-month cell, 5.84 to 11.41.

**An event with no image shows the category icon on the category's tint, and that is a different composition from the list card's placeholder rather than the same one shrunk.** The list card's tile carries the category NAME beside its icon; at 32 square there is no room for a word, and a scaled-down tile with unreadable text in it is exactly what a broken image looks like. An icon centred on its own tint reads as a deliberate mark: the same icon the category carries everywhere else, in the same colours as its chip, at a size the icon was drawn for.

**The title wraps to two lines and then truncates**, rather than truncating at one. The time sits beneath it, quieter.

**The grid is wider: the cap goes from 900px to 1200px, for the month grid and combined views only.** 900px is right for a column of cards, where a longer line is a worse one; a month grid is seven columns of small blocks and every pixel of column width is room for a title. Still a cap and not a size, so a grid pasted into a 600px column is unaffected.

Measured: column **128.3px to 171.1px** outer, cell content **118.3px to 159.1px**, and once the 32px thumbnail and its 8px gap are taken the title goes from **68.3px to 109.1px**.

**Below 930px of container width the thumbnail is dropped rather than shrunk.** Per column the thumbnail and gap take 40px, the entry's border and padding 10, and the cell's 12, so a narrower column leaves under 70px of title. A smaller thumbnail was the other option and is worse: at 20px the icon stops being legible and the ring stops being a ring, which is the accent bar's fault again in a different shape. The full-width day panel on a phone shows the entry complete, thumbnail included.

**Every event on a day still shows. No cap, no "+2 more".** The cell grows to fit and the week's row grows with it, which has always been true here and is now asserted rather than assumed.

**Each half of the combined mode is now its own query container.** Every container query in the stylesheet names `uc-calendar`, and until the combined mode existed that was the same thing as "the width the content has". Side by side it is not: a 1200px block gives the grid about 628px, so the root answered "wide" while seven columns were being squeezed into 89px each and every narrow rule stayed off. Naming the panels `uc-calendar` as well shadows the root for their own descendants, so each half measures its own column and no query had to be rewritten. The containment trap was checked rather than assumed: both panels size from the flex algorithm using a definite `flex-basis`, and both already carried `min-width: 0`, so the 744px changeover is unmoved.

= 3.30.0 =

**A combined display mode: the month grid and the list, side by side.** A fourth mode alongside list, calendar and sidebar, in the embed generator and as `[sfaf_calendar view="combined"]`.

**It composes the two renderers rather than adding a third**, which is the point of it: a fix to either the month grid or the card list reaches this mode without anybody remembering it exists. `render_calendar_block()` was already building both panels whenever the view toggle was on, so that flipping the toggle would not cost a round trip to another domain; the combined mode shows both instead of hiding one.

**Two views of one filtered set, not one driving the other.** The category bar, the group pills, the search and the block's own scope apply to both, because both are built from the same filters. Clicking a date in the grid does exactly what it has always done and does not touch the list: the list is "what is coming up", and making it follow the grid would take that view away with nothing to replace it.

**No view toggle in this mode**, forced off in the renderer rather than hidden in CSS, so a hand-written shortcode asking for both gets the same answer as the generator and the buttons are not in the markup for a keyboard to find.

**It stacks at 744px, grid above list, and the layout is intrinsic rather than a container query.** `.uc-calendar` is a query container and does carry a definite `min-width`, so a query would in fact have been safe here; `flex-wrap` with declared bases is still the better of the two remedies DESIGN.md offers, because it asks nothing about width and no host layout can defeat it. Both panels carry `min-width: 0`, without which the month table's min-content width would stop the grid panel shrinking and push it through the host's page.

**The grid comes before the list in the DOM, not in CSS.** `order: -1` would have moved what the eye sees and left tab order and screen readers meeting the list first. The two panels are buffered and emitted in the order they are read.

**"Open events to source listing": a checkbox in the embed generator, unchecked by default.** Ticked, an event that has a source URL links straight to it, so a Cycle to Zero block sends visitors to donate.sfaf.org rather than through an event page here. A native event has nowhere else to go and always opens on this site, in the same block, with no setting to explain that.

**It reaches every link that would otherwise point at an event page, in every mode**, because every renderer resolves its destination through one function. The card title, the card image, the View event button, the compact card, the sidebar row and the month grid's day links all ask `sfaf_event_link()`; there is no `get_permalink()` left in the card renderers, and `.claude/embed-modes-test.php` fails if one comes back.

**Carried as a data attribute on the block, with no new REST route.** `is_embed_request()` matches the route string exactly, so a new route would fail CORS from sfaf.org. It is part of the payload cache identity, so two blocks differing only in this are two payloads, and `build_payload()` sets it for the items and month modes as well, without which a snippet would honour the setting on first paint and hand back resources links on page two.

**The link reads as external without becoming noisy:** one small arrow on the source byline, which already names the platform, and nowhere else on the card. The arrow is `aria-hidden` with a spoken sentence beside it.

**The default is one line.** `sfaf_source_links_default()` is the only place it lives: the generator checkbox, the shortcode attribute and every renderer follow from it, and a block that carries no attribute at all follows it forever rather than being frozen at whatever it was when the snippet was pasted. Flipping it later changes every block already on sfaf.org without anybody re-pasting anything.

**The current behaviour is unchanged and is still the default.** A third-party event opens the resources event page, which carries the map, the series dates and add to calendar, and registration hands off to the source. This setting is for the blocks where that page earns nothing.

= 3.29.0 =

**Private events.** See the section above for what it is and every route it covers. One checkbox on the event, off by default, hiding the event from the calendar, the month grid, the sidebar, search, its series page, the series archive, both sitemaps, the embed payload, core's REST collection, the multi-site feed, and the JSON-LD and social tags on its own page. The address becomes 32 random hex characters. Everything on the page works normally for anybody holding the link, including registration and all four emails.

**Privacy is on the event and there is no series-level setting.** Two settings that can contradict each other are worse than one rule: the moment a series says private and an event in it says public, something has to decide which wins and it will be wrong for somebody. A wholly private series is every event in it marked private.

**Each date of a repeating private event gets its own token.** Occurrence slugs are normally `{seed-slug}-{date}`, so inheriting one token would mean that being sent one date hands somebody every other date by editing the URL. One forwarded link is one forwarded link.

**The `.ics` download was the route that almost got missed.** Making the page unguessable does nothing for a second door addressed differently: `?uc_ics=417` is four digits, and walking them would have returned a file carrying the title, date, time and address of every private event on the calendar. A private event's `.ics` now requires its token as well as its id. Add to calendar still works for anybody holding the link, because they reached the page by that token.

**Two independent mechanisms for Yoast**, because neither can be verified from a machine with no Yoast on it. Making an event private stamps Yoast's own noindex meta, and Yoast leaves a noindexed post out of its sitemap without being asked; the event's id is also added to Yoast's sitemap exclusion filter, which covers the case where that write did not happen. Core's sitemap is handled separately and directly.

**`.claude/private-events-test.php` is committed, and it is a whitelist rather than a checklist.** A checklist of routes to hide from is only as complete as the person writing it, and the failure here is a route nobody thought of. So it sweeps every query in the source that builds against `uc_event` and requires each one to either exclude private events or be named, with a reason, as a place a private event belongs: a manager screen, or a mechanism like the reminder runner that must act on an event regardless of who can see it. 29 queries found, 4 exclude, 25 whitelisted, none unaccounted for.

That sweep is per function rather than per file, and it is worth saying why: the first version asked "does this file exclude anywhere", and it passed with the exclusion deleted from the calendar list builder, because the month grid in the same file still had one. The list would have carried private events while the grid beside it did not. Four faults were planted and caught before the test was trusted.

= 3.28.0 =

**The public RSVP REST endpoint is gone.** `POST /sfaf-calendar/v1/rsvp` had `permission_callback => '__return_true'`, which is an unauthenticated public write, and nothing had ever called it: it was built so a satellite site could post registrations back. It had also been broken since 3.26.0, passing `name` where `submit()` requires `first_name`, so every call it ever received would have been refused. That is the proof nobody called it, not the reason it went. It went because it is the fourth thing found behind the wrong gate on this project, after the ungated RSVP screen, the dashboard leaking registrant names and the `uc_export_rsvps` export, and a public write path that nothing uses is not made safe by being broken. Registrations still go through admin-ajax `uc_submit_rsvp`, which checks a nonce.

**Removed, all confirmed uncalled first:** `SFAF_Teams::add_member()` and `remove_member()`; `SFAF_Embed::endpoint_url()`; `SFAF_Series::total_count()`; `SFAF_FAQ_Sets::set_series_default()`; `SFAF_Reminders::recent()`; the `satellite_sites` branch in the settings sanitizer, which had been sanitizing a value no field could produce since before 2.0; the write-only `sfaf_notify_merge_moved` option; the `initSeriesImage()` handlers in `admin.js`, which waited for markup the 3.27.0 screen removals took away; and the `admin.css` rules for `.uc-status`, `.uc-status-confirmed`, `.uc-status-cancelled`, `.uc-status-waitlist`, `.uc-status-subscribed`, `.uc-rsvp-count-bar`, `.uc-rsvp-search`, `.uc-search-input` and `.uc-series-flag`.

The two Teams methods are worth a note. They were a single-user API over a whole-membership store, and `save()` exists precisely because that shape is unsafe here: a form may only speak for the ids it actually showed. Both read the membership, changed one entry and wrote the array back, which is the lost update the `$offered` guarantee was written to prevent.

**The multi-site events feed is marked dormant, not dead, and is kept on purpose.** See the new section above. It is unused as of August 14, 2026, retained for a possible future without a managed host, and it now answers nobody rather than everybody.

**That last part is a reversal.** The feed used to treat "no key configured" as open, so it worked out of the box during setup. That is the wrong default for a feature nobody uses, and it was actively dangerous in combination with retiring the stored key: clearing a credential would have thrown the feed open to the world rather than shutting it. So the gate is inverted, no key means 403, and a one-time upgrade step clears any key left from setup. The two changes are one change and must not be separated.

**Four things that look dead and are not, now say so in the code** so the next sweep does not surface them: the `sfaf_is_embed_context()` branches in `sfaf_reminders_button()`, `sfaf_rsvp_block()` and `sfaf_add_to_calendar()`, all three unreachable only because the embed never renders the single event template and all three live again the moment it does; the legacy meta reads in `sfaf_migrate_notification_lists()`, a permanent no-op here but the upgrade path for any site still below 3.25.0; the legacy branch of `SFAF_Series::resolve()` and `META_LEGACY_ID`, which are a contract with embed snippets published before 3.0.0; and `sfaf_fail_safe()`, which has never caught anything and exists for the release where it does.

= 3.27.1 =

**Series has a WordPress fallback screen again.** 3.27.0 left `uc_series` with `show_ui => false`, which meant there was no route to a series outside the portal at all: not a screen, not a URL, nothing. Every other thing an administrator might need to reach with the portal down has a way in, which is the whole argument for keeping Calendar Users in the WordPress admin, and series was the one gap. The term screen is back at `/wp-admin/edit.php?taxonomy=uc_series&post_type=uc_event`.

**It is unlisted, and it adds no second picker.** `show_in_menu => false` keeps it out of the menu, the same treatment Categories and Organizers get, so it is reachable by somebody who needs it rather than an invitation to work there. `meta_box_cb => false` suppresses the taxonomy box WordPress would otherwise add to the event editor beside the series select that is already there, because two controls over one relationship on one screen is the duplication 3.27.0 spent a release removing.

**The screen says what it is.** A bare term screen edits a name, a slug and a description, and a series also carries an image, a default FAQ set applied to events created into it, a schedule with a repeat pattern, and the events themselves. Left unexplained it reads as though a series is those three fields, so a notice at the top of the add and edit forms says what the screen does and links to Series & Categories in the portal. Editing here does not lose the fields it cannot draw; it simply does not touch them.

`.claude/admin-menu-test.php` now loads the real `SFAF_Series::register_taxonomy()` rather than a stub and asserts all three flags, because each undoes a different half of this: `show_ui` takes the screen away again, `show_in_menu` puts Series back in a menu a whole release removed it from, and dropping `meta_box_cb` gives the event editor two series controls.

= 3.27.0 =

**The WordPress menu is administrator concerns only.** Everything an event manager does is in `/caladmin`, and what was in the WordPress admin as well was a second set of forms over the same records. Two forms over one record drift, and these already had: the portal's series screen holds a schedule the WordPress one never learned about, and its registration list is gated on `can_view_all` where the WordPress one was gated on `edit_posts`.

Gone from the menu: **Add New Event, Categories, Organizers, RSVPs, Series, Shortcode Generator** and **Series migration**. Kept, because each is a site administrator's job rather than an event manager's: **Embed Code**, **Calendar Users**, **Settings** and **Automation**.

**Three different mechanisms, chosen by what each would otherwise break.** RSVPs, Series and Series migration are unregistered outright, because `/caladmin` owns the first two and the third has nothing left to do. Add New Event and the Shortcode Generator lose their menu entry only, through `remove_submenu_page()`, so `post-new.php` still answers, the Add New button on the Events list still works, and the generator is still at `/wp-admin/edit.php?post_type=uc_event&page=uc-shortcode-generator`. Categories and Organizers lose theirs through `show_in_menu => false` on the taxonomy, **not** `show_ui => false`, which would have removed their metaboxes from the WordPress event editor and their term screens along with the menu entry.

**The Shortcode Generator is the one screen with no portal equivalent**, which is why it is unlisted rather than removed. Organizers is the other: the portal offers a picker of existing organizers on its event editor and has no screen for creating or renaming one, so that term screen stays reachable too. Nothing else on the removed list does anything `/caladmin` cannot.

**`[sfaf_calendar]` and `[upcoming_events]` are untouched.** The generator writes shortcodes, it does not run them. Every page already using one keeps working, and so do the post type, its archive, its permalinks, the REST routes, the embed, and every taxonomy and term relationship.

**An `admin-ajax` endpoint that dumped every registration went with the screen it belonged to.** `uc_export_rsvps` was the download link on the WordPress RSVP page and was gated on `edit_posts`, so any Author on the site could fetch the full registration list as a CSV by calling it directly. Once its screen was gone nothing would ever have made anybody look at it again, so it is removed rather than orphaned. The export in `/caladmin` is the one that remains, gated on `can_view_all` and on a nonce.

**The 3.0.0 series migration is removed.** It has never been run, and it no longer has anything to convert: an old-model series was a `uc_event` post carrying `_uc_series_parent`, so clearing the calendar of test data removes every one of them, and the calendar had not launched, so no other copy of that data exists. Nothing writes that meta, nothing reads it, and no import produces it. What it was is a one-way destructive button sitting permanently in a menu, which is not a thing to leave behind once its job is gone. The class, the screen and the admin notice all go; it is recoverable from git at 3.26.1 if an old database ever turns up.

`SFAF_Series::resolve()` is not part of that and stays. It maps a pre-3.0.0 parent post ID onto a series term for embed snippets published back then, reading term meta the migration would have written. With no migrated terms it finds nothing and falls through to the term ID, which is the right answer.

**`.claude/admin-menu-test.php` is committed and locks the result down.** It models the entries WordPress adds itself, runs the real `add_menu_pages()`, `hide_duplicate_submenus()` and `register_taxonomies()`, and asserts both directions: nothing unexpected is in the menu, nothing removed has come back, and the two taxonomies lost `show_in_menu` without losing `show_ui`, `public` or their metaboxes. That last assertion is the point of the file: switching those two flags would leave the menu looking correct while the metabox quietly vanished from the event editor with no error anywhere.

= 3.26.1 =

**The pre-event summary had the same leak shape as the registration alert, and now has the same fix.** It links to the event in `/caladmin` and goes to the same notification list, which is gated on nothing: contributors, people reached through a team, and typed addresses that are not accounts at all. Every one of them was being sent that link. It is now built per recipient, from the same resolution the alert uses, and anybody who cannot open the screen gets the public event page instead.

**The capability asked is not the same one the alert asks, and that matters.** The alert links to `/caladmin/rsvps`, gated on `can_view_all`. The summary links to `/caladmin/events/edit/N`, gated on `can_edit_event`, which is `can_view_all` OR being the event's author. Testing the summary against `can_view_all` would have leaked nothing and still taken the link away from the contributor whose own event starts in two hours, which is the person most likely to want it. So each message names the capability its own link needs, `SFAF_Portal::user_can_edit_event()` joins `user_can_view_all()` as a public static, and the private instance methods both delegate to them so there is one rule per gate rather than two copies.

What is shared is the resolution: `SFAF_Notifications::staff_entries()`, which carries the account behind each address. A free-text address is `user_id` 0 and can never reach a caladmin link, including the one that resembles somebody's account, because the question is asked of the resolution's user record and never of the address. A team is resolved to people and each person is asked separately.

**`.claude/alert-recipients-test.php` now covers both messages through one routine**, because a second copy of the rule per message is how the summary came to have this fault while the alert did not. Seven recipients, both parts of every message, run through the real sender with `wp_mail()` as the seam and the plain-text alternative pulled through the real `phpmailer_init` path rather than being dropped by a stub. It was made to fail on purpose three times first: once with the summary always linking to caladmin, once with the summary checking the wrong capability, and once with a caladmin link planted in the confirmation.

**There is no third.** The five other messages this plugin sends were inventoried rather than assumed: the confirmation and the morning-of reminder link only to the public event page and a cancel URL, and the two cron health emails link to the WordPress admin Automation screen, not to `/caladmin`. `.claude/email-render-test.php` now enforces that as a whitelist over every message it builds, in both parts, so an email added later is caught by default instead of being missed by default.

**The map on the event page loads with the page. The "Show map" button is gone.** This reverses the decision taken in 3.2.0, and the reasoning behind that decision is recorded at `sfaf_event_map_html()` rather than deleted, because a note that vanishes is one somebody restores the old behaviour from without knowing it was ever weighed. A Google iframe loading on page view tells Google that this browser viewed this page, and these pages cover HIV services, substance use programs and trans health groups. That cost has been weighed and accepted: the map being immediately visible is worth it.

The map is server-rendered now, so it works with scripts off; `initMaps()` and the data attributes it read are gone from `calendar.js`. Everything else about the block is unchanged: the address is still an ordinary Google Maps link that requests nothing until it is clicked, an event with no location still renders nothing at all, and with no key configured the page still shows the address link alone, with no map, no error, no broken frame and no admin notice on a public page. The frame keeps `loading="lazy"`, which is the ordinary performance default and not a consent mechanism.

= 3.26.0 =

**The registration form asks for a first name and a last name, and the last name is optional.** Not optional in the label and required underneath: the field is optional in the form, in the browser check, in `SFAF_RSVP::submit()` and in the column, so there is no level at which it quietly becomes mandatory. Somebody registering for an HIV testing session or a trans health group has good reason to give a first name and no more, and some people have one name. Requiring a surname costs registrations rather than gaining data.

**The split reaches everywhere the name appears**: the form, the registration list in `/caladmin`, the registration list in the WordPress admin, and both CSV exports, which now carry First Name and Last Name as two columns rather than one combined field. A single column is one somebody has to split by hand on a space before it can be pasted into Salesforce, which gets "Ana Maria Ruiz" and "van Dijk" wrong every time. The data handed to any Pardot integration carries the two fields as they were typed, which is what the Salesforce admin asked for.

**The confirmation greets by first name.** "You are registered, Mark." A database addresses a record by its full name; a person is addressed by their first, and this one lands in front of somebody who has just handed over their details for a health service. Staff-facing messages and the lists still show the whole name, where telling two people apart is the point. Search on the registration list matches either half or the two together.

**A phone number entered as 1234567890 is stored as (123) 456-7890, and nothing else is touched.** The formatting happens once, on the server, when the value is complete, rather than as somebody types: a number cannot be recognised until it is finished, so an as-you-type formatter has to guess at every keystroke and then take its guess back, and it has to decide where to put the caret after rewriting the field, which is where that pattern goes wrong on phones and with a screen reader. An international number, a country code (a leading 1 included), an extension or anything with a letter in it is kept exactly as entered. **Nothing is ever rejected.** A phone field somebody cannot complete is worse than an unformatted number.

**The confirmation modal now sets expectations and offers the date.** It says "Check your inbox for a confirmation, including your spam folder", which is an instruction rather than "it may be in your spam folder", a sentence that invites somebody to doubt a message that has just been sent. Below it are the same two add-to-calendar buttons the confirmation email carries, Google and Apple or Outlook, built by the same two helpers so the modal and the email cannot offer different links. This is the moment somebody is thinking about the date.

**The registration alert links to the registration list, per recipient.** An organizer who has just been told somebody registered wants to see who is coming, not the public event page, so the button is now this event's RSVP screen in `/caladmin`. That screen is gated on `can_view_all` and the notification list is gated on nothing: it holds contributors, people reached through a team, and typed addresses that are not accounts at all. So the message is built per recipient. Anybody without the capability gets the public event page, exactly as before. A team is resolved to people and each person is checked individually, because a team is a set of names and not a permission. A free-text address is never offered the link even when it happens to match somebody's account, because it is a string typed in a box rather than that person: the capability is asked of the resolution's user record, never of the address. There are only ever two versions of the message, so a list of thirty people costs two builds.

`.claude/alert-recipients-test.php` is the proof, and it is committed. Six recipients covering every route onto the list and both answers to the capability question, run through the real sender with `wp_mail()` as the seam, checking that no `/caladmin` link reaches anybody who cannot open it, that everybody who can gets this event's list, that nobody is mailed twice, and that the per-event off switch still switches it off.

**The alert counts the registration it is announcing.** It read "0 of 12 places taken" on an email whose subject was a new registration. The per-request count store is the cause: the capacity check earlier in `submit()` reads the count and memoizes it, so the alert built afterwards, and the number handed back to the capacity bar on the card, were both reading the copy taken before the row was written. The cache is now cleared the moment the row lands, which is the same remedy the cancellation path already used. A test send still reads the true current count, because a test writes no row, and it says so at the foot of the message.

= 3.25.0 =

**None of this has ever run.** No email has ever been sent by this plugin. Everything below is built and statically verified, and delivery, rendering, the Reply-To header and the cancel flow are unverified until somebody presses send on a live site. **Events > Automation > Send a test email** exists for exactly that, and it is the first thing to do after installing this build.

**Four emails, all on by default, all switchable per event.** A confirmation to the person who registers, carrying the date, the time, the place, add-to-calendar links for Google and for Apple or Outlook, a link to the event page, and a link to cancel. An alert to staff the moment somebody registers, saying who and how many places are taken. The morning-of reminder, unchanged in timing and now in the branded template. And a list of who is coming, two hours before the event starts, which is not sent at all when nobody has registered.

**No event image in any of them, and that is a decision.** The banner is already a large picture; a second one pushes the date, the time and the address below the fold on a phone, which is the part somebody opens the email to re-read. Half the imported events have no photograph and would render a flat color block in its place.

**One notification list, which reverses a decision this project made deliberately and wrote down.** Until now, "email somebody when an RSVP comes in" was a checkbox and a single address, while the morning-of reminder went to a full picker of people, teams and typed addresses. The note in the code argued the split was correct: "The two are genuinely separate mechanisms and are not merged. Presenting them as one list would be a lie about what the software does."

That argument was about the code as it stood, and the code has changed. Both fields answered the same question, who finds out, and the only real difference between them was that one had been upgraded and the other had not: a manager had to name the same colleague twice in two different shapes, and a team could not be told about a registration at all. There is now one list, it is the picker, and everybody on it gets all three staff emails. All or nothing per person; per-recipient control can come later if anybody ever wants it.

**Nothing that used to be told stops being told.** On upgrade, every event's old single address is folded into its notification list unless that address is already reachable through a person or a team, and the old checkbox is translated rather than ignored: an event that said "do not email me when somebody registers" still says that, recorded as the new per-event switch. An event that never expressed a preference gets the new default, which is on. The legacy meta is left in place as the evidence for what moved.

**The controls that used to write those two fields are gone from both editors**, in the portal and in the WordPress admin, because nothing reads them any more and two live-looking controls that change nothing are worse than none: somebody would type an address into one and believe it. The WordPress metabox now lists who is currently on the event's list and links to the portal to change it. The site-wide fallback address is gone too: every event's list starts with a real person, whoever created it, so the case it existed for cannot arise.

**Registrations can be cancelled, which was not possible before.** Every email to somebody holding a place carries a link with a 128-bit token in it, working with no account, the same pattern the reminder subscriptions use.

**The link opens a page that asks, and never cancels on being opened.** Mail scanners and link previewers fetch the URLs in an email before a person has read it, and Outlook is already rewriting SFAF mail through safelinks, so a one-click cancel would fire during scanning and drop people's places for them. A GET shows the question; the POST from that page is what acts.

Cancelling marks the registration cancelled rather than deleting it, so an organizer can see that somebody registered and then changed their mind. The place is free at the same moment for all three things that count it: capacity, the reminder send, and the who-is-coming list all select on status, so moving the status is the whole of it. There is no counter to decrement.

**A visitor-powered cron trigger, needing nothing set up on a server.** WordPress cron only fires on site traffic and the calendar site has almost none, so a 6am reminder could go out at noon or not at all. Every sfaf.org page carrying a calendar now asks the calendar site to run its jobs, after the page has loaded and in an idle moment. It costs a page nothing: it never runs during load, a timestamp in the browser means one visitor reading six programme pages sends one request rather than six, and the endpoint returns an empty 204 in a few milliseconds unless fifteen minutes have passed. The request is fire-and-forget, so there is no CORS requirement and nothing on the page waits for it or notices if it fails.

It is on admin-ajax rather than a REST route on purpose. Every CORS mechanism in the embed is gated on an exact route-string match, so a second REST route would have matched none of preflight, the response headers or the serve fallback, and would have been blocked by the browser the moment sfaf.org asked for it.

**The interval is fifteen minutes rather than an hour** because the new summary is due two hours before an event starts, and an hourly run can be up to an hour late for that. Every job is idempotent and most passes find nothing due, so a run with no work is one query.

**Table-based markup with inline styles, 600px wide.** Outlook on Windows renders with Word's engine: no flexbox, no grid, no reliable border-radius. The SFAF skyline banner is bundled in the plugin at public/images and served from this site, so it travels with the plugin rather than depending on a media library entry, and it carries real alt text because many clients block images. No yellow rule under it: the banner is the header. Montserrat with a real fallback stack, one yellow button per message with Dark Gray text, #0E7680 for links and the outline button, and the postal address in the footer.

**A plain-text alternative for every message, and one caveat that cannot be resolved from here.** WordPress attaches the text part by setting AltBody on the phpmailer_init action. That works with core's mailer and with any SMTP plugin routing through PHPMailer, and it cannot work with a plugin that replaces wp_mail() outright and posts to an HTTP API, because PHPMailer is never constructed. The Postmark plugin is the second kind. The hook is attached because it is correct wherever PHPMailer is involved, and whether a text/plain part actually arrives is a fact about the transport that is visible in a delivered message and nowhere else. Check it in the test send.

**From name and address are a setting, not a constant.** It ships as San Francisco AIDS Foundation <websites@sfaf.org>; events@calendar.sfaf.org is being set up and will replace it, and when it exists somebody types it into Settings and nothing is deployed. Reply-To is unchanged and still per event: the event's address, or the person who created it, or the site default. The registration alert is the one exception and replies to the person who just registered, because that is who a staff member pressing reply means to write to.

**Delivery is not this plugin's problem and must not become it.** Everything goes through wp_mail() and stops. No SMTP layer, no transport, no library.

**Creating an event still requires nothing.** Title, description, date, time, location, category, image, Save, and the event has working email with a real recipient: the notification list starts with whoever created it and all four emails are on. Custom copy, extra recipients and switching one off live behind a fold whose closed line says what the default is currently doing, so the state is visible without being a decision.

**.claude/email-render-test.php is committed.** It builds all five message shapes with WordPress stubbed and checks what came out: both parts present, table layout rather than divs, 600px stated twice, the banner and its alt text, the postal address in both parts, no modern CSS, a closed palette, no em dash, absolute links, one yellow button, the cancel link present in exactly the messages that should carry one, and every fact in the HTML also in the text. Five faults were planted in it to prove it fails: a removed alt attribute, a border-radius, the banned brand teal, a cancel link dropped from the text part, and a cancel link leaked into the staff alert. It catches all five.

**It found a real defect the moment it rendered.** The "Registered" column in the who-is-coming list was empty for everybody. The one formatter anchors a bare date at midday to stop a timezone shift moving it a day, and it was doing that to stored datetimes too, producing "2026-08-04 21:30:00 12:00:00", which cannot be parsed at all. The same call sites that did parse were reading a site-local timestamp as UTC and rendering it back in the site's zone, which moved a nine-in-the-evening registration to two the next morning: the same class of fault as the compact card tile in 3.21.1 and the RSVP table in 3.24.2, one layer further in. sfaf_local_timestamp() now tells the two kinds of string apart and parses a stored datetime in the zone it was written in. The test grew an "empty cell under a heading" check, which catches the original.

**SFAF uses the serial comma, and the list-joining helpers are where that is decided.** Four of them, two in PHP and two mirrored in JavaScript, joined "A, B and C". They now produce "A, B, and C", with two items still taking no comma. That changes labels a visitor reads: "Every week on Monday, Wednesday, and Friday". Seven user-facing sentences elsewhere were fixed by hand. The recurrence cross-check compares the PHP and JS labels on 4,321 cases and is what proves the mirrored pair still agree.

Also: the RSVP table gains a token column and a cancelled_at column (schema version 4). The key on token is not unique and cannot be, because every existing row would share the default empty value; every lookup rejects an empty token before it queries, which is what keeps a blank link from matching the entire history. Confirmation emails are on when nothing has been saved, which they were not before: the setting was read as "absent means off", so an install where nobody had pressed Save in Settings sent none and gave no sign of it. The Settings switch now reads the same function the sending path reads, so it cannot show off while the code is sending.

Lint: 38 files parse. Callable audit: clean, self-test passes. Email render test: five messages, every rule, self-tested by planted faults. Recurrence cross-check: 4,321 cases, PHP and JS agree on every date, label and count. Group operations harness: passing. Date sweep: zero human-facing call sites outside the formatter. All re-run against the extracted zip.

= 3.24.2 =

**The sidebar ignored the width of the column it was in, and it had no floor of its own to state.** In a table cell or a flex track the browser sizes the box from its contents, and what it asked the card was "how narrow can you be". The answer was 196px, and nobody had chosen that number: it was the longest word in the heading, plus the one date span that is not allowed to break, plus the card's 30px of border and padding, in whatever typeface the page had loaded. Change the heading and the number moves.

`.uc-sidebar` now carries **`min-width: 200px`**, the minimum this readme already publishes for the mode, and the same treatment `.uc-calendar` was given in 3.24.1. Below 200px the card overflows rather than compressing, which is what publishing a minimum means. Measured in a table cell and a flex track: a 400px column renders the card at its 380px cap with the thumbnail beside the title; a 200px column renders it at 200 with the thumbnail stacked above; the changeover is at 272px to the pixel.

**A page cannot honour widths it has not got, and no floor changes that.** The report was four columns at 180, 220, 280 and 400px all rendering identically. That is 1080px of request in a content area of about 700; the table algorithm has nothing left to distribute and pins every column at its floor. Measured across five page widths in the probe: still identical at 860px, differentiated by 1000px, which is also where the 400px column crosses back over 272 and puts its thumbnail beside the title again.

**Photograph rows and placeholder rows did not match, and the band was never the thing that was wrong.** The branded placeholder filled the full-width band; a real photograph drew a small block inside it with the grey showing round the outside. Both bands measure the same at every width, because the band is sized by the row's flex rules. What differed was the picture in it.

The asymmetry is that one of them is an `<img>`. The placeholder is a `<span>`, which no host stylesheet has an opinion about; the photograph is the one element in the row that every WordPress theme writes a rule for, and the usual rule is a responsive-images reset of `width: auto; height: auto`. Ours outranks a bare `img` selector and loses outright to the same rule carrying `!important`, at which point the photograph falls back to its intrinsic size, and the sizes WordPress serves are smaller than a stacked band. A 150px crop in a 210px band is exactly the reported "small block".

**So the fill no longer depends on a property a host can overwrite.** The band is a flex container and the picture is a flex item that grows and stretches, so its used size comes from layout rather than from `width` and `height` on the element. Reproduced under three shapes of theme reset, including `width: auto !important; height: auto !important`, and the picture fills its band in all of them. No `!important` was added: this file has exactly one and it is enforcing the `hidden` attribute.

**The probe was measuring a case the live page does not produce.** 3.24.1 reported from `.claude/embed-width-probe.html` that the photo row and the placeholder row measure identically at every width, and on the live page they did not. Its "photograph" was a 1x1 GIF, which has no intrinsic size to fall back to and therefore fills any box whatever the cascade does to it: the one thing that separates a photograph from a `<span>` was the one thing the probe had removed. It now uses a real 150x150 raster, it compares the picture against the band rather than measuring the band alone, and its table and flex sections give each width its own row, because eleven columns in one row is a squeeze and a squeeze answers a different question than the one those sections ask. It also renders the four-column page that was reported, at five page widths.

**Every human-facing date now goes through the one formatter, and it is checked rather than asserted.** DESIGN.md has required this since 3.17.0 and fifteen call sites were still spelling out their own format, two of which were found after the rule was set. `.claude/date-callsite-sweep.php` is committed: it tokenises every PHP file, finds every `date_i18n`, `date`, `gmdate`, `wp_date`, `get_the_date`, `mysql2date` and `->format()` with a literal format, and splits them into human-facing and machine. It carries a `--self-test` that plants nine cases and proves it rejects what it should. The count outside the formatter is now zero. Machine formats are untouched: `Y-m-d` keys, `Y-m` slugs, the ICS stamp and the `w` weekday numbers the recurrence engine compares are storage and protocol, and putting them through a localised formatter would break them.

**"Every month on the 4th" was a hard violation on a visitor-facing page.** `SFAF_Recurrence::pattern_label()` reaches the single event page, and the brand guide's rule is no ordinals, ever. It reads **"Every month on day 4"** now, which keeps the fact and drops the suffix, and the same words are used by both schedule editors and by the JavaScript engine. The cross-check caught the drift immediately, which is what it is for: `public/js/portal.js` had its own copy of the label and was still saying "the 31st" after PHP had stopped. `ucOrdinalDate()` is deleted rather than left unused.

**Two of these were wrong days, not wrong formats.** The RSVP table in wp-admin was using PHP's `date()`, which reads the server clock, so a registration taken at nine in the evening in San Francisco was filed under tomorrow. Same class as the compact card date tile fixed in 3.21.1. Several others took `strtotime()` of a bare `Y-m-d` and handed the result to a site-timezone formatter: WordPress runs PHP in UTC, so that is midnight UTC and renders as the previous day anywhere west of Greenwich. `sfaf_ap_date()` anchors a date string at midday for exactly this reason, and those call sites now pass it the stored string instead of a timestamp.

**Two styles and one helper were added to the formatter rather than to the call sites.** `short_year` is "Aug 4, 2026" and `month_year` is "August 2026"; a missing style is the reason a call site writes its own format, so the list is the thing that has to grow. `sfaf_ap_datetime()` is "Aug 4, 2026 at 6 pm" for the three places that record when something happened: the two RSVP tables and the cron alert email.

Also: `nth_weekday_of_month()` no longer returns a `weekday` key. It held an English weekday name built at the call site, all five callers use `nth` and `dow` only, and a spare formatted string nobody displays is a second source of wording waiting to be picked up. The schedule editor's dates in the browser read "Aug 4, 2026" rather than "Aug 4 2026".

Lint: 35 files parse. Callable audit: clean, self-test passes. Recurrence cross-check: 4,321 cases, PHP and JS agree on every date, label and count. Group operations harness: passing. Date sweep: zero human-facing call sites outside the formatter, self-test passes. All re-run against the extracted zip.

= 3.24.1 =

**The sidebar thumbnail was missing at every width, and the cause was the thing that was supposed to make the block responsive.** Reported after testing at 180, 220, 280 and 400px on a live page: no thumbnail in any column, including 400px, which had them before 3.24.0.

The markup was never at fault. The live feed emits the thumbnail slot on every row, filled with either a real `<img src>` or the branded category tile, and the deployed stylesheet was byte-identical to the repository. The only rule that could hide it fires below 240px, which a 400px column should never reach. It was reaching it.

**`container-type: inline-size` applies size containment, and size containment means an element's intrinsic sizes are computed as if it had no contents.** 3.24.0 put one on `.uc-sidebar`. On the live page that card sat in a table cell, and a table cell is sized from its contents. The card's min-content contribution fell from roughly 150px, a 60px thumbnail plus the longest word in a title, to 30px of its own border and padding. Every column in that table collapsed, every card fell under the breakpoint, and the thumbnail was hidden everywhere. The comment above the declaration read "SAFE, BECAUSE OF WHAT IS NOT IN HERE" and reasoned only about absolutely positioned descendants: one mechanism verified carefully, and the conclusion generalised to a second that was never checked. That shape of error, not just the rule, is written into DESIGN.md.

**The sidebar is no longer a query container and has no queries at all.** The row reflows intrinsically instead, so nothing about it depends on an element reporting a width correctly and no host layout can defeat it. Above 271px the thumbnail sits left of the text exactly as before, at 60 by 45. At 271px and below the row stacks and the thumbnail becomes a full-width 16:9 band above the title, cropped from the centre, capped at 120px so a ten-date sidebar does not become a column of pictures. Measured band sizes: 130x73 at a 180px column, 150x84 at 200, 170x96 at 220, 190x107 at 240, 210x118 at 260 and 221x120 at 271, where the cap engages. The wrap is decided by flex, on a 60px thumbnail and a 150px text column plus a 12px gap; the 150px is a `flex-basis` rather than a `min-width` because a real minimum cannot shrink below itself and would overflow a 180px column.

**The picture is never removed at any width now, and the old argument for removing it was wrong on the data.** It ran: every row in this placement is the same programme, so the picture is the least load-bearing thing in it. On the live feed five of six rows carry no photograph and render the branded category tile, and roughly half of imported GoFundMe Pro campaigns can never have one because the API does not expose it. What `display: none` deleted was not a repeated photograph, it was the category's colour and icon, permanently, for the events that most need it.

**`.uc-calendar` keeps its container queries and gains `min-width: 260px`, which reverses what 3.24.0 argued and must not be undone from the old reasoning.** That build said a hard minimum makes a block in a too-narrow column overflow the host's page rather than be cramped in it, and that the overflow is the worse failure. Sound about readability, and it missed containment. The month grid genuinely needs a layout switch so the queries stay, which means the collapse hazard stays with them, and a definite `min-width` is the only thing that puts an intrinsic floor back under a parent that sizes from its contents. Without it the block is not cramped, it is destroyed, and the host's layout goes with it.

**The published minimum widths are now measured rather than calculated: 200px sidebar, 260px list, 260px month grid.** 3.24.0 derived them by arithmetic and got the sidebar wrong in the same build that published it, since a container query measures the content box and the card carries 30px of border and padding, so the documented 220px minimum was 30px inside the width at which the thumbnail vanished. `.claude/embed-width-probe.html` is committed and renders every mode at eleven widths in three kinds of parent, printing card width, content box, layout state, both thumbnail sizes and any overflow. It includes a **table cell** and a **flex item**, the two content-sized parents that broke and that nothing was testing, and it caught a fault in its own first draft: with a sidebar and a list card in one cell, every table column came out at 260px because the list card's new floor was holding it open and the sidebar was never the thing being measured.

= 3.24.0 =

**Every embed mode now measures its own column, not the browser window.** The sidebar block is going into columns of varying width across sfaf.org and possibly other sites, so this had to be checked rather than assumed. What it did at 280px was fill the column correctly and hold a row that did not: `flex: 0 0 60px` on the thumbnail is a hard reservation that never shrinks, so of the 160px a row has for content the picture took 60 and left 88 for a title. "TransLife Galaxy Mental Health Series" is five or six lines in 88px. Nothing overflowed and nothing looked broken, which is why it survived.

**The month grid was the worse case and had a complete fix sitting in the stylesheet unreachable.** Its phone layout has been there since 2.x and is gated on `@media (max-width: 640px)`, which asks about the WINDOW. An embed in a 280px column on a 1440px desktop is 280px wide inside a 1440px window, so the query is false: seven columns were rendered into 40px cells, with event titles truncated to two or three characters and "6-7:30 pm" wrapping underneath. The table is `table-layout: fixed; width: 100%`, so it did not overflow either.

The question is asked of the container instead. `.uc-calendar` and `.uc-sidebar` are `container-type: inline-size`, and the narrow layouts are `@container` queries. Both roots were checked for what inline-size containment brings with it, because layout containment makes an element the containing block for absolutely and fixed positioned descendants: everything absolute inside the calendar resolves against a nearer `position: relative` ancestor, and the RSVP modal, the one thing that would have broken, is appended to `<body>` and outside both roots. The viewport copy of the month grid's phone layout stays as the fallback for browsers without container query support and is marked as the copy.

**Documented minimum widths, in readme rather than in CSS: 220px sidebar, 260px list, 300px month grid.** Below those they still render and still do not overflow. A `min-width` would make a block pushed into a column too narrow for it overflow the host's page rather than be cramped inside it, which is a worse failure and not ours to cause.

The one control that genuinely overflowed was the calendar's search box: `flex-shrink: 0` around a field declaring `width: 200px` is a 230px reservation once padding and border are counted, so in a 200px column it walked 30px into the host's page. It is `flex: 0 1 200px` now, identical at desktop width and able to give way. The sidebar's date and time line is two spans rather than one string, each held whole, so the only place it can break is at the separator instead of splitting "Tue," from "Aug 4" or a clock from its "pm". No width setting was added to the embed generator: responsive is the answer and a fixed width would work against it.

**The schedule screen has four properly built actions where it had three partial ones.**

**Change the pattern, all of it.** The old form offered a weekday and two times, so a group that had to move from the first Monday of the month to the second could not be moved at all and one going from weekly to fortnightly had to be rebuilt by hand. Frequency, interval, weekday, monthly ordinal and both times are editable now, and any subset of them is one edit: every field arrives holding what the group says today, so touching only the times produces an identical pattern and moves nothing. Sameness is decided on canonical forms rather than on stored text, because `weekly` and `weekly:1:3` are one schedule for a Wednesday group and comparing them as strings would report a change on every save.

The dates move and the count does not. Twelve upcoming sessions are twelve upcoming sessions afterwards, on the new cadence; nothing is created and nothing is deleted, which is what makes the number on the button honest on both sides of the press. The confirmation afterwards names the new first and last date, because "12 occurrences were changed" says nothing about twelve daily sessions becoming twelve weekly ones. Extra dates are still left exactly where they are, warned about before the button, named in the confirmation and accounted for after it. `reday_group()` and `weekday_is_movable()` are gone rather than left sitting beside their replacement: a dead function that reads like the canonical statement of something is what the next person edits.

**Extend the series, which had no control at all.** A series running to December 31 that needs to continue into the new year was previously January's dates added one at a time. Set an end date and the missing occurrences are generated from the existing pattern; nothing about the pattern changes. The screen says how far out the series is currently generated, in a date, because "does this need extending" cannot be answered without it and the alternative was scrolling to the bottom of a list of forty. Three rules make it safe and each was tested: it resumes from the last PATTERN date rather than the last date, so a Saturday somebody added by hand cannot re-anchor a weekly group onto Saturdays; a date the group already holds is skipped, and that includes TRASHED occurrences, so a holiday removed in December is not put back by an extend run in January; and nothing before today is created, so a dormant group is not back-filled with six months of sessions that never happened.

`SFAF_Recurrence::extend_group()` takes a group and a date, derives its own seed, anchor and pattern from stored data, touches no request state, returns rather than redirects, and creates nothing on a second run with the same horizon. **A scheduled top-up for the coming "ongoing series" option can call it as it stands**, once a week with today plus twelve months, and needs no other entry point.

**Add a date, reframed as two routes, with the checkbox and its explanation gone.** It was a date field plus a tickbox reading "Keep this date out of the group" under four sentences about recurrence groups, bulk edits and extra-date marking, which asked somebody adding one session to a Tuesday class to first understand the data model. The two cases that actually differ are now told apart by a fact about the event: **use this event's details on another date**, which copies the occurrence with its location, description, times, category, organizer and FAQs and takes an optional title override so one date can be "Annual picnic"; and **create a new event in this series**, a link to the event editor with the series already chosen, for the date whose details genuinely differ. The behaviour the checkbox was offering is now the only behaviour of the first route and not a choice: the copy joins the group so a time change reaches it, and is marked as an extra date so a pattern change leaves it alone.

**Remove a date is unchanged, and now has one more thing that must not undo it.** Regeneration went in 3.0.0 and nothing brought it back; the new extend path is the first code since then that creates occurrences into an existing group, and it is the reason removed dates are matched against trashed posts rather than live ones.

**Two committed checks, both made to fail on purpose before being trusted.** `.claude/group-ops-test.php` runs the two new group operations against an in-memory WordPress stub: 47 assertions covering first Monday to second Monday, an extra date staying put through a cadence change and moving with a time change, the past never being reached, weekly to monthly holding its count, extend being idempotent, a removed date staying removed, a dormant group not being back-filled, and the title override reaching both the title and the slug. The recurrence cross-check gained 13 rules for `plan_from()` and `canonical_pattern()`, and its "20 rules hold" line is counted rather than written down, since a hardcoded total stops being evidence the moment somebody adds a rule.

= 3.23.0 =

**The per-field edit pencils are gone. The modal is the only gate.** Opening a recurring event asked the scope question in a modal, and then every field still had to be unlocked by pressing its own pencil. That was the same question twice, and a click on every field of every recurring event. The pencils made sense before the modal existed, when the scope was two quiet buttons at the top of a long form that were easy to walk past; the modal cannot be walked past, so the second lock was paying for a problem that had already been solved. Once a scope is chosen, everything that scope permits is directly editable.

A field the scope FORBIDS is a different thing and must not look like a locked one, because no gesture opens it: the scope is what forbids it and changing the scope is the answer. Those render disabled with the reason printed under the control. In all-upcoming mode that is the date, always, and capacity when any date in the set already has RSVPs against it. `disabled` is safe for exactly these two and for nothing else on the form: `save_event_from_post()` guards `date` with `isset()` and `save_rsvp_settings_from_post()` guards `capacity` the same way, so a control that posts nothing leaves its stored value alone. The explanation also moved from inside the `<label>` to after it, where it is a note about the control rather than part of the control's accessible name.

**Custom, as a fifth repeat option.** For the programme that meets Monday one week, Tuesday the next and Wednesday after that, with no pattern to express. Selecting it reveals a date picker; each date added appears in a numbered list with a remove control, and the same start and end time applies to all of them. No pattern is stored, because there is not one. The summary says it plainly: "5 dates. 5 events will be created." The event's own date is pinned to the top of that list and cannot be removed, so the five the sentence counts are five things on screen.

**Extra dates alongside a pattern.** A weekly Wednesday group may also meet on one Saturday. The same picker sits beside Daily, Weekly and Monthly, and the dates it holds generate events in the **same recurrence group**, so "edit all upcoming occurrences" reaches them and a time change applies to them. The summary states both halves: "Every week on Wednesday, until Dec 31 2026, plus 2 extra dates. 23 events will be created." A date the pattern already produces is not an extra date and is not counted as one, because the merge collapses it into a single event and announcing an event that will not exist is the one thing this sentence must never do.

The consequence is surfaced rather than left to be discovered. **A later pattern edit, moving Wednesdays to Tuesdays, does not move an extra date**, because that date was never on the pattern and shifting it by the same offset would land it on a day nobody chose. Each such date carries a marker, the pattern edit reads it (`reday_group()` then, `repattern_group()` since 3.24.0), the schedule screen warns before the button is pressed and names how many dates it will leave alone, the confirmation dialog says the same, and the message afterwards accounts for them. A time change is the opposite case and reaches every date in the group. The schedule list tags extra dates so the row can be found by eye, and does not tag them in a Custom group, where every date was chosen by hand and the tag would be on every row saying nothing.

**The count is cross-checked, and now so are the rules behind it.** Generating occurrences creates real posts, so the number under the control has to be the number. Both engines gained the merge, Custom, and one `summary()` function each so that the sentence and the count come from the same place rather than being assembled beside each other. The check is a committed script, `.claude/recurrence-crosscheck.php`, and it slices the JS engine out of `public/js/portal.js` between two markers so it runs the code that ships rather than a copy somebody remembered to update. It covers **4,321 cases**, up from 525, of which 3,780 carry extra dates and 288 are Custom, comparing dates, labels and sentences. Two engines agreeing on a wrong answer is the failure the matrix cannot see, so 20 rules are also asserted as values: what a date on the start day does, what a duplicate does, what a date beyond the end date does, and what each of the three summary shapes reads like. Both layers were made to fail on purpose before being trusted.

Recurrence stays disabled on imported events, unchanged since 2.9.0.

= 3.22.0 =

Nothing visitor-facing is left defenceless against the host stylesheet. The scoped reset had three roots and covered the calendar block, the sidebar and the widget; it now has five and covers the event page and the registration modal as well.

**The single event page had no host-proofing at all.** `.uc-single` appeared in none of the reset's selector lists, so there was no reset for links, headings, paragraphs, lists, buttons or images, and `box-sizing: border-box` was left to the theme, which means every padded box on the page was doing its width arithmetic under rules a host could change. All seventy of the page's own rules sat between (0,1,0) and (0,2,0), so a themed `h1`, `ul`, `button` or `a` at (0,1,1) outranked the title, the facts list, the FAQ questions and the back link. The page now states its colours, sizes, list-style, link decoration, button appearance, image sizing and spacing rather than inheriting them, on specificity rather than `!important`. The series page uses the same root and is covered by the same block.

Extending a reset root is never a free change, and this is the fourth build to be caught by it: every rule in the reset is (0,1,1), so any component naming itself with a single class is now outranked by its own reset. Ten rules were raised to two classes for that reason and each carries a note saying which property it would otherwise have lost. `.uc-faq-q` is the clearest: it is a `<button>` at `width: 100%`, and the reset's `width: auto` would have shrunk every question to its own text and moved the toggle off the right-hand edge.

**The prose is exempt, deliberately.** An event description and an FAQ answer are editorial HTML, and `ul { list-style: none }` with `p { margin: 0 }` is correct for a facts list and wrong for a paragraph. A prose island at (0,2,1) gives bullets, paragraph spacing, heading rhythm and underlined links back inside `.uc-single-body` and `.uc-faq-a`.

**The RSVP modal sat outside every protected root.** It is appended to `<body>`, so nothing in the reset reached it and its inputs, labels, buttons, headings and paragraphs were defended only by their own classes at (0,1,0) to (0,1,1), which is the same exposure that produced the 3.21.0 consent-checkbox fault. It keeps its position and gains its own root class, `.uc-rsvp-modal-overlay`, covered by the same resets. Moving it inside `.uc-calendar` was the other option and was rejected: the overlay is `position: fixed`, and a transform, filter or `contain` on any ancestor makes a fixed element resolve against that ancestor instead of the viewport, `overflow: hidden` clips it, and there can be more than one calendar on a page.

Two live faults surfaced there. `.uc-rsvp-form input` set `width: 100%` and `padding: 10px 14px` on **every** input in the form including the consent checkbox, which takes width and padding like any other control, so the tick was being drawn as a form-width rounded box; the rule now excludes checkboxes and radios. And pressing RSVP on the event page itself opened a modal with a blank subtitle, because the title was read from the `.uc-card-title` of an enclosing list card that does not exist there; the button carries the event name now, exactly as the reminders button already did.

**The registration form had no visible focus.** `.uc-rsvp-form input` set `outline: none` and replaced it with brand teal on the border plus a 10 percent alpha shadow. Brand teal `#16BECF` is **2.26:1** against the white field, under the 3:1 floor WCAG 1.4.11 asks of a focus indicator, and the shadow flattens to `#E8F9FA` at **1.08:1**, which is not a boundary at all. Both fields and the calendar's search box now take a 2px `#0E7680` outline at **5.35:1** on white and **5.04:1** on the page canvas, plus the same colour on the border so the indicator does not depend on the outline surviving a host's `outline: 0`.

**The yellow category chip was yellow on yellow, and the cause was a second renderer rather than a lost rule.** `sfaf_category_shades()` was never being asked. There are two chip renderers: the list card emits `.uc-lc-chip` and reads the measured tint-and-ink pair, and the event page emitted `.uc-badge` and passed the **raw** category colour in a property of its own, used both as a 12% tint and, unchanged, as the text colour. On Fundraising that is `#FFD900` on `#FFFAE0`: **1.32:1**. Every family failed, the best of them Purple at 2.63:1. Both renderers now emit the same two custom properties from the same function, so the pair cannot be honoured on one surface and skipped on the other. Measured across all ten as they render: Yellow 6.01, Orange 6.11, Red 6.10, Burgundy 6.35, Pink 6.07, Purple 6.02, Teal 6.02, Green 6.08, Light Gray 11.80, Dark Gray 9.98. Nothing is near the floor; the ramp was built with headroom and was simply not reaching this surface.

The two non-category badges join the ramp with it. The volunteer badge was brand Purple on `rgba(123,97,255,0.12)`, and `#7B61FF` is not in the brand palette at all; it takes the Purple family's own pair, 6.02:1. The recurrence badge was `#6B7280` on `#F3F4F6`, which measures **4.39:1** at 11px and misses 4.5:1; it takes the Dark Gray pair, 9.98:1.

**Two sets of dead rules removed.** Four card rules read as the canonical card spec and never applied, because `.uc-card-title` and its siblings only ever render inside `.uc-event-card`, where the scoped rules are (0,3,0). They are deleted rather than promoted: the effective rules already say the same thing more precisely, so promoting them would leave two descriptions of the card and deleting them leaves one. A seven-class heading list went the same way, having become redundant the moment the reset started setting `font-family` on all five roots' headings. Nothing rendered differently before either deletion or after it; what is removed is the invitation to edit the wrong rule.

**The upcoming-events widget was missing from two resets.** `.uc-upcoming-widget` was absent from the heading reset and the paragraph reset, so `.uc-upcoming-title` was taking the host's margin, padding, text-transform, letter-spacing and border.

= 3.21.1 =

Four findings from the public-surface audit. No structural change: the single event page's missing host-proofing layer and the RSVP modal's are the next build.

**The public stylesheet was still on the superseded teal, and the comment above it is why.** `--uc-teal-text` was `#0E818C` under a note reading "this is 4.63:1 and passes". That is true on plain white and on nothing else this colour lands on: `4.36:1` on the page canvas `#F7F8FA`, `4.27:1` on the `#E8F9FA` band, and `4.33:1` on the 8% teal tint, all against a 4.5:1 floor. Two of those were live, `.uc-filter-btn.active` and `.uc-series-link`, both putting teal text on `rgba(22,190,207,0.08)`. DESIGN.md mandates `#0E7680`, which measures 5.35 / 5.04 / 4.94 / 5.01 across the same four; the portal moved to it in 3.20.0 and this file had not followed. The old note is not trimmed but replaced with the full four-way table, because a comment asserting a pass is exactly what makes somebody revert the fix. The reversed pairings improve with it: white on the primary action button's fill goes from 4.63:1 to 5.35:1.

**One secondary text colour, not two.** `--uc-muted` was `#9CA3AF`, which measures **2.54:1 on white and 2.39:1 on the page canvas**, and it carried thirteen text elements: the compact card's date and time line, the single page's back link, the month grid's out-of-month numbers, the event count, the capacity text, the modal subtitle, the series time, the empty-state line, the group label, the crumb separator, the share label, the loading indicator and the mobile day dot. The variable is deleted rather than darkened, because two greys a shade apart invite the question "which one is this" at every call site, and DESIGN.md names exactly one public secondary. Everything that was muted is now `#6B7280`, 4.83:1 on white and 4.55:1 on the canvas.

The card's own date line had already got this right, with a note saying `--uc-muted` "fails as text at any size" sitting directly above the one rule that used the correct colour while thirteen others did not.

**The month grid's out-of-month day numbers were not treated as an exception.** They are the most defensible candidate, since days outside the current month look like disabled text. They are not: each cell is `role="gridcell"` with a real `aria-label`, and the roving tabindex means the arrow keys move focus onto them, so they are keyboard-reachable content rather than a disabled control. They also sit on the tinted `#F7F8FA` cell, where the old grey was 2.39:1, its worst pairing anywhere. They now read at 4.55:1 and still recede clearly, because an in-month number is `#1A1D21` at 16.91:1 and drops a weight step as well.

**The RSVP failure message was the hardest text on the surface to read.** `.uc-rsvp-error` used `--uc-warm #F04937` at 13px, which is **3.68:1**, and DESIGN.md permits Red as text only at large sizes. It is now `#AD1C0D`, the Red family's ink from the category ramp: **7.12:1** on the modal's white and 6.70:1 on the canvas, still unmistakably red rather than body copy.

**The compact card's date tile was reading the server's clock.** It called `date( 'M', $ts )` and `date( 'j', $ts )`, not `date_i18n()`, so the month and day on every compact card came from the server timezone rather than the site's and an evening event could render on the wrong day. It is a real bug, not a styling one. Both now go through `sfaf_ap_date()`, which gained `'month'` and `'daynum'` styles: a tile that stacks "Aug" over "4" as two elements still cannot use one formatted string, but splitting a date into two spans is still formatting a date and belongs in the formatter.

The month grid's cell label was `date_i18n( 'l j F Y' )`, giving "Monday 4 August 2026" in day-month-year order rather than AP's. It is the label a screen reader reads on every arrow-key move, and it now goes through the formatter's `'full'` style.

**Fifteen more call sites format a date outside the formatter, and they are reported rather than changed.** A sweep of all 36 source files classified every `date`, `date_i18n`, `wp_date`, `gmdate` and `->format` call by its format string: 50 are machine formats that have to be exactly what they are (`Y-m-d` keys, the ICS stamp, month slugs) and are not violations. Of the human-facing ones, the two fixed here were public. The highest-priority remainder is also public and is a hard DESIGN.md violation: `SFAF_Recurrence::pattern_label()` uses `date_i18n( 'jS' )`, so a monthly event's single event page reads "Every month on the 4th" where the guide says no ordinals, ever. The rest are caladmin, wp-admin and the reminder email, including one more server-clock `date()` in the WordPress admin's RSVP table.

VERIFIED: 31 files parse under PHP 8.3; the callable audit reports nothing unresolved across 99 functions and 27 classes, and its self-test finds 7 of 7 planted faults; the cascade audit holds at 8 findings, all previously confirmed deliberate; 14 measured pairings move from below their floor to above it and none remains below. Both gates re-run against the extracted zip, which diffs clean against the staged tree and has no backslash entries.

= 3.21.0 =

**The fetch results panel was written for whoever built the importer, and read by somebody running programmes.** It reported that the removal check "ran on a complete result set, nothing had gone", which is an internal guard answering a question nobody asked; it offered to let the reader "change this with the `sfaf_gfmp_import_statuses` filter", which names a PHP hook to a person who will never write one; and it carried an "Image field used (9)" disclosure whose table was nine rows of `none` in monospace beside a URL column reading None on every row. That table existed while the GoFundMe Pro image question was open. The answer is known and permanent, so the table and the collection behind it are both gone. The skipped-campaign count stays, without the hook name: "12 campaigns skipped because they are not active or published."

**It said four events were updated and never said which four.** That is the one thing the counts cannot reconstruct, and a source quietly overwriting a date or a description is exactly what a manager reads this panel to catch. Each source now lists the events it changed, by title, linked to the editor, with what changed on each: "date and time", "description", "donate link". `update_event()` already keyed its change list by label, so the panel prints those keys rather than keeping a second list of field names that could fall behind the first; only the adapter's own meta extras needed naming, so `_uc_gofundme_goal` reaches the screen as "fundraising goal" instead of as itself.

The summary line is plain now. A quiet fetch reads "Eventbrite: nothing new. 12 checked." rather than "0 new, 4 updated, 19 unchanged (of 23 fetched)", five numbers with four of them irrelevant. Quiet success, loud failure: a source that failed, timed out, or returned nothing where it used to return events keeps its own sentence, takes a tinted row and states it in the row's own weight, so it cannot be skimmed past.

**The subordinate element was the most prominent thing on the queue.** The "Needs an image, a description, a category and an organizer" disclosure had a 1px border, a 10px radius and a white background; the row it belonged to had none of those. The comment above the rule already said it was "quieter than the meta line above it, and carrying no card of its own", which was a correct description of something the code had never done. No border, no fill, no radius, and the summary is sized to its own text with `width: fit-content` instead of spanning the row, which keeps `display: list-item` and therefore keeps the marker. Open, the form is grouped by one hairline down its left and an indent: space, then a hairline, and a box not at all. Rows themselves go from 16px of padding to 22px, because a row carrying a title, a meta line, a link and a disclosure needs more air between two of them than there is inside one.

**Expanded, every waiting field repeated the same paragraph.** The adapter's note explaining that GoFundMe Pro supplies neither an image nor a description printed in full beside the image field and again beside the description field, on every event, down the queue. It is said once per event now, at the top of the panel the fields belong to. The per-field badge stays, because that is what marks which field is waiting; the sentence explaining why is the same sentence every time.

**The Remove control on Users belonged to no visible row and asked about no visible person.** It was a second `<form>` opening after the row's own form had closed, so it was a sibling of the row rather than part of it and rendered below the row's bottom border as a bare link. Its confirmation was `window.confirm` reading "Remove calendar access for this user?", which names nobody. It always posted the correct user id, and always had; nothing on screen said so. Remove now sits beside Save in the row's third column, reaching its own form by id through the `form` attribute, because forms cannot nest. The actions moved ahead of the categories disclosure in the source too: that disclosure spans the full grid, so anything after it was being pushed onto a new line, which is why Save had been sitting under the name column rather than in the row.

The confirmation names the person: "Remove calendar access for Mark Sapoznikov? They come off every team. Their WordPress account and role are not changed, and you can add them back at any time." It is a real `<dialog>` opened with `showModal()`, the same instrument the scope switcher already used, so the page goes inert, Tab is trapped and Escape closes without any of that being written here. Cancel is first in the source, so focus lands on the safe answer. This replaces `window.confirm` for every `data-uc-confirm` action at once, venue deletion and schedule-date removal included; `confirm()` remains the fallback where `<dialog>` is missing, because asking badly beats not asking. Removing calendar access still writes no roles and no capabilities: it deletes three user meta keys and drops the person from every team, and an administrator keeps full access through `manage_options` regardless, which is why their control says so and offers a different sentence.

**The public calendar's square pills, and the four other controls nobody had mentioned.** Reported in 3.20.0 and out of scope then. `.uc-calendar button` is (0,1,1) and every control we ship names itself with one class, so the reset won `border-radius` on all of them: the filter pills, the group pills, Add to Calendar, Get Reminders and the Load More button all rendered square. The rule is split rather than lowered wholesale, because its two halves want opposite things. `text-transform`, `letter-spacing` and the shadows are defences, and no control of ours ever wants a theme's uppercase back, so they stay at (0,1,1). `border-radius` and `margin` are a floor, and anything naming a shape of its own should beat them, so they drop to `:where(.uc-calendar, .uc-sidebar, .uc-upcoming-widget) button`. The floor keeps its type selector rather than going to zero: at (0,0,1) it still beats a theme's bare `button {}` on source order, and at (0,0,0) it would lose to it, which is the fault the rule was written to prevent.

Two more the same audit found, both fixed by raising the component rather than lowering the base, because those base rules are right to outrank a bare element. "See all events" in the embed sidebar was the one link there that did not underline on hover, its `.uc-sidebar-all:hover` losing to the `a:hover` reset. And the RSVP form's opt-in row is a `<label>`, so `.uc-rsvp-form label { display: block }` beat its `display: flex` and put the consent checkbox on the line above its own text.

**A correction to 3.20.0.** That entry reported that `.uc-card-title` loses its 6px bottom margin to the same button reset. It does not, and a reset on `button` could never reach an `h3`. `.uc-card-title` renders only inside `.uc-event-card`, where `.uc-calendar .uc-event-card .uc-card-title` sets `margin: 14px 0 0` at (0,3,0) deliberately. The 6px was never in play there and nothing is wrong. The mechanism was assumed from the neighbouring fault instead of being traced, which is the thing this project keeps writing down.

**Two checkers, both made to fail on purpose before being believed.** The cascade audit walks every element the public calendar actually renders, harvested with a tag stack so ancestry is read from the source rather than assumed, against every rule that can match it, and reports the winner per property with shorthands expanded so `margin: 0` beating `margin-bottom: 6px` is visible. Its first version assumed every widget root wrapped every element, which made two thirds of its output impossible: the RSVP modal is appended to `<body>`, so no `.uc-calendar` rule can reach it. Its second version named the fragments that DO land inside a widget, and that whitelist silently dropped `.uc-group-pill` and `.uc-reminder-btn`, both genuinely square, because each is emitted by a standalone function with an empty chain. The test is inverted now: anything not provably detached is assumed reachable, which can only over-report.

The callable audit is committed as `.claude/audit-callables.php` so the build gate is reproducible rather than rebuilt from memory each time. It resolves `sfaf_*`/`uc_*` calls, `$this->`, `self::`, `parent::` and `Class::` against an inheritance-aware index, checks arity both ways, and checks that a hook's `accepted_args` is not more than its callback declares. Its own first run produced six false positives on `sfaf_embed_context_flag()` and `sfaf_rsvp_count_store()`, reporting their definitions as undefined calls: PHP 8.1 returns the `&` in `function &name()` as a named token rather than the plain character, so testing for the character had silently stopped matching. That is the trap this project had already written down, found by running the checker rather than by trusting it. `--self-test` plants seven faults including that one and fails if any goes unfound or if valid code is flagged.

VERIFIED: PHP 8.3 parses every file; the callable audit reports no undefined callables and no arity mismatches across 32 files, 115 functions and 27 classes, and its self-test finds 7 of 7 planted faults with no false positive; `node --check` passes on the changed scripts; the cascade audit goes from 7 real findings to 0, with the 8 that remain confirmed as deliberate state and card-context overrides. Both audits re-run against the extracted zip.

= 3.20.0 =

**One line of CSS was repainting nine different things, and the sidebar was only the one somebody noticed.** The nav labels and icons looked dim because they were: `.uc-portal a` is (0,1,1) and `.uc-nav-item` is (0,1,0), so the page's link teal `#0E7680` won and the nav rendered at **2.30:1** on the dark sidebar. The rule saying `#D1D3D4` at 8.22:1 has been in the file since 3.16.0 and has never once applied, and the icons went with it because they are `currentColor`. Every measurement in the note above that rule was correct and none of it was on screen.

Walking each anchor's class list against every colour rule in the stylesheet, rather than looking at screens, found eight more: `a.uc-btn` and `a.uc-btn-sm`, so every link-shaped Cancel, Rename and Manage in caladmin; `a.uc-btn-primary`, teal on SFAF yellow at **3.87:1**, an actual failure; the All events / My events tabs; the pager; Log out; `a.uc-tlink` and `a.uc-source-link`. The fix is `:where(.uc-portal) a`, which contributes zero specificity: a bare link still gets link teal, and anything declaring a colour of its own now wins by declaring it once.

**The active nav item is a bar, a white label and a teal icon.** The old treatment made the whole signal out of colour, teal text on a 10% teal tint, and that is why it could not be pushed further: at 15% the tint flattens to `#32494A` and teal on it is 4.253:1, under the AA floor for a 14px semibold label. So the signal is now three things that do not compete for the same contrast budget. A 4px solid brand teal bar down the row's left edge, full height, 5.47:1 against the bare sidebar and 4.64:1 against the tint. The label white at 600, **10.47:1**, brighter than the 8.22:1 an inactive label gets rather than merely a different hue from it. The icon keeping brand teal at 4.64:1, which is an icon, so 3:1 applies. The tint stays at 10% and now has no contrast job at all: it is what makes the row read as one block behind the bar.

**Control spacing is a baseline, not a fourth instance fix.** Save Series got a margin of its own in 3.18.0, the RSVP settings form got 4px, the import queue form got 16px, and each time the answer was "add a margin to that one". Counting every control row in caladmin gives nineteen, and **eleven had no vertical spacing rule of any kind**: `.uc-actions`, `.uc-cat-actions`, `.uc-cat-form-actions`, `.uc-faq-set-actions`, `.uc-head-actions`, `.uc-page-head-actions`, `.uc-queue-actions`, `.uc-schedule-actions`, `.uc-scope-switch`, `.uc-team-actions` and `.uc-user-actions`. Three of those laid out with no gap either, so their buttons were flush horizontally as well.

The baseline is keyed on `[class*="-actions"]`, the naming convention the codebase already follows, so a row added tomorrow is spaced the moment it is named. It applies only to rows that are a direct child of something that stacks, the page column, a card, a section or a form, because a margin on a flex child in a horizontal row shoves the control out of alignment: `.uc-team-actions` inside `.uc-team-row` stays centred and untouched, the same class inside `.uc-team-members-form` gets its 14px. Both halves are `:where()`, so every figure that already existed still wins.

**A team shows its members.** The old screen listed every calendar user under every team with ticks meaning membership, which is a list of the calendar with some ticks in it: a team of four on a calendar of forty read as forty rows, an empty team looked like a full one at a glance, and it grows with the calendar rather than with the team. The team now lists the people in it, each with a remove control, and everybody else is behind an Add member picker that offers only calendar users who are not already in, with a type-to-filter box over them. Same source rule as the notification picker. Nothing saves until Save is pressed: a removed member stays on screen struck through and a chosen candidate stays ticked, so what is about to happen is readable before it happens, and Cancel is a plain reload that discards it. "Close" is gone, replaced by the rotating chevron the RSVP settings screen uses, because Close describes neither what it does nor what will happen.

The 3.13.0 `$offered` guarantee survives it, and is now structural rather than remembered: the member list asks the TEAM who is in it, so a member without calendar access appears there and gets a checkbox instead of being invisible to the form that saves them. Sixteen assertions cover it, six of them exercising the real `SFAF_Teams::save()`: a rename that posts no members keeps all three, a member outside the offered set is kept, an offered member who was unticked is removed, a ticked candidate is added, a member whose WordPress account has been deleted keeps their stored id, and the order stays stable.

**The embed sidebar heading is a banner, and most of it had never applied either.** 3.19.0 gave it a top margin, 12px of padding, a left indent and a hairline underneath, and not one of those reached the screen: the scoped host-proofing reset says `.uc-sidebar h3 { margin: 0; padding: 0; border: 0 }` at (0,1,1) against the heading's (0,1,0). The reset won every property it declares and the heading rendered as a line of bold text sitting on the first row. Same fault as the thumbnail in 3.18.0 and the same fault as the nav above. Two classes beat two, and it is now a centred band across the top of the card: 10% brand teal pre-flattened to `#E8F9FA`, Montserrat 18/700 in `#1A1D21` at **15.59:1**, a hairline under it and the card's own top corners. The same restraint as the caladmin card headings, and for a stronger reason, since this renders inside somebody else's programme page. The three heading states from 3.18.0 are unchanged: absent means the default, empty means none.

**Found and not fixed, because it is a different surface.** The same reset outranks fourteen single-class rules in `calendar.css`. Most are harmless, where the reset and the class agree. Two are not: `.uc-filter-btn` and `.uc-group-pill` both ask for `border-radius: 99px` and the button reset's `border-radius: 0` beats them, so every filter pill and group pill on the public calendar renders square. `.uc-card-title` loses its 6px bottom margin the same way. Reported rather than changed: nothing on the public calendar was in scope here.

VERIFIED: 31 PHP files parse under PHP 8.3; the callable audit resolves 99 plugin functions, 186 `$this->` calls and 372 `Class::` members with nothing unresolved; five scripts pass `node --check`; three stylesheets balance; 16 executed assertions on the `$offered` guarantee; the anchor cascade audit goes from 9 outranked to 0. Zip extracted, diffed file by file against the tree and linted from the extract.

= 3.19.0 =

**The accent bar was never a border, which is why three passes of grepping for one found nothing.** It is a real element: `<div class="uc-compact-accent">`, absolutely positioned down the left edge inside a card that is position: relative and overflow: hidden, with its color written as an inline style in PHP. The stylesheet only ever sized and placed it. So every search for `border-left` came back clean and every one of those searches was answering a question about the wrong thing.

3.18.0 made that worse by citing a git check as evidence. The check was real and its result was true: `.uc-sidebar-row` did lose its border-left in 3.17.0. But it asked "was the change I made applied", not "what is drawing the bar on screen", and those are different questions. A verified change is not a verified outcome. The check that should have run, and that ships as a build assertion now, starts from the element as rendered, works out which rules in any stylesheet can match it, and looks for a left edge by any mechanism: border, inline-start border, or a narrow full-height absolutely positioned strip. It also looks for renderers that emit one.

The bar is gone from the compact rows, and the 22px of left padding that replaces it matches what the sidebar rows got in 3.18.0.

**The sidebar heading reads as a heading.** It was 15px at weight 600, a hair above the row titles under it, so it looked like a first row. It is 19/700 in Montserrat now, two clear steps above them, with real space and a hairline before the first row. Because the block is pasted inside somebody else's page, where an h3 may already carry a theme's own article treatment, the rule states everything rather than relying on defaults: the face, the size, the weight, the case and the letter-spacing.

**Pending events, quieter.** The timezone said "(America/Los_Angeles)" on every row of a queue run by people in America/Los_Angeles, which can never change a decision. It now appears only when it differs from the site's own, which is exactly when it is worth knowing, because an imported event in another zone is a real trap. The location was the full postal address on every row, mostly the same address repeated; it is the venue's name where the event names a venue, or the first line of the address otherwise, and it is omitted rather than padded with "Location not set" when there is none.

**The removal confirmation says what happens and stops.** Three paragraphs went: that the past is never deleted because those events are the record of what happened, that the description lives on the series rather than on the events, and that registration records outlive their events on purpose. All three were true and none of them told somebody standing at that decision what to do or what would happen to them. What is left is the counts, the two options in a line each, that neither can be undone, and one short line naming what goes with the series. The yellow callout is gone, and registrations are not mentioned at all, because nothing on that screen touches them and a reassurance about something that is not at risk is one more thing to read before deciding.

The same test was run over the rest of caladmin, since that screen survived the last pass. Five more justifications went with it: why team membership resolves at send time, why registrations for deleted events are kept, why the opt-in table has two evidence columns, why a series is created implicitly, and how venue addresses are stored. In every case the consequence stayed and the defence of the design went.

**All events / My events, on the dashboard and the Events list.** Defaults to My events for everybody. For an admin or an editor it is a filter over a list they may see either way. For a contributor it is a genuinely different screen.

Other people's events are drawn by a separate renderer that shows title, date, time, location, category, organizer and published status, which is exactly what an anonymous visitor reads off the public calendar for the same event. The separation is the safety argument, not a flag: that renderer contains no call that can produce a registration count, no link to the editor, no link to the registrations screen, no form and no anchor of any kind. It could not leak participant data by being edited carelessly, because it would have to be given the ability first. The same reasoning, and the same decision, as the read-only dashboard list against the full events table.

The RSVP column is absent rather than empty. Not the number, not a dash, not a blank cell: a table has one column set for all its rows, so in that view the column does not exist. A contributor who wants their own counts switches to My events, where every row is theirs. Nothing in the wider view is clickable either, because a link would mean another surface that has to exclude participant data correctly, and the last three permission defects here were all of that shape.

Widening the query takes an explicit argument. Callers that say nothing keep the access rule they were written with, so the six that predate this release behave exactly as they did, and only the two screens offering the toggle can lift it. A default that showed everything and relied on each caller to narrow it would be one forgotten argument away from a disclosure.

The scope is not a way to acquire access. Recent activity and the RSVP total stay on the 3.18.0 rule under both scopes: everything for an admin or an editor, own events only for anybody else. The toggle moves which events are listed; it never moves the gate.

= 3.18.0 =

**The series removal copy is gone, and the reason it survived two instructions is that neither one reached the file.** `git log -S` on the paragraph returns exactly one commit: 3.13.0, the one that added it. No commit since has changed a line containing that string. 3.17.0 did edit the two lines directly above it, wrapping the heading in a card band, so the block was open and being worked on and the paragraph beside it was left alone. That is the same finding as the RSVP banner in 3.16.0 and it took the same thirty seconds to establish. What is there now is a red Remove series button and nothing else. Everything the paragraph described, the two options and the counts, is on the screen the button opens, where the numbers are real.

**American English throughout.** Every user-facing string in caladmin, the public calendar, the emails and this readme was swept with a scanner that separates copy from code, because a blanket find and replace cannot tell "the color of its card" from `sfaf_category_color()`. 82 hits in copy, 70 of them in this readme, converted: color, behavior, program, recognize, organize, organization, center, gray, canceled, canceling, labeled, neighbor, optimizer. The Categories screen carried the British spelling of Color in a field label, which is where this started.

Two were deliberately left. The `cancelled` RSVP status is a value stored in the database and named in SQL, so only the label it maps to on screen became Canceled; changing the key would have orphaned every canceled registration. And code comments are developer text and are not copy, so the 113 hits there were reported and not rewritten.

**Pending events read as a list of events instead of a table of columns.** It was five columns wide, and a table gives every column the same weight, so the title, the platform badge, a timezone string, an address and two verbs all arrived at once with nothing saying which was the thing. A queue is scanned for which event this is and then decided about. Each row now leads with the title as a real heading, with source, when and where beneath it in one quiet line, and the actions to the right. The disclosure carrying the fields the platform cannot supply is still there and is now plainly subordinate to its row rather than a full width cell of its own. Times in that queue were also being printed as the raw stored value, so a 6pm event read 18:00-19:30 on a screen where every other time is AP style; they go through the one formatter now.

**Sidebar mode.** The accent bar was removed in 3.17.0 and `git log -S` confirms it left the stylesheet then; there is 10px of padding before the thumbnail in its place, so a row starts with space rather than with a stripe.

The thumbnails were cropping badly for a reason worth naming. 3.17.0 gave them width and height of 100 percent with object-fit: cover inside a fixed box, which is correct and never applied: the scoped reset at the top of the stylesheet says `.uc-sidebar img { height: auto }` at two classes and one element, and the thumbnail rule was one class. The reset won, the height went back to auto, and object-fit has nothing to work with once the box is the image's own shape, so every picture was clipped from the top by the wrapper instead of being cropped from its middle. The same shape of fault as the 3.10.0 radios: a rule that wins on paper and loses in the cascade. The thumbnails are now 60 by 45, rectangular rather than square because event photography is landscape and a square crop throws away the sides, and the centering is stated explicitly rather than left to a default, because that is the line somebody will look for the next time a picture crops wrong.

Sidebar blocks also take a configurable heading, defaulting to "Upcoming event dates", with a field in the embed generator and a data attribute so it travels with the pasted snippet. Three states, not two: a block that never mentions a heading gets the default, which is what keeps every embed pasted before this release working; a block carrying an empty heading renders none, because clearing the field is a real instruction. That distinction is preserved at every step, which is why the shortcode attribute defaults to null, the REST argument declares no default at all, and the embed script sends the parameter only when the block actually carries the attribute and sends it even when it is empty. No new REST route: every CORS mechanism in the embed is gated on an exact route string match, so a second route would pass every test here and be blocked by the browser the moment sfaf.org asked for it.

**The dropdowns on the embed code page had no affordance, and the cause was one word.** WordPress draws the arrow on its own selects as a background-image and sets appearance: none so the platform does not draw a second one. Our control styling set `background: #fff`, and `background` is a shorthand: every declaration of it resets background-image to none. Same specificity as core, loaded after it, so it won and deleted the arrow while leaving appearance: none in place. What is left is a white box that happens to open a menu, and on the embed generator four of the five controls are selects.

This is not the fault the 3.16.0 sweep found, and the difference is worth stating. That one was a control styled only because of the container it happened to sit in. This one is the opposite: the control was reached and styled correctly, and a shorthand inside that styling wiped something the framework was supplying, which a container audit cannot detect because nothing is unstyled. The :where() baseline could not have caught it either: it lives in the portal stylesheet, is scoped to the caladmin body class, and that stylesheet is not loaded on a WordPress admin screen at all.

The chevron is now ours rather than the framework's, drawn as an inline SVG in the brand's own stroke weight, on both surfaces so they agree. Every rule in either stylesheet that can match a select and used the background shorthand was converted to background-color, because there were eight more of them waiting to do the same thing.

**The dashboard was showing a contributor things they have no part in.** Two of its seven elements ignored access level entirely. Total RSVPs counted every confirmed registration on the calendar, and Recent activity read the whole registration table, which is the worse of the two because it names people: a contributor read that a named person had registered for an event they cannot open, edit or see the registrations for. That is the same family as the ungated RSVP screen found in 3.5.0, and it matters more than usual on a calendar carrying HIV, substance use and trans health programming, where the size of a group is itself worth not saying.

The other five were already scoped. The events counts take an author, and Next events and the edits half of Recent activity both go through the query helper that applies the author filter for anyone who is not an admin or editor. That is the shape of the fault: two lists built around the helper that knows the rule, five built through it.

Both now scope by joining registrations to their event's author. Pending review and Needs attention stay unscoped inside their admin gate, because reviewing other people's submissions is an administrator's job and those totals are exactly what they can act on. The labels changed too: a scoped number under an unscoped label is still misleading, so a contributor sees My events, My upcoming, RSVPs to my events and Recent activity on my events.

= 3.17.0 =

**The language toggle 3.16.0 built has been removed, and it should never have been built.** The instruction was to tidy an existing control. Nothing in this plugin rendered English or Espanol, and caladmin emits its own document with no wp_head and no wp_footer, so nothing could have injected one either; the correct answer was to report that the control does not exist here. It belongs to Weglot. Gone with it: the sidebar form, the save handler, the locale write, the switch_to_locale call and the CSS. The rule this leaves behind is worth more than the code was: when an instruction says move, style or fix something that does not exist, say so rather than creating it.

Weglot's own dropdown is hidden on caladmin screens instead. Hiding only, and scoped to those screens: the switcher is appended after the closing html tag, which every browser reparents into the body, so it landed on top of the sidebar. Nothing here changes how Weglot runs and every public page keeps its switcher untouched.

**Why no animation was appearing, which was two faults and one probable third.** The entrance ran on DOMContentLoaded, which fires after the first paint, so the page drew itself complete and only then set the blocks back to invisible to fade in from. That is not an entrance, it is a flicker, and on a fast machine it is over before it registers as anything. The call that did it was also the last of nineteen initialisers running as a bare list, where a single exception in any earlier one silently removes every one below it, with nothing in the console to say so.

The switch now sets a class on the html element from an inline script in the head, before anything is painted, and the stagger is nth-child rather than a custom property written by JavaScript, so once that class is set the whole thing is CSS and nothing in portal.js can break it. Every initialiser in caladmin and on the public calendar is now isolated: one failing is contained to its own feature and named in the console rather than taking the rest down. The public reveal moved to the front of its list, because it decides what the page looks like on arrival and should not sit behind eight functions that could throw first.

The third possibility is the one to check first and cannot be checked from here: a machine that asks for reduced motion suppresses all of this correctly. That is now readable rather than guessable. The html element carries data-uc-motion, reading "on", "reduced" or "no-observer", and inside an embed the block carries it; if the attribute is absent entirely the script never ran, which is a fourth and quite different answer. The non-reduced path was verified to contain the animation rather than being empty.

**Sidebar mode rebuilt.** The colored bar down the left of every row is gone and nothing replaces it. In the placement this mode is for, beside a program or on a series page, every event in the list is the same program, so ten colors were saying one thing. Each row is now a 44px rounded thumbnail, the event's own image or its branded category tile, the title as the primary line, and the date and time on a quieter line under it, with a hairline between rows and no boxes, stripes or left borders anywhere. The list is static: no scrolling loop, no auto-advance.

**Cards lift themselves, because they cannot lift the page.** A white card on a near-white section divided from the next by a hairline is very close to no card at all, and the embed does not control its host's background. Two shadows: a 1px contact edge at 4 percent so the card does not float unattached, and a 20px ambient spread at 7 percent that does the lifting, with the hairline one step stronger. Neither is visible as a shadow; what is visible is that the card has an edge. Hover deepens it, which is the other half of the sentence the View event button starts. On a dark host section black-based shadows contribute nothing and neither does a light hairline, and that is the right outcome: a white card on a dark background is the highest-contrast edge there is and needs no help. Nothing is keyed to the host's background, which the embed cannot read.

**The card heading treatment was being applied per screen, so it drifted.** Sixteen of thirty-nine cards in caladmin did not have it. On the series editor the Schedule card had the teal band and the details card immediately above it did not, which is what prompted this. Two cards had a bare heading taking the generic subhead style instead, and five had their heading floating above them as a separate element, which was a second way of heading a card.

The fix is at the component, not the screen: a heading that is the first element of a card now gets the band by being that, so a card cannot be built without one. Every card that had no heading at all was given one, the second pattern is gone along with its now-dead rule, and danger cards color the band rather than the heading text, since uppercase and tracked was already saying "heading" and the red was a second signal doing the same job. Dark red on the pale band measures 9.16:1. Screens that were missing it: the fetch report, Events, Series, the series editor, the series removal screen, Email Opt-ins, Pending, the imported queue, Refresh from source and FAQ Sets.

The Save Series row also had 22px above it and nothing below, with the Schedule card immediately after, so the most consequential control on the screen sat a few pixels off an unrelated section. A form's actions are the end of the form and now have 36px saying so.

**Registration settings read as four subsections.** "When somebody registers", "The morning-of reminder" and "Replies" had 16px and a hairline between them and headings with no room to act on their size; Registrations, the capacity and the on/off switch, had no heading at all. Each is now a section with 28px above its rule and 22px below, so every subhead has a clear band of nothing before it. The rule stays a hairline rather than getting heavier, because space is doing the work and the brand guide is explicit that rules and boxes are what clutter a layout. The shared field list is untouched: Registrations is claimed by name and the catch-all still takes the remainder, so a setting added later still appears on both screens without either being told about it.

**Public brand audit, against the guide (v3.0).**

TYPEFACES. Every rule asked for Montserrat and Merriweather and nothing anywhere loaded them. The stylesheet's own note said the host theme already did, which is an assumption about one host on a stylesheet whose purpose is to be adopted by any host: on the resources site, where the single event pages live, and inside an embed on a third-party page, both families fell straight through to Segoe UI for every heading and Georgia for every paragraph. The brand was correct in the source and absent on screen. Both are now loaded by the stylesheet itself, which is the only mechanism that reaches an embed, since embed.js adopts this file by URL and cannot enqueue anything. Two elements also carried a platform UI stack that overrode the brand: the upcoming-events widget and the embed's own shell, which is what a visitor sees first on a slow connection.

DATES AND TIMES. Wrong on every count the guide lists, in eleven separate places, because each place formatted its own. Cards said "6:00 PM to 7:30 PM"; the month grid said "6:00pm"; the sidebar said "6:00 PM", all from the same two fields. There is one implementation now: am and pm lowercase, one space, ":00" dropped, an en dash for ranges, and the first meridiem omitted when both ends match, so "6-7:30 pm" and the guide's own "10-10:30 am". Applied to the cards, the month grid, the sidebar, the event page, the series lists and the reminder emails, which had been following whatever the site's date format setting happened to be and would have printed an ordinal on any site that set one. The ICS export is unchanged and deliberately so: its timestamps are machine fields and there is no human-formatted time in it. AP also abbreviates some months with a full stop when they carry a date; the guide does not call that out and a full stop inside a compact date badge reads as a typo, so those stay as they are.

PUNCTUATION. Em dashes removed from every string a visitor or a manager can read, on the public pages and in caladmin. Headlines carry no punctuation.

COLOR. Yes, it read as a kaleidoscope, and the reason was that the category hue landed in three places on every card: the chip, the placeholder tile and the two small meta icons. Across twenty cards drawing on ten approved colors that is up to sixty colored elements with no neutral field left for any of them to read against. The color that carries information stays, since the chip names the category in words and the tile is its picture. The color that carried none has gone: a clock is a clock in every category. The month grid keeps its per-event accent, examined and deliberately kept, because a month mixes categories by definition and there the bar is the only signal there is.

ICONS. The premise needs correcting. The clock and pin on the cards are not generic marks from a third-party set; they are the plugin's own, drawn to the construction rules the guide sets for new icons: simplest shapes, consistent line weights and corners, and readable as shorthand. The guide also refers to a custom-designed SFAF icon set available for web and print, and those files are not in this repository. If they are supplied, swapping to them is one edit, because every icon in the plugin comes from a single table. Substituting icons that are not available was not an option.

TYPOGRAPHY AND RESTRAINT. No drop shadow on text anywhere. Contrast between levels is made with weight and size rather than decoration, which is what the sidebar rebuild and the subsection spacing both do.

= 3.16.0 =

**The sensitivity banner on Registrations is gone, and it did not "fail to take" last time, it was never touched.** It was added in 3.5.0 and no commit between then and 3.15.0 changed the line it sits on, so there was no wrong element, no second copy and no override: the removal simply never reached the file. There was exactly one of it in the entire plugin, and the CSS rule that positioned it went with it. Nothing about who can see this screen has changed. Viewing it and exporting it both still begin with the same access check, and the export still verifies its nonce; the export has never carried the warning text, so there was nothing to remove there.

**Registration settings on that screen now start closed, sit hard left, and open on a real chevron.** It used to open itself whenever the list was empty, which moved the registrations down the page for a reason the reader could not see; somebody arriving here came for the registrations. The heading and the status line were thrown to opposite edges of a 1100px card by a space-between; they stack at the left now. The marker was a three-pixel gray triangle, and it is a 28px ringed teal chevron that turns a quarter circle on open. It sits on a summary element, so click, tap, Enter and Space all work with no JavaScript at all, and aria-expanded is written into the markup and kept in step on every toggle.

**"New series" moved to the foot of the Series section.** It was in the page head, above both sections, which put the rarest control on the screen in its most prominent place and made the two halves work differently: Categories offered "Create a category" at the bottom of its list and Series offered its equivalent above everything. Both are now "here is what exists, and here is how to add one".

**Every form control in the plugin was enumerated with a tokenizer, and the reason some were still unstyled is structural rather than a list of misses.** 346 controls across caladmin and the plugin's own WordPress screens were walked, each one resolved to the wrapper classes actually above it, and each verdict checked against the rules that exist. Until now a control was styled because it happened to sit inside a container that styled it, a field wrapper, a filters bar, a login form, a picker filter, a dozen of them, so a control rendered outside all of them fell through to the browser and nothing said so. That is why the opt-ins search box was raw browser chrome for four releases while the identical box on Registrations was not: one form carried the class that styles inputs and the other carried one that styles a select and nothing else. The previous sweep covered the controls somebody opened a screen and looked at; it could not cover the ones nobody had opened.

So the baseline is now a property of being a control in this portal, and the wrappers only refine it. A new field in a new card is styled before anybody writes a rule for it. The fallback is written at zero specificity so it can never outrank a class rule, the same specificity accident that once repainted a button through a plain link rule. Checkboxes and radios are tinted with accent-color rather than boxed, which recolours the platform's own control instead of replacing it, so what a keyboard and a screen reader get is unchanged. Date, time, datetime-local, month, week and file inputs are only partly ours: the box, border, radius, font and focus ring are set here, and the calendar popover, the time spinner and the "Choose file" button are browser shadow DOM with no standard hook. Those six are listed rather than chased.

The three named faults are fixed at their cause. The opt-ins search box wears the same class as every other search on the portal. Dismiss and Restore in the pending queue had a class with no rule behind it, which is invisible on a link and leaves browser chrome on a button, so they were native gray buttons in a row of links next to Publish, which is a different class and is reset, one rule now covers both elements. And the row actions were 13px, one step below body text, on the only controls on that screen that do anything; they are 14px.

The plugin's own WordPress admin screens got the same treatment, scoped to those two roots and no further. The rest of wp-admin is not ours to repaint.

**Fetch updates is a utility action and now looks like one.** It was a plain outline button, indistinguishable from Export CSV on a screen where the two do very different things. It is solid darkened teal with a white label, 5.35:1, its fill measuring 4.95:1 against the page background so the control's own edge clears the 3:1 floor without a separate border, and it carries a refresh mark that turns while the fetch runs. Yellow stays the single primary and is not spent on this. Refresh from source gets the same treatment, because it is the same job.

**The sidebar is dark, and every pairing on it is measured.** This is the opposite case from the rest of the portal: the darkened teal that exists because brand teal fails on white is itself unreadable on Dark Gray, at 2.30:1, and brand teal at full value is the correct choice there. Inactive items are Light Gray at 8.22:1, brightening to white at 12.34:1. The active item is brand teal for both label and icon on a teal tint, with a teal edge marking its position. The tint is 10 percent rather than 15: at 15 the flattened background puts brand teal at 4.253:1, under the AA floor for a 14px semibold label, and at 10 it measures 4.638:1. Ten percent is the most tint this can carry and still let the label be brand teal, which is the point of the treatment. Every nav item has an icon that takes the row's color rather than declaring its own. Pending carries a count of everything waiting, submitted and imported both, in brand yellow with Dark Gray on it at 8.92:1, and that is the only yellow in the sidebar, on the one thing that needs acting on. There is no collapse-to-icons: a rail of glyphs is a memory test.

**The language toggle moved into the sidebar footer and became a real control.** English and Espanol were two unstyled links at the bottom of the content flow. They are a segmented control now, the same pattern as the recurrence switch, with the active language filled in brand teal and Dark Gray on it at 5.47:1. Not flags: a flag names a country and there is no country called Spanish, so the mark is a globe. It posts, carries a nonce and returns to the screen it was pressed on, and it writes WordPress's own per-user language key rather than a private one, so the choice made here and the choice on the WordPress profile screen cannot disagree. Everything that goes through WordPress follows it immediately; the portal's own sentences are still literal English in the source and will follow when they are wrapped and a translation exists.

The signed-in user and their access level are in that footer too, as one block. They used to be two halves of an answer in two places: a bare role chip at the foot of the sidebar and a name in the top bar.

**Soft entrance animations, and not one of them can leave anything invisible.** Every rule is behind a class that JavaScript adds, and the animations carry their hidden state in the animation itself rather than in a base rule. With the script blocked, slow, or never run, the page is a normal finished visible page. There is no state in which content waits to be revealed.

In caladmin the nav items slide in from the left 30ms apart on the first load of a session, not on every navigation, because every screen here is a full page load and an unconditional stagger would replay on every click. Content blocks rise a few pixels and fade, 40ms apart, 300ms each, ease-out.

On the public calendar each list card animates as it reaches the viewport, and its image eases from 1.04 down to exactly 1.0 over the same beat inside a frame that already clips, so the crop at rest is identical to the crop with no animation at all and no card is left showing a different piece of its picture than its neighbor. Once per card: the observer stops watching on the first crossing, because a long list that re-animated every time it scrolled back past something would be unusable. Never the month grid, forty-two staggering cells is a fault report, not an entrance. Cards appended by Load More and by a search are picked up too.

On an event page the header and the picture play on load and the sections below are revealed on approach.

The embed runs inside sfaf.org's page, where the theme may well have its own scroll behavior. An IntersectionObserver is a private object with no global handler and no shared registry, so a host running its own reveal script cannot see ours and ours cannot see it; the class name is ours and the rules using it are scoped to our root. If the observer cannot be constructed, or the browser has none, nothing is marked at all and every card is simply visible.

All of it is transform and opacity only, both composited, neither causing layout, so nothing moves while a card settles, and all of it is wrapped in prefers-reduced-motion: reduce. That matters more than usual here: motion sensitivity is not a rounding error in the audience for a health services site.

= 3.15.0 =

**"Replies go to" was on screen twice, and the mechanism that prevents that is the same one that caused it.** Every RSVP setting is drawn through one method and claimed by name, with a final call that takes whatever is left over. The notification box IS the "notify" setting, and it also drew the reply-to field by hand. A field drawn inside another field's renderer is invisible to that bookkeeping, so the catch-all drew it a second time. Reply-to now draws itself, carrying its own heading, and nothing else renders it. The same fault was on the registrations screen for the same reason, because both screens end at the same catch-all.

Nothing else was doubled: capacity, accept RSVPs, the organizer address and the new-RSVP toggle each appear once, and a test now asserts that by counting them in the source rather than by inspection.

**A type scale, applied across the whole portal.** The colored card headers fixed the outermost level and stopped there, so inside a card a subhead, a field label and a line of helper text were all within a point or two of each other. There are five steps now and every one is visibly different: page title, section, card heading, subhead, then body with helper text below it. The subhead was the missing rung and is the one that changed most: larger than body, weight 600, in Montserrat, with real space above it, because a heading with air over it reads as starting something and one without reads as continuing. This lands hardest on the registrations screen, where the headings were closest to body text.

The card heading stays smaller than the subhead on purpose. Uppercase, letter-spacing and a colored band across the card are what make it dominant, and no other level has any of the three; making it larger as well would push every field further down the page for a distinction that is already unmistakable.

**Chosen recipients are chips.** The picker was a scrolling column of checkboxes that was both the way to find somebody and the record of who was chosen, so "who is on this list" was a question you answered by scrolling, and the answer moved as you filtered. The list finds people; chips below it hold the answer, each with an x. Teams are marked as teams, because "Philanthropy" and a person's name otherwise look like two people. It reads the same way as the address pills further down the card, so the whole card speaks one language. The checkboxes are still what post, so with scripting off the plain list is exactly the control it has always been.

While fixing that: the live recipient count had been reading the address textarea by id since 3.14.0 replaced it with pills, so typed addresses had silently stopped counting. It reads the pills now.

**Pickers list people with a calendar user record, and only those.** This narrows what 3.14.0 widened, and the distinction is the point. Calendar ACCESS comes from manage_options: a WordPress administrator has full access and can never be locked out, which is the 3.7.0 fix and is untouched. Picker VISIBILITY comes from having a record under Users and Permissions. An administrator who has not added themselves has full access and does not appear in pickers, which is correct: a picker is a list of the people who work on this calendar, not of everybody who could. 3.14.0 fixed the symptom by widening the list, which quietly made those two things the same list.

**The series schedule shows what is coming up.** Past dates are folded behind a summary that names how many there are, and it starts folded. A weekly group two years old has a hundred past dates and four upcoming ones, and this screen exists to answer the second. Nothing there can be edited from this screen anyway, and the Events list already has an Archived view holding all of it, which the summary links to.

**Categories can be managed in caladmin at last, on one page with Series.** Both answer how the programming is organized, neither is site configuration, which is the same reasoning that moved venues in 3.13.0.

**Three things were broken, not one.** Categories could only be edited in the WordPress admin, and only their name could be set there. Color was term meta with no form field anywhere in the plugin: the only thing that ever wrote it was the sample-data seeder, so every category anybody created by hand was permanently the default teal. Icon was not stored at all, but mapped from five exact category names, so a sixth category could not have one and renaming a category silently took its icon away. And the branded placeholder had a second hard-coded name map with its own colors that ignored the stored color entirely, naming two icons that do not exist in the icon set and therefore never drew at all.

All of it now reads from the category. The color picker is the ten approved brand colors as swatches and nothing else can be saved, enforced on the way in as well as in the form. The icon is a curated list, checked against the icon set before it is ever returned, so a key that does not exist can never reach the page. The five categories that predate this screen keep the icons they have always had, so nothing on the calendar changes appearance today.

A category created without a color gets the documented default and stores it, rather than holding an empty string that readers have to guess at. Every brand color has a measured foreground for the placeholder label: Dark Gray or white, whichever wins, all ten clearing the 3:1 that large text requires. Deleting a category is allowed rather than refused, unlike a venue or a team, and the difference is deliberate: its events stay exactly where and when they were and simply lose the classification, which is visible and reversible rather than data loss. The confirmation names the count.

= 3.14.0 =

**The portal is on brand, and it follows the one rule the brand guide is explicit about.** The guide approves ten colors and then says not to use them all, because too many produces a kaleidoscope: combine a neutral gray or black with one or two accents and use lighter and darker variations of those for contrast. So this is Dark Gray and white, teal as the single structural accent, and yellow reserved for one job.

Every card now carries a 3px teal edge and a heading band in teal at 10 percent. Card headings are Montserrat, the brand's web headline face, uppercase and semibold at 14px in Dark Gray. That is three differences at once, which is what makes a heading unmistakable at a glance rather than on inspection; the complaint was that everything read at the same weight, so a card heading looked like a field label. A heading does not have to be large to be a heading, it has to be different, and making it large would only push the fields down the page.

**Yellow means "this is the action" and nothing else.** Save and Add. The moment it decorates a heading or a border it stops carrying that meaning and there is no color left that does. Body text is Dark Gray rather than pure black. Category colors are untouched: they are a different system with real meaning behind each hue and have been contrast-checked since 3.1.0.

**Contrast was measured, not chosen by eye, and one brand value did not survive it.** Brand teal is 2.26:1 against white and has been banned as a text color here since 3.1.0. The guide permits darker variations, and its own #0E818C is the usual one, but that measures 4.27:1 on the new teal heading band and 4.28:1 on the page background, both below AA. Teal that carries text is therefore one step darker again at #0E7680, which clears 4.5:1 on white, on the page background and on both bands. Nineteen pairings are measured and all pass. The 3px card edge is deliberately brand teal at 2.26:1 and is decoration: it carries no information, and removing it entirely would lose nothing but the brand.

**Montserrat loads from Google Fonts** with preconnect and display=swap, so text is readable in the platform's own face immediately rather than invisible while the file arrives.

**The recurrence control is rebuilt, and the engine behind it grew to match.** The old single dropdown described the arithmetic rather than the schedule: "every Thursday" was spelled "Every week" and only meant Thursday if the date above happened to be one, and a group meeting Tuesdays and Thursdays could not be expressed at all.

It is now a segmented control, Never, Daily, Weekly or Monthly. Weekly reveals an interval and seven day circles, so a group that meets twice a week is one setting. Monthly offers the date of the month or a chosen ordinal and weekday, including **last**, which is genuinely different from fifth: a month with four Fridays has a last Friday and no fifth one. An ends block offers no end date, a date, or a number of occurrences.

**A plain-language summary underneath states what it comes to and how many events that is:** "Every week on Wednesday, until Dec 31 2026. 21 events will be created." Generation makes that many independent posts, once, so the number belongs in front of the button rather than after it.

**What the engine already did, and what it did not.** Daily, weekly, every two weeks, monthly on the same date, and the nth weekday of the month all existed. What did not: several weekdays in one pattern, any interval other than one or two weeks, choosing the ordinal and weekday rather than inferring both from the start date, "last" as distinct from "fifth", and ending after a count rather than on a date. All five are new. Every pre-existing pattern produces byte-identical dates, which is asserted rather than assumed.

**Recurrence stays disabled on imported events**, unchanged since 2.9.0.

**FAQs moved up**, directly below Event details. They are the event's own content and belonged with it rather than at the foot of the page.

**Notifications now leads with the question people actually open it to ask:** who gets told when somebody registers. That was answered by a lone checkbox in a card called Organizer contact, three cards away, which is why that card looked like it had no purpose. It had one, it was filed under the wrong heading. The card is three sections in the order things happen: when somebody registers, the morning-of reminder, then replies. **Organizer contact is gone**, and nothing was lost with it: both of its controls are one setting and are now the first thing in Notifications.

**Reply-to stays, and stays separate.** The notification list is who receives mail; reply-to is who fields the replies, which is very often a different answer and very often a shared mailbox. Folding it into the list would make "reply to all the recipients" the only expressible option.

**"Anyone else" is an address at a time, as removable pills**, instead of a textarea of one per line. A bad address is refused while you are still looking at it rather than after a save and a page reload. Each pill is a ticked checkbox, so it works with scripting off and reads correctly to a screen reader. The separate "Currently on the list" block is gone: it restated what the picker already showed, one save behind, so the two disagreed until Save was pressed and nothing said which was true.

**Why typing a name into the Individuals filter found nobody.** Two faults with one root. The list was built from users carrying the calendar-role record and nobody else, but calendar access has been decided by manage_options first since 3.7.0, precisely so an administrator cannot be locked out of their own calendar. So the site's administrators were absent from the picker: real calendar people, invisible to it, and typing one of their names genuinely matched nothing. The same query fed the team membership picker, so they were missing there too. One method answers "who counts as a person on this calendar" now, and both pickers use it.

The second fault is why the message appeared before anything was typed: it was only ever updated inside the keystroke handler, so its state was whatever the markup left it as until somebody typed. It is now set on load from the list actually on screen, and only ever shown in answer to a query. A panel that renders no options at all says so plainly instead of offering a search box over nothing.

**The event's own location is street, city, state and ZIP.** 3.13.0 gave venues those four fields and left the "a different location" branch as a single free-text line, so the same thing was structured in one place and a sentence in the other. It was never a rendering fault: the fields existed only on the Venues screen and had never been added to the event's own entry.

**Existing single-line locations survive, parsed by the venues parser, unchanged.** Only a trailing ZIP and a bare two-letter state code are treated as certain; anything uncertain stays in street whole and nothing is ever dropped. That parse happens when the form is drawn rather than as a migration pass, so nothing is rewritten until somebody saves and looks at the result. Everything still reads one composed line, so no reader had to change.

= 3.13.0 =

**The event editor is two columns, two thirds and one third.** Six equal columns still read as a scatter, because a card's width said nothing about what the card was for and pairing cards became a height-matching exercise. The split is now by what a card is. The main column is the event itself: Event details, Classification, Schedule, Location, Notifications, FAQs, every one of them full width, so no card in it ever sits beside another and the eye goes straight down. The side column is the settings that are read rarely and changed rarely: Capacity, Donate, Organizer contact, Display. It collapses to one column below 980px with the main column first, and nothing is reordered at any width, so the tab order is always what is on screen.

**Users & Permissions is two sections.** It was four cards in a row down one page, so "add a user", "the users", "the teams" and "add a team" read as one continuous flow with nothing saying where one subject ended. Calendar users and Teams now each have a heading, a sentence saying what the section is for, and a rule between them.

**Teams are a list of what exists, not a stack of open forms.** Every team used to render as a form with its name in a permanently editable text box. A team is now a row: its name as plain text, how many people are in it, and three actions. Rename turns that one name into an input. Manage members opens the picker for that team. Delete is unchanged and is still refused while any event names it, with the events listed. Creating a team is a separate collapsed control asking for one thing, because who is in it is a decision made afterwards on the row that now exists.

**Why a team said "0 people right now" above a list of names.** Two separate faults, and the count was the honest one. The member list showed EVERY calendar user under every team, member or not, with nothing but a tick to tell them apart, so a team with nobody in it looked like a team with eight people in it. And "people right now" was counting who could be emailed, not who was in the team, so a member whose account has no address made the number smaller than the membership. Members are now behind "Manage members" and are plainly a picker; the row states the membership, and where the two numbers differ it says so in words.

**A related fault, found while fixing that one:** the membership form only ever listed calendar users, but saving wrote the membership wholesale, so a member without a calendar role was silently dropped by the next save of an unrelated field. The form now declares which people it actually offered, and anything stored outside that set is kept.

**Member rows are one line:** box, name, address. They were in a 180px-minimum grid, so at any real width the name wrapped onto a second line while the rest of the row sat empty.

**Removing a series asks what should happen to its events.** It used to delete on the click behind a browser dialog that promised the events were safe. They are not necessarily safe, because there is a real second thing somebody might mean. The question is now a screen with the actual counts in it and two options.

Keep the events, which is the default: they all stay on the calendar, on the same date and at the same address, and either become unassigned or move to another series that you pick. Or remove the series and its events, which deletes the upcoming ones and leaves the past ones alone.

**The past is never deleted by that button.** Past occurrences are detached and stay on the calendar as standalone past events. They are the record of what this organization actually did, and one click must not be able to destroy them. Somebody who wants a past event gone can delete that event.

Either option removes the series' own description, image and default FAQ set, because those live on the series and not on the events; the screen says so, and names which of them this particular series actually has. Registration records are kept under both options: they are stored separately from events and outlive them by design, with the event's title saved alongside them. Upcoming events go to the trash, which is what deleting an event does everywhere else here.

**The RSVP count links through even when it is zero,** on the Events list and on the dashboard. It used to link only when somebody had registered, which made it a control that changes shape under the reader, and zero is exactly when somebody wants to go and look. The page they land on says whether nobody has registered yet or whether registrations are switched off entirely, and it distinguishes both from a search that simply did not match.

**The event's registration settings are on the registrations page.** Capacity, whether registrations are accepted, who else is notified and where replies go were only in the event editor, so answering "why has nobody registered" meant leaving the list, finding the event, scrolling a form and coming back. They are here now, and changeable here. They are not a second copy: both screens draw them through one method and save them through one method, so neither can grow a setting the other lacks. That is the same rule that has kept the editor and the pending approval queue in step since 3.2.0.

**Each registration says whether that person also asked for SFAF news and updates.** Consent still lives in its own table and is deliberately not a column on the registration, because they are two different permissions and an RSVP row must never be the evidence for a mailing list. The rows are marked from that table in one query, matched on the address and the event, so somebody who ticked it in March and not in June is marked in March and not in June. CSV export is unchanged, behind the same capability.

**A venue address is street, city, state and ZIP.** One free-text box made "470 Castro St, San Francisco, CA 94114" and "470 Castro" the same field, so nothing could tell a complete address from half of one and correcting a city meant retyping the string.

**Existing addresses were split up rather than dropped, and nothing is lost.** Where the shape is unmistakable the parts are filled in; where it is not, the whole original string goes into street and the rest stay empty, which reads correctly and displays identically. Only a trailing five-digit ZIP and a bare two-letter state code count as unmistakable, so "470 Castro St, San Francisco" is not turned into a street in a state called San Francisco. The migration only ever adds, never overwrites a venue that already has parts, and never touches the original.

**Everything that reads an address still reads one line,** because the four parts are composed into the existing field on every save. That is why none of the thirteen readers had to change, and why an address is still searchable, now by city, state and ZIP as well.

**The embed generator defaults to all events.** It defaulted to "One organizer" with whichever organizer sorted first already chosen, so a block generated without touching the control silently showed one team's events. A page that looks like it is working and is quietly missing most of the calendar is the worst kind of wrong. Narrowing is a decision and now has to be made on purpose.

= 3.12.0 =

**The editor is a grid with real column spans again, and nothing stretches.** 3.10.0 went to CSS multi-column to stop cards growing to the height of their row, and that fixed the wrong thing at too high a price. Columns fill top to bottom before moving right, so Classification and Schedule stacked down a narrow left strip while the top two thirds of the page beside them sat empty.

The stretching was never a reason to leave grid. It is `align-items: stretch`, the grid default, and one declaration turns it off: with `align-items: start` every card sits at the top of its cell at its own height. No JavaScript measures anything, no reflow when the webfont lands, and per-card widths come back.

**The cards, top to bottom.** Event details full width; Classification beside Schedule; Location beside Capacity; then Donate, Organizer contact and Display sharing one row as three short cards; Notifications, anything unclaimed, and FAQs full width at the foot. Short sits beside short deliberately: nothing stretches now, but a row is still as tall as its tallest card, so pairing a two-field card with a ten-field one would put the gap straight back.

**"Basics" is now "Event details", and the picture is in it.** The old name described nothing. Title, description and image are the three things a person writes about the event itself, so they are one card and it is the first one. The image had its own card two thirds of the way down the page, which is where it was found last and chosen last. There is no separate Image card any more.

**A card with no span is full width rather than one column.** That is the forgiving failure. A card somebody adds and forgets to size reads as deliberate at full width and as broken at a sixth, and it matters most for the "Other details" catch-all that exists for exactly the field nobody placed.

**Why the 3.10.0 location fix did not take.** The radios were never inheriting a centered layout. `.uc-field input` gives every input inside a field wrapper `width: 100%`, twelve pixels of padding and a bordered white background, and the location block is a `div` carrying `.uc-field` that holds radios rather than a label wrapped around one text input. So each radio was as wide as the row, and a browser paints the control glyph in the middle of whatever box it is given: that is the "floating center-ish" look, with the label squeezed against the right edge and two-word phrases wrapping.

The 3.10.0 rule did win on specificity and was loaded; it set `flex: 0 0 auto`, and a flex basis of `auto` resolves from `width`, which it never declared. Nothing overrode the rule because nothing addressed the property doing the damage.

**The same rule was quietly oversizing three other controls**, all of them the same shape of wrapper: the category checkbox list, the fundraising progress toggle and "Reset to series image". Checkboxes and radios inside a field now keep their own size, and the reset lives beside the rule that caused it rather than in the block that suffered from it.

Locked and amber fields are untouched and still carry their badges; the catch-all card still claims any manager-owned field no card named, so a field added to the shared list cannot vanish from the editor while showing in the pending queue; and the help disclosures added in 3.10.0 are unchanged real buttons.

= 3.11.0 =

**A second level of filtering on the public calendar, and it only appears once the first has been answered.** The visitor bar is category-only on purpose: nobody reading the calendar should have to learn what an organizer or a series is. But somebody who found one Wednesday of a group that runs every Wednesday had no way to ask for the rest of its dates. Choosing a category now reveals a **Groups** row underneath it.

**The word "series" is never shown to a visitor.** It is the name of a data structure. The row is called Groups and each pill carries the term's own public name, the one that is on the poster.

**The list is derived from the events actually in front of the visitor**, not from the site's series list. The same query the list runs is run once for ids and the series terms are asked for those events, so every pill has at least one event behind it and a group with nothing in the chosen category never appears. One-off events are not in the row: a one-off is already fully visible in the list below and does not need a filter to find, and mixing the two would make the row long and its entries mean different things. A category whose events are all one-offs shows no row at all rather than an empty one.

**Multi-select**, because choosing two groups means "either of these". Up to six it is a row of pills; beyond six the row wraps to three lines and stops being scannable, so the same checkboxes fold away behind a summary. The decision is made per category on the count that will actually render.

**A breadcrumb says where you are and steps back up:** All events > Support groups > the groups chosen, with every segment to the left of the current one a real control. Up to two groups are named; beyond that it says how many, because three long names wrap into a tangle at the width a sidebar embed gets.

**It is a server query, like the category filter since 3.8.0 and the search since 3.6.0.** Nothing is filtered client-side. Hiding rows only ever sees the page already downloaded, which is what made both the old search and the old category filter silently wrong past page one, and a shorter list is indistinguishable from "no such group". The count above the list is the count of matching events, so it can never contradict what is under it, and the month grid narrows with the list rather than sitting beside it showing something else.

**The choice is clamped on the server against what the block actually contains.** The derived list is built under the block's own category, organizer, venue, series and search, so a group not in it is a group this block does not hold; a slug naming one is dropped rather than honoured. Every path re-derives rather than trusting the request: the page load, the AJAX list, the month grid and the embed endpoint all end at the same two methods. A scoped block therefore only ever offers groups it contains, and a hand-written `uc_group` cannot reach past it.

**The selection is in the URL as `uc_group`**, following `uc_cat`, so a narrowed calendar is a real address that survives a reload and can be sent to somebody. Unknown and out-of-scope slugs are dropped before they reach a query, exactly as `uc_cat` is.

**The shortcode and the embed return the same events for the same selection**, because both reach the query through the same derivation and the same clamp. No new REST route: the parameter is on the existing embed route, since every CORS mechanism there is gated on an exact route-string match and a second route would have worked in testing on this site and been blocked by the browser the moment sfaf.org asked for it.

= 3.10.0 =

**The editor cards no longer stretch to match each other.** A grid row is as tall as its tallest card, so Location beside Image left a hand's depth of empty card below it. The cards now flow as real masonry: a card ends where its content ends and the next one moves up under it.

Done with CSS multi-column rather than grid row spans, because grid has no pure-CSS masonry: row spans have to be measured in JavaScript first, which reflows when the webfont lands and again every time a field grows. Multi-column needs no script and collapses by changing one number, three columns to two to one. The trade is that columns are equal width, so the old four-and-two split is gone; the cards that genuinely need a full row (Basics, Notifications, FAQs) span all columns, and dead space was the actual complaint. Tab order still follows the document, which is the order the form is meant to be filled, and at one column that is exactly what is on screen.

**Formatting faults fixed.** The Location radios were inheriting a centered layout that pushed their labels right and wrapped two short phrases; they are ordinary left-aligned rows now, control then label. The Schedule card's link was a sentence with a link buried in it that broke mid-phrase across lines; it is one short link, "Edit the schedule". The Repeat box was a bordered card inside a card carrying its own paragraph; it is one line stating the cadence and the count, with the explanation behind the help icon.

**Long helper text moved behind a "?".** Most of the height mismatch was paragraphs longer than the fields they explained, all of them read once and never again. Four are now disclosures: how a venue reference works, what a recurrence group is and what deleting one occurrence does, how the first category supplies the card color, and what the image URL override means.

It is a real button with `aria-expanded` and `aria-controls`, not a hover and not a `title` attribute. Hover cannot be produced by a touch screen or a keyboard, and `title` is announced inconsistently and cannot hold a sentence worth reading. Click, tap, Enter and Space all work because that is what a button already does. With scripting off the explanation is simply visible, exactly as before, so nothing is ever behind a control that cannot open.

**Two short ones stay inline and are not hidden:** the image spec (1200 x 675, 16:9 landscape) and "0 means unlimited" under capacity. Both prevent a specific mistake, both are one line, and both are read every time rather than once. Behind a "?" they would be read never.

**Venue addresses are searchable again.** A regression from 3.9.0: the address used to live in `_uc_location` and was found by the meta clause, then moved onto the venue term, which the search matched by name only. Searching "940 Howard" stopped finding the events held there. Term meta is now in the search, as one named key on the same whitelist principle as everything else: not "all term meta", because a term can carry anything anybody ever attached to it and a wildcard would make a future key searchable the day it was invented rather than the day somebody chose it. Nothing personal is reachable and the test asserts that against the generated SQL rather than against the list it was built from.

= 3.9.0 =

**The event editor is a set of cards instead of one long column.** Six of them, in a six-column grid: Basics, Classification, Schedule, Location, Image, Capacity, Donate, Organizer contact, Display, Notifications and FAQs. Every group names itself, helper text is visibly quieter than a field label, and every control in the form is one width. Before this the title, the fundraising toggle and the reply-to address all carried the same visual weight, so finding the date meant reading everything above it. It collapses to two columns and then to one, in the order somebody fills the form.

**The cards are not shared with the pending queue, and the controls still are.** The queue's layout is a single stack under one heading; that is a layout, and two screens cannot share one. What they share, which is what the 3.2.0 rule was actually about, is the list of manager-owned fields and the markup of each one: neither screen holds a list, both ask the same function, and both draw through the same renderer. The editor now places those controls into its own cards by name, and makes one last call for anything no card claimed, which lands in an "Other details" card. So a field added to the shared list still cannot appear on one screen and not the other. That last call is the point: silence was the failure this design had to rule out.

**Locked fields survive the new layout.** An imported event still disables what its platform owns and still marks empty manager-owned fields amber, per field, exactly as before. No card is built out of controls that can only be locked together, so a card of disabled inputs reads as deliberate rather than broken.

**Categories are chips.** Selected ones show as removable pills with an Add control, matching how a category looks on a card, on the event page and in the filter bar. The checkboxes are still the form: they are what posts, they are what a browser with scripting off shows, and a chip has no state of its own beyond the box it mirrors.

**Venues moved into /caladmin, and Location became a picker.** The venue taxonomy has existed since the beginning with a WordPress admin screen and no way at all to pick one while creating an event, so the location was typed by hand every time and the same building got six spellings. There is a Venues screen in the portal now with names and addresses, the WordPress one is gone, and the Location field offers a venue or "a different location" with free text behind it.

**An event stores a REFERENCE to its venue, not a copy of the address.** This is the whole design decision. Correct a suite number on the Venues screen and every event held there is right immediately, including ones already published and ones already past, because nothing was copied and there is nothing to go and re-copy. An event holds either a venue or its own text and never both, so nothing downstream has to decide which one wins. Deleting a venue is refused while events are held there, with those events named: they keep no address of their own, so deleting it would leave them with nowhere to be rather than with stale text. Imported events are untouched, since the platform owns their location and writes it on every fetch.

**"Save these as a set" is in the FAQ block, where the questions are.** 3.3.0 moved applying a set down into the block and left saving one on a panel above the form, so a manager writing FAQs had no way to keep them from where they were working. Both controls are in the card header now. HTML forms cannot nest, so the control belongs to a form declared outside the editor's by the `form` attribute, which is plain HTML and works with scripting off. With scripting on, the questions currently on screen are copied across first, so a set can be saved from questions just typed.

**Fixed: the notification picker's filter box could not be typed into.** The edit-scope lock walks every input in the form and makes it read-only until that field's pencil is pressed. That box is not a field on the event: it saves nothing, posts nothing and changes nothing, it only narrows the list under it. Locking it made the picker unusable at any real size on exactly the events that need it, since a recurring event is the one that has a scope choice at all. Controls over the form's own interface are now marked as such and left alone.

**The /caladmin search filters as you type.** The search has been a real server query since 3.6.0, not a pass over the rows on screen, so this is debounced at 300ms and then submits the form it was always in. The answer arrives at a real URL: the address bar says what is being searched, the back button works, the result is shareable, and the sort and filters ride along in the fields they were already in. The caret is put back where it was, so typing continues through the reload. With scripting off the Filter button is unchanged.

**Verified rather than changed: one-off events already had no pencils.** The scope question is only asked when an event has two or more upcoming occurrences in its recurrence group, the fieldset only arrives locked when that question is asked, and the script that adds pencils returns immediately when there is no question. A one-off event, and a new one, open with every field directly editable. There is now a test holding that in place.

**The dashboard's RSVP figure links to that event's registrations**, the same link the Events list has had since 3.5.0 and this screen did not, so the identical number two screens apart answered "how many" in one place and both "how many" and "who" in the other. Linked only when there is something behind it and the reader may see it, exactly as on the Events list.

= 3.8.0 =

**An event can be in more than one category again, and the data model never stopped allowing it.** uc_event_category is an ordinary WordPress taxonomy, the WordPress editor has always shown checkboxes, and the payload satellites read has always carried an array. What enforced one category was the /caladmin editor: a single dropdown, saving an array of one. Opening a two-category event there and pressing Save deleted the second category. Nothing about the database changed in this release; the control did.

**Every category now shows as a chip on the card**, in one fixed order, and that order is decided in a single place. Several things pick "the first" category: the card's color, the branded placeholder's color and icon, the accent stripe on a compact card, the breadcrumb. WordPress does not promise an order for an event's terms, so the same event could take one color in the list and another in the month grid, and could change when a cache was rebuilt. Categories are now sorted by name, term ID breaking a tie, everywhere. Alphabetical because it is the order the chips are printed in, so "the first one" is something a manager can see rather than a hidden property.

**The filter bar runs a query instead of hiding cards.** It was wrong in three separate ways and only looked right on a single unpaginated block: it never saw the events on page two, so a category with events further down showed fewer than it had; it compared one slug on the card against one slug on the button, so an event in two categories was findable under only one of them; and the number above the list was how many cards remained visible rather than how many events matched. It now asks the same query the first page load and the search box use. An event in two categories appears under both, and the count changing when you choose a category is the point.

**A block scoped by its shortcode cannot be widened from the filter bar.** `category="fundraising"` is the author saying what the block is; a chip is a visitor narrowing what is there. They are now separate values, the second is clamped to the first on the server rather than by convention in the browser, and a scoped block only offers buttons for its own categories instead of printing every category on the site, all but one of which could only empty the list.

**Category chips are links.** Inside a calendar or an embed, clicking one filters in place with no page load and no jump to another domain. The href is a real calendar URL all the same, because that is what has to happen with no JavaScript, on a middle-click, or on a host page whose script failed.

**Nobody is stranded on the resources site any more.** The event page is served from resources; the calendar people actually read is a page on sfaf.org. "All Events" pointed at this site's own post type archive, so a visitor who clicked an event on sfaf.org and then asked for the list landed on a site they had never seen and that is not a public surface. Category chips on an event page would have done the same.

Both now use the **referrer** when there is one, so a visitor goes back to the exact calendar page they came from, with the category applied for a chip. A referrer is followed only if it parses, is http or https, is on this site's host, on the configured calendar host, or on a host sharing this site's own domain, and is not itself an event page; the URL is then rebuilt from those validated parts, so credentials, ports and fragments in the header do not survive. A link back to somebody else's site cannot be built from it.

**New setting, Display > Calendar home URL:** the page that carries the calendar, used when there is no referrer at all, which is every shared link, search result and bookmark. With several calendar pages this one is the fallback for all of them. The plugin cannot know which page holds a shortcode and does not try to guess.

**One event, one series.** Setting a repeat on a new event now creates its series implicitly, named from the event, in the same step. A repeating event in this organization's programming has exactly one series and it is called the same thing as the event, so asking for the title and then asking again for a series name was asking one question twice and inviting the two answers to differ. Creating a series by hand is still there for a program that needs describing before its dates are known; it is simply no longer the path you are pushed down to schedule a repeat.

**The series screen is now the schedule editor, which is the gap this release exists to close.** The recurrence pattern has been stored since 3.0.0 and shown nowhere after the event was created, so a group's cadence was invisible, and there was no way at all to add a date to an existing group: extending a term's program by three weeks meant three hand-built events that no bulk edit could reach.

The screen now reads the pattern back in plain language ("Every other Wednesday, 6:00 PM to 7:30 PM"), lists the occurrences with upcoming and past clearly separated, and offers four things:

* **Change the pattern.** Day, time, or both, applied to upcoming occurrences only, with the confirmation naming the count. Weekly and biweekly groups move by one uniform offset, so the interval between occurrences is exactly what it was; the offset takes the short way round unless that would push the next session into yesterday, in which case the whole group goes forward instead. A monthly-on-the-same-weekday group is recomputed per month, so "the second Friday" becomes "the second Tuesday". Daily and monthly-on-the-date take a time change but not a day change, and say why: daily has no weekday to pick, and shifting a day-of-month pattern onto a weekday would silently turn it into a different pattern from the one it was set up with.
* **Edit one date**, from a pencil on every upcoming row, which opens that event's own editor. That is how a single week moves to a different day.
* **Add a date**, copied from the next occurrence so it carries the event as it is now rather than as it was in March. It joins the recurrence group by default so bulk edits reach it, and there is a tick box for a date that should deliberately stay independent. It starts with the group's capacity and nobody registered, because registrations belong to the date they were made for.
* **Remove one date.** It stays removed: recurrence has not been a template since 3.0.0, so there is no pattern re-run on save that could notice a gap and fill it back in. That is what makes taking a holiday off the calendar work.

**Past occurrences cannot be reached from any of it.** They are the record of sessions that happened, in front of the people who attended them. Every write is bounded at the query by "today or later", the past rows carry no controls at all rather than disabled ones, and removing a past date is refused by the handler as well as absent from the markup.

**Imported events are not offered schedule editing.** Recurrence has been refused on GoFundMe Pro and Eventbrite events since 2.9.0, because the platform decides whether its own event repeats and every fetch brings that shape back. Offering an editable pattern here would be offering a change the next fetch quietly undoes, so the screen says so instead.

The REST payload satellites consume needed no change: `categories`, `category_colors` and `terms.uc_event_category` have always been arrays and still are.

= 3.7.0 =

**An administrator can no longer lose access to the calendar.** One did. He added himself as a calendar user so that he could put himself in a team, and his portal access dropped from full to contributor. His WordPress role was never touched and never could have been: nothing in this plugin has ever written a WordPress role or capability. What happened is that the calendar's own access level, which until now was only consulted when somebody had no record at all, started existing for him, and the "Add a user" form's first option is Contributor.

**The rule is now the other way round.** Full access is derived from `manage_options` first, and a calendar user record cannot reduce it. If you can change how this site runs, you have full calendar access, including RSVPs and the pending queue, whether or not the calendar lists you as a user. A record for an administrator still exists and still matters, because that is what puts somebody in the team picker and the notification picker, but it can only ever describe somebody who is not already an administrator. An administrator added to the calendar is added at Admin, whatever the form said.

**On the screens, an administrator's access level is shown as fixed with the reason**, rather than as a dropdown that would accept a change and then have no effect.

**Team membership never touches access.** It did not before either, and this release does not fix a bug there so much as make it impossible to introduce one: adding somebody to a team, removing them, or saving a team writes exactly one option, `sfaf_teams`, and nothing else. Membership is a set of user ids that says who gets notified. It says nothing about what anybody may do.

**New screen: Events > Calendar Users.** Every WordPress user on one page, gated on `manage_options`, showing their WordPress role, their calendar access level and their team memberships. Access level and teams are two separate forms with two separate handlers, so saving one cannot carry a stale value from the other; that coupling is exactly how the defect above happened. The WordPress role is read-only here with a link to the built-in Users screen, deliberately: routing it through `wp_update_user()` is easy, but reproducing everything the Users screen does around it (editable roles, refusing to let the last administrator demote themselves, multisite super-admins) and keeping that in step with WordPress is not, and a second subtly different way to change a role is the kind of thing this release exists to prevent.

The portal's own Users screen is still where teams are created and named, and still where contributor approval and category restrictions are set. The new screen is where you look somebody up.

**Standing rule from this release on:** nothing in this plugin writes `wp_capabilities`, or calls `set_role()`, `wp_update_user()`, `add_cap()` or `remove_cap()`, except a control whose explicit and only purpose is changing a role. Never as a side effect of saving something else. There is no such control in this plugin; WordPress already has one.

= 3.6.0 =

**Searching an event now searches the event.** It did not before, on any of the three surfaces, and the reasons differed.

**The caladmin Events search** used WordPress's built-in search, which covers the title, the description and the excerpt and nothing else. Most of an event is not in any of those: the location is meta, the venue, organizer, series and category are taxonomy terms, the FAQs are meta, and the platform an imported event came from is meta. So searching for a venue, an organizer, a neighbourhood or a question somebody answered in the FAQ returned nothing, correctly, to a question it was never asked.

**The public search and the embed search were not searches at all.** Both read the text out of the event cards already on the page and hid the ones that did not match. That only ever looked at the current page, so with twelve events shown and forty on the calendar the other twenty-eight were never considered, and a shorter list reads exactly like "no such event". It also searched the CARD rather than the event: a card carries the title, a summary trimmed to twenty-five words and the time and location line, so everything else was invisible to it. The two were not even consistent with each other, since the embed looked at the compact layout's markup and the calendar site did not.

**All three now run one query, built in one place.** A search matches the title, the description, the excerpt, the location, the category, organizer, venue and series by name, and the FAQ questions and answers. Imported events match on the platform's display name, so "Eventbrite" and "GoFundMe" both find their events even though what is stored is a short slug. Matching is partial and case insensitive, and several words all have to match, each of them anywhere in the event, so adding a word narrows the results.

**The public calendar and an embed of it return the same events for the same words**, because both go through the same query builder and the same renderer. The embed no longer decides what matching means; it asks.

**No attendee data is searchable, and cannot become searchable by accident.** RSVP names and addresses, the per-event notification list, the teams an event notifies, the reply-to and organizer addresses and the confirmation email text are all absent. The list of places a search looks is a list of what to include rather than what to skip, so a field added in future is unsearchable until somebody adds it there deliberately. Registrations are reached from the event they belong to, which is the only route to them.

**Unpublished events cannot be reached by searching.** The search only ever adds a restriction to a query whose post status and date window were already decided, so it can narrow a result set and has no way to widen one. On the public calendar and in embeds that set is published upcoming events, before any search term is considered.

**On performance:** the query uses one correlated EXISTS per source rather than one JOIN per condition, which is what makes a multi-field meta search scan badly. A concatenated search-index meta key was considered and not built: it would have added a rebuild hook, a backfill and a way for results to be silently wrong when the index drifted, for a saving this calendar's size does not need. If it is ever needed, `SFAF_Search::where()` is the single method that would change. Search responses are deliberately not cached by the embed endpoint, since the search term is the one parameter a caller can put anything into and caching it means one stored row per string anybody has ever typed.

= 3.5.0 =

**Teams, a proper notification picker, and registrations reached through their event.**

**A team is a name and a set of people, and an event stores the team rather than the people.** Under Users you can create a team, rename it, choose who is in it and delete it. When an event notifies a team, what gets saved is the team, and who that means is worked out at the moment the reminder is sent. So taking somebody out of a team stops their notifications for every event naming it straight away, with nothing to go and correct, and deleting their account does the same. Adding somebody to a team puts them on events that were set up before they joined, which is deliberate: it is what belonging to a team means, and the alternative is reopening a term of events to add one person. Nothing is snapshotted anywhere, not addresses, not membership, not a resolved list.

**Deleting a team that an event still names is refused, and the events are listed.** The other option was to warn and delete anyway, which cannot be made safe: the event would keep a reference to nothing, and a notification that silently stops going out looks exactly like one that is still going. Refusing is the only version where nothing quietly breaks. The message names the events with links, so you are told what to change rather than just told no. Because a team in use cannot be deleted, no event can ever be left holding a team that does not exist.

**The per-event notification list is now one control with two tabs.** Individuals has a type-to-filter box, which the list needed before it grows to a few hundred names. Teams sits beside it. Both can be used at once, and the summary line says what you have chosen and what it comes to: "1 individual, Philanthropy team (2 people). 3 people in total." The total is a count of distinct addresses, not a sum, so somebody chosen individually who is also in a chosen team is one person and gets one email. Filtering only ever hides rows, so searching for one name can never quietly deselect the people chosen a minute ago. Free-text addresses for people outside the calendar are unchanged and still work. It is built in plain PHP, CSS and JavaScript, uses a real tablist with arrow-key navigation and visible focus, and without JavaScript it degrades to a disclosure holding both lists.

**The three layers of reminder recipients are unchanged.** Registrations, "Get Reminders" subscribers and the notification list are still resolved separately and still deduplicated once; teams slot into the notification-list layer and nothing else moved. The send-once guarantees are untouched.

**RSVPs are reached through their event.** The RSVP count on the Events list is now a link to that event's registrations, with Export CSV. The standalone RSVPs tab has gone: a flat list of every registration ever taken, across every event, is not a question anybody has.

**Registrations for deleted events are still reachable, which is why the screen behind that tab remains.** Those rows are kept on purpose, with the event title snapshotted at deletion so they read "Santa Skivvies (deleted)", and they have no event to be clicked through from. The Events list carries a link to them at the foot of the page whenever any exist, saying how many, and the registrations screen has a view for exactly them. Nothing about the snapshot behavior changed.

**Security fix: the registrations screen now checks permissions.** It relied on not appearing in the sidebar, which is not a permission, so a contributor who typed the URL could read every registration on the calendar. It is now gated on the same capability as the CSV export beside it, which is where the gate always was. Nobody who could not already export this data has gained access to it.

**The CSV export says what it is carrying.** RSVP data covers HIV, substance use and trans health programming, and knowing somebody attended can disclose things they did not choose to disclose. The registrations screen now states that above the table, next to the button that turns it into a file with no login in front of it. The export gains nothing new: the same rows, the same capability, the same columns including the event title. The filename now carries the event, so a folder of exports is identifiable without opening any of them.

= 3.4.0 =

**Past events have a home, and any event can be copied into a new one.** Nothing in this release deletes anything, expires anything or moves anything. No event is altered by installing it.

**The Events list has views: Upcoming, Archived, Removed at source, and All.** Upcoming is what opens now, because it is the work, and it sorts soonest first rather than newest first. Archived is every event dated before today, which is the whole definition: there is no archive flag, no retention period and nothing that runs. An event moves between the two views because the date passed, not because anything happened to it. It is the same table with the same columns, the same sortable headings and the same actions, so filtering by category or status and sorting by any column all work inside a view, and the view travels with the sort, the filters and the page numbers.

**Imported events are in the archive exactly like native ones**, because a past GoFundMe Pro or Eventbrite event is a permanent local record and always was. The Source column now says so: it shows the platform's badge, linked to the campaign where there is a URL. Until now that column read only the multi-site sync marker, so an imported event said "Local", which is the one thing it is not.

**Events the platform stopped listing are not filed as "past".** Those were unpublished to drafts by this plugin rather than by a person, and they can be at any date, so they get a view of their own and an amber "Removed at source" marker beside their status saying when and why. The editor's fuller explanation is unchanged.

**Duplicate creates a new draft from any event, archived or not.** It copies the title, description, image, location, start and end times, capacity, RSVP and display settings, fundraising URL and goal, category, organizer, venue, series, FAQs, the notification list and the reply-to address. It deliberately does not copy the date: setting that is the point of the action. It does not copy registrations or reminder history, so the new event has no attendees and no record of having mailed anyone, and its morning-of reminder will still go out. It does not join the original's recurrence group, so an "edit all upcoming occurrences" elsewhere cannot reach it.

**A duplicate of an imported event is a native event.** No external source, id, URL, image, timezone or import timestamp comes across, and no FAQ row keeps the platform id it was carrying. That means nothing is locked and every field is editable, not because anything unlocks it but because there is no longer a platform to ask. The copy is built from a list of what to take rather than by taking everything and deleting what should not have come, so a source field added in future cannot leak into a duplicate by being forgotten.

**Registrations still outlive their events.** RSVP and reminder-log rows are kept when an event is deleted, with the title snapshotted at that moment so the list reads "Santa Skivvies (deleted)". Nothing in this release touches that, and nothing in this release deletes an event.

= 3.3.0 =

**IMPORTANT: the embed block on sfaf.org must be re-copied.** This release changes `public/css/calendar.css`, and the embed serves its stylesheet from this site at a pinned version. Until the block is re-copied from Events &rsaquo; Embed Code, sfaf.org keeps the old card image shape. Nothing breaks in the meantime; the images there simply stay as they were.

**Card images are 16:9 now, and the target size is 1200 x 675.** The card's image box was a fixed 150 pixels tall at whatever width the column happened to be, so its shape changed with the layout and the same photograph was cropped differently in different places. In a wide single column that band was shallow enough to cut through people's faces. It is a 16:9 ratio now, so the height follows the width and the crop is the same everywhere. Both event editors state the 1200 x 675 target beside the image control, which is also the right shape for a shared link's preview image, so one upload covers both. The month grid and the sidebar are untouched: neither uses this box.

**A saved FAQ set can be applied from inside the FAQ block.** The set could already be applied, from a panel at the top of the editor that posted the page and reloaded it, which threw away every unsaved edit in the form below and sat nowhere near the questions it changed. There is now a dropdown and an Add button in the FAQ block itself. It copies the questions into the rows underneath it without leaving the page, so nothing typed is lost and any of the new rows can be edited or deleted before saving. It appends only: existing questions are never rewritten or reordered, and a question already on the event is skipped rather than duplicated. Available on imported events too, where a campaign often needs questions the platform does not carry, and on an event that has not been saved yet. Applied rows are hand-written content and carry no platform ID, so a refetch from GoFundMe Pro leaves them exactly where they are. The old panel still works without JavaScript.

**The edit-scope question is a modal.** Opening an event that is one of several upcoming occurrences used to show two buttons in a box at the top of a long form. They were easy to scroll past, and a manager who scrolled past them met a form where nothing could be typed and nothing explained why. The question now opens over a frozen editor and has to be answered first. It names the count, as in "Edit all 12 upcoming occurrences". Escape and a Cancel button both go back to where the manager came from rather than leaving them in an editor with no scope chosen. Focus is trapped in the dialog while it is open and lands on the banner stating the answer once it closes. A one-off event opens straight to editing with no dialog at all. What happens after the choice is unchanged: the same banner, the same per-field unlocking, the date never editable across occurrences, and past occurrences never targeted by either option.

= 3.2.1 =

**Fixes a fatal in 3.2.0 that stopped the plugin activating at all.** One line of `includes/class-sfaf-portal.php` shipped with its variables missing, which is a PHP parse error, and a parse error in a required file cannot be caught by the plugin's own safety net. WordPress could install 3.2.0 and never activate it, with no useful message. 3.2.0 never ran, so nothing it introduced was ever in service and no data was touched by it. Install 3.2.1 over it.

**How it will not happen again.** Every PHP file is now parsed with a real PHP binary before a build is packaged, and any parse error stops the build. The checks that were in place before proved the code was balanced (braces, brackets, PHP tags) and never proved it was valid, which is exactly the gap the broken line fell through: it was correctly balanced and wrong only as an expression.

= 3.2.0 =

**The card button says what it does.** It is "View event" on every event, on every surface. 3.1.0 varied the word between RSVP, Donate and View event, and the button did none of those things: it went to the event page and left a person to find the form themselves. A card promising "RSVP" was promising an action it could not perform, and Galaxy Digital volunteer events fell through the gap because no fourth label existed for them. They read "View event" like everything else now.

**Donate is a second button, not a different first one.** Where an event has a donation link, a Donate button appears beside View event and goes straight to the donation page. It carries the heavier weight of the two, because it is the action with a consequence; View event is navigation and takes the outline. Events without a donation link keep one button.

**How many places are left is still on the card.** "12 of 20 spots left" for RSVPs and the volunteer spots remaining for imported Galaxy Digital needs, from the same helpers the event page uses, so the two can never quote different numbers.

**One button family across the card and the event page.** The RSVP button, the volunteer Sign Up button and the Donate button on an event page were three different shapes with three different hovers, none of them matching the card. They are all the same control now: pill radius, the arrow that slides in on hover and on keyboard focus, the reduced-motion guard and a visible focus ring. Solid #0E818C with a white label at 4.63:1 for the action with a consequence, outline for navigation.

**Fundraising figures are opt in, per event, and off by default.** A goal arriving from GoFundMe Pro no longer publishes itself. Nothing about the money appears anywhere until a manager ticks the box on the event, and the choice is declared manager-owned in the GoFundMe Pro adapter, so the hourly fetch can never change it back. Switched on with no raised figure on file, the section renders nothing at all rather than a goal on its own: a goal with no progress beside it reads as zero raised, which is a claim we have no basis for.

**The pending queue shows the fields, not just a warning that fields exist.** The manager-owned controls (image, description, category, organizer and the fundraising toggle) now render from one function that both the event editor and the pending approval screen call. Neither screen holds a list of the fields, so a field cannot appear on one and be missing from the other. Approving an import no longer means opening a second screen to set two things.

**A map on the event page, loaded only if somebody asks for it.** The address is always a working link to Google Maps. The map itself sits behind a "Show map" button, and there is no iframe in the page until it is pressed: nothing is requested from Google on page view, so Google is told nothing about who opened the page. Event pages cover HIV services, substance use and trans health programming, and a passively loaded third-party frame would report every visit. With no API key configured the page shows the address link alone, with no button, no error and no notice.

= 3.1.0 =

**The list card is rebuilt.** This affects the LIST display mode only, in both the shortcode and the embed, which are the same renderer. The month grid, the sidebar and the mobile day-detail list are untouched.

**What a card says now, top to bottom.** A header row carrying the category and who is running the event on the left, and the day, month and date on the right. The image below it, inset inside the card's own padding and rounded rather than bled to the edge. Then the title, a one-line summary, and the facts stacked one per row with an icon: time, place, and the series. Fundraising progress where there is a goal. A footer with what is left of the capacity on one side and a single action on the other.

**The category chip is readable.** It was the category color on a 12% tint of itself, which measures 2.05:1 and could not be read. Every approved brand color now has a measured darkest stop of its own family, and the chip uses it: the worst of the eight is 6.01:1 against a 4.5:1 requirement. Never black, never gray, because the color is the information.

**Events with no picture get a category tile, not a hole.** Category tint, the category's icon, the category's name. Roughly half of imported GoFundMe Pro events will never have an image, because their API does not expose one, so this is the normal case and now looks like it was meant. The old placeholder was a fixed 16:9 drawing that could only be shown at that one ratio.

**The series row names the series.** "Weekly yoga, see all dates" instead of a generic "Event Series" label, so the link still makes sense read out of context by a screen reader. The row is omitted entirely when an event is in no series.

**One action per card, and the word on it says what it does.** RSVP where the event takes registrations, Donate for a GoFundMe Pro appeal, View event for everything else. White text on the brand teal measures 2.26:1 and fails WCAG AA at this size, so the button is the outline treatment instead: brand teal border, label at 4.63:1, and a teal fill on hover and keyboard focus with the label at 5.47:1. An arrow slides in beside the label on hover and on focus, in pure CSS, and snaps into place instead for anyone who has asked for reduced motion. It is decorative, because there is no hover on a touch screen and the label has to carry the meaning by itself.

**Nothing on a card moves on hover.** No lift, no reflow, no change of row height. The arrow grows into the footer's own free space and the supporting text truncates rather than wrapping, so a card can never shift its neighbors.

**Add to Calendar, sharing and reminders left the card.** They are unchanged on the event page, which renders every one of them. Nobody scanning thirty events adds the fourth to their calendar without opening it first, and those controls were charging every card a row of chrome for a decision that happens one page later.

**Fundraising figures stay honest.** A bar is drawn only where there is a real raised amount and a real goal to measure it against. A goal with nothing behind it states the goal and draws no bar, because a bar at zero is a claim about how an appeal is going.

= 3.0.0 =

**This release changes the data model and needs a one-time migration.** After updating, an admin notice links to Events → Series migration, which shows a dry run of exactly what would happen and writes nothing until the button is pressed. Take a database backup first.

**A series is a container, not an event.** Until now the series parent post was simultaneously the series template and its own first occurrence, and that single fact caused most of the calendar's structural problems: series turned up in the Events list, events existed only as a series, deleting a parent orphaned every occurrence, deleting one occurrence needed a canceled-dates list on the parent so it would not come back on the next save, and FAQs lived in three different meta keys. A series is now a taxonomy term, exactly like a category or an organizer. It has no date, never appears on the calendar, is never returned by an event query, and cannot be an event because a term cannot be a post. A series may hold different kinds of event, an educational session one week and a social the next, and a series with no events at all is valid and useful: people can read what it is about and see that dates may be added.

**An event is an event.** Every date, including the first, is an ordinary event post. Events say which series they belong to; nothing inherits live from a parent.

**Recurrence is a generator, not a template.** Choosing a pattern when creating an event produces that many separate, independent events, all stamped with a shared recurrence group, and then forgets the pattern. Nothing regenerates. Editing one is an ordinary edit; deleting one removes one date and nothing brings it back. Weekly, monthly, every two weeks, daily, and "the same weekday of the month" (the second Friday, the fourth Tuesday), derived from the date you chose.

**Two groupings, doing different jobs.** A SERIES is the umbrella, used for filtering, embeds and browsing, and is never a target for bulk edits because it may hold different kinds of event. A RECURRENCE GROUP is the set generated together from one pattern, identical by default, and is what a bulk edit targets.

**Edit scope: two buttons, chosen before editing.** At the top of the event editor: "Edit this event" and "Edit all upcoming occurrences". Every field is locked until one is chosen, so nothing can be edited before you have decided how it saves. Each field then opens behind its own pencil, everything is saved once at the end, and a banner that stays visible while the form scrolls states the scope and the count. Saving asks "Update 12 events?" first. "All upcoming" excludes anything whose date has passed, because past events are the historical record. Date carries no pencil in that mode, because the dates are the only thing making the occurrences distinct; capacity loses its pencil when any of the target dates already has RSVPs against it, because places are held per date.

**FAQs live in one place.** One key, on the event. No inheritance, no override flag, no display logic deciding whether a series' questions appear above an event's or instead of them. Reuse comes from saved FAQ sets, which have been copies since 2.9.0, and from a series naming a default set applied when an event is created into it, which is inheritance-like convenience at the one moment it helps, without tying the event to something it may need to differ from.

**Nothing changes for a visitor.** The same events on the same dates in all three display modes and in the embed; every event permalink unchanged, including the old series parent's, which keeps its ID, slug and URL and is now an ordinary event; existing embed code keeps working without being regenerated, because a series filter still takes one integer and the old parent ID still resolves to the same series; RSVP and reminder links resolve to the same events. The one visible change is where "Part of series" links to: it went to the parent post, which no longer exists, and now goes to the series' own page, which shows its description, image and dates. The badge itself looks the same.

**Removed rather than left dormant:** series regeneration, canceled-date tracking, promote-to-parent, orphan detection and both orphan repair screens, the series removal screen that asked whether to delete everything or promote the next occurrence, the individually-edited flag that existed only to protect occurrences from regeneration, and the three-way edit scope in both editors.

**Fixed:** on a GoFundMe Pro event, entering an image and a description did not clear the pre-publish warning. The warning, the amber field highlight and the Publish confirmation all read the same list, so they agreed with each other, and all three were computed once, server-side, when the page was rendered, and nothing re-ran while you typed. The sentence on the Publish button had been written before the form was touched. All three now recompute as you work, from a single description of each field that names both how it is stored and which control fills it in.

= 2.13.0 =
* The portal's Events list sorts on Event, Date, RSVPs and Status. Click a heading to sort by it, click again to reverse it. The active column carries an arrow and an aria-sort attribute, so which column is sorted and in which direction is readable both by eye and by a screen reader, rather than being implied by the order of the rows. Category, Series and Source do not sort, and that is deliberate: an event can hold more than one category so there is no single answer, Series is another post's title, and Source reads "Local" on nearly every row.
* Sorting is in the URL, so a sorted view can be linked, bookmarked and shared. It survives filtering (the filter form carries it) and it survives paging (every page link carries it), and changing the sort returns you to page one rather than leaving you on page four of a different ordering.
* The Events list is paged, 25 to a page. It was previously capped at 50 with no pager at all, which silently dropped every event past the fiftieth: not a truncation anybody was told about, and indistinguishable from not having those events.
* Statuses read as words. The Status column printed WordPress's own slugs through ucfirst(), so an event that was live said "Publish", which is an instruction rather than a state, and a scheduled one said "Future", which tells nobody anything. Published, Draft, Pending, Scheduled, Private and Trash now, plus the plugin's two import statuses in the wording the import queue already used. Fixed everywhere it leaked, not only on the Events list: the orphan repair screen, the WordPress admin series screens, and the RSVP lists, which had the same problem with their own values and now read Registered, Reminders only and Canceled.
* Email validation is inline, specific and persistent. The old behavior was the browser's floating bubble: it appeared on submit, hovered over the page and vanished the moment the pointer moved, leaving no mark on the field. There is now a red border on the offending field until it is corrected, and the message sits below the field in the flow where it stays put. The field is marked aria-invalid and the message is wired to it with aria-describedby, so it is announced and not merely drawn.
* The message says what is actually wrong. "kgkg.ff.com" is a real domain and not an email address, so it says to include an @, not that the value is "invalid": being told something is invalid is being told nothing you did not already know. Missing local part, missing domain, a domain with no dot, more than one @, and spaces each get their own sentence.
* One validator, every email field in the plugin: the RSVP form, the reminder signup form, the per-event Reply-To, the per-event notification list (checked line by line, naming the offending lines), the event Organizer email, and the six address fields in Settings and the event meta boxes. Server-side rejections render in exactly the same shape as the client-side ones, so the two are indistinguishable to somebody reading them. Without JavaScript the browser's own validation is left in place rather than removed.
* "Fetch updates" says it is working. It is several seconds of remote HTTP, and as a plain form post it looked identical before, during and after, so people pressed it twice. The button now disables, shows a spinner, and either navigates on success or restores itself with a visible message on failure. It never restores itself silently: a button that comes back with nothing said reads as "nothing happened", which is exactly the wrong conclusion. The form still posts normally without JavaScript.
* Automation moved out of the calendar portal and into the WordPress admin, at Events > Automation. The run log, cron health, the cron URL and "Run now" are facts about how the server is configured; the people who manage events can neither act on them nor fix them. It is gated on manage_options rather than on the plugin's own calendar-admin role, which is the capability that actually corresponds to "may change how this site runs". The old /caladmin/automation address redirects, so nothing that linked to it breaks.
* The scheduled-tasks card and the cron health line are gone from the portal Dashboard for the same reason: a warning nobody on that screen can act on only teaches people to ignore warnings. Everything else on the Dashboard is unchanged.
* Cron failures are emailed, not only shown as an admin notice. An admin notice needs somebody logged in and looking, which is precisely what nobody is doing at three in the morning. The two conditions that were already detected, three consecutive failed runs and no completed run for three hours, now also send mail, saying what was detected, when the last successful run was, and linking to the Automation screen.
* The health check does not run inside the runner it monitors. That is the whole difficulty with alerting on a dead cron: anything the runner would have sent does not send either, because the runner is what is broken. It hangs off `wp_loaded` instead, which fires on every request this site serves, including an admin page load, a visitor on the calendar and WordPress's own pseudo-cron, none of which depend on the runner having run. It costs one autoloaded option read per request and does real work at most every fifteen minutes.
* Alerts do not repeat themselves into uselessness. One message on the transition into a bad state, then at most one a day while it lasts, so a runner dead for a week produces seven emails and not a hundred and sixty. A recovery message is sent when it starts working again, so nobody has to go and check. A send that fails is retried at the next check rather than being counted as delivered.
* An alert address setting, defaulting to the site administration email, kept separate from the event email settings: this is about whether the server is working, and the person who fields that is very often not the person who fields a question about an event.

= 2.12.0 =
* Fixed the occurrence-delete bug, which is the one that mattered. Removing a single occurrence from a recurring series did not stick: nothing recorded that the date had gone, so the next time the series was saved the generator saw a gap and filled it back in. A weekly group canceled for a public holiday quietly un-canceled itself the moment anybody touched the series. Cancellations are now recorded against the series as dates, so they survive the occurrence being gone, any number of later saves, a change of cadence and a change of end date.
* Canceled dates are visible and reversible. Each series lists them on its own screen in both the portal and the WordPress admin, with a Restore button per date, and the Series list shows a count. A date that has since fallen outside the pattern says so rather than offering a restore that would do nothing. Canceling now works the same way whether the occurrence is trashed, permanently deleted, removed from the portal, removed from the WordPress list table or removed by anything else, because the record is written on the removal hooks rather than in one handler.
* Removing a series parent is now a decision with the consequence written down. It used to be one click on a row that looked like every other row, and it left every other occurrence published, pointing at an event that no longer existed, missing from the Series Manager and impossible to edit back into shape. Trashing or deleting a series parent anywhere in WordPress is now intercepted and offers the two things somebody pressing Remove might actually have meant: remove the whole series including every occurrence, or promote the next occurrence to be the series and keep the rest. There is no route left that orphans an occurrence silently.
* Occurrences that were already orphaned are findable and repairable. The Series Manager, in both the portal and the WordPress admin, lists them grouped by the event they are looking for, and offers to rebuild them into a series (the earliest becomes the parent) or to convert them into ordinary standalone events. Nothing is repaired automatically on page load: both of those rearrange somebody's program, and a plugin doing either quietly is how a calendar gets rearranged by nobody.
* The display helpers no longer render a broken series link. An occurrence whose parent has gone now reads as not being in a series at all, rather than emitting "Part of series:" with an empty name and an empty href on a public page. That degrades in one place rather than in each caller, so a future caller cannot reintroduce it.
* "All future events in this series" was a lie, and is gone. It ran over the whole date range, past occurrences included. There are now three scopes with the cutoff date printed in the label: only this event, this event and occurrences from a named date onward, and all occurrences including past ones. Printing the date is the point, because a label that names its own cutoff cannot drift away from the behavior without somebody noticing.
* "This and future" is implemented properly rather than relabelled, because it is what people reach for when a weekly group changes time from next month. Applied from an occurrence, it copies that occurrence's details onto the later ones and moves the series template to that date, so everything before it keeps exactly what it had and stays part of the series. Applied from the series itself, it counts from today.
* The scopes are identical in the portal and in the WordPress admin, built from one list in one place. The portal previously offered no choice at all and simply marked an occurrence as individually edited.
* RSVP rows and reminder-log rows now outlive their event on purpose, and readably. Deleting an event is a decision about the calendar, not a decision to forget that thirty people came, and those rows are the only evidence a person ever registered. What was wrong was that they survived unreadable: the list joins on the post, so with the post gone the Event column rendered blank. The event's title is now copied onto its rows at the moment it is permanently deleted, which is the last moment it can be known, and anything orphaned before this release renders as "Deleted event (#id)" instead of nothing.
* Event management has moved off the Dashboard. It used to carry a "+ New Event" button, a "Fetch updates" button, the fetch report, and a table of upcoming events with Edit and Remove on every row, which put the most destructive control in the plugin on the first screen after logging in, next to a welcome message. The Dashboard is now an overview: counts, what needs attention, the health of the scheduled runner, what is coming up, and recent activity, all read-only and each linking to the screen that can act.
* Events is where single events and individual occurrences are created, edited and removed, and the Remove control now knows what it is looking at: an ordinary event, an occurrence (which offers to cancel that date, and says it will not come back), or a series parent (which sends you to the removal screen).
* Series is where a whole series is created, edited and removed, and where canceled occurrences are listed and restored.
* "Fetch updates" and its report moved from the Dashboard to Pending, beside the import queue they fill. The old dashboard URL still resolves, so nothing bookmarked is broken.
* Reminder emails now carry a per-event Reply-To, so a reply reaches whoever is running the event instead of a no-reply address. One address per event, free text, so a group mailbox works and it does not have to be on the sending domain. It is pre-filled with the address of whoever created the event, so an event set up and then forgotten still has replies arriving somewhere real, and falls back to the site-wide default if cleared. This reuses the per-event Reply-To that has existed since the confirmation email override rather than adding a second field, so there is only ever one answer to where replies go. It is separate from the notification list: that is who receives the reminder, this is who fields the answers.
* Native events only, matching the reminders themselves. An imported event's mail is sent by its platform.
* No email provider is configured or assumed anywhere. From name, From address and the fallback Reply-To remain settings, as in 2.11.0.

= 2.11.0 =
* Morning-of reminder emails. One reminder per event, at 6:00am site time on the day, to everyone with an RSVP for it, everyone who pressed "Get Reminders" on it, and the event's own notification list. It carries the title, the date, the start and end time, the location, a link to the event page, and a link that releases the recipient's place.
* Native events only. Events imported from GoFundMe Pro, Eventbrite or any adapter added later are excluded outright, and no reminder settings are shown on them. Those platforms hold the registration and send their own reminders; a second email from here would be a duplicate about a registration this site does not own. The check is on whether an event has a source at all, not on a named platform, so a new adapter is excluded the day it exists rather than the day somebody remembers to add it.
* Sending once is guaranteed by the database, not by the code being careful. A send is claimed by inserting a row into a ledger with a unique key on (event, recipient) BEFORE the mail goes out, so a second attempt from an overlapping run, a manual re-run or a run resumed after a crash fails that insert and is skipped. Two further layers sit on top: a run lock that stops two runs overlapping at all, and a per-event marker set once an event's pass finishes. Any one of the three would do it; all three are there because the failure mode is somebody's inbox.
* A send that fails is recorded as failed and not retried. `wp_mail()` returning false does not reliably mean nothing was delivered, so a retry risks the exact duplicate the whole design exists to prevent. Failures are counted in the run log where somebody can see them.
* Somebody who registers after the morning's send does not get a late reminder. They just signed up; they know the event is today.
* An event that starts before 6am is handled rather than skipped: its reminder moves to 00:00 that day and goes out on the first run after midnight, so it still arrives before the event. An event with no start time keeps the 6:00 slot, because "no time set" must not be read as "starts at midnight".
* The reminder's "Can't make it?" link releases the recipient's place so it goes back to the count. The link is tokenised per recipient per event, is not guessable, and needs no account. Opening it never cancels anything on its own: it shows a page that asks, and the button on that page is what acts, because mail clients and security scanners fetch the links in an email without a person ever clicking one. A canceled registration is kept and marked canceled rather than deleted, so the history survives.
* One hourly scheduled runner for every unattended job, rather than each feature scheduling its own. It works the same whether it is triggered by a real system cron, by WordPress's visitor-triggered pseudo-cron, or by a "Run now" button in the portal.
* A run lock, so a slow run cannot be overlapped by the next one and process the same work twice. A lock abandoned by a fatal is broken automatically after fifteen minutes, so a crash cannot stop the runner permanently and silently.
* A run log: start time, what ran, per-task counts, completion status and duration, newest first, viewable under Events > Automation in the WordPress admin (it lived in the calendar portal until 2.13.0). The newest sixty runs are kept and older entries are pruned on every write, so it cannot grow forever. This is unattended work, and when something happens at 3am the log is the only thing that can say what did it.
* Failure is visible rather than silent. An admin notice appears after three consecutive failed runs, and separately when no run has completed for three hours, which is the case a failure counter cannot catch: a cron that simply stops produces no failures at all. Both also show on the Automation screen with the cron URL to check.
* Automated fetching, off by default. The setting exists and is deliberately left disabled: the unpublish-on-removal guard built in 2.7.0 has never been exercised against an actual removal at source, and running that unattended before it has been watched once is how live events disappear overnight. Switch it on by hand after that test. "Fetch updates" on the dashboard is unchanged.
* Per-event notification list, on native events, in the portal. Who else receives the event's reminder, so staff can see what participants are sent. The person who created the event is on it automatically, taken from the author WordPress already stores rather than a second copy that could drift; they can take themselves off. Other calendar users are added from a picker, and anyone outside the calendar system by typing their address. An address that is not valid is rejected and named back rather than dropped in silence. The resulting list is shown in plain text, along with what actually went out. It is a notification list and nothing else: it grants no permission and changes nothing about who can edit the event.
* The RSVP form now says, next to the email field, that event reminders and updates will be sent to that address.
* A separate, unticked opt-in on the RSVP form for monthly email updates from SFAF. It is never pre-ticked, and RSVPing never subscribes anybody as a side effect. Each consent is recorded as its own row with the moment it was given and the form it came from, viewable and exportable from the portal under Email Opt-ins. There is no integration in this release and nothing is pushed anywhere: whoever wires this to a mailing platform later will be asked when and where each address consented, and that is far harder to reconstruct afterwards than to record now.
* Mail is sent through `wp_mail()` and no email provider is hardcoded anywhere. From name, From address and Reply-To are settings. WordPress mail through Bluehost has poor deliverability; a transactional service must be configured before launch. See the Scheduled Tasks section above.
* Removed the three "Subscribe" buttons (Google Calendar, iCalendar, Outlook) from the bottom of the calendar. All three pointed at a query string nothing has ever handled, so all three quietly loaded the homepage. Whole-calendar feed subscription is out of scope, and a control that makes a promise the calendar cannot keep is worse than no control, particularly on the one screen where somebody is looking for exactly that feature. Per-event "Add to Calendar", the Google link and the .ics download are a different feature and are untouched.
* Reminder emails require the hourly cron job described under Scheduled Tasks above. Without it they will not go out on time, or at all.

= 2.10.1 =
* Fixed: the month calendar arrived on sfaf.org as unstyled markup. The cause was not the design, it was the address the stylesheet was requested from. The embed snippet is copy-pasted HTML carrying a script URL with the plugin version baked into it, and the script derived its stylesheet URL from its own address, version and all. So a block pasted at an earlier version kept asking for that version's script and stylesheet forever, and the browser kept serving what it had cached under those exact URLs. The markup came from the server and was current; the CSS and JavaScript were months old, and every rule for the month grid lives in the newer stylesheet. That is why day names read "Sun S Sunday", why a stray dot followed every date, and why links were underlined and cells had no borders: those are the host theme's defaults showing through where our rules never arrived.
* The embed snippet no longer carries a version in its script address, so a released change reaches every embedded page on the browser's normal schedule instead of never. The stylesheet address is no longer derived from the script's, and every response now tells the page which stylesheet the running plugin actually serves, so a cached script can no longer pin the styling to an old release.
* IF YOU HAVE AN EMBED BLOCK ON ANOTHER SITE, re-copy it once from Events > Embed Code. Blocks pasted before this release still carry the old pinned address and will keep loading a cached old script until that cache expires.
* Added a reset scoped to the calendar so the host site's styles cannot bleed into an embed: link color and underlines, table and cell borders, header backgrounds, list bullets and indents, button styling, font sizes and headings are all now stated rather than assumed. It wins on specificity rather than by shouting, so a host can still deliberately override it if they ever need to.
* The month grid has been redesigned as a dense, fully bordered calendar. Cells have gridlines on all four sides, the header is compact with the month and its date range on the left and the controls grouped on the right, Previous / Today / Next are one segmented control, and the view toggle is two icons with no text. Events inside cells are small bordered blocks with the title and time rather than bare underlined links. The date sits top left, dimmed for days outside the month, and today is a filled pill.
* The event dots are the phone treatment and are hidden on larger screens, which is what the missing stylesheet had been failing to do.
* Clarified two counts that looked like a contradiction and were not. The header counts every event from today forward across all months; the calendar counts one month, including days already past. A calendar opened on the last day of July can honestly show one event in July beside thirty-eight still to come, so the wording now says "38 events coming up" and the month says "1 event", with correct singulars in both.
* Fixed the month query to use a named meta clause rather than a meta key and a matching filter at once, which had WordPress joining the same table twice and could have duplicated or mis-sorted rows once a series filter was added.
* A month with nothing in it now says so, instead of leaving forty-two empty cells that read as a broken grid. That message is deliberately different from the one shown when a month fails to load.

= 2.10.0 =
* Three display modes, in the embed and in the shortcode, from the same renderer: a month calendar, the list, and a narrow sidebar. Both paths already shared one rendering class, so adding the modes there gave them to both at once and there is still only one implementation to keep correct.
* Month grid. Sunday first, one cell per day, each listing that day's events as title and start time, linking through to the event. Row count is worked out from the month rather than fixed at five, so the six-week months (May 2026, August 2026, January 2027) show all their days instead of losing the last few. A row grows to fit its busiest day, so nothing is ever truncated or hidden behind a "+2 more". Today is marked with a filled pill and the word "Today"; days from the neighbouring months are dimmed and hatched but still show their events. Previous, Next and Today.
* Months load one at a time and are cached on the server, keyed by month and filter, so a hundred visitors browsing to September is one query rather than a hundred. The months either side are fetched quietly after the page has painted, so the first arrow click is instant. A month that fails to load says so and offers to retry, rather than showing an empty grid, which would be indistinguishable from a month with nothing on.
* The cache is now also cleared when only an event's meta changes. A fetch that moves an imported event to a different date writes the date straight to meta without touching the post, which fired none of the old invalidation hooks: the grid could have kept showing the old day until the cache expired.
* Sidebar mode for narrow placements: date, title and start time per row, limited to a number you choose. It lists occurrences, so a weekly group appears once per date, and shows fewer rows rather than padding when fewer exist. A program with nothing scheduled shows a short line saying so instead of leaving a blank box on a live page, and every sidebar ends with a link through to the full filtered calendar.
* Redesigned list card. The image is now a full-width banner across the top of the card instead of a cropped block on the left, and the meta row leads with the date and time, ahead of the organizer. This also fixes the branded placeholder rendering as a cut-off title reading "gram Gro": it is a 16:9 image that was being squeezed into a 4:3 box, and roughly half of imported GoFundMe Pro events will never have a real image, because their API does not expose one. Everything the card carried before is unchanged.
* A visitor-facing toggle between list and calendar, defaulting to list because real months have entire weeks with no Friday or Sunday events and the grid reads sparse. The choice is remembered per block, so two embeds on one page do not affect each other.
* On a phone the grid collapses to date numbers with one dot per event, and tapping a date shows that day's events underneath in full. It opens on today, or on the next day that has anything, so it is never blank.
* The embed accepts a filter by organizer, series or category, and it applies to all three modes. Organizer is the default and usually the right one for a program page: one team's work is often several series and several categories at once.
* The embed code generator now exposes display mode and filter as independent controls rather than a list of preset block types, with a count for sidebars and a default view for blocks that show the toggle. Every combination is a snippet, so two organizers are two snippets from the same screen.
* Accessibility: the grid is a real table with day-name column headers, navigable with the arrow keys, and each day announces its date and event count. The view toggle carries its pressed state. Today, the selected day and out-of-month days are each marked by more than color.
* Fixed two contrast failures in the /caladmin portal: the pending count chips were white on SFAF yellow at 1.38:1, now dark on yellow at 12.22:1; and the keyboard focus ring on the needs-attention icon was 2.15:1, below even the 3:1 floor for non-text, now 5.02:1.
* No scheduled fetching yet: it stays manual.

= 2.9.0 =
* Removed a fabricated fundraising figure from the public calendar. Where an event had a GoFundMe goal but no real raised total, the donate block filled a progress bar to a hardcoded 65% and printed the goal multiplied by it as "$X raised". That number was a design placeholder from before the GoFundMe Pro integration existed. It was not an estimate or a projection: it was invented, and it was shown to donors next to a real goal in a way nobody could tell from a real total. It is gone. A raised amount is now displayed only when a real one has been stored, and the progress bar is drawn only when there is both a real amount and a goal to measure it against. An event with a goal and no total now shows the goal by itself; an event with neither shows the Donate button alone. Affects the event cards on the calendar and the single event page, which are the only two places the block renders.
* Saved FAQ sets. Managers were retyping the same questions for every new instance of a recurring event. A set is a reusable group of questions and answers, created from an event that already has them ("Save these FAQs as a set"), applied to any other event from a dropdown, and managed on a new FAQ Sets screen.
* Applying a set copies its rows. Editing a set later does not change events that already used it, and deleting a set never removes questions from anything, because nothing refers back to it. That is deliberate: answers drift year to year, and a linked set would silently rewrite last year's event when this year's answer changed.
* A series can carry a default set, applied automatically to every occurrence it generates from then on. That is the part that actually solves the problem, rather than a dropdown that helps only when somebody remembers to use it. Existing occurrences are untouched, and a question already present is never added twice.
* Applying a set never disturbs questions imported from a platform. They stay where they are, in the source's order, in every mode, including "replace", which only replaces your own rows.
* Fixed: the Eventbrite image is editable again. 2.8.0 locked it, which was wrong. The editor's image control writes the manual override, which a fetch has never touched and never will; only the platform's own copy is refreshed. Locking it removed the ability to put a decent banner on an event whose Eventbrite logo is a small square mark, and protected nothing.
* Category and Organizer are now highlighted on imported events until they are set. They are this calendar's own taxonomies, no platform supplies them, and an event is not really ready to publish without them. Same amber treatment as the other fields waiting on a person, on both Eventbrite and GoFundMe Pro events, and it clears the moment they are filled.
* Recurrence is now read-only on imported events. How an event repeats is decided at the source, so the "Repeats" dropdown is disabled with a line saying where to change it, rather than looking settable and quietly doing nothing.
* Fixed a serious contrast failure: the "Open the campaign page" button rendered teal text on a dark brown background at 1.53:1, well below the 4.5:1 accessibility floor and genuinely unreadable. The portal's link color was overriding the button's own white. It now measures 7.09:1, and 9.37:1 on hover.
* Em dashes have been removed from everything the plugin says to a person: admin copy, portal copy, notices, error messages, tooltips, sample content and this changelog.
* No scheduled fetching yet: it stays manual.

= 2.8.0 =
* Fixed: GoFundMe Pro raised amounts, which had never worked. Every fetch reported that "the campaign overview endpoint is not in the supplied API spec and this account did not answer it". That was false, and it was our bug, not theirs. The endpoint is supported. GoFundMe Pro support has confirmed its absence from the specification is a documentation gap they are fixing, and it answered every single request correctly. The import was then searching the response for three field names taken from the specification (raised_amount, progress_bar_amount, total_online_funds_raised) that are not in it. The real fields are gross_amount, total_gross_amount, net_amount, fees_amount and percent_to_goal.
* Campaign totals now come from gross_amount, which is what GoFundMe Pro's own progress figure is calculated from: a campaign returning 1,833 gross against a 100,000 goal reports 1.833% to goal, so the calendar's bar now shows the same number a donor sees on the campaign page. Net (after fees) would have shown a quietly smaller total than the source with no way to explain the difference.
* A raised-amount lookup that genuinely fails is now reported for the campaign it failed on, by name, with the reason. It no longer makes a claim about whether an endpoint exists on the strength of one empty result.
* The raised-lookup cap has gone from 100 to 500. GoFundMe Pro's published limit is 300 requests a minute per application, and an hourly run over about twenty campaigns costs roughly forty requests, so the cap was never doing rate-limiting work and is now only a guard against a runaway loop. It was not the cause of the missing amounts. Requests that are rate-limited now wait out the retry_after they are given and try again rather than failing the whole source.
* Campaign FAQs are now imported. Each campaign's questions and answers are read from GoFundMe Pro, following pagination to the end, ordered by the weight the campaign sets, and written into the event's FAQ block so they render exactly like questions typed by hand.
* Imported FAQs stay in step with the campaign. On every fetch, a question that changed at the source is updated here, a new one is added, and one deleted there is removed here. This does overwrite local edits to an imported question, deliberately: the campaign's answer is the answer of record, and a calendar showing a policy the campaign changed months ago is worse than one that loses a typo fix.
* Questions you add yourself are never touched: not updated, not reordered, not removed, and always appear after the imported ones. Removal of an imported question is held to the same guard that governs taking an event off the calendar: it never happens on a failed, partial or empty fetch. The fetch report counts FAQs added, updated and removed per source.
* Each platform now declares which fields it owns, and the editor is built from that declaration. Fields a fetch will overwrite are shown but not editable, grayed with a small lock and the "Edit on…" link beside them, so nobody types into a box whose contents are replaced on the next run. The list the editor locks is literally the list the importer writes, so the two cannot drift apart.
* GoFundMe Pro campaign images and descriptions are now permanently yours. Their support has confirmed that the campaign banner and the About copy live in the campaign's page design and are not exposed on the public API at all. Those two fields are now formally excluded from every import, refresh and future scheduled update, with a note in the editor saying why, so that what you write survives, and so that nobody later "fixes the gap" by adding a mapping and starts silently overwriting your copy.
* Both fields are highlighted in amber while they are empty, with an icon and words rather than color alone, and the highlight simply disappears once they are filled. Amber and not red: a campaign arrives needing them every time by design, so this is a step in the job and not a fault.
* The pending queue marks events still waiting on those fields with the same amber pencil, tooltipped with what specifically is missing ("Needs an image and a description") on hover and on keyboard focus, with the same text for screen readers. The campaign page link on those rows is promoted so filling them in is copy and paste from the source. A queue with no marks means everything in it is ready to publish.
* Publishing an event that is still missing them asks for confirmation naming what is missing, and then publishes. It does not block: there are good reasons to put an event live before its copy is written.
* Eventbrite is unchanged apart from declaring the fields it already refreshed.
* No scheduled fetching yet: it stays manual.

= 2.7.1 =
* New temporary diagnostic in the GoFundMe Pro settings panel: enter a campaign ID, press "Probe campaign", and see exactly what four campaign endpoints return: the URL called, the HTTP status and the response body, printed verbatim with nothing decoded, reordered or left out. Failures print their error body too, since how a call fails is information. It is strictly read-only: nothing is imported, created or changed. Output can be copied as text in one click.
* The reason for it: the supplied API specification has now been wrong or silent four times: the token endpoint, the raised amounts, the campaign image and the campaign description. This replaces reading the specification with looking at the account.
* Stopped importing the wrong description. GoFundMe Pro campaign descriptions were being taken from the campaign's default appeal text for individual fundraiser pages, which produced personal-fundraiser copy on the calendar. The Campaign record has no description field, and every other text field on it is a default for some page or team rather than the campaign's own copy, so nothing is mapped at all for now. Write a description when approving the event. It will survive every later fetch.
* One-time cleanup for descriptions already imported that way. On the next fetch, any GoFundMe Pro description that still exactly matches its campaign's appeal text is cleared; anything edited by hand differs and is left alone. The fetch report says how many were cleared and how many were kept.
* Stopped importing the wrong image. The campaign cover photo turned out to be artwork that appears nowhere on the campaign page, and the only other candidate is the small logo mark. Campaigns now fall through to the calendar's own placeholder rather than showing a wrong picture. The filter that chooses the field is unchanged, so the right one can be switched on without a new release once we know it.
* The source's image can now be cleared as well as set. It belongs entirely to the platform. An image you choose yourself lives separately and is untouched, so a source that stops offering one no longer leaves a stale picture behind. This applies only to the platform's image; a hand-written description is still protected from being blanked by a refetch.

= 2.7.0 =
* Imported events now update on re-fetch. Previously anything already known was skipped, so a photo, title, time or venue changed at the source never reached the calendar. A fetch now refreshes the platform's fields on events it already has, and the report counts how many were updated, unchanged or newly added.
* Local decisions are never touched by an update: category, organizer, series, FAQs, RSVP settings, a manually chosen image, and, importantly, whether the event is published. An update can change what an event says, never whether it is live.
* An empty value from the source never overwrites something already there. Most GoFundMe Pro campaigns have no date and a manager fills one in when approving; a refetch used to be capable of erasing that. Blank from the source now means "nothing to say", not "clear this".
* The source's image and a manually chosen image are now kept apart. The source's image refreshes on every fetch into its own field; an image you pick or paste always wins for display, and clearing it falls back to whatever the source currently has. Events imported by 2.6.0 are separated automatically on first load, since that release wrote both to the same field.
* GoFundMe Pro campaigns now use the campaign's cover photo rather than its small logo mark. The Campaign record exposes only those two image URLs and has no banner field, so this is a considered guess. The fetch report lists which field each campaign resolved to and the resulting URL, so we can see what actually came back rather than assuming.
* Events that disappear at the source are taken off the calendar and kept as drafts, never deleted, and can be republished. They are not auto-republished if they come back: that is reported instead, so a person decides.
* The removal check is heavily guarded, because "not in the response" and "the fetch broke" look identical. It only runs when every request in that source's run succeeded, pagination reached the last page, and at least one item came back. Any doubt and the whole step is skipped for that source and the report says why. It is per source: one platform failing never affects another's events. A campaign closed at the source is counted separately from one that vanished outright.
* New "Refresh from source" button on the edit screen for third-party events. It re-fetches that one event and reports field by field what changed, or that nothing did, with the same protections for local fields.
* No cron yet: fetching stays manual. Unpublish-on-removal running unattended before one clean cycle has been watched is how live events disappear overnight.

= 2.6.0 =
* GoFundMe Pro campaigns can now be imported. It plugs into the same framework Eventbrite uses: the same "Fetch updates" button, the same Pending queue, the same publish/dismiss/restore actions and the same no-duplicates rules. Adding it required no change to the importer itself, which is what the framework was for.
* Campaigns are read from the organization's campaign list, following pagination to the end, and land in Pending with their name, appeal text, dates, venue and address, image, campaign URL and fundraising goal.
* Campaigns without a date are imported rather than skipped: a general fundraiser has no start or end, and the Pending row says "No date. Set it when publishing" so the manager fills it in on approval. (This also fixed a real defect: the queue was ordered by event date in a way that would have silently hidden every dateless import.)
* An imported campaign fills in the GoFundMe URL and goal fields the calendar already uses, so the donate block and progress bar pick it up with no extra setup.
* The progress bar now shows real numbers when it has them. It previously showed a fixed 65% for every campaign; that placeholder now applies only where no real raised figure exists, and is never used to override real data.
* Fixed: imported Eventbrite events had no image, even though the fetch was already retrieving it. The image is now written to the field the calendar actually renders from, using Eventbrite's full-resolution original rather than its cropped version. Third-party images are used as URLs and are not copied into the media library; events created here still use "Choose Image" as before.
* The dashboard "Fetch updates" report now covers every source, not just connected ones. A source that is not connected says exactly what it is waiting for ("no Organization ID stored"), a source that ran but found nothing says that instead of showing a bare zero, and each source's result is listed separately: "Eventbrite: 2 new, GoFundMe Pro: 3 new". Pressing Fetch with nothing connected no longer reports success with nothing beneath it.
* Note on fundraising totals: the supplied GoFundMe Pro API specification defines the raised-amount fields but documents no endpoint that returns them. Goals come from the campaign record and are reliable; raised amounts are looked up from a pattern-matched endpoint that fails quietly, and the fetch report says plainly when none were available.

= 2.5.2 =
* Fixed: platform credentials no longer go missing. Every credential the plugin holds has moved out of the general settings option into its own dedicated, autoloaded store that is never rewritten wholesale. The general settings option is rebuilt from scratch on every save, and anything its sanitizer did not explicitly copy across was lost. That is exactly the fragility that had already cost the GoFundMe Pro token an earlier fix. Credentials are no longer stored there at all, so a save that does not mention one cannot erase it.
* Moved: the Eventbrite private token and API base URL; the GoFundMe Pro client ID, client secret, organization ID, token endpoint and API base; the multi-site API key; the Galaxy Digital API key; and the webhook secret. Existing values are copied across automatically on first load after updating. Nothing needs re-entering, and the move never overwrites a value already in the new store, so it is safe if it runs more than once.
* Fixed: entering a credential, pressing "Test connection", seeing it succeed and then leaving the page without pressing Save Changes used to discard it silently. That looked exactly like a credential being lost on update. A credential that has just proven it works is now saved at that moment.
* The two write-only fields, the Eventbrite private token and the GoFundMe Pro client secret, stay write-only: never rendered back into the form, never returned to the browser, and blank still means "keep the stored one".
* Confirmed by audit: nothing in this plugin deletes or resets a credential. There is no uninstall.php and no uninstall hook, so even deleting the plugin leaves stored credentials intact; deactivation only flushes rewrite rules; and the activation routine, which runs again after every update, only creates the RSVP table, registers the post type, seeds sample data once and flushes rewrites. A comment there now says outright that it must never touch credentials.
* Connection state was already held in its own options (the GoFundMe Pro token and the Eventbrite verification record) and is unaffected. The GoFundMe Pro integration ID is a filterable constant rather than stored data, so there is nothing there to lose either.

= 2.5.1 =
* GoFundMe Pro: requests now carry the `x-integration-id` header their support issued for this integration. The Cloudflare 403 that blocked every token request was their edge security treating this server's traffic as a bot. Support confirmed the endpoint and our credentials were correct all along and the credentials were never actually examined. This header is the fix they specified.
* GoFundMe Pro: the header goes on every request through the shared helper, not just the token call, so campaign and other data calls carry it too and cannot drift out of step. The value is a constant, overridable with the `sfaf_gfmp_integration_id` filter if it is ever reissued, and the settings panel shows the ID in use.
* GoFundMe Pro: the token endpoint is unchanged at https://api.classy.org/oauth2/auth, which support confirmed is correct. Guidance to try pro.gofundme.com as a token endpoint has been removed throughout. Support confirmed it does not issue tokens, so it was never a valid fallback. The settings panel now says to leave the token endpoint alone.
* GoFundMe Pro: the bot-protection error message has been rewritten for the new situation. It reports the integration ID and User-Agent that were sent, and points at confirming the ID rather than at changing the endpoint. Connection test output is otherwise unchanged and still reports the status, endpoint and message.

= 2.5.0 =
* Third-party events can now be imported into a review queue. A "Fetch updates" button on the /caladmin dashboard asks every connected source for its events, and anything genuinely new lands in a Pending list for a manager to publish or dismiss. Nothing appears on the calendar without a person deciding it should.
* Built as a shared framework rather than an Eventbrite feature. Each platform is an adapter, "Fetch updates" runs all of the connected ones, and each reports its own result: "Eventbrite: 2 new". One source failing does not stop the others; its error is shown beside the sources that worked. GoFundMe Pro and EveryAction will connect as further adapters without changing the importer.
* Eventbrite is the first adapter, reading upcoming published events across every organization the account owns.
* The Pending page now has two new sub-sections above the existing review queue. "Imported, pending review" lists new third-party events with their source, date and time, location, and a link to the event on the platform; each can be Published or Dismissed. Dismissed events move to their own section and can be Restored or Published from there. Dismissing keeps an event, it does not delete it.
* Publishing an imported event opens it first, so a category, organizer and series can be assigned before it goes live. The platform owns the title, description, times, location and image; those three are local decisions and are never guessed.
* Nothing is ever imported twice. Every fetched event is matched by source and source ID against every existing record in any state: published, pending, dismissed, and trashed. Anything already known is left exactly as it is. A dismissed event is never resurrected by a later fetch.
* Imported events are held in two dedicated statuses that no public query can reach, so they cannot appear on the calendar, in the embed, in the REST feed, in search, or at their own URL until someone publishes them.
* Each imported event records where it came from, its ID at the source, and the URL of its page there, so the event's edit screen links back to the original and later work can match against it.

= 2.4.0 =
* Eventbrite: a "Fetch events (preview)" button in the Settings panel now reads the account's events and shows exactly what came back. It is deliberately read-only: no events are created on this site and nothing is queued, so the data can be inspected before anything is mapped to it. Importing is the next step.
* Eventbrite: events are read the way Eventbrite currently documents. The account's organizations are fetched first, then the events under each one; an account owning several organizations has all of them read and the results combined. The old single-call route is deprecated and is not used.
* Eventbrite: the fetch asks for the venue and logo expansions, so location and image data appear in the preview rather than being absent by default. Where an expansion is genuinely empty, as with an online event that has no venue, the preview says so, so a missing address is never mistaken for a failed request.
* Eventbrite: paginated results are followed to the end. If the run stops early, either Eventbrite reporting more items but sending no continuation token, or the page limit being reached, the preview says so plainly instead of showing a partial list that looks complete. The limit is adjustable with the sfaf_eventbrite_max_pages filter.
* Eventbrite: the preview shows each event's id, name, status, start and end (local time with timezone, and UTC), venue name and address, ticket URL, logo URL, and whether a description is present and how long it is, plus the untouched JSON of the first event, so the full shape is visible. A status selector chooses which events to read; published (live) is the default.
* Eventbrite: one organization failing no longer loses the rest. Its error is reported against that organization and the other organizations' events still come back. If the fetch is rejected as unauthorized, the "Connected" badge is withdrawn rather than left claiming a connection that no longer works.

= 2.3.0 =
* Eventbrite: new integration panel in Settings, with authentication only at this stage. Enter the account's private token and press "Test connection". The plugin calls Eventbrite for real and reports the account name and email it reached, so a connection is something you have seen work rather than something the screen claims.
* Eventbrite: the "Connected" badge is written only by a successful live call, and is fingerprinted against the token and API base URL that produced it. Change either one and the badge drops back to "Not connected" instead of leaving a stale claim on screen.
* Eventbrite: the private token is write-only. It is never sent back to the browser, never logged, and never appears in an error message; the field submits blank unless you retype it, and blank means "keep the stored one".
* Eventbrite: the API base URL is an editable setting pre-filled with the documented default (https://www.eventbriteapi.com/v3), so it can be corrected on a live site without a new release. The sfaf_eventbrite_api_base filter overrides it in code.
* Eventbrite: failures report the real HTTP status, Eventbrite's own error code and description, and the exact endpoint that was called. If a bot-protection page comes back instead of an API response, that is named outright rather than being misread as a bad token.
* Eventbrite: every request carries an identifying User-Agent and asks for JSON explicitly, set in one shared place so the coming event calls cannot drift from what authentication uses.

= 2.2.1 =
* GoFundMe Pro: requests now identify themselves with a proper User-Agent and ask for JSON explicitly. The token host is behind Cloudflare, which was answering WordPress's default agent with a bot-protection page instead of passing the request through, a rejection that looked like an authentication failure but never examined the credentials. The headers are set in one shared place, so campaign calls will carry them too, and the agent is adjustable.
* GoFundMe Pro: if a bot-protection page comes back anyway, the error now says so outright, names what it matched and the agent that was sent, and lists what to try next, rather than showing a fragment of the challenge page's HTML.

= 2.2.0 =
* GoFundMe Pro: authentication now uses the documented request exactly: credentials form-encoded in the body, sent to the token host at api.classy.org. The experimental Basic-auth and JSON-body variants added in 2.1.2 have been removed now that the correct format is confirmed.
* GoFundMe Pro: the token endpoint and the data API base are now editable settings, pre-filled with the documented defaults. Tokens and data come from two different hosts, and the documentation and the API specification disagree about the data host, so either can be corrected on a live site without a new release. Leave a field blank to use its default.
* GoFundMe Pro: the connection result now names the data base URL that campaign calls will use, alongside the token expiry.

= 2.1.2 =
* GoFundMe Pro: the token request now sends the client credentials as an HTTP Basic Authorization header rather than in the request body, which is the standard OAuth2 form and the likely cause of the "access token is missing" rejection. Because the API documentation does not state which form its token endpoint expects, "Test connection" now tries all four combinations of header-or-body credentials with a JSON-or-form body, stops at the first that works, reports which one succeeded, and remembers it for later refreshes. If every combination fails, each one's error is reported so the responses can be compared.

= 2.1.1 =
* GoFundMe Pro: corrected the API address. Authentication now points at https://pro.gofundme.com/api/2.0, taken from the official API specification, rather than the pre-rebrand Classy host used in 2.1.0. The authentication method itself was already correct. The settings panel now shows the exact base and token addresses being used.

= 2.1.0 =
* GoFundMe Pro: real authentication. "Test connection" now performs an actual OAuth2 client-credentials token request against the Classy API using the saved Client ID and Secret, stores the returned token with its expiry, and refreshes it when it lapses. The "Connected" badge reflects whether a token was genuinely obtained, the old hand-settable flag is gone, and success or failure is reported on screen, failures including the HTTP status, the message returned, and the endpoint that was called.
* GoFundMe Pro: the Client Secret is no longer written back into the settings page. The field submits blank unless retyped, and blank means "keep the stored secret". Neither the secret nor the access token is ever displayed or logged.
* GoFundMe Pro: the "Fetch Campaigns" button has been removed for now. It never called anything. Campaign fetching and event import are the next step and are deliberately not part of this release.

= 2.0.6 =
* Embed: event images now appear on other sites even when an image optimizer is active here. Optimizers rewrite image tags to hold a blank placeholder, keeping the real address in a side attribute that their own script swaps back in, which works on this site but not on a site that does not load that script, leaving the image blank forever. Embed responses now put the real image back into the standard attributes before sending, so no script is needed at the other end. Recognizes the Jetpack, WP Rocket, lazysizes and a3 Lazy Load conventions, and keeps native browser lazy loading.

= 2.0.5 =
* Embed: event images no longer show as blank placeholders on other sites. Any relative URL in the embed's markup was resolved by the browser against the site displaying the embed rather than the calendar site, so images stored as a path (rather than a full address) could not be found. Every image and link address in an embed response is now fully qualified with the calendar site's address. Covers src, srcset, lazy-loading data-src and data-srcset, and links. Events with no image still show the placeholder graphic, as before.

= 2.0.4 =
* Fixed a fatal error on the embed endpoint: the per_page parameter used PHP's intval() as its sanitize callback. WordPress passes three arguments to a sanitize callback, and since PHP 8 a built-in function given more arguments than it accepts raises an error, so every request to the endpoint failed. It now uses a single-argument wrapper. Audited every REST parameter callback in the plugin for the same pattern; this was the only one.

= 2.0.3 =
* Embed: the card-style value is now read directly from the branding settings inside the response builder, instead of through a helper method on the embed class. Removes a callable that a partial or stale deployment could leave missing, which produced a fatal on the embed endpoint.

= 2.0.2 =
* Embed: the card style chosen under branding now applies to embeds on other sites. It previously reached the CSS through a body class that only exists on this site, so remote embeds always rendered in the default style.
* Embed: a request that is silently dropped rather than refused now times out after 15 seconds and falls back, instead of leaving "Loading events…" on the host page indefinitely.

= 2.0.1 =
* Fixed a fatal error that made the public embed endpoint return a 500 on every request, which also prevented its cross-origin (CORS) header from ever being sent. The endpoint called a WP_REST_Response method that does not exist; the header it needed to clear is now removed through the supported API.

= 2.0.0 =
* Authorship and identity updated to San Francisco AIDS Foundation; plugin description rewritten to describe SFAF's actual use.
* Sample demo data now seeds only once, ever (a one-time flag), and never re-inserts after the site is emptied and the plugin reactivated.
* Removed a broken zero-byte bundled zip; added repo hygiene (.gitignore/.gitattributes).
* Series and venue filtering on [sfaf_calendar] and [upcoming_events].
* Public HTML embed: render the calendar on any other site with a small block, backed by a public read-only embed endpoint and a dependency-free script. New Events > Embed Code generator screen.
* Brand guide v3.0 compliance across the public calendar, admin, and /caladmin portal: inline-SVG icon set replacing emoji, AA-contrast colors, Montserrat/Merriweather typography, and the approved palette as the branding defaults (the branding settings themselves are unchanged).

= 1.7.5 =
* Multi-site API: the events feed now includes each event's slug so satellites can mirror the main site's URLs exactly (including the dated occurrence slugs).

= 1.7.4 =
* Recurring occurrence URLs now include the date: each child event's slug is "{parent-slug}-YYYY-MM-DD" (e.g. /events/prop-contingency-management-2026-05-26/) instead of the generic "-2"/"-3" suffix. The series parent keeps its original slug.
* A one-time update renames existing occurrence slugs to the new dated format on the next admin page load.

= 1.7.3 =
* Multi-site API: the events feed now supports a `page` parameter (paginated) so satellites can pull every published event, not just the first 100.
* Multi-site API: each event now carries full term identity (id, slug, and category color per category/organizer/venue) so satellites can mirror category/organizer/venue renames, slug changes, and color changes instead of creating duplicate terms.

= 1.7.2 =
* /caladmin: event rows now have a proper "Actions" column with Edit/Remove aligned on one row with consistent spacing (the actions cell no longer breaks table cell alignment). Renamed the "Trash" action to "Remove" throughout the portal.
* Performance: the /caladmin portal is significantly faster across saving, loading, and navigation. Event lists now bulk-load post/meta/term caches and batch RSVP counts into a single query instead of one query per row; dashboard counts use found_posts instead of loading every event ID.
* Performance: series membership, series images, category colors, RSVP counts, and portal roles are now memoized per request to eliminate repeated lookups; frontend cards batch their RSVP counts too.
* Performance: saving a recurring series only rewrites occurrences that actually changed (and primes its children up front), so re-saving an unchanged series no longer rewrites every occurrence.

= 1.7.1 =
* Event cards now show a consistent 4:3 image thumbnail on the left (full cards) and a small thumbnail (compact/upcoming cards); AJAX-loaded cards include them too.
* Single event pages show the image prominently at the top only when an actual image exists.
* Series Manager list (WP admin + portal): image column now renders a small thumbnail (with a placeholder icon when no image is set) instead of the full placeholder graphic.

= 1.7.0 =
* Series Manager in both WP admin (SFAF Calendar ▸ Series) and the /caladmin portal: list series + edit shared properties (name, image, FAQ, default description/location/time, category, organizer) that flow to occurrences.
* Image inheritance: event image → series image (_uc_series_image_*) → synced image → SVG placeholder, with per-event override + "Reset to series image".
* FAQ model: canonical Series FAQ (managed in the Series Manager) + per-event FAQ that appends or replaces (override). Event editors show inherited series FAQ read-only plus an editable event-specific FAQ.
* REST response includes series name/image for satellites.

= 1.6.0 =
* Frontend pagination for [sfaf_calendar] and [upcoming_events] with three styles: Load More, Next/Previous pages, and Infinite scroll.
* New Settings ▸ Display section: Events per page (default 12) + Pagination style.
* Shortcodes accept a per_page attribute to override the global setting; per_page="0" or "-1" shows all events with no pagination.

= 1.5.0 =
* Fix: recurring child events now inherit the parent's image (_uc_image_url + featured image) so URL images display on every occurrence.
* Multi-site is now one-directional: this plugin is the API server only. Removed satellite pull/push, the POST /events/sync receiver, and the sync cron. Use the separate "SFAF Calendar Satellite" plugin on satellite sites.
* Events feed (GET /events) now returns full meta (venue, GoFundMe URL/goal, display toggles, FAQ, category colors, image, series info) and can be gated by the API key.
* Simplified the Multi-Site settings panel to the API endpoint + key generator.

= 1.4.4 =
* Multi-site sync now includes the featured image URL; satellites store it in _uc_remote_image_url and display the main site's image without downloading it.
* /caladmin event form: featured image section with WP media library picker (sets the post thumbnail) plus an image URL fallback (_uc_image_url), live preview, and Remove.
* sfaf_event_thumbnail() priority: local featured image → _uc_image_url → _uc_remote_image_url → SVG placeholder.

= 1.4.3 =
* Version bump.

= 1.4.2 =
* Removed the Settings section from the /caladmin portal. Plugin configuration is now managed only via WP Admin > SFAF Calendar > Settings. The portal focuses on event management: dashboard, events, RSVPs, pending queue, and users & permissions.

= 1.4.1 =
* Fix: post type & taxonomies were registered via add_action('init') from inside the init hook, so they never ran on normal loads: no admin menu and events hidden. Now registered directly.

= 1.4.0 =
* Sample events + taxonomy terms (with category colors) seeded on first activation
* SFAF brand colors: yellow/black/gray palette; branding defaults yellow + teal; black/yellow /caladmin portal & login (event cards keep teal/warm)
* SEO/AEO/GEO: Event, FAQPage, and BreadcrumbList JSON-LD, Open Graph + Twitter cards on single events, sitemap inclusion
* Series FAQ system: per-parent/standalone FAQ repeater (admin + portal), inherited by children, frontend accordion
* Branded inline-SVG placeholder thumbnails per category via sfaf_event_thumbnail() (cards + single page)

= 1.3.0 =
* GoFundMe Pro: OAuth2 fields (Client ID/Secret, Org ID) + Connect/Fetch buttons, Classy base URL
* Galaxy Digital: Bearer-token auth, agency ID, needs-as-category, import events / active-only / auto-sync + interval, Sync Now; Volunteer badge, spots remaining, and Sign Up button on imported needs
* Multi-site sync now functional: Generate Key (AJAX), Site Role (Main/Satellite), POST /events/sync with X-SFAF-API-Key validation, pull/push, wp-cron, Last Sync; Source indicator on event lists
* Donate button confirmed as a direct new-tab link (shown only when a campaign URL is set)

= 1.2.0 =
* Recurring events: generate individual occurrence posts linked by series, with parent/child editing, regeneration, and preservation of manually edited occurrences
* Frontend series info ("Part of series" link + "Upcoming in this series" list)
* /caladmin standalone front-end portal: custom login, role-based dashboard (Admin/Editor/Contributor), event CRUD, RSVP viewer + CSV, pending queue, users & permissions, settings mirror
* Custom Events list columns: Event Date (sortable), RSVP count, Series, integration indicators
* Shortcode generator now uses a read-only field; added {organizer_name} email token

= 1.1.0 =
* Frontend: social share, donate button with progress bar, add-to-calendar (.ics + Google), reminder signup
* New styled single event template loaded via template_include
* Per-event display toggles, organizer notification, and confirmation email overrides
* GoFundMe campaign manager + per-event campaign dropdown
* Pardot campaign manager, global default, and category-to-campaign mapping
* Shortcode Generator admin page
* Branding controls (logo upload, color pickers, card style)
* Global email templates and RSVP data routing
* layout="compact" attribute for [sfaf_calendar]

= 1.0.0 =
* Initial release
* Custom post type with taxonomies
* RSVP system with capacity tracking
* Frontend calendar with filters and search
* Admin settings with integration panels
* REST API endpoints
* CSV export
