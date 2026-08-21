# HANDOVER.md, SFAF Calendar

**Where things stand today.** Drag this into a new chat to bring an assistant up
to speed. This file only answers "what is true right now": `PROJECT.md` is what
the plugin is, `DESIGN.md` is color and layout, `CLAUDE.md` is the working rules.

**Last updated:** 2026-08-21, at 3.49.0.

---

## Where things stand

The plugin is at **3.49.0**, built as `sfaf-calendar-3.49.0.zip` in the project
root and pushed to `origin/production-2.0`. Whether it is installed on
resources.sfaf.org is not recorded anywhere in the repo. The tell is the Plugins
screen: if it does not say 3.49.0, the deployment is stale or partial, and that
has explained a "fix that did not work" before.

**INSTALL THIS ONE BEFORE ANYBODY EDITS ANOTHER EVENT.** On every release from
3.36.0 to 3.40.0, pressing Save in the caladmin event editor cancelled the event
and emailed everybody registered that it was off, and the edit was discarded. It
was reported against 3.39.0 and 3.40.0, but the cause has been there since
3.36.0. See "Events cancelled by a save" below for what to do about the ones it
hit.

**Read `PROJECT.md` §4 and §5 before touching a query over events or a route
that reads one.** Teams are an access model (3.35.0) and cancellation is a state
rather than a status (3.36.0), so a cancelled event is still `publish` with a
date and anything selecting on those two must ask `SFAF_Cancellation` as well.

**The scheduled path works end to end.** A morning-of reminder went out
unassisted at 6:58am on 2026-08-18. That was the single most valuable unverified
thing in the system and it is now verified. Cron is a reliability question from
here, not a "does it work" question.

## Cron now depends on an external service

**If reminders stop silently in six months, look at cron-jobs.org first, before
anything in this plugin.** The order matters and getting it wrong means nothing
runs at all: create the ping first (every 15 minutes,
`wp-cron.php?doing_wp_cron`, no parameter or key), confirm on **Events >
Automation** that tasks are running, and ONLY THEN set `DISABLE_WP_CRON`.
`PROJECT.md` §4 and the readme's "Scheduled Tasks" have the full version,
including why it must not be the admin-ajax route and why the page-view nudge
stays on.

## Events cancelled by a save

**This needs a person, and part of it cannot be undone.** From 3.36.0 to 3.41.0,
every save on the caladmin event editor cancelled the event instead of saving
it. Nothing was deleted, so:

1. **Reinstate them.** Open each affected event in caladmin and press Reinstate
   on the cancel card. The event returns to exactly what it was.
2. **Find them** two ways, and use both. In caladmin, anything showing as
   cancelled that nobody meant to cancel. And ask whoever edits events which
   ones they touched since 3.36.0 went on, because a save that was meant to fix
   a typo is the shape of this.
3. **A second save put it back on, so the cancelled list is not the whole
   list.** Once cancelled, the card showed its reinstate form instead, and that
   form's field joined the event form the same way, so saving again silently
   un-cancelled it. An event that was edited twice looks completely normal today
   and its registrants were still told it was off. This is why step 2 asks
   people what they touched rather than trusting the screen.
4. **The emails cannot be unsent, and a correction has to come from a person.**
   Everybody confirmed and everybody subscribed on each affected event was told
   it was cancelled, one message each. There is no route in this plugin for a
   correction. Each event's registrations screen lists them; write to that list
   yourself.

How many were affected is not knowable from the repo, and the plugin keeps no
log of sent mail. `_uc_cancelled_at` on each event is the timestamp of the save
that did it, which is what makes step 2 checkable rather than a guess, for the
events that were not saved a second time.

## In flight

- **SOME EVENTS MAY HAVE LOST AN ORGANIZER ALREADY.** Until 3.40.0 the caladmin
  editor showed only the first and its save replaced the rest, so an event given
  two through the WordPress post editor and later saved here lost one silently,
  with no log of it. If Eric knows of co-hosted events from before now, open them
  and check. Nothing to do if organizers were only ever set in caladmin.
