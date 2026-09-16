=== SFAF Calendar ===
Contributors: sanfranciscoaidsfoundation
Tags: calendar, events, rsvp, nonprofit, embed
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 3.90.0
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
* `[sfaf_calendar view="combined"]` - Month grid and the upcoming dates sidebar side by side, stacking below 864px
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
* Combined mode: **260px**, and it goes side by side at **864px**

The month grid and combined views cap at **1200px** rather than 900px, because
a grid is seven columns and every pixel of column width is room for the title
inside it. At 1200 a column is 171.1px outer and 159.1px of content, which
leaves 109.1px of title once the 32px thumbnail and its 8px gap are taken.
Below 930px of container width the thumbnail is dropped and the entry is a
title and a time, which is what it was before 3.31.0.

The combined mode has no minimum of its own because below 864px it stops being
a two-column layout: the sidebar wraps under the grid and each takes the full
width, at which point it is the month grid and the sidebar, whose 260px and
200px apply unchanged. 864px is where the changeover happens, not a minimum.

That number is the two flex bases, 576 + 288, because flex line breaking uses
each item's flex-basis rather than its shrunk width. There is no gap in the sum
any more: since 3.45.0 the two halves are one container with a divider between
them rather than two cards 24px apart, so the gap is zero and the changeover
moved from 888px to 864px with neither basis touched. It is
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

= 3.90.0 =

**THE CYCLE TO ZERO PICTURE WAS PINNED TO AN ATTACHMENT THAT NO LONGER EXISTED, AND THAT IS WHY A CORRECTLY TAGGED PICTURE SAT THERE UNUSED.** The previous investigation concluded nothing was tagged. That was wrong, and the real fault is an ORDER problem rather than an empty state.

**`SFAF_Series::image_id()` read the stored attachment id first and never asked whether it still resolved.** Mark replaced the picture: the old attachment went, the meta pointing at it stayed, and a stale id is still truthy. So it won, `image_url()` ended at a dead attachment, and the tag fallback below it never ran. The series could not reach the picture in the folder because it was still pointing at one that was gone.

**Established by execution, not by reading.** `.claude/series-image-stale-test.php` builds exactly that state, an attachment table with the old id absent and the replacement tagged, and the unfixed code returns an empty string while the tagged picture is right there.

**A STORED ID THAT NO LONGER RESOLVES IS NOW TREATED AS ABSENT**, so the chain continues to the tag. **Fall through, do not clear:** deleting the stale meta on read would turn opening a page into an edit, it would fire on the public calendar for every visitor, and it would destroy the only record of what the series was pinned to before anybody could look at it. An existence check costs one cached lookup and leaves the evidence in place. **A picture that is still there still wins, always.**

**TWO READERS HAD THE SAME BLINDNESS**, which is why every surface went dark at once instead of one of them. The series screen read the term meta directly and previewed `$img_id ? url($img_id) : $img_url`, so a dead id did not even fall through to the pasted URL beside it, and it never consulted the tag at all. It asks the same chain the calendar asks now, so the screen and the calendar cannot disagree about what a series' picture is.

**WHY THE UPLOAD PANEL'S TAG WAS NOT USED: it was written, and nothing was wrong with it.** Mark selected the series in that dropdown and the tag went on correctly. It was never reached, because the stale id short-circuited the chain three steps earlier.

**THE "ALL CALENDAR IMAGES" BUTTON HAS NEVER BEEN VISIBLE**, since 3.74.0, which is the sixth control in this project found built and unreachable. Its own note says it is "rendered always and hidden by portal.js when there is no series". Only the second half was true: it was rendered with `hidden` in the markup, `portal.js` only ever sets that attribute, nothing removes it and no stylesheet reveals it. It renders visible now and the script hides it when there is nothing to escape from, which is what the note always claimed.

**A BLANK PICKER SAYS WHY IT IS BLANK.** wp.media's own empty state is "No media items found", which is true and useless: it cannot know the library was narrowed to one folder and possibly one series. The public forms' picker has had that sentence since 3.80.0; this is the caladmin equivalent, written on every open rather than once, because uploading a picture into an empty folder makes it wrong to still say the folder is empty.

**THE SERIES SCREEN'S PICKER NO LONGER NARROWS TO ITS OWN SERIES.** The reasoning was circular: that screen is where a series' picture is ESTABLISHED, so offering only pictures already tagged to it means the first one can never be chosen. Combined with an invisible escape button and a silent empty grid, it was a dead end built out of three separately correct decisions. **The event editor's narrowing stays**, because there the series is context rather than the subject.

**CHOOSING A PICTURE ON THE SERIES SCREEN NOW TAGS IT TO THAT SERIES.** Mark's reasoning: the Series dropdown on the upload panel IS the tag, so setting a series' picture and tagging it should not be two separate things somebody has to know to do.

**WHAT THAT COSTS AGAINST 3.81.0**, which decided a tag is a FALLBACK rather than a second setting. That decision survives in the direction that matters: nothing makes a tag override a setting, and the stored id is still read first. What changes is that a deliberate choice now leaves a tag behind it, so the two facts agree instead of drifting apart. **Nothing now reads a tag assuming nobody set it deliberately:** the three readers are the picker's series filter, the earliest-tagged fallback and the Images screen's grouping, and all three mean "belongs with this series", which is exactly what choosing it asserts. The one thing to keep in view is unchanged and is why the fallback is the earliest rather than the newest: tagging an OLDER attachment to a series with no stored picture can change what that series falls back to. **It is additive and never untags**, because a save quietly unpicking a relationship nobody mentioned is a shape of fault this project keeps meeting.

**A CALADMIN UPLOAD NOW APPEARS IN THE MEDIA LIBRARY'S CALENDAR FOLDER TOO.** Both of Mark's facts were true at once: fourteen pictures sit in `uploads/calendar/` and the library's Calendar folder showed five. That folder belongs to WP Media Folder, which files by TAXONOMY ASSIGNMENT rather than by location, and this plugin had only ever set the path.

**THE TAXONOMY IS DISCOVERED, NOT NAMED, AND THAT IS THE WHOLE DESIGN.** WP Media Folder is commercial, is not on wordpress.org and is not on the build machine, so its taxonomy name could not be verified here. Hardcoding a guess would either work silently or fail silently with no way to tell which. Instead the code asks WordPress which taxonomies attachments actually carry and looks for a term that is literally the calendar folder's name.

**IT CANNOT MISFILE ANYTHING.** Three conditions, all required: the taxonomy must be registered for `attachment`, it must not be one of ours, and it must ALREADY contain a term matching the folder. Nothing is created and nothing is invented, so a site without that plugin, or with the folder named something else, is untouched. It is appended rather than replacing, so a picture already filed elsewhere keeps its other folders, and it only ever runs on an upload carrying the calendar flag. Two filters turn it off or correct the name without a release.

**NO FILES WERE MOVED AND NO STORED PATH WAS REWRITTEN**, as instructed. The nine pictures already on disk and absent from the library folder are not retrofitted by this: it changes what happens to uploads from here. Filing those nine is a decision rather than code, and the options are in PROJECT.md.

**Eight faults planted and every one caught by name**, including both halves of the shipped fault. **Three escaped a first draft**, and one of those is worth recording: a guard's removal left the test green because the fixture never gave the excluded taxonomy a matching term, so the assertion had been passing for the wrong reason. The fixture now makes the guard the only thing preventing the write.

**Unchanged**: the folder rule reads the physical directory, the event editor picker narrows by series, the public forms' picker and its MarCom sentence, search and the filters in calendar view, and the filter dropdown's open state across a redraw.

= 3.89.0 =

**THE FILTER PANEL SHUT ON EVERY TICK, ON THE EMBED, AND THAT IS THE FOURTH TIME.** 3.87.0 carried the open state across the redraw in `calendar.js`, because the server renders a `<details>` closed and the redraw replaces it. `embed.js` does its own swap, with `innerHTML`, and never got the fix. Choosing two organizers meant reopening the panel between them.

**WHY THE PAIR TEST PASSED WHILE THE BEHAVIOUR WAS MISSING**, which is the part worth keeping. `.claude/embed-filters-test.php` was written in 3.88.0 and lists the behaviours being fixed THAT DAY: the merged control, the narrowing, the month redraw, the cache keys. Carrying the open state was added a release earlier and was never on the list, so its absence from the other script was not something the file could notice. **It was green and it was blind.** A pair test only covers the pairs somebody enumerated, and the remedy is not a better list: anything touching the filter bar in one script adds its pair in the same release, which is now in PROJECT.md rather than left to memory.

**A SECOND FAULT FOUND WHILE FIXING THE FIRST, in both scripts.** The narrowing is an attribute on each row and the redraw replaces every row, so the groups hidden by a tick came back one frame after being hidden. Neither script reapplied it: the handlers are delegated and survived, the state they had written did not. `narrowWho()` is now module level in both and is called from the one place that does the swapping.

**THE MONTH GRID IS LINES, NOT CARDS.** Each event was a bordered box with a 32px picture, a title clamped to two lines and a time on its own line, which cannot be shorter than 42px. Nine on one Wednesday made a row that pushed the rest of the month off screen. It is one line now: a category dot, the title, the time.

**MEASURED IN CHROME AGAINST THE RENDERER'S OWN MARKUP**, nine events in one cell:

```
one row          21px
row pitch        22px
nine events      196px   (the old tile could not be under 42px each)
border           0px     background  transparent
title lefts      1 distinct   time rights  1 distinct
longest title    one line, ellipsis, time still visible and inside the cell
```

**FOUR THINGS KEEP NINE LINES READABLE, and none of them is a border**, because "clean and clear" was the condition this was asked under and nine undifferentiated lines of text is not an improvement on nine boxes. The dot is a left margin, so every title starts at the same x and they form a column the eye runs down, which is what the border used to do and costs no height. One line each, so the rhythm holds where the day is busiest. The time is right aligned and quiet, so the times form their own column. The hover is the whole row rather than a 1px edge.

**THE DOT IS NOT THE ONLY CARRIER OF THE CATEGORY**, which is a rule this project already holds: colour alone cannot state a fact. The category name is emitted beside it as text, in the accessibility tree, because there is no room for a word at this size. The title is the fact; the dot reinforces it.

**A TITLE THAT WILL NOT FIT IS CUT, NOT WRAPPED.** "Opioid Overdose Prevention and Naloxone Training" does not fit at 11px in a seventh of a calendar and no size it could be cut to would. Wrapping it is what made the rows enormous. Nothing is lost: the full title is on the hover preview, on the event page, and in the accessibility tree, which reads the text and not the box. **The day panel still wraps**, because it is full width and showing the whole title is the reason it exists.

**The picture is not lost either, it moves.** The hover preview already carried it, from the same link, in a place with room for a picture rather than a 32px square.

**A STALE embed.js NOW SAYS SO, AND THE SCRIPT URL STAYS UNVERSIONED.** Two releases of correct work were invisible until Mark hard refreshed, because the browser was running a cached copy of that file. The obvious remedy is a version on the script URL, and **that remedy is banned here by a defect**: a URL baked into a snippet somebody pasted once is PINNED by a version rather than busted by it, which is exactly what went wrong in 2.10.1 and left an old script loading an old stylesheet.

**So the version travels in the payload**, which is fetched fresh on every load and cannot be pinned. `embed.js` carries the version it was shipped as and names the mismatch in the console. That does not make a stale script fresh, and it is not claimed to: it turns the failure from INVISIBLE into NAMED, which is the thing that cost four releases, because a fix that is live on a page that is not looks exactly like a fix that does not work.

**A WARNING RATHER THAN A RELOAD.** A script that refetches itself when it dislikes a number can loop against a CDN serving two versions from two edges, on a page this plugin does not own.

**AND THE CONSTANT CANNOT DRIFT**, which is the only thing that makes it worth having. `.claude/embed-filters-test.php` fails the build when `EMBED_JS_VERSION` is not `SFAF_VERSION`, so it cannot be forgotten at release time and then report nonsense forever. Planted and caught.

**Eight faults planted across the release and every one caught by name**, including the two that shipped. **Three escaped a first draft and all three were the same trap**: asserting that a handler's code exists rather than that it is CALLED. That trap has now been met three times in one file and each assertion is written against the call site.

**Four committed guards asserted arrangements this release reverses** and were rewritten to assert the new ones more strongly rather than weakened: the day tile's thumbnail became the dot plus the rule that the dot is not the category's only carrier, and the two-line clamp became one line with an ellipsis plus the day panel still wrapping.

**Unchanged and confirmed working**: search filtering the grid, the dropdown filtering, the event-editor upload and its tagging, the upload panel layout, and closure editing.

= 3.88.0 =

**THE EMBED HAS ITS OWN SCRIPT AND ITS OWN ROUTE, AND THAT IS THE WHOLE STORY.** Search and the merged Organizers and Groups dropdown did nothing on the embedded calendar. Two releases of work went to the admin-ajax path, and 3.87.0's verification drove that path in a headless browser and found it correct. It was correct. Nothing on an embed calls it.

**THIS IS THE THIRD TIME.** The list card was rebuilt into a renderer only the shortcode used. The toggle rendered into a panel the stacked layout hid. Now this. **The renderers are shared and the two scripts are not**, and until this release nothing made them agree.

**WHAT THE ROUTE HONOURS, which was never the problem.** `normalize_params()` reads `s`, `category`, `active_category`, `organizer`, `active_organizer`, `active_groups`, `filters`, `venue`, `series`, `view`, `month`, `mode`, `page`, `count`, `layout`, `per_page`, `toggle`, `source_links`, `show_filters` and `heading`. It ignores nothing the filter bar can send, and `$resolved = $params` hands all of it to the same renderers the shortcode uses.

**FAULT ONE: THE EMBED ASKED FOR mode=items AND NOTHING ELSE.** That is the list. In calendar or combined view the list is the panel that is hidden or far below, so the month grid beside it never moved. It is exactly the bug the shortcode had before 3.86.0, living on the other path. **The month and the sidebar are redrawn from the search handler now**, carrying the term the same request already sent.

**FAULT TWO: THE MERGED DROPDOWN HAD NO HANDLER ON THE EMBED AT ALL.** `embed.js` still listened for `[data-uc-organizer]` and `[data-uc-group]`, the two controls 3.85.0 replaced with one. So the dropdown rendered, opened, and did nothing whatever was ticked. It is handled now, with the same narrowing rules: a group with no organizered events is always shown, a ticked group is never hidden, organizer is the controlling filter, selected items rise on open rather than under the cursor, and the redraw is debounced at 350ms for the reason the other script debounces.

**WHAT WAS MISSING FROM THE SERVER'S CACHE KEY: nothing.** `cache_identity()` has carried the search term, both active selections, the filter set, the venue, the series and the mode for releases, and searches are not cached server-side at all. Saying otherwise would have been easier and wrong.

**WHAT WAS MISSING WAS THE CLIENT'S.** `monthCacheKey()` in `embed.js` carried the category and nothing else, so searching and clearing, or ticking an organizer and unticking it, served the grid cached for the other state out of a JavaScript object. Every narrowing is in that key now, which is what the server's identity has always done.

**AND THE 304 WAS REAL, THOUGH IT WAS NOT THE CAUSE.** Every response carried `public, max-age=60`, including one built for one visitor's search. Per URL that is not wrong, but it makes a filtered calendar a publicly cacheable document for a minute: a shared proxy may keep it, and clearing a filter inside the minute is answered from the copy built while it was on. **A narrowed answer is now `private, no-cache, must-revalidate`**; the unnarrowed calendar, which is the same for everybody, is still cached.

**WHY THE LIST VIEW WAS UNAFFECTED.** It asks for `mode=items` and `mode=items` is exactly what it wants: the request that did nothing useful in calendar view is the whole answer in list view. The list was always being rebuilt correctly by both scripts, on both paths, which is why the same search box worked in one view and looked dead in the other.

**AN EMPTY MONTH SAYS WHICH KIND OF EMPTY IT IS**, on the embed as on this site, because both go through the same `render_month_grid()`: it names the search term back when a search emptied it, says the filters did when they did, and says nothing is scheduled only when that is true.

**THE EMBED CACHE NOW RETIRES ON A PLUGIN UPDATE, which closes a gap flagged twice.** Every other hook on it was a CONTENT event, so nothing told it that the code building the markup had changed, and for up to the TTL after an update a site served the payload rendered by the release before it. A release is installed and then immediately looked at, which is exactly that window. **Four faults in this project have looked arbitrary for want of this.** Both `upgrader_process_complete` and activation are hooked, because an update in place and a reinstall are different events.

**THE CALLABLE AUDIT CAUGHT ONE ON THE WAY OUT.** `upgrader_process_complete` passes two arguments and `flush_cache()` took none, while its own docblock claimed it accepted whatever a hook sent. PHP discards extra arguments silently, so nothing would have broken; what would have shipped is a promise the code did not keep.

**`.claude/embed-filters-test.php` ASSERTS THE PAIRS.** For each behaviour the filter bar has, BOTH scripts must have it: the merged control handled and actually called, the month redrawn on search from the search handler rather than merely owning the function, every narrowing in both month cache keys, every parameter still read by the route and still in its cache identity. Five faults planted, including the one that shipped, and all five caught. **Two of the five escaped the first draft** and both were the same trap: matching a string that also appears in a comment, and matching a handler's code while nothing calls it.

**Unchanged and confirmed working**: the event-editor upload and its tagging, the upload panel layout, closure editing, and the filter panel's position, columns, open state across a redraw and no-script path.

= 3.87.0 =

**AN IMAGE UPLOADED FROM THE EVENT EDITOR WAS NEVER LOST, AND THE PICKER COULD NOT SEE IT.** The proposed explanation was that the upload went through wp.media rather than the plugin's own form and landed in a date directory. It did not. The folder flag rides the uploader's multipart params, so `upload_dir` fires, and the file lands in `uploads/calendar/` with a stored path that satisfies the folder rule. It has been on the Images screen the whole time.

**IT VANISHED FROM THE PICKER THAT UPLOADED IT.** wp.media refreshes its library the instant an upload finishes, using that frame's own arguments, and for an event in a series those include `uc_series`. `restrict_query()` then narrows on that term, and a picture uploaded two seconds ago carries no term at all. The picker could not return the thing it had just uploaded. 3.74.0 and 3.80.0 were both working exactly as designed; what was missing was that a picture uploaded FOR an event is a picture in that event's series.

**THE SERIES RIDES THE UPLOAD NOW AND THE PICTURE IS TAGGED AS IT ARRIVES**, on the same route the folder flag takes, through `SFAF_Media::add_tag()` so the rule about what may be tagged is not implemented twice. **Tagging rather than widening the picker**: widening would answer a different question from the one asked and would undo the hiding 3.80.0 added on purpose. The series is a fact here rather than a guess, because somebody was editing an event in it when they pressed Upload.

**BOTH KEYS OR NOTHING**, and the series is cleared from the uploader's global defaults when there is none, so a later upload from any picker on the page cannot inherit it.

**SEARCH IN CALENDAR VIEW: THE CODE IS CORRECT AND NOTHING WAS CHANGED.** It was reported as still doing nothing after 3.86.0 changed four places for it. The instruction was not to fix a fifth place blind, so this release establishes rather than guesses, and the answer is that there is no fifth place.

**Driven in Chrome against a real rendered block with the real script**, typing fires two requests and the month one carries the term, in calendar view and in combined view alike:

```
[0] action=uc_load_month   month=2026-09   s="harm"
[1] action=uc_load_events                  s="harm"
```

**And the server half is executed, not read.** `month_grid_data()` puts `sfaf_search` on the WP_Query it builds, leaves it absent when there is no term, and `build_query_args()` puts the same var on the list query from the same term. **All four of 3.86.0's changes are present in the shipped 3.86.0 zip**, checked by extracting it.

**So the chain holds from the keystroke to the query var.** What could not be exercised here is the WHERE clause `SFAF_Search::clauses()` appends, because that is SQL and needs the database, and that clause is shared with list view, which works. The remaining candidates are the install itself and the environment, not the code. `.claude/search-in-calendar-test.php` is committed so the next report starts from proof rather than from reading.

**THE FILTER PANEL IS LIGHTER AND LESS BOXY.** Reported as "the lettering is thick and it looks square and boxy", and three things were doing that, none of them colour or typeface, which DESIGN.md and the brand guide own and which are untouched. The headings were 700 AND uppercase AND tracked at 0.08em, which is three emphasis signals on a label nobody reads twice, so the weight comes down to 600. The rows were 7px apart, which is a wall at thirty-four names, and are 9px with a rounded inset hover rather than a full-bleed band. The corners were 10px on a 700px panel, which reads as square, and are 16px against a lighter border with the separation carried by a softer, wider shadow.

**THE APPLY BUTTON IS GONE, AND IT IS GONE RATHER THAN HIDDEN.** 3.85.0 hid it with a CSS rule driven by an attribute the script stamped, which is a hidden button: still in the markup, still there if that attribute ever failed to be set. It is inside `<noscript>` now, so a browser with scripting on never parses it. **It is still the whole no-script path**, because without script ticking a box does nothing until something submits.

**WHAT REMOVING IT COST, AND IT IS NOT THE REBUILD.** `reloadBlock()` replaces the whole block, and the server renders the control CLOSED because a `<details>` is closed unless it says otherwise. That was invisible while Apply did the reloading, since the panel was on its way out anyway. With every tick applying immediately, the panel would have shut on the first box and ticking two would have been impossible. **The open panel now survives the redraw**, read off the outgoing block and put back on the incoming one.

**And yes, it needed debouncing.** The panel answers at once, because narrowing and relabelling are local and cost nothing; only the calendar redraw waits, at **350ms**. That is longer than the search box's 250ms on purpose: a search is one field typed continuously, and this is several separate decisions with longer pauses between them. Without the delay, live filtering on a control with thirty-four options is worse than the button was.

**THE UPLOAD PANEL IS THE PICTURE ON THE LEFT AND WHAT IS TYPED ABOUT IT ON THE RIGHT.** The 2x2 grid 3.86.0 put there did not fix the alignment and could not: a file input, a select and two text boxes have different intrinsic heights, so whatever grid they are arranged in, their labels drift. **Splitting by KIND is the fix.** The file control and its preview are one column that answers for itself; the three things somebody types are a single stack where every label is the first line of an identical block. Nothing has to line up across the gap, so nothing can fail to.

**The preview well holds its size empty or full**, at the 16:9 a card crops to, so choosing a file changes what is in the box and never the layout around it. It is server-rendered rather than built by script, so the panel is the same shape with no script at all.

**Unchanged and confirmed working**: editing a closure, the panel's position under its trigger, that it does not push the calendar down, one third and two thirds, groups in two sub-columns, the widths holding still as groups hide, and the folder rule reading the physical directory.

= 3.86.0 =

**THE FILTER POPOVER OPENED IN THE TOP LEFT OF THE VIEWPORT, AND IT WAS NOT THE ANCHOR POSITIONING API.** Nothing in this plugin has ever used `anchor-name`, `position-anchor` or `anchor()`. The panel was `position: fixed` with `top: 0; left: 0` as pre-measurement values, moved under its trigger by a script running from the popover's `toggle` event. Wherever that event did not fire, nothing moved it and it stayed at 0,0.

**AND THE RULE THAT HID IT WAS WORSE THAN THE ONE THAT PLACED IT.** The panel was hidden by `:not(:popover-open)`. A browser that does not know that selector discards the whole rule, so the panel does not merely open in the wrong corner: it stands open permanently. Two failure modes, one dependency.

**THE FIX REMOVES THE DEPENDENCY RATHER THAN ADDING A FALLBACK BESIDE IT.** The control is a `<details>` and its trigger is a `<summary>`, which open and close by themselves in every browser that has ever shipped. The panel is placed by ordinary absolute positioning inside a relative wrapper, which has put elements under other elements since CSS 2. No popover, no anchor positioning, no script, no measurement.

**Measured in Chrome with no JavaScript on the page at all**: closed it computes `display: none` at 0x0; opened it is 700x184 at 16,62 under a trigger ending at y=56; the calendar below sat at y=88 before and y=88 after, so it still floats rather than pushing.

**A CLOSED `<details>` DOES NOT HIDE AN ABSOLUTELY POSITIONED CHILD**, which is the one thing this approach needed saying out loud. Measured, the panel rendered 700x184 with the control shut, because out-of-flow content escapes the content skipping a closed `<details>` does. `.uc-who:not([open]) .uc-who-panel { display: none; }` is the whole remedy and it is a plain attribute selector.

**THE SCRIPT NOW ADDS ONLY WHAT A MENU NEEDS AND A `<details>` LACKS**: light dismiss on an outside click, Escape to close, and the reordering. All of it delegated, so a block redrawn by `reloadBlock()` keeps it; the previous version bound at ready and lost its behaviour on every redraw. **The stamp that hides the no-script Apply button moved to the document element** for the same reason.

**THE HOVER PREVIEW DOES NOT HAVE THIS PROBLEM, and it is worth saying why rather than just that.** It probes `typeof probe.showPopover !== 'function'` and returns before building anything, so a browser without the popover API gets no preview and no stray element. Its visible state is a class it controls rather than `:popover-open`, and it positions itself immediately after `showPopover()` rather than waiting for an event. It is safe in current Safari and Firefox, which have supported popover since Safari 17 and Firefox 125, and it degrades to nothing rather than to a panel in the corner in anything older.

**TWO COLUMNS, ONE THIRD AND TWO THIRDS.** Nine organizers against twenty-five groups, so equal columns wasted the left side and doubled the height of the right. Measured at 900px: organizers 227px, groups 453px, exactly 1 : 2, side by side, with the groups running in two sub-columns.

**THE WIDTHS ARE HELD BY THE GRID, NOT BY THEIR CONTENTS**, which is the part that needed deciding rather than discovering. Narrowing can take the right column from twenty-five names to two. `grid-template-columns: 1fr 2fr` does not care what is left inside it: measured, hiding all but one group moved neither column by a pixel.

**THE STACK BREAKPOINT MEASURES THE CONTAINER, NOT THE WINDOW**, because this block is embedded on another site inside a column it does not control and a media query about the window is a lie in there. A media query sits beside it at the same number as the floor for any host that gives us no container.

**CLOSURES COULD NOT BE EDITED, AND THE MODEL COULD ALWAYS DO IT.** `SFAF_Closures::save()` has taken an existing id since it was written: pass one already in the option and it updates that row. What was missing was any way to SEND one. The form hardcoded `closure_id` to the empty string, so every save took the create branch, and the table offered Remove and nothing else. **That is the fifth control in this project found built and unreachable, so it was checked before being built again rather than after.**

**Editing covers the name, both dates and the note**, through the same form, because two forms would be two places for the next field to be forgotten. A multi-day closure stays one entry: asserted, because an edit that quietly added a row would leave the grid marking both the old span and the new one.

**SEARCH DID NOTHING IN CALENDAR VIEW, AND IT WAS THREE THINGS.** `monthParams()` did not send the term, `ajax_load_month()` did not read it, and `month_grid_data()` did not apply it. Any one of the three alone was enough. **The month cache key did not include it either**, so a search would have been served the entry cached for the unsearched month, which looks exactly like search not working and is the harder version to find.

**IT JOINED A MECHANISM THAT ALREADY EXISTED.** The category, organizer, venue and series filters have always narrowed the month query; search was the one filter on the bar that did not. Everything still runs through the query and nothing is hidden after being downloaded.

**THE SEARCH BOX KEEPS ITS FOCUS.** The organizer filter narrows the grid by going through `reloadBlock()`, which replaces the whole block including the search input, and doing that on every keystroke would take the caret out from under somebody mid-word. Search calls `loadMonth()` instead, which replaces the grid and sidebar panels only.

**AN EMPTY MONTH SAYS WHICH KIND OF EMPTY IT IS.** "Nothing scheduled in September" is true of a bare month and false of a month full of events that do not match what somebody typed, and searching in calendar view made the second the common way to get there. It names the term back, because the box may be off screen by the time the sentence is read.

**THE UPLOAD PANEL TAKES A NAME AND ALT TEXT**, both optional. The name goes through `SFAF_Media::rename()` rather than being written here, because that method sets the deliberate marker as well as the title: without it, "Strut clinic" uploaded as strut-clinic.jpg is read as a file name and thrown away by the derived-title rule.

**AND THE PANEL IS A GRID RATHER THAN TWO FIELDS WITH TWO APPENDED.** It was a flex row of two, and only one had a hint under it, so the columns were different heights and the labels lined up only when the content happened to make them. Every label is now on a grid row boundary, so they line up because the grid says so.

