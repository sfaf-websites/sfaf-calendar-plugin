=== SFAF Calendar ===
Contributors: sanfranciscoaidsfoundation
Tags: calendar, events, rsvp, nonprofit, embed
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 3.5.0
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
* Morning-of reminder emails on the day of the event, with a per-event notification list
* An hourly scheduled runner with a run lock, a run log and failure notices
* Styled single event template (auto-loaded, theme-overridable)
* Per-event display toggles and confirmation/organizer email overrides
* Category filter buttons and search on the public calendar
* Shortcode generator and branding controls (logo, colors, card style)
* REST API for multi-site event sync
* Integration panels for GoFundMe Pro, Eventbrite, Pardot/Salesforce, Google Calendar, Galaxy Digital, and Webhooks
* RSVP data routing with confirmation/organizer emails
* CSV export of RSVPs (compatible with Google Sheets import)
* Responsive, modern UI

**Shortcodes:**

* `[sfaf_calendar]` - Full calendar with filters
* `[sfaf_calendar category="support-groups"]` - Filtered by category
* `[sfaf_calendar layout="compact" show_filters="no"]` - Compact list, no filter bar
* `[upcoming_events count="5" category="fundraising"]` - Compact upcoming events widget

Build any of these visually under Events &rsaquo; Shortcode Generator.

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
category's colour, carrying the category icon and name, at the same 16:9 shape.
That is a designed state and not a failure: roughly half of imported GoFundMe
Pro campaigns will never have a picture, because the API does not expose one.

The same target is stated beside the image control in both editors: the
/caladmin event form and the Event Image box in the WordPress admin.

== Installation ==

1. Upload `sfaf-calendar.zip` via Plugins > Add New > Upload Plugin
2. Activate the plugin
3. Go to Events > Add New Event to create your first event
4. Add `[sfaf_calendar]` to any page to display the calendar
5. Configure integrations under Events > Settings

== Scheduled Tasks (required for reminders) ==

Reminder emails go out at 6:00am on the day of the event. WordPress cannot do
that on its own, and this is the single most important thing to set up before
launch.

**Why WordPress cannot do it on its own.** WP-Cron is not a scheduler. It is a
check that runs when somebody visits the site. On a calendar with no traffic at
6am, a 6am job simply does not happen; it waits until the first visitor, which
might be 10am, or the next day. The fix is a real system cron job.

**Setting it up on Bluehost (cPanel).**

1. In cPanel, open **Advanced > Cron Jobs**.
2. Under "Add New Cron Job", set Common Settings to **Once Per Hour** (`0 * * * *`).
3. In the Command box, put:

       wget -q -O /dev/null "https://YOURSITE.org/wp-cron.php?doing_wp_cron" >/dev/null 2>&1

   Replace YOURSITE.org with the real domain. The exact URL for this install is
   shown in the WordPress admin under **Events > Automation > Cron URL**. Copy it from
   there rather than typing it.
4. Click "Add New Cron Job".
5. Edit `wp-config.php` and add this line **above** the
   `/* That's all, stop editing! */` comment:

       define( 'DISABLE_WP_CRON', true );

   This stops WordPress firing scheduled tasks off visitor traffic, so the
   system cron is the only thing that triggers a run and the same work cannot
   happen twice.
6. Go to **Events > Automation** in the WordPress admin and press **Run now**. A new entry should
   appear at the top of the run log. Come back after the next hour and check a
   second entry appeared on its own.

**An external pinger works too** (cron-job.org, UptimeRobot and similar). Point
it at the same URL, hourly. Note that a pinger may report a timeout or a failure
even when the run completed: `wp-cron.php` calls `ignore_user_abort()` and keeps
working after the connection is dropped. **The run log under Events > Automation is the
source of truth, not the pinger's status code.**

