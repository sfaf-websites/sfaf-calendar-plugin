=== SFAF Calendar ===
Contributors: sanfranciscoaidsfoundation
Tags: calendar, events, rsvp, nonprofit, embed
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 2.10.1
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

== Installation ==

1. Upload `sfaf-calendar.zip` via Plugins > Add New > Upload Plugin
2. Activate the plugin
3. Go to Events > Add New Event to create your first event
4. Add `[sfaf_calendar]` to any page to display the calendar
5. Configure integrations under Events > Settings

== Changelog ==

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