**THE TICK BOXES ARE TOP ALIGNED**, in one shared rule. Categories and organizers are the same control wearing the same class, so `align-items: center` put the box level with the middle of any name that wrapped to two lines. One fix, both screens.

**THE DASHBOARD COUNTS PUBLISHED EVENTS WITH NO ORGANIZER**, and links to them. Organizers only: imported events also lack images, descriptions and categories, and the pending queue already flags those per event. **A count that goes to zero as somebody works through it is worth reading; a permanently large one teaches people to look past the row, and then the one that mattered is looked past with it.** The list it opens says it is narrowed and offers the way out.

**SAVES STILL DO NOT REFUSE ON THOSE EVENTS.** The count is how the gap gets noticed and nothing else. An event that had no organizer and still has none saves normally and stays published, which is `SFAF_Organizers::requirement()` unchanged from 3.85.0.

**Nothing was deleted.** The hundred published events with no organizer stay published and editable, several organizers per event is untouched, the calendar folder rule is unchanged pending the investigation below, and the embed payload cache still keys on the version.

**THE TWO CALENDAR FOLDERS ARE INVESTIGATED AND NOTHING WAS BUILT.** The plugin's rule and its Images screen both read the PHYSICAL path, `_wp_attached_file` anchored at `calendar/`. The folder in the WordPress media library belongs to **WP Media Folder**, which is a taxonomy assignment and can be set independently of where a file actually sits. That is the whole disagreement, and moving files to reconcile it breaks stored URLs, so it needs a decision rather than an implementation.

= 3.85.0 =

**NO COMMUNITY SUBMISSION HAS EVER CARRIED AN ORGANIZER, AND THE WRITE WAS NEVER THE PROBLEM.** The reported fault was that the community form resolved the series' organizer and then threw it away. That is not what the code did: it called `wp_set_object_terms()` with it, and has since 3.76.0. The fault is the ORDER, which is why reading the write in isolation finds nothing wrong with it.

**THE EVENT WAS ITS OWN SOURCE.** `create_event()` adds the new event to the series, and then asks `SFAF_Series::organizers_for()` for the series' organizers. That method answers from the series' MOST RECENT EVENT and counts `pending` among the statuses it looks at. A submission is pending and is dated in the future, so by the time the question was asked the newest event in the series was the submission itself, holding no organizer yet. It read its own empty set and wrote nothing.

**Proved by running it rather than by reading it**, with the series' events and the insert modelled in order: asked before the join the answer is organizer 7, asked after the join the answer is the new event and an empty list. The control case, the same submission dated BEFORE the series' last event, inherits correctly, which is why this was never a total failure and never showed up as one.

**The resolve moved to the top of `create_event()`**, before anything is inserted or joined, so the question describes the series as it was. `submissions-test.php` asserts the ORDER rather than the write, reading a comment-stripped tokenization because the docblocks around that code name that method several times and a raw offset comparison would compare prose.

**EVERY SURFACE THAT ASSUMED ONE ORGANIZER, AND WHAT IT DOES NOW.** The decision was already undone in 3.40.0 and finished in 3.84.0, so most of this was reporting rather than building: `for_event()` returns them all name-ordered, `phrase()` joins them "A, B and C" with no serial comma, the event page prints that phrase, the caladmin editor and the staff form are tick boxes, the filter is a `tax_query` that has always matched one of several, and JSON-LD emits an array. Nothing there was rebuilt.

**THE SILENT-DROP GUARD HAD TWO HOLES AND BOTH WERE FOUND BY PLANTING.** The 3.84.0 check required a NEWLINE between the `foreach` and the `wp_set_object_terms()` call, so the whole fault written on one line walked past it. Widening that then missed `array( $orgs[0] )`, because the pattern demanded an `(int)` cast and allowed no subscript. Five shapes are now planted and caught: a same-line loop, a multi-line loop, and a single-element array with a cast, without one, and on a property.

**AN ORGANIZER IS REQUIRED, ON THE SERVER.** It has been in `completeness_fields()` since 3.40.0, but that is a `confirm()` in the browser: with script off, or the dialog dismissed, nothing stopped a published event having none.

**THE RULE IS ABOUT DIRECTION, NOT STATE, and that is what protects the hundred.** `SFAF_Organizers::requirement()` is the one place that decides, so the two forms cannot answer differently. An event that HAS organizers cannot be saved with none: that is somebody unticking every box, it is related to what they came to do, and the save refuses and says so. A new or unpublished event being PUBLISHED with none is SAVED and NOT PUBLISHED, because refusing a new event outright would discard everything typed, there being no saved event to return to.

**WHAT HAPPENS WHEN SOMEBODY EDITS ONE OF THE HUNDRED: nothing.** An event that had no organizer and still has none is left alone at whatever status it already held, published included. A flat refusal would stop somebody fixing a typo because of a field they did not come to change and cannot fill in without going and finding out who ran it, which is how a rule people cannot satisfy gets worked around. The staff request form does refuse outright, and the difference is real rather than an inconsistency: that form only ever CREATES, so there is nothing grandfathered to accommodate, and it redisplays every answer with the error so refusing costs nobody their typing.

**ORGANIZERS AND GROUPS ARE ONE CONTROL, AND IT FLOATS.** Two dropdowns asked what is one question to a visitor, and Programa Latino exists as both an organizer and a series, so the two lists could show the same name twice with nothing to tell them apart. The headings are what tell them apart, which is why this is grouped rather than one list of thirty-four names.

**THE PANEL IS A POPOVER, SO THE BROWSER PUTS IT IN THE TOP LAYER.** The old groups disclosure was an ordinary block and opening it pushed the calendar down. A stacking value would not have been enough: an ancestor with a transform or a filter becomes the containing block and traps it, which is what happened to the hover preview in 3.75.0. **Measured in Chrome rather than reasoned about**: the calendar sat at y=88 before the panel opened and y=88 after, with the panel in the top layer at 16,62 under a trigger ending at y=56.

**`right` AND `bottom` ARE EXPLICITLY `auto`.** The UA stylesheet gives every `[popover]` `inset: 0` and `margin: auto`, so setting only `top` and `left` leaves the other two edges pinned and the auto margins centre the panel in the viewport instead of placing it. Same trap as the hover preview, answered before it had to be met again.

**ORGANIZERS ARE MULTI-SELECT**, which an event with three organizers makes necessary rather than nice, and **selecting them HIDES the non-matching groups** rather than greying them: every option is visible when the panel opens, so somebody already knows they are there, and a greyed row still costs a line of a list thirty-four long.

**A GROUP APPEARS UNDER THE ORGANIZERS OF THE EVENTS ACTUALLY IN IT.** Nothing stores a group's organizer, because a series carries a description, an image and a FAQ set and no organizer; an organizer is a property of the EVENTS. Deriving it from the events also handles collaboration, which the simpler reading could not: Strut Community Events holds events from two organizers and appears under both.

**A GROUP WITH NO ORGANIZERED EVENTS IS ALWAYS SHOWN.** An empty list is missing information rather than a statement that the group is not that organizer's, and on the current data it is roughly a third of them while Mark is still setting organizers by hand. Hiding those the moment somebody picks an organizer would empty most of the list and read as a broken control. As the events gain organizers those groups start narrowing on their own, with nothing to change. **A ticked group is never hidden either**, or a filter would be running with nothing on screen to see it by or clear it with.

**Organizer is the controlling filter and the narrowing is one-way.** Selecting groups does not narrow the organizers, because then each would be hiding the other's options and neither list could be trusted to be complete.

**SELECTED ITEMS SIT AT THE TOP, AND THE LIST REORDERS ON OPEN RATHER THAN ON CLICK.** A list that moves the row just ticked out from under the cursor makes the next click land on something else. The server emits the order for the state it is rendering and the script reorders only when the panel is next opened.

**The closed trigger says what is selected**, names while they fit and a count after that, from the server AND the script in the same shape so the wording does not change when the script takes over. **The category pills are untouched**: they are colour-coded and readable at a glance and folding them in would lose that.

**IT WORKS WITH SCRIPT OFF, AND THAT IS NEW RATHER THAN PRESERVED.** The filter bar has never had a no-script path: there was no form, no submit and no noscript anywhere in the file, and the search box, the organizer select and the group checkboxes were all read by JavaScript. Saying the calendar works without script was true of the LISTS and was never true of the FILTERS. There is a real GET form now, `popovertarget` opens the panel declaratively with no script at all, and Apply is a real submit that reloads the page with the choices in the query string. The script intercepts, exactly as `initLoadMore()` intercepts the pagination links, and hides Apply only once something is actually listening.

**`slug_list()` accepts an array**, which it did not. Checkboxes submit one, and casting an array to a string gives "Array" plus a notice, which `sanitize_title()` turns into the slug `array`, which matches no term, which empties the calendar silently.

**THIS MAKES GROUPS A FIRST-LEVEL FILTER, and that undoes a decision worth naming.** The groups row rendered only once a category had been chosen, so that nobody was ever looking at two taxonomies at once. Merging the controls necessarily ends that, because the organizer half has always been first-level. The headings carry the job the staging used to do. `render_group_row()` now has no caller and is left in place for one release rather than deleted, because the merge is the part of this change most likely to be reversed.

**A CLOSURE CAN CARRY A FREE TEXT NOTE.** Shipped in 3.84.0 and unchanged here: optional, in full on the list card, shortened on a word boundary with the cut marked on the month grid, with the whole note on the `title` and the `aria-label` speaking it in full. Not naming venues.

**Nothing was deleted.** The hundred published events with no organizer stay published and untouched, the 287 imported events keep their organizers, and no event's terms were rewritten by this release.

= 3.84.0 =

**AN EVENT COULD ALREADY HAVE SEVERAL ORGANIZERS. TWO FORMS COULD NOT SAY SO.** The brief for this release asked for co-hosting to be built and for the cost of undoing the single-organizer decision to be reported first. That decision was already undone, in 3.40.0, and reporting it was most of the work: the taxonomy is multi, `SFAF_Organizers::for_event()` returns every organizer name-ordered, `phrase()` joins them as "A, B and C" with no serial comma, the event page prints that phrase, the caladmin editor is tick boxes with a present marker, and the filter is a `tax_query` that has always matched one of several. None of that needed building and none of it was touched.

**WHAT WAS ACTUALLY MISSING WAS THE TWO PUBLIC FORMS**, and the reason is a sequence rather than an oversight. The staff request form's organizer field was added in 3.76.0, after 3.40.0 had settled the question everywhere else, and it was added as a single select. So the surface most likely to receive a co-hosted event was the one surface that could not describe one.

**NEITHER FORM COULD EVER HAVE DROPPED A STORED ORGANIZER**, which is worth saying plainly rather than leaving as an implication. Both CREATE a pending event and neither edits one, so there was never an existing set for `wp_set_object_terms()` to replace. What was lost was what the requester said, before it was ever stored, which is a smaller fault than the 3.40.0 one and a different shape.

**The staff form is tick boxes now.** The validator returns a list and drops duplicates rather than refusing over them; approval writes the whole set in ONE call. There is no "Not sure" option any more, because with tick boxes it is not a choice to offer: nothing ticked IS not sure, and a box saying so could be ticked alongside a real answer. It has no present marker, and that is deliberate and not a copy of caladmin: a marker separates "the form did not ask" from "every box was unticked", which matters only where a save REPLACES an existing set.

**The community form inherits every organizer the series lends**, not the first. It reads them off the series' most recent event, and that event can be co-hosted, so an inherited single name put a co-hosted submission under one team's filter and not the other's. That is the whole purpose of the filter.

**And the request form's prefill applies what it previews.** It previewed the joined phrase, "A, B and C", and then applied A on its own.

**No tie-break was needed, and that is the answer to whether organizers want the category rule.** A category's first alphabetically supplies the card colour and the placeholder tile. An organizer carries no colour and no icon, so nothing downstream has a decision to make. They are ordered by name through one method, which is 3.40.0's rule and is unchanged.

**The 287 imported events are untouched, structurally.** `organizer` is a manager field on both adapters, so no fetch has ever written it and none can.

**A CLOSURE CAN CARRY A FREE TEXT NOTE.** SFAF can be closed overall while one site stays open, and "Closed for Labor Day" on its own is then wrong for whoever is standing outside the 6th Street Center. The note is optional, and a closure without one renders exactly as it did.

**It renders on both surfaces, from one stored string, so they cannot come to disagree.** The list card shows it in full on its own line. The month grid shows a shortened form, because a day cell is the tightest space on the calendar and Mark has days carrying nine events.

**A LONG NOTE IS CUT ON A WORD BOUNDARY AND THE CUT IS MARKED.** `note_short()` trims at 32 characters, backs up to the last space so it never breaks mid-word, and appends an ellipsis. The cell's `title` carries the whole note and the cell's `aria-label` already spoke it in full, so nothing is available only to a mouse and the spoken label is never the truncated one. CSS clamps the cell to two lines as a floor under that, and the note is dropped with the name line below 560px where the day panel and the list card still carry it.

**`all()` REBUILDS EVERY ROW FROM A FIXED SET OF KEYS**, which is how the note behaved for its first draft: written, stored, and then silently dropped by every reader, because `get()`, `covering()` and `spans()` all come through it. It is re-cleaned on read like the dates already were.

**Not naming venues.** A closure saying which sites it applies to was considered and set aside: a venue picker turns a sentence somebody wants to write into a data model with its own rules about a site that is half open.

**Everything else about closures is unchanged**: one option rather than a post type, no page, no permalink, nothing to register for, invisible to every query over events, one entry spanning dates, and the stripes and the CLOSED label exactly as they were.

**TWO PLANTED FAULTS WERE NOT CAUGHT, AND FIXING THE CHECKERS IS THE MORE USEFUL HALF.** The first draft of the closure-note checks read the raw file, so "the grid cell gets note_short()" written in a DOCBLOCK satisfied the note_short check, and renaming the class to `uc-closure-noteX` still matched `/uc-closure-note/` as a substring. Both are now matched against a comment-stripped tokenization with quoted class names, and both plants are caught. That is PROJECT.md's rule about auditing with a tokenizer rather than grep, met the hard way a second time.

**Seven faults planted in total and every one now caught by name**: the community form taking the first, the staff form returning to a select, its validator reducing to one id, the prefill applying `organizers[0]`, `all()` dropping the note, the grid rendering the full note, and the card losing it.

**NOT DONE, AND REPORTED RATHER THAN BUILT.** The brief lists "the embed payload cache keying on the version, which was fixed in 3.84.0" under what must not change. There is no such fix. `cache_key()` keys on the parameters, the calendar day and a generation counter, `SFAF_VERSION` is not in it, and nothing flushes on `upgrader_process_complete`. The 10-minute TTL bounds the staleness so it self-heals rather than persisting, which is why it has never been visible, but the gap is real and is left alone here because building something a brief describes as already existing is how a report stops being trustworthy.

= 3.83.0 =

**A PICTURE OUTSIDE THE CALENDAR FOLDER IS NOT USED, AND THE RULE IS ENFORCED WHERE A PICTURE IS RESOLVED.** Not by clearing 270 stored references, which is the expensive way to enforce a rule that belongs in one place and would be undone by the next import. `sfaf_event_own_image_url()` is the rule and every surface reads it: the month tile, the hover preview, the event page, the sidebar, the list card, the embed payload and the sharing tags. Nothing is cleared, no file is touched, and whatever a future import writes is not used either.

**The event editor asks the same question**, which is what makes read-time enforcement safe. Without that the editor would say an event has a picture of its own while the calendar drew the series one, which is the two-answers-to-one-question fault this project keeps meeting. The completeness prompt, the per-event override flag and the picker's current selection all go through it.

**`sfaf_event_thumbnail()` no longer short-circuits on the featured image**, which was the one path the rule could not have reached: it returned the picture before the chain was consulted at all.

**An address on another site is left alone.** The rule is about attachments and about local paths naming one. A remote source image is the rung a fetch maintains and taking it away would blank every imported campaign, which is a different decision nobody has made.

**`.claude/image-folder-rule-test.php` proves it both ways**: the chain lands on the right rung for seven combinations, and a sweep refuses any other way of resolving a picture. Putting a direct `has_post_thumbnail()` back into a renderer fails the build, which was checked by doing it.

**THE LIST VIEW COLLAPSED TO ONE LETTER PER LINE, AND IT WAS TWO FAULTS.**

**The layout, measured at 700px:**

```
uc-panel-sidebar   shown   698x964  left  21
uc-panel-list      shown     0x964  left 719   flex 0 1 auto  min-width auto
a card              26x268
```

`.uc-panel-list` had no sizing inside the combined wrapper, because until 3.82.0 it was never in it. Beside a sidebar claiming `1 1 288px` it fell to zero width and its cards overflowed at their 26px min-content width, with Load More floating clear of both. It takes a full row now, and **it does not depend on the script having tidied up first**: a layout that is only correct once a script has run is wrong on any page where the script is old, cached or blocked, and this one is served to another site.

**And the query. The combined mode asked for the total and told the renderer to draw no cards**, with `'none'`, which was right while it had no list panel. 3.82.0 gave it one and nothing here changed to match, so the list panel got an empty string and rendered its empty state: "No upcoming events found." beside a sidebar listing the same events perfectly. **The query had always run and always found them.** There is no `'none'` path left.

**REMOVE, THIRD ATTEMPT, AND THE ANSWER IS THAT THE PRESS WAS NEVER THE PROBLEM.** Driven in a browser: the button belongs to the remove form and not the card's Save form, the confirmation opens, accepting it submits, and the form carries `uc_action=media_remove` with the right id and nonce. The handler, the marker, the exclusion clause and the refusal all hold against a stubbed store.

**What could never be ruled out from here is the refusal**, and a refusal that arrives as a flash band over a reloaded page is indistinguishable from nothing having happened. **So the screen says it before the press.** A picture an event or a series is relying on shows what is relying on it and has no Remove button at all, which is this project's own rule: a control that cannot do anything should not be on screen. Two queries for the whole page rather than two per card. The refusal in the handler stays, because a POST is a request anybody can construct.

**Two committed guards asserted decisions this release reverses** and both were rewritten to assert the new arrangement rather than weakened: display may now filter on the folder, in exactly one place, and the list panel must be sized inside the combined wrapper.

= 3.82.0 =

**THE LIST AND CALENDAR TOGGLE WAS NOT MISSING IN THE STACKED LAYOUT. IT WAS NOT IN THE MARKUP AT ALL.** The combined mode has forced it off since 3.45.0, at every width, on reasoning that was sound and is now only half true: "both views are on screen, so it has nothing to switch". That holds SIDE BY SIDE. Stacked, what is on screen is a month grid and, a long way below it, a short list of upcoming dates, which is not the list view. A day carrying nine events is exactly when somebody needs the list, and there was no way to reach it.

**PHP cannot tell the two shapes apart**, because the panels stack on flex-wrap at a width decided in the browser. So the decision was being made for both shapes at once, and it was made for the shape that needs it least.

**The calendar button goes home rather than to a fixed view.** In a combined block it carries `data-view="combined"`, so pressing it returns the layout the block was configured for. Sending it to "calendar" would collapse the block to a bare grid nobody chose and leave no way back, which is worse than the missing toggle. The list panel is built and hidden, pagination comes back with it because there are 287 events, and the controls sit inside the list panel so they can never appear under a grid.

**THE SIDEBAR CARD, MEASURED THIS TIME RATHER THAN REASONED ABOUT.** At 700px stacked:

```
panel content box    left 39   right 701
uc-sidebar-heading   left 21   right 719   width 698   ESCAPES
uc-sidebar-list      left 39   right 701   width 662
uc-month-tabs        left 39   right 701   width 662
uc-sidebar-all       left 39   right 701   width 662
```

One child reaching the card's edge and three sitting 18px inside it. **That is 3.80.0's doing**, and the question asked was the right one: it set the band's escape to the panel's padding so the band would meet the card's edge, which looked correct side by side, where the band's right edge IS the card's edge and its left is the divider. Stacked, the band spans the whole card while everything under it is inset, across 700px rather than 355px.

**The escape is back to zero and the band is a heading inside the column**, aligned with the list, the month tabs and the link. Every child now measures identically in all three states. What the escape was added for is answered another way: the band read as a panel floating in a card because the column was 380px wide in a 700px card and stopped 292px above the bottom, and 3.81.0 fixed both.

**REMOVE, AND WHY THE IMAGE SURVIVED IT.** Nothing in the code is wrong, and that was established rather than assumed: the marker is written, `row()` reports it, `pictures()` builds the right exclusion clause, the handler is placed correctly and gated correctly, the button belongs to the remove form rather than the card's Save form, and the confirmation opens and replays the press. Two harnesses and a new test cover all of it.

**What is left is the refusal**, which is the one branch that cannot be exercised without the site: a picture is refused while an event's own featured image or a series' picture is that file, and the refusal is a flash band rather than anything on the card. **The investigation below makes that likely rather than theoretical**: the 2026-09-03 import set a featured image on every event it had one for.

**And the Add an image panel's two columns line up.** `align-items: flex-end` was reaching for two controls sharing a baseline and cannot get there, because only the File column has a hint under it: bottom-aligned, the hint pushed that whole column up and the two labels ended up on different lines.

= 3.81.0 =

**portal.js HAS BEEN THROWING ON EVERY PAGE SINCE 3.77.0, AND IT TOOK THREE FEATURES DOWN WITH IT.** That release closed `initFaqSetPeek()` AFTER `initRequestPrefill()` instead of before it, so the whole of the second function lived inside the first. The braces still balanced, so `node --check` passed. The name was still declared somewhere in the same top-level scope as far as `.claude/js-scope-test.js` could tell, so that passed too, through a hole it names in its own header.

**What it did.** `run('requestPrefill', initRequestPrefill)` evaluates the name BEFORE calling `run()`, so the ReferenceError is thrown in the caller and not inside `run()`'s try/catch. It killed the rest of the startup list: **requestPrefill, calendarTick and tickPickers**. Every tick picker on every screen, from 3.72.0's schedule publish to 3.79.0's bulk publish, has been dead. So has "Fill this in from the last one", since the release that added it.

**This is why "Tag 0 images" did not count.** The ticks were fine: correctly owned by the form, and the form posted the right ids, which is why tagging worked for whatever was ticked. What was missing was the script that keeps the number honest, reveals select-all and disables the button at zero. **Established by loading the real markup and the real script into a real browser** and reading the button, rather than by reading the source: `.claude/media-ticks-live.php`. Reading the source is what let the same class of fault sit for six releases in 3.79.0.

**`.claude/js-nesting.js` closes the hole.** A real scanner, not a regex: the first attempt reported nesting depths of fifteen because line comments, block comments, strings, template literals and **regex literals** all carry braces that are not code. It self-tests against all five, then asserts that every initialiser the startup list names is declared at depth 1. Run against 3.80.0 it reports the fault; against this release it passes.

**THE SIDEBAR: THE DIAGNOSIS WAS RIGHT, THE FIX LANDED, AND IT WAS AIMED AT THE WRONG END.** Measured in a browser this time, at three widths, in `.claude/sidebar-enclosure.php`. Nothing overflows and "See all events" was always inside the card, which is what 3.80.0 said. The band does escape to the card's edge and does take its inner radius, which is what 3.80.0 built. What the measurement then showed is that the link sat **292px above the card's painted bottom**, with its own hairline as the last rule in the column. A rule with 292px of nothing under it is not read as a divider; it is read as the bottom of a box, and the link below it as something that has fallen out.

**So the column fills its panel and the link sits at its foot**, 21px from the card's edge instead of 292px. The air goes above it, where an empty list belongs.

**AND ON sfaf.org THE COMBINED VIEW STACKS, WHICH IS PROBABLY WHAT WAS BEING LOOKED AT.** The block renders at about 770px there, under the 864px the two panels need side by side, so the sidebar sits under the grid. `max-width: 380px` on the panel did not stop applying when it stacked, so the column stayed 380px wide in a 770px card, left aligned, with about 390px of blank white down its right: a tinted band with square corners floating in a much wider rounded card. Both caps are lifted when it stacks.

**Two of these rules ask the BLOCK and not the window.** 3.80.0 wrote the corner rule as `@media (min-width: 864px)`, and the panels do not stack on the viewport: they stack on flex-wrap, at the width the container can no longer fit 576 plus 288. A block in a 700px column inside a 1400px window is stacked while that media query is true.

**TAGGING A PICTURE TO A SERIES AND GIVING A SERIES A PICTURE WERE TWO DIFFERENT THINGS, AND ONLY ONE WAS READ.** `SFAF_Series::image_url()` read term meta; the Images screen writes a term relationship on the attachment. The two never met, so a picture tagged and named against El Grupo de Apoyo Latino produced no banner anywhere.

**It showed up on the imported series and not the others, which is what made it look like a fault in the new banner.** Cycle to Zero and Strut Community Events predate the import and were built by hand, so somebody set their picture on the series screen. The thirty the import created came from `SFAF_Series::create( $name )`, which takes a name and nothing else.

**A tag is a fallback now, not a second setting.** A picture set on the series screen still wins, always. The fallback answers only the case that used to answer nothing, and it is **the earliest picture tagged**, so adding one to the folder never silently changes a programme's picture, its banner and every event fallback across it.

**AN IMAGE CAN BE REMOVED, AND REMOVE IS NOT DELETE.** It takes the picture out of the set this calendar offers: gone from the grid, from every picker, and not eligible to become a series' picture. **The file is not deleted and neither is the attachment.** The thing somebody wants nine times out of ten is "stop offering me this", and a recoverable action that covers the common case beats an irreversible one. A **Removed** view in the filter puts it back.

**It is refused while anything is using it, and the refusal names what.** An event whose own picture it is, or a series it has been given to. A series that merely TAGS it is not using it: a tag is filing, and removing the picture moves that series to the next one tagged or to nothing, which is a change in what is offered rather than a dangling reference.

**ALT TEXT, AND IT IS NOT A SECOND NAME.** The name is how somebody finds a picture in a chooser and is written for the person picking it. Alt text stands in for the picture and is written for the person who cannot see it. **It is never filled in from the series**, because a programme's name is exactly what it must not say: wrong alt text is worse than none, since a screen reader announces it as though it described what is there. What each field is for is said **once, above the grid**, with an example of a good one and a bad one, rather than under fifty cards.

**No caption.** Nothing in this plugin renders one, so it would be a third box on a card that 3.78.0 cut from two forms to one, collecting text nobody would ever read. Name covers being found in the chooser and alt text covers being read out and being indexed, which is the whole of what was asked for.

= 3.80.0 =

**THE PICKER HIDES NOW. IT WAS GROUPING, AND GROUPING IS NOT HIDING.** 3.76.0 put the chosen series' pictures first under a heading and everything else under a second one, so a series with two tagged images still showed all eight. At the forty or fifty this folder is heading for, a heading only tells somebody where to stop reading; it does not save them the reading. Only the chosen series' pictures are offered now, and there is no route back to the full list, because a route back is the grouping again with a click on it.

**A SERIES WITH NOTHING TAGGED GETS A SENTENCE, NOT THE WHOLE FOLDER.** A quiet fallback is indistinguishable from the filter not working, which is what was reported twice: "No images are available for that series yet. Contact MarCom for an event image to be added." The "no picture" row stays, so the form can still be sent without one and the approver picks.

**The two forms filter in different places, and they have to.** The community form's series is fixed by the URL, so the SERVER writes out that series' pictures and nothing else: no script can undo it and there is no moment where the list is wrong. The staff form's series is a dropdown, so every row is written out carrying the series it belongs to and the script hides what does not match. Filtering that one on the server would be right on arrival and quietly stale the moment somebody changed the select with scripting off, and a list confidently showing the wrong series is worse than one showing all of them.

**An untagged picture is hidden by every series.** It is not a picture for everybody; it is one nobody has filed yet.

**AND THE TWO FILTERS OVER ONE LIST DO NOT FIGHT.** The search box and the series narrowing act on the same rows, and both assigning `hidden` is two answers to "is this row on screen" with the later one winning: typing in the search box would have restored every picture the series had just removed. The series filter owns a marker, the search filter owns the property. `.claude/picker-filter-test.php` renders the picker and counts the options, which is the only question anybody was asking, and four planted faults were each caught.

**THE BANNER IS A LIVE PREVIEW OF THE EVENT.** The community form has had the series picture across the top since 3.47.0 and it was decoration. It follows the picker now, on both public forms, so what is at the top is what the event will look like. The staff form gets one too, driven by its series dropdown. No picture means no banner rather than a placeholder, and the zoom is 260ms because somebody comparing three programmes will change that dropdown three times.

