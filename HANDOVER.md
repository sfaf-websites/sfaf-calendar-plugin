# HANDOVER.md, SFAF Calendar

**Where things stand today.** Drag this into a new chat to bring an assistant up
to speed. This file answers only "what is true right now". `PROJECT.md` is what
the plugin IS and why, `DESIGN.md` is color and layout, `CLAUDE.md` is the
working rules. Anything here that is still true in six months belongs in one of
those instead.

**Last updated:** 2026-08-25, at 3.55.0.

---

## What shipped last

**3.55.0**, built as `sfaf-calendar-3.55.0.zip` in the project root, committed
and **pushed to `origin/production-2.0`**. The working tree is clean apart from
one stray PNG that is not part of the plugin.

> **3.53.0 ADDED A TABLE**, `uc_series_followers`, and took `SFAF_DB_VERSION` to
> `6`. 3.54.0 adds nothing, but if the site is still on 3.52.0 this still
> applies. The table is created on load as well as on activation, so overwriting
> the folder is enough; if anything about following throws "table doesn't
> exist", that is the check that did not run.

The last four releases, so a fresh chat knows what is recent:

| | |
|---|---|
| **3.54.0** | Follow copy reads as an invitation. The confirm page is styled and loses Weglot's language switcher. FAQ rows put the question above the answer, in a taller resizable box in the brand font. |
| **3.53.0** | Get Reminders becomes Follow this series, recorded against the series term in its own table, confirmed by email before it is active. **Part 1 of 2.** |
| **3.52.0** | The staff request form can send FAQs: a saved set, its own questions, or both. The set is COPIED, so editing it later does not change events already submitted. The community form deliberately gets no set picker. |
| **3.51.0** | Get a form link is a primary button. FAQ answers on the community form are rich text, with the plumbing two public pages needed for a deferred editor to start at all. |

**Whether it is installed on resources.sfaf.org is not recorded anywhere in the
repo.** The tell is the Plugins screen: if it does not say 3.55.0, the
deployment is stale or partial, and that has explained a "fix that did not work"
before.

> **INSTALL BEFORE ANYBODY EDITS ANOTHER EVENT.** On every release from 3.36.0
> to 3.40.0, pressing Save in the caladmin event editor cancelled the event and
> emailed everybody registered that it was off, and the edit was discarded.
> Fixed in 3.41.0. See "Events cancelled by a save" below for the ones it hit.

**The scheduled path works end to end.** A morning-of reminder went out
unassisted at 6:58am on 2026-08-18. Cron is a reliability question from here,
not a "does it work" question.

## In flight

**Following a series is half built, and the half that is missing is the point of
it.** 3.53.0 established who the followers are and how somebody becomes one.
**Nothing sends a follower anything yet**, and nothing fires when a date is
added to a series.

**Part 2 is the organizer's announcement screen** and is not built: the list of
events in a series that have been added and not yet announced, an envelope state
per row, send and dismiss, and the email naming what was added.
`SFAF_Follow::active_followers()` is the audience it will read, and it is
uncalled on purpose. The reasoning, including why this is a person pressing a
button rather than a hook on occurrence creation, is in `PROJECT.md` §8.

**Somebody can follow a series today and will never hear anything until part 2
ships.** That is the expected state, not a fault. The confirmation email is real
and the unsubscribe link in it works.

**3.54.0 finished the wording and the page**, and touched none of the storage,
the flow or the token lifetimes. The copy reads as an invitation rather than a
settings description, and the page the confirm link opens is styled like a
public event page instead of arriving with no stylesheet at all.

**3.55.0 closed the cancel page, which had the identical fault**, and both pages
now go through one renderer, `sfaf_notice_page()`. That is the fix for the thing
this section warned about for a release: two copies of a document shape meant
the second was still unstyled three releases after the first was fixed. Nothing
about what cancelling does changed.

**Events cancelled by a save.** The bug is fixed; the damage is not. Nothing was
deleted, so each affected event reinstates from its cancel card. Find them two
ways and use both: anything cancelled that nobody meant to cancel, and asking
whoever edits events what they touched since 3.36.0. **A second save silently
un-cancelled it, so the cancelled list is not the whole list**, and
`_uc_cancelled_at` is the timestamp of the save that did it. **The emails cannot
be unsent and there is no route for a correction:** each event's registrations
screen lists who was told, and that has to come from a person.

**Some events may have lost an organizer.** Until 3.40.0 the caladmin editor
showed only the first and its save replaced the rest. If Eric knows of co-hosted
events from before then, open them and check. Nothing to do if organizers were
only ever set in caladmin.

**The external cron ping has not been created yet.** Until it is, the only thing
driving the runner is visitor traffic and the page-view nudge from sfaf.org.
`PROJECT.md` §4 has the order it has to be switched on in; getting that order
wrong leaves the site with no scheduler at all.

Four smaller things waiting on somebody here:

- **The GFMP campaign image is deliberately unmapped**, so campaigns show the
  branded placeholder. Run the `[PROBE]` in `class-sfaf-gfmp.php` against a real
  campaign, fix it live through `sfaf_gfmp_image_fields`, delete the probe.