**Do not add more than one trigger.** One cron job or one pinger, not both. The
run lock will stop two runs overlapping, but a second trigger only ever produces
log entries saying a run stood down.

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
and that behaviour has never been watched through a real removal. Switch it on
by hand, under Events > Settings > Scheduled Tasks, only after one real removal
has been seen go through correctly. Until then use "Fetch updates" on the
portal's Pending screen, which runs the same fetch with somebody watching.

== Google Maps on the event page ==

Optional. Paste a Maps Embed API key under **Events > Settings > Integrations >
Google Maps** and each event page gains a "Show map" button beneath its
address. Leave the field blank and the page shows the address as a Google Maps
link and nothing else: no button, no error, no admin notice on the public page.

**Nothing is sent to Google until a visitor presses the button.** The server
renders an empty placeholder; the iframe is created by script on click. This is
deliberate and it is not a performance optimisation to be reversed. These event
pages cover HIV services, substance use programmes and trans health groups, and
a Google iframe placed in the markup is fetched on page view, which hands
Google the page URL, the visitor's IP and their referrer for everyone who lands
on one whether they wanted a map or not. The address link meets the practical
need at no third-party cost, and the click is the consent for the rest.

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

= 3.5.0 =

**Teams, a proper notification picker, and registrations reached through their event.**

**A team is a name and a set of people, and an event stores the team rather than the people.** Under Users you can create a team, rename it, choose who is in it and delete it. When an event notifies a team, what gets saved is the team, and who that means is worked out at the moment the reminder is sent. So taking somebody out of a team stops their notifications for every event naming it straight away, with nothing to go and correct, and deleting their account does the same. Adding somebody to a team puts them on events that were set up before they joined, which is deliberate: it is what belonging to a team means, and the alternative is reopening a term of events to add one person. Nothing is snapshotted anywhere, not addresses, not membership, not a resolved list.

**Deleting a team that an event still names is refused, and the events are listed.** The other option was to warn and delete anyway, which cannot be made safe: the event would keep a reference to nothing, and a notification that silently stops going out looks exactly like one that is still going. Refusing is the only version where nothing quietly breaks. The message names the events with links, so you are told what to change rather than just told no. Because a team in use cannot be deleted, no event can ever be left holding a team that does not exist.

**The per-event notification list is now one control with two tabs.** Individuals has a type-to-filter box, which the list needed before it grows to a few hundred names. Teams sits beside it. Both can be used at once, and the summary line says what you have chosen and what it comes to: "1 individual, Philanthropy team (2 people). 3 people in total." The total is a count of distinct addresses, not a sum, so somebody chosen individually who is also in a chosen team is one person and gets one email. Filtering only ever hides rows, so searching for one name can never quietly deselect the people chosen a minute ago. Free-text addresses for people outside the calendar are unchanged and still work. It is built in plain PHP, CSS and JavaScript, uses a real tablist with arrow-key navigation and visible focus, and without JavaScript it degrades to a disclosure holding both lists.

**The three layers of reminder recipients are unchanged.** Registrations, "Get Reminders" subscribers and the notification list are still resolved separately and still deduplicated once; teams slot into the notification-list layer and nothing else moved. The send-once guarantees are untouched.

**RSVPs are reached through their event.** The RSVP count on the Events list is now a link to that event's registrations, with Export CSV. The standalone RSVPs tab has gone: a flat list of every registration ever taken, across every event, is not a question anybody has.

**Registrations for deleted events are still reachable, which is why the screen behind that tab remains.** Those rows are kept on purpose, with the event title snapshotted at deletion so they read "Santa Skivvies (deleted)", and they have no event to be clicked through from. The Events list carries a link to them at the foot of the page whenever any exist, saying how many, and the registrations screen has a view for exactly them. Nothing about the snapshot behaviour changed.

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

**The category chip is readable.** It was the category colour on a 12% tint of itself, which measures 2.05:1 and could not be read. Every approved brand colour now has a measured darkest stop of its own family, and the chip uses it: the worst of the eight is 6.01:1 against a 4.5:1 requirement. Never black, never grey, because the colour is the information.