**AN UPLOAD DOES NOT CHANGE THE BANNER, AND NOW SAYS SO.** A file attached here is a working copy that an approver decides about, so putting it in the banner would claim it is already the event's picture. But somebody who attaches a photograph and sees nothing whatever change will believe it failed, so there is a thumbnail of it under the field with one line: sent for review, somebody will decide. The thumbnail says it arrived; the line stops the thumbnail saying more than that.

**THE MONTH ARROWS HAD NO BORDER AT ALL, AND THE STYLESHEET APPEARED TO SAY THEY DID.** `.uc-month-nav-side .uc-month-nav` declared a 1px edge and an 8px radius; twenty lines further down `.uc-calendar .uc-month-nav` declared `border: 0`. Both carry two classes, so source order decided it and the later one won. What was on screen was a text chevron in gray on white with nothing to aim at, which is an exact description of "too small and blending into the colour scheme". **Computed rather than read**: `.claude/month-nav-cascade.php` prints the winner and the loser for every property, and it was written because the obvious remedy, darkening the border, would have darkened one that never rendered.

**And they sat at opposite ends of the header.** On a 1200px block that is about 900px of travel between the two buttons somebody uses alternately. Previous, Today and Next are one segmented control at the right now, with the month name to their left.

**The boundary is the 3.64.0 control standard's, not a new colour.** `--uc-control-edge` is `#8C8D8E`, byte for byte what portal.css already uses, measured at 3.33:1 on white and 3.13:1 on the page. This file's `--uc-border-strong` is `#D7DBE1` at 1.39:1, which is right for a CARD edge, since that is decoration, and was being used for controls too. **44px on a touch screen**, because these are the only way to move through months. The chevrons are the icon set's own path rather than `&lsaquo;` and `&rsaquo;`, which are text and took the host's font, weight and line box.

**THE SIDEBAR CARD WAS NOT OVERFLOWING, AND NOTHING WAS DRAWN SHORT.** Nothing caps a height, nothing is positioned out of flow and nothing sets overflow on that card or its panel, so the box has always grown with its contents and "See all events" has always been inside it. In the combined view the sidebar deliberately has **no box at all**: no border, no padding, no background, because the panel around it carries all three. So the only edges on screen were the heading band's bottom hairline and the link's top hairline, and with the list empty those two are far apart with a paragraph between them, which reads as a card that closes above its last row. **The cause is present in every state.** A full list fills the gap with the rows' own hairlines, which is why an empty month is what made it visible, and every one of the 287 imported events is still a draft.

**The fix is to make the column read as a column.** The heading band escapes the panel's padding instead of the sidebar's zero, so it spans the top of its column and meets the card's own edge, taking the card's inner radius on the corner it actually reaches. A band inset on all four sides inside a rounded white card is what read as square corners.

**And the comment on that container claimed an `overflow: hidden` it never had.** Since 3.45.0 it has explained that the property is what lets the panels' square corners sit inside the rounded border. It was never declared, for thirty-five releases, and nothing was ever reported, which is the evidence it is not needed. It was added here and taken straight back out: `.claude/embed-modes-test.php` refuses any height or overflow on that container, a guard from 3.31.2 where a height cap above `overflow: hidden` cards squashed them to 40px strips. **The fix was not to widen the guard.** The one element that reaches a corner carries the matching radius itself, and the comment now says what is actually true. A comment asserting a declaration that is not there is worse than no comment, because it is what stops the next person looking.

**The empty state's words and controls are deliberate and are unchanged**: a month with nothing on says so, then offers the neighbouring months and everything else, which are the same three ways on a full month offers. What was wrong is that a small muted line directly under an 18px band reads as a card that failed to load, so it has a row's vertical rhythm and is centred under a centred band. **The air below it in the combined view is also deliberate**: the two panels share one bottom edge because they are one card, so a short sidebar beside a tall month grid has space under it by construction.

= 3.79.0 =

**THE BULK CATEGORY CONTROL HAD NO TICK BOXES, AND HAD NEVER HAD ANY.** Not unstyled, not hidden, not gated away from some viewers: the cell was never drawn on that screen. 3.73.0 inserted it into `upcoming_overview()`, the read-only table on the dashboard, rather than `events_table()` immediately below it, and it has been there for six releases.

**Both halves of one fault, in two different tables.** The events list got a column HEADER with no cell under it in any row, so a bulk panel sat above a table with nothing to select. The dashboard got the cell, under a header row that has no such column, guarded by a `$plain` that does not exist in that method, posting to a form that is not on that screen.

**It read correctly in review because the two loops are identical where it landed.** Both open with the date, then `get_post_status()`, then `<tr>`. Nothing at that point says which table you are in. And every check in use proved something true and beside the point: the file parsed, the string was present, the edit had landed. All three answer "was it written". The question was "is it written in the same table as its header", and **that is a relationship rather than a presence**, so `.claude/bulk-ticks-test.php` now asserts it per renderer, along with every `form=` association resolving to a form the file actually opens.

**BULK PUBLISH IS ON THE EVENTS LIST NOW.** 3.71.0 put it on the series schedule, which works one series at a time, and there are 287 drafts across 32 series.

**THE FIVE SKIP RULES ARE THE SCHEDULE SCREEN'S, ASKED RATHER THAN COPIED.** A past date, an event with no date, a submission awaiting review, anything carrying source provenance, and an import parked as a draft because it vanished at its source are each refused, by `SFAF_Series::publish_skip_reason()` itself. That method was already written per event with the date passed in, so it takes no series and needed no change to serve both screens. **A second copy is how two screens come to disagree about what may reach the public calendar**, and the test asserts that both callers call it and neither restates a rule of its own.

**One set of ticks, two buttons, and they do not reach the same rows.** A checkbox associates with exactly one form, so a second action cannot have a second form without a second column of boxes. The form says who; the button says what.

**A ROW THAT CANNOT BE PUBLISHED KEEPS ITS TICK AND SAYS WHY.** This is the one place the two screens differ, and it is forced: the tick is shared with the category control, which reaches every row the viewer can edit, past events and imports included. Taking the box off a past import to protect the publish button would take the category control's reach with it. So the reason sits under the box instead, on drafts only, because "not a draft" on a page of published events is the page restating itself.

**Each button counts its own subset.** The category button counts every tick. The publish button counts only the ticks it could act on, and disables at zero even with forty rows ticked. A button whose number includes rows it is about to skip is the failure this is built to prevent, and it is the one button on the screen where being wrong is public. **The server decides eligibility and the script only counts it**: a box with the marker stripped by hand is still refused at the write, where permission and the five rules are both re-asked per id.

**The confirmation names the count and what is being left out**, in the schedule screen's own words, because they come from the same method. The outcome names four buckets rather than one: published, could not be published, not yours to change, and could not be saved. **Skipped and refused are said separately** so that ticking a past date does not read as a permissions problem.

**And nothing renders that cannot do anything.** The panel is absent when no row on the page is the viewer's to edit, the publish button is absent when no row on the page could be published, and the count starts at 0 rather than at the number of rows, which was a button naming a larger set than the one it would act on.

= 3.78.0 =

**THE PREVIEW IS TWO TARGETS, THE PICTURE AND THE BUTTON.** 3.77.0 wrapped the whole panel in one anchor and explained the reasoning rather than doing what was asked, and the consequence was visible: everything inside took the anchor's computed colour, so the theme's link teal tinted the entire box and the date, the time and the place all read as links. A preview whose every line looks clickable says less than one with two things that are.

**The title is deliberately not a target either.** A title that is a link in a panel where the date beside it is not is the same confusion in miniature, and the tile underneath is already a link on the title.

**What survives is the reasoning, and all of it still holds.** Both destinations come off the tile, copied rather than re-derived, and from **one loop**, so the picture and the pill cannot disagree with each other or with the tile about where they go or how they open. **Neither is a tab stop**: the panel is `aria-hidden`, a focusable element inside one is invisible to a screen reader and still a stop, and a keyboard user reaches the tile which already carries the same destination.

**Nothing else was inheriting link styling, checked rather than assumed.** The title, the three fact lines and the cancelled line have always stated their own colour; the pill states its own pair, white on the dark teal at 5.35:1; and the picture's link holds no text at all. What **was** inheriting was every one of those, from the wrapper, and the wrapper is gone.

**THE SERIES PREVIEW ON THE STAFF FORM WAS SMALLER THAN THE ROWS IT CHOOSES FROM.** Not missed in 3.76.0 and not a rule living somewhere else: that release raised both in one edit, the trigger 48 to 96 and the row 64 to 132, which **inverted** them. The picture somebody looks at to decide ended up smaller than the ones they scan past, and neither number was wrong on its own. It is 200 by 113 now, and the ORDERING is the thing to preserve from here rather than either number.

**THE IMAGES SCREEN IS REBUILT.** The grid's floor was 150px, chosen as "a thumbnail somebody can recognise a photograph in", which was the wrong question: the widest thing in a card is a select that has to render "Mobile Health Sites" without clipping, and that is what sets the minimum. **300px, so three across rather than six.**

**One form per card, and it was two.** A card held a tick, a thumbnail, a name box with its own Save, a series dropdown with its own Add, and the tag chips: six controls and two submit buttons in a 150px column, thirty controls on a screen, with the dropdown clipping after the word "Add" so the one thing it exists to show was the thing it could not. **Naming a picture and filing it are the same act at the same moment**, so one Save does both: the name is replaced and the series, if one is chosen, is added. Both fields are labelled now rather than placeholder-only, which is the pattern that made it read as a wall of boxes. The chips keep their own x, because taking a series off is an undo and must not wait for a press it has nothing to do with.

**A primary button that cannot do anything stops being primary.** "Tag 0 images" rendered in brand Yellow at 55% opacity, and 55% yellow is still yellow. Yellow appears once on a screen and means "this is the thing to act on"; a control that is inert must not wear it. A disabled primary is a plain disabled control now, on all three screens that use the tick pattern.

**The upload panel folds, shut**, because adding a picture is something an admin does occasionally and looking at the library is what everybody does every time. A native `<details>`, like the Series and Categories lists.

**AND THE FILENAME RULE WAS TOO AGGRESSIVE.** `looks_like_a_filename()` compares a title against the file it came from with separators turned to spaces, which is right about WordPress's own derived titles and is a **guess** about everybody else's. The guess is wrong in the commonest case there is, because a well-named file is usually named after the picture:

```
"Cycle To Zero"             on  cycle-to-zero.jpg             thrown away
"Strut SFAF San Francisco"  on  strut-sfaf-san-francisco.jpg  thrown away
```

So somebody could type a name on the Images screen, save it, and watch every picker go on showing the file name. **The remedy 3.76.0 added for exactly that complaint did not work for the case it was most likely to meet.**

**A name typed here is known rather than guessed at now.** Saving one marks the picture, and the reader trusts a marked title without putting it through the heuristic. **Clearing the box clears the mark**, so emptying a name really does put the row back to its file name. The heuristic stays and still covers every picture nobody has named, which is what it was written for.

**And the screen says why.** One line pointing at the box rather than explaining the rule, plus "no name yet" beside the label on the cards where it is true. A card showing a file name beside one showing a name looks like a fault, and was reported as one twice.

= 3.77.0 =

**THE PREVIEW'S BUTTON WENT NOWHERE, AND THE ADDRESS WAS NEVER MISSING.** The tile a preview describes IS an `<a>` with the event's href on it, so the URL has been sitting on the element the panel is handed since 3.75.0. What was missing is that nothing read it and the panel was built entirely out of spans: "View Event Details" looked like a button, was not one, and had nothing behind it.

**THE WHOLE PANEL IS ONE LINK NOW, NOT THREE.** The picture, the title and the pill are all things somebody aims at when they want the event, and the tile underneath is already exactly this: one link over its whole area. A panel that is an expansion of that tile should behave like it. Three separate anchors would be three places for the target and the rel to drift apart, and three cursors that change on some parts of the panel and not others.

**What it costs is text selection**, and that is named rather than discovered: nobody selects the date out of a panel that closes when the pointer leaves it, and every line in it is on the tile and on the event page as well.

**The destination and how it opens both come off the tile**, copied rather than re-derived, so the panel cannot open in this tab while the tile opens a new one. **It is not a tab stop**: the panel is `aria-hidden`, a focusable element inside one is invisible to a screen reader and still a stop, and a keyboard user already reaches the tile, which carries the same destination. Sixty tiles would have been sixty extra stops.

**FILL THIS IN FROM THE LAST ONE, ON THE STAFF REQUEST FORM.** caladmin has offered this since 3.64.0 and the request form has not, so a requester retyped the location, the times and the description for an event that has run eleven times.

**One data source, two appliers**, which is the honest shape rather than a flag inside one function. The payload is `SFAF_Series::prefill_data()` unchanged, the same function caladmin reads, and it is pure server-side PHP with no logged-in user and no media library in it. What differs is the writing, because the two forms genuinely have different controls: a venue select and a text box rather than a location-mode radio group, radios for the picture rather than a hidden attachment id, one organizer rather than several. A function with two field maps in it is two functions sharing a body, and the one nobody is looking at is the one that rots.

**Nothing posts.** Every write lands in a field already on the page. A control that applied by posting and redirecting would discard every unsaved answer on the form, which is the 3.3.0 fault that taught people not to press the FAQ set picker.

**A tick per field, all ticked, each naming the value it would write**, and a field somebody has already answered is **marked as one this would replace** rather than silently taken: the row stays ticked and the decision stays theirs. **The date is never filled in**, and neither is the title, because setting the date is the reason somebody is filling the form in.

**AND IT IS PROVED BY RUNNING IT, WHICH IS THE WHOLE POINT.** The caladmin twin of this control sat dead for twenty-six releases because a variable was used before it was assigned: the call was present, the file parsed, the callable audit was happy, and the card simply never drew. `.claude/request-prefill-test.js` executes the real function out of `portal.js` against a document, presses the button, and reads back both what it rendered and what it wrote. Its self-test plants the panel never revealing, and a write aimed at a field this form does not have.

**Two things that test found rather than confirmed**, both in its own stub: `type` was a plain property while a browser reflects it to the attribute, and a radio group was not exclusive, so the test was about to assert something the code neither does nor should do. The stub's own rule, that it must never answer a question differently from a browser, is what caught both.

**The hand-off gives up its two most durable blocks** to `PROJECT.md`: the events a save cancelled between 3.36.0 and 3.40.0, where the damage outlives the fix because a second save silently un-cancelled one and the cancelled list is therefore not the whole list, and the four things waiting on somebody outside the code.

= 3.76.0 =

**THE HOVER PREVIEW WAS ONLY EVER WIRED FOR THE SHORTCODE, AND THIS CALENDAR HAS NO SHORTCODE.** 3.75.0 put it in `calendar.js` and nowhere else. `calendar.js` is the script a shortcode page loads; the calendar has no front end on resources.sfaf.org and exists only as an embed on sfaf.org, which runs `embed.js`, a deliberately jQuery-free reimplementation that says so in its own header. So the preview was correct, covered by a test, and **never executed anywhere anybody could see it**.

**Nothing else was wrong, and each was ruled out by trace rather than by assumption.** The markup was there the whole time: the embed calls the same `render_month_grid()` the shortcode does, so every tile in its payload carried every attribute. `showPopover()` was never reached, because the function that calls it was not on the page. Edge was never the issue; it has supported the Popover API since 114.

**The block now exists in both scripts, byte for byte**, between markers, and the build fails if the two copies differ by a character. That is the arrangement the recurrence engine already uses for the same problem: one logical copy, enforced by the build rather than by somebody remembering. A shared third file was the alternative and was rejected, because it means a second network request from a third-party page with an ordering question attached.

**And the test that passed throughout is the lesson.** Every assertion in `hover-preview-test.php` was true of `calendar.js` while the feature did not exist on the only surface that matters. A test that checks the right thing in the wrong file reports a feature as fine when nobody can use it.

**THE TAG LAYER AND THE MEDIA SCREEN WERE ALREADY THERE**, shipped in 3.74.0 and not rebuilt: the series taxonomy registered for attachments, the grid, bulk tagging, the per-image dropdown, the series filter, the untagged filter, and the three permissions. **The picker is filtered too.** What it does when NOTHING IS TAGGED is show one ungrouped list of everything, which is correct and is indistinguishable from broken. There are six pictures in the folder and none carries a series yet.

**THE FILE NAMES ARE NOT A REGRESSION EITHER.** `SFAF_Media::row()` runs every title through the check 3.65.0 built, which blanks a title WordPress derived from the file on upload, and the picker then falls back to the file name. "Dsc 0043" is not a name anybody chose and is worse than showing `dsc_0043.jpg`. **What was missing is anywhere to type a real one**: every picture in the folder was uploaded without a title, and the only screen that could fix that was wp-admin, which most of these people never see. The Images screen has a name box per picture now. It writes the title and nothing else: the file does not move, the id does not change, and every event pointing at the picture goes on pointing at it.

**THE THUMBNAILS WERE 48x27 AND 64x36**, and at that size a photograph is a smudge. Somebody choosing between six pictures of people in a room cannot tell them apart, which is the whole job of the control. 96x54 on the trigger, 132x74 in the rows, and the panel is taller to match.

**"NO PICTURE CHOSEN" ON A SERIES THAT HAS A DEFAULT was the first of the two possibilities: the form did not know about the series photo at all.** The staff form's row has been filled in since 3.68.0 by reading the photo off the series `<select>` as somebody changes it, and **the community form has no such select**, because its series comes from the URL. The server fills the row in now, which is the right half either way: a form whose series cannot change has nothing to wait for a script to tell it, and the staff form gets a correct first paint instead of a correct second one.

**The picture section moved directly under the series**, which is what decides its default. It was six sections below, so choosing a series updated a control nobody could see.

**THE STAFF FORM HAD NO ORGANIZER FIELD AT ALL**, and nothing anywhere recorded that as a decision. Every staff request arrived with no organizer and whoever approved it had to know or ask, when the requester is the one person who certainly knows. There is one now, **first, above the series**, because organizer then series then picture is the order these three depend on each other. A closed list with **Not sure** as a real answer that stores nothing; creating an organizer stays a caladmin decision.

**THE COMMUNITY FORM DERIVES ITS ORGANIZER FROM THE SERIES**, because that form is only ever reached at a series' own address and a stranger is in no position to say which SFAF programme is putting an event on.

**The link is derived and not stored, and that is worth knowing.** A series carries a description, an image and a default FAQ set; it does **not** carry an organizer, because an organizer is a property of the EVENTS in it. So this reads it off the most recent event, exactly as the caladmin prefill card does. **A series with no events, or whose events have no organizer, answers with nothing, and nothing is invented:** the submission arrives with no organizer, as every submission did before, and whoever approves it sets one. A guessed organizer on a public page is worse than none.

**THE FAQ SET NOW SHOWS ITS QUESTIONS.** Choosing one named a set and a count and showed nothing else, so a requester picked blind and had to remember what "Clinic basics (4)" contains. Every set's questions are in the markup and the script narrows it to the chosen one, which is the start-visible-and-hide rule this form follows everywhere: with nothing running a requester sees all of them under headings naming each. **Read-only**, because the rows are copied server-side at validate time and a second editable copy here would be a second place the set's text can change.

**THE ICON PICKER DRAWS THE ICONS.** It was a `<select>` of thirty words, and choosing between thirty glyphs by name is guessing: "Bolt" and "Star" tell you what the word is and nothing about what the picture looks like at 20px on a card. It is radios now, the same arrangement the colour swatches on that form already use. **The grouping had to become data to survive**: it was a comment in the source, which reads well and cannot be rendered, and a grid of thirty unlabelled glyphs would be worse than the list it replaced.

**THE SERIES AND CATEGORIES LISTS FOLD AWAY**, so the form under them is reachable without scrolling past twenty-five rows. **One threshold, asked of the list, rather than two defaults chosen against today's counts.** "Collapse series, open categories" is the obvious answer and the wrong shape: it is two judgements taken against the numbers on one afternoon, and the seventh category becomes the fortieth without anybody revisiting them. Ten rows is where a list stops being something you take in at a glance. Today that means categories open and series folded, which is what was asked for, and it goes on being right when the counts move. A native `<details>` in both cases, so it works with nothing running.

= 3.75.0 =

**THE UPDATER LOOP IS CLOSED AND THIS IS THE RELEASE THAT RECORDS IT.** Check for updates on 3.73.0 offered 3.74.0 and it installed in one click. The cache was the cause, the fix works, and the cycle runs end to end: build, release, the site offers it, press update. Two releases had to go on by hand; nothing does now.

**SIX MORE CATEGORY COLOURS, AND THEY ARE SHADES RATHER THAN NEW HUES.** Seven categories against ten colours left three, and more categories are coming. Light Orange and Deep Orange, Light Purple, Deep Teal, Light Green and Deep Green: sixteen now, every one recognisably the brand's.

**EVERY ONE IS MEASURED AND THE MEASUREMENTS ARE IN THE BUILD.** `.claude/palette-audit.php` checks the two things that can go wrong when a palette grows, on every colour and every pair. Can this colour carry its own icon: the placeholder fills a rectangle with the raw colour and draws the icon and the label on whichever neutral wins, so a colour with no neutral over 4.5:1 is one where both are washed out. And can anybody tell it from its neighbour: CIE76 in Lab, floor 25, measured against the 28px swatch the picker actually renders at rather than against a hex comparison, because two hexes differing in every digit can look identical.

**AND THE FIRST DRAFT OF THAT AUDIT HAD A CHECK THAT COULD NEVER FAIL.** It asked whether each colour had a neutral over 3:1, which is arithmetically guaranteed with these two neutrals: clearing 3:1 against Dark Gray needs a luminance under 0.2155, clearing it against white needs over 0.30, and nothing can be in both bands. The worst any colour can do is **3.44:1** at the crossover. So the check was a statement about arithmetic rather than about the palette and would have passed any shade anybody ever added. The floor is 4.5 now, which a real colour can fail, with Red and Pink exempt by name as brand colours that predate the rule and are already documented as large-text-only. **Its own self-test is what caught it.**

**THE ICON SET GREW FROM SIXTEEN OFFERED TO THIRTY**, and the reason is a collision rather than a shortage: **Español and Program Groups both drew the same icon**, so two kinds of event were indistinguishable on every placeholder they produced. Nine new glyphs, in the same 2px stroke as the rest, for the things this calendar actually runs: a conversation, a globe, a book, a meal, music, a bicycle, a shield, a star and a flag. Four more were already drawn and had simply never been offered.

**The swatch copy no longer counts.** It read "the ten approved brand colors", which was true of the guide and stopped being true here. A number in copy goes stale silently, so it says what the rule is instead.

**CALENDAR VIEW IS WHAT A BLOCK OPENS ON.** It was the list, and it was the list in **four places that all agreed**: the shortcode's attributes, the shortcode's render arguments, the REST route's parameter and embed.js's own fallback. The reason was written down and was a real one, that a thin calendar looks empty as a grid. The calendar is not thin any more.

All four say the grid now, and the build compares them rather than asserting a value, so changing the default deliberately passes and changing it in three of four does not. **A visitor's own choice still wins**: embed.js remembers which view somebody last pressed, per block, so this changes what a first visit opens on and nothing about a returning one.

**A HOVER PREVIEW ON THE MONTH GRID.** Hovering a tile shows the picture, the date, the times and where it is, with the event's own link under it.

**IT IS IN THE TOP LAYER, AND THAT IS THE WHOLE OF THE STACKING ANSWER.** 3.70.1 spent a release proving a number cannot win here: an ancestor on resources.sfaf.org has a transform, which makes it the containing block for fixed descendants and traps every z-index inside its own stacking context. `showPopover()` is the non-modal door into the same top layer `showModal()` uses for the registration dialog, and the non-modal one is the right door: a preview that moved focus and made the rest of the page inert because a mouse passed over a tile would be absurd. **There is no z-index in its stylesheet at all**, and the build fails if one appears.

**NO FALLBACK, DELIBERATELY.** A browser without `showPopover()` gets no preview and keeps a tile that is still a link to the event. The alternative is a number we already know loses, on the one theme that matters, in a way nobody would notice until somebody said the preview was behind the header.

**IT APPEARS BELOW THE TILE, ABOVE IT WHERE THERE IS NO ROOM BELOW**, and pinned inside the viewport either way, which is what a tile on the last week of the month needs. Measured after it is shown, because a popover has no size until it is in the top layer, and kept invisible for that one frame so the first paint is not a flash in the corner.

**MOBILE GETS NOTHING, AND THAT IS THE DECISION RATHER THAN AN OMISSION.** There is no hover on a touch screen; a hover preview there fires on tap, so the first tap would show a panel and the second would open the event. The whole thing is behind `(hover: hover) and (pointer: fine)`, which asks the device what it can do rather than guessing from the width, because a wide touch screen still cannot hover. **260ms before it opens**, because moving a mouse diagonally across a month passes a dozen tiles.

**THE REGISTRATION BOX WAS THE LAST THING ON A PHONE.** On desktop the card holding the date, the time, the place and **Register** sits beside the top of the content. Stacked into one column it landed after the entire description and the FAQ, so the primary action on the page was the furthest thing from the top of it.

The card goes first on one column now, which is **where the desktop layout already puts it**: when, where, register, then read. Not a sticky bar with its own Register, which would be a second control to keep in step with the first for one event: the card moves, and there is still exactly one of everything on it.

**And the page stayed two columns down to 601px**, where the content column is 249px and a paragraph reads at about thirty characters a line. It stacks at 860px.

**THE DAY PANEL'S THUMBNAIL WAS FLUSH AGAINST ITS TITLE.** The rule that hides the thumbnail in a 40px grid cell also sets `gap: 0` on every entry, which is correct about the cell and reaches the panel below the grid, where the thumbnail is deliberately put back. Nothing lost on specificity and nothing was missing: a rule that was right in one context, one selector along. It is the same fault family as the cascade defects and wants looking at from the context where the thing it assumes is false.

**A FOURTH, FOUND AT 360px RATHER THAN REPORTED.** A **table** or an **iframe** pasted into an event description had never been styled at all, and either pushes the whole page sideways on a phone. The table scrolls inside its own box, because six columns cannot be read at 360px however they are styled, and a pasted URL with no spaces in it now breaks instead of taking the page with it.

**AND A TEST BROKE WITHOUT ANYTHING IT CHECKS CHANGING.** `filter-bar-test.php` located a button with a nested-quantifier regex across the whole shortcodes file; adding forty lines to that file exhausted PCRE's JIT stack, `preg_match()` returned false rather than 0, and the test read that as "the button is missing". It is string work now, which cannot backtrack, and the question it asks is unchanged.

= 3.74.0 =

**THE FAQ ANSWERS THAT STAYED PLAIN WERE THE ONES A SET PUT THERE, and there is a third path nobody had counted.** A row arrives on that screen three ways: from the server, from + Add FAQ, and from applying a FAQ set. The first two ask for an editor and always did. The set picker clones the same template, fills in the question and the answer and appends the row, and it asked for nothing, so those answers stayed the plain textareas the template holds. They stayed plain until something else swept the document, and pressing + Add FAQ is exactly that: one new row, and every set row on the screen turning into an editor at the same moment. **The sweep was doing that, not the add.**

**The console was silent and the silence was the finding.** The catch added in 3.72.0 names an initialise that threw; nothing on this path ever got as far as initialising, so there was nothing for it to name. The 3.72.0 logging is untouched and is still the route to a diagnosis if anything else is wrong.

**Matched on the document rather than called from the picker**, because initFaqSetPicker() is in another top-level scope of portal.js and calling across it is the fault 3.73.0 closed. `.claude/rich-text-start-test.js` presses both buttons now and plants the 3.73.0 arrangement, in which a set row is appended and never started.

**PUBLISH FROM THE EVENT EDITOR WAS NOT APPROVE FROM THE QUEUE, and on a submission that is the whole difference.** Approve writes the submitter's address onto the notification list and sends the published notice, both behind ticks. Publish set the status and did neither. So somebody reviewing a submission properly, by opening it and reading everything, published it in a way that told the person who sent it nothing at all, and there was no way to reject from that screen because Reject was not on it.

**Approve and Reject are on the editor now, and they are the queue's own two forms:** same action, same nonce, same prompt, same two ticks. The buttons carry `form=` because a form cannot nest inside the event form, which is the same reason the ticks in the prompt already do. There is no second way to approve.

**THE BUTTONS FOLLOW THE EVENT'S STATE**, because on a published event Save and Publish were two labels for one outcome, and two controls that look like a choice and are not teach people to stop reading the pair.