- **CHECK WHETHER THE WORDPRESS POST EDITOR SHOWS A SERIES PANEL.** One look
  settles it. Venue is closed (`show_ui => false`, no screen anywhere), but
  `uc_series` is `show_ui => true` with `meta_box_cb => false`, which only
  closes the classic metabox, and events open in the block editor, which
  chooses its taxonomy panels off `show_ui`. If a Series panel is there, an
  event can be given two series the same way it could be given two organizers,
  and a caladmin save would then drop one silently. If it is not there, the
  limit is real. Nothing was changed on the guess, because those flags also
  decide the public archive and the satellite payload.
- **THE RICH TEXT EDITOR IS STILL THE FIRST THING TO CHECK.** caladmin builds
  its own document rather than running through `wp_head`, so TinyMCE is being
  started somewhere it usually is not. Open any event and look at the Description
  field. If it is a toolbar, it works. If it is a plain textarea showing tags,
  the scripts did not start; nothing is lost and nothing is broken, but it needs
  the enqueue chased. This could not be verified from the repo.
- **3.41.0 needs one pass, and it is the pass that matters most.** On a
  repeating event: open it, answer the scope question, change something, Save.
  The event must still be published, must NOT be cancelled, the change must be
  there, and the scope question must not be asked again. Then press Enter in the
  title field and confirm it saves rather than unpublishing. Then check the left
  button on a published event reads Save and not Save Draft, and that the
  organizer card no longer offers "Not listed? Add one". The cancel card is
  below the form now rather than in the side column, and its state line and its
  legend changed size: that is the type scale being applied, not a mistake.
- **OPEN ALL FOUR SCREENS THAT CARRY A PICKER OR AN EDITOR, AND CONFIRM EACH
  RETURNS A PAGE.** This is the pass nothing in the build can do: there is no
  WordPress in the build environment, so no check there proves a screen loads.
  The four are **FAQ Sets**, **the event editor**, **Pending** and **New or Edit
  Series**. FAQ Sets is the one that returned 500 on 3.44.0. On each, confirm
  the page is complete to the footer, the sidebar is full width, and the FAQ
  answer is an editor with a toolbar rather than a small plain box. **Do this
  after every release that touches a screen's assets.**
- **CHECK CHOOSE IMAGE ON NEW SERIES AND ON EDIT SERIES.** It did nothing at
  all before 3.43.1 and now shares the event editor's control, so the picker
  should open on the calendar folder and an upload from it should land there.
  Worth one look on the event editor and the pending queue too, since that
  control was rewritten to come from the same renderer even though it was
  working.
- **THE REQUEST FORM NEEDS ONE END-TO-END PASS, AND ITS ADDRESS NEEDS SHARING.**
  It is at `/?uc_event_request=1`, which nothing links to on purpose: send that
  address to staff yourself, or have it put on the intranet. The pass: enter
  your own sfaf.org address, wait for the link, fill the form in, submit. Check
  that the request appears in Pending marked **Staff request** with your name on
  it, that opening it shows the read-only panel with your notes, that you got a
  copy by email, and that Mark and Eric each got one. Then try the same with a
  non-sfaf.org address and confirm it is refused. **Use a test event**, and
  reject it afterwards.
- **Nothing decides who counts as an approver except the Admin role.** If
  somebody new should be told about requests, give them Admin on the Users
  screen; there is no separate list to maintain, and there deliberately is not.
- **CONFIRM THE CALENDAR FOLDER IS REAL ON DISK. The picker in 3.42.1 assumes
  it, and the screen will tell you if it is wrong.** Open any event and press
  Choose Image. If it shows the calendar pictures, the assumption holds and
  there is nothing to do. If it opens empty, the field above it will already be
  saying so: the folder is not where WordPress records those files, and the
  filter needs pointing at whatever `_wp_attached_file` actually holds. The
  five-second version without opening caladmin: in Media, hover an event photo
  and read its URL. `/wp-content/uploads/calendar/latino.jpg` is what this
  release expects; `/wp-content/uploads/2026/08/latino.jpg` means WP Media
  Folder is keeping the folder in the database only, and `SFAF_Media_Folder`
  would have to match on its taxonomy instead, which is the dependency the
  current version was written to avoid.
- **Then upload one picture from caladmin and check where it lands.** It should
  appear at `uploads/calendar/`, and be offered by the picker straight away.
  That is the half that cannot be checked from the repo at all.
