# HANDOVER.md, SFAF Calendar

**Where things stand today.** Drag this into a new chat to bring an assistant up
to speed. This file only answers "what is true right now": `PROJECT.md` is what
the plugin is, `DESIGN.md` is color and layout, `CLAUDE.md` is the working rules.

**Last updated:** 2026-08-18, at 3.35.0.

---

## Where things stand

The plugin is at **3.35.0**, built as `sfaf-calendar-3.35.0.zip` in the project
root and pushed to `origin/production-2.0`. Whether it is installed on
resources.sfaf.org is not recorded anywhere in the repo. The tell is the Plugins
screen: if it does not say 3.35.0, the deployment is stale or partial, and that
has explained a "fix that did not work" before.

**3.35.0 changed who can see and edit an event.** Teams are now an access model:
an event may name up to two teams and everybody on either can edit it and read
its registrations, live, without anything being copied onto the event. Read
`PROJECT.md` §5 before touching any route that reads an event or its RSVPs. One
function answers the edit question and `.claude/event-access-test.php` is a
whitelist of all 43 routes that must ask it.

**The scheduled path works end to end.** A morning-of reminder went out
unassisted at 6:58am on 2026-08-18. That was the single most valuable unverified
thing in the system and it is now verified. Cron is a reliability question from
here, not a "does it work" question.

## Cron now depends on an external service

**If reminders stop silently in six months, look at cron-jobs.org first, before
anything in this plugin.** That is the trade 3.34.0 made deliberately, in
exchange for the plugin needing no server configuration and staying portable.

The order matters and getting it wrong means nothing runs at all:

1. Create the external ping: **cron-jobs.org**, every 15 minutes, requesting
   `https://<site>/wp-cron.php?doing_wp_cron`. No parameter, no header, no key.
2. Confirm on **Events > Automation** that the banner says tasks are running and
   the log has grown. Wait for two pings.
3. **Only then** add `define( 'DISABLE_WP_CRON', true );` to `wp-config.php`.

Doing (3) before (2) with a mistyped URL leaves a site where nothing runs and
nothing says so. The readme has the full version under "Scheduled Tasks".

**Not `admin-ajax.php?action=sfaf_cron_ping`.** It runs this plugin's jobs only,
so under `DISABLE_WP_CRON` it would stop every other scheduled job on the site.
`PROJECT.md` §4 has the full reasoning.

**Leave the page-view nudge switched on.** It is not a second scheduler. It is
the request from sfaf.org that lets this site notice the external scheduler has
died: if the pinger stops and nobody visits, no code here runs at all, including
the health check that sends the alert email. The nudge is what closes that.

## In flight

- **3.35.0 is unverified on a live site**, and it is a permission change, so it
  is the one to verify by hand rather than by reading the tests. The three things
  worth doing in order: put somebody in a team, assign that team to an event they
  did not create, and confirm they can open it and see its registrations and
  cannot open anything else. Then take the team off and confirm access goes.
- **3.34.0's Automation screen and confirmation-email buttons are also
  unverified on a live site.** The email was rendered in a browser and looked at;
  it has not been delivered to a mailbox. The test send on the Automation screen
  is how that gets checked.
- **The external ping has not been created yet.** Until it is, the only thing
  driving cron is visitor traffic and the page-view nudge from sfaf.org.
- **The GFMP campaign image is deliberately unmapped.** Both fields their schema
  offers were wrong, so campaigns fall through to the branded placeholder. The
  open action is running the `[PROBE]` tool in `class-sfaf-gfmp.php` against a
  real campaign; the `sfaf_gfmp_image_fields` filter then fixes it live with no
  rebuild, and the probe tool is deleted in the same commit.

## Blocked on other people

| Who | What is needed | Status |
|---|---|---|
| **Aaron** | DNS records for `calendar.sfaf.org` so `events@calendar.sfaf.org` can send | Asked. Until then the From address stays `websites@sfaf.org`. It is a setting, so when the mailbox exists somebody types it into Settings and nothing is deployed. |
| **Val** | The EveryAction JSON file: a **sample with real events** before the adapter is written, and confirmation that his hourly job **writes atomically** (temp name, then rename) so a fetch cannot catch a half-written file | Asked. The field list is in `PROJECT.md` §8. Do not build the adapter against a guessed shape. |
| **Salesforce admin** | The Pardot connected app: client ID and secret, Business Unit ID, a service user, and which OAuth flow | Asked. Campaign IDs already store against events; nothing talks to Pardot. |

## Mark's own testing list

Not yet done, and each matters for a different reason.

1. **Confirm a private event is absent from the Yoast sitemap.** The plugin
   writes Yoast's own noindex meta and registers the sitemap-exclusion filter,
   but nobody has loaded the sitemap and looked. Privacy is a claim about every
   route, and this is the one route that is a third party's code.
2. **Grep the sfaf.org theme for `sfaf_is_in_series` and `sfaf_get_series_name`.**
   Both are defined in `includes/sfaf-template-functions.php` and called nowhere
   inside the plugin. They are theme-facing on purpose, so they cannot be deleted
   until the theme is known not to call them. One grep settles it.
3. **The two-hour pre-event summary, unattended.** The morning-of reminder is
   proved (2026-08-18, 6:58am, unassisted). The summary is the other half and has
   not been seen: it is due two hours before an event starts, which is what the
   15-minute runner in 3.34.0 exists to make accurate, and it needs an event with
   somebody registered and a staff mailbox being watched.
4. **Send yourself the confirmation email** from Events > Automation and look at
   the two Add to calendar buttons. They were rebuilt in 3.34.0 and have been
   seen in a browser, not in a mail client. Outlook on Windows is the one that
   matters, and the specific question is whether the two buttons are the same
   height and whether the calendar glyph loads.

## Open decisions

- **Are ticketed events worth building at all?** GoFundMe Pro already handles
  payment, and an event that needs money taken can be a GFMP campaign imported
  here. Building ticketing would mean money handling, refunds and PCI questions
  this plugin has never had. Undecided.
- **Should "open events at their source" be the default?** It is currently opt-in
  per block. Making it the default changes where every imported event's card
  sends a visitor, on every existing embed, which is why it has not simply been
  switched.

## Queued work

1. **The `/caladmin` design audit**, carrying **106 findings against
   `portal.css`** that have never been enumerated in the repo. The list needs to
   be written down before it can be worked, and it should be split by mechanism
   rather than worked top to bottom.
2. **Simplify the event editor.** Creating an ordinary event is currently a
   parade of checkboxes, most of which a normal event never needs. The shared
   field list pattern means this is a rendering-order and disclosure problem, not
   a data-model one.

## Recent failures worth remembering

Only the ones still live or likely to recur. `PROJECT.md` §7 has the full set
with the mechanisms.

- **Tests that pass while the thing is broken.** Plant the fault a new checker
  should catch and watch it fail, before trusting it. Of nine plants in 3.35.0
  two were caught by nothing, and neither was a hole in the code: the test was
  proving less than it claimed.
- **A rule that loses the cascade, and a rule nobody wrote.** Identical on
  screen, opposite fixes. Ask which before rewriting.
- **Shell strings carrying `$`.** Write the script to a file and run the file.
  Never begin a Bash command with a variable assignment.
- **"It did not take" usually means the edit never landed.** `git log -S` first.

---

*When the situation changes, update this file in the same commit. A build that
ships moves something out of "in flight". An answer from Aaron or Val moves
something out of "blocked". A decision moves out of "open". If what you are
writing would still be true in six months, it belongs in `PROJECT.md` instead.*
