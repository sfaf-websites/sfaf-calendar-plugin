# SFAF Calendar — Handover

**Last updated:** 2026-07-29
**Current version:** 2.6.0 (`sfaf-calendar-2.6.0.zip` in the project root)
**Branch:** `production-2.0` — pushed to `origin/production-2.0`, working tree clean
**Repo:** https://github.com/sfaf-websites/sfaf-calendar

---

## 1. Read this first

Everything below 2.6.0 has been **statically verified, never executed.** There is no PHP
binary and no WordPress install in the development environment, so nothing in the
Eventbrite, GoFundMe Pro or import work has ever actually run. Every build passes a
static callable audit (see §7) — that proves nothing is undefined, not that the
integrations work against the live APIs.

**The single most valuable next action is to install 2.6.0 on the live test site and
press the buttons.** §8 lists exactly what to test and what to look for.

---

## 2. Where the project stands

The plugin is the San Francisco AIDS Foundation event calendar: a `uc_event` post type,
RSVPs, recurring series, a `/caladmin` front-end portal, a public `[sfaf_calendar]`
shortcode, an embed system for other sites, and a read-only REST feed for satellite
sites.

The last six releases have been about **importing events from third-party platforms.**
That work is roughly two-thirds done:

| Piece | State |
|---|---|
| Eventbrite authentication | Built (2.3.0) — never run live |
| Eventbrite fetch + preview | Built (2.4.0) — never run live |
| Import framework + Pending queue | Built (2.5.0) — never run live |
| GoFundMe Pro authentication | Built (2.1.0–2.5.1) — **was blocked by Cloudflare; unblock shipped in 2.5.1, unverified** |
| GoFundMe Pro fetch adapter | Built (2.6.0) — never run live |
| **Part B: update-on-refetch, unpublish, expiry, cron** | **NOT BUILT — this is the next feature** |

---

## 3. Release history (this line of work)

| Version | What it did |
|---|---|
| 2.1.0–2.2.1 | GoFundMe Pro auth, iterated four times against wrong assumptions about the endpoint |
| 2.3.0 | Eventbrite step 1 — private token auth, real connection test |
| 2.4.0 | Eventbrite step 2 — fetch + preview (read-only, no import) |
| 2.5.0 | Third-party import **Part A** — framework, Pending queue, publish/dismiss/restore |
| 2.5.1 | GFMP unblock — `x-integration-id` header supplied by their support |
| 2.5.2 | Credentials moved out of `uc_settings` into a dedicated store |
| 2.6.0 | GFMP fetch adapter + Eventbrite image fix + fetch-report fixes |

---

## 4. Architecture of the import system

This is the part a new session most needs to understand.

### 4.1 The framework — `includes/class-sfaf-sources.php`

Two classes:

- **`SFAF_Source_Adapter`** (abstract) — what a platform must implement:
  `slug()`, `label()`, `is_active()`, `fetch()`, `normalize($item)`, plus a concrete
  `inactive_reason()` that should be overridden.
- **`SFAF_Sources`** — the registry and importer. It walks registered adapters, calls
  `fetch()` then `normalize()` on each, and imports what comes back.

**Adding a platform means writing an adapter and registering it. No file in
`class-sfaf-sources.php` changes.** GFMP proved this in 2.6.0 — the framework was
untouched.

Registration happens in `sfaf_init()` in `sfaf-calendar.php`:

```php
SFAF_Sources::register_adapter( new SFAF_Source_Eventbrite() );
SFAF_Sources::register_adapter( new SFAF_Source_GFMP() );
```

External code can register via the `sfaf_source_adapters` filter. **EveryAction is
expected to arrive this way** — write `includes/class-sfaf-source-everyaction.php`
extending `SFAF_Source_Adapter`, register it, done.

### 4.2 The common event shape

What every `normalize()` must return:

```php
array(
    'external_source' => 'eventbrite',   // required — the adapter's slug
    'external_id'     => '1234567890',   // required — stable at the source
    'title'           => 'Event name',   // required
    'description'     => '…',
    'start_date'      => '2026-08-15',   // Y-m-d — MAY BE EMPTY (see §4.5)
    'start_time'      => '18:00',        // H:i
    'end_date'        => '2026-08-15',
    'end_time'        => '21:00',
    'timezone'        => 'America/Los_Angeles',
    'location'        => 'Venue, 123 Main St, San Francisco, CA',
    'source_url'      => 'https://…',    // the page on the platform
    'image_url'       => 'https://…',
    'meta'            => array( '_uc_gofundme_url' => '…' ),  // optional, _uc_-prefixed only
)
```

The date/time split matches the meta the calendar already stores (`_uc_event_date` +
`_uc_start_time`), so an imported event is an ordinary `uc_event` from the moment it
exists and every existing screen reads it without knowing.

### 4.3 Storage and public exclusion

Imported events are **`uc_event` posts in two custom post statuses**:

- `uc_imported` — awaiting review (the "Pending" sub-section)
- `uc_dismissed` — dismissed (kept, not deleted, never re-imported)

Both registered `public => false`, `publicly_queryable => false`, `protected => true`,
`exclude_from_search => true`. Registered in `SFAF_Sources::register_statuses()`, called
from `sfaf_init()` (must be on `init`).

**Exclusion from public display is by construction, not by a filter.** Every public
query names `post_status => 'publish'`: the shortcodes (`build_query_args`), the embed
(renders through the shortcode class), the REST feed, the `.ics` endpoint, the series
listings, the RSVP guards. All 21 `uc_event` queries in the plugin were enumerated and
verified at 2.5.0.

Provenance meta on every imported event:

| Meta key | Holds |
|---|---|
| `_uc_external_source` | adapter slug |
| `_uc_external_id` | ID at the source |
| `_uc_source_url` | the platform page (click-out / edit-on-source) |
| `_uc_external_image` | image URL as supplied |
| `_uc_external_timezone` | source timezone |
| `_uc_imported_at` | unix timestamp |

### 4.4 The no-duplicates rule

`SFAF_Sources::find_existing( $source, $external_id )` matches against an **explicit
status list**: `publish, pending, draft, future, private, trash, uc_imported,
uc_dismissed`.

⚠️ **Do not "simplify" this to `post_status => 'any'`.** `'any'` silently omits every
status registered `exclude_from_search`, which both queue statuses are — using it would
miss the entire queue and re-import everything on every run. Trash is deliberately
included so a trashed import doesn't come back either.

### 4.5 Dateless imports

Many GFMP campaigns have no date (a general fundraiser has no start or end). **They are
imported anyway**, with an empty date, and the manager sets it at approval.

⚠️ **`SFAF_Sources::queue_ids()` must not order by the `_uc_event_date` meta.** Setting
`meta_key` in `WP_Query` implies that meta must EXIST, which silently hides every
dateless import. This was a real bug found and fixed in 2.6.0 — the method now fetches by
status and sorts in PHP, dateless last.

### 4.6 The UI

- **Dashboard** (`/caladmin`) — "Fetch updates" button, admin role only. Runs
  `SFAF_Sources::run_all()`, stores the report in a per-user transient, redirects, and
  renders a per-source breakdown.
- **Pending page** (`/caladmin/pending`) — three sections: "Imported — pending review",
  "Dismissed" (only shown when non-empty), and the pre-existing "Submitted for review"
  queue for locally submitted events.