**Events with no picture get a category tile, not a hole.** Category tint, the category's icon, the category's name. Roughly half of imported GoFundMe Pro events will never have an image, because their API does not expose one, so this is the normal case and now looks like it was meant. The old placeholder was a fixed 16:9 drawing that could only be shown at that one ratio.

**The series row names the series.** "Weekly yoga, see all dates" instead of a generic "Event Series" label, so the link still makes sense read out of context by a screen reader. The row is omitted entirely when an event is in no series.

**One action per card, and the word on it says what it does.** RSVP where the event takes registrations, Donate for a GoFundMe Pro appeal, View event for everything else. White text on the brand teal measures 2.26:1 and fails WCAG AA at this size, so the button is the outline treatment instead: brand teal border, label at 4.63:1, and a teal fill on hover and keyboard focus with the label at 5.47:1. An arrow slides in beside the label on hover and on focus, in pure CSS, and snaps into place instead for anyone who has asked for reduced motion. It is decorative, because there is no hover on a touch screen and the label has to carry the meaning by itself.

**Nothing on a card moves on hover.** No lift, no reflow, no change of row height. The arrow grows into the footer's own free space and the supporting text truncates rather than wrapping, so a card can never shift its neighbours.

**Add to Calendar, sharing and reminders left the card.** They are unchanged on the event page, which renders every one of them. Nobody scanning thirty events adds the fourth to their calendar without opening it first, and those controls were charging every card a row of chrome for a decision that happens one page later.

**Fundraising figures stay honest.** A bar is drawn only where there is a real raised amount and a real goal to measure it against. A goal with nothing behind it states the goal and draws no bar, because a bar at zero is a claim about how an appeal is going.

= 3.0.0 =

**This release changes the data model and needs a one-time migration.** After updating, an admin notice links to Events → Series migration, which shows a dry run of exactly what would happen and writes nothing until the button is pressed. Take a database backup first.

**A series is a container, not an event.** Until now the series parent post was simultaneously the series template and its own first occurrence, and that single fact caused most of the calendar's structural problems: series turned up in the Events list, events existed only as a series, deleting a parent orphaned every occurrence, deleting one occurrence needed a cancelled-dates list on the parent so it would not come back on the next save, and FAQs lived in three different meta keys. A series is now a taxonomy term, exactly like a category or an organizer. It has no date, never appears on the calendar, is never returned by an event query, and cannot be an event because a term cannot be a post. A series may hold different kinds of event — an educational session one week, a social the next — and a series with no events at all is valid and useful: people can read what it is about and see that dates may be added.

**An event is an event.** Every date, including the first, is an ordinary event post. Events say which series they belong to; nothing inherits live from a parent.

**Recurrence is a generator, not a template.** Choosing a pattern when creating an event produces that many separate, independent events, all stamped with a shared recurrence group, and then forgets the pattern. Nothing regenerates. Editing one is an ordinary edit; deleting one removes one date and nothing brings it back. Weekly, monthly, every two weeks, daily, and "the same weekday of the month" — the second Friday, the fourth Tuesday — derived from the date you chose.

**Two groupings, doing different jobs.** A SERIES is the umbrella, used for filtering, embeds and browsing, and is never a target for bulk edits because it may hold different kinds of event. A RECURRENCE GROUP is the set generated together from one pattern, identical by default, and is what a bulk edit targets.

**Edit scope: two buttons, chosen before editing.** At the top of the event editor: "Edit this event" and "Edit all upcoming occurrences". Every field is locked until one is chosen, so nothing can be edited before you have decided how it saves. Each field then opens behind its own pencil, everything is saved once at the end, and a banner that stays visible while the form scrolls states the scope and the count. Saving asks "Update 12 events?" first. "All upcoming" excludes anything whose date has passed — past events are the historical record. Date carries no pencil in that mode, because the dates are the only thing making the occurrences distinct; capacity loses its pencil when any of the target dates already has RSVPs against it, because places are held per date.