- **The Turnstile keys are not in.** Both, or no widget is drawn at all and
  nothing says so except that panel. Before the community form is shared.
- **The Cycle to Zero series does not exist.** The form's address is
  `/?uc_event_submit=<series-slug>` and its banner is that series' image, so it
  needs a picture before anybody gets the link.
- **One test event is live:** post 60379, `pending`, badged Community
  submission. Reject it once it has been seen.

## Blocked on other people

| Who | What is needed | Status |
|---|---|---|
| **Aaron** | DNS records for `calendar.sfaf.org` so `events@calendar.sfaf.org` can send | Asked. From stays `websites@sfaf.org` meanwhile. It is a setting, so nothing needs deploying when the mailbox exists. |
| **Val** | The EveryAction MangoApps Trackers endpoint, plus a **sample response with real events**, and confirmation his hourly job **writes atomically** | Asked. Field list in `PROJECT.md` §8. Do not build the adapter against a guessed shape. |
| **Salesforce admin** | Pardot connected app: client ID and secret, Business Unit ID, service user, OAuth flow | Asked. Campaign IDs store; nothing talks to Pardot. |

## Mark's own testing list

**Nothing in the build can do any of these.** There is no WordPress, no
database, no browser and no mail in the build environment, so every one of these
is a claim about code that has never run.

**The ones these three releases added:**

0. **Follow a series, end to end.** Open an event that **belongs to a series**
   and press **Follow this series**. Enter your address and submit. **Confirm
   the email arrives**, click the link, and press the button on the page it
   opens; it must then say you are following. Check the record is **active**
   rather than pending. Then use the **Stop these emails** link and confirm it
   asks before it acts.

   **Then open a one-off event and confirm there is no button at all.** That is
   the gate 3.53.0 added, and it is the half nothing here can see.

   **Nothing will arrive after that**, because the announcement is part 2. The
   confirmation email is the only thing following sends today.

0b. **The page that link opens is styled, and has no language control.** This is
   3.54.0's, and it is the same click as above, so do both at once. The page
   must look like a public event page: brand fonts, the card on the grey ground,
   one yellow button. **It must not show an "English" checkbox or an "Español"
   link.** That control is Weglot's, not ours, and it is suppressed on this page
   only. Check any other page on resources.sfaf.org still has its switcher.

   If the page arrives unstyled, it is not a cascade problem: something has put
   it back through `wp_die()`, which prints no `wp_head`.

0d. **Weglot is off every calendar surface, and only those.** 3.55.0's, and the
   second half is the half that matters. Check the switcher is **gone** from: an
   **event page**, the **series archive** (the "Part of series" badge), a page
   with the **calendar shortcode** on it, the **confirm** and **cancel** pages,
   **both public forms**, and **caladmin**.

   **Then check it is still there on a page with no calendar on it** — the site
   home page, any ordinary content page. If it has gone from those, the scope
   escaped and that is a site-wide change made from inside this plugin. Nothing
   about Weglot itself was touched, so nothing needs undoing there.

0e. **The cancel page, which is registration and not following.** Register for an
   event, then use the cancel link in the confirmation or the morning-of
   reminder. The page must be **styled the same way the confirm page is**, ask
   before it acts, and say **cancel your registration** rather than release your
   place. Then check the registration really is cancelled.

   **On an event with NO capacity set**, the page must NOT say "Places are
   limited", and the success message must not claim the place went back to a
   count. On an event **with** a capacity, both sentences belong.

0c. **The FAQ editor rows.** On **FAQ Sets** in caladmin, and on the FAQ card in
   the event editor. The **question sits above the answer** and is full width.
   The answer box is **about a paragraph tall** and can be **dragged taller from
   the grip at its bottom right**. The text inside it is **Merriweather**, the
   serif, not the browser default. Add three or four rows and check they are
   still readable as separate rows.

   Check the same on the **staff request form** and the **community submission
   form**, which draw the same repeater, and in **wp-admin** on a series.

**The two that block other things:**

1. **The staff form, end to end.** It is at `/?uc_event_request=1` and nothing
   links to it on purpose, so its address has to be shared by hand. Enter your
   own sfaf.org address, wait for the link, fill it in, submit. Check it appears
   in Pending badged **Staff request**, that opening it shows the read-only
   panel with your notes, and that you and the admins each got mail. Then try a
   non-sfaf.org address and confirm it is refused. **Use a test event and reject
   it afterwards.**
2. **One real removal at source**, so automated fetching can be turned on. Until
   a removal has been seen behaving correctly once, the fetch stays manual.

**The editor on the public forms, which the build cannot see at all:**

3. **Open both public forms and add a second FAQ question.** The answer box must
   be an editor with a toolbar, not a plain box, on the FIRST row and on the one
   the button adds. 3.51.0 wired the community form's answers to the shared
   control and fixed two things that stopped any deferred editor starting on a
   page that builds its own document. **Nothing in the repository can confirm
   this**: there is no WordPress, no browser and no logged-out page load here.
   If it is a plain box, the content is still safe and still saves; it is the
   editor that did not start.
   The staff form has FAQs from 3.52.0, so check its answers too.

   **Then, on the staff form: pick a saved set, add a question of your own, and
   submit.** In Pending, the set's questions must appear FIRST with yours after
   them, and a question you typed that is already in the set must appear once.
   Editing that set afterwards must not change the event you just submitted.