- **Actions** — Publish (opens the event edit form so category/organizer/series can be
  assigned, *then* the form's own Publish button puts it live), Dismiss, Restore.
- **Event edit form** — shows a source badge and "Edit on <platform>" link for imported
  events.

**Publish is deliberately not one-click.** The platform owns title, description, times,
location and image; category, organizer and series are local decisions and are never
guessed.

---

## 5. The two adapters

### 5.1 Eventbrite — `includes/class-sfaf-source-eventbrite.php` + `class-sfaf-eventbrite.php`

**Auth:** a single long-lived private token, sent as `Authorization: Bearer <token>`.
No OAuth round trip.

**Endpoints** (the old `/users/me/events/` is **deprecated and deliberately not used**):

```
GET {api_base}/users/me/                        ← connection test
GET {api_base}/users/me/organizations/
GET {api_base}/organizations/{org_id}/events/
    ?time_filter=current_future&expand=venue,logo,organizer&status=live
```

`api_base` default `https://www.eventbriteapi.com/v3`, editable in settings, filterable
via `sfaf_eventbrite_api_base`.

An account can own several organizations; the second call runs once per organization and
results combine. Pagination follows `pagination.has_more_items` / `continuation`. One
organization failing records its error and leaves the others intact.

**Image:** uses `logo.original.url` (full-resolution), not `logo.url` (cropped).

### 5.2 GoFundMe Pro — `includes/class-sfaf-source-gfmp.php` + `class-sfaf-gfmp.php`

**Auth:** OAuth2 client_credentials.

```
POST https://api.classy.org/oauth2/auth          ← TOKEN host
     Content-Type: application/x-www-form-urlencoded
     x-integration-id: NSINTG4F8A2C9D6Q1
     grant_type=client_credentials&client_id=…&client_secret=…

GET  https://pro.gofundme.com/api/2.0/…          ← DATA host (different!)
     Authorization: Bearer <token>
     x-integration-id: NSINTG4F8A2C9D6Q1
```

⚠️ **Two different hosts. Support confirmed `api.classy.org/oauth2/auth` is the correct
and only token endpoint — `pro.gofundme.com` does NOT serve tokens.** Four releases were
burned guessing at this. Do not "fix" it back.

⚠️ **The `x-integration-id` header is why auth works at all.** Their edge security was
answering with a Cloudflare 403 that read like a credential failure but never examined
the credentials. Support issued this header as the fix. It is set in `request_args()` so
every request carries it. Value is `SFAF_GFMP::DEFAULT_INTEGRATION_ID`, overridable via
the `sfaf_gfmp_integration_id` filter.

**Campaigns endpoint** (`listOrganizationCampaigns` in `apiv2-public-gfmp.json`):

```
GET {data_base}/organizations/{org_id}/campaigns?page=N&per_page=100
```

Response is the spec's `PaginatedResponse` — rows under `data`, `current_page` /
`last_page` drive the loop. Org ID comes from settings (98313).

**Fields used:** `id`, `name` (→`internal_name`), `default_page_appeal` as description,
`started_at`/`ended_at`, `venue`+`address1`+`city`+`state`+`postal_code`,
`timezone_identifier`, `logo_url`, `external_url`/`custom_url`/`canonical_url`, `goal`,
`status`.

Three quirks worth knowing:

- **`Campaign` has no `description` field.** `default_page_appeal` is the nearest thing.
- **`canonical_url` is relative** in the spec's own example (`/campaign/c0`), so absolute
  `external_url`/`custom_url` are preferred; a relative value is resolved against the
  data host.
- **Timestamps are UTC** (unlike Eventbrite's local wall-clock) and are converted to site
  time via `wp_date()`, so an evening Pacific start doesn't land on the following day.

**Fundraising:** imported campaigns fill `_uc_gofundme_url` and `_uc_gofundme_goal` — the
meta the donate block already reads — so the progress bar picks them up with no extra
setup.

⚠️ **Raised amounts are best-effort and may not work at all.** `apiv2-public-gfmp.json`
defines a `CampaignAggregates` schema (`raised_amount`, `progress_bar_amount`) but
**documents no path that returns it.** It documents `/fundraising-pages/{id}/overview`
and `/fundraising-teams/{id}/overview`, so the campaign lookup follows that pattern —
`/campaigns/{id}/overview` — and **fails silently** if the endpoint doesn't exist. The
fetch report says outright when no raised amounts came back. If it turns out not to
exist, ask GFMP support what the correct endpoint is.

Filters: `sfaf_gfmp_max_pages`, `sfaf_gfmp_per_page`, `sfaf_gfmp_import_statuses`
(default `active,published`), `sfaf_gfmp_fetch_raised`, `sfaf_gfmp_raised_lookup_limit`,
`sfaf_gfmp_campaign_overview_path`.

---

## 6. Credentials — `includes/class-sfaf-credentials.php`

⚠️ **Never store a credential in `uc_settings`.** That option is rebuilt from an empty
array on every settings save — `SFAF_Admin::sanitize_settings()` copies across only the
keys it explicitly names, so anything it forgets is silently dropped. This is what kept
losing the Eventbrite token.

Everything now lives in the **`sfaf_credentials`** option (autoloaded, never rewritten
wholesale — `set()` is read-modify-write of a single key):

`eventbrite_private_token`, `eventbrite_api_base`, `gofundme_client_id`,
`gofundme_client_secret`, `gofundme_org_id`, `gofundme_token_url`, `gofundme_api_base`,
`multisite_api_key`, `galaxy_api_key`, `webhook_secret`.

**Adding a credential:** add it to `SFAF_Credentials::keys()` with a type — `secret`
(write-only, blank means keep), `url`, or `text` — and read it with
`SFAF_Credentials::get()`.

`SFAF_Credentials::absorb( $input )` is called at the top of `sanitize_settings()` and
pulls credentials out of the submission; they are deliberately **not** added to the
returned array. `migrate()` runs on `init` and copies anything still in `uc_settings`
across, never overwriting — idempotent and self-healing.

Connection *state* has its own separate options: `sfaf_gfmp_token`,
`sfaf_eventbrite_status`.

**Audited 2026-07-29: nothing in the plugin deletes credentials.** No `uninstall.php`, no
`register_uninstall_hook`, `sfaf_deactivate()` only flushes rewrites, and
`sfaf_run_activation()` (which reruns after every update) only creates the RSVP table,
registers the post type, seeds sample data once and flushes rewrites. There is a comment
there saying it must never touch credentials — **keep it that way.**

Also fixed in 2.5.2: the AJAX connection tests used to accept a typed credential, use it,
and never save it — so typing a token, pressing Test connection, and leaving without
pressing Save Changes discarded it. Both now persist on success.

---

## 7. Build and release process

**Every rebuild bumps the version.** Fixes = patch, features = minor. Three places:

1. `sfaf-calendar.php` header — ` * Version: X.Y.Z` (line ~6)
2. `sfaf-calendar.php` — `define( 'SFAF_VERSION', 'X.Y.Z' );` (line ~17)
3. `readme.txt` — `Stable tag: X.Y.Z` (line ~7)

Plus a `== Changelog ==` entry in `readme.txt`.

Name the zip `sfaf-calendar-X.Y.Z.zip`, move older zips into `Old Calendar Files/`, leave
**only the current zip** in the project root. Zips are gitignored, so archiving is a
filesystem move.

**Build mechanics:** stage into a scratchpad directory under a top-level `sfaf-calendar/`
folder, then zip with .NET `ZipArchive` writing **forward-slash entries**.
`Compress-Archive` writes backslashes that break on Linux hosts. Verify the built zip has
zero backslash entries.

### The callable audit — mandatory before every build

There is no PHP binary here and three undefined-callable fatals shipped in a row early in
the project. Run this against the **staged build**, not the working tree:

1. All `sfaf_*`/`uc_*` calls vs definitions — must be equal, zero undefined. (Regex must
   handle `function &name()` return-by-reference — two functions use it.)
2. Every `$this->` method in changed classes is defined.
3. Every `self::`/`ClassName::` resolves to a member of *that* class.
4. Every cross-object call (`$response->`, `$adapter->`) checked against WP core or the
   owning class, and guarded (`is_wp_error`, `instanceof`).
5. Core functions exist. **No WP core tree is available here to grep** — cross-check
   against existing production use elsewhere in the plugin, and flag anything that has
   none.
6. Callback arity for `register_rest_route` / `add_action` with `accepted_args > 1`.

Plus: brace balance, PHP-in-single-quoted-string scan
(`grep -rnP "echo\s+'[^']*<\?php"`), and the alternation-syntax checker.

**There is a string-and-comment-aware PHP alternation checker** (`if:`/`endif`,
`foreach:`/`endforeach`, etc.) written during the 2.5.0 build. It lives in the session
scratchpad, so it is gone — naive regex counting gives false positives on ternaries and
prose, so **rewrite it rather than trusting a raw grep count.**

---

## 8. What to test on the live site — in priority order

1. **Install 2.6.0.** Confirm the Plugins screen reports 2.6.0 (version mismatch is the
   tell for a stale/partial deployment).
2. **Settings → Eventbrite → Test connection.** Should report the account name and email.
3. **Settings → GoFundMe Pro → Test connection.** ⚠️ **This is the one that has never
   succeeded.** If it still returns a Cloudflare challenge, the error message names the
   integration ID and User-Agent sent — take that back to GFMP support rather than
   changing the endpoint.
4. **Settings → Eventbrite → Fetch events (preview).** Read-only. Confirms the payload
   shape and that pagination/expansions behave.
5. **`/caladmin` dashboard → Fetch updates.** Expect a per-source line for each. A source
   that isn't connected should say what it's waiting for.
6. **`/caladmin/pending`.** Confirm imported events appear with source badge, date,
   location and source link — **including dateless GFMP campaigns**, which should read
   "No date — set it when publishing".
7. **Publish one.** Confirm it opens the edit form, that the image shows, that assigning
   category/organizer/series then pressing Publish puts it on the public calendar.
8. **Dismiss one, then Fetch again.** It must **not** come back into Pending.
9. **Credential persistence:** save settings, then reinstall the plugin, then
   deactivate/reactivate. Credentials must survive all three. If they still vanish, the
   cause is outside this plugin (host-level option reset, staging→production DB sync) —
   say so rather than patching here again.
10. **Check the donate/progress bar** on a published GFMP campaign. If no raised amount
    was available it falls back to the legacy hardcoded 65% placeholder.

---

## 9. Next feature — import Part B (not started)

Explicitly deferred from 2.5.0 and still not built:

- **Update on re-fetch** — an event already imported should have its platform-owned
  fields refreshed (title, description, times, location, image) while leaving local
  fields (category, organizer, series) alone. `fetch_events()` / `fetch_campaigns()`
  already return everything needed; `SFAF_Sources::find_existing()` already returns the
  post ID.
- **Unpublish on removal at source** — an event that disappears from the platform should
  come off the calendar.
- **Expiry** — auto-remove past/unpublished events from the Pending and Dismissed views.
- **The 15-minute cron** — currently fetching is manual only.

Design note for Part B: `SFAF_Sources::import_event()` is where creation lives; an
`update_event()` beside it, called when `find_existing()` returns an ID, is the natural
shape. Be careful not to overwrite `_uc_image_override`, taxonomy terms, or anything a
manager has edited locally.

---

## 10. Known rough edges / open decisions

- **Public `/events` REST feed is open when no API key is set.** Flagged as a production
  security decision during the 2.0 port; still unresolved.
- **Category-color admin UI is a free text input**, not restricted to the brand palette,
  though `sfaf_sanitize_brand_color()` exists.
- **Galaxy Digital and Webhooks panels are inert demos.** Their settings save and their
  credentials are now stored properly, but nothing calls those APIs. The Galaxy "Sync
  Now" button shows a JS alert.
- **The donate progress bar's 65% placeholder** predates the integration. It now applies
  only where there's no real raised figure, but it is still a fake number on screen for
  every non-GFMP event with a goal.
- **`gofundme_auto_import` and `gofundme_show_progress` toggles** exist in settings;
  auto-import is not wired to anything (that's Part B).
- **Data names stay `uc_*`** (post type, meta, options) for backwards compatibility; code
  identifiers are `sfaf_`/`SFAF_`. Don't rename.

---

## 11. Branches

- **`production-2.0`** — the active line. Real 1.7.5 production baseline + everything
  above. Pushed to origin.
- `main` — the original two plugins as received (unified-calendar 1.0.0 + satellite
  1.0.9). Stale.
- `embed-system`, `brand-guide`, `phase-1-consolidation` — old 1.0.0-line work kept for
  cherry-picking. **Do not delete.**

`apiv2-public-gfmp.json` (2.2 MB) in the project root is the GoFundMe Pro OpenAPI spec —
it is the source of truth for their field names. It is too large to read whole; query it
with a small Node script (`require('./apiv2-public-gfmp.json')`, then inspect
`.paths` / `.components.schemas.Campaign.properties`).