```
published, scheduled   Save changes
pending submission     Save changes, Approve, Reject      (calendar admins)
pending, not a sub     Save changes, Publish
draft or new           Save draft, Publish
```

**No Unpublish.** Cancelling is how an event comes off the calendar and it tells the people who registered; a second quiet route to making one disappear is a way to do it by accident. **Delete is a card at the foot of the screen, after the cancel card**, on the same route the events list posts and with the same refusal on an event that has registrations. It is not in the row of actions because Save is pressed dozens of times a day and deleting cannot be undone from that screen, and it is after cancelling rather than before because the two read as a ladder: the reversible answer first, the irreversible one last on the page.

**THE SEARCH ON MY EVENTS WAS NOT SEARCHING TOO EAGERLY. IT WAS NAVIGATING.** It has been debounced at 300ms since it was built, so "it searches on every keystroke" was not the cause; what it did after the debounce was submit the form. A submit tears down the document, so every letter typed while the answer was in flight went into a page that no longer existed, and the refocus mark then put the caret back at the end of whatever the SERVER thought the term was. A longer debounce would not have touched any of that.

It fetches the same URL now and swaps the results card. **The input is never replaced**, so there is no focus to restore and nothing typed mid-flight is lost. The address bar still says what is being searched, through replaceState rather than a history entry per keystroke. A stale answer is discarded by sequence number, so a slow reply for "co" cannot land on top of the results for "coffee", and anything that fails falls back to the page load it replaced, which is also what happens with no script.

**FIVE MINUTE TIME STEPS WERE NEVER LOST.** Every time control in the plugin has carried `sfaf_time_step_attr()` since 3.72.0 and all twelve still do. What was missing is anything that would notice the thirteenth being written without it, so `.claude/time-step-test.php` checks every call site and the one rule that decides where the attribute is deliberately absent: a control already holding a time off the boundary does not get it, which is what keeps an imported 6:07 editable.

**THE COMMUNITY FORM ASKED FOR A NAME AND AN EMAIL TWICE**, thirty fields apart, and for most submitters the two answers are the same answer. There is a tick now: **Use my name and email as the contact on the event page**.

**The direction is what makes it safe and it only runs one way.** "About you" is collected first and is internal: it reaches the notification list and the alert and appears on no public page. This copies it ONTO the public contact when somebody asks for that, and nothing ever copies the other way. The default is off and the separate fields are visible, so nothing anybody typed becomes public because a control was left alone. The copying is done by the validator, so the answer is the same with no script at all.

**"Somewhere else" is "Enter location manually"**, and **"How many places, if there is a limit" is "Capacity"**, which is what caladmin already calls it.

**AND THAT FORM HAD AN UPLOAD AND NO PICKER AT ALL.** It never had one: the chooser was built for the staff request form in 3.67.0 and this form was left with a file input, so somebody submitting a Strut event could not use the Strut photograph that already exists and is already the right shape, and whoever approved it had to go and set one.

**One picker for both forms now**, and it leads with the pictures tagged for the series whose link the submitter was given, with everything else listed under them rather than behind a toggle. A picture chosen from the folder becomes the event's image straight away; an uploaded one still does not, because that is a working copy in a folder meant to be emptied.

**Showing the folder to a stranger is not showing the series list.** The refusal that keeps FAQ sets off this form is about NAMES: a dropdown of set names is a directory of this calendar's programming handed to anybody who opens the form. These are the images already published on the public calendar's own event pages.

**THE IMAGE LIBRARY, IN CALADMIN, TAGGED BY SERIES.** Contributors and editors never see wp-admin, so the WordPress media library is not available to most of the people who maintain this calendar. An organizer who wanted to know what pictures exist for their programme had nowhere at all to look, and WordPress's own tagging asks you to type a tag rather than pick one, which is how a library ends up with "Strut", "strut" and "STRUT".

**The tags ARE the series.** Not a second vocabulary: a new series makes a new tag available the moment it exists, renaming a series renames the tag, and there is no list to keep in step.

**DELETING A SERIES DOES NOT TOUCH THE IMAGES, and that is guaranteed rather than intended.** `wp_delete_term()` deletes the term and its rows in `term_relationships` and does not read, write or delete a single post. The half that a future build could break is ours, so `.claude/media-tags-test.php` reads every term-deletion callback in the plugin with a tokenizer and requires that none of them writes a post. One is registered, the embed cache flush, and it writes none. What is left is an image with no row in the taxonomy at all, which is exactly what the **Untagged** filter asks for, so it surfaces rather than disappearing.

**Upload is admins, tagging is admins and editors, looking is everybody with caladmin access.** An editor can tag images they cannot upload, which is what lets somebody tidy a library they are not adding to. The screen has a grid, tick boxes and a bulk tag using the same pattern the schedule screen's bulk publish uses, a per-image dropdown, and filters for each series and for untagged.

**The picker inside an event opens on that event's series**, with **All calendar images** beside it as the way out, and **wp.media's Upload Files tab is gone for anybody who is not a calendar admin**. The hidden tab is not what refuses: a hidden tab is a hidden tab, so `SFAF_Media_Folder` filters `upload_files` for requests carrying the picker's own flag. It narrows and never widens, touches no other upload on the site, and writes no role.

**`SFAF_Uploads::inspect()`** is the public forms' guard, split out of `store()` unchanged so caladmin's own upload gets exactly the same checks and a different destination. Two copies of "is this really an image, and is it wide enough" is the one duplication worth refusing outright: it is the guard between a form and the server's disk.

**THE DUPLICATE CONTROL NAMES THE EVENT IT COPIES.** It read "Use this event's details on another date", and on a series holding several distinct events "this event" names nothing the reader can see. Four series are in that position. The name comes from the same one rule the button uses, which is what 3.73.0 made true, so the label and the copy cannot disagree.

= 3.73.0 =

**APPROVE AND REJECT ON THE PENDING QUEUE HAD BEEN DEAD SINCE 3.72.0, and so had Get a form link.** One cause, three controls. portal.js is four top-level IIFEs; 3.72.0 declared the light-dismiss helper inside the first and called it from the third and the fourth, which are its siblings and cannot see into it. Both calls threw, and because each throws after the click is cancelled and after the panel has been moved into a not-yet-shown dialog, the visible result was a control that did nothing at all. The declaration is at file scope now.

**Neither build gate could see it and neither ever will.** Checking that a file parses is not checking that a name resolves, and a ReferenceError is a runtime fact. **`.claude/js-scope-test.js`** closes it: it reads every top-level scope and requires that every call to a name the file itself declares is made from a scope that can see the declaration. Only our own names, so there is no allow-list of browser globals to keep in step. Its self-test puts the 3.72.0 arrangement back and requires both calls caught.

**The locked FAQ answers showed HTML as literal text, and it was the one locked rich text field in the plugin that did not use the locked renderer.** An imported answer is stored as HTML; the FAQ block hand-wrote its own textarea and escaped that HTML into it, so the browser showed the tags. Every other locked field flattens to prose first. **Three releases were spent on the wrong control:** the symptom reads as "the editors do not start", and those rows are not editors and are not meant to be, because a fetch owns them.

**And the real question is now answered for our half.** `.claude/rich-text-start-test.js` runs the starter against a document holding two stored rows and a template: both stored rows are asked for an editor **at page load**, the template row never is, and a failure is named in the console with the element id. What it cannot reach is whether WordPress then succeeds, and it says so.

**The events list is three icons on one line.** Edit, Duplicate and Remove read at one weight in two similar colours across 259 rows, and the column was wide enough that some rows wrapped and some did not. Each icon carries hover text **and** a clipped name for a screen reader, and the destructive one carries its red at rest rather than only on hover. **On a touch device there is no hover**, so each control gets a 44px hit area larger than the box drawn, and the clipped name is what a phone reads out.

**"Cancel instead" was wrong in both halves.** "Instead" pointed at a Remove link the reader never saw, because on that row it had been replaced. And it went to the bare editor, which asked which occurrences an **edit** should touch and then opened a form, so somebody who pressed something about cancelling got a question about editing and landed on the wrong screen. It is **Cancel** now, it opens the cancel card and scrolls to it, and **the scope question is answered rather than suppressed**: cancelling takes one event id and acts on one event, so "this event" rides the link and is true rather than convenient.

**A third case existed that the two-way branch missed.** The cancel card is not drawn at all on an imported event, because a platform owns it, so a Cancel link there would have landed on a page with no card on it: the same fault, rebuilt one row along. An imported event with registrations shows a padlock instead, which is a state and not a control.

**Bulk add a category, in caladmin.** Contributors and editors never see wp-admin, so WordPress's own bulk edit is not available to most of the people who maintain this calendar. **It adds and does not replace**, said on the button and again in the confirmation.

**Which rows it may reach is every row the viewer can already edit, and that is deliberately not the bulk publish's exclusions.** Each was asked about rather than copied. Publishing is refused on a past date, on an event with no date, on an import and on a submission because publishing reaches the **public calendar**. Filing an event changes no status and publishes nothing. A past event is fine; a submission awaiting review is better categorised **before** it is approved, which is the moment it goes public; and category is a manager-owned field on every adapter that a fetch never overwrites. Permission is asked per id at the write, because this route takes a list.

**One tick-box pattern, not two.** The bulk publish picker and this one are the same code. It reads the form's own element list rather than a selector, which is what makes it work on a table whose rows already contain forms, since forms cannot nest.

**The Display RSVP tick follows Accept RSVPs.** They are two questions and stay two questions: an event taking registrations through a link somewhere else is a real case. But they are combined with AND, so the state that was open was an event that **accepts registrations and shows no button**, which nothing warned about and nothing broke. The tick greys while registrations are off, the same treatment Add to calendar already had, and the save skips it on its own reading of the stored value rather than trusting a disabled control to post nothing.

**A fifth message: this event is back on.** Cancelling told everybody registered. Reinstating told them nothing, so the only way to learn it was on again was to go back and look at a page they had no reason to open. **It goes out whether or not the date moved**, and where it moved it says what the date is now, in the same message. It leads on the event being **on**, which is why it is not the "something changed" message: a date that moved means nothing to somebody who thinks the thing is not happening. **It carries a cancel link**, because a registration made for a Wednesday and reinstated onto a Thursday is a commitment nobody re-made. **Subject to the consent rule**, through the same confirmation cancelling uses: only an explicit yes sends, and it fails closed.

**And it settles an ordering that was two paths.** Reinstating and then changing the date offered a change notice; changing the date and then reinstating offered nothing at all, because reinstating had no prompt. Reinstating always asks now. Doing it the first way is still two messages, and that is correct: two things happened and the person is told about both. What is gone is the case where neither was sent.

**The duplicate-to-another-date control copied a different event from the one it named.** The screen worked out which event to copy one way and the button behind it worked it out another, and on a series holding several recurrence groups the two disagree. Four series hold several distinct events. One rule now, asked by both.

**3.72.0 was released correctly and the site did not offer it.** Every static question passed: the release was real, the tag was right, the asset was named correctly, `asset_url()` accepted it, and `inject()` would have offered it. What was wrong is that `inject()` never saw any of it.

**`latest()` has taken a `$force` argument since 3.70.0 and nothing has ever passed `true`.** Both call sites ask without it, so every answer this plugin has ever given about whether an update exists came out of a twelve-hour cache that only an install could clear. **A dead parameter is invisible to every check this project had:** the linter proves it parses, the callable audit proves the arity matches, and neither can ask whether anybody ever uses it.

**WordPress's own "Check again" could never have worked, and both build scripts said it would.** It calls `wp_clean_update_cache()`, which deletes `update_core`, `update_plugins` and `update_themes` and does not touch `sfaf_updater_release`. So the forced check fires our filter, our filter answers from its own cache, and WordPress is told what it was told twelve hours ago. The closing line of `publish.sh` and `build-zip.sh` promised "or at once from Dashboard > Updates". That was false and somebody acted on it.

**There is a control now, on the Plugins screen.** **Check for updates**, beside Deactivate, gated on `update_plugins` and behind a nonce. It asks GitHub whatever the cache says, clears WordPress's own transient so its check cannot short-circuit on `last_checked`, re-runs that check, and returns to the Plugins screen saying what it found. It is on the Plugins screen and not in caladmin because updating the plugin is an administrator concern, which is the 3.27.0 rule.

**It names both versions, and it tells "no update" apart from "the check failed".** Those are different facts: one means wait, the other means look at the network, and a control that answers both with silence is the thing that made this hard to diagnose in the first place.

**Installing a plugin clears the cache now, not only updating it.** `forget()` asked for `'update' === $options['action']` and nothing else, and **uploading a zip on the Plugins screen is `install`**. That is how every release before 3.70.0 reached this site and how 3.72.0 reached it in the end, so the route used most often was the one route that left a stale answer behind. It no longer asks whether the upgrade succeeded either: a half finished install is exactly when the cached answer is least trustworthy, and clearing costs one request on the next check.

**The forced check has no `delete()` in front of its fetch, and that is deliberate.** It had one, and it had to come out: clearing the cache first makes an unforced `latest()` fetch anyway, so the `true` became decoration and a regression that dropped it changed nothing observable. The checker below plants exactly that, and it went uncaught until the force carried the whole job. Nothing is lost, because every path through `latest( true )` writes the cache back.

**`.claude/updater-test.php` runs the updater rather than reading it.** WordPress and the network are stubbed and the class is the real file; the transients are an array the test reads back afterwards, so "the cache was cleared" is observed. It plants six regressions and requires all six caught, including the one that is this fault exactly. Two of the six were missed by the first version of the checker and both assertions were rewritten until they failed on purpose.

= 3.72.0 =

**The cancel dialog offered two buttons reading "Cancel the event" and "Cancel the event".** With nobody registered, the two answers that dialog exists to tell apart, "and email them" and "and do not", are the same sentence. There is one action now, and the way out beside it is called **Go back** rather than "Leave it alone", which read as a third thing to do to the event.

**No overlay in caladmin was ever bespoke, and what they were missing was not Escape.** Every one has been a real `<dialog>` opened with `showModal()` since 3.42.0, so Escape, the focus trap and the inert page have always been the browser's. What a modal `<dialog>` does not give is light dismiss: a click on its own backdrop does nothing at all. That is added, on the same test the public dialogs have used since 3.70.1. The recurrence scope dialog is deliberately left out, because it treats dismissal as "leave the editor" and a stray click outside would navigate somebody away mid-edit.

**Remove on the events list promised something the code has never done.** It read "Nothing else changes and nothing brings it back". The action is `wp_trash_post()`, which is one click to undo, and it is refused outright on a live event with registrations. That refusal has existed since 3.36.0 and the row did not know about it, so the flow was: agree to a deletion, arrive somewhere else, read that it did not happen. The row asks the same question the server does now and offers **Cancel instead**, pointing at the event page. The test costs no extra query.

**"Cancel this event" is a destructive control and now looks like one**, solid #c0392b with white ink, which is the DESTRUCTIVE weight in the control standard. 3.42.1's closed disclosure stays: being reached deliberately is bought by the fold, not by the ink, and there was never a reason both could not be had.

**A cancelled event said so on its own page and nowhere else.** The exclusion rule only removes the ones somebody chose to hide, so the default answer, and the recommended one, rendered exactly like a live event on the list card, the month grid and the sidebar. All three carry the word **Cancelled** above the title. The word, never the colour: six of this calendar's ten category hues are already reds, so a red card is not reliably distinguishable from a card in the Red category beside it. Not the closure treatment, which says the office is shut on a day and is a fact about the day.

**A second message box, for the people registered and nobody else.** The box that existed is public: it renders on the event page for anybody who arrives at the address. The new one is in the email and nowhere else, and it is a **second step of the confirmation** rather than a field on the form, so "I wrote a message for the registrants and then chose not to tell them" cannot happen. The box cannot be reached except through the answer that sends it, and the handler asks that answer again before storing anything.

**"Remove from the calendar", beside "Put it back on".** The visibility question was asked once, at the moment of cancelling, and never again, so somebody who left an event listed for the people who registered and wanted it gone three weeks later had one route: reinstate it and cancel it a second time, which runs back through the prompt offering to email everybody. The new control writes one meta key, cannot send anything, and refuses on an event that is not cancelled. **It is not the private setting.** A private event is unlisted and still reachable because the URL is the credential and somebody was given it; this is unlisted and still answering at its address with the cancellation notice, which is what somebody arriving from an old email needs to see.

**Tick boxes on the bulk publish, all ticked.** Publishing everything is still one press. Ineligible rows carry no tick at all and say which of the four rules they met, because a disabled checkbox still reads as something that could be ticked if you found the right way. Individual selection can only narrow: the posted set is intersected with what the rule says is publishable, in that order, so it can never become a route to publishing a past date or a submission.

**Two FAQ set controls, one of them an empty box with a title.** The card above the form was the no-script fallback's wrapper, and the script hid the form inside it and left the heading and its "Manage sets" link on screen with nothing under them. The form stays, because it is the whole no-script path; the card is gone, and "Manage sets" moved beside the live picker.

**caladmin's public contact box stored what was typed and nothing read it.** It wrote `_uc_public_contact`, the single open box the community form had before 3.47.0, and the reader prefers the three-part answer that form has written ever since. So on every community submission from 3.47.0 onwards, a manager could type in that box, save, reload, and see their own text still in the box while the event page showed something else. It is three boxes now, writing the three keys. The old value is shown read-only, and only where it is still the one being used.

**The FAQ editors, and what is and is not claimed.** The exception that stops them starting is still not named: there is no browser here, and until now the catch swallowed it with no output at all. It logs through the same channel every other initialiser uses, which is what makes the next attempt possible. The load pass goes through the same deferred task the Add pass uses and retries on a short backoff rather than guessing at one turn. **This is not asserted as the fix.**

**Five-minute steps on the time fields.** A time off the boundary is refused by the browser, before the form posts, which is what the attribute means; rounding would change somebody's answer without telling them. A control already holding such a time does not get the attribute, so an imported event at 6:07 stays editable instead of becoming a form nobody can submit.

**The staff request form asks the same repeat question caladmin does.** The five-option select could not say "every Wednesday", because "Every week" names no day, and could not say "Tuesdays and Thursdays" at all. Both screens share one renderer and one reader now. **The pattern is captured and not armed:** it is stored under keys of its own, never under the key the generator reads, and nothing on that path creates a post. The approver sees it filled in on the editor and presses the button there. Hiding the fields on "it happens once" needed no code at all, which is itself the answer to why the old select did not.

**The series is asked first on every screen.** caladmin asked at the top on a new event and two thirds of the way down on an edit. The prefill offer does not move: filling a blank form in from a series is a convenience, and offering to write over an event that already has content is not.

**"Use this image" on the pending row.** A submitted picture has always been shown there and has never been the event's own, so using it meant downloading it and uploading it again. The attachment id is read off the event and never from the form. Cards crop to 16:9 and the outcome message says so, rather than leaving it to be found on a published event.

**Uploads under 1200 pixels wide are refused**, naming the minimum and the width that arrived, and the minimum is on the form before a file is chosen rather than only in the refusal.

**Three things about submissions.** Who is told that one has arrived is a setting now, rather than everybody who happens to have Admin on the calendar, which was a consequence of who had been given a role rather than a decision anybody took. The "your event is published" notice is community submissions only, checked in the sender as well as on the control. And there is a rejection notice, with an optional note, **unticked by default**, because a message that goes out because nobody untangled a default is not a decision anybody took.

**Help icons on series, teams and private events**, extending the six that already existed rather than adding a second style.

= 3.71.0 =

**A series' upcoming drafts publish in one press.** The import creates 287 drafts across 32 series and the schedule screen could show them and not act on them, so approving a series meant opening 270 events one at a time.

**One already existed, and it is worth knowing where.** WordPress's own Events list at **/wp-admin/edit.php?post_type=uc_event** has bulk actions, and `uc_event` never set `show_ui` or `show_in_menu` to false, so the menu entry has been there all along under **SFAF Calendar**. It works, and it is the wrong tool for this: it knows nothing about a series, nothing about upcoming versus past, and nothing about which rows are somebody else's decision to make.

**What the new one covers, said before it is pressed.** The button carries the count, the line above it carries the date range, and both name what is being left out. Nothing is discovered afterwards.

**Upcoming only, and an event with no date is not upcoming.** Publishing a past date puts a session that has already happened on the public calendar. The import also leaves events with no date at all, for series whose schedule is filled in later; publishing one of those would put it on no calendar while removing the draft badge that says it still needs a date. Both are skipped and both are counted in the line above the button.

**Four kinds of row are never touched**, and three of them are somebody else's decision rather than a state: anything that is not a draft, which includes a `pending` submission awaiting review; anything carrying source provenance, which a fetch owns; an import parked as a draft because it vanished at its source, where publishing would put back what the source dropped; and a submission. The Pending and Dismissed queues need no rule and get none, because `uc_imported` and `uc_dismissed` are not in `editable_statuses()` and this screen has never been able to see them.

**The imported events qualify, and the rule is written so they keep qualifying.** They were created by a script rather than through the event editor, so what they carry is worth stating: a `draft` status, a `_uc_event_date` and the series term. That is the whole of what this asks for, and the rule is written in terms of what an event **is** rather than how it was made.

**The set is decided again when the button is pressed**, not taken from a hidden field, because between the page rendering and the press a fetch could have run or a date could have passed midnight. `wp_update_post()`, not a direct status write, so `save_post` fires for every listener that cares about an event becoming public.

**`.claude/series-publish-test.php`** exercises the rule over fifteen cases without a database and plants five holes in it, requiring all five caught. The route whitelist caught the new action before the suite went green, which is what it is for.

= 3.70.1 =

**The registration dialog opened underneath the site header, and it was one cause with two symptoms rather than a z-index that needed raising.**

**What the second symptom proved.** The dialog was also centred on the page rather than the viewport, beginning above the fold on a short screen. It already declared `position: fixed; inset: 0` with `align-items: center` and `max-height: 90vh`, and an element like that **is** the viewport by definition: it cannot begin above the fold and it cannot be page-centred. So `fixed` was not resolving against the viewport, which leaves exactly two possibilities, and **both of them also explain the layering**. Either an ancestor became the containing block for fixed descendants, which it can only do by creating a stacking context, and that traps every z-index inside it; or the theme overrode `position` to something for which z-index is inert. One cause, both symptoms.

**Why no number here could have fixed it.** The overlay already carried `z-index: 99999`. If it is trapped in a stacking context, that number is compared only against its siblings inside the trap and never against the header; if `position` is no longer fixed or absolute, the number does not apply at all. Raising it would have been a guess against a value that is not readable from this repository, because the header belongs to the resources.sfaf.org theme and the theme is not in it.

**Both modals now open in the top layer.** The registration dialog and the follow dialog are `<dialog>` elements opened with `showModal()`. A top-layer element is painted above every stacking context in the document and **its containing block is the viewport whatever its ancestors do**, so it is immune to both candidate causes at once and to whatever the theme actually declares. A browser without `showModal()` gets the `open` attribute instead and behaves exactly as before.

**Three things come free with it,** and the dialog should always have had them: focus moves into the dialog, the rest of the page becomes inert, and Escape is handled by the browser. The user-agent box a dialog carries is fully overridden so the dim still fills the viewport, and the fade is preserved by adding the visible class one animation frame after the element stops being `display: none`.

**A check was added because nothing else in the build would notice a regression.** There is no browser here, so a `<dialog>` quietly becoming a `<div>` again, or `showModal()` becoming a class toggle, would pass lint, the callable audit and the whole suite while putting the dialog back under the header. `.claude/modal-toplayer-test.php` plants eight regressions against itself and requires all eight caught.

= 3.70.0 =

**The plugin updates from inside WordPress now, from GitHub releases on a public repository.** The Plugins screen shows an update notice like any other plugin and Update now does the rest. **No token is stored on the site**, which is the whole reason the repository serving releases is public: a private one would mean keeping a credential on the site to fetch its release assets, and that is the thing this arrangement exists to avoid.

**One zip, not two.** The update downloads the release **asset**, which is the exact file `.claude/build-zip.sh` already produces and which is what gets uploaded by hand today. It is deliberately not GitHub's auto-generated source zip: that one is named after the tag, carries the whole repository including the checkers and the documents, and unpacks to a folder WordPress would treat as a different plugin. `SFAF_Updater::asset_url()` accepts only an asset named `sfaf-calendar-X.Y.Z.zip`, so **a release published without one reports no update rather than installing the wrong thing**.

**One version, not two.** The installed version is `SFAF_VERSION` and the available one is the release tag with any leading `v` removed. Nothing in the updater holds a version of its own. **`build-zip.sh` now refuses to build unless the three places version discipline already bumps agree with each other**, so the tag is derived from them rather than being a fourth place to keep in step. That check would have caught a zip named for a version the plugin did not claim, which mattered little while the zip was uploaded by hand and matters now: a mismatch means either an update nobody can install or one that installs and then offers itself forever.

**What it will not do.** It never downgrades: a release older than what is installed is silently not an update. It caches for twelve hours, and for one hour after any failure, so a rate limit or an outage costs one slow admin page rather than one per page load. It registers on every request rather than in wp-admin only, because WordPress runs its update check on cron, which has no admin screen.

**Two things came out of the repository.** `apiv2-public-gfmp.json`, GoFundMe Pro's own 2.2 MB OpenAPI spec, is now kept on disk and ignored: it is their document, it carries no licence granting redistribution, and it was a third of the repository's weight. The comments citing it as the source of truth for the endpoint and the Campaign schema remain accurate as provenance. Real staff addresses used as test fixtures were replaced with role addresses, and a developer's Windows username and local paths came out of `guard-test.sh`.

= 3.69.0 =

**No plugin code changed in this release.** What it carries is the tooling for a one-time import of the old Events Calendar, in `.claude/import/`, which is not part of the plugin and is not in the zip. The zip differs from 3.68.1's only in the three version strings, so installing it changes nothing on the site. It exists so that the version running while the import runs is the version this repository describes.

**What the import is.** resources.sfaf.org runs The Events Calendar and is being replaced by this plugin. Mark's list of organizers and series is the authoritative structure; the four WXR exports in the project root are a source of detail for the rows that match it, and nothing more: description, start and end times, venue, organizer and featured image. Where they disagree the list wins, and where the list has no match the export row is dropped. **A second pass folded in Mark's own copy for twelve events and the Stonewall Project's 2026-27 group info sheet for nine more**, which supersede the export. 32 series, 39 events, 287 draft event posts.

**The export is reliable for times and descriptions and unreliable for whether something still happens.** Among the twenty rows this import matched, **every rule that carries an end date has already passed**, the latest being 2026-07-01. So dates are generated only from a live schedule, meaning either the list states a day and time or the export's rule has no end date at all, and everything else gets its times and its description with no dates. Eighteen events take their dates from a schedule stated on the list or the sheet, seven from a live rule in the export, and 14 are handed over for Mark to schedule. **No placeholder description survives**: every one the export had has been superseded.

**The most recent occurrence is what the export means, not the first one.** El Salon's first twelve rows say 09:30-11:00 and its most recent says 12:30-14:00; Express Yourself's early rows are Thursdays and its most recent is a Monday. Reading the first row in the file would have imported the oldest fact in it.

**Two groups meet on the first and the third Wednesday**, which the plugin's `monthly_nth` cannot say in one pattern because it carries one ordinal. The second rule arrives the way a hand-picked date does, and **the seed is the earliest date either rule produces** rather than the earliest the pattern produces: `merge_dates()` discards every explicit date on or before the start, and rightly so, which means anchoring on the pattern alone would have silently dropped a third Wednesday falling before the first one.

**Everything is created as a draft**, and an event with no schedule still gets an empty `_uc_event_date` row rather than none, because the caladmin event list orders by that meta key and a query that orders by a meta key drops every post with no row for it. An event Mark is meant to fill in has to be in the list to be filled in.

**Nothing the import creates can cause mail to anybody, and the address it would otherwise have added is one nobody typed.** `SFAF_Reminders::notify_entries()` reads four sources and the import writes none of the three that are lists. But `wp_insert_post()` defaults `post_author` to whoever is logged in, and **the creator is on an event's notification list unless the event says otherwise**, so running this from a browser would have put the administrator who ran it on all 287 lists. **The opt-out also does not travel to an occurrence:** `SFAF_Recurrence` copies `post_author` onto every generated date and its `$copied_meta` carries none of the notification keys, which is right for a manager building a group by hand and wrong here. So every post, seed and occurrence alike, is opted out and then **asks `notify_list()` who is left**, because an assertion about which keys are written is a different claim from an empty list. `.claude/import/no-mail-check.php` proves the same at build time and plants five violations against itself. Addresses inside descriptions are text on a page that no send path reads, and they stay.

