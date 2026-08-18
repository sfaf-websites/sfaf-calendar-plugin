# HANDOVER.md, SFAF Calendar

**Where things stand today.** Drag this into a new chat to bring an assistant up
to speed. This file only answers "what is true right now": `PROJECT.md` is what
the plugin is, `DESIGN.md` is color and layout, `CLAUDE.md` is the working rules.

**Last updated:** 2026-08-18, at 3.37.0.

---

## Where things stand

The plugin is at **3.37.0**, built as `sfaf-calendar-3.37.0.zip` in the project
root and pushed to `origin/production-2.0`. Whether it is installed on
resources.sfaf.org is not recorded anywhere in the repo. The tell is the Plugins
screen: if it does not say 3.37.0, the deployment is stale or partial, and that
has explained a "fix that did not work" before.

**Two releases changed rules that other code has to respect.** 3.35.0 made teams
an access model: one function answers who may edit an event and
`.claude/event-access-test.php` is a whitelist of every route that must ask it.
3.36.0 added cancellation as a state: a cancelled event is still `publish` with a
date, so anything selecting on those two must ask `SFAF_Cancellation` as well.
Read `PROJECT.md` §4 and §5 before touching a query over events or a route that
reads one.

**The scheduled path works end to end.** A morning-of reminder went out
unassisted at 6:58am on 2026-08-18. That was the single most valuable unverified
thing in the system and it is now verified. Cron is a reliability question from
here, not a "does it work" question.

## Cron now depends on an external service

**If reminders stop silently in six months, look at cron-jobs.org first, before
anything in this plugin.** That is the trade 3.34.0 made deliberately, in
exchange for the plugin needing no server configuration and staying portable.

The order matters and getting it wrong means nothing runs at all: create the
ping first (**cron-jobs.org**, every 15 minutes, `wp-cron.php?doing_wp_cron`,
no parameter or key), confirm on **Events > Automation** that tasks are
running, and ONLY THEN set `DISABLE_WP_CRON`. Doing that last step first
with a mistyped URL leaves a site where nothing runs and nothing says so.
The readme has the full version under "Scheduled Tasks".

**Not `admin-ajax.php?action=sfaf_cron_ping`.** It runs this plugin's jobs only,
so under `DISABLE_WP_CRON` it would stop every other scheduled job on the site.
`PROJECT.md` §4 has the full reasoning.

**Leave the page-view nudge switched on.** It is not a second scheduler. It is
the request from sfaf.org that lets this site notice the external scheduler has
died: if the pinger stops and nobody visits, no code here runs at all, including
the health check that sends the alert email. The nudge is what closes that.

## In flight

- **3.36.0 is unverified on a live site.** Worth doing by hand, in order: cancel
  an event that has a registration and confirm the email arrives and reads right;
  confirm the event still appears on the public calendar marked cancelled and its
  Register button is gone; confirm deleting it is refused before cancelling and
  allowed after. Then move a date on an event with a registration and check the
  email names the OLD date as well as the new one.
- **3.37.0 is unverified on a live site.** Two things worth doing: add an
  organizer from the Organizers tab and confirm it appears in the event editor's
  picker; then, on a half-typed event, use the picker's "Not listed? Add one"
  box and confirm saving keeps everything else you typed.
- **3.35.0 is unverified on a live site**, and it is a permission change. Put
  somebody in a team, assign that team to an event they did not create, confirm
  they can open it and see its registrations and nothing else, then take the team
  off and confirm access goes.
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
| **Salesforce admin** | The Pardot connected app: client ID and secret, Business Unit ID, a service user, OAuth flow | Asked. Campaign IDs store against events; nothing talks to Pardot. |

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
4. **Send yourself every message type** from Events > Automation and read them
   in Outlook on Windows, which is the client that breaks things. Two specific
   questions: are the Add to calendar buttons the same height with the glyph
   loaded (3.34.0), and does the changed-event message name the old value as
   well as the new one (3.36.0). None of these has been seen in a mail client.

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

1. **The `/caladmin` design audit**, **106 findings against `portal.css`** never
   written down in the repo. Enumerate them first, split by mechanism.
2. **Simplify the event editor.** A parade of checkboxes, most of which a normal
   event never needs, and two more cards since 3.35.0. A rendering-order and
   disclosure problem, not a data-model one.

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