**FAQs live in one place.** One key, on the event. No inheritance, no override flag, no display logic deciding whether a series' questions appear above an event's or instead of them. Reuse comes from saved FAQ sets, which have been copies since 2.9.0, and from a series naming a default set applied when an event is created into it — inheritance-like convenience at the one moment it helps, without tying the event to something it may need to differ from.

**Nothing changes for a visitor.** The same events on the same dates in all three display modes and in the embed; every event permalink unchanged, including the old series parent's, which keeps its ID, slug and URL and is now an ordinary event; existing embed code keeps working without being regenerated, because a series filter still takes one integer and the old parent ID still resolves to the same series; RSVP and reminder links resolve to the same events. The one visible change is where "Part of series" links to: it went to the parent post, which no longer exists, and now goes to the series' own page, which shows its description, image and dates. The badge itself looks the same.

**Removed rather than left dormant:** series regeneration, cancelled-date tracking, promote-to-parent, orphan detection and both orphan repair screens, the series removal screen that asked whether to delete everything or promote the next occurrence, the individually-edited flag that existed only to protect occurrences from regeneration, and the three-way edit scope in both editors.

**Fixed:** on a GoFundMe Pro event, entering an image and a description did not clear the pre-publish warning. The warning, the amber field highlight and the Publish confirmation all read the same list, so they agreed with each other — and all three were computed once, server-side, when the page was rendered, and nothing re-ran while you typed. The sentence on the Publish button had been written before the form was touched. All three now recompute as you work, from a single description of each field that names both how it is stored and which control fills it in.