**The clear removes test events made by hand and nothing else, and status alone does not tell them apart.** Rows in the Pending and Dismissed queues carry the custom statuses `uc_imported` and `uc_dismissed`, which the first version of the counting code did not even name, so it reported both queues as empty when they were not. Worse, **an import that vanished at its source is parked as an ordinary `draft` and a submission awaiting review is an ordinary `pending`**, so both look exactly like a hand-made test event until the provenance meta is read. The clear now spares five kinds of row and names each one it spares, with its id and its reason. **Trashing one would have been data loss rather than an inconvenience:** `SFAF_Sources::all_statuses()` includes `trash` and `find_existing()` searches with it, so the next fetch matches the trashed row, finds `trash` is not in `updatable_statuses()`, counts it untouched and moves on **without creating a new one**. A trashed import does not come back by fetching; it comes back only if somebody restores it by hand, and if WordPress empties the trash first the row and every decision on it are gone. The plugin states this itself, in the paragraph explaining why an expired pending row is dismissed rather than trashed.

**Clearing the existing events is a separate mode that must be asked for by name, and it trashes rather than deletes.** `wp_trash_post()` is what the plugin's own remove actions already use, so a wrong call is one click to undo. The importer creates no category ever: every category in caladmin carries a brand color and an icon somebody chose, and one created here would carry the defaults and look like a mistake on the calendar.

= 3.68.1 =

**A layout fault 3.68.0 shipped on both request forms, and nothing else.**

**What was on screen.** Every section's heading and its first field rendered side by side: "When" on the left with the Date input squeezed into a roughly 40px column at the right, "Where it happens" with the Venue select the same, and a large empty area under the heading. Every field after the first was correct and full width, and both forms had it.

**What was causing it was a float that had never run before.** The section's `<legend>` was floated, to lift it out of the fieldset's top border so the panel's edge could draw unbroken. That rule was written in 3.66.0 and **was inert from the day it was written**: the only two fieldsets carrying it also carried `.uc-field`, which is `display: flex`, and **float computes to `none` on a flex item**. So the float sat there doing nothing for two releases.

**3.68.0 switched it on, and did so as a side effect of the previous fix.** The remedy taken for the earlier cascade fault was to stop `.uc-request-card fieldset.uc-field` matching a section, by taking `.uc-field` off it. That reset was three properties, `border`, `padding` and `margin-inline`, and none of them mattered here. **The class carrying it was also the section's `display`.** Removing the class to escape the reset removed the layout mode that was holding the float inert, which is the same fault one level along, exactly as it was predicted to be.

**Why a live float broke the first field and only the first field.** Every first child of every section on both forms is `.uc-field`, `.uc-field-row`, `.uc-check` or `.uc-check-grid`, and all four are flex or grid containers. Such a container establishes its own formatting context, so it **refuses to overlap a float and is placed beside it**, and a flex item's `min-width: auto` stops it shrinking away, so it overflowed into a narrow column at the right. Only the first child sits at the float's vertical position; everything below it flows normally, which is why Start and End, the address parts and the venue website were all correct.

**The fix is that a section now declares its own layout mode.** `.uc-form-section-group` is `display: flex; flex-direction: column`, which stacks the legend above the fields inside the padding exactly as the float was trying to, and does it by **making a float impossible rather than by arranging for one to be harmless**. The float, its `width: 100%` and the clearfix that existed only for it are gone. No gap was added: the children keep their own margins, so the mechanism changed and the spacing did not.

**Nothing else changed.** The section structure, the seven named sections on the community form, the heading scale, the tint and the hairline are all as 3.68.0 left them. The type-scale sweep still passes and no new step exists.

**What the new check proves and what it does not.** `.claude/section-layout-test.php` reads `portal.css` with comments removed by a real pass, and asserts that no rule floats a legend inside a section, that a section declares its own `display`, that the value is one in which a float on a child is inert, and that the first child of all eighteen sections across both forms really is one of the flex or grid classes this fault needs. Putting either half of the fault back makes it fail by name. **It computes no geometry and opens no browser**, so it cannot say the Date control is full width, that no hint wraps one word per line, or that the panel edge draws unbroken. That is `TESTING.md` 1.39, including at the 770px the forms render at inside a host page, and it is the only thing that settles it.

= 3.68.0 =

**Six changes to the two public forms and the pending queue, and two investigations reported rather than acted on.**

**Both request forms are one sequence of sections with a boundary you can see.** The complaint was one white box after the next with no clear break, and it turned out not to be the type: 3.66.0 fixed that and its fix is untouched. It was a **cascade fault of the shape this project keeps meeting**. Two of the staff form's six sections were fieldsets carrying both `.uc-field` and `.uc-form-section-group`, and `.uc-request-card fieldset.uc-field` at (0,2,1) sets `border: 0; padding: 0` over the section rule's (0,1,0), so the picture section and the team section drew no divider and had no top padding while the four `<h2>` sections beside them did. **There were also three spellings of a section**: an `<h2 class="uc-form-section">`, a `<fieldset class="uc-field-group">` and a `<fieldset class="uc-form-section-group">`. There is one now, on both forms, and no section carries `.uc-field`. Each is a **neutral panel**, one tint and one hairline, the same on every section of both forms, which is what DESIGN.md's decoration rule requires of a surface that groups: it carries no information and cannot be read as a state, a category or a warning. **No new type step and the type-scale sweep still passes:** the legend keeps the Subhead step 16/600 that 3.66.0 settled on.

**The community form had the same fault for a different reason** and is fixed with the same mechanism. Three of its groups were panels and the eleven fields between them were one undifferentiated run with no headings at all. They are now The event, When, Cost and who can come, Signing up, Event Image, Questions people often ask, and Anything else we should know. **One field moved:** the capacity now sits beside the registration link rather than between Cost and Age, because those two are one question and the old order was the order the fields were written in.

**The staff form asks which series first, and the picture chooser says what that means.** It was the fifth question, after the description and the categories, so a requester met the picture chooser before they had been asked the thing that answers it. Choosing a series now shows that series' photo on the "nothing chosen" row of the picker and on the closed control, **only while no picture has been chosen**: picking one leaves both alone. **Nothing is copied.** The radio still posts 0, because an event in a series with no picture of its own already resolves to the series photo every time it is displayed, and a copy is a value that goes stale the moment somebody changes the series photo.

**Why that is not the same code New Event runs, which is a fair question.** The two screens share the question and the rule, not the control. caladmin fills six fields through two hidden inputs and a wp.media preview; this form has neither, because the page has no logged-in user, and its picture control is a list of radio buttons over the calendar folder. What they do share is `SFAF_Series::prefill_data()` and `SFAF_Series::image_url()` for the data, and the rule that a filled field is never overwritten.

**An event that arrives with no location says so on the pending queue.** Neither form requires one and that stays deliberate: both land in Pending and a manager decides before anything is published. What was missing is that the row said nothing, so an event with nowhere to be looked exactly like one with a venue. It is **the state the imports already had, extended rather than duplicated**: the same amber row, the same mark, and the wording from the same `field_phrase()`. **An online event is not missing a location**, which also corrects the import path, where every online campaign would have carried the mark forever.

**Two copy corrections on the staff form** and one on the event page. The Location card's first label reads **Location** rather than Where; the picture section is **Event Image**; and a cancelled event now says "If you have a place, you do not need to do anything" rather than the awkward past tense.

**The private event copy is three sentences.** It ran to five and two of them described the mechanism: which surfaces the event is left out of, and what happens to the old address if it is switched back off. Neither is a thing to do or a thing that happens to the reader. The **third sentence is why this is not one sentence**: ticking the box breaks a link somebody may already have sent, and nothing else on the screen would say so. Both screens that carry the control now use the same wording. **"This choice is yours permanently. A fetch never changes it." was already conditional on the event being imported and is unchanged.**

**A private event page already emits noindex and nofollow**, and always has. `SFAF_Privacy::robots()` sets both on `wp_robots`, the event is stamped with Yoast's own noindex meta, and it is filtered out of the core sitemap and Yoast's. Nothing was added, and the copy says nothing about it because it is not a decision a manager makes.

**Two investigations, reported and not acted on.** What caladmin's Listing detail card is, field by field, and what its contact box should do now that it reads a key no form has written since 3.47.0. And two things on the event editor reached from Pending: why the FAQ editors do not start until somebody presses Add, and why there are two FAQ set controls on that screen.

**Proved by running.** `request-form-test.php` reproduces the cascade fault by name if a section takes `.uc-field` back, and checks the series is asked before the name and before the picture chooser. `pending-queue-test.php` renders the queue and reads the rows: an event with no location carries the mark, one with a location does not, and an online one does not; removing the new call makes it fail by name. `request-picture-picker-test.php` renders the picture section for real.

= 3.67.0 =

**Four changes and an investigation. Two of them are things 3.66.0 found and stopped short of.**

**The community submission form takes up to five email addresses, and the submitter's own is the first of them.** An external organizer who wants two colleagues told when somebody RSVPs can now say so. **It is the About you field**, which is the one that feeds `_uc_request_email` and, at approval, the event's notification list; the "Contact for the event" email is a separate field printed on the public event page and is untouched. An **Add email** button adds a box, and **at five the button is not there at all** rather than greyed out. All five go on the notification list when a manager ticks the registrations box at approval, so they get the alert on every registration and the list of everybody registered on the morning of the event. They get nothing else: no caladmin access, no edit rights, and no message from the form. **The confirmation of submission still goes to the first address only**, because that is the person who filled the form in.

**Rate limiting is unchanged and five addresses are not five allowances.** The limiter counts POSTS, keyed on the client address and on the campaign, and neither key has ever been an email address. One submission is one count whether it names one address or five, and the form still sends exactly one confirmation and one message per approver.

**The pending row shows the contact details the submitter actually entered.** It read the single open box 3.47.0 replaced with three fields, and the public form has not written that key since, so **"Contact for the listing" rendered empty on every community submission for nineteen releases** and the name, email and phone were invisible to whoever approved it. Nothing was lost: the values were stored the whole time under the three keys the event page already reads. The panel now asks `sfaf_event_public_contact()`, the same reader the event page uses, so the two cannot disagree. **The approval prompt names every address the submission asked to have told**, because a tick that puts five people on a list carrying registrant names has to say who they are.

**Event links open in a new tab, everywhere the calendar renders.** The month grid day link, the sidebar row, the card's picture, the card's title, the View event button, the compact card and both "in this series" lists. `target="_blank"` and never `_top`, which would replace the whole window the embed is sitting in. **`rel="noopener"` and deliberately not `noreferrer`:** noopener is the security half, and noreferrer would also suppress the Referer header, which is exactly what the event page reads to work out which calendar somebody came from. Every link a person can reach carries a visually hidden **"(opens in a new tab)"**, which is the pattern the external marker already used here. **The back link is untouched.**

**Two copy corrections.** The staff form's picture picker shows **one name per row**: the picture's title where somebody gave it a real one, its file name where they did not, decided by the check 3.65.0 added rather than by a second rule. It was drawing both, which made a titled picture two lines and an untitled one a line with a gap above it. And the staff form says **"People need to RSVP"** rather than "People need to register", with the section heading and the confirmation email's summary row moved to match, since the control this mirrors in caladmin says **Accept RSVPs**.

**Proved by running.** `.claude/repeater-max-test.js` is new: it slices the real `initRepeaters()` out of `portal.js`, presses the add button until the cap and asks whether the button is still **in the document**, which is a question no class or `disabled` check would answer. Replacing the removal with `disabled = true` makes it fail by name. `submissions-test.php` runs the real validator over six, duplicate, empty, malformed and nested-array addresses, and reads back what the approval screen would; `request-form-test.php` reproduces the empty contact block if the stale key is put back; `embed-modes-test.php` counts the five event anchors and fails if one loses its attributes or gains a `noreferrer`.

**An investigation, reported and not acted on.** What both public forms require today, field by field, which is enforced on the server rather than only in markup, and what a manager loses when each optional field is empty. Nothing was made required: that is Mark's call, and a field somebody cannot answer means an abandoned form rather than an incomplete submission.

= 3.66.0 =

**Five changes and an investigation. One requested change was stopped before it was built, because checking its premise found the premise was wrong.**

**The four pages that build their own document have the calendar's favicon.** The staff request form, the community submission form, and the notice page the follow links and the registration cancel link land on all write their own `<head>` and call `wp_head()` nowhere, which is why they loaded no stylesheet until 3.44.0 and why they had no icon from either direction. **The icon is the calendar's own**, not the site's and not the theme's: `public/images/favicon-caladmin.*`, bundled with the plugin and drawn from the calendar glyph the plugin already uses, in Dark Gray on brand Yellow. caladmin declared those three links inline with a comment saying the public forms did not need them because they went through `wp_head()`. They do not. There is one declaration now, `sfaf_favicon_links()`, and all four documents call it.

**"Campaign" is "Event Series" in the Get a form link dialog.** The dialog only. **The three names are deliberate and are now recorded in a comment where the dialog is built**, so nobody reconciles them later: the public calendar's filter row says **Groups**, because a visitor should not have to know this calendar has a taxonomy; caladmin says **Series**; this dialog says **Event Series** because somebody there is choosing which kind of thing a link points at, with a staff form link directly above it that points at no series at all. "Campaign" was a fourth name and named nothing in the product.

**The staff request form can name the team that should be able to edit the event.** Teams only, never individuals: membership resolves at read time, so adding somebody to a team hands them every event that team owns and removing them takes it back, while a typed address is a string nobody maintains. The requester sees team names and never who is on them. **Up to two, which is the cap caladmin already enforces**, asked of `SFAF_Teams::MAX_PER_EVENT` rather than written out a second time, and going over it is refused by name rather than silently trimmed. The team is written through `SFAF_Teams::set_access_for_event()`, the same call the portal makes, so there is one definition of a valid access list. Showing the list costs nothing: the form is only ever rendered after a token sent to a verified sfaf.org mailbox has resolved.

**Both request forms have a visible heading hierarchy.** They used the type scale correctly and the scale is not flat. What they did was **reach for the wrong step**: a heading over a group of fields was set at the field-label step, 13/600, which is the step the labels of the fields inside that group are already at, so a heading and the thing it headed rendered the same. The Subhead step, 16/600, sat unused between Field label and Section. It is what a group heading is for and it is what they use now. **No new size was introduced** and the type-scale sweep still passes. One related fault came out with it: `.uc-field-group > legend` declared `font-weight: 700` over a legend already carrying 13/600, making the rendered pair 13/700, which is not a step at all. The sweep could not see it because the size and the weight are in two different rules.

**"All Events" on an event page took visitors to the sfaf.org homepage, and it was not an unfilled setting.** The referrer chain was working, and that is what produced it. The event page is on resources.sfaf.org and the calendar is a page on sfaf.org, so every real click through to an event is **cross-origin**, and every current browser then sends the **origin only**: `https://sfaf.org/`, with no path. That passed every check, and the function handed back the site root. **An origin with no path names no calendar page**, so it is now treated as no referrer at all and the configured Calendar home URL answers instead. **Filling that setting in is the other half of the fix and neither half works alone.** A same-origin referrer is untouched and still carries its full path.

**One requested change was stopped rather than built.** Up to five organizer addresses on the community submission form, feeding the event's notification list. Checking which field feeds that list found it is **Your email under About you**, the submitter's own address, not the "Contact for the event" email, which is a public field printed on the event page and reaches no list. The labels are what is wrong there, and that is a decision rather than a build. **Two related findings came out of the same check:** the pending row does not show all the submitted addresses, and it does not show the contact block at all, because it reads a meta key the public form has not written since 3.47.0 split that field into three.

**Proved by rendering and by running.** `.claude/self-built-pages-test.php` is new: it renders the icon links, checks the files they point at exist, asserts all four documents call the one emitter and none writes its own, and drives the real referrer function over the exact headers a browser sends. Removing the new condition makes it reproduce the reported symptom by name. The team picker's assertions are in `request-form-test.php`, and writing them found two faults in the new code: a nested array in the post body threw a PHP warning, and the first draft asserted the wrong guarantee.

= 3.65.0 =

**The staff request form's picture chooser is a picker rather than a grid. Nothing else on that form, and nothing on the community form or in caladmin, is touched.**

**What it was.** Every picture in the calendar folder, drawn at once, as a grid of thumbnails at least 140px wide, capped at 60. Each tile was a hidden radio button with the image as its control and a ring around the chosen one, and the picture's title appeared under it only when the title was a real one. That is workable with a dozen pictures and unusable with two hundred, and the folder only grows.

**What it is now.** A closed control showing the picture currently chosen, or saying none is. Opening it reveals a search box and a scrollable list. Each row is a thumbnail, the picture's **file name**, and its title above that when somebody actually gave it one. Typing narrows the list; clicking a row chooses that picture and closes the panel.

**The file name is on every row, not on hover.** Two photographs of the same event look alike at 64px and the file is often the only thing that separates them. It is also what the search matches.

**It works with no JavaScript, and that is the requirement this form is built around.** The page is reached by a link and used by staff who are not logged in to WordPress, so a control that posts nothing without scripting would be a form somebody cannot complete. The picker is a native `<details>` full of radio buttons: the browser opens and closes it on its own, and the radios post whether it is open or shut. Script adds exactly two things, the filtering and closing the panel on a choice, and creates none of the control. The search box is hidden until portal.js can act on it, because a box that cannot filter is a control that lies. Same shape as the dashboard's form-link disclosure and the notification picker.

**Nothing was imported to build it.** It is the `.uc-picker` disclosure that the notification picker and the teams screen already wear, so the trigger, the search box, the panel and the list are the definitions 3.64.0 established rather than a third answer to the same question. The narrowing is the same `initFilterLists()` the teams screen uses. What is new is the difference between a row of people and a row of pictures, and nothing else.

**The calendar folder restriction is unchanged**, and so is what the form submits, how the image is stored, and the cleanup on the way in. Only an attachment already in the calendar folder can be chosen, and `validate()` checks that again rather than trusting the list.

**A picture nobody titled no longer gets a caption made out of its file name.** WordPress sets an attachment's title from its filename on upload, and the test written for this release found that the guard against that has never caught the shape its own comment used as the example: "img 2847 final v3" is nine letters against five digits with no extension, and slipped through both tests. Given the file, that is an exact question rather than a guess: take the file, drop the extension, turn dashes and underscores into spaces, and compare. The two older guesses stay as the net for a title somebody typed that reads like a file anyway.

**Proved by rendering it.** `.claude/request-picture-picker-test.php` is new and renders the control, parses what came back, and asks the no-script questions as outcomes: there is a set of radios named `image_id`, one per picture with its own attachment id, none disabled, none hidden, all inside a native `<details>` that starts closed. Three faults were planted and each was caught by name, including a file name moved into a `title` attribute where it would only appear on hover.

= 3.64.2 =

**Two corrections. The series prefill card now shows the picture it is offering and the picture it has just applied, and Add to calendar sits under the tick that decides whether it means anything. No behaviour changed anywhere else.**

**"Fill these in" left the picture invisible.** The Image option wrote the series image into the event's two hidden fields and stopped there, so the preview under **Featured Image** stayed empty and the tag beside the label went on reading "Placeholder". The button reported filling six things in, and the one thing that is a picture was the one thing nobody could see. It now looks exactly as it does after Choose Image: the preview comes up, **Remove** is offered beside it, and the tag reads **Event-specific**, which is what the picture now is. The image is COPIED onto the event, the same as the location, the times, the description, the category and the organizer, so a later change to the series image does not reach this event; **Reset to series image** on the Edit screen is what puts that back, and that is unchanged.

**The tag's words come from the tag.** The label the script writes is carried on the element by the server rather than spelled a second time in `portal.js`, so there is one definition of "Event-specific" and renaming it renames it everywhere.

**The image row on the card shows the picture, not the file name.** Every other row on "Is this part of a series?" shows its actual value: the address, the times, the description's opening words, the category and organizer names. The image row showed `prop-harm-reduction-1024x576.jpg`. It is now a small thumbnail, cropped to the 16:9 the cards use so a portrait file cannot make that row twice the height of the five around it. The file name is kept as the picture's alt text, so a screen reader still has the handle it had, and it is what the row falls back to if the picture will not load.

**Add to calendar moved to directly under RSVP in the Display card.** The order is now RSVP, Add to calendar, Donate, Social share, Follow the series. Ticking **Accept RSVPs** greys **Add to calendar**, and a control whose availability is decided by another belongs beside it. This trades a rough match to the order those things appear on the public event page, deliberately: nobody reads the card with the event page open next to it, and plenty of people tick Accept RSVPs and then wonder why a tick four rows down went grey.

**And the greyed-out line says why before it says what happens instead.** It read "The calendar link goes out with the registration confirmation instead", which left an organizer to connect a grey tick and a sentence about email on their own. It now reads **"Because this event takes RSVPs, the calendar link goes out with the registration confirmation instead."**

**The rule itself is untouched.** An event that accepts registrations still shows no Add to Calendar button on its page, one that does not still shows it, and the save still skips the field on its own test rather than trusting a `disabled` attribute to survive.

**Proved by what the screen shows.** `.claude/prefill-image-test.js` is new: it runs the real `initSeriesPrefill()` out of `portal.js` over a document, presses the button and then reads the preview, the Remove button and the tag, because every check that could have been written about the WRITE passed for as long as the defect existed. `.claude/series-control-test.php` is the other half and holds the document to the real render, asserting from the rendered New Event page that the image field carries the hooks that test uses, that the five Display ticks come out in the new order, and that the note names the cause first.

= 3.64.1 =

**The series control on New Event has never worked, and this is the release that makes it appear. Fix only.**

**What was wrong, and it was one thing.** `render_event_form()` called the "Is this part of a series?" card 102 lines above the two lines that give it the list of series to show. Straight-line code, one function, no loop, so both variables were undefined at the call, PHP passed `null`, and the card's own `empty()` guard returned without drawing anything. **The card has never rendered on the New Event screen since it was added in 3.38.0**, 26 releases ago. The only symptom was two `Undefined variable` warnings, suppressed wherever `display_errors` is off, and a control nobody could find.

**Every static check passed, and would pass again.** The call is there, the method is there, the arity matches, the file parses, the callable audit is clean. Nothing was wrong except the ORDER of two statements, and order is not a property any of those questions can see.

**A second thing comes back with it.** The schedule screen's **Create a new event in this series** button carries the term in the URL, and its own comment says "The series arrives already chosen, which is the only thing this screen knows that the editor does not." It arrived nowhere: the query string was validated into a variable AFTER the dead call, and the only other reader is the dropdown further down, which renders on an edit and not on a new event. Same one ordering fault, second casualty. The button now does what it always said it did.

**The schedule screen's seed comes from the recurrence group.** The list of dates on that screen is scoped to the SERIES, but the pattern, the cadence controls, the times and the sentence describing the schedule all describe a recurrence GROUP. It read them off whichever event happened to be soonest. Assign a one-off event to a series by hand, date it before the next generated occurrence, and it becomes the seed, carries no pattern because nothing generated it, and **the pattern form stops offering a frequency for a series that plainly has one**. The sentence at the top said the same wrong thing. Display only: the save has always read the group rather than the seed, so nothing was ever written from the wrong event. It was already reachable through the WordPress admin metabox, and restoring the caladmin control makes it easy, which is why it is fixed in the same release.

**`.claude/series-control-test.php` is committed and decides the outcome by rendering the form.** It loads the real portal, invokes the real `render_event_form()`, captures the HTML and parses it, then asserts that New Event emits exactly one control named `series`, that it is a single `<select>` and not a multi-select in either spelling, that `?series=` arrives as the chosen option, that an id naming no term chooses nothing, and that the Edit screen still emits exactly one. **A grep for the call would have passed on every release since 3.38.0.**

**It also refuses a multi-select, and that is not hypothetical.** One series per event is enforced only in the control: both readers take the first term and the save writes a single-element array through `wp_set_object_terms()`, whose default replaces. A multi-select here would let somebody pick two and lose one on save, silently, with nothing logged. That is the categories fault of 3.8.0 and the organizers fault of 3.40.0.

**Both faults are planted and caught.** `series-order` puts the assignment back where it was, in two edits, because the fault IS a position and a single find-and-replace could only approximate it; `series-multi` turns the control into a multi-select. `.claude/plant-one.php` grew support for a multi-edit plant to make the first one faithful, and stages every edit before writing any, so a plant that cannot apply in full applies not at all.

**Nothing else changed.** One series per event, the recurrence group marker and everything scoped to it, the Edit Event control, the WordPress admin metabox control, and following are all untouched.


= 3.64.0 =

**One definition per kind of control, applied everywhere in /caladmin, and no Add to Calendar button on an event that takes registrations.**

**What was reported, three times, on three screens.** Controls were white on white with thin gray outlines, and controls that do different things looked the same. Choose Image, Add these questions, Add FAQ, Add category, Save Draft and Back all read as the same weight and none read as pressable; the Title and Capacity fields were faint borders on white cards. Two of the three reports were fixed by restyling the screen they were reported on, which is what made the third one arrive.

**The defect was not "this button looks wrong".** A secondary button was a white box with a 1px gray outline and an 8px radius. A text input was a white box with a 1px gray outline and an 8px radius. The only difference was font-weight. Meanwhile one kind of control had SEVERAL definitions and the one that reached the screen was decided by which wrapper it happened to sit inside: a text field's appearance was declared in **fourteen** places, **nine** of which handed it the decorative hairline (1.26:1) instead of a control boundary (3.33:1). The same `<select>` had a visible edge in the event editor and effectively none on the Users screen, in the filter bars and on the sign-in box.

**A secondary button has a surface now.** Brand Light Gray `#D1D3D4`, ink `#373433` at 8.22:1, measuring 1.50:1 against a white card and 1.39:1 against the page. White stays "you type in this, or it opens", which is the rule the public filter bar settled in 3.61.0; caladmin had the first half of it and not the second. The border stays and is still what carries the boundary, because a fill at 1.50:1 is a surface and not an edge. It cannot be mistaken for the disabled state, which in this portal is *lighter* than the page with *muted* ink.

**The vocabulary is four weights and a quiet fifth, and nothing else:** yellow primary (one per view), Light Gray secondary, solid teal utility, solid red destructive, and the link-shaped quiet actions that have no surface in any state.

**A select looks like a select.** It gains the end cap the public filter bar uses, a hairline just inside the right-hand end that makes that end part of the control, and the chevron turns over while the menu is open. That is the same idea as the public bar rather than a second answer to the same question.

**An option group no longer looks like a row of buttons.** The Repeats switch and the All events / My events switch were two copies of one control written 1500 lines apart, value for value identical; they are one declaration. The option card, a radio with its consequence written under it, existed three times, and one of the three was scoped to markup that has not existed for several releases, so the class read as a bordered card in the stylesheet and rendered as a bare list of radios on the screen. The cancel card's two visibility choices are proper option cards now, which is a visible change there.

**Focus is teal, everywhere.** Every field inside a labelled wrapper painted its focused border YELLOW, which is the one colour reserved for the single primary action on a screen, and it keyed on `:focus` rather than `:focus-visible`, so a mouse click lit it too.

**A disclosure has one mark.** There were three answers to "does this open?" in one stylesheet: the shared chevron element, a text triangle drawn in CSS, and, on seven summaries, nothing at all. "Change the pattern", "Extend the series", "Use this event's details on another date", "Contributor categories", the past-dates fold, the pending-queue field panel and "Get a form link" were lines of text with no marker. All of them carry the mark now, and the plain glyph is the default: the 28px ring belongs to a summary that IS a card's head band, and was previously the default that had to be undone by hand.

**Four rules were dead and read as if they worked.** `.uc-disclosure-chevron` was declared twice at the same specificity, so the cancel disclosure wore a white circle nobody chose and the rule saying otherwise lost silently. `.uc-role-help`, its toggle and its body were each declared twice. `.uc-organizer-add`, `.uc-organizer-add-body` and `.uc-organizer-row` were each declared twice with different values. `.uc-inline-form` was `display: flex` in one place and `display: inline` in another, so the Users screen's add-user form had been spacing its select and its button with a space character.

**`.claude/control-standard-audit.php` is committed and is a build gate.** It fails if a text control's appearance is declared outside the baseline, if any control takes the decorative hairline as its boundary, if a button and a field share a fill, if a single class is declared twice at the same specificity, if a `<summary>` carries no mark, if a button variant replaces the face and forgets to on hover, or if any measured pair drops under its floor. It has a `--self-test` that proves it can fail, including on the two traps that have made audits in this project lie before: `:where()` counted as specificity, and a comment inside a declaration block eating the declaration after it.

