# TESTING.md, SFAF Calendar

**The manual testing backlog.** Everything here is a claim about code that has
never run: there is no WordPress, no database, no browser and no mail in the
build environment, so none of this can be settled by the test suite or by
reading the repository. Each item says what to do and why it needs a person.

**A finished test is DELETED from this file, not marked done.** This is a
backlog, not a record of what has been checked. What a test proved, if it is
worth keeping, belongs in `PROJECT.md`.

**Outstanding: 34 items.** Quick 17, needs real conditions 14, blocked on other
people 3.

---

## 1. Quick — a few minutes at a desk

Opening a screen and looking, loading a URL, pressing a control. Fastest first.

### 1.1 Load the Yoast sitemap and confirm a private event is absent

The one privacy route that runs through a third party's code rather than ours.

### 1.2 Grep the sfaf.org theme for `sfaf_is_in_series` and `sfaf_get_series_name`

Both are theme-facing and called nowhere in this plugin, so they cannot be
deleted until the theme is known not to call them.

### 1.3 Open the WordPress post editor and see whether it shows a Series panel

If it is there, an event can be given two series exactly as it could once be
given two organizers. `PROJECT.md` §7 has why nothing was changed on the guess.

### 1.4 Confirm `uploads/calendar-submissions/` is NOT offered by the caladmin picker

Open **Choose Image** on any event. If a raw submission appears there, the two
folders have collided and unapproved files are being shown to staff.

### 1.5 Upload one picture from caladmin

It must land in `uploads/calendar/` and be offered by the picker straight away.

### 1.6 Open the four screens that carry a picker or an editor

**FAQ Sets**, **the event editor**, **Pending**, and **New or Edit Series**.
Each must return a complete page: it reaches its footer and the sidebar is full
width. FAQ Sets is the one that returned 500 on 3.44.0.

**Worth repeating after any release that touches a screen's assets.**

### 1.7 FAQ editor rows: layout and typography

On **FAQ Sets** in caladmin, and on the FAQ card in the event editor. The
**question sits above the answer** and is full width. The answer box is **about
a paragraph tall** and can be **dragged taller from the grip at its bottom
right**. The text inside it is **Merriweather**, the serif, not the browser
default. Add three or four rows and check they are still readable as separate
rows.

Check the same on the **staff request form** and the **community submission
form**, which draw the same repeater, and in **wp-admin** on a series.

### 1.8 FAQ rows: the ground and the remove button

On **FAQ Sets**, the event editor, both public forms and **wp-admin** on a
series. Each row sits on a light gray ground with its **fields white inside
it**, and four rows read as four rows.

**Remove is a labelled button under the row.** On an **empty** row it removes
with no dialog. On a row where **either** the question or the answer has
anything in it, it asks first. **Type a paragraph into the answer and press
remove without clicking away** — the dialog must still appear, which is the case
that depends on reading the editor rather than the textarea behind it.

### 1.9 The form link and the tab comparison (3.49.0)

Open Pending, press **Get a form link**, then compare a caladmin tab against a
resources tab. Four tabs with the counts you expect, imports and submissions in
one list; both links open in a private window; the favicon is caladmin only, so
anything else changing icon reached too far.

### 1.10 The three filter toggles and the organizer dropdown (3.50.0)

Regenerate an embed block and try the three filter toggles. Then use the
organizer dropdown, here and in an embed. With only Series on, pills must appear
with no category row above them, and choosing one must change the **count**, not
just the visible rows. The organizer filter never worked before 3.50.0.

### 1.11 The combined view in a browser, on the embed

Not only on sfaf.org. Six releases shipped a fault the suite passed, and four of
them were embed-only. The five things to look at are in `PROJECT.md` §1.

### 1.12 The closure treatment, in a browser, at three widths (3.60.0)

Nothing in the build can see a rendered stripe. Put a closure on a day that
**already has two or three events**, then look at the month grid:

- **Full width.** Red diagonal stripes on the cell, a CLOSED chip with the
  closure's name under it, and **the events still legible on their own white
  panels below it**. The cell should have grown rather than crowded.