= 2.13.0 =
* The portal's Events list sorts on Event, Date, RSVPs and Status. Click a heading to sort by it, click again to reverse it. The active column carries an arrow and an aria-sort attribute, so which column is sorted and in which direction is readable both by eye and by a screen reader, rather than being implied by the order of the rows. Category, Series and Source do not sort, and that is deliberate: an event can hold more than one category so there is no single answer, Series is another post's title, and Source reads "Local" on nearly every row.
* Sorting is in the URL, so a sorted view can be linked, bookmarked and shared. It survives filtering (the filter form carries it) and it survives paging (every page link carries it), and changing the sort returns you to page one rather than leaving you on page four of a different ordering.
* The Events list is paged, 25 to a page. It was previously capped at 50 with no pager at all, which silently dropped every event past the fiftieth: not a truncation anybody was told about, and indistinguishable from not having those events.
* Statuses read as words. The Status column printed WordPress's own slugs through ucfirst(), so an event that was live said "Publish", which is an instruction rather than a state, and a scheduled one said "Future", which tells nobody anything. Published, Draft, Pending, Scheduled, Private and Trash now, plus the plugin's two import statuses in the wording the import queue already used. Fixed everywhere it leaked, not only on the Events list: the orphan repair screen, the WordPress admin series screens, and the RSVP lists, which had the same problem with their own values and now read Registered, Reminders only and Cancelled.
* Email validation is inline, specific and persistent. The old behaviour was the browser's floating bubble: it appeared on submit, hovered over the page and vanished the moment the pointer moved, leaving no mark on the field. There is now a red border on the offending field until it is corrected, and the message sits below the field in the flow where it stays put. The field is marked aria-invalid and the message is wired to it with aria-describedby, so it is announced and not merely drawn.
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
* Fixed the occurrence-delete bug, which is the one that mattered. Removing a single occurrence from a recurring series did not stick: nothing recorded that the date had gone, so the next time the series was saved the generator saw a gap and filled it back in. A weekly group cancelled for a public holiday quietly un-cancelled itself the moment anybody touched the series. Cancellations are now recorded against the series as dates, so they survive the occurrence being gone, any number of later saves, a change of cadence and a change of end date.
* Cancelled dates are visible and reversible. Each series lists them on its own screen in both the portal and the WordPress admin, with a Restore button per date, and the Series list shows a count. A date that has since fallen outside the pattern says so rather than offering a restore that would do nothing. Cancelling now works the same way whether the occurrence is trashed, permanently deleted, removed from the portal, removed from the WordPress list table or removed by anything else, because the record is written on the removal hooks rather than in one handler.
* Removing a series parent is now a decision with the consequence written down. It used to be one click on a row that looked like every other row, and it left every other occurrence published, pointing at an event that no longer existed, missing from the Series Manager and impossible to edit back into shape. Trashing or deleting a series parent anywhere in WordPress is now intercepted and offers the two things somebody pressing Remove might actually have meant: remove the whole series including every occurrence, or promote the next occurrence to be the series and keep the rest. There is no route left that orphans an occurrence silently.
* Occurrences that were already orphaned are findable and repairable. The Series Manager, in both the portal and the WordPress admin, lists them grouped by the event they are looking for, and offers to rebuild them into a series (the earliest becomes the parent) or to convert them into ordinary standalone events. Nothing is repaired automatically on page load: both of those rearrange somebody's programme, and a plugin doing either quietly is how a calendar gets rearranged by nobody.
* The display helpers no longer render a broken series link. An occurrence whose parent has gone now reads as not being in a series at all, rather than emitting "Part of series:" with an empty name and an empty href on a public page. That degrades in one place rather than in each caller, so a future caller cannot reintroduce it.
* "All future events in this series" was a lie, and is gone. It ran over the whole date range, past occurrences included. There are now three scopes with the cutoff date printed in the label: only this event, this event and occurrences from a named date onward, and all occurrences including past ones. Printing the date is the point, because a label that names its own cutoff cannot drift away from the behaviour without somebody noticing.
* "This and future" is implemented properly rather than relabelled, because it is what people reach for when a weekly group changes time from next month. Applied from an occurrence, it copies that occurrence's details onto the later ones and moves the series template to that date, so everything before it keeps exactly what it had and stays part of the series. Applied from the series itself, it counts from today.
* The scopes are identical in the portal and in the WordPress admin, built from one list in one place. The portal previously offered no choice at all and simply marked an occurrence as individually edited.
* RSVP rows and reminder-log rows now outlive their event on purpose, and readably. Deleting an event is a decision about the calendar, not a decision to forget that thirty people came, and those rows are the only evidence a person ever registered. What was wrong was that they survived unreadable: the list joins on the post, so with the post gone the Event column rendered blank. The event's title is now copied onto its rows at the moment it is permanently deleted, which is the last moment it can be known, and anything orphaned before this release renders as "Deleted event (#id)" instead of nothing.
* Event management has moved off the Dashboard. It used to carry a "+ New Event" button, a "Fetch updates" button, the fetch report, and a table of upcoming events with Edit and Remove on every row, which put the most destructive control in the plugin on the first screen after logging in, next to a welcome message. The Dashboard is now an overview: counts, what needs attention, the health of the scheduled runner, what is coming up, and recent activity, all read-only and each linking to the screen that can act.
* Events is where single events and individual occurrences are created, edited and removed, and the Remove control now knows what it is looking at: an ordinary event, an occurrence (which offers to cancel that date, and says it will not come back), or a series parent (which sends you to the removal screen).
* Series is where a whole series is created, edited and removed, and where cancelled occurrences are listed and restored.
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
* The reminder's "Can't make it?" link releases the recipient's place so it goes back to the count. The link is tokenised per recipient per event, is not guessable, and needs no account. Opening it never cancels anything on its own: it shows a page that asks, and the button on that page is what acts, because mail clients and security scanners fetch the links in an email without a person ever clicking one. A cancelled registration is kept and marked cancelled rather than deleted, so the history survives.
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
* Added a reset scoped to the calendar so the host site's styles cannot bleed into an embed: link colour and underlines, table and cell borders, header backgrounds, list bullets and indents, button styling, font sizes and headings are all now stated rather than assumed. It wins on specificity rather than by shouting, so a host can still deliberately override it if they ever need to.
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
* Sidebar mode for narrow placements: date, title and start time per row, limited to a number you choose. It lists occurrences, so a weekly group appears once per date, and shows fewer rows rather than padding when fewer exist. A programme with nothing scheduled shows a short line saying so instead of leaving a blank box on a live page, and every sidebar ends with a link through to the full filtered calendar.
* Redesigned list card. The image is now a full-width banner across the top of the card instead of a cropped block on the left, and the meta row leads with the date and time, ahead of the organizer. This also fixes the branded placeholder rendering as a cut-off title reading "gram Gro": it is a 16:9 image that was being squeezed into a 4:3 box, and roughly half of imported GoFundMe Pro events will never have a real image, because their API does not expose one. Everything the card carried before is unchanged.
* A visitor-facing toggle between list and calendar, defaulting to list because real months have entire weeks with no Friday or Sunday events and the grid reads sparse. The choice is remembered per block, so two embeds on one page do not affect each other.
* On a phone the grid collapses to date numbers with one dot per event, and tapping a date shows that day's events underneath in full. It opens on today, or on the next day that has anything, so it is never blank.
* The embed accepts a filter by organizer, series or category, and it applies to all three modes. Organizer is the default and usually the right one for a programme page: one team's work is often several series and several categories at once.
* The embed code generator now exposes display mode and filter as independent controls rather than a list of preset block types, with a count for sidebars and a default view for blocks that show the toggle. Every combination is a snippet, so two organizers are two snippets from the same screen.
* Accessibility: the grid is a real table with day-name column headers, navigable with the arrow keys, and each day announces its date and event count. The view toggle carries its pressed state. Today, the selected day and out-of-month days are each marked by more than colour.
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
* Fixed a serious contrast failure: the "Open the campaign page" button rendered teal text on a dark brown background at 1.53:1, well below the 4.5:1 accessibility floor and genuinely unreadable. The portal's link colour was overriding the button's own white. It now measures 7.09:1, and 9.37:1 on hover.
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
* Each platform now declares which fields it owns, and the editor is built from that declaration. Fields a fetch will overwrite are shown but not editable, greyed with a small lock and the "Edit on…" link beside them, so nobody types into a box whose contents are replaced on the next run. The list the editor locks is literally the list the importer writes, so the two cannot drift apart.
* GoFundMe Pro campaign images and descriptions are now permanently yours. Their support has confirmed that the campaign banner and the About copy live in the campaign's page design and are not exposed on the public API at all. Those two fields are now formally excluded from every import, refresh and future scheduled update, with a note in the editor saying why, so that what you write survives, and so that nobody later "fixes the gap" by adding a mapping and starts silently overwriting your copy.
* Both fields are highlighted in amber while they are empty, with an icon and words rather than colour alone, and the highlight simply disappears once they are filled. Amber and not red: a campaign arrives needing them every time by design, so this is a step in the job and not a fault.
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
* Embed: event images now appear on other sites even when an image optimiser is active here. Optimisers rewrite image tags to hold a blank placeholder, keeping the real address in a side attribute that their own script swaps back in, which works on this site but not on a site that does not load that script, leaving the image blank forever. Embed responses now put the real image back into the standard attributes before sending, so no script is needed at the other end. Recognises the Jetpack, WP Rocket, lazysizes and a3 Lazy Load conventions, and keeps native browser lazy loading.

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
* Brand guide v3.0 compliance across the public calendar, admin, and /caladmin portal: inline-SVG icon set replacing emoji, AA-contrast colours, Montserrat/Merriweather typography, and the approved palette as the branding defaults (the branding settings themselves are unchanged).

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
