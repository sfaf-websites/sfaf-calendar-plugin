=== Unified Calendar ===
Contributors: marketingmarksolutions
Tags: calendar, events, rsvp, multi-site, nonprofit
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

A modern, multi-site event calendar built for organizations managing events across multiple web properties. Features RSVP tracking, integration hooks for GoFundMe Pro, Pardot/Salesforce, Google Calendar, Galaxy Digital, and webhook-based automation.

== Description ==

Unified Calendar replaces expensive third-party calendar plugins with a custom, API-driven solution designed for organizations that need to sync events across multiple WordPress sites and external platforms.

**Core Features:**

* Custom Event post type with full WordPress editor support
* Event Categories, Organizers, and Venues taxonomies
* Date, time, location, and recurrence fields
* RSVP system with capacity tracking
* Category filter buttons and search on the public calendar
* REST API for multi-site event sync
* Integration panels for GoFundMe Pro, Pardot/Salesforce, Google Calendar, Galaxy Digital, and Webhooks
* CSV export of RSVPs (compatible with Google Sheets import)
* Responsive, modern UI

**Shortcodes:**

* `[unified_calendar]` - Full calendar with filters
* `[unified_calendar category="support-groups"]` - Filtered by category
* `[upcoming_events count="5" category="fundraising"]` - Compact upcoming events widget

== Installation ==

1. Upload `unified-calendar.zip` via Plugins > Add New > Upload Plugin
2. Activate the plugin
3. Go to Events > Add New Event to create your first event
4. Add `[unified_calendar]` to any page to display the calendar
5. Configure integrations under Events > Settings

== Changelog ==

= 1.0.0 =
* Initial release
* Custom post type with taxonomies
* RSVP system with capacity tracking
* Frontend calendar with filters and search
* Admin settings with integration panels
* REST API endpoints
* CSV export