- **Narrow the window until the grid collapses to dots** (560px of the
  calendar's own column). The name line goes, CLOSED stays.
- **Narrower still.** CLOSED should still be on a solid patch, never read
  directly over the stripes.

Then the **list** view: one card for the whole span, the same two-line label,
the same stripes, the date range beside it. **The grid and the list must look
like the same thing.**

**Check the embed too**, not only sfaf.org. It gets this by calling the same
renderers, and that is exactly the kind of assumption that has broken before.

### 1.13 The rebuilt filter bar, on the EMBED first (3.61.0)

Everything here is a rendered outcome, and the build can see none of it. **Look
at the embed on sfaf.org before looking at the calendar site**, because four of
the six past faults in this area were embed-only and looked correct here.

- **The dropdown against the search field.** They must not be the same object.
  The dropdown has a chevron and a hairline end-cap; the field has a magnifier
  and neither. Open the dropdown: the chevron must turn over and stay turned
  while the menu is open.
- **The chevron and the magnifier are there at all.** Both are elements now, so
  a theme cannot reset them away, but that is the claim being tested. **Two
  arrows on the dropdown means `appearance: none` lost to a host rule** and
  wants a third class; report it rather than working around it.
- **The panel has a ground.** The bar and the groups row are one tinted panel
  with a hairline seam, not two floating rows. If they are two rounded boxes
  with a gap between them, the browser has no `:has()` and that is the
  documented fallback, not a fault.
- **The two pill rows read as different kinds of filter.** Categories are
  rounded and carry a colour dot; groups are square-cornered and carry none. A
  chosen category fills solid; a chosen group gets a tick. Choosing two groups
  must still show two ticks.
- **The category dots match the cards.** The dot on Fundraising must be the
  colour Fundraising events already carry below.
- **The narrow embed, which is the one that has never worked.** Put the block
  in a sidebar column of about 280px on a wide desktop. The search field and
  the dropdown must **stack full width**. If they sit side by side and overflow,
  the container query is not reaching, and that is the exact fault this release
  claims to have fixed.
- **A phone or tablet.** Every chip, pill and field must be at least 44px tall
  there and unchanged on a desktop.
- **Nothing about behaviour changed, so confirm nothing did.** Searching,
  choosing a category and choosing an organizer must each change the **count**,
  not just the visible rows, and must still work past page one.

### 1.14 An online event, everywhere a location renders (3.62.0)

Make one event online, with a real Zoom link, and tick both delivery boxes. Then
**look at every public surface** and confirm the same two words and nothing else.

- **"Online Event" on the event page**, in the sidebar facts, with a video glyph
  rather than a map pin, and **not a link**. There must be **no "Getting there"
  section and no map** anywhere on that page.
- **The cards, the sidebar rows and the month grid** say "Online Event" where
  they used to say a street.
- **View source and search the page for `zoom.us`.** Nothing. Do the same on the
  embed on sfaf.org, and on the JSON-LD block in the head, which must read
  `OnlineEventAttendanceMode` and a `VirtualLocation`.
- **Untick it and confirm the address does NOT come back.** That is the designed
  behaviour and the control says so; what is being checked is that the screen
  agrees with the sentence.
- **With scripting off** the venue picker and the meeting link are both visible
  and both submit, and saving with the tick on still clears the address.

### 1.15 The two calendar files, one with the link and one without (3.62.0)

The `.ics` route is the only place the link leaves this site outside an email,
and it is the one route on this feature the suite can only model.

- Open the online event's page and press **Add to calendar > Apple / Outlook**.
  That file is the PUBLIC one: **it must not contain the link.** Open it in a
  text editor; `LOCATION` reads `Online Event` and there is no `CONFERENCE` line.
- **Then take the .ics link out of the confirmation email** somebody actually
  received and open that. It carries `CONFERENCE` and a `Join:` line in the
  description. **Delete the `&j=...` from the end of that URL and load it again:
  the link must vanish from the file.** If it does not, the token is not being
  checked and that is the whole gate.
- **Import the confirmation's file into Apple Calendar and into Outlook** and
  see whether either offers a Join button from `CONFERENCE`. Neither is required
  to; the description carries the link regardless. This is worth knowing, not
  fixing.

### 1.16 Duplicating a set, and the collapsed list (3.63.0)

Nothing in the build can open a `<details>`, move a caret, or see two sets at
once. On **FAQ Sets** in caladmin, with at least three sets:

- **Every set is closed on arrival**, showing its name and a count in brackets.
  Open two of them: **both stay open.** If opening the second closes the first,
  a `name` attribute has got onto the group and that is the fault.
- **With JavaScript switched off**, they still open and close. That is why they
  are `<details>` and not script.
- **Press Duplicate this set.** The page comes back with the copy **already
  open** and the **caret already in its name field**, named
  `<original> - copy`. If the page opens with nothing focused, `autofocus` is
  being ignored and the list will fill with sets called "copy".
- **Check the original is untouched:** same name, same questions, still there.
- **Rename the copy, change one of its answers, save.** Reopen the original: its
  answer must be unchanged. Then edit the original and reopen the copy: also
  unchanged. That is the copy-not-link guarantee at set level.
- **Duplicate the same set twice.** Two sets both named `<original> - copy` is
  correct and must not be refused.
- **Check an event that already used the original.** Its questions must be
  exactly what they were. Nothing in 3.63.0 touches an event's stored FAQs.

### 1.17 The event editor's FAQ card, after the removal (3.63.0)

Open any event with FAQs.

- **There is no text box and no "Save these as a set" button** in the FAQ card
  header. The header is the word FAQs and nothing else.
- **"Apply a saved FAQ set" still works**, unchanged: choose a set, press Add
  these questions, and the rows arrive underneath the ones already there.
- **On an event with no sets yet**, the panel above the form points at the FAQ
  Sets screen rather than telling somebody to press a control that no longer
  exists.

---

## 2. Needs real conditions

Waiting for an unattended job to fire, a real removal at source, or a real event
with real registrations and real mail.

### 2.14 The joining block in a real confirmation and a real reminder (3.62.0)

**Register a real address for an online event with both delivery boxes ticked**
and read both messages in Outlook on Windows, which is the client that renders
with Word's engine.

- The **Joining online** heading, a **Join the event** button, and the full URL
  under it as text. The URL is there so it can be copied and so a client that
  strips styling still shows it.
- **Then clear the meeting link on the event and register again.** The same
  block must appear saying **"A link to join will be sent before the event."**
  That is the fallback, and it fires off the tick rather than off the link.
- **Untick both boxes and register a third time.** Neither message may carry
  anything about joining, and everything else about both must be unchanged.
- **The morning-of reminder needs the next morning**, or an event dated
  tomorrow. Its copy to the notification list carries the link too, on purpose.

### 2.1 Follow a series, end to end

Open an event that **belongs to a series** and press **Follow this series**.
Enter your address and submit. **Confirm the email arrives**, click the link, and
press the button on the page it opens; it must then say you are following. Check
the record is **active** rather than pending. Then use the **Stop these emails**
link and confirm it asks before it acts.

**Then open a one-off event and confirm there is no button at all.** That is the
gate 3.53.0 added, and it is the half nothing in the build can see.

**Nothing will arrive after that**, because the announcement is part 2. The
confirmation email is the only thing following sends today.

### 2.2 The cancel page, which is registration and not following

Register for an event, then use the cancel link in the confirmation or the
morning-of reminder. The page must ask before it acts and say **cancel your
registration** rather than release your place. Then check the registration really
is cancelled.

**On an event with NO capacity set**, the page must NOT say "Places are
limited", and the success message must not claim the place went back to a count.
On an event **with** a capacity, both sentences belong.

**3.56.0 adds the date.** Both the question and the confirmation must name the
**date and time** on their own line. Do this on **an event in a series**, because
that is the case it exists for: three Thursdays share one title, and the page has
to say which Thursday.

### 2.3 The cancellation alert, which is the new email

Same click as 2.2. When the cancellation goes through, **everybody on that
event's notification list should get an email** naming the event, its date and
time, who cancelled, and the count now. Check the count is the number AFTER the
cancellation, not before.

Then **untick "Alert to your notification list when somebody cancels"** on an
event and confirm cancelling sends nothing, while the other four still send.
Check an event created before this release still sends it, since absent means on.

### 2.4 The staff form, end to end

**This blocks other things.** It is at `/?uc_event_request=1` and nothing links
to it on purpose, so its address has to be shared by hand. Enter your own
sfaf.org address, wait for the link, fill it in, submit. Check it appears in
Pending badged **Staff request**, that opening it shows the read-only panel with
your notes, and that you and the admins each got mail. Then try a non-sfaf.org
address and confirm it is refused. **Use a test event and reject it afterwards.**

### 2.5 The staff form: a saved set, and a question of your own

On the staff form, **pick a saved set, add a question of your own, and submit.**
In Pending, the set's questions must appear FIRST with yours after them, and a
question you typed that is already in the set must appear once. Editing that set
afterwards must not change the event you just submitted.

### 2.6 Submit the community form with a photo

It must land in `uploads/calendar-submissions/` under a **generated** name rather
than the one you sent, the pending row must show the thumbnail, and the event
must NOT be visible until approved.

### 2.7 Rename a `.txt` to `.jpg` and submit it

It must be refused.

### 2.8 One real removal at source

**This blocks other things.** Until a removal has been seen behaving correctly
once, automated fetching stays manual.

**AND AUTOMATED FETCHING IS REPORTED TO BE ON ALREADY**, which is the opposite
of what this item and the Settings panel both say should happen. Either this
test was done and can be deleted, or the safeguard it covers has not been
watched and the switch is ahead of it. **Only Mark can say which**, and until he
does, neither this item nor that copy should be changed.


### 2.9 Watch for the two-hour pre-event summary, unattended

The morning-of reminder is proved; this half has never been seen.

### 2.10 Send every message type and read them in Outlook on Windows

From **Events > Automation**. Are the Add to calendar buttons the same height
with the glyph loaded, and does the changed-event message name the old value as
well as the new?

### 2.11 Teams and cancellation, both untested live

Put somebody in a team on an event they did not create: they must see that
event's registrations and nothing else. Cancel an event with a registration:
deleting must be refused before and allowed after.

### 2.13 What the import gate actually refuses, on the real campaign list (3.58.0)

**The one number nobody in the build can produce.** There is no database here,
so which of the live queue rows the new rules would have excluded can only be
read off a real run. The four rows that prompted the build — SFAF Website
Donations, SFAF Giving Status, Migrated Recurring Donations, SFAF Giving Appeal
June 2026 — all carry past dates and would go on the date rule alone; what has
never been seen is **which campaign types GoFundMe Pro actually returns for this
organization**.

Press **Fetch updates** on Pending and read the report. It now names every
refusal and its reason. Check two things:

- **Nothing that is a real event was refused.** A refusal reading "it is a
  ticketed campaign" would mean the type list is wrong. A refusal reading "its
  date has already passed" on an event that has not happened would mean
  `started_at` on a ticketed campaign is the ticket-sales opening rather than
  the event, which is the one thing the platform's spec does not settle.
- **The count is plausible.** Most GFMP campaigns are expected to be refused.

### 2.12 The fetch box on Pending, against a real scheduled run (3.57.0)

Every state of this box was proved against a stubbed run log, which leaves the
one thing a stub cannot vouch for: that a REAL fetch writes the per-source
breakdown the box reads. Open `/caladmin/pending` after the runner has fired and
confirm the box names each source and says the same thing about that run as the
Automation screen in wp-admin does.

**Then break one source on purpose**, by clearing the Eventbrite token, and
confirm the failure is named at the top of the box rather than left as one line
among several. Put the token back afterwards.

**The staleness line needs an hour of nothing working**, so it is the one state
not worth manufacturing by hand. It is proved in the suite; what a person is
confirming here is that a real log reaches it.

---

## 3. Blocked on other people

Nothing here can move until somebody outside the build answers.

| Who | What is needed | Status |
|---|---|---|
| **Aaron** | DNS records for `calendar.sfaf.org` so `events@calendar.sfaf.org` can send | Asked. From stays `websites@sfaf.org` meanwhile. It is a setting, so nothing needs deploying when the mailbox exists. |
| **Val** | The EveryAction MangoApps Trackers endpoint, plus a **sample response with real events**, and confirmation his hourly job **writes atomically** | Asked. Field list in `PROJECT.md` §8. Do not build the adapter against a guessed shape. |
| **Salesforce admin** | Pardot connected app: client ID and secret, Business Unit ID, service user, OAuth flow | Asked. Campaign IDs store; nothing talks to Pardot. |

---

*Delete an item when it has been done. Add one in the group that matches what it
needs from a person, not the release it came from. Keep the count at the top of
this file in step.*