- **3.42.0 IS THE ONE TO EXERCISE WITH A REAL REGISTRATION, and use a test
  event.** On an event somebody is registered for, change the end time and press
  Save. A dialog must appear naming how many people and showing the old time and
  the new one. Press "Save without telling them": the change must be saved, no
  mail must arrive, and the flash must say nobody was emailed. Do it again and
  press "Save and email them": the mail arrives and the flash counts it. Then
  change only the DESCRIPTION and save, and confirm no dialog appears at all.
  Then press Cancel on the dialog and confirm nothing was saved and everything
  typed is still on the form. The cancel card asks the same question now, and it
  did not ask anything at all before: `data-uc-confirm-cancel` was on the form
  and nothing read it, so cancelling was one click with no confirmation.
- **3.39.0 and 3.38.0 need one pass over the editor.** On an event somebody is
  registered for, change a time and check the dialog names them, including
  anybody who only pressed **Get Reminders**, who used to be invisible to it.
  Cancel on the scope modal leaves. A three-day closure marks three grid
  squares and shows ONE list card. Picking a series first offers the prefill and
  asks before overwriting a typed location. No caladmin card wears a coloured
  left edge.
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
- **THE COMBINED VIEW NEEDS A PASS IN A BROWSER AFTER EVERY RELEASE THAT TOUCHES
  IT, AND ON THE EMBED, NOT ONLY ON SFAF.ORG.** Six releases running it shipped
  a fault the suite passed, and four of the six were embed-only. Nothing in the
  build lays anything out, so this cannot be closed by a check. The five things
  to look at are in `PROJECT.md` §1, under "The combined view is one calendar".
- **PUT THE TURNSTILE KEYS IN BEFORE SHARING THE COMMUNITY FORM'S ADDRESS.**
  Events > Integrations > Cloudflare Turnstile, both keys, from the account
  Gravity Forms already uses here. **Without both, no widget is drawn at all**,
  and the form is then protected only by the honeypot and the rate limits. It
  still works, so nothing will tell you it is missing except that panel.
- **MAKE THE Cycle to Zero SERIES, AND CHECK ITS PICTURE, BEFORE SENDING
  ANYBODY THE LINK.** The form's address is `/?uc_event_submit=<series-slug>`,
  the banner is that series' image and the heading is its name, so a series with
  no picture gives a form with no banner. An address naming no series says the
  link is not right, which is also what a typo in the slug looks like.
- **3.46.0 NEEDS ONE END-TO-END PASS ON EACH FORM, AND THE UPLOAD IS THE PART
  NOTHING IN THE BUILD CAN TOUCH.** There is no WordPress and no browser here,
  so every claim about a real file is a claim about code that has never run.
  On the community form: submit with a photo, and check it appears in
  `uploads/calendar-submissions/` with a generated name rather than the one you
  sent, that the pending row shows the thumbnail badged **Community
  submission**, that admins got an email and you got a copy, and that the event
  is NOT visible until approved. Then try a `.txt` renamed to `.jpg` and
  confirm it is refused. Then submit with no picture and confirm that is
  normal. On the staff form: check the description is a toolbar rather than a
  plain box, that formatting survives the save, and that sending a photo does
  NOT become the event's image.
- **CHECK THAT `uploads/calendar-submissions/` DOES NOT APPEAR IN THE CALADMIN
  PICKER.** Open Choose Image on any event. If a raw submission is offered
  there, the two folders have collided and the picker is showing unapproved
  files. Nothing else in the release depends on that separation holding.
- **THE TEST CTZ EVENT FROM 3.46.0 IS STILL THERE AND SHOULD NOW BE VISIBLE.**
  It is post 60379, status `pending`, and 3.47.0 is what makes the queue show
  it. Open **Pending** after installing: it should be in the list, badged
  **Community submission**. Reject it once you have seen it. **If it is still
  missing, stop and say so**, because then the exclusion is something other
  than the author filter and this release fixed the wrong thing.
- **AND THE ORPHAN EMAIL SHOULD STOP NAMING IT.** The daily check ran and
  reported it with "a deleted account" as its organizer, which was a false
  positive: nobody had been given it yet. A submission awaiting review is
  exempt now. **An approved one with no organizer still fires**, which is the
  case the alert is actually for, so do not read a silent morning as the alert
  being switched off.
