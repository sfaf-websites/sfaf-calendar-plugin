# CHAT-HANDOVER.md, SFAF Calendar

Read this first in a new chat. It records where the planning conversation with Mark stood on 2026-10-15. The repo's own HANDOVER.md, PROJECT.md, TESTING.md and CLAUDE.md (maintained by Claude Code, CC) are the technical truth; the project copy of HANDOVER.md here is stale (3.95.0) and should not be trusted for current state.

## How this chat works (non-negotiable)

- Mark runs development. CC builds. The assistant in this chat is planner and reviewer.
- Discuss first, answer the question asked, keep it short. Never draft a CC instruction block unless Mark has explicitly confirmed the items and said to draft. "Say go" then draft.
- When asked to update a code block, output the whole block again.
- Don't answer what you don't know; ask.
- No em dashes anywhere (chat, code, copy, emails). Helper text says what to do, never why.
- A release is not finished until `bash .claude/publish.sh --release` has run and CC reports the GitHub release link. From 3.113.4, CC runs it itself once every check passes (CLAUDE.md 2), and asks first if any check failed or was skipped. Mark installs on the site with Check for updates; that stays Mark's decision. CC runs the test suite itself, in parts.
- After each release the assistant gives Mark a plain test list of the new items.

## Current state

- Installed on resources.sfaf.org: 3.112.0, released 2026-10-15 (https://github.com/sfaf-websites/sfaf-calendar-plugin/releases/tag/3.112.0).
- 3.112.0 contains: "What's included" tags (admins create on Series & Categories > Tags; organizers tick on the event form; pills on event page link to filtered calendar, uc_tag), Location filter on the calendar (uc_loc), Multi-day event tick with End date, closed-day email with Keep and Cancel links plus Pending > On closed days tab, Drafts and Scheduled tabs, Replies go to dropdown, keepalive only with activity in the last 30 minutes.
- 3.111.0 and earlier (3.105 to 3.110.5): Email registrants rich text with PDF attachment and editable label, pin events per embed (data-pin in the generator), opt-in style isolation (data-isolate="yes", off by default because Weglot must keep working on sfaf.org), sidebar ends at content with See all events going to the Calendar home URL (https://www.sfaf.org/calendar/), slug fields with redirects, browser drafts and session-ended page, Register on another site, capacity/waitlist, questions for registrants, check-in, Team and access card, private unlisted events, picture upload rule 1200x675 under 500KB.

## Incident on 2026-10-15 (unresolved cause)

After installing 3.112.0, the sfaf.org/calendar embed showed "The events calendar could not be loaded just now" for everyone. Mark rolled back to 3.111.0 (calendar returned), then reinstalled 3.112.0 to gather evidence. With DevTools "Disable cache" ticked, the page loaded fine on 3.112.0, so 3.112.0 stayed installed. No previous update ever produced this. Working theory, unconfirmed: embed.js is loaded on sfaf.org without a version parameter, so the CDN/browser kept serving the 3.111.0 script against 3.112.0 responses, and/or the embed cached an error response for ten minutes. Side effect: the new "What's included" and Location dropdowns were not visible on sfaf.org/calendar. The tags dropdown may also be hidden by design until a tag exists (none existed yet). The REST URL /wp-json/sfaf-calendar/v1/events returns a "feed is switched off" 403; that is the multisite feed route and is probably unrelated.

Nobody at SFAF in this chat has a WP Engine portal login, so server error logs were not checked.

Proposed, awaiting Mark's go: a CC diagnostic brief to establish the real cause (embed.js caching and version parameter, old/new embed.js compatibility with the new REST responses, why the filter dropdowns don't show), report before changing anything.

## Awaiting Mark's confirmation for 3.112.1 (do not draft until confirmed)

1. Fix for the incident above once diagnosed; embed must never cache an error response.
2. Clear the embed cache whenever an event is cancelled, reinstated, published, unpublished or hidden.
3. Cancel event and Delete as centred modal dialogs (also on the series screen); no "how should this save apply" scope prompt after cancelling.
4. Tags screen: replace the heading copy "What's included at an event, such as food or childcare" with a more generic line (childcare is not something SFAF offers; proposed "such as food, giveaways or interpretation"), and fix the Add tag button spacing (too close to the New tag field) and make it the primary button style.
5. Open question: where the tag picker sits on Add event. Mark had not yet found it. Check the installed build before assuming.

## Mark's own to-do list

- Confirm sfaf.org/calendar loads normally with cache on and in a private window; purge WP Engine cache on sfaf.org if needed.
- Create the first tags under Series & Categories > Tags, then recheck the dropdown.
- Test 3.112.0 items: TESTING.md 1.268 to 1.276 and 2.55 to 2.59; also earlier unticked 1.235/1.236 (Strut slug), 1.245 (See all events), 1.263 (CTZ embed with data-isolate), 1.267 (Weglot).
- Regenerate the CTZ embed with "Protect from the host page's styles" ticked.
- Put the CTZ donation link on the Cycle to Zero series.
- Send EMAILS.md to the Spanish reviewer.
- Assign the Aging Services team under Team and access on their series.
- Add cycletozero.org domains to cPanel Hotlink Protection if images are still blocked.
- Get a WP Engine portal login for resources.sfaf.org.
- Post the team update in the Teams channel (draft below).

## Team update draft (brief bullets, last three releases)

- Email registrants: rich text message, attach a PDF with your own download label
- Pin events: highlight chosen events at the top of an embedded calendar (set in the embed generator)
- What's included tags: tick what the event provides (food, giveaways, etc.) on the event form; they show as clickable pills on the event page and visitors can filter the calendar by them. Admins create the tag list under Series & Categories
- Location filter on the public calendar
- Multi-day events: tick the box and set an End date
- Drafts and Scheduled tabs in caladmin
- Replies go to: pick who receives replies to event emails
- Closed days: email with Keep and Cancel if your event lands on a closure
- Browser drafts: your work is saved if your session expires

Hold the tags, location and multi-day lines until tested on the live site.

## Held / queued (not scheduled)

Analytics tab (count-based attendance), Twilio texts (recommended over EZ Texting), Mailchimp/Pardot list sync (team moving off EveryAction; Pardot blocked on credentials), reminder schedule checkboxes, calendar.js shortcode style isolation, default visibility of cancelled events, EveryAction/HAN import, bulk per-day keep/cancel beyond the On closed days tab, Event Images/ folder untracked in git.