**Uploads, which are the part nothing here can touch:**

4. **Submit the community form with a photo.** It must land in
   `uploads/calendar-submissions/` under a **generated** name rather than the
   one you sent, the pending row must show the thumbnail, and the event must NOT
   be visible until approved.
5. **Rename a `.txt` to `.jpg` and submit it.** It must be refused.
6. **Confirm `uploads/calendar-submissions/` is NOT offered by the caladmin
   picker.** Open Choose Image on any event. If a raw submission appears there,
   the two folders have collided and unapproved files are being shown.
7. **Upload one picture from caladmin** and confirm it lands in
   `uploads/calendar/` and is offered by the picker straight away.

**After any release that touches a screen's assets:**

8. **Open all four screens that carry a picker or an editor and confirm each
   returns a complete page**: **FAQ Sets**, **the event editor**, **Pending**,
   and **New or Edit Series**. FAQ Sets is the one that returned 500 on 3.44.0.
   Check the page reaches its footer, the sidebar is full width, and the FAQ
   answer is an editor with a toolbar rather than a small plain box.

**The rest:**

| | What to do | Why it needs a person |
|---|---|---|
| 9 | Open the **WordPress post editor** and see whether it shows a **Series panel**. | If it is there, an event can be given two series as it could two organizers. `PROJECT.md` §7 has why nothing was changed on the guess. |
| 10 | Watch for the **two-hour pre-event summary**, unattended. | The morning-of reminder is proved; this half has never been seen. |
| 11 | Send yourself **every message type** from Events > Automation, read them in **Outlook on Windows**. | Are the Add to calendar buttons the same height with the glyph loaded, and does the changed-event message name the old value as well as the new? |
| 12 | Load the **Yoast sitemap**, confirm a private event is absent. | The one privacy route that is a third party's code. |
| 13 | Grep the sfaf.org theme for `sfaf_is_in_series` and `sfaf_get_series_name`. | Theme-facing, called nowhere here, so they cannot be deleted until the theme is known not to call them. |
| 14 | The **combined view** in a browser, **on the embed**, not only sfaf.org. | Six releases shipped a fault the suite passed; four were embed-only. The five things to look at are in `PROJECT.md` §1. |
| 15 | **3.50.0:** regenerate an embed block, try the three filter toggles. Then use the organizer dropdown, here and in an embed. | With only Series on, pills must appear with no category row above them, and choosing one must change the **count**, not just the visible rows. The organizer filter never worked before 3.50.0. |
| 16 | **3.49.0:** open Pending, press **Get a form link**, then compare a caladmin tab against a resources tab. | Four tabs with the counts you expect, imports and submissions in one list; both links open in a private window; the favicon is caladmin only, so anything else changing icon reached too far. |
| 17 | **Teams and cancellation**, both untested live. | Put somebody in a team on an event they did not create: they must see that event's registrations and nothing else. Cancel an event with a registration: deleting must be refused before and allowed after. |

## Open decisions

**Should saving an imported event keep it in the queue? Mark has not made this
call.** Today the left button on an imported event is **Save Draft**, which sets
the status to `draft` and takes the event **out of the pending queue it was
being reviewed on**; the other button publishes it. There is no third option and
no warning. The fix is one line, adding `uc_imported` to `$keep_status`, and it
was deliberately not made in 3.49.1 because it changes which events stay in the
queue after a save. **Workaround until then:** fill an imported event in one
sitting, or use the **Save these fields** panel on the Pending queue itself,
which writes those fields without touching the status. `PROJECT.md` §1.

**Are ticketed events worth building at all?** GFMP already handles payment and
a paid event can be a campaign imported here. Building it would mean money
handling, refunds and PCI questions this plugin has never had.

**Should "open events at their source" be the default?** Opt-in per block today.
Switching it changes where every imported event's card sends a visitor on every
existing embed, which is why it has not simply been done.

## Queued work

1. **The `/caladmin` design audit.** 106 findings against `portal.css`, never
   written down. Enumerate first, split by mechanism.
2. **Simplify the event editor.** A parade of checkboxes, and four more cards
   since 3.35.0. A rendering-order and disclosure problem, not a data-model one.

## Before touching anything

**Read `PROJECT.md` §7.** It opens with the four standing hazards that used to
be listed here (what Approve sends and to whom, no unsaved-work warning anywhere
in caladmin, a cancelled event still being `publish`, and why the confirm and
unsubscribe pages can have no stylesheet enqueued onto them), and continues with
the lessons that each cost more than one build. They moved in 3.54.0 because
none of them is about today.

---

*When the situation changes, update this file in the same commit. A build that
ships moves something out of "in flight". An answer from Aaron or Val moves
something out of "blocked". A decision moves out of "open". A finished pass
leaves the testing list. If what you are writing would still be true in six
months, it belongs in `PROJECT.md` instead.*