- **THE APPROVAL PROMPT SENDS REGISTRANT DATA OUTSIDE SFAF, AND IT IS TICKED BY
  DEFAULT.** Approving a submission now asks two things. The second one puts the
  submitter's address on the event's notification list, which means they get an
  alert each time somebody registers AND the morning-of summary listing
  **everybody registered, by name and email address**. That is right for the
  person running the event and wrong for anybody else, so **read the name on the
  prompt before pressing Approve**. Untick it when the submitter is not the
  organizer. Nothing is sent for an event whose submitter left no usable
  address, and the prompt says so instead of offering the ticks.
- **THE 30-DAY COOKIE ON THE STAFF FORM NEEDS ONE PASS ON A SHARED MACHINE.**
  Follow a link, submit, close the browser, and come back to
  `/?uc_event_request=1`: it should open the form directly and say which
  address it is about to submit as. Press **Not you? Use a different address**
  and confirm it goes back to asking for an address. That control is the whole
  reason this is safe on a machine two people use.

## Blocked on other people

| Who | What is needed | Status |
|---|---|---|
| **Aaron** | DNS for `calendar.sfaf.org` so `events@calendar.sfaf.org` can send | Asked. From stays `websites@sfaf.org` meanwhile. It is a setting, so nothing is deployed when the mailbox exists. |
| **Val** | The EveryAction JSON file: a **sample with real events**, and confirmation his hourly job **writes atomically** | Asked. Field list in `PROJECT.md` §8. Do not build against a guessed shape. |
| **Salesforce admin** | Pardot connected app: client ID and secret, Business Unit ID, service user, OAuth flow | Asked. Campaign IDs store; nothing talks to Pardot. |


## Mark's own testing list

Not yet done, and each matters for a different reason.

1. **Load the Yoast sitemap and confirm a private event is not in it.** The
   noindex meta and the exclusion filter are both written; nobody has looked,
   and this is the one privacy route that is a third party's code.
2. **Grep the sfaf.org theme for `sfaf_is_in_series` and `sfaf_get_series_name`.**
   Theme-facing on purpose and called nowhere in the plugin, so they cannot be
   deleted until the theme is known not to call them.
3. **Watch for the two-hour pre-event summary, unattended.** Needs an event with
   somebody registered and a staff mailbox being watched. The morning-of
   reminder is proved; this is the other half and has never been seen.
4. **Send yourself every message type** from Events > Automation and read them
   in Outlook on Windows, which is the client that breaks things. Are the Add to
   calendar buttons the same height with the glyph loaded (3.34.0), and does the
   changed-event message name the old value as well as the new one (3.36.0)?

5. **Open Pending and look at the one list.** 3.49.0 replaced three stacked
   blocks with a single list and a filter. Confirm the four tabs show the counts
   you expect, that an imported event and a submission sit in the same list, and
   that Dismissed is still its own card underneath. The build renders this
   screen and asserts it, so what is left to check by eye is that it READS well
   at real row counts rather than that the rows are correct.
6. **Press "Get a form link" on the dashboard and copy both.** Paste each into a
   private window. The staff one should ask for an sfaf.org address; the
   community one should open the form for whichever campaign was picked. Every
   caladmin user sees this control, not only admins.
7. **Look at a caladmin tab beside a resources.sfaf.org tab.** The favicon is new
   and is caladmin only. If resources itself, an event page or either public
   form has changed icon, something reached further than it should have.

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

- **Tests that assert something other than the behaviour that matters.** Three
  releases running, and 3.41.0 treats it as the finding rather than a footnote.
  Write the assertion in the words of the OUTCOME, then find a way to decide it;
  `.claude/save-outcome-test.php` is what that looks like here. §7 has all three
  instances.
- **And it happened again inside the fix.** The type scale sweep written for
  this release matched `(\d+)px`, so it could not see `13.5px`, which is the one
  value the ladder exists to forbid, and its self-test passed because every case
  in it was a whole number. Six real rules were hiding behind it. **A new
  checker gets a case for the shape you have NOT already seen**, not only the
  one that prompted it.
- **Tests that pass while the thing is broken.** Plant the fault, and check what
  the STUBS do. A stub that cannot read its input does not test its input.
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
