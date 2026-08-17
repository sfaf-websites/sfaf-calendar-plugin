# HANDOVER.md, SFAF Calendar

**Where things stand today.** Drag this into a new chat to bring an assistant up
to speed on the situation.

This is not the software. `PROJECT.md` is what the plugin is and how it fits
together; `DESIGN.md` is color, type and layout; `CLAUDE.md` is the standing
working rules and the build gate. Read `PROJECT.md` first if you need to know how
something works. This file only answers "what is true right now".

**Last updated:** 2026-08-17, at 3.32.0.

---

## Where things stand

The plugin is at **3.32.0**, built as `sfaf-calendar-3.32.0.zip` in the project
root and pushed to `origin/production-2.0`. The last three releases were all the
combined display mode: 3.30.0 introduced it, 3.31.x fixed it rendering nothing
and then rendering 40px strips, and 3.32.0 made its right-hand column the sidebar
renderer rather than a second card list. Whether 3.32.0 is installed on
resources.sfaf.org is not recorded anywhere in the repo. The tell is the Plugins
screen: if it does not say 3.32.0, the deployment is stale or partial, and that
has explained a "fix that did not work" before.

## In flight

- **3.32.0 is unverified on a live site.** The combined mode has been proved by
  rendered-output parity tests at 770px and 1000px, not by a person looking at
  resources.sfaf.org. Three releases in a row shipped a combined-mode defect that
  its own passing tests could not see.
- **The GFMP campaign image is deliberately unmapped.** Both fields their schema
  offers were tried and both were wrong, so campaigns currently fall through to
  the branded placeholder. The `sfaf_gfmp_image_fields` filter can restore a
  candidate on a live site without a rebuild once the probe says which field is
  right. Running that probe against a real campaign is the open action.
- **The `[PROBE]` tool in `class-sfaf-gfmp.php` is temporary** and marked for
  deletion in one commit, once GFMP's payloads are settled.

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
3. **The overnight test: does a morning-of reminder and a two-hour pre-event
   summary actually send?** This is the single most valuable unverified thing in
   the whole system. Nothing in the scheduled path has ever run unattended in
   production, and the failure mode is silent. It needs a real event, a real
   registration, and somebody checking a mailbox the next morning.

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

- **Tests that pass while the thing is broken.** Three combined-mode releases in
  a row. Counting events is not looking at them; asserting a panel exists is not
  asserting a visitor can see it. Before trusting a new checker, plant the fault
  it should catch and watch it fail.
- **A correct rule that never reaches the screen.** A one-class component rule
  under a class-plus-element base rule loses, silently. Compute the cascade
  before rewriting anything in CSS.
- **A comment quoting the line it replaced.** Two builds running, a source sweep
  matched its own explanatory comment and reported a false positive. Strip
  comments before sweeping CSS or JS.
- **Shell strings carrying `$`.** This has damaged the generator twice and
  shipped a parse error once. Write the script to a file and run the file. Never
  begin a Bash command with a variable assignment.
- **"It did not take" usually means the edit never landed.** Twice.
  `git log -S"<exact string>"` before changing anything.

---

*When the situation changes, update this file in the same commit. A build that
ships moves something out of "in flight". An answer from Aaron or Val moves
something out of "blocked". A decision moves out of "open". If what you are
writing would still be true in six months, it belongs in `PROJECT.md` instead.*