**No Add to Calendar button on an event that takes registrations.** It sat directly under the RSVP button, and two things to press with no order between them is a control somebody presses believing it is how you sign up, coming away with a calendar entry and no place held. The calendar file goes out with the registration confirmation instead, which it already did, at the moment the place is actually held. On an event that takes no registrations the button is exactly as it was, because then it is the only route there is.

**In caladmin the Display card's tick is greyed while registration is on**, with a line saying where the calendar link goes instead, rather than leaving a control that looks settable and is not. It keeps the manager's stored value, so switching registration off later gives back the setting they chose. **The save does not read the tick either**: a disabled input posts nothing, and a save that read "nothing" as "unticked" would quietly clear a setting nobody touched, so `save_event_from_post()` asks the stored value rather than trusting the attribute to survive. `.claude/addcal-rsvp-test.php` covers all of it, including that the confirmation email still carries both calendar destinations, because the removal is only safe while that route exists.

**Scope.** The public calendar's filter bar is untouched: it was done in 3.61.0 and has the host-stylesheet constraint on top. The standard is a property of `body.uc-portal`, which is caladmin plus the two standalone submission forms, which use the same chassis and no host page.


= 3.63.0 =

**FAQ sets are made and maintained in one place, sets can be duplicated, and the list no longer opens every question at once.**

**The model, plainly.** On an **event** you apply a set and write questions specific to that event. On the **FAQ Sets** screen you create, edit, duplicate and delete sets. Nothing on an event adds to the shared list any more.

**"Save these as a set" is gone from the event editor**, along with its text box, its button, the hidden form it posted to, its handler and the script that collected the rows. It had three things wrong with it and all three are disposed of rather than repaired, because the control was going:

* **The browser collected the questions on screen and the server ignored them**, building the set from the last *saved* state. On a new event with unsaved questions it refused with "this event has no FAQs to save yet" while the questions sat on screen in front of the person reading it.
* **A refusal redirected with the success message key**, so the page showed a green "FAQ set saved" banner and a red error on the same load.
* **It was gated differently from every other set operation.** Creating a set was the per-event gate, so a contributor could add a row to a list every event picks from, while editing and deleting one were editor-level. A shared list is not something to add to from inside one event.

**"Apply a saved FAQ set" is untouched.** That control was correct and is unchanged.

**Sets can be duplicated.** Each set on the FAQ Sets screen carries **Duplicate this set**. The copy is named **"<original name> - copy"**, and it **opens with its name field already focused**, so renaming is the first thing that happens rather than a step somebody skips and a list that fills with sets called "copy". The original is untouched in every respect.

**A copy is a copy, not a link, and that is the same argument applying a set already makes.** Applying copies rows onto an event so that editing a set never rewrites an event that already used it. Answers drift year to year, and a manager correcting a 2027 answer must not silently rewrite the 2025 and 2026 events sitting on the calendar as past events. Duplication is that argument one level up: "next year's version of this set" is a **new set that starts from this one's text**. Editing either one afterwards leaves the other alone, because after the copy is made there is nothing joining them.

**Two sets may share a name and this is not refused.** Storage has always kept sets apart by id rather than by name, so a duplicate name was never a collision. Refusing would put a failure state in front of something that is about to be renamed anyway.

**Duplication takes the editor gate**, the same one editing and deleting already take.

**The sets list is collapsed.** Every set rendered fully expanded, so the screen was every question of every set at once and finding one meant scrolling past all the others. A set now shows its **name and question count**, in the same format the apply dropdown uses, and opens to reveal its questions for editing.

**Opening one does not close the others**, because somebody comparing two sets needs both. It is a native disclosure rather than scripted show and hide, so it works with JavaScript off and the browser supplies the keyboard and screen-reader behaviour, which is the same reasoning as the dashboard's form-link control.

**Nothing in this release reaches a single event's stored questions.** No event's FAQ block is read, written, or migrated by any part of it. Events that already used a set keep exactly what they had, as they always did, because the rows were copied when the set was applied.


= 3.62.2 =

**Fix: what happens to the meeting link was told in three places, and one case was told nowhere.**

Copy only. Every tick does exactly what it did in 3.62.0, and nothing about the link, the whitelist or the calendar file changed.

A hint under the link box said who gets it. A sentence under the tick group said what happens with no link entered. A paragraph under the same ticks said what a calendar file carries. An organizer had to read all three and assemble the picture, and **a fourth case was in none of them: entering a link and ticking neither box**, which keeps it on the event for the organizer's own reference and sends it nowhere. Nothing said that was possible.

**All of it is now under the meeting link field, as two lines:**

> Only people who register will get this link. It never appears on the event page.
>
> If no link is entered, RSVP emails will say a link will be provided before the event. If you enter one, choose below where it goes out, or leave both unticked to keep it here for your own reference.

Four cases, one place, beside the field they are about.

**The tick group carries nothing beneath it now.** Both lines under it are gone.

**The calendar point moved onto the tick that causes it**, rather than being dropped. It was a paragraph under **both** ticks while only one of them has anything to do with a calendar file, so the confirmation tick now reads **"Send the link with the registration confirmation (also added to their calendar file)"**. It is read at the moment of deciding rather than as a paragraph afterwards. The morning-of reminder's label is unchanged, because that message carries no calendar file at all.


= 3.62.1 =

**Fix: the online event helper text explained the code instead of the event.**

Copy only. Every tick does exactly what it did in 3.62.0, and nothing about the link, the whitelist or the calendar file changed.

The four hints under the online tick were written from the implementation outwards. They described what happens to stored values, which messages a block rides on, and what a calendar file does and does not carry. An organizer setting up a Zoom meeting is deciding what happens to their event and to the people registering for it, and none of the four sentences answered that.

| | |
|---|---|
| was | Ticking this removes the venue or address from this event, and unticking it does not bring it back. |
| now | The venue and address will be cleared. You'll need to re-enter them if you switch back. |
| was | Never shown on the event page. It goes out only in the messages ticked below. |
| now | Only people who register will get this link. It never appears on the event page. |
| was | With no link entered, the ticked messages say a link will be sent before the event. |
| now | No link yet? The emails will say one is coming before the event. |
| was | The confirmation's calendar file carries the link too, and a calendar entry is shared more widely than an email. It syncs to the person's phone and to anybody they share a calendar with. The morning-of reminder does not add it to any calendar file. |
| now | Registrants can add the event to their calendar with the link included. **Calendar entries can be visible to anyone they share a calendar with.** |

**The last one lost a sentence saying the morning-of reminder does not add the link to a calendar file.** That is a statement about something that does not happen, in a paragraph about the other tick, and nobody reading it had reason to wonder. It is gone rather than moved.

**"Who gets the link" was asking a question its own controls do not answer.** Both of those emails go to everybody registered, so the two ticks choose *when* the link goes out and never *who* gets it, and a label promising to choose people is a label that will be believed. It reads **"When the link goes out"**.


= 3.62.0 =

**An event can now say it is online, and the meeting link goes out only where somebody chose to send it.**

**This is a first pass to show a team, and the approval workflow is not in it.** Everybody who registers gets whatever the ticks below select. Per-registrant screening is still being decided and is deliberately absent rather than half built.

**One tick on the event, default off.** Ticking it says the event has no place. The venue picker goes, and **"Online Event"** is what renders everywhere an address would have: the event page and its sidebar, the cards, the month grid, the four emails, the calendar file, the structured data and the satellite feed. That is one change in one function, `sfaf_event_location()`, which every one of those already read.

**No "Getting there" section and no map.** The Google frame on an event page loads on view, and asking Google for a map of two words that are not a place buys nothing and tells it this browser viewed this page. The address in the sidebar becomes plain text with a video glyph rather than a maps link.

**Either a venue, or an address, or online. Never two of them.** Ticking online clears the venue term, the composed address line and all four of its parts, in one call, so nothing downstream has to decide which one wins and no stale value survives for something added later to find and believe. **Unticking does not bring an address back**, and the control says so before you tick it.

**A meeting link field, and two tickboxes deciding who gets it:** with the registration confirmation, with the morning-of reminder, both, or neither. **With the tick on and no link entered, the ticked messages say a link will be sent before the event.** That is the honest thing to tell somebody who has just registered for a meeting with no address.

**The link is treated as a credential, because it is one.** Anybody holding it can join, and this calendar carries HIV, substance use and trans health programming. **It never appears on the public event page**, not in the location, not in a details block, not anywhere a template can reach. It is not in the embed payload, the REST feed, the satellite feed, the search index or the structured data. An organizer who types it into the description themselves has made that decision; nothing here does it for them.

**That is asserted as a whitelist, not a checklist**, the same way private events are. One function reads the link, every place in the source that names the key or calls one of the functions that emits it is enumerated with a reason, and **anything not on the list fails the build**. A field added next year cannot leak it quietly, because it will not be on the list. Alongside it, every public payload is built for real with a link on the event and the bytes are searched for it, because a rule can be right about code that does not do what the rule assumed.

**The structured data says `OnlineEventAttendanceMode` and a `VirtualLocation` named "Online Event".** schema.org would put the meeting link in that node's `url`; the event page goes there instead. This block is emitted into the public head and quoted in search results.

**The calendar file carries the link only on the confirmation's copy, and that is wider distribution than the email.** A calendar entry syncs to the person's phone, to their laptop and to any calendar they share with a partner, an assistant or a household, and anybody with sight of that calendar can read the link. It is deliberate, for the case where the person already holds it. **When the link is only going out with the morning-of reminder it is in no calendar file at all**, because the file is offered by the confirmation.

**The `.ics` route is public and addressed by post id**, so the join copy requires a token keyed on the site's own salt, which only the confirmation builds. Walking the ids returns the ordinary file. The link goes in the description and in the standard `CONFERENCE` property; `LOCATION` still reads "Online Event".

**Imported events are not offered the tick.** Eventbrite and GoFundMe Pro own the location field and rewrite it every hour, so a tick that emptied it would be undone by the next fetch. An online event from a platform already says so in the text the platform sends. An adapter is also refused these three keys outright, the way it is already refused the privacy flag: the cost of honouring one once would be a URL this plugin has never seen going out in the confirmation of everybody who registers.

**The tick travels with a repeating event**, onto generated occurrences and across an "all upcoming" save, exactly like the address it replaces.

**Nothing else moved.** Registration is the same flow with the same confirmation and the same reminder. The consent rule for manager-caused mail is untouched, and no message is sent that was not already going to be sent. Private events, cancellation and their own guarantees are unchanged.


= 3.61.0 =

**The filter bar said what each control was, and now it looks it.**

Four different kinds of control were four white boxes with thin outlines on a white page. The search box and the organizer dropdown were very nearly the same object, and nothing said which of them opened a list.

**One ground, and the controls read against it.** The bar is a tinted panel, and everything on it takes its meaning from that: white fill means you type in this or it opens, no fill means you press this. White now means something instead of being what was left when nothing was decided. There is no box around the bar; there is a ground under it.

**The dropdown is still a native `<select>`.** Keyboard, screen reader, the phone's own picker and the no-JavaScript case all arrive with the element, and nothing custom reproduces the last three. Only its appearance changed: a chevron where the search field has a magnifier, a hairline end-cap the field does not have, and the chevron turning over while the menu is open. Somebody knows it opens a list without clicking it.

**The two glyphs are elements, not backgrounds, and they sit on the wrappers rather than on the controls.** `background: #fff` in a theme resets `background-image` to none, which is how WordPress's select arrow was lost in 3.18.0, and an affordance a rule nobody wrote about us can delete is not an affordance.

**The two pill rows are different kinds of filter and now look it,** in four ways and not one of them color on its own. A category is a rounded pill, pick-one; a group is a square-cornered rectangle, pick-any, and beyond six that row folds into literal checkboxes, so the shape is telling the truth. A category carries its own color dot and a group carries none, because a group has no color anywhere else on the calendar. A chosen category fills; a chosen group gets a tick. The groups row keeps its smaller type and sits under the row it depends on.

**The dot reads a value that had been emitted and read by nothing since 3.31.0,** when the accent bar that used it was removed. It is the same color the cards below already carry for that category.

**The folded groups list had no marker at all** and read as a pill that surprised you. It gets the same chevron as the dropdown, rotated by the same rule.

**Every control on this bar now outranks the resets under it.** Our own button reset is one class plus one element and declares `min-height: 0` and `box-shadow: none`; a host's bare `input[type="search"]` is the same strength and beat every property the search field declared. That last one was live: the field could be repainted on sfaf.org and look untouched here. Each control is written at two classes, which outranks all of it, and the resets keep the strength they need against a theme.

**The bar was asking the browser how wide it was.** An embed in a 280px sidebar of a 1440px page is 280px wide, so the stacked layout it needs there could never fire and three controls fought over 280px on a desktop that looked fine. It measures its own column now, which is what every other part of this block already did.

**Touch targets are keyed to the finger, not to the column.** A 44px floor applies where the device is a touchscreen, which is true of a tablet holding the bar at full width and false of a narrow window on a desktop. The desktop density is unchanged.

**What the controls do is untouched.** Everything still filters through the query rather than by hiding rows, the three filter-row switches and their fallback still decide which rows exist, a visitor still cannot widen past what the block was scoped to, there is no new route, and the organizer filter runs the same server query it has run since 3.50.0.

= 3.60.0 =

**A closure now stands out on a busy month, and says CLOSED in two lines.**

A closure worked but blended in. On a month with plenty of events, somebody scanning the grid did not notice the office was shut.

**Diagonal stripes, in the brand's Red.** The palette gives Red two roles, "category, and destructive state", and a closure is the second: the message is do not come. The values are the measured stops from the category ramp — tint `#FDE9E7` and ink `#AD1C0D`, 6.10:1 — with the stripe being the brand Red itself at 14%. Nothing here is a new colour.

**Grey was not available.** The month grid already spends grey on days belonging to the previous and next month, and a grid where two greys mean two different things is worse than a grid with one highlight.

**The label is two lines, CLOSED over the closure's own name**, with the word the larger of the two. Uppercase comes from the stylesheet rather than the markup, so the word is read rather than spelled.

**Text never sits on the pattern.** In both renderers the hatch is the ground and the words sit on a solid panel over it.

**Both renderers, one treatment.** The month grid still asks per day and marks a square for each day of a closure; a list still asks per span and shows one card reading the range. That difference is the point and is unchanged. What is now shared is the look: the same two-line label, the same classes, the same hatch, declared once and referenced by both. The test asserts they cannot drift.

**Sized against the real grid.** At full width the chip carries both lines above whatever events fall that day. **Below 560px the name line is dropped and CLOSED is kept** — cells collapse to a 44px minimum there and the event entries are already replaced by dots. Nothing is lost: the cell's screen-reader label still carries the whole sentence, and the day panel below the grid names the closure in full. Below 300px the chip sheds its border and most of its padding but keeps its solid ground.

**A closure and an event on the same day are both readable.** Event entries already sit on their own solid white panel, so they are neither tinted by the hatch nor hidden by the chip, and the cell grows to fit both.

**This is not decoration standing in for information.** Every closure says the word "Closed" in text in both renderers, and the grid cell's label reads the full sentence before its event count. The stripes agree with something already legible rather than encoding it, and the reasoning is recorded in the stylesheet so the next decoration audit does not remove it as unexplained.

**Nothing about closures changed underneath.** Still one option rather than a post type, still one entry spanning dates rather than one row per day, still no page, no permalink and nothing to register for, and still invisible to every query over events. The embed gets this by not being special: it calls the same renderers.

= 3.59.0 =

**The expired-row sweep was judging the wrong date, so four rows never cleared.**

3.58.0 gave the queues a sweep and it worked: Pending went from 12 rows to 6. Four rows stayed, three in Pending and one in Dismissed, all with start dates months past.

**The sweep preferred the end date whenever a row had one.** On a GoFundMe Pro row that field holds `ended_at`, which the platform documents as "Date/time of when the campaign ends" — the close of the **fundraising window**, not the end of an event. A donation page collecting until December reported a December last day and never cleared, while the screen showed a start date from April.

**This is the same finding as 3.58.0, one field over.** `started_at` was established there as a fundraising-window boundary rather than an event date, and the multi-day rule was then built on `ended_at` without applying that finding to it. The comment above that rule asserted an event meaning the data does not carry, which is the more expensive half: code can be read, but a comment claiming a meaning is believed. The comment is corrected along with the code.

**The end date is now believed only where the item's type says it means an event's end.** The type is stored on the row at import, which nothing did before, and it is refreshed on any later fetch that still returns the campaign — so a row imported before this release learns its type the next time its source mentions it.

**Event-shaped types are `event` (everything Eventbrite returns), and GoFundMe Pro's `ticketed`, `registration`, `reg_w_fund` and `fund_for_entry`.** Anything else, **and any type this release has not heard of**, is judged on its start date. A type the platform adds later will not default into being treated as an event.

**A row whose type is unknown is judged on its start date.** That is every row imported before this release, and every campaign the source has stopped returning — those can never have a type learned, and are judged on their start date permanently. That is correct and is not worked around.

**Eventbrite is unchanged.** It maps a genuine event start and end, it now declares itself as such, and a real multi-day Eventbrite event still waits for its last day.

**A row clears once the event's DAY has passed, not when the day begins.** An event happening today stays in the queue all day whatever time it runs, and goes at the first sweep after midnight — within a quarter of an hour of it, since the runner is on fifteen minutes. **A dateless row stays**, because it has no date to have passed and filling that date in is the job.

**Published events are untouched, as before.** The sweep names the two queue statuses and reaches nothing else. A published event that expires becomes a past event and stays.

= 3.58.0 =

**Only events are imported, and the queues clear themselves.**

**THE PENDING QUEUE HAD FILLED WITH THINGS THAT ARE NOT EVENTS** — "SFAF Website Donations", "Migrated Recurring Donations" — each carrying a date at an odd hour that looked like a timestamp because very nearly is one. The adapter maps GoFundMe Pro's `started_at` into the event date. On a ticketed event that field is the event; on a donation page it is when fundraising opened, which is the moment somebody created the campaign.

**So the fix is not a date test.** A date test would have cleared those four rows and none of the ones arriving next week: a donation page made on Monday carries Monday, which is not past, and it is still not an event. GoFundMe Pro says which is which in its `type` field, and nothing here had ever read it. **Ticketed, registration, registration-with-fundraising and fundraise-for-entry campaigns are events; donation pages, crowdfunding, peer-to-peer and the GoFundMe Nonprofit Page feeds are not**, and neither is anything the platform flags as a general fundraiser. A type this build has not heard of is imported rather than refused, because a stray row is one click to dismiss and an event silently refused is invisible.

**On top of that, and for every source: no event date means no import, and a date that has already passed means no import.** Today does not count as past, and a conference running Thursday to Sunday is not past on Friday. Eventbrite already refused dateless events and already asks its API for upcoming ones only.

**Refusals are counted and named in the fetch report**, with the campaign and the reason, because an event that never arrived is otherwise indistinguishable from one that was never offered.

**NOTHING ALREADY IMPORTED IS RE-JUDGED.** The rule is applied at the one moment a row would be created. An event already published, queued or dismissed is a decision somebody made and is left alone.

**Expired rows now leave the Pending and Dismissed queues on their own**, which is the last unbuilt piece of the original import design, agreed 2026-07-29. A queue is a list of decisions; once there is no decision left, the row is clutter hiding the rows that do need somebody. An expired pending row becomes **dismissed** — not deleted and not trashed, so the next fetch recognises it and cannot import it again — and expired rows are hidden from both lists. Queue badges now count what the list actually shows.

**A PUBLISHED EVENT THAT EXPIRES IS UNTOUCHED.** It simply becomes a past event and stays exactly where it is. The sweep names the two queue statuses and can reach nothing else. A dateless queue row also stays, because filling that date in is the job.

**The removal safeguard is unchanged**, and keeping it that way decided where the new rule lives. A refused campaign is still counted as present at the source, so a refusal can never be mistaken for a deletion — which would have unpublished live events. `.claude/import-gate-test.php` asserts exactly that, and the case was found by planting the mistake.

= 3.57.0 =

**The Pending screen says what the automatic fetch just did, and whether it is still working.**

**AUTOMATED FETCHING RUNS EVERY 15 MINUTES AND ONLY THE WORDPRESS ADMIN KNEW.** The Automation screen has the full account of it, and that is a different admin area from the one somebody reviewing imports is standing in. A person working the Pending queue could not tell whether it was empty because nothing had come in or because nothing had run since yesterday.

**The last run is now on the Pending screen**, above the queue it fills: when it ran, what each source found in that source's own words, and the cadence. **A run that found nothing is a run that worked** and is worded that way; "nothing new" and "the source returned nothing at all" stay separate facts, because a platform that has stopped answering must not read as a quiet week.

**A source that failed is named at the top of the box**, not left as one item among several, and it says the word rather than only being red. One source erroring does not mark the whole fetch failed: the others ran and their events are in the queue.

**And it says when nothing has worked for over an hour**, which is the case the box exists to catch. A fetch failing on every pass has a very recent "last run" and a stale "last succeeded", so the two are now recorded separately; reading the first as the second would draw a healthy box over a queue a day behind the source.

**Nothing about a fetch is stored twice.** Every figure comes from the same run log the Automation screen reads. What the log gained is the per-source breakdown it was already carrying as one joined sentence.

**"Fetch updates" is unchanged and reports as it always has.** That button runs the sources directly and never touched the run log, so the new box does not move when it is pressed; it is headed "Automatic fetching" so that reads as correct rather than broken.

= 3.56.0 =

**A fifth email: somebody cancelled their registration. The cancel page names which date. FAQ rows read as rows, and removing one asks first.**

**THE NOTIFICATION LIST HEARD ABOUT EVERY REGISTRATION AND NOTHING ABOUT A CANCELLATION**, so a count read off the last alert drifted from the truth and only opening the registrations screen corrected it. There is now an alert when somebody cancels, to the same list, naming the event, its date and time, who cancelled, and the resulting count.

**It is a fifth kind, not a fifth mechanism.** Same per-event switch as the other four, in the same card, stored the same way: the meta records only what somebody has switched OFF, so it defaults on and an event created before this existed behaves like one created after. **The hook it listens to already existed and had never had a subscriber** — `uc_rsvp_cancelled` has been firing into nothing since the cancel link was built.

**What it discloses:** the name and email address of the person who cancelled, plus the count, to the event's notification list. That is exactly what the registration alert already tells the same list about the same person, so it is consistent rather than a new disclosure. There is no button and no link into caladmin.

**THE CANCEL PAGE NAMES THE DATE.** Every occurrence in a series carries the same title, so somebody registered for three Thursdays saw three identical pages and could not tell which one they were cancelling. The date and time are now on their own line, on the question and on the confirmation, in the same house format the emails use. The confirmation is what somebody keeps, and "which Thursday did I drop?" is exactly what a title cannot answer.

**FAQ ROWS.** Each row now sits on a light neutral ground with its fields white inside it, so four rows read as four rows rather than one field of boxes. The background groups a row and carries nothing else: no accent, no left border, no tint, on the rule 3.38.0 established.

**The remove control is a labelled button under the row**, not a small red x in the corner: findable, and deliberate to press. **It asks first only when there is something to lose.** An empty row goes silently, because adding four rows and removing three is ordinary editing and must not cost three dialogs; a row with anything in EITHER field asks, because somebody who typed a question and has not written the answer yet has still done work. Nothing in caladmin warns about unsaved work and there is no undo.

**And there is one FAQ row renderer now, where there were nine.** The event editor, FAQ Sets, the wp-admin series box and both public forms each had their own copy, already drifted on three details, so every FAQ change had to be made nine times or be made incompletely.

= 3.55.0 =

**Weglot's language switcher comes off every calendar surface, the registration cancel page gets a stylesheet, and "release your place" becomes "cancel your registration".**

**THE SWITCHER IS SUPPRESSED ON SEVEN SURFACES AND NOWHERE ELSE.** 3.54.0 took it off the follow confirmation page only. It is now off that page, the registration cancel page, the single event page, the series archive, any page carrying `[sfaf_calendar]` or `[upcoming_events]`, both public submission forms, and caladmin. The last three needed nothing new: they emit their own documents with `uc-portal` on the body and have been covered since 3.17.0.

**Weglot itself is untouched and stays a site-wide plugin.** It is a separate plugin the rest of resources.sfaf.org depends on. Nothing here changes how it runs, detects a locale, switches one, or translates anything; a page with no calendar on it never gets the class and keeps its switcher exactly as it is. The build asserts that no rule in either stylesheet is unscoped, that the class is actually stamped, and that nothing calls `switch_to_locale()`, `set_language()` or `get_available_languages()` — the calls the 3.16.0 fault used, when an instruction to tidy this control was read as licence to build a language feature the plugin never had.

**THE REGISTRATION CANCEL PAGE HAD NO STYLING, for the reason the follow page had none.** It went out through `wp_die()`, whose handler writes its own document and never calls `wp_head()`, on a request handled before `wp_enqueue_scripts` runs. So no stylesheet reached it and no amount of enqueueing would have. Both pages now use one renderer, `sfaf_notice_page()`, which emits its own document and loads the public stylesheet. **Nothing about cancelling changed**: same token, same page that asks before it acts, same row moved to cancelled, same people mailed.

**"RELEASE YOUR PLACE" IS GONE.** It was not the language people use about events. The reminder email, the changed announcement, the cancelled announcement, the cancel page's question, its button and its success message all say cancel-registration now.

**AND THE PAGE NO LONGER CLAIMS PLACES ARE LIMITED WHEN THEY ARE NOT.** "Places are limited, so canceling puts yours back for someone else" was printed unconditionally, including on events with no capacity, where nothing is limited and nothing goes back. It is now shown only when the event actually has one, which is the test `sfaf_spots_left_line()` already applied. The success message drops the same claim on an uncapped event rather than rewording it, because there is nothing true to put in its place.

**The follow dialog's button is yellow**, matching its own landing page and the rule that yellow is the primary action color with Dark Gray as the only ink on it. It has its own class; the RSVP modal's button is deliberately untouched.

= 3.54.0 =

**The follow copy reads as an invitation, the page its link opens is styled, and the FAQ editor puts the question above the answer.** Part 2, the organizer's screen for announcing new dates, is still not built and nothing here sends a follower anything.

**THE WORDING.** The control and its dialog described a setting rather than made an offer. The dialog now leads with the series name in its heading, says "Be the first to know when new dates are added", and puts what arrives and how to stop it under the button: "One email when dates are added. Stop any time." After submitting, the answer is "Almost there! Check your email to confirm and you're all set."

"Nothing else is sent" is gone from all of it. It defined the feature by what it is not, and the line above it already says what arrives. So is "You will not hear about new dates until you do", which stated the failure rather than the outcome.

**THE CONFIRMATION EMAIL** says the series name once, in the heading, instead of twice in two consecutive lines, and no longer carries "no reminders, no newsletter", which was fine print defending a design decision. It stays plainer in tone than the page: this calendar carries HIV, substance use and trans health programming, and the subject line is the part that shows in a preview pane or a shared inbox. **The unsubscribe link is still in it**, which is the guarantee the whole feature was built around.

**THE PAGE THE CONFIRM LINK OPENS HAD NO STYLING AT ALL, and it was not losing a fight with a theme.** It rendered through `wp_die()`, whose handler writes its own document and never calls `wp_head()`, and it is reached on `template_redirect`, before `wp_enqueue_scripts` has run. So there was no stylesheet on the page to lose a cascade: enqueueing harder or raising specificity would have changed nothing. It emits its own document now and loads the public stylesheet, which is what caladmin and both public forms already do. It is styled as a public event page rather than as caladmin, because somebody arriving from an email is a visitor and not a manager.

**THE "English" AND "Español" CONTROL ON THAT PAGE IS NOT OURS AND IS NOW HIDDEN ON IT.** Nothing in this plugin renders those two words. The switcher belongs to Weglot, a separate plugin, which appends it after `</html>`; every browser reparents that into `<body>`, so it lands on any document the site emits, including one this plugin writes itself. It is hidden on this page and only on this page, exactly as caladmin already hides it on its own screens. Nothing changes how Weglot runs and every other page keeps its switcher.

**THE FAQ EDITOR.** Four separate faults, with four different causes:

* **The question sat beside the answer and now sits above it, full width.** It was truncating mid-word in a `flex: 1` box next to an answer at `flex: 2`. `git log -S` and `-G` over the rule return two commits, the 2.0 baseline and 2.8.0, and neither gave the row a direction: this change had been reported before and had never reached the file.
* **The answer box was three lines with its own scrollbar inside a scrolling page.** It is about a paragraph tall now and can be dragged taller.
* **The text inside it was not the brand font.** The editor draws into an iframe, which inherits nothing from the page around it, so it needed a stylesheet of its own rather than anything restyled outside it. It is Merriweather, which is what every surface that later displays that answer uses.
* **The rows were cramped.** Each is a boxed row with room around its controls, which at a cap of 50 is the difference between a set of ten being usable and not.

The row cap, the storage, the cleanup rules and the toolbar buttons are all unchanged.

= 3.53.0 =

**Get Reminders becomes Follow this series, and a follower is a record of their own.** This is the first of two pieces. The second is the organizer's screen for announcing new dates; **nothing in this release sends a follower anything.**

**WHAT THE BUTTON DID, AND WHY IT IS GONE.** It wrote a row into the registrations table against ONE event, at status `subscribed`, for somebody who held no place. That put them on that occurrence's morning-of reminder, made every query that asked who was registered have to say `IN ('confirmed','subscribed')` or quietly disagree with the next one, and produced copy telling people they had released a place they never took. What it was always meant to offer is hearing when a series gains new dates, which is a different subject with a different lifetime.

**Following is recorded against the SERIES TERM, in its own table.** Not the registrations table, and not with a different status: putting the two in one table is what produced the confusion this removes. Nothing about a follower is written to the marketing opt-in record and nothing reaches the newsletter list. Following a programme's dates and agreeing to be mailed by a foundation are two consents, and only one of them was given.

**Nobody is a follower until they answer an email.** Typing an address is not permission to mail it, and anybody can type anybody's. A submission is PENDING and in no audience; a short email asks whoever holds the address to confirm, and confirming is what makes the record active. **That first email also carries the unsubscribe link**, so a follower is never without a route out. Unconfirmed records expire after 30 days and are swept away.

**The button only appears on an event that belongs to a series**, because a one-off has no future dates to hear about. Whether it appears at all is the Display tick it always was, now labelled "Follow the series".

**The dialog says what will arrive before it asks for anything.** The old one was headed "Get Event Reminders", asked for an email address and explained nothing, which is the defect that started this work.

**The answer after submitting is the same sentence whatever happened** — sent, already following, or refused by a rate limit — because a different screen for any of them answers "is that address known here". Rate limited on two subjects, address and client, as the public forms already are.

**What did not change: registration, and every message it produces.** The confirmation, the morning-of reminder, the pre-event summary, and the cancelled and changed announcements all reach exactly the people they reached before and read exactly as they read before.

= 3.52.0 =

**The staff request form can send FAQs: a saved set, questions of its own, or both.**

**TWO CONTROLS THAT COMBINE.** A picker offering the existing FAQ sets, and the same repeater the community form has. Both optional. A requester may pick a set, write their own questions, or do both, and when both are used **the set's questions come first with the requester's appended after**. No interleaving. Answers get the rich text editor with the short toolbar, from the one shared definition.

**A SET IS COPIED, NOT LINKED, AND THAT IS THE EXISTING RULE RATHER THAN A NEW ONE.** The caladmin event editor has always worked this way and the reasoning is worth repeating, because it decides something people ask about: **editing a set later does not change events already using it.** The answers drift year to year, so a manager editing "the 2027 parking answer" would otherwise silently rewrite the 2025 and 2026 events still on the calendar as past events. Copying fails safe, and it is also what makes deleting a set harmless: no event refers to one. Nothing on a submitted event records which set it came from.

**The row cap and the set size limit are the ones already in use**, read from the FAQ sets class rather than typed again: **50**, the same ceiling every other FAQ list has.

**A question already in the set is not asked twice.** If a requester picks the parking set and also types "Is there parking?", one of it is sent, decided by the same case- and space-insensitive comparison caladmin uses when it applies a set.

**A set's rows are cleaned by the same narrow rule everything else on that form gets.** They were written in caladmin under the wider WordPress rule, and nothing is widened to carry them across: the short toolbar cannot produce anything the narrow rule rejects, so a set arrives intact and the form keeps one answer to what may reach it.

**THE COMMUNITY FORM GETS NO SET PICKER, AND NOT FOR SIMPLICITY.** Listing the sets would tell a stranger what programmes this calendar runs. Set names are written by managers, for managers, about recurring programming, and this calendar carries HIV, substance use and trans health programming, so **the names themselves are the disclosure**. It is the same refusal the form already makes when an unknown link says only that the link is not right rather than listing the campaigns that do exist. A stranger submitting one event to one campaign has no business reaching the internal sets. The reasoning is recorded in the code where somebody would otherwise add the picker for consistency.

**Nothing about approval changed.** A submitted FAQ is a field a manager reads and may edit before publishing, like every other submitted field, on the same meta key the editor writes. The status is still named in the code and the author still stays 0 on both forms.

**Recurrence is untouched.** The staff form still records repeating in words, and that is deliberate for this release.

**It still degrades.** Every answer is a real text box with a real name. If the editor's scripts do not run, the row holds its content, posts and saves.

**Not verified here, and it stays on the manual list.** Whether the editors start in a browser cannot be settled from the repository: there is no WordPress, no browser and no logged-out page load in the build environment. Open the staff form, pick a set, add a question of your own, submit, and confirm in Pending that the set's questions appear first with yours after them.

= 3.51.0 =

**The form-link control reads as a button, and FAQ answers on the community form get the same editor the event editor has.**

**GET A FORM LINK IS A PRIMARY BUTTON NOW.** It was a quiet one-line disclosure under the Welcome heading and was too easy to miss. It takes the existing primary treatment, Dark Gray on brand Yellow, measured 8.92:1. **It is still a disclosure and not a button element**, deliberately: with no JavaScript a disclosure opens by itself and shows every campaign's link, and a button with no script does nothing at all. Styling only. The markup, the script that lifts it into a dialog, and the link construction are untouched, and the no-JavaScript list is unaffected.

**FAQ ANSWERS ON THE COMMUNITY FORM ARE RICH TEXT.** Bold, italic, bullets, numbers, link and unlink, from the same shared control and the same single toolbar the event editor uses. No second toolbar was defined.

**Why they were plain, and why that reasoning did not survive.** The field was a plain textarea on the argument that a stranger answering "is there parking" wants no formatting. That argument also said "what survives is the same narrow list either way", **and that was not true of FAQ answers**: caladmin sanitises them with the wide WordPress rule, and this form with the narrow anonymous one. The sentence was true of the description field, which takes the narrow rule on both public forms, and it had been carried across to a field where it did not hold. The comment now names both paths.

**Nothing was loosened to do this.** The narrow anonymous rule already permitted exactly what this toolbar produces, anchors included, restricted to http, https and mailto. The control and the rule now agree, where before the control was narrower than the rule for no stated reason.

**Two things were stopping the editor from ever starting on these pages**, and neither was the field itself:

* The **toolbar settings block** was printed by caladmin and nowhere else, so the script had nothing to read.
* **The scripts were loaded in the wrong order.** The page script ran before WordPress printed the editor's own, so it found no editor and returned. That cost nothing while the only editor on these pages was the description, which starts itself; it is fatal to a row the browser has to build.

**It still degrades.** Every answer is a real text box with a real name. If the editor's scripts do not run, the row still holds its content, still posts and still saves.

**The staff request form has no FAQ field at all**, so there was nothing to convert there. It is not that its FAQ answers are plain; there are none. Reported rather than built, since adding one is a feature rather than a fix.

**Also:** `CLAUDE.md` described the EveryAction integration as a JSON file written hourly, which `PROJECT.md` had already corrected to a MangoApps Trackers endpoint. The two no longer disagree about what is blocked.

**Not verified here, and it goes on the manual list.** Whether the editor actually starts in a browser cannot be settled from the repository: there is no WordPress, no browser and no logged-out page load in the build environment. Open both public forms, add a second FAQ question, and confirm the answer box is an editor with a toolbar rather than a plain box.

= 3.50.0 =

**Each filter is its own switch now, the organizer filter works for the first time, and the series row no longer waits for a category.**

**THE SERIES PILLS FROM 3.11.0 WERE NEVER MISSING.** They were reported absent from the live calendar, so the shipped zip was checked rather than the working tree: the renderer is there, it is called, it has styles and it has a click handler in both scripts. What it had was a gate. It appeared only **after a category was chosen**, which is exactly what a bar showing categories only looks like before the first click, and it lists the series carried by **the events that category actually holds**, so a category whose events have no series offers no pills. Nothing was broken and nothing was unwired.

That gate is gone in 3.50.0, because it stopped being right the moment a block could offer the series row **without** the category row.

**THREE TOGGLES INSTEAD OF ONE SWITCH.** "Let visitors search and filter" was all or nothing. The embed generator now offers **Category**, **Organizer** and **Series** separately, in any combination including none, and the choice travels in the pasted snippet. Two cases it was built for:

* A block scoped to one series, on that programme's own page, wants **no** filters. The block is already the answer.
* A block scoped to an organizer wants the **series** filter only, so a visitor can move between that organizer's programmes. Many organizers run several.

When more than one is on they render in the order **category, then organizer, then series**: the broadest question first.

**NOTHING IS HIDDEN FOR BEING REDUNDANT.** A block scoped to one organizer that asks for the organizer filter gets it. Whoever generates the block can see the page it is going on, and the plugin cannot.

**THE ORGANIZER FILTER RUNS A QUERY, WHICH IT NEVER DID BEFORE.** The dropdown existed, rendered on this site only, was deliberately left out of embeds as "a client-side stub", and had **no handler in either script**: choosing an organizer changed neither the rows nor the count. It runs the same server query the category chips run, so it works on this site and in an embed, and its options are the block's own organizers rather than every organizer on the calendar.

**Every filter narrows through the query, never by hiding rows.** Client-side hiding only ever sees the page already downloaded, which is what made both the old search and the old category filter silently wrong past page one, and the count wrong with them.

**A scoped block still cannot be widened.** A hand-written `active_category`, `active_organizer` or `active_groups` naming something outside the block's scope is dropped server-side, and the block shows its own events. That holds when the filter row is switched off entirely, which is the case such a parameter is actually aimed at.

**No new endpoint.** The extra parameters ride the existing embed route, because its CORS gate compares the route string exactly and a second route would be blocked by the browser the moment sfaf.org asked for it.

**Blocks already pasted on sfaf.org are unchanged.** They carry the old attribute and no new one, and an absent `filters` still means what `show_filters` meant: all three, or none.

**Asserted by rendering.** `.claude/filter-toggles-test.php` renders a real block for **all eight toggle combinations in each of the four display modes**, reads the controls and the event rows back out of the markup, and checks the scope clamp against four hand-written parameters. Seven faults were planted, including a plugin that helpfully hides a redundant control; all seven were caught.

= 3.49.1 =

**Fix: the publish warning named fields that were filled in.**

Opening a pending GoFundMe Pro event, writing a description, choosing a category and choosing an organizer, then pressing Publish still asked for all three. The warning was right about WHICH fields a campaign leaves to a person; it was wrong about whether they had been filled in.

**It was not one fault. It was two, and they landed on the same three fields.**

**The live check looks for controls by name, and two of the names had brackets.** Categories have taken several values since 3.8.0 and organizers since 3.40.0, so the controls are `category[]` and `organizer[]`. The list the check reads from held `category` and `organizer`, which is what PHP calls them once submitted and is not what the browser has to match. Finding no control at all, the check fell back to what was stored, and nothing is stored on an event nobody has saved yet, so ticking boxes could never clear it.

**The description is a rich text editor, and a rich text editor does not keep its text in the box behind it.** TinyMCE holds the content in its own frame and writes it back only at submit, so reading the textarea gave whatever the page loaded with. Typing a description never cleared the warning either, for a completely different reason. The check now asks the editor, and listens to it, so the amber clears as the words are typed.

**The warning also says less.** It used to read "This event still needs a description, a category, and an organizer. GoFundMe Pro cannot supply that, so it stays empty on the live page until somebody writes it here. Publish anyway?" The middle sentence explains why the software could not fill the field in, to somebody who is looking at the empty field. It now names what is missing and asks the question: **"This event still needs a description, a category, and an organizer. Publish anyway?"**

**The check is asserted by running it.** This is the fourth time a check has read something other than what the interface writes, so the new one renders the real controls, reads their real name attributes, and runs the real JavaScript over them: fill all four and nothing warns, empty each in turn and it names that field and no other. The shipped fault was planted back in all three of its forms and every one was caught.

**Known, not fixed here: there is still no way to save a half-finished imported event.** On an imported campaign the left button is "Save Draft", which takes it out of the pending queue, and the right one publishes it. Nothing saves work in progress in place, and nothing warns before leaving the page, so a part-filled event closed by accident is lost. That is an omission rather than a decision and is written up in PROJECT.md.

= 3.49.0 =

**The pending queue is one list, the dashboard hands out the form links, caladmin has its own favicon, and the thumbnails stop wearing a color nobody can decode.**

**THE QUEUE IS ONE LIST WITH CONTROLS OVER IT.** It used to stack three blocks: imports, dismissed imports, then a separate table of submissions. Three blocks meant knowing which block a thing would be in before you could look for it, and the two that were actual work were sorted by different rules and drawn two different ways. Now there is **one list**, with a filter across the top for **Everything, Imported, Staff requests and Community submissions**, each tab carrying its own count. Nothing about "this needs a decision" differs between an import and a submission, so nothing about the row does either.

**IT OPENS NEWEST FIRST, AND THAT IS THE POINT.** This is a work list, not a calendar. What arrived most recently is what nobody has looked at yet, and it matters more than what happens soonest: an event three months out that was submitted an hour ago needs a decision, and an event next week that was reviewed yesterday does not. **Event date** is offered as a second sort, soonest first, for "what is nearly here and still not published". Anything with no date sorts to the bottom in both directions, because imports arrive dateless by design and reversing a sort must not park all of them on top of the rows that have real dates.

**The kind badges stay on the rows.** The filter narrows and the badge identifies; they are not alternatives. Everything else on the screen still works exactly as it did: the fetch report, Publish and Dismiss, the disclosure carrying the fields a platform cannot supply, the submitted image thumbnail, the two questions Approve asks, and Preview, Edit, Approve and Reject on a submission.

**DISMISSED IS STILL ITS OWN CARD, BELOW.** Dismissed is a status, not a kind. Those rows have already had their decision taken and are kept only so a fetch never offers them again, so folding them into a work list would put things nobody has to act on among things somebody does.

**A ROW THAT MATCHES NO FILTER IS STILL IN "EVERYTHING".** An event set to pending by hand carries no badge and belongs to no kind. It is in the unfiltered list, because the one thing that must never happen on this screen again is a pending row that is in no list at all.

**GET A FORM LINK, ON THE DASHBOARD.** Nothing in caladmin linked to either public form, so sending somebody one meant remembering the URL. One line on the dashboard now opens both: the **staff form**, which takes no parameters, and the **community form**, which is produced by **picking the campaign** from a list, so a second campaign is a dropdown rather than somebody assembling an address by hand. A Copy control on each. It is on the dashboard rather than Pending because Pending is admin-only and a contributor has as much reason to send somebody the community form, and it is **closed by default** so it takes one line from a screen it is not the subject of.

**A FAVICON FOR CALADMIN, AND ONLY CALADMIN.** The portal builds its own document, so it can carry a mark of its own: the calendar glyph this plugin already draws, in Dark Gray on brand Yellow. A caladmin tab is now tellable from every other SFAF tab. It is bundled in the plugin rather than uploaded, so it travels with the code and cannot be deleted from the media library by accident. The glyph is **simplified for 16 pixels**, with the two hanging tabs dropped and the head rule drawn as a solid band, because at that size a hairline and a one-pixel nub smear into each other. Nothing else changed: resources.sfaf.org, the event pages and both submission forms keep the favicon they had.

**THE THUMBNAIL RING IS NEUTRAL NOW, WHICH REVERSES HALF OF 3.31.0.** The month grid's 32px thumbnails carried a 2px ring in the category's color. **Nothing on the grid tells a visitor what a color means**, so it was decoration presenting itself as information: a key with no legend. The brand guide is explicit that too many colors make a kaleidoscope and that the rule is a neutral base with one or two accents, and ten inks around ten thumbnails in one cell is the thing it warns about.

