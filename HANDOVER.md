# HANDOVER.md, SFAF Calendar

**Where things stand today.** Drag this into a new chat to bring an assistant up
to speed. This file only answers "what is true right now": `PROJECT.md` is what
the plugin is, `DESIGN.md` is color and layout, `CLAUDE.md` is the working rules.

**Last updated:** 2026-08-18, at 3.40.0.

---

## Where things stand

The plugin is at **3.40.0**, built as `sfaf-calendar-3.40.0.zip` in the project
root and pushed to `origin/production-2.0`. Whether it is installed on
resources.sfaf.org is not recorded anywhere in the repo. The tell is the Plugins
screen: if it does not say 3.40.0, the deployment is stale or partial, and that
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

**Not `admin-ajax.php?action=sfaf_cron_ping`**, which would stop every other
scheduled job on the site. **Leave the page-view nudge on**: it is the request
from sfaf.org that lets this site notice the scheduler has died. `PROJECT.md`
§4 has both in full.

## In flight

- **SOME EVENTS MAY HAVE LOST AN ORGANIZER ALREADY.** Until 3.40.0 the caladmin
  editor showed only the first and its save replaced the rest, so an event given
  two through the WordPress post editor and later saved here lost one silently,
  with no log of it. If Eric knows of co-hosted events from before now, open them
  and check. Nothing to do if organizers were only ever set in caladmin.
- **THE RICH TEXT EDITOR IS STILL THE FIRST THING TO CHECK.** caladmin builds
  its own document rather than running through `wp_head`, so TinyMCE is being
  started somewhere it usually is not. Open any event and look at the Description
  field. If it is a toolbar, it works. If it is a plain textarea showing tags,
  the scripts did not start; nothing is lost and nothing is broken, but it needs
  the enqueue chased. This could not be verified from the repo.
- **3.39.0 and 3.38.0 need one pass over the editor.** On an event somebody is
  registered for, change a time and check the prompt appears naming them; check
  Save does not ask the scope question twice and Cancel on the scope modal
  leaves. Then: a three-day closure marks three grid squares and shows ONE list
  card; picking a series first offers the prefill and asks before overwriting a
  typed location; no caladmin card wears a coloured left edge. The prompt fix
  matters most where the only interest is people who pressed **Get Reminders**:
  they were invisible to it.
- **Older releases still unverified live.** The two worth doing are the ones
  that touch data: put somebody in a team, assign it to an event they did not
  create, and confirm they see that event's registrations and nothing else
  (3.35.0); and cancel an event with a registration, read the email, and confirm
  deleting is refused before cancelling and allowed after (3.36.0).
- **The external ping has not been created yet.** Until it is, the only thing
  driving cron is visitor traffic and the page-view nudge from sfaf.org.
- **The GFMP campaign image is deliberately unmapped**, so campaigns show the
  branded placeholder. Run the `[PROBE]` tool in `class-sfaf-gfmp.php` against a
  real campaign, fix it live through `sfaf_gfmp_image_fields`, delete the probe.

## Blocked on other people

| Who | What is needed | Status |
|---|---|---|
| **Aaron** | DNS for `calendar.sfaf.org` so `events@calendar.sfaf.org` can send | Asked. From stays `websites@sfaf.org` meanwhile. It is a setting, so nothing is deployed when the mailbox exists. |
| **Val** | The EveryAction JSON file: a **sample with real events**, and confirmation his hourly job **writes atomically** | Asked. Field list in `PROJECT.md` §8. Do not build against a guessed shape. |
| **Salesforce admin** | Pardot connected app: client ID and secret, Business Unit ID, service user, OAuth flow | Asked. Campaign IDs store; nothing talks to Pardot. |

## Mark's own testing list

Not yet done, and each matters for a different reason.

1. **Confirm a private event is absent from the Yoast sitemap.** The plugin
   writes Yoast's own noindex meta and registers the sitemap-exclusion filter,
   but nobody has loaded the sitemap and looked. Privacy is a claim about every
   route, and this is the one route that is a third party's code.
2. **Grep the sfaf.org theme for `sfaf_is_in_series` and `sfaf_get_series_name`.**
   Theme-facing on purpose, called nowhere in the plugin, so they cannot be
   deleted until the theme is known not to call them. One grep settles it.
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

- **Are ticketed events worth building at all?** GFMP already handles payment
  and a paid event can be a campaign imported here. Building it would mean money
  handling, refunds and PCI questions this plugin has never had. Undecided.
- **Should "open events at their source" be the default?** Opt-in per block
  today. Switching it changes where every imported event's card sends a visitor
  on every existing embed, which is why it has not simply been done.

## Queued work

1. **The `/caladmin` design audit**, 106 findings against `portal.css` never
   written down. Enumerate first, split by mechanism.
2. **Simplify the event editor.** A parade of checkboxes, and four more cards
   since 3.35.0. A rendering-order and disclosure problem, not a data-model one.

## Recent failures worth remembering

Only the ones still live or likely to recur. `PROJECT.md` §7 has the full set
with the mechanisms.

- **Tests that pass while the thing is broken.** Plant the fault, and check what
  the STUBS do: 3.39.0's live bug survived every 3.36.0 test because none seeded
  a `subscribed` row, and correcting that found the $wpdb stub had silently
  stopped filtering when the real query changed to `IN (...)`. A stub that
  cannot read its input does not test its input.
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
