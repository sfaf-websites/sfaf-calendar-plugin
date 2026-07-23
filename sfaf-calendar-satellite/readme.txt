=== SFAF Calendar Satellite ===
Contributors: marketingmarksolutions
Tags: calendar, events, multi-site, sync, satellite
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.9
License: GPLv2 or later

A lightweight, read-only companion to the main SFAF Calendar plugin. It pulls events from a main site over the REST API and displays them locally with the same calendar UI — identical to the main site from a visitor's perspective.

== Description ==

Install this on a satellite WordPress site to mirror events published on your main SFAF Calendar site. The satellite never sends data back; it only pulls.

**What it does:**

* Pulls events on a schedule via GET {main_site_url}/wp-json/sfaf-calendar/v1/events
* Stores them as local `uc_event` posts tagged with `_uc_source_site`
* Frontend shortcodes `[sfaf_calendar]` and `[upcoming_events]` with the same CSS/card design
* RSVP works locally (modal, local `uc_rsvps` table, capacity tracking)
* Donate button, social share, add-to-calendar, get-reminders, FAQ accordion
* Styled single event template + SEO output (JSON-LD, Open Graph, Twitter cards, breadcrumbs)
* Branded SVG placeholder images and the same image priority chain
* Shortcode Generator admin page

**What it does NOT do (those live only on the main site):**

* No event creation / editing UI
* No /caladmin portal
* No integration panels (GoFundMe, Pardot, Galaxy Digital, Webhooks)
* No outbound sync — it only receives

== Installation ==

1. On the **main** site: Events > Settings > Multi-Site API → click **Generate Key** and copy it.
2. On the **satellite** site: install + activate this plugin.
3. Go to **SFAF Calendar > Settings**, enter the Main Site URL, paste the API Key, choose a Sync Interval, Save.
4. Click **Sync Now** to pull immediately (or wait for the scheduled cron).
5. Add `[sfaf_calendar]` to a page (use **SFAF Calendar > Shortcode Generator** to build variations).

Do NOT run this plugin alongside the main SFAF Calendar plugin on the same site — they share code by design.

== Changelog ==

= 1.0.9 =
* Event images are now downloaded into the satellite's own media library and set as the featured image, instead of hotlinking the main site. This keeps images working even when the main site has hotlink protection. Images are fetched once and only re-downloaded when they change on the main; recurring occurrences that share a series image reuse a single local copy. If a download fails the event falls back to the SVG placeholder without breaking the sync, and the local copy is cleaned up when the image changes or the event is removed.

= 1.0.8 =
* Satellite event URLs now mirror the main site's slugs, including the dated "{parent-slug}-YYYY-MM-DD" form for recurring occurrences. A changed slug on the main propagates on the next sync. (Requires SFAF Calendar 1.7.5+ on the main site.)

= 1.0.7 =
* Sync is now a true mirror: after pulling, events that no longer exist on the main site (deleted, trashed, or removed series occurrences) are deleted locally along with their data. Reconciliation only runs after a fully successful pull, so a network error never wipes the mirror.
* Sync now pages through the entire feed instead of stopping at the first 100 events.
* Category/organizer/venue terms are matched to the main by a stable id, so renames, slug changes, and category color changes update the local term in place instead of creating duplicates.
* Requires SFAF Calendar 1.7.3+ on the main site for term-identity and paginated sync (older main sites still work via a names-only fallback).

= 1.0.6 =
* Settings: new "Reset All Events" button (with a confirmation prompt) that permanently deletes every event and its data stored on the satellite, so you can clear stale events and run a fresh sync without touching the database. The main site is never affected.

= 1.0.5 =
* Performance: shared frontend code updated to match the main plugin 1.7.2 — series membership, series images, category colors, and RSVP counts are memoized/batched per request, so calendar pages with many events render with far fewer database queries.

= 1.0.0 =
* Initial release: read-only event mirror with full frontend display, local RSVP, and shortcode generator.