Both the month grid thumbnail and the **sidebar** thumbnail now take the same **neutral 1px hairline in --uc-border (#E2E5EA)**, so the two surfaces match, which matters most in the combined view where they are a few hundred pixels apart. Measured at **1.26:1 on a white cell and 1.17:1 on an out-of-month or hovered one**. Those are not failures of the 3:1 contrast floor: that floor applies to a graphic carrying information, and this one carries none. Its job is to bound a photograph with pale edges.

**What 3.31.0 was fixing is not reintroduced.** The accent bar it replaced was the raw category color, and six of the ten measure under 3:1 on white. Its answer, a ring in the darkened ink, was right about which color to use if a color was used at all. What it never established was that a color belonged there. The old reasoning is kept in the stylesheet, in DESIGN.md and in the check that measures it, pointed the other way, because reasoning that good is exactly what somebody restores from.

**The category chip and the placeholder tile keep their color, deliberately.** The chip carries the category name right beside it, so the color is labeled; the placeholder needs a fill and its icon is the category's own. Both are now checked for it, so a later sweep toward "make it neutral" cannot quietly take them.

**THE SIDEBAR THUMBNAIL IS TOP-ALIGNED.** It was vertically centered, so a row whose title ran to three or four lines left the picture floating in the middle of a much taller block of text. It aligns to the top of the row now, in both the standalone sidebar and the sidebar half of the combined view. It is **not stretched** to the row height, which would drag a 16/9 crop into a tall thin slice of a landscape photograph, and the title is **not truncated**, since plenty of SFAF event titles are long: the row simply gets taller. The month grid is unchanged, because its titles are clamped to two lines and there is no tall row for a 32px thumbnail to float in.

**Under the hood.** The queue's own check now renders the screen and reads the rows back out of the HTML, rather than searching source for a string. Three faults on this queue have been marking or filtering problems that no source check could see, so six were planted to prove the new one can fail, and all six were caught. Separately, the build's staging directory is now skipped by the linter, the callable audit and the date sweep: it is a byte-identical copy of the plugin, and scanning it counted every finding twice and reported the date formatter for being the date formatter.

= 3.48.0 =

**More of the submission form is answered rather than typed, and approving one now asks two questions about the person who sent it.**

**COST IS AN ANSWER NOW.** The "Not saying" entry is gone. It read as an option and was really a way to answer without answering, and an event either costs something or it does not. Three answers, **Free, Donation and Something else**, and the field is required, because saying Free explicitly is worth having: it is the question people ask. Something else reveals a box that is **also required**, since Other with an empty box is no answer wearing the shape of one. The first entry in the list is a disabled "Choose one" prompt, so nobody submits "Free" simply by never touching the control.

**SUBMITTERS CAN SEND FAQs.** The same repeater the event editor has, question and answer, starting with one empty row and controls to add and remove. Empty rows are dropped silently on save, because most people will send that one row untouched. A **half-filled** row is kept: somebody meant it, and losing it quietly is worse than showing a gap at review. They arrive as the event's own FAQs, on the same meta key the editor writes, so they open for review like any other field.

The answers go through the **narrow allow-list** the description uses, not the wide one the event editor uses. A stranger's FAQ answer cannot carry an image, a style or a class.

**AND A CAPACITY.** A number, optional, blank meaning unlimited, exactly as the event editor treats it. **It does not switch registration on.** Whether this calendar takes the registrations or the submitter's own link does is a decision taken at approval by somebody who can see both answers.

**APPROVING A SUBMISSION NOW ASKS TWO THINGS, IN ONE DIALOG.** Both are about the same person at the same moment, so they are one interruption. Two prompts in a row is how somebody learns to press the second without reading it.

1. **Email them that the event is published.** Until now neither form sent anything after its confirmation, deliberately, on the grounds that chasing is a person's job. That still holds for "still waiting". It does not hold for "it is live", which is the thing a submitter is actually waiting to hear and cannot find out any other way. It is still not automatic: somebody ticks it, per event.
2. **Send them registrations for this event.** **Ticked by default**, because somebody running an event who does not receive their own registrations has a real problem.

**WHAT THE SECOND TICK ACTUALLY SENDS, because it is registrant data going to somebody outside SFAF.** The address goes onto the event's notification list, which already accepts typed addresses for people with no account here. That list carries **two** messages, and they get both: **an alert each time somebody registers**, naming that person and how many places are taken, and **the morning-of summary two hours before the event, which lists everybody registered by name and email address**. One list carries both, so it is one decision rather than two, and the control says so in those words rather than implying it is only registrations. Untick it when that is not right.

**If the submitter left no usable address, neither question is offered.** The prompt says so instead, because a tick that cannot do anything still reads as a promise that it did.

The prompt is a plain panel in the page that the browser lifts into a dialog. With no JavaScript it stays visible beside the Approve button and the ticks work exactly as they read.

**Both addresses are re-derived from the event at approval.** The form posts two ticks and nothing else: no address, no name. An address arriving in the request would be an address anybody who could reach that route could nominate, and this one goes on a list that is sent people's names and email addresses.


= 3.47.0 =

**A submitted event was in no list at all, and everything a submission asks is now a choice rather than an open box.**

**A COMMUNITY SUBMISSION WAS REACHABLE ONLY BY THE LINK IN ITS OWN NOTIFICATION EMAIL.** Fix this one first: lose the email and the event was invisible. **The status was never wrong.** It saved as `pending`, exactly as intended, which is why the daily orphan email could see it while both screens could not.

**What excluded it was the author.** Both public forms set `post_author` to 0 on purpose, because nobody is logged in to author a public submission. The pending queue passed no scope, and an unset scope means "your own events plus your teams'", so **the review queue was filtering itself by who wrote it**. An authorless post matches nobody, so it was in neither the queue nor the default Events view. It was the 3.19.0 scoping, not the 3.35.0 access gate, and not the status. The queue now says `all` out loud, in one method both the screen and its nav badge read, so the badge can never count a different set from the list it links to.

**The comment above that code claimed a capability check that has never existed.** It said an unset scope shows everything to anyone who may view all. There is no such check on that path and there never was, so the next caller read the comment and omitted the argument. It now describes what the code does.

**THE ORPHAN ALERT WAS A FALSE POSITIVE BY DESIGN.** It reported every submission as having "a deleted account" for an organizer. "Nobody has been given this yet" and "whoever had it has left" are different states, and left alone every community submission would have produced an orphan email the next morning, burying the one alert that matters. A submission **awaiting review** is now exempt. An approved one with no owner still fires, because that means somebody looked at it and published it without giving it an organizer, which is exactly what the alert is for.

**Submissions stay authorless, deliberately.** The alternative was a system account or assigning each one to an admin. A system account is a new principal to secure that then owns events and appears in pickers; assigning to an admin puts a name against work they did not do, which is the same mistake as the badge fault. The cost of leaving them authorless was two queries, and both are now fixed and tested by running them rather than by reading them.

**EVERY ANSWER WITH A KNOWN SET IS A CHOICE NOW.** Cost is Free, Donation or Something else. Age is All ages, 18+, 21+ or Something else. Both reveal a text field when somebody picks the last one. This is what their Google Form already does, and it stops "Free", "free" and "No charge" arriving for one thing. Cost is still informational and no money is handled anywhere.

**THE EVENT CONTACT IS THREE FIELDS: NAME, EMAIL AND PHONE.** Name is required and at least one of email or phone. **There are two contact ideas on that form and they are now labelled so they cannot be confused:** the submitter's own name and email are internal and reach them about an unclear submission, and the event contact goes on the public listing. The form says so in bold, including that the phone number appears there if given.

**THE ADDRESS IS STRUCTURED.** The venue list is offered first, with street, city, state and ZIP as the alternative, because venues have carried those separately since 3.13.0 and one free-text line meant somebody retyping it at approval or a map that would not resolve. **A submitted address does not create a venue record**; the parts are kept so one can be promoted at approval if the place turns out to be used repeatedly. **A venue website** can be given and appears beside the address on the event page, not on the card, which is already carrying a lot.

**STAFF CAN STAY SIGNED IN FOR 30 DAYS.** Once a link has been followed, that browser remembers the address and goes straight to the form. It is scoped to this form, grants nothing else, and is signed so it cannot be edited into a colleague's address. **The form shows which address it is about to submit as, with a way to switch**, because a shared machine is the case it has to be safe on. Any failure clears it, and the sfaf.org check still applies to a new address.

**THE CALENDAR PICTURES NOW SHOW WHAT THEY ARE.** The staff form's grid was twelve thumbnails with nothing to tell them apart. Each shows its **title** from the media library, which says what a picture is for; alt text describes what is in it and is a different job. **No title means nothing is shown**, and a title WordPress made from the filename counts as no title, because "img 2847 final v3" looks like information and is not.

**THE BANNER WAS BEING CROPPED TO A LETTERBOX.** It had a 220px cap with `object-fit: cover`, and a series image is prepared for cards at 16:9, so a 1200x675 picture was cut to roughly 5.5:1 and lost about two thirds of its height out of the middle. It now shows **the whole image at its own proportions**. A separate wider banner image was considered and rejected: it would be a second place to put the artwork, empty for every campaign whose series predates it, and cropping is only ever correct when you know what is in the frame.

**THE CONFIRMATION PAGE'S ONLY ACTION WAS THE SECONDARY BUTTON.** All four terminal pages on the two forms put their action in a bare paragraph rather than the action row every other screen uses, so it lost the row's separation and its size step, and a white button on a white card is the weight used when something else matters more. They use the same row and the primary weight now. Paragraph spacing on these pages was also the browser default rather than the portal's, which is the same "new surface, missed the baseline" as Automation and FAQ Sets.

**Copy.** "organisers" and "sorting out" were British and are gone, along with the rest of the sweep. The confirmation now reads "Thanks, we have it", and says somebody reviews every submission and that we will email if we have questions. **A series called "Cycle to Zero" no longer prints as "Cycle To Zero"**: small words inside a name keep their case, and the stored name is not rewritten, only what is shown. The cost hint that explained what the software does not do is gone.

**The community form is not styled in any campaign's colours**, and will not be. One template serves every campaign, so a palette here would follow every future campaign form regardless of whose it is. The banner from the series record carries the identity; the SFAF palette carries everything else.


= 3.46.0 =

**Uploads on both public forms, and a second form for community submissions.**

**PEOPLE CAN SEND A PICTURE NOW, ON BOTH FORMS.** 3.43.0 deliberately had none, because an upload endpoint reachable with no account is the riskiest thing such a page can carry. That has been weighed and the risk is handled rather than avoided, in one place both forms call.

**A submitted file is a WORKING COPY and never the published image.** It lands in a media folder called Calendar submissions, which is separate from the calendar folder that holds approved images, and it is offered by no picker. The pending row shows it so you can see what arrived; approving does not copy it anywhere. The published picture is still chosen at approval from the calendar folder, after somebody has downloaded the submitted one, resized it and given it alt text. **Nothing points at that folder permanently, so it can be emptied whenever you like** and no published event loses anything: a row whose file has gone simply shows no thumbnail.

**What the handler checks, in this order.** Is there a file at all, and is no file the answer (an image is optional on both forms and a submission without one is normal). What PHP says went wrong. The rate limit, before the file is touched, because otherwise the work is the denial of service. Whether it is genuinely an upload rather than a path the request named. The size, read from the file rather than from the browser, against a 10MB ceiling. What is actually inside it, which must be a JPEG, PNG, GIF or WebP. Then a second, independent reader has to agree with the first. Then the dimensions, against a pixel ceiling, because a small file can decompress enormously. Then the submitted name is discarded entirely and a new one generated from the type that was found. Then the move, then the mode with the execute bits cleared, then a check that the file is where it was put, and only then is it an attachment. **No SVG, on purpose:** an SVG is a document that carries script, served from our own domain.

**A SECOND PUBLIC FORM, FOR COMMUNITY SUBMISSIONS.** The first use is Cycle to Zero, where the public propose events for the ride's calendar. It replaces a Google Form. There is no login and no email check: the address is public and printed on the campaign's own site, and Cloudflare Turnstile stands in for the mailbox the staff form has. Keys go on **Events > Integrations**, and without both of them the widget does not appear at all rather than appearing unchecked.

**THE URL NAMES THE SERIES, AND THAT IS THE WHOLE OF THE SETUP.** `/?uc_event_submit=cycle-to-zero`. The banner and the series every submission joins both come from that series record, which already carries a name and an image, so **a second campaign next year is a URL and a series rather than another build**. There is deliberately no separate banner setting: a second place to put the picture is a second place for it to be wrong. An address that names no series says the link is not right and stops, rather than listing what exists.

**What it asks.** The submitter's name and email, **kept internally and never shown on the calendar**, which is how you reach somebody about an unclear submission. Then the event: name, description, date, start and end, and where, which their old form had no field for at all. Then cost as free text, blank by default and **not shown at all when blank**, because an event with no cost given is not a free event. An age restriction, also free text, since "18+", "all ages" and "21+ after 9pm" are all real answers. An optional link to register somewhere else. A contact **that IS shown publicly**, which is theirs rather than ours and distinct from their own details. An optional picture, and anything else they want you to know, kept internal.

It does not ask about categories, private events, notification lists, reply-to addresses, display toggles or FAQ sets. Those are decisions taken at approval by somebody who knows the calendar.

**Every submission arrives as a pending event that nobody can see**, badged **Community submission** so it is distinguishable at a glance from a staff request and from an import. Admins get one email each, with the submitter as the reply-to. The submitter gets a copy of what they sent, on screen and by email, and nothing after that.

**The four public lines can be corrected.** Cost, ages, contact and the registration link appear on the event editor wherever one of them has a value, because a value written by somebody outside SFAF that nobody here can fix would otherwise be on the calendar permanently.

**THE STAFF FORM'S DESCRIPTION IS RICH TEXT NOW.** Same control as everywhere else. It is still **sanitised on the way in** rather than trusted: what arrives is a POST body whoever the form was drawn for. Both public forms allow exactly what the toolbar can produce, which is paragraphs, bold, italic, the two list types, one heading level, blockquote and links on http, https or mailto. **No images, no inline styles, no classes and no target attributes**, none of which the control can make and every one of which is a way to reach outside the box the prose is drawn in. That is deliberately narrower than the rule for somebody with an account.

**Two lines of copy on the staff form.** The image spec is gone: it described how the software crops rather than what somebody should do. And "Nothing here suitable?" now says to ask Roxane Chicoine for an image for your event.

**Repeating is still asked in words rather than as a pattern, and that stands.** These are one-off events in practice, and anybody running something weekly gets a caladmin account because they will be handling registrations every week. The form still does not ask who receives RSVP notifications either: a one-off requester does not know who owns that list, and it is set at approval.

= 3.45.2 =

**The list view was showing one month, two navigations fought in the embedded combined view, and the sidebar's heading band was wider than its rows.**

**THE LIST SHOWED THE CURRENT MONTH ONLY.** 27 upcoming events, 5 on screen. 3.45.0 bound the combined mode's sidebar to the month the grid is drawing, deliberately, so the two halves move together. It did that with a FILTER called `month`, and a block already carries an attribute called `month` meaning something completely different: which month the GRID is drawing. `render_calendar_block()` hands its whole attribute array to the filter normalizer, and the embed endpoint fills that attribute in on every single request, because the function answering "which month am I drawing" never returns nothing. So every list quietly gained an upper bound. Two different things sharing one key, and the collision was the whole of the bug.

**The bound month is a parameter one renderer sets, not a filter any caller can fill in.** It is called `bound_month` now, and nothing reads it off an attribute, an embed parameter or a form field, so no display month can reach it by being spelled the same way. The sidebar renderer sets it when its caller passed a month; the list never sets it. All four display modes were then checked through the doors a visitor actually arrives by: the list shows everything coming up across every month, the combined view's sidebar shows the displayed month, the sidebar display mode on its own still spans months exactly as it did before 3.45.0, and the grid draws the month it was given.

**TWO MONTH NAVIGATIONS IN AN EMBEDDED COMBINED VIEW.** Only in an embed, and only after navigating. First load was correct everywhere, and this site's own calendar was correct throughout. 3.45.0 moved the month name out of the grid so it could span both halves, and 3.45.1 put the composition in one method so the first render and the redraw could not disagree. The REST route that serves embedded calendars was never told: it kept asking for a grid with its heading left on, so navigating dropped a grid carrying its own month name and its own previous and next into the left column, underneath the spanning head, which still said the month before. It asks the same composition as the other two callers now, and the redraw moves the head, the grid and the sidebar together.

Two things that came with it. The sidebar and the head are markup going to another domain, and the pass that makes every URL absolute read only the grid by name, so a redrawn column of events would have arrived on somebody else's page with root-relative image URLs that resolve against their site. Every piece is resolved now. And the payload cache keyed on the month alone, so a combined payload and a plain grid payload shared an entry and whichever was built first was served to both.

**THE SIDEBAR'S HEADING BAND WAS WIDER THAN ITS ROWS.** The band pulls back out through the card's padding to reach the card's edges, and the amount was written into it as a number. In the combined mode the card is not a card: the panel around it carries the border and the padding, so the card's own padding is zero, the band escaped 14px through padding that was not there, and it finished 14px outside the rows on each side. The escape is now the padding itself rather than a copy of it, so a context that changes the padding cannot forget to change the escape. The row hover tint had the same number written into it twice and is fixed the same way.

**WHY THE SUITE KEPT PASSING.** Five releases in a row this mode shipped a fault the checks passed. Not one of those faults was in a renderer: the renderers were correct every time, and the harness that called them said so, honestly and uselessly. Every one was at the SEAM, in what the entry point hands the renderers or what the context around them is. So the new check calls no renderer directly. Every assertion enters through one of the four doors a visitor comes through, and reads what came back. It asserts nothing about the source text, because three source-string assertions have now been satisfied by the wrong copy.


= 3.45.1 =

**The combined view was composed twice, and only the first render had the 3.45.0 changes.**

**WHAT WAS ACTUALLY WRONG.** The block composed the head, the grid and the sidebar; `ajax_load_month()` composed the same three again when somebody navigated. Two compositions of one thing, so they drifted. The redraw decided whether the grid drew its own heading from a **boolean the browser sent**, so any caller that did not send it, including a browser still holding an older `calendar.js`, got a grid carrying its own heading inside the left column while the spanning one above it stayed where it was. First load looked right and navigating did not.

**One composition now.** `render_combined_parts()` builds all three pieces, and both the first render and the redraw ask it. There is no second place that produces this markup, so the two cannot differ. **The shape is no longer a parameter either:** the redraw reads the VIEW, normalizes it with the same function the first render used, and asks the same `is_combined_view()`. A caller that sends nothing gets the same answer the block would have given.

**THE DATE RANGE LINE UNDER THE HEADING IS GONE.** It read "26 Jul to 5 Sep, 16 events": the grid's full span, which runs into the neighbouring months because the grid starts on a Sunday, plus a count. It explained the greyed cells at each end, which is a question nobody asks. The span is still in the table's caption, where a screen reader meets it before the grid.

**And the count that was actually on screen is gone.** 3.45.0 was asked to remove "29 events coming up" from the month view and removed the count inside the month head instead, which is a different number in a different element. The one on screen is the block's own count line, and it counts every published event from today forward across all months, which beside a grid showing one month is a number about something else. It is dropped in the month and combined views and kept in the list view, where it counts exactly what the list is paging through.

**Everything else in 3.45.0 was in the shipped file and reaching the combined renderer.** Rendering the block here and reading the markup shows the shared container, the spanning head, the sidebar panel, the month tabs, the centred heading cells and the venue row all present. If none of them appear on a live page, the page is not running this renderer: check that the block or shortcode says `view="combined"`, because every one of those five is gated on it while the centred heading is not, which is exactly the pattern of "only the heading changed".

**The check that would have caught it.** This project's own 3.45.0 report named the blind spot and then shipped it: a check asking whether a string exists anywhere is satisfied by either copy when the same calls appear in two places. So the new assertions compare the composition's OUTPUT rather than looking for its source, hold the redraw to having no composition of its own, and refuse the client-sent shape flag by name. The drift is planted and caught.

= 3.45.0 =

**The combined view is one calendar rather than two cards, and the public calendar no longer goes backwards.**

**ONE UNIT.** The month grid and the sidebar were two bordered cards 24px apart, which reads as two things placed beside each other. They are one container now: one border around both, a divider between them rather than an edge each, and the month name spanning the top so it plainly governs both halves. The gap is what made them two, so it is zero and the divider does the separating. That moves the point where they stop fitting side by side from 888px to 864px, which is the two flex bases with nothing between them. Both bases are untouched, and the number is recomputed from the stylesheet by the build rather than published from memory.

**THE SIDEBAR SHOWS THE MONTH THE GRID SHOWS.** It listed whatever was coming up regardless, so navigating to October moved one half and left the other on August. Now the two agree by construction: the block hands the same month to both, and navigating moves both together in one request. Clicking a date in the grid still does nothing to the sidebar, exactly as before.

The sidebar display mode on its own is unchanged and still lists what is coming up across months. Only the combined mode binds to a month.

**MONTH TABS UNDER THE LIST**, with a count in each, because that is where somebody runs out of this month and the count answers "is there anything next month" before a click is spent finding out there is not. **The next month always shows. The previous one appears only once somebody has moved forward**, since the current month is the floor, so on the current month there is one tab and a month or more forward there are two and somebody can walk in either direction. The counts come through the same query the list uses, so a tab cannot promise events the list would not show.

**THE MONTH HEADING IS CENTRED** with the navigation either side, rather than pushed left with a date range and a count crowded around it. **The event count is gone from the month view**: the grid is already showing what is on, and the number was competing with the month name for the same glance. The table caption still carries it for anybody arriving by screen reader, who has no grid to look at, and the list view's own count is untouched.

**THE CURRENT MONTH IS THE FLOOR, IN EVERY DISPLAY MODE, AND IT IS ENFORCED SERVER SIDE.** A public calendar has no reason to browse backwards. **What happened before:** the month rides a parameter on the REST route and on the ajax loader, so anything could ask for `month=2019-03` and get a payload built for March 2019, with a working grid and a previous-month control to keep going. **What happens now:** any month before the current one comes back as the current one, and the response names the month it actually built, so a caller that asked for something old gets a calendar rather than an error. The clamp is in `normalize_month()`, which is the one place all four callers pass through, rather than on the route, so the ajax loader and the shortcode are covered by the same line.

**Two things it deliberately does not touch.** A past event reached by DIRECT LINK still resolves: that is a URL rather than navigation, people arrive there from bookmarks, search results and reminder emails they kept, and the event page never calls this. And the caladmin Events list and its Archived view are staff screens that need the past; nothing in the portal calls this either.

**THE SIDEBAR ROW GAINS THE VENUE, on its own line.** Not appended to the date and time, which is already two elements held apart so a clock cannot be split from its meridiem; a third clause there would break wherever the column ran out. It is the short form, so "Strut" rather than the full postal address, and it is left out entirely when there is nothing to say rather than leaving a blank line.

**Checked by rendering it, which is the point.** This mode has shipped three faults in three releases and the suite passed every time, because each test asserted something ABOUT the markup rather than the markup. The new check runs the real renderers and reads what came back: both halves render their events, the sidebar lists the displayed month and only that month, the head appears once, the floor offers no way back, the tabs count correctly and the venue is its own element. Its harness has a query that honours the date clauses, which is what makes any of that testable; a self-test proves the harness filters, because a stub that returns the same rows whatever it is asked would pass every assertion above while proving nothing.

That check found a real fault in this release before it shipped: binding the list to a month set only an upper bound, so October's sidebar opened with the last days of August in it and the tab under it counted them. Both ends are set now.

= 3.44.1 =

**The FAQ Sets screen returned HTTP 500 on 3.44.0. Install this over it.**

**WHAT HAPPENED.** caladmin builds its own document, so anything WordPress would normally print into a page has to be asked for. There was one flag for that, named for the media library, and it did three jobs at once: print the enqueued styles and scripts, print the media library's own templates, and print the editor settings.

3.44.0 gave FAQ Sets a rich text control and set that flag to get its scripts printed. That screen has no image picker, so it had no reason to call `wp_enqueue_media()` and did not. The footer then reached `wp_print_media_templates()` on a request where the media stack had never been loaded, and the screen fataled part of the way through its own footer. The document went out truncated, which is why the chrome looked squeezed rather than simply absent, and why the answer field was a small textarea: the scripts that would have turned it into an editor were in the part that never arrived.

**One flag doing two jobs was the whole fault.** There are two now. `load_media` means "this screen opens the media library" and is what pairs with `wp_enqueue_media()`. `load_editor` means "this screen has a rich text control". Either one prints the enqueued styles and scripts; only `load_media` prints media templates.

**WHICH OTHER SCREENS WERE AFFECTED: none, and they survived BY ACCIDENT.** The event editor and the pending queue render FAQ answers through the same control and did not fatal, because both happen to call `wp_enqueue_media()` for their image picker, which has nothing to do with rich text. Take the picker off either screen and its FAQ editors would have failed exactly the same way. Both now declare what they actually use, and so does the series screen.

**HOW A 500 GOT THROUGH THIRTY-NINE CHECKS.** PHP lint could never have seen it: the file parses perfectly. The callable audit could not either: every function involved exists. The fault was a function that is only safe once something has been enqueued, reached on a request that never enqueued it. That is the third fatal on this project that lint could not see.

**What would actually catch it is rendering the screen and asserting a complete document comes back, and that cannot be done here.** There is no WordPress in this environment, no database and no HTTP server, and stubbing enough of WordPress to render a portal screen would mean the test deciding which functions exist, which is the exact question this bug turned on. So the new check asserts the contract instead: a screen declaring `load_media` must call `wp_enqueue_media()`, a screen rendering a rich text control must declare `load_editor`, media-stack functions are reachable only under `load_media`, and the flags are set before the head is written. The first and third each catch this fatal on their own, and the real 3.44.0 fault is planted and caught. **Loading the screens is still a manual pass, and it is named in the hand-off.**

Two faults in that checker were found by making it fail on purpose: it missed a flag written with its assignment aligned, and it compared against the first `chrome_open()` rather than the last, so it reported every screen's permission branch as setting flags too late.

= 3.44.0 =

**Rich text wherever somebody writes prose, stated as a rule rather than a list, and one control for all of it.**

**THE RULE.** Any field where somebody writes more than a sentence AND it is displayed as prose gets the editor. Both halves do work: "more than a sentence" excludes labels and one-line notes, where a toolbar is clutter; "displayed as prose" excludes anything read as a value, where markup is not formatting but corruption. A field added next year inherits this without anybody deciding again.

**WHAT QUALIFIED:** the event description (had it since 3.38.0), **the series description**, and **FAQ answers**, everywhere they are written: the event editor, the pending queue's panel and the FAQ Sets screen.

**WHAT WAS EXCLUDED, and why.** The cancel reason is one line by its own label and travels into an email as a value. The organizer description says on its own label that it is not shown anywhere, so it is an internal note. The public request form's description and notes take anonymous input, which 3.43.0 strips markup from on purpose; a rich control there would be an unauthenticated HTML surface. **The venue and category descriptions do not exist**: venues carry a name and an address, categories a name, a colour and an icon. Nothing was built to fill the gap.

**EMAIL BODIES GET NOTHING, and that is a decision rather than an omission.** An HTML email is not a browser: clients strip what they do not understand, and every message here also has a plain text alternative that must carry the same words with no markup at all. The bodies are token templates too, and a token wrapped in markup by an editor stops matching. Plain text is the only thing both halves of a message can carry.

**ONE CONTROL, WHICH IS THE PART THAT MATTERS.** 3.43.1 found the image picker written out twice, one copy silently broken and the two carrying different rules. A rich text control is worse to duplicate, because the copies would differ in **what somebody may type**: one screen with font colours and another without is a calendar branded in some places and not others, and nobody notices until it is everywhere. `wp_editor()` now has exactly one caller and the toolbar exactly one definition, both asserted. The browser reads the same settings from the server rather than carrying its own, because FAQ rows are cloned after the page loads and their editors have to be started in script.

**The toolbar:** bold, italic, links, bulleted and numbered lists, one heading. No colours, sizes or alignment. The heading is **h3**, below the page's own title and its sections, so it cannot break reading order for anybody navigating by headings.

**WHAT BREAKS WHEN PLAIN TEXT BECOMES HTML, checked one at a time.**

**Trimming was wrong in four places and had been since 3.38.0.** `strip_tags()` and `wp_strip_all_tags()` join the text either side of a tag with nothing between, so two paragraphs become "OneTwo". The event description has been rich text for six releases, so this was already live in **the page's meta description and its schema.org payload**, **the .ics file's DESCRIPTION**, **the Add to Google Calendar link's notes**, and the fetch report's before-and-after line. Nothing on screen showed any of it. All four flatten now, which puts a space where the block tag was. The public calendar's cards were already correct, because 3.38.0 fixed that one.

**Copying carries formatting intact.** The series prefill and the FAQ set copy both move stored values without touching them, and the prefill already wrote through TinyMCE rather than the textarea.

**Imported FAQ text was being stripped on the way IN.** The GoFundMe Pro adapter takes the answer verbatim, and the store then ran `sanitize_textarea_field()` on it, which removes every tag: a platform sending HTML had its paragraph boundaries destroyed before anything was saved. What shape it actually arrives in is not knowable from here and has never been observed, which is why there is a probe tool in that file; what is certain is what this plugin did to it. Answers are kept as markup now. **The first fetch after upgrading will report imported FAQ rows as changed once**, because the stored string genuinely changes.

**Existing content needs no migration.** Everything stored is plain text with line breaks and every display path runs it through `wpautop()`, so it reads as the paragraphs it always looked like.

**The embed** renders the description through the same prose island 3.22.0 added, and the FAQ accordion is not in the embed at all, so there is nowhere new for this text to appear unstyled.

**THE COPY SWEEP.** Cut: the FAQ set field's paragraph about rows becoming the event's own, which is the data model; "The values are copied. Editing the series later does not change this event." on the prefill panel, keeping "The date is never filled in." because that is what will happen; and "which is why every category has both", which defends a decision nobody on that screen is making.

**And the reason it keeps coming back is now written where copy gets written.** This has been swept in 3.15.0, 3.19.0 and 3.37.0, and it returns because a sweep removes sentences without changing how the next one is written. `CLAUDE.md` carries the test as something to run before typing a label, with what to delete, what to keep, and the observation that most of these paragraphs exist because the field's name was vague.

= 3.43.1 =

**Choose Image did nothing on the series screen, and the reason was not the one it looked like.**

**IT WAS NOT THE MEDIA LIBRARY.** caladmin builds its own document, so anything needing `wp_enqueue_media()` has to be asked for per screen, which is the shape the rich text editor had in 3.38.0 and the obvious suspicion here. It was wrong. Every screen carrying a picker was already enqueueing, in the right order, before the document head was written. Checking the enqueue would have found nothing and cost a day.

**THE PICKER WAS THE SAME MARKUP WRITTEN OUT TWICE.** When it was rebound from `getElementById('uc-featured-image-id')` to data attributes, so the pending queue could render several on one page, the event editor's copy was updated and the series screen's was not. `bindImageField()` looks for `data-uc-image-id`, `data-uc-image-preview` and `data-uc-image-preview-img`, found none of them there, and returned before binding the click. **Nothing at all was wired to the button**, which is exactly what pressing it did.

**Which screens were affected: New Series and Edit Series**, which are one renderer, so it is one screen in two states. The event editor and the pending queue were fine, because they share a control. Categories, venues, organizers and FAQ sets have no image picker, so there was nothing to miss. Two places rendered picker markup and one of them was broken.

**Rebinding the broken copy would have been the wrong fix.** 3.42.1 filtered the picker to the calendar folder and routed its uploads there, on the copy that worked. Fixing only the binding would have left the series screen offering the whole media library and uploading into the month directory, and the two would have gone on drifting. **So there is one renderer now.** The hooks the script needs, the folder attributes, the buttons, the size line and the folder note are emitted in exactly one place, and a screen wanting a picker calls it rather than copying markup.

**Checked by asserting the contract, not by looking.** The new check reads what `bindImageField()` requires OUT OF the script rather than restating it, so adding a required hook to the JavaScript and not to the markup fails the build. It then asserts those hooks are emitted in exactly one place, that every picker wrapper carries the calendar-folder attributes, and that every screen rendering one loads the media library, walking the call graph so a sub-renderer is not asked to enqueue what its caller already did.

Two faults in that checker were found by making it fail on purpose: it read only the first early return in `bindImageField()` and so believed one hook was required instead of four, and its call walk ran past the enqueue up into the router and reported `handle()` as a broken screen. Both are the shape of the bug it is about.

= 3.43.0 =

**Staff can ask for an event without an account, and the request arrives filled in rather than as an email somebody retypes.**

**WHAT IT REPLACES.** Most SFAF staff will never manage events; they want MarCom to add one. That meant an email or an Asana task and somebody retyping it into caladmin. This is one page that puts the same information straight into the pending queue.

**IT IS NOT A CUT-DOWN caladmin**, deliberately. No sidebar, no events list, no navigation, no account. A restricted portal would mean every screen growing a second set of permission questions, and the answer to "what can a requester see" would live in forty places instead of one file.

**HOW THE LINK WORKS.** Somebody enters their work address on `/?uc_event_request=1`. If it is an sfaf.org address, a link arrives that opens the form; the link carries a token and the token is the only credential, which is what lets this work with no account. The token is 32 random hex characters from the same generator the reminder cancel links use, so there is one answer to "how strong is that token". **It works for 60 minutes.**

The token is stored as a transient rather than a row: nothing about somebody who has not submitted anything is worth keeping, it expires with no cleanup job, and it needs no schema change. **The stored key is a hash of the token, not the token**, so the options table never holds a working credential.

**Asking for a link says the same thing whatever happens.** Sent, refused by a rate limit, or dropped because the mailbox does not exist: all three render the identical page. Anything else would answer "is that a real address here" for anybody who asked. A non-sfaf.org address IS told plainly, because that reveals nothing about a person and somebody who mistyped their own address needs to know rather than watching a link never arrive.

**WHAT IS ON THE FORM:** their name, the event name, a description, categories, a series, the date and times, whether it repeats and until when in plain words, a venue or a free-text place, a picture, whether people register and how many places, and anything else we should know. **Not on it:** private events, the notification list, reply-to, the donate link, display toggles and FAQ sets, because those are decisions for whoever approves it.

**Choosing a series prefills the photo and nothing else, by doing nothing.** An event in a series with no picture of its own already falls back to the series image, so there is no copying and nothing to go stale if the series photo changes before anybody approves the request.

**Repeating is recorded in words, not set as a schedule.** Generating dates makes N independent posts, and doing that from an unapproved request would put fifty-two events one button press away. The request says "every week, until March 3" and whoever approves it sets the real schedule on the screen built for that.

**The picture is chosen from the calendar folder, and there is no upload.** The media library frame needs an account, so on a page with none it would not open at all; this is the same rule in the only shape that works, a grid of radio buttons over the same folder query the event editor uses. **1200 x 675** is stated on it. Nothing suitable means asking Roxane Chicoine for one, which is the answer the brief gives and a better one than an upload endpoint reachable with no account.

**WHAT HAPPENS ON SUBMIT.** A pending event is created, the requester gets an emailed copy of what they sent, and everybody whose calendar role is Admin gets one message naming the requester and the event and linking straight to it. **Nothing after that:** no reminder and no approval notice, because chasing is a person's job and a system that nags on somebody's behalf teaches people to filter it.

**Admins are found two ways, because there are two ways to be one.** A site administrator is a calendar Admin with no `_uc_calendar_role` meta at all, so the meta query alone would miss exactly the people this is for. Both sets are gathered and each is put through the same `get_role()` the portal asks.

**A REQUEST IS DISTINGUISHABLE IN THE QUEUE.** Pending has meant "arrived from GoFundMe Pro or Eventbrite and needs an image and a description", which is a different job from reading something a colleague filled in properly. The row carries a **Staff request** badge and the requester's name and address instead of an author, and the event editor shows a read-only panel with who asked, when, what they said about repeating, and their notes. **No second queue:** two queues is one queue somebody stops checking.

**IT IS TREATED AS A PUBLIC SURFACE.** Every id must resolve to a term that already exists, every date is parsed and compared back to what it parsed from, every string is capped and stripped of markup, and the picture must be an attachment that is an image and already in the calendar folder, which is three checks rather than one. The status is named in the code and never read from the form. There is no REST route: `is_embed_request()` matches an exact string and a new route would fail CORS from sfaf.org, so this hangs off a front-end query var like the cancel link, which also means no rewrite rule and no flush on an install updated by overwriting the folder.

**Four rate limits, because one is not enough.** Link requests are capped per address and per client, and submissions per token and per client. Without the per-address limit one person's inbox can be filled; without the per-client limit every address in the organisation can be hit once each. There is a honeypot field, and it fails exactly as a success looks, because telling a bot it was caught is telling whoever wrote it what to change.

= 3.42.1 =

**The event editor now says which controls are the point of the screen, and the picker only offers pictures that are already the right shape.**

**SAVE AND PUBLISH HAVE WEIGHT.** They were a right-aligned pair under a hairline, sitting between the last card and the cancel card, wearing the same border as every divider on the page. Nothing marked them as the actions the whole screen exists for. They are a band now, with their own surface, clear of the cards on both sides, and the buttons are the largest controls on the page. The weight comes from position and space rather than from a second colour: Publish already carries the one yellow this design gives to actions, and putting another signal on the band it sits on would set two things competing in one place. Only the event editor gets this. Every other screen's action row is a small form where the buttons are already the obvious next thing, and doing it everywhere would spend the emphasis and mean nothing.

A line on the left says what the two buttons differ ON, which is the one thing somebody hesitating between them needs and the one thing the labels cannot say.

**CANCELLING IS SET APART AND CLOSED.** It read as the next section of the form: a card in the same rhythm as the cards above it, so somebody scrolling past Save landed straight in a set of radio buttons and a red button. It is a closed disclosure now, quiet when shut, and opening it is a deliberate act. The red appears once it is open, on the control that does it, because a closed disclosure that shouts is the same problem in a smaller box.

**When the event is ALREADY cancelled it is not hidden**, and that is the point of splitting the two. Then it is not an action somebody needs protecting from, it is the most important fact on the screen and Reinstate is what they came to press. Hiding a status behind a disclosure is how somebody edits a cancelled event for ten minutes without noticing.

The two sentences that used to open that card are gone. "A cancelled event keeps its registrations and takes no new ones. This is what to use instead of deleting" is background: it explains the feature to somebody not doing anything yet, and ran to three lines before the first control. The confirmation says it in one line while they are deciding, and the refusal to delete a registered event says the rest at the moment it applies.

**THE FEATURED IMAGE PICKER SHOWS THE CALENDAR FOLDER ONLY.** Event photographs are cropped to 16:9 and filled, so the wrong shape loses faces. The media library holds every image the site has ever used, and asking somebody to pick the right one from that every time is asking them to remember a rule. Now everything on offer is already right.

**Uploading still works and lands in the folder.** Somebody setting up an event with a picture nobody has prepared can upload it there and then; it goes into the calendar folder, so it is offered next time and can be resized later without moving. The alternative was an upload landing in this month's directory, invisible to this picker, with the event held up until somebody with media library access moved it. The size, **1200 x 675, 16:9**, was already stated at the control and still is.

**Events whose picture sits outside the folder are untouched.** This is about choosing a NEW image, never about showing one, so nothing display-side asks about the folder and a test asserts that it never starts to. An event with a picture from before the folder existed, or one set through the WordPress editor, renders exactly as it did. The URL field beside the picker still takes any address at all.

**It filters on the file path, not on WP Media Folder's API.** Files in that folder are on disk at `wp-content/uploads/calendar/`, so WordPress's own `_wp_attached_file` records `calendar/latino.jpg`. That is core metadata, written by core, present whatever plugin put the file there. So if WP Media Folder is ever removed, every file stays where it is, the meta still starts with `calendar/`, and the picker carries on with nothing to change. The folder stops being manageable, because there is no longer a screen for dragging things into it, but nothing breaks and no event loses a picture. Going through the plugin's own taxonomy would have tied the editor to a third-party plugin staying installed.

The match is **anchored at the front**, so `photos/calendar/x.jpg` and `2026/08/calendar-flyer/x.jpg` are not calendar images. **If the folder ever turns out to be empty the picker says so** rather than opening on a blank grid that reads as broken, and names the folder it looked in.

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

**On sfaf.org nothing about the stacking changes.** That template is locked at about 770px, which is below 864 as it was below 920, so the mode stacks there and always will: the month grid above, the sidebar card beneath it at its own 380px width. The two-column layout needs 864px and is for wider hosts.

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
