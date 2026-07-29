=== SFAF Calendar ===
Contributors: sanfranciscoaidsfoundation
Tags: calendar, events, rsvp, nonprofit, embed
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 2.3.0
License: GPLv2 or later

The San Francisco AIDS Foundation event calendar: manage events, RSVPs, reminders, and recurring series in one place, display them on this site, and embed them on any other site with a small block of HTML.

== Description ==

SFAF Calendar is the San Francisco AIDS Foundation's own event calendar. Staff create and manage events — dates, recurring series, RSVPs with capacity, reminders, categories, organizers, and venues — either through the WordPress admin or the standalone /caladmin front-end portal, with role-based access for admins, editors, and contributors.

Events display on this site through the [sfaf_calendar] shortcode and a styled single-event page with SEO/JSON-LD markup. Where the calendar needs to appear on another site that cannot run the plugin, a small HTML embed block renders the same event cards from this site — nothing is installed or stored remotely. A read-only REST feed (optionally gated by an API key) lets other SFAF sites mirror the events.

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

= 2.3.0 =
* Eventbrite: new integration panel in Settings, with authentication only at this stage. Enter the account's private token and press "Test connection" — the plugin calls Eventbrite for real and reports the account name and email it reached, so a connection is something you have seen work rather than something the screen claims.
* Eventbrite: the "Connected" badge is written only by a successful live call, and is fingerprinted against the token and API base URL that produced it. Change either one and the badge drops back to "Not connected" instead of leaving a stale claim on screen.
* Eventbrite: the private token is write-only. It is never sent back to the browser, never logged, and never appears in an error message; the field submits blank unless you retype it, and blank means "keep the stored one".
* Eventbrite: the API base URL is an editable setting pre-filled with the documented default (https://www.eventbriteapi.com/v3), so it can be corrected on a live site without a new release. The sfaf_eventbrite_api_base filter overrides it in code.
* Eventbrite: failures report the real HTTP status, Eventbrite's own error code and description, and the exact endpoint that was called. If a bot-protection page comes back instead of an API response, that is named outright rather than being misread as a bad token.
* Eventbrite: every request carries an identifying User-Agent and asks for JSON explicitly, set in one shared place so the coming event calls cannot drift from what authentication uses.

= 2.2.1 =
* GoFundMe Pro: requests now identify themselves with a proper User-Agent and ask for JSON explicitly. The token host is behind Cloudflare, which was answering WordPress's default agent with a bot-protection page instead of passing the request through — a rejection that looked like an authentication failure but never examined the credentials. The headers are set in one shared place, so campaign calls will carry them too, and the agent is adjustable.
* GoFundMe Pro: if a bot-protection page comes back anyway, the error now says so outright, names what it matched and the agent that was sent, and lists what to try next, rather than showing a fragment of the challenge page's HTML.

= 2.2.0 =
* GoFundMe Pro: authentication now uses the documented request exactly — credentials form-encoded in the body, sent to the token host at api.classy.org. The experimental Basic-auth and JSON-body variants added in 2.1.2 have been removed now that the correct format is confirmed.
* GoFundMe Pro: the token endpoint and the data API base are now editable settings, pre-filled with the documented defaults. Tokens and data come from two different hosts, and the documentation and the API specification disagree about the data host — so either can be corrected on a live site without a new release. Leave a field blank to use its default.
* GoFundMe Pro: the connection result now names the data base URL that campaign calls will use, alongside the token expiry.

= 2.1.2 =
* GoFundMe Pro: the token request now sends the client credentials as an HTTP Basic Authorization header rather than in the request body, which is the standard OAuth2 form and the likely cause of the "access token is missing" rejection. Because the API documentation does not state which form its token endpoint expects, "Test connection" now tries all four combinations of header-or-body credentials with a JSON-or-form body, stops at the first that works, reports which one succeeded, and remembers it for later refreshes. If every combination fails, each one's error is reported so the responses can be compared.

= 2.1.1 =
* GoFundMe Pro: corrected the API address. Authentication now points at https://pro.gofundme.com/api/2.0, taken from the official API specification, rather than the pre-rebrand Classy host used in 2.1.0. The authentication method itself was already correct. The settings panel now shows the exact base and token addresses being used.

= 2.1.0 =
* GoFundMe Pro: real authentication. "Test connection" now performs an actual OAuth2 client-credentials token request against the Classy API using the saved Client ID and Secret, stores the returned token with its expiry, and refreshes it when it lapses. The "Connected" badge reflects whether a token was genuinely obtained — the old hand-settable flag is gone — and success or failure is reported on screen, failures including the HTTP status, the message returned, and the endpoint that was called.
* GoFundMe Pro: the Client Secret is no longer written back into the settings page. The field submits blank unless retyped, and blank means "keep the stored secret". Neither the secret nor the access token is ever displayed or logged.
* GoFundMe Pro: the "Fetch Campaigns" button has been removed for now — it never called anything. Campaign fetching and event import are the next step and are deliberately not part of this release.

= 2.0.6 =
* Embed: event images now appear on other sites even when an image optimiser is active here. Optimisers rewrite image tags to hold a blank placeholder, keeping the real address in a side attribute that their own script swaps back in — which works on this site but not on a site that does not load that script, leaving the image blank forever. Embed responses now put the real image back into the standard attributes before sending, so no script is needed at the other end. Recognises the Jetpack, WP Rocket, lazysizes and a3 Lazy Load conventions, and keeps native browser lazy loading.

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
* Fix: post type & taxonomies were registered via add_action('init') from inside the init hook, so they never ran on normal loads — no admin menu and events hidden. Now registered directly.

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
