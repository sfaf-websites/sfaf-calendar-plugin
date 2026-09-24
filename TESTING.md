# TESTING.md, SFAF Calendar

**The manual testing backlog.** Everything here is a claim about code that has
never run: there is no WordPress, no database, no browser and no mail in the
build environment, so none of this can be settled by the test suite or by
reading the repository. Each item says what to do and why it needs a person.

**A finished test is DELETED from this file, not marked done.** This is a
backlog, not a record of what has been checked. What a test proved, if it is
worth keeping, belongs in `PROJECT.md`.

**Outstanding: 194 items.** Quick 163, needs real conditions 28, blocked on other
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
one list; both links open in a private window.

**The favicon note here changed in 3.66.0.** The calendar mark is now expected
on caladmin AND on both forms and the notice page, which is item 1.27. What
would still be reaching too far is an ordinary resources.sfaf.org page changing
icon. Check the dialog says **Event Series** rather than Campaign while it is
open.

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

### 1.18 Walk every caladmin screen and confirm nothing lost an affordance (3.64.0)

**Fourteen rules that styled a text field were replaced by one, and nine of them
were removed rather than reconciled.** The audit proves the standard is the only
declaration left; it cannot prove that no control depended on a rule that is now
gone for something the standard does not supply. That is a looking job.

Open each screen and check every control has a visible edge, that fields and
buttons read as different things, and that nothing is the browser's own chrome:
Dashboard, Events (list and both filter bars), the event editor including the
Repeats switch, the dates picker, the notification picker and the cancel card,
Series, the schedule screen, Categories, Organizers, Venues, FAQ Sets, Pending,
Dismissed, Registrations, Opt-ins, Users and Teams, Settings, and the sign-in
box. **The sign-in box and the Users screen are the two most likely to have
moved**, because both had rules of their own that were deleted outright.

### 1.19 The select's end cap and its chevron, in Firefox and Safari (3.64.0)

The cap is a 1px `linear-gradient` layer and the chevron is a data-URI SVG, both
on the select's own `background-image`, and the chevron swaps for an upward one
on `:focus`. Chromium is what the build was written against. Check a dropdown in
Firefox and in Safari: one cap, one chevron, the chevron turning over while the
menu is open, and no second arrow from the platform.

**A native `<select>` on iOS and on Android draws its own picker**, so also open
one on a phone and confirm the control still opens normally.

### 1.20 An event that takes registrations has no Add to Calendar button (3.64.0)

The one public change in 3.64.0, and the test suite can only prove the gate is
written.

- **On an event with registrations on**, the event page shows the RSVP button
  and **no Add to Calendar button**. Register, and the confirmation email
  carries both destinations, Google and the `.ics`.
- **On an event with registrations off**, the button is there exactly as before.
- **In caladmin**, the Display card's "Add to calendar" tick is greyed while
  Accept RSVPs is ticked, with the line about the confirmation under it. Untick
  Accept RSVPs and it should become settable **without a save**.
- **The one that needs two saves.** With registrations on, tick Add to calendar
  is greyed at whatever it was; save; switch registrations off; save; the tick
  must still be where the manager left it rather than reset to off.

### 1.21 A cancelled event that takes registrations (3.64.0)

**Deliberately not settled in the build, because the rule as written decides
it and somebody should look at the result.** A cancelled event renders no RSVP
button, so nothing can be confused with Add to Calendar, but it still "takes
registrations" by the stored value, so the Add to Calendar button is absent
there too. Open a cancelled event that has registrations on and say whether an
absent button is right. Adding a cancelled event to a calendar is arguably not
wanted either, which is why this was left as it fell rather than special-cased.

### 1.22 Assign an event to a series by hand, then look at that series' schedule (3.64.1)

**THE PATH THIS EXERCISES IS CORRECT IN THE CODE AND HAS NEVER RUN.** The New
Event series control has not rendered since 3.38.0, so nobody has ever
hand-assigned an event to a series through caladmin, and the one-off-in-a-series
case has only ever existed in theory. The suite proves the control is drawn and
that a pattern edit is scoped to the recurrence group. It cannot prove what a
schedule screen looks like with a hand-added event on it, because there is no
WordPress here to make one.

Take a series that has generated dates from a pattern. Then:

- **Create an event from New Event**, choose that series from "Is this part of a
  series?", and **date it BEFORE the next generated occurrence**. That ordering
  is the whole point: it is the case that made the seed wrong.
- **Open the series' schedule screen.** The new event appears in the list,
  marked **one-off**, with its own Edit and Remove.
- **The pattern form still offers a frequency**, and the sentence at the top of
  the card still describes the real repeat. Before 3.64.1 both would have
  described a series with no pattern at all.
- **Change the pattern.** The generated dates move. **The hand-added event does
  not**, and the count in the confirmation does not include it.
- **Open the hand-added event.** It offers no "edit all upcoming occurrences"
  choice, because it is in no recurrence group. That is correct, not a fault.

### 1.23 The schedule screen's "Create a new event in this series" button (3.64.1)

It has carried the series in the URL since 3.38.0 and the editor dropped it.
Press it and confirm the New Event form opens with that series **already
chosen** in the card at the top, and that saving without touching the control
leaves the event in that series.

### 1.24 "Fill these in" with a series that has a picture (3.64.2)

Everything about this is browser behaviour over a real image URL, and the build
environment has neither. Make a series with an image, then open **New Event**
and choose it.

- **On the card**, the Image row shows a small thumbnail of that picture, not
  `something-1024x576.jpg`. Confirm the row is not noticeably taller than the
  five around it, and that a portrait original is cropped rather than stretching
  the row.
- **Press Fill these in.** The preview under **Featured Image** comes up showing
  that picture, **Remove** appears beside it, and the tag beside the label
  changes from **Placeholder** to **Event-specific**.
- **Save, reopen, and confirm the picture stuck** and the tag still says
  Event-specific. Then change the series image and confirm this event does NOT
  follow it, and that **Reset to series image** on the Edit screen puts it back.
- **Repeat with a series whose picture is a chosen library file rather than a
  pasted URL.** That is the case where the URL field is legitimately left empty
  and the preview has to come from the attachment instead. A blank preview here
  is the defect this release was about, in its other form.

### 1.25 The Display card after the reorder (3.64.2)

Open any event editor and look at the **Display** card. The order is RSVP, Add
to calendar, Donate, Social share, Follow the series. Tick **Accept RSVPs** in
the card below and confirm **Add to calendar** greys immediately, without a
save, and that the line under it reads "Because this event takes RSVPs, the
calendar link goes out with the registration confirmation instead." Untick it
and confirm the tick comes back holding the value it had, and the line goes.
Then save with registration on and reopen, and confirm the stored value is
still what the manager chose rather than "off".

### 1.26 The staff form's picture picker, INCLUDING WITH JAVASCRIPT OFF (3.65.0)

The no-script half is the point of this control and nothing in the build can see
a browser. Open the staff request form from a real emailed link.

- **With script.** The closed control says "No picture chosen". Open it: a
  search box, and a scrollable list of thumbnails with each file name beside
  its picture. Type part of a file name and confirm the list narrows and that
  "No pictures match that" appears when nothing does. Press Escape mid-search
  and confirm it clears the box rather than closing the panel. Click a picture:
  the panel closes and the trigger now shows that picture and its name.
- **Then turn JavaScript off and reload.** This is the assertion that matters.
  **The search box should not be there at all.** The control still opens, the
  full list is still in it, a picture can still be chosen, and **submitting the
  form must attach that picture**. Check the pending row afterwards to confirm
  the right one arrived.
- **On a phone.** The list scrolls inside its own box rather than the page, and
  the rows are big enough to hit.
- **File names that are long and have no spaces** must wrap inside the row
  rather than widening the panel.
- **Look for a picture whose title is really its file name.** It should show the
  file name once, on the file-name line, and no title line above it. A row
  reading "harm reduction 2026 a" above "harm-reduction-2026-a.jpg" means the
  exact test added in 3.65.0 is not matching and wants reporting.

### 1.27 The favicon on the four self-built pages (3.66.0)

Nothing in the build can see a browser tab. Open each of these and look at the
tab: **caladmin**, the **staff request form** from a real emailed link, the
**community submission form**, and the page a **follow confirmation link** or a
**registration cancel link** lands on. All four must show the yellow calendar
mark, and it must be the same mark on all four. A blank page icon on any one of
them means that document is not calling the emitter.

**Check Safari too.** It is the reason the PNG exists beside the SVG, and a
browser that takes the SVG and one that takes the PNG must show the same thing.

### 1.28 "All Events" after filling in the Calendar home URL (3.66.0)

**Fill in Settings, Display, Calendar home URL first.** The code half of this
fix does nothing on its own. Then, from a calendar page on sfaf.org, click
through to an event and press **All Events** at the top. It must land on the
page named in that setting, not on the sfaf.org homepage and not on
resources.sfaf.org.

Then check the case that must not have broken: from **resources.sfaf.org/events/**
click an event and press All Events. It should go back to that archive, because
a same-origin referrer still carries its full path.

**A category chip on an event page uses the same resolver**, so try one and
confirm it lands on the calendar page with the filter applied rather than on the
site root.

### 1.29 The team picker on the staff form, WITH SCRIPT OFF (3.66.0)

Open the staff request form from a real emailed link. Under **Who should be able
to edit it**, tick one team and send the request. Then, as somebody who is on
that team but is not an admin, open caladmin and confirm **the pending request is
visible and editable** before anybody approves it. That is the whole point of
writing the team on a pending row.

- **Tick three teams and send.** It must come back with an error naming the
  field, with the three still ticked so you can see what you chose, and nothing
  saved.
- **Turn JavaScript off and repeat both.** Nothing here is built by script and
  nothing should behave differently.
- **Then remove yourself from that team** and confirm the event disappears from
  your list on the next page load, without anything being re-saved.

### 1.30 Heading hierarchy on both request forms (3.66.0)

Open the staff form and the community form and look at them as a whole. The
section headings must read as **headings** rather than as slightly bolder field
labels: About the event, When, Where, The picture, Registration, Who should be
able to edit it on the staff form; About you, Where it happens, Contact for the
event on the community form.

**The one thing to look at closely is the rule above a fieldset heading.** A
`<legend>` normally sits inside its fieldset's top border and cuts it, and the
fix for that is a float. If the rule above **The picture** or **Who should be
able to edit it** runs up to the heading, stops, and starts again after it, the
float has been lost. Check the same in Safari.

### 1.31 The five-address field, INCLUDING WITH JAVASCRIPT OFF (3.67.0)

Open the community form at `/?uc_event_submit=<series-slug>`. **About you** now
shows one email box and an **Add email** button.

- Press **Add email** four times. There must be **five boxes and no button at
  all**, not a greyed-out one. Nothing here removes a box, so the button does
  not come back.
- Fill the first box and one other, submit the rest of the form, and check the
  confirmation arrives **only at the first address**.
- **Turn JavaScript off and reload.** There must be exactly one box, it must
  still be required, and the form must still submit. The Add button does
  nothing without script, which is the state this form has always been in for
  one address.
- Submit with something that is not an address in the second box. It must
  refuse and say so, rather than quietly dropping it.

The validator and the button's disappearance are both proved in the suite. What
needs a person is that a real browser posts the array, that the confirmation
goes where it should, and the no-script path.

### 1.32 Approve a submission that named several addresses (3.67.0)

Send a community submission naming three addresses, then open it in **Pending**
and press **Approve**.

- The panel must name the other two addresses, in full, above the ticks.
- With the registrations tick left on, approve it, then open the event's
  notification list. **All three addresses must be on it**, once each.
- Approve a second time. There must still be one of each.
- The published notice goes to the first address only, and says they will start
  getting mail.

**This puts registrant names and email addresses in front of people outside
SFAF**, which is the whole reason the panel names them. Check the wording says
what it does.

### 1.33 The pending row shows a submitted contact (3.67.0)

Open any community submission in **Pending**. The row must show **Contact for
the listing** with the name, email and phone the submitter typed. It rendered
empty on every submission from 3.47.0 to 3.66.0, so a submission made before
this release is the case to look at: the values were stored the whole time.

Compare it with what the published event page shows under the mail icon. The
two read the same function and must say the same thing.

### 1.34 Event links open a new tab, on the embed and in the shortcode (3.67.0)

On a page carrying the calendar, and again on an embed on a different site,
click through from each of these and confirm a **new tab** opens rather than
the page navigating:

- a card title, a card picture and its **View event** button
- a row in the **Upcoming event dates** sidebar
- an event in a **month grid** day cell
- a compact card, and a row in an **"in this series"** list

Then, in the new tab, press **All Events**. It must still go to the calendar
you came from, which is what proves `rel="noopener"` was used rather than
`noreferrer`. **With a screen reader**, each of those links must announce
"opens in a new tab" as part of its name, and the hidden sentence must not be
visible on screen anywhere.

### 1.35 Both request forms, section by section (3.68.0)

Open the staff form and the community form and look at each as a whole. Every
part of both must sit inside a **panel**: a heading, a hairline edge and a light
ground, the same on every section of both forms.

- Nothing should be loose between panels except the robot check and the submit
  button on the community form.
- **The two that were broken are the picture section and the team section on the
  staff form.** They had no edge and no top padding, so they ran straight on
  from the field above. Check those two hardest.
- Nothing should have changed size or weight. If a heading now looks bigger than
  it did, a type step has crept in and the sweep did not see it.

**The heading's own position and the field widths moved to 1.39** when 3.68.1
fixed the layout fault, which covers strictly more than this item did and adds
the narrow width. Do 1.39 as well; neither replaces the other.

### 1.36 The staff form's series, first, filling the picture (3.68.0)

The series question is now the first thing on the staff form.

- Choose a series that **has a photo**. The picture chooser's closed control
  must show that photo and say **The series picture**, and the top row inside
  the list must show it too.
- Now choose a real picture from the list. The closed control must show the one
  you chose. **Go back to the series select and change the series.** The picture
  you chose must not change. That is the whole guard.
- Choose **Not part of one**. The top row must go back to a dashed empty box
  saying **No picture**.
- Send the request and check the pending row: the event must show the series
  photo without anything having been copied onto it.
- **Turn JavaScript off and reload.** The series select must still be there and
  still post; the picture row says "No picture"; the hint above still explains
  what choosing a series does. Nothing on this form may depend on the script.

### 1.37 An event with no location on the pending queue (3.68.0)

Send a staff request with **no venue and nothing in the "If somewhere else"
box**, which the form allows. On the pending queue that row must carry the amber
mark and say **Needs a location**, exactly as an import needing an image does.

Then check the two that must NOT carry it: an event with a venue, and an
**online** event. An online event has no location on purpose, and a permanent
mark on every one of them would train people to ignore the mark.

### 1.38 The private event copy, on both screens (3.68.0)

Open the private control in caladmin and again in the WordPress post editor.
Both must read the same three sentences. Confirm the third one is there: turning
it on gives the event a new link, so a link already shared stops working.

**Then prove the sentence.** On a test event, copy the public address, tick
private, save, and open the old address. It must not resolve to the event.

### 1.39 Both request forms, section by section, and at 770px (3.68.1)

**This is the half no test covers.** `.claude/section-layout-test.php` proves
the rules; it computes no geometry and opens no browser.

Open the staff form and the community form.

- **Every section's heading must sit ABOVE its first field**, not beside it.
  The two that were wrong in 3.68.0 are **When** on the staff form, where Date
  ended up in a roughly 40px column at the right, and **Where it happens** on the
  community form, where Venue did. Check every section on both forms.
- **The Date input and the Venue select must be full width**, the same width as
  Start, End, the street address and the venue website below them.
- **No hint may wrap one word per line.** The Venue select's hint was doing that
  down a narrow column.
- There must be **no large empty area under a heading**.
- **The panel's edge must draw unbroken above each heading.** If it runs up to
  the heading, stops, and starts again after it, the legend is being placed in
  the border again. Check that in **Safari**, which places legends differently.

**Then do all of it again at 770px**, which is the width these forms render at
inside a host page. Narrow is where a field that is sharing a line with a
heading has least room to hide.
### 1.40 The cancel dialog on an event nobody has registered for (3.72.0)

Open any event with no registrations and press **Cancel this event**.

- **Two buttons, not three**, and neither of them says the same thing twice.
- **Escape closes it**, and so does clicking the dim outside it. Both leave the
  event exactly as it was.
- The way out reads **Go back**.

The click-outside half is the only genuinely new behaviour; Escape has worked
since 3.42.0 and was reported as missing, so it is worth pressing to settle
which of the two was actually the problem.

### 1.41 The cancel dialog on an event that HAS registrations (3.72.0)

Same control, on an event with somebody registered. Press **Cancel and email
them** and stop at the second step.

- A box appears asking for anything to add **just for the people registered**,
  with **Go back** beside it.
- Go back returns to the three buttons with nothing lost.
- The box cannot be reached at all through **Cancel without telling them**.

Do not send. This is about whether the second step appears and returns; 2.20
covers what actually arrives.

### 1.42 A cancelled event on the three public surfaces (3.72.0)

Cancel one event, leave it listed, then look at the calendar as a visitor: the
list, the month grid, and the sidebar if the page carries one.

Each should show the word **Cancelled** above the title. Check the month grid on
a phone too, where the day panel is a copy of the cell's own markup.

### 1.43 Take a cancelled event off the calendar and put it back (3.72.0)

On a cancelled event, press **Remove from the calendar**. It should leave the
public listings, keep its own page working and still saying it is cancelled, and
say plainly that nobody was told. Press the control again to put it back.

**Nothing should arrive in any mailbox from either press.** That is the whole
point of the control existing, and it is the one thing only a person watching
mail can confirm.

### 1.44 Remove on the events list, both paths (3.72.0)

On the events list, find an event with registrations that is not cancelled: the
row should offer **Cancel instead**, not Remove. Find one with none: it should
offer Remove behind the portal's own dialog, whose wording now says the event
goes to the WordPress trash and can be restored.

Then check the trash actually holds it.

### 1.45 The publish picker on a real series (3.72.0)

Open a series with several upcoming drafts. Every eligible date should carry a
ticked box; anything ineligible should carry no box at all and say why.

Untick two and confirm the button's own count follows. Press it, then check that
exactly the ticked ones went public and the two you unticked are still drafts.

### 1.46 The FAQ set controls, and whether the editors start (3.72.0)

Open an event with FAQs on it, with the browser console open.

- **One** FAQ set control on the screen, not two, with **Manage sets** beside it.
- The answers should be editors on load rather than raw markup.
- **If they are still not editors, the console now says why.** Copy whatever it
  says and send it: that message is the whole reason this release touched the
  FAQ editors, and without it the fault cannot be diagnosed from here at all.

### 1.47 The three contact boxes on Listing detail (3.72.0)

Open a community submission that carries a public contact. The card should show
**Name**, **Email** and **Phone** filled in from what was submitted. Change one,
save, and check the **event page** shows the change.

That last step is the point: the old single box stored what was typed and the
event page went on showing something else.

### 1.48 Five-minute steps on the time fields (3.72.0)

On New Event and on both request forms, use the up arrow in a time field: it
should move five minutes at a time. Type a time that is not on a five-minute
boundary and try to submit: the browser should refuse it and name the two
nearest valid times.

Then open an imported event whose stored time is off the boundary, if there is
one, and confirm it can still be saved.

### 1.49 The staff request form's repeat section (3.72.0)

Open the staff request form. The repeat control should be the one caladmin's New
Event has: a Never/Daily/Weekly/Monthly/Custom switch, day circles, an Ends
panel and a live summary.

- Choosing **Never** should hide every field under it.
- Choosing **Weekly** and a day should make the summary say how many dates.

Submit one as a repeating request, then open it in caladmin: **the repeat
control should already be filled in with what was asked for, and no occurrences
should exist yet.** If any dates were created before you pressed Save, stop and
say so: that is the one thing this design must not do.

### 1.50 The series selector, on an edit (3.72.0)

Open an existing event. The series selector should be at the top, in its own
card, and there should be **no second one** further down in the Schedule card.

### 1.51 Help icons on series, teams and private events (3.72.0)

Open New Event and press each new glyph. Three panels, each opening under its
card's heading rather than squeezed into the tinted band.

### 1.52 "Use this image" on the pending row (3.72.0)

Find a community submission that arrived with an uploaded picture. Press **Use
this image** on the row, then open the event and confirm the picture is now its
featured image.

**Then look at how it crops on a card.** A submitted photo is whatever shape the
person had, and cards crop to 16:9, so a tall one loses its top and bottom. That
is expected and is worth seeing once before it happens on a published event.

### 1.53 An upload under 1200 pixels wide (3.72.0)

Submit the community form with a small image. It should be refused with a
message naming both the minimum and the width that arrived, and the form should
say the minimum before a file is chosen.

### 1.55 Approve and Reject on the pending queue (3.73.0)

**THESE WERE DEAD FROM 3.72.0 UNTIL THIS RELEASE** and nobody reported it, so
this is the first item to do.

Open Pending on a community submission or a staff request and press **Approve**.
The two-tick dialog should open. Press **Reject** on another: its dialog should
open, with the note box and the tick.

Then check the other two dialogs the same fault killed: **Get a form link** on
the dashboard, and a destructive control anywhere with a confirmation on it.

**Click the dim outside each one.** It should close and do nothing. That is what
3.72.0 was trying to add and never reached.

### 1.56 The events list actions, and the same list on a phone (3.73.0)

Three icons per row now: a pencil, two panels, and a red cross. Hover each and
read the tooltip.

- **No row wraps onto two lines**, whatever is in it.
- A row whose event has people registered shows a **bell with a line through
  it** instead of the cross. Press it: it should open the event **with the
  cancel panel already open and scrolled to**, and it should NOT ask which
  occurrences to edit.
- An **imported** event with registrations shows a **padlock** and nothing to
  press.

**Then open the same list on a phone or tablet.** There is no hover there, so
what matters is that the three icons are far enough apart to hit one at a time.
Try Edit on a middle row and confirm you do not land on Duplicate.

### 1.57 Bulk add a category (3.73.0)

On the events list, tick three events, choose a category, press the button.

- The button's count follows the ticks, and unticking everything disables it.
- **The events keep the categories they already had.** Check one that already
  had two.
- **Select all** ticks the page.

Then try it on a page that includes **a submission awaiting review and an
imported event**, which are both allowed on purpose, and confirm neither is
published or otherwise changed by it.

**As a contributor**, if there is an account to hand: ticking Select all should
add the category to your own events and report how many were skipped.

### 1.58 The Display RSVP tick greys (3.73.0)

Open an event. Under **Capacity**, untick **Accept RSVPs**. The **RSVP** tick
under Display should grey immediately, with a line saying why, and Add to
calendar should ungrey.

Tick Accept RSVPs again and confirm the RSVP tick comes back **as it was**
rather than unticked. Then save and reload and confirm it is still as it was:
that is the half a disabled control quietly posting nothing would have broken.

### 1.59 The FAQ answers on an event whose questions came from a set (3.73.0)

**This is the case three releases of investigation never looked at.** Open an
event that is NOT imported and apply a FAQ set to it, or type two questions by
hand, then save and reload with the console open.

The answers should be **editors on load**, not raw markup and not plain boxes.

**If they are not, copy whatever the console says.** It now names the element.
That message is the whole route to a diagnosis and there is no browser in the
build environment to get it any other way.

### 1.60 The locked FAQ answers on a GoFundMe Pro event (3.73.0)

Open a GoFundMe Pro event from Pending and look at the questions under the
padlock line.

They should read as **prose**, not as HTML tags. They are still not editable and
still say so, which is correct: a fetch owns them.

### 1.61 "Use this event's details on another date" on a series with several groups (3.73.0)

**The half of this that was fixed can only be confirmed on the site**, because
the disagreement needs a series holding more than one recurrence group and
there is no database here. PROP holds four distinct events, Coffee Social two,
Mobile Health Sites two, and the Strut community events three.

Open the schedule screen on one of those. Read the **Title for this date**
placeholder, which names the event the copy is taken from. Then add a date and
open the event it made.

**The event it copied must be the event the placeholder named**: same location,
description, times, category, organizer and questions. Before 3.73.0 the screen
preferred the next event in the recurrence group and the button took the next in
the series, so on these four series the placeholder could name one event while
the button copied another.

### 1.62 The FAQ answers after applying a set, which is the case that was wrong (3.74.0)

**This is the exact thing Mark reported and the exact thing 3.74.0 changes.**
Open a pending event that is not imported, with the console open. Apply a FAQ
set.

The answers should be **editors immediately**, without pressing anything else.
Before 3.74.0 they were plain boxes until Add a manual question was pressed.

Then press **+ Add FAQ**. The new row should get an editor and **nothing else on
the screen should change**, which is the other half: if every box on the screen
still flickers into an editor at that moment, something is still sweeping rather
than starting one row.

**If a row does not get an editor, copy whatever the console says.** It names
the element id. Silence now means something different from what it meant before:
until 3.74.0 silence meant nothing had asked, and asking is what was added.

### 1.63 The editor's buttons on four kinds of event (3.74.0)

Open one of each and read the row of buttons at the foot of the form.

```
a published event      Save changes                    and nothing else
a draft                Save draft, Publish
a pending submission   Save changes, Approve, Reject   (as a calendar admin)
a pending staff event  Save changes, Publish
```

**Approve and Reject must open the same prompts the Pending queue opens**, with
the same two ticks, and must land where approving from the queue lands. If
Approve opens nothing, or if it publishes without asking, stop and say so: the
two are meant to be the same forms.

**And there should be no Publish on a published event.** That is the whole point
of this change.

### 1.64 Delete on the event editor, and what it refuses (3.74.0)

At the foot of the editor, under the cancel card, there is a **Delete this
event** card.

- On an event with **nobody registered**: Delete asks to confirm, then the event
  goes and the list comes back without it.
- On an event **with registrations that has not been cancelled**: there is no
  button at all, only a line saying to cancel it first. That is the same refusal
  the events list makes, and the card should not offer a control that bounces.

### 1.65 Searching My events without losing letters (3.74.0)

**Type a whole phrase into the search box at an ordinary speed**, with pauses,
on the real 259-row list. Nothing typed should be lost, the caret should never
jump, and the list should update under the box while it stays exactly where it
is.

**The address bar should follow along**, so reloading lands on the same search
and the link can be sent to somebody. **Back should leave the list**, not walk
backwards through the word.

Then press a row's **Remove** icon and tick a box for the bulk category control
**after** a search has happened. Both are rebound when the results are replaced,
and if either is dead after searching, say so: that would be worse than what was
fixed.

### 1.66 The Images screen, as three different people (3.74.0)

**caladmin > Images.** It is new, so everything here is unexercised.

- **As an admin:** the grid shows the calendar folder. Tick two, choose a series,
  press Tag; both should carry it and keep anything they already had. Use the
  per-image dropdown on a third. Take a tag off with the small x and confirm the
  picture is still there. Upload a file and confirm it appears in the grid and,
  more importantly, **in the picker inside an event**.
- **As an editor:** the tagging controls are there and **Add an image is not**.
- **As a contributor:** the grid is there and **no tagging control is**.

**Then Untagged.** It is the filter the screen exists for and the one nobody has
seen work.

### 1.67 The picker inside an event opens on the event's series (3.74.0)

Open an event that is in a series with at least one tagged image and press
**Choose Image**. The library should show that series' pictures only. Press
**All calendar images** beside it and the whole folder should be there.

**On an event with no series, the second button should not be on the screen at
all**, because the first one already shows everything.

**As an editor or a contributor, the modal should have no Upload Files tab.**

### 1.68 The hover preview, including a tile on the bottom row (3.75.0)

**Nothing about this has run in a browser.** Open the calendar on a desktop,
month view, and hover an event tile.

- A panel appears after a short pause, holding the picture, the title, the
  date, the times and the place.
- **Move the mouse across a whole week without stopping.** No preview should
  appear at all until the pointer rests on one.
- **Hover a tile on the LAST ROW of the month**, with the grid scrolled so the
  tile is near the bottom of the window. The panel must flip ABOVE the tile
  rather than opening off screen. That is the case the positioning exists for.
- **Hover a tile in the leftmost and rightmost columns.** The panel must stay
  inside the window on both sides.
- **It must be over everything**, including the site header. If it appears
  behind anything at all, say so and say what: that would mean the top layer is
  not being reached, which is the one thing the build cannot check.
- **Tab through the grid with the keyboard.** The preview should follow focus.

**An event with no picture** should show the panel with no image band and no
gap where one would be.

### 1.69 The preview is absent on a phone and a tablet (3.75.0)

**This is the deliberate omission and it wants confirming rather than assuming.**
On a phone and on a touch tablet, tap an event tile in the month grid.

It must open the event **on the first tap**, with no panel appearing first. If a
panel appears, the hover gate is not doing its job and every tile on the
calendar has just become a two-tap control.

### 1.70 The new colours and icons on the category screen (3.75.0)

**caladmin > Series & Categories.** Edit a category.

- The swatches are **sixteen** now. Confirm the six new ones read as clearly
  different from the colours they are shades of, at the size they are drawn.
  They are measured to be, and it is worth one look.
- The icon list is **thirty**, grouped rather than alphabetical.
- **Give Español and Program Groups different icons**, which is the thing that
  prompted this: they have been drawing the same one.
- Save, then look at the category's placeholder on an event with no picture of
  its own: the colour and the icon should both be what was chosen.

### 1.71 What a block opens on, on the site and in an embed (3.75.0)

**In a browser that has never opened the calendar**, or a private window,
because a remembered choice beats the default and that is correct.

The calendar should open on the **month grid**, not the list. Press **List**,
reload, and it should still be the list: the choice is remembered per block.

**Then check an embed on another site** the same way. Both are supposed to open
the same way now, and four places had to agree for that to be true.

### 1.72 The event page on a phone (3.75.0)

**Open an event with registration on, on a phone.**

- **Register is near the top**, under the title and the picture, with the date,
  time and place. Not under the description and not under the FAQ.
- **The thumbnail in the list under the calendar has space against its title.**
  Tap a date in the month grid and read the panel below it.
- **Turn the phone sideways, and try a small tablet.** The page should be one
  column up to about 860px and two above it, and the switch should not leave a
  240px column of text at any width.
- **Nothing should scroll sideways.** If an event description has a table or a
  pasted video in it, that is the case to try: the table should scroll inside
  its own box and the page should not.

### 1.73 The hover preview, on the embed this time (3.76.0)

**1.68 and 1.69 were written for a feature that never ran.** 3.75.0 put it in
the shortcode's script and the calendar is only ever an embed, so everything
those two items asked for is still unseen. Do them now, on sfaf.org.

- Hover a tile: the panel appears after a short pause with the picture, title,
  date, times and place.
- **A tile on the LAST ROW**, with it near the bottom of the window: the panel
  must flip ABOVE rather than open off screen.
- **It must clear the site header.** If it appears behind anything, say what:
  that means the top layer is not being reached, which is the one thing the
  build cannot check.
- **On a phone, tapping a tile must open the event on the FIRST tap**, with no
  panel first.

### 1.74 Name the six pictures, then look at a picker (3.76.0)

**This is the item that turns "the picker shows file names" into a name.**
caladmin > Images. Each picture has a name box under it now.

Give all six a real name, then open the picture chooser on the staff request
form. It should show those names rather than `dsc_0043.jpg`.

**Then tag them with a series** from the same screen, and open the chooser on an
event in one of those series: that series' pictures should lead, under **For
this series**, with everything else below. Until something is tagged the chooser
correctly shows one ungrouped list, which is what it has been doing.

### 1.75 The icon picker, and the two folded lists (3.76.0)

**caladmin > Series & Categories.**

- The icon control is a grid of drawn icons in five labelled groups, not a
  dropdown of words. Choose one, save, and confirm the placeholder on an event
  in that category uses it.
- **Categories opens and Series is folded.** One threshold decides it, at ten
  rows, so a category list that grows past ten folds too.
- **Press each heading**, and confirm both open and shut with nothing else on
  the page moving.

### 1.76 The organizer on the staff request form (3.76.0)

Open the staff request form. **Organizer is the first section**, above Series,
above the picture.

- Choose one and send a request. The pending event should carry that organizer,
  visible in the editor.
- Send another leaving it at **Not sure**. That event must arrive with no
  organizer at all rather than a guessed one.

### 1.77 The FAQ set shows its questions (3.76.0)

On the staff request form, choose a saved set. Its questions should appear under
the select, read-only, and change when a different set is chosen.

**With JavaScript off** they should all be listed, each under a heading naming
its set. That is deliberate: the fold is an enhancement and the complete list is
the fallback.

### 1.79 Fill this in from the last one, on the staff request form (3.77.0)

**Open the staff request form and choose a series that has run before.** A panel
appears under the select offering the location, the times, the description, the
organizer, the picture and the FAQ set, each naming what it would fill in.

- **Press "Fill these in".** Every ticked field should be filled, and the form
  should say how many. Check the venue, the times and the description in
  particular.
- **Type something into the location first, then choose a series.** That row
  should say it would replace what you typed, and should still be ticked: the
  decision is yours.
- **Untick a row and press it.** That field must be left exactly as it was.
- **The date must never be filled in**, and neither must the title.
- **Press "Start from scratch".** The panel goes and nothing is written.

**Then send one.** The point of the control is the request that arrives: confirm
the pending event carries the location, times, description, organizer and FAQ
set that were filled in.

**This is the control whose caladmin twin sat dead for twenty-six releases**
without anybody noticing, so it is worth pressing rather than glancing at.

### 1.80 The preview's two targets, and the tint (3.78.0)

**This replaces 1.78, which described the panel as one link with a clickable
title. 3.78.0 made it two targets and the title is not one, so that item was
deleted rather than left describing behaviour nobody built.** Hover
a tile on the month grid.

- **The panel must not be tinted.** The date, the time and the place should read
  as ordinary text, not as teal links. That was the visible consequence of the
  whole panel being an anchor.
- **Click the picture.** It opens the event.
- **Click the View Event Details pill.** It opens the event, the same way.
- **Click the title, or the date.** Nothing should happen: they are not targets.
- **Tab through the grid.** One stop per tile, and no stop inside the panel.

### 1.81 Naming a picture after its own file (3.78.0)

**This is the case that did not work and is the reason to check rather than
assume.** caladmin > Images.

Find a picture whose file is named after what it is, `cycle-to-zero.jpg` for
instance. Type that same name into its Name box, **"Cycle To Zero"**, and Save.

**It must stick.** The card should show it, and so should the picture chooser on
the staff request form. Before this release the title was saved and then refused
on the way out, so the file name went on showing and the box looked broken.

**Then clear the box and Save again.** It should go back to the file name, which
is what an unnamed picture shows.

### 1.82 The Images screen, laid out (3.78.0)

**caladmin > Images**, on a normal desktop window.

- **Three cards across, not six**, each wide enough that the series dropdown
  shows a full name. "Mobile Health Sites" is the one to look for.
- **One Save per card**, not two. The name and the series are one form: choose a
  series, type a name, press Save once, and both should take.
- **Tag 0 images must not be yellow.** With nothing ticked it should read as a
  plain disabled button; tick one and it should turn primary and say "Tag 1
  image".
- **Add an image is folded shut** at the top. Press it to open.
- **A picture with no name says "no name yet"** beside its Name label, and one
  with a name does not.

### 1.83 The events list's two bulk actions, pressed for the first time (3.79.0)

**caladmin > Events.** The category half has existed since 3.73.0 and **has
never been usable**: the tick boxes were built into the wrong table, so nothing
on this screen could be selected. Both halves are first presses.

**Start by counting cells.** Every row should have a tick column at the far
left, and the header row should have one too. That is what was wrong: a header
cell with nothing under it.

- **Tick two events and add a category.** The button should say "Add to 2
  events" and be disabled until something is ticked. Afterwards the two rows
  should show the new category **beside the ones they already had**, not
  instead of them. This is the promise the control makes twice on screen.
- **Select all, in the header.** It should appear only once the page has
  loaded, tick every box, and show a dash rather than a tick when you then
  untick one.
- **Now the publish half.** Filter to **Status: Draft** so there is something to
  publish. The Publish button counts **only the drafts it may touch**, which
  will usually be fewer than the number ticked.
- **The two numbers should disagree, and that is the test.** Tick a past draft
  and a future one: the category button should say 2, the publish button 1. If
  they ever match when a row is marked as not publishable, the wrong subset is
  being counted and **the publish button is naming events it will skip**.
- **A draft that cannot be published says why**, in small type under its box:
  "already happened", "imported from a source", "no date yet", "a submission",
  "gone at its source". A published row says nothing, on purpose.
- **Press Publish and read the confirmation before accepting it.** It should
  name the count and what is being left out. Then read the message on the page
  afterwards: it should say how many were published and, separately, how many
  could not be and how many were not yours.
- **Then check the public calendar.** This is the only bulk action on the screen
  that reaches it, so the last step is looking at what a visitor now sees.

**With 287 drafts across 32 series, try this on one page of 25 before reaching
for Select all.**

### 1.84 The picker hiding by series, on both public forms (3.80.0)

**DO 1.74 FIRST OR THIS TEST CANNOT PASS.** With none of the six pictures
tagged, every series shows the empty message and nothing else, which is the
feature working and looks identical to it being broken. Tag at least two
pictures to one series and one to another before starting.

**On the community form** (a series' own submit URL), the picker should offer
**only that series' pictures**. Count them against what the Images screen says
is tagged to it. Nothing else may be in the list, and there must be no "For this
series" heading and no "Everything else" group: that is what 3.76.0 did and what
this replaces.

**On the staff request form**, choose a series, then open the picker. Same
answer. Then **change the series and open it again**: the list must change with
it. This half runs in the browser rather than on the server, so it is the half
that can fail on its own.

- **Choose a picture, then change the series to one that does not have it.** The
  choice should go back to "The series picture", and the banner with it. If a
  picture from the old series is still chosen, the form would post a picture
  that is not on screen.
- **Type in the picker's search box after choosing a series.** The search must
  narrow **within** that series and must never bring back a picture from
  another one. This is the specific thing that breaks if the two filters end up
  fighting over the same rows.
- **A series with nothing tagged** must show "No images are available for that
  series yet. Contact MarCom for an event image to be added." and no pictures.
  The "no picture" row stays, and the form must still submit without an image.

**With JavaScript off**, the community form should still be filtered and the
staff form should show the whole folder. That is deliberate and the reasoning is
in `PROJECT.md`.

### 1.85 The banner, and what an upload does not do (3.80.0)

**Both public forms.** The series picture should sit full width across the top.

- **Choose a different picture in the picker.** The banner should change to it,
  with a short zoom. Change it three or four times: the zoom must play every
  time, not only the first, and must not feel slow by the third.
- **On the staff form, change the series.** The banner should follow.
- **A series with no picture must show no banner at all**, not a grey box.
- **Now attach a file under "Or send your own".** The banner must **not** change.
  A thumbnail of what you attached should appear under the field with a line
  saying it has been sent for review. That is the point of the test: the upload
  is a working copy an approver decides about, and the banner would otherwise
  claim it is already the event's picture.

### 1.86 The month arrows, on the embed first (3.80.0)

**Look at the embed on sfaf.org before the calendar site.** These render inside
the host's page and the host stylesheet has caused six documented faults.

- **Previous, Today and Next are one group at the right**, with the month name
  to their left. They were at opposite ends of the header.
- **They have a visible edge.** They had none at all: a rule twenty lines below
  the one that drew it removed it, so what was there was a bare chevron. If they
  still look like floating text the host has beaten our border, and that is worth
  reporting rather than working around.
- **The chevrons are the same size and centred.** They were text characters
  taking the host's font; they are drawn icons now.
- **On a phone or tablet each button is at least 44px.** These are the only way
  to move through months.
- **Page back and forth several times.** The grid and the sidebar must move
  together, which is unchanged and worth confirming after a markup change.

### 1.87 The Upcoming event dates card in the combined view (3.80.0)

**Look at it with the month empty first**, which is the state that showed the
fault and is still easy to reach: navigate to a month with nothing published.

- **The heading band should span the top of its column** and meet the card's own
  edge, with the top-right corner rounded to match. It was inset on all four
  sides with square corners, which read as a card whose top had not been
  finished.
- **"See all events" should read as part of the column**, not as something below
  a box that closed above it.
- **Then look at a month WITH events.** It should look like the same thing with
  rows in it. If the band is right when empty and wrong when full, or the other
  way round, the escape is keyed to the wrong padding.
- **Then narrow the window until the two halves stack.** The band's top-right
  corner should go square: down there it is in the middle of the card, not at
  its corner.

### 1.88 Every tick picker, because none of them has ever run (3.81.0)

**FOUR SCREENS, AND THIS IS THE FIRST TIME ANY OF THEM COULD WORK.** `portal.js`
threw on every page from 3.77.0 to 3.80.0, which killed the script behind all of
these. The boxes always posted correctly, so anything ticked was acted on; what
was dead was the count, select-all, and the button going grey at zero.

On each of the four, tick two things and check the button says **2**, then use
**select all**, then untick everything and check the button goes grey:

- **caladmin > Images**, the Tag button. This is the one that was reported.
- **caladmin > Events**, the Add to category button.
- **caladmin > Events**, filtered to Status: Draft, the Publish button. Its count
  should be the eligible rows only, which is usually fewer than the number
  ticked.
- **A series' schedule screen**, the bulk publish list, where every row starts
  ticked.

**Also press "Fill this in from the last one" on the staff request form.** It has
never run either. `TESTING.md` 1.79 covers what it should fill in.

### 1.89 A series picture that was tagged rather than set (3.81.0)

**caladmin > Images.** Tag a picture to **El Grupo de Apoyo Latino**, or any of
the thirty series the import created, and give it a name. Do **not** go to the
series screen and set a picture.

Then open the **staff request form** and choose that series. The banner should
appear across the top, and the picker's first row should say "The series
picture" with that photograph on it. Before 3.81.0 it showed nothing, because
tagging and setting a series' picture were two different things and only one was
read.

**Then set a different picture on the series screen** and choose the series
again. The set one must win. That is the order that must not invert.

### 1.90 Remove a picture, and try to remove one in use (3.81.0)

**caladmin > Images.** Press **Remove** on a picture nothing is using. Read the
confirmation before accepting: it should say the file is not deleted.

- It should vanish from the grid and from **every picker on both public forms**.
- Choose **Removed** in the filter. It should be there, with **Put back**.
- Put it back and check it is offered again.

**Then try to remove one that is in use.** Set a picture as an event's own image,
or as a series' picture, then try. It must be **refused, naming the event or the
series**. If it removes, something can be stranded and that is the fault to
report.

### 1.91 Alt text, and what must never fill it in (3.81.0)

**caladmin > Images.** There should be **one block above the grid** saying what
Name, Alt text and Series are each for, and **nothing under each card**.

- Type alt text on a picture and press **Save once**. Both the name and the alt
  text should save together; there is one Save per card.
- **Choosing a series must never write anything into the alt text box.** If a
  programme's name ever appears there on its own, that is the fault this was
  built to avoid: a screen reader reads alt text as a description of the picture.
- Check it landed in WordPress: **wp-admin > Media**, open the picture, and the
  Alternative Text field should hold what you typed.

### 1.92 The toggle in the stacked layout, and where its calendar button goes (3.82.0)

**At 700px, which is what sfaf.org gives the block today**, so this is the
default shape rather than something to go looking for.

- **The list and calendar toggle is on screen.** It was not in the markup at all
  in the combined mode, at any width, from 3.45.0 to 3.81.0.
- **Press List.** The month grid, the month name and the upcoming dates column
  should all go, and the list view should be there in their place, paged.
- **Press the calendar button.** It must bring back **the combined layout**, the
  grid with the upcoming dates under it, not a bare month grid on its own. If it
  lands on a grid with no sidebar, the button is going to the wrong view and
  there is no way back to what the block was configured for.
- **Reload after pressing List.** It should still be the list. Reload after
  pressing the calendar button: it should be the combined layout.
- **Then widen the window** until the two panels sit side by side, and check the
  toggle still does both of those.

### 1.93 The sidebar card at 700px, third look (3.82.0)

The band and the content under it were measured into line, so this is confirming
what the numbers say rather than hunting.

- **The heading band is exactly as wide as the list, the month buttons and
  "See all events".** It was 36px wider than all three.
- **"See all events" is inside the card**, near its bottom edge, not below a rule
  with white space under it.
- **The October button row has the same left and right margin as everything
  else** in the column.
- **Check it with the month empty and with events in it.** Both should look like
  the same card with different contents.

### 1.94 Remove, and what it says when it refuses (3.82.0)

**This is the one that did not work for Mark and the code is not wrong**, so the
point of this test is to find out which branch runs.

Press **Remove** on a picture, accept the confirmation, and then **read the
message band at the top of the screen**, which is the thing to look for:

- **"Taken out of the calendar folder"** means it worked. The picture should be
  gone from the grid; choose **Removed** in the filter to see it and put it back.
- **"That picture is still being used"** means it was refused, and the message
  names the event or the series using it. That is the likely one: the
  2026-09-03 import set a featured image on every event it had one for, so a
  calendar-folder picture may well be an event's own image. Change what uses it
  first.
- **Neither message, and the picture still there**, is the case that needs
  reporting, with whatever the page says.

### 1.95 The Add an image panel's two columns (3.82.0)

**caladmin > Images > Add an image.** The **File** and **Series** labels should
sit on one line, with the two controls on the next. The hint about sizes hangs
below the File control, where a hint goes everywhere else.

### 1.96 The calendar folder rule, on a real event (3.83.0)

**This is the big one and it changes what the calendar looks like.** Every event
whose picture came from the import now falls back to its series picture, and
where a series has none, to the category placeholder. **That is expected. The
calendar will look emptier until the new pictures are in.**

- **Open an event that showed an imported picture.** Its card, its month tile,
  its hover preview and its own page should all show the series picture or the
  placeholder. **All of them, or one surface is not reading the rule.**
- **Open that event in caladmin.** The picture card should say the picture comes
  from the series, not that the event has one of its own, and the picker should
  not show the old one as the current choice. The editor and the calendar
  agreeing is the whole point.
- **Now choose a picture from the calendar folder on that event.** It should
  appear everywhere immediately and the editor should call it the event's own.
- **Nothing was deleted.** Check in **wp-admin > Media** that the old picture is
  still there.
- **An imported event from GoFundMe Pro or Eventbrite keeps its source picture.**
  Those are addresses on another site, not attachments, and the rule is not about
  them. If one of those goes blank, report it.

### 1.97 The list view at 700px (3.83.0)

**Press the list button in the stacked layout**, which is what sfaf.org gives
you today.

- **One column of event cards, full width.** It was a column about one character
  wide beside the sidebar, with a Load More button floating in the middle.
- **It should show events.** It said "No upcoming events found." while the
  sidebar beside it listed them.
- **Then press the calendar button** and check the combined layout comes back.
- **Widen the window** so the panels sit side by side, and try both again.

### 1.98 Remove says what is using a picture (3.83.0)

**caladmin > Images.** A picture that an event or a series is relying on should
now show **"In use by ..."** where the Remove button would be, naming what, and
have **no Remove button at all**.

- **A picture nothing uses should still have Remove**, and pressing it should
  take the picture out of the grid. Choose **Removed** in the filter to find it
  and put it back.
- **If a picture shows Remove and pressing it still does nothing**, that is the
  case to report, with whatever the message band at the top of the screen says.
  Every step between the press and the write has been driven in a browser and
  holds, so a failure now is somewhere none of that reaches.

---
### 1.99 A co-hosted event, requested on the staff form (3.84.0)

**The staff request form**, reached by the emailed link. The organizer question
is **tick boxes** now rather than a dropdown, and there is no "Not sure" option:
leaving them all clear is what "not sure" means.

- **Tick two organizers and submit.** Approve it in the pending queue and open
  the event page. It should read **"Hosted by A and B"**, with no serial comma,
  in alphabetical order rather than the order they were ticked.
- **Then check the filter**, which is the reason for the whole change: the event
  should appear under **both** organizers on the public calendar, and both
  counts should include it.
- **Tick none and submit.** The event should arrive with no organizer, exactly
  as it did before this field existed.

This is the half that cannot be checked here: there is no form post, no queue
and no taxonomy write in the build environment.

---

### 1.100 The series prefill fills in every organizer (3.84.0)

**The staff request form**, choosing a series that has run before. Press **Fill
this in from the last one**.

- The organizer row's preview names the joined phrase, for example **"Black
  Brothers Esteem and The Stonewall Project"**. **Every one of those boxes
  should end up ticked**, not just the first.
- This is the exact fault being fixed: the preview promised the whole phrase and
  the form received one name. If the preview still names two and only one box
  ticks, that is the case to report.

Needs a series whose most recent event is co-hosted. If none is, tick two
organizers on any event in a series first, then use that series here.

---

### 1.101 A community submission inherits every organizer (3.84.0)

**The public form at a series' own address.** It asks nothing about organizers
and never has: it derives them from the series' most recent event.

- Use a series whose most recent event has **two** organizers. Submit, approve,
  and open the event. It should carry **both**.
- Before 3.84.0 it carried the first alphabetically only, so a co-hosted
  submission appeared under one team's filter and not the other's.

---

### 1.102 A closure note, on both surfaces (3.84.0)

**WordPress admin > the closures screen.** Add a closure with a **Note**, for
example `The 6th Street Center is open as usual`.

- **On the month grid** the note should appear under CLOSED and the closure's
  name, shortened if it is long, with an **ellipsis** marking the cut and the
  **full text on hover**. Check a crowded day too: the cell should not push the
  events out or overflow.
- **On the list view** the same closure's card should show the note **in full**,
  on its own line.
- **Write a deliberately long note**, longer than about 32 characters, and
  confirm the grid cuts it **between words** rather than mid-word, and that
  hovering still gives the whole thing.
- **An existing closure with no note must look exactly as it did.** Every
  closure on the site today has none, so this is the case that proves nothing
  regressed.
- **On a narrow phone** the note should disappear with the name line, leaving
  CLOSED, and the list card should still carry it.

---

### 1.103 A community submission carries the organizer now (3.85.0)

**This is the one to do first.** No community submission has ever carried an
organizer, and the fix is an ordering change nothing here can exercise.

- Use a series whose **most recent event has an organizer**, and submit through
  that series' public URL. Approve it and open the event.
- It should read **"Hosted by ..."** with the organizer the series' last event
  names. Before 3.85.0 it arrived with none, every time.
- **Then try a series co-hosted by two.** The submission should carry both.
- **A series with no organizer on any event still gives none**, which is correct
  and is not the fault. Nothing is invented.

---

### 1.104 An organizer is required, and the hundred are not blocked (3.85.0)

**caladmin, the event editor.** Three cases, and the third is the one that
matters most because it is the one that could stop people working.

- **A new event with no organizer ticked, pressing Publish.** It should SAVE as
  a draft and say "Saved, and not published. Tick at least one organizer, then
  publish." Nothing typed should be lost.
- **An existing event that HAS an organizer, unticking every box and saving.**
  It should refuse: "Nothing was saved. Tick at least one organizer." The event
  should be unchanged.
- **One of the roughly hundred published events with no organizer.** Open it,
  change something unrelated, for example fix a typo in the description, and
  save. **It must save normally and stay published.** If it refuses, that is the
  case to report at once, because it blocks ordinary editing.

---

### 1.105 The staff form refuses a request with no organizer (3.85.0)

**The staff request form.** Submit with no organizer ticked. It should refuse
and say "Tick everybody putting this on.", with every other answer still filled
in. Nothing typed should be lost.

---

### 1.106 The filter dropdown floats, on the real site (3.85.0)

**The public calendar.** Organizers and groups are one control now.

- **Open it. The calendar must not move.** The old groups list pushed
  everything below it down; this one floats over. It was measured in a headless
  browser, but not on sfaf.org and not inside the Teal embed, where an ancestor
  with a transform is exactly what could break it.
- **Check it inside the embed on the other site too**, at 700px, which is where
  the stacked layout lives.
- **Tick two organizers.** Both should apply, the list should narrow to events
  from either, and the closed control should say "2 selected".
- **Tick one organizer and watch the Groups half.** Groups whose events name
  other organizers should DISAPPEAR. Groups with no organizer on any event
  should STAY. That second part is deliberate and is not a bug.
- **Reopen the panel.** What is ticked should now be at the top of its list.
  While it is open, ticking things must NOT reorder under the cursor.

---

### 1.107 The filter bar with JavaScript turned off (3.85.0)

**This has never worked and is new, so it has never been seen.** Turn scripting
off in the browser and open the calendar.

- The **Organizers and groups** button should still open its panel. It is a
  native popover and needs no script. It will appear centred rather than under
  the button, which is expected: script is what positions it.
- Tick an organizer and press **Apply**. The page should reload filtered, with
  the choice in the address bar.
- **With script on, Apply should not be visible at all**, because the choices
  apply as they are made.

---
### 1.108 The filter dropdown opens under its trigger, everywhere (3.86.0)

**This is the one to do first, and it needs more than one browser.** It opened
in the top left corner of the viewport on sfaf.org. It is a `<details>` now and
is placed by the stylesheet, so there is no script and no platform feature left
to fail.

- **On sfaf.org, and inside the Teal embed at 700px.** Open it. The panel should
  appear directly under the button and the calendar below should not move.
- **In Safari and in Firefox**, which is the point of the change. Chrome alone
  proves nothing here: it supported the thing that broke.
- **Shut, it must be invisible.** A closed `<details>` does not hide an
  absolutely positioned child on its own and that was caught by measuring, so if
  the panel is ever visible before you press anything, report that.
- **Click outside it**, and press Escape. Both should close it.

---

### 1.109 The two columns hold still as groups are hidden (3.86.0)

**The filter dropdown, opened.** Organizers on the left in one third, groups on
the right in two thirds running in two sub-columns.

- **Tick an organizer.** Groups that do not match should disappear. **The two
  columns must not change width or swap places** as that happens.
- **On a phone, and in the narrow embed**, the two should stack into one column.
  The breakpoint measures the block's own column, so check it inside the embed
  rather than only by narrowing the window.

---

### 1.110 Editing a closure (3.86.0)

**WordPress admin, the closures screen.** There was no way to edit one at all
until now, so every closure Mark has made was typed once and could only be
deleted.

- **Press Edit on an existing closure.** The form above should fill in with its
  name, both dates and its note, and the button should read "Save changes".
- **Change the note and save.** The list should show one closure, changed, not
  two. Check the month grid afterwards: a multi-day closure must still mark its
  whole span once rather than appearing twice.
- **Press Cancel**, and confirm the form goes back to "Add a closure" empty.
- **This is what makes the note from 3.84.0 usable on closures that already
  exist.** Adding notes to the ones already recorded is the real test.

---

### 1.111 Search narrows the month grid and the sidebar (3.86.0)

**The public calendar, in calendar view.** Typing in the search box did nothing
to the grid or the sidebar before; it worked only in list view.

- **Type something that matches a few events.** The grid should lose the events
  that do not match, and the sidebar beside it should narrow with it.
- **The caret must stay in the box.** If focus jumps out mid-word, report it: the
  grid is refreshed without replacing the search field precisely to avoid that.
- **Search for something that matches nothing in the month on screen.** The grid
  should say it found nothing for that term rather than "Nothing scheduled",
  and should name the term back.
- **Then clear the search** and confirm the month fills back in. A stale cached
  month here would show everything again only after moving month, which is the
  thing to watch for.

---

### 1.112 Name and alt text at upload (3.86.0)

**caladmin > Images > Add an image.**

- The panel should now show **four fields on a tidy grid**, with File, Series,
  Name and Alt text, and all four labels on the same line as each other.
- **Upload with a Name that matches its own file name**, for example a file
  called `strut-clinic.jpg` named "Strut clinic". The name must STICK. If the
  grid shows the file name instead, the deliberate marker is not being set and
  that is the 3.78.0 fault returning.
- **Upload with both left blank.** It should behave exactly as before and show
  up under the untagged filter.
- **Check the alt text reached the picture**, in the grid's own alt field.

---

### 1.113 The tick boxes line up with long names (3.86.0)

**caladmin, the event editor.** Find an organizer whose name wraps to two lines
in the column. Its tick box should sit level with the FIRST line, not the middle
of the two. **Check the categories in the same way**: they share one rule, so
they should both be right or both be wrong.

---

### 1.114 The dashboard count of events with no organizer (3.86.0)

**caladmin dashboard, Needs attention.**

- It should say how many published events have no organizer. On today's data
  that is about a hundred.
- **Press the link.** It should open the events list showing only those, with a
  band saying it is narrowed and a way back to all events.
- **Set an organizer on one and come back.** The number should go down by one.
  That is the whole point of counting this one and not the others.
- **Editing one of those events must still work normally**, including saving it
  while it still has no organizer. If a save refuses, report it at once.

---
### 1.115 An image uploaded from the event editor (3.87.0)

**This is the one to do first.** It was reported as going nowhere.

- **Edit an event that belongs to a SERIES.** Press Choose Image, upload a new
  picture, and it should appear in the picker immediately and be selectable for
  that event. It should also now be tagged with that series.
- **Then edit an event with NO series** and upload. It should appear too, and
  should NOT be tagged with anything.
- **Check the Images screen afterwards.** The picture should be there with the
  series tag it was given, and in `uploads/calendar/` rather than a date folder.
- **Upload from somewhere else in WordPress** while you are at it, for example a
  page, and confirm it does NOT get filed into a calendar series.

---

### 1.116 Search in calendar view, on the real site (3.87.0)

**No code changed for this in 3.87.0 and that is deliberate.** The whole chain
was proved: typing fires a month request carrying the term, and the server puts
that term on the month query. What could not be tested here is the SQL and the
install.

- **Confirm the site is actually on 3.87.0** before judging this. Plugins > SFAF
  Calendar > Check for updates.
- **Open the browser's Network tab, type in the search box in calendar view**,
  and look for `admin-ajax.php`. There should be a request with
  `action=uc_load_month` and an `s` field carrying what you typed.
- **If that request is there and the grid does not change**, the fault is in the
  SQL or the data, and the response body is what to send back.
- **If that request is NOT there**, the script on the page is older than 3.87.0
  and the cache is the thing to chase.

---

### 1.117 The filter panel, restyled, with no Apply (3.87.0)

- **It should look lighter**: less heavy headings, more space between rows,
  rounder corners, a softer edge.
- **There should be no Apply button.** Ticking an organizer should narrow the
  groups at once and rebuild the calendar about a third of a second later.
- **Tick two or three in a row.** The panel must STAY OPEN throughout. If it
  shuts on the first tick, report that at once: it is the thing most likely to
  have gone wrong with removing the button.
- **With JavaScript off**, Apply should reappear and be the way to apply a
  filter.

---

### 1.118 The upload panel, laid out (3.87.0)

**caladmin > Images > Add an image.** The picture goes on the left and Name, Alt
text and Series on the right.

- **Choose a file.** A preview should appear in the box on the left, and
  **nothing to the right of it should move**.
- **The three labels on the right should be on the same lines as each other.**
  That is the alignment fault reported twice now.
- **Upload with a Name that matches its own file name**, which is the case that
  used to be blanked.

---
### 1.119 Search and the filter dropdown ON THE EMBED (3.88.0)

**On the embedded calendar, not on resources.sfaf.org.** That distinction is the
whole of this release: the embed has its own script and its own route, and the
two previous fixes went to the other one.

- **Type in the search box in calendar or combined view.** The month grid and
  the sidebar should both narrow. Before 3.88.0 only the hidden list changed.
- **Open the Organizers and Groups dropdown and tick an organizer.** The groups
  should narrow at once and the calendar should rebuild about a third of a
  second later. Before 3.88.0 nothing happened at all on an embed.
- **Tick two or three in a row.** The panel must stay open throughout.
- **Clear the search and confirm the month fills back in.** If it only fills in
  after moving month, a cached grid is being served and that is the thing to
  report.
- **Watch the Network tab while you do it.** There should be a request with
  `mode=month` carrying your search in `s`, as well as the `mode=items` one.

---

### 1.120 A release is visible immediately on an embed (3.88.0)

**The embed cache now retires on a plugin update**, which it never did.

- **Update the plugin, then reload a page carrying an embed**, without waiting.
  Whatever the release changed should be visible at once rather than up to ten
  minutes later.
- This is worth one deliberate check because four faults in this project have
  looked arbitrary for want of it, and because it is invisible when it works.

---

### 1.121 An empty month on the embed says why (3.88.0)

**On the embedded calendar.** Search for something that matches nothing in the
month on screen. The grid should name your term back rather than saying nothing
is scheduled. Do the same with an organizer that has no events that month.

---
### 1.122 The filter panel stays open while you tick, ON THE EMBED (3.89.0)

**On the embedded calendar.** It shut on every tick and had to be reopened
between selections.

- **Open it and tick three organizers one after another.** The panel must stay
  open the whole time and the calendar should rebuild about a third of a second
  after the last one.
- **Untick one.** Still open.
- **Watch the groups as you tick.** Groups the chosen organizers do not run
  should disappear AND STAY disappeared after the calendar redraws. If they
  come back a moment later, that is the second half of this fix and it is the
  thing to report.
- **Then the same on resources.sfaf.org**, which had the open state since
  3.87.0 but had the same re-narrowing fault.

---

### 1.123 The month grid reads well on a busy day (3.89.0)

**This is the one that needs Mark's eye rather than a check.** Each event is now
a line: a coloured category dot, the title, the time. No box, no picture.

- **Find the busiest day** and look at the whole month. The row should no longer
  push the rest of the calendar off the screen.
- **The question is whether it is CLEAN AND CLEAR**, which was the condition:
  nine lines of text must not read as a wall. Look at whether the titles line up
  down the left, whether the times line up down the right, and whether the dots
  help or just add noise.
- **A long title is cut with an ellipsis** rather than wrapping. Check that the
  time is still visible on those rows and has not been pushed out.
- **Hover a cut title**: the preview should show the whole thing.
- **On a phone**, tap a day and check the panel below the grid still shows the
  full title wrapped rather than cut.
- **If the lines read as too tight or too plain**, say so with a screenshot.
  The spacing, the dot size and the weight are all one edit; the structure is
  the part that took the work.

---

### 1.124 A stale embed.js now announces itself (3.89.0)

**This is why two releases looked broken when they were not.**

- **After the next release lands**, open an embedded page and look at the
  browser console. If the page is running an old cached `embed.js` there will be
  a warning naming both versions and saying a reload without the cache is what
  fixes it.
- **It does not fix itself**, deliberately. The warning is the fix: it tells you
  which of "the release did not work" and "this page has not got the release yet"
  you are looking at.
- **Worth one deliberate check** that the warning appears when it should and
  does NOT appear once the page has the current script.

---
### 1.125 The Cycle to Zero picture comes back (3.90.0)

**This is the fix for what Mark reported and it needs no action first.** The
series was pinned to the attachment he replaced, which no longer exists, and
that pin stopped the chain reaching the picture he tagged.

- **Open a Cycle to Zero event.** The picture should be there now, from the
  tagged one in the folder, with nothing re-saved.
- **The series page too**, and the community request form for that series.
- **Then open the series screen.** The preview should show the same picture the
  calendar shows. Before this it could show nothing while the calendar showed
  something, or the reverse.
- **Worth doing for the other series as well.** Any series whose picture was
  ever replaced was in the same state, so more than Cycle to Zero may come back
  at once.

---

### 1.126 The picker is no longer a dead end (3.90.0)

**caladmin, the series screen, Choose Image.**

- It should open on the **whole calendar folder**, not only pictures tagged to
  that series. That circularity is why it was blank.
- **"All calendar images" should be visible** beside Choose Image. It has been
  rendered hidden since 3.74.0 and has never been seen by anybody.
- **On the event editor**, Choose Image should still open on that event's series
  and the escape button should still be there beside it.
- **Find a series with nothing tagged and open the picker from the event
  editor.** The empty grid should now carry a sentence saying why it is empty
  and pointing at the escape button, rather than WordPress's bare "No media
  items found".

---

### 1.127 Setting a series picture also tags it (3.90.0)

**caladmin, the series screen.** Choose a picture and save.

- **Check the Images screen**: that picture should now carry the series tag, set
  by the save rather than by a second visit.
- **The old picture keeps its tag.** Changing a series' picture does not untag
  the previous one, deliberately: it may still belong with the series.
- **Only pictures in the calendar folder are tagged.** A pasted URL tags nothing.

---

### 1.128 A caladmin upload appears in the media library folder (3.90.0)

**This is the one that cannot be verified from the build machine**, because WP
Media Folder is not there and its taxonomy name could not be confirmed.

- **Upload a picture through caladmin > Images > Add an image.**
- **Then open the WordPress media library and look at the Calendar folder.** The
  new picture should be in it as well as in `uploads/calendar/`.
- **If it is not**, the discovery did not find the folder, and the thing to
  report is what the Calendar folder is actually called in that library. It is a
  one line correction through the `sfaf_library_folder_term` filter and needs no
  release if the name simply differs.
- **The nine already on disk and missing from the folder are NOT retrofitted.**
  This changes uploads from here on. Filing those is a separate decision.

---
### 1.129 The list view is a table now (3.91.0)

**This replaces the card list entirely.** Thumbnail, title, date and time,
venue, and nothing else.

- **On sfaf.org and in the embed**, at a wide window and at 700px. Rows should
  read as a table: the dates should line up down one column and the venues down
  another, with a hairline between rows and no boxes.
- **Scroll to the bottom.** Infinite scroll should keep working exactly as
  before; the rows are a grid of divs precisely so that did not have to change.
- **On a phone**, the row should stack beside the thumbnail rather than scroll
  sideways. If anything scrolls sideways, report it.
- **The excerpt, the big image and the View event button are gone on purpose.**
  If Mark wants any of them back, that is a decision rather than a fault.

---

### 1.130 The month tile shows the whole title (3.91.0)

- **Find the longest title on the calendar**, for example an Opioid Overdose
  Prevention and Reversal Training. It should now wrap to as many lines as it
  needs, with **no ellipsis anywhere**.
- **The time should be on its own line under the title**, not beside it.
- **The dot should sit against the FIRST line** of a three-line title, not float
  down beside the middle of it.
- **Check a busy day.** It will be taller than 3.89.0 and that is accepted; what
  matters is whether it reads.

---

### 1.131 The dropdown reads as sections and rows (3.91.0)

**The complaint was "a big blob of text and boxes", and the previous one was the
opposite, so the weights were deliberately NOT changed.**

- **Open it.** There should now be a rule under each heading, a hairline between
  rows, a divider between the Organizers and Groups columns, and a rule between
  the two group sub-columns.
- **The headings should sit clear of the first row.** They have been flush
  against it since the panel was built, which was a specificity fault rather
  than a choice.
- **A ticked row should stay visibly ticked** after the pointer moves away.
- **If it now reads as too ruled**, say so: the hairlines are one edit. The
  thing to avoid is going back to changing the weights.

### 1.132 The counts beside each name are the right numbers (3.92.0)

**Every organizer and group in the dropdown now shows `(n)`. Nothing here can be
settled without real data, because the whole question is whether the number
matches what clicking it produces.**

- **Open it and pick any name with a count.** Tick it, let the list redraw, and
  **compare the number of events against what the row said.** They must agree.
  Do this for one organizer and one group.
- **Then select a category first and open the panel again.** The counts should
  have changed: they are computed inside the category. If they did not move at
  all, the category is not reaching `who_counts()`.
- **Tick an organizer and watch the GROUP counts.** They should narrow to what
  that organizer runs. **The organizer counts should NOT all drop to zero**,
  because an organizer's own count deliberately ignores the organizer ticks.
- **Type something in the search box and open the panel.** The counts should
  narrow to the search as well.

### 1.133 Nothing reads (0), and nothing that should be there is missing (3.92.0)

**A name with nothing behind it is now hidden rather than shown as (0). Two
cases are deliberately held out of that and both need a person to confirm.**

- **Count the names in the panel against the taxonomy screens.** Nine organizers
  and twenty-five groups were the numbers given. If the panel now shows fewer,
  the missing ones have nothing upcoming, which is correct, but **check one of
  them on its taxonomy screen to be sure it really has nothing** rather than
  being hidden by a bad count.
- **A ticked name must never disappear.** Tick something, then tick a category
  that leaves it with nothing. The row should stay, reading (0), so it can be
  unticked. If it vanishes, that filter is stuck on with no way off.
- **A group whose events name no organizer must stay visible when an organizer
  is ticked**, reading (0). Roughly a third of groups were in that state while
  the hundred were being set by hand. This is the rule that a plain zero rule
  would have reversed by a side effect, so it is worth one deliberate check.

### 1.134 The panel is tighter and the scrollbar mostly does not appear (3.92.0)

- **Open it on a normal desktop window** and look for a scrollbar inside the
  panel. There should not be one: the content measures 460px and the cap is
  520px. If there is one, the content is taller than it was measured to be and
  the cap needs to go up again rather than the overflow coming off.
- **Then make the browser window short**, about 700px tall, and open it again.
  **It should scroll**, and it should still be possible to reach the last group.
  That is the case the overflow exists for.
- **The rows are 31px.** Tap one on a phone, on the NAME rather than the box.
  The whole row is the target. If only the checkbox responds, the label is
  broken and that is a real defect, not a preference.
- **Look for names broken onto two lines.** Two or three of thirty-four is
  expected and is what the longest names did before the counts existed. If most
  of them are wrapping, say so and say at what width, because the width taken
  back out of the gutters was measured against invented names rather than the
  real ones.

### 1.135 The dropdown headings, and nothing else, carry colour (3.92.0, 3.93.0)

**REWRITTEN RATHER THAN LEFT, because what it described lasted one release. It
asked about teal heading TEXT with a rule under it, which 3.93.0 replaced with a
tinted band after that was reported as not being separation. The half that has
not changed is the half worth keeping: whatever the mark is, only the headings
get it.**

- **No name, count or tick should have taken any colour.** Thirty-five teal
  names would be a field of teal with no separation in it.
- **Look at it beside the rest of the filter bar.** If the two headings now
  compete with the calendar itself, say so; the answer is the amount rather than
  the hue.
- The band itself is 1.138.

### 1.136 Check boxes are top aligned, on every screen (3.93.0)

**Fourteen rules changed and the fix is a different shape from the last two, so
this needs looking at rather than trusting.** The box should sit level with the
FIRST LINE of its label, not the middle of a two-line one.

- **caladmin, the event editor's Classification card.** Categories and
  organizers, where several names wrap. This is the one that was reported.
- **caladmin, the Access card**, the Publish list, the Prefill options and the
  Approve question. All four had their own rule and two of them disagreed with
  the base.
- **WordPress admin**, the event's Display toggles and the Pardot campaign list.
- **The public calendar's filter dropdown**, and the RSVP opt-in on an event.
- **If any box now looks too HIGH**, say so. The 2px offset puts it on the first
  line rather than at the very top of the text block, and that number is the one
  thing here that is a judgement.

### 1.137 No name in the dropdown breaks mid-word (3.93.0)

**"Transformaciones" was rendering as "Transformacione" then "s". It could not
be reproduced in isolation: measured against the real terms the column is wide
enough at every width, so the break was being forced by a property inherited
from sfaf.org's own stylesheet.**

- **Open the dropdown on sfaf.org and find Transformaciones.** It must be on one
  line. Look at the other long ones too: Health Education & Legal Assistance
  Group, Opioid Overdose Prevention and Reversal Training, The Elizabeth Taylor
  50-Plus Network.
- **Wrapping at a SPACE is fine.** Those three take two or three lines and that
  is expected. What must not happen is a word cut in half.
- **If it still breaks**, that is worth knowing immediately, because it means the
  cause is not what was measured. Say which name and at what window width.
- **Check it on a phone as well**, where the panel narrows to one column.

### 1.138 The dropdown after the band, the reorder and Clear (3.93.0)

- **Two tinted bands, one per section.** "Organizers" and "Groups" sit on a light
  teal band now rather than being teal words. If the bands read as too strong or
  too faint, say which, because 18% is the top of the range the heading text can
  stay legible on.
- **Tick something and watch where it goes. It must not move.** Ticked items used
  to jump to the top of their column; they now stay in alphabetical order.
- **Clear all is in the top right of the panel.** Tick several things, press it,
  and everything should clear at once.
- **Tab to it with the keyboard.** It should come BEFORE the checkboxes, not
  after all thirty-five of them.
- **Look for a scrollbar inside the panel on a normal desktop window.** There
  should not be one: the content measures 532px and the cap is 580px. On a short
  window it will scroll, which is what the overflow is for.

### 1.139 Five minute steps, the fourth report (3.93.0)

**Nothing was built for this and nothing was lost. The attribute has been on all
twelve time controls since 3.72.0 and a committed test checks every one. So this
item is about finding out what Mark is actually seeing.**

- **Open a request form, staff or community, and click the time field.** The
  field starts empty on a new form, so it always carries `step="300"` there. If
  the picker still offers every minute, **the attribute is not the mechanism in
  that browser** and that is the thing to report: say which browser and version.
- **Then open an EXISTING event in caladmin and check its start time.** If the
  stored time is not a multiple of five, that control deliberately has no step,
  because `step="300"` on a field holding 6:07 makes the form unsubmittable.
  **Change it to a round time, save, reopen.** The stepper should be there.
- **Confirm the install is current** before either: `SFAF_VERSION` in the plugin
  header against what the site reports.

### 1.140 A place name on both request forms (3.93.0)

- **Send a staff request with a place name and an address**, "Strut" and "470
  Castro St, San Francisco". The event page should read "Strut, 470 Castro St",
  with the name on its own line above the address.
- **Do the same on the community form**, where the name sits above Street
  address.
- **Leave the name blank** on one and confirm the page reads exactly as it did
  before, with no stray comma.
- **The pending queue row should show the NAME**, not the street number.

### 1.141 Promoting a typed place to a venue (3.93.0)

**This writes a row every future event can pick from, so it wants one careful
run before it is used in anger.**

- **On the pending row for an event with a typed place name**, there should be a
  button reading "Add <name> to venues". Press it.
- **Check the Venues screen**: the venue should be there with the address the
  submitter typed, split into its parts.
- **Check the event**: it should now POINT at that venue, with no address text of
  its own. Open it in caladmin and confirm the venue picker shows the venue and
  the address boxes are empty.
- **Now correct the venue's address on the Venues screen** and reload the event
  page. The correction must reach it. That is the whole reason to promote.
- **The button must not appear on either public form.** Check both.
- **Send a second event at the same place and promote it too.** It should reuse
  the existing venue rather than making a second one with the same name.

### 1.142 A small picture is taken and flagged (3.93.0)

- **Send a community submission with a picture under 1200 pixels wide.** The
  submission must go through. It used to be refused.
- **The pending row should show the picture and an amber sentence beside it**
  naming the real width: "That image is 768 pixels wide and needs to be at least
  1200."
- **"Use this image" should still work** on it. The warning is information, not a
  block.
- **Send one over 1200 as well** and confirm there is no warning on that row.

### 1.143 The dropdown's counts, after the move (3.94.0)

- **Open it and look down a column.** Each `(n)` should sit immediately after its
  name with the same small gap on every row, and its digits should sit on the
  same line as the name rather than higher.
- **If a count is still pinned to the right edge**, that is important: it would
  mean something other than a flex-grow on the name is doing it, and the whole
  diagnosis was wrong. Say so, and say at what window width.
- **Count the wrapped names.** Nine of thirty-five is expected against the real
  terms. If it is many more, the gutters are where to look, not truncation.

### 1.144 A ticked row can be seen at a glance (3.94.0)

- **Tick two or three things in different columns and step back from the
  screen.** Each ticked row should have a teal left edge and a light teal fill,
  and the set should be findable without reading the names.
- **If it now reads as too loud**, say so: the edge is the half carrying it and
  the fill could come down without losing the effect.
- **The tick itself should be teal**, not the browser's default blue.
- **The name must still be easy to read on the fill.** It measures 14.5:1, so
  this is a look rather than a check, but say if it is not.

### 1.145 An event description is in one font (3.94.0)

**This needs a real pasted description, which is the whole point.**

- **Find an event whose description was pasted from Word or Outlook** and open
  its public page. All of it should be in the calendar's serif.
- **Bold, italic, links, bullets and numbered lists must all survive.** Only the
  typeface is being overridden. If any of those has gone, that is a defect.
- **Paste something new from Word into the editor, save, and look at the page.**
  Same result.
- **Pasted colour and pasted text size still come through as pasted.** That is
  deliberate and not a fault. Say whether they should be next.
- **Check an FAQ answer too**, which takes the same rule.

### 1.146 Choose Image on an event uses the new picker (3.94.0)

**READ 1.74 FIRST. With none of the six pictures tagged to a series, this picker
will correctly show "No images are available for that series yet" and nothing
else. That is the feature working, and it cannot be judged until tagging is
done.**

- **Open an event in a series that has a tagged picture** and press the picture
  chooser. It should open inline, not as a WordPress modal, showing that
  series' pictures with a search box.
- **Press "All calendar images".** The rest of the folder should appear, and the
  button should go.
- **Choose one and save.** The event's picture should change. Then reopen: the
  chosen one should be selected.
- **Choose "The series picture" and save.** The event should go back to
  inheriting.
- **The Remove button should still work** on an event with its own picture.
- **There is no upload here any more.** If you need a new picture, it goes on the
  Images screen first. Confirm that reads clearly rather than looking broken.
- **The series screen still uses the old modal** and is unchanged. Check it
  still opens.

### 1.147 The three buttons at the foot of the event editor (3.94.0)

- **Save changes is green, Cancel is amber, Delete is red**, on one line.
- **Press Save.** It must still save. The row sits outside the form now and
  reaches it by id, so this is the one thing that would be quietly broken.
- **Press Cancel.** The options should appear below the three buttons rather
  than beside them, and the button should become the panel's heading.
- **Press Delete on an event with no registrations.** It should ask first, then
  delete.
- **Open an event that HAS registrations and is not cancelled.** There should be
  no Delete button at all, and a sentence saying why.
- **Open an event that is already cancelled.** The cancelled state should still
  be a prominent block with "Put it back on", not hidden behind anything.
- **Try it with a keyboard.** Tab should reach all three.

### 1.148 See all events, clear of the corner (3.94.0)

**Reported three times. The fix is on the STANDALONE sidebar, which is the one
nobody had measured.**

- **Find a page with the sidebar block on its own** and look at the bottom of
  the card. "See all events" should sit clearly inside it, with visible space
  between the link and where the card starts curving.
- **Check it with events listed AND with none.** The empty state is where it was
  first noticed.
- **Check the combined view too**, which was always 21px clear and should be
  unchanged.
- **If it still looks wrong, say which of the two you are looking at.** That
  distinction is what took three attempts.

### 1.150 The submitted picture path, with no button (3.95.0)

**"Use this image" is gone. It never worked, so nothing is lost, but the path
that replaces it has never been walked end to end by anybody.**

- **Open a pending community submission that came with a picture.** There should
  be a thumbnail and no button under it.
- **Click the thumbnail.** The full-size file should open in a new tab. Save it
  from there.
- **Add it on the Images screen**, give it a name, tag it to the series, then
  open the event and choose it in the picture picker. That is the whole path and
  it is the one Mark intends to use, so say if any step of it is awkward.
- **Open the same event in the editor.** The request panel should show the
  picture with the sentence "Download it, size it, and add it on the Images
  screen. This file is not used on the event." **It used to say "upload the
  finished one through the image picker", which stopped being possible in
  3.94.0.**
- **Check an event that had a typed image URL.** Nothing about a submitted
  picture should touch it now. The button used to delete it.
- **Send a submission with a picture under 1200 pixels wide** and look at the
  queue row. The amber warning should be a readable sentence beside the row, not
  squeezed into the narrow picture column.

### 1.151 The list view at the embed width (3.95.1)

**All three of these were measured in a headless browser and none has been seen
by a person on sfaf.org.**

- **Open the calendar on sfaf.org in list view** and read down the date column.
  Every date should be two lines, the weekday on the first and the rest on the
  second: "Wednesday," then "September 16, 2026". **Never "16, 2026" alone.**
- **Widen the browser until the calendar has more room.** At a wide enough
  column the date should draw on one line. If it stays two lines everywhere,
  say so.
- **Read down the line under each title.** The category chip and the organizer
  should be side by side on every row, including the long ones. A long organizer
  wraps inside its own space; it must not drop underneath the chip.
- **Look at where the date and the venue sit against the picture.** They should
  be centred against it, not level with its top.
- **Then look at it on a phone.** The picture should still sit beside the title
  at the top of the row, not floating against the middle of the text. That shape
  is deliberately not centred and is the one to check for a regression.

### 1.152 The ticked edge in the filter dropdown (3.95.1)

- **Tick something in Organizers and groups.** The teal edge should be a
  straight vertical line, square at both ends, running the full height of the
  row.
- **The row fill should still have its soft corners**, the same as a row you are
  hovering. If the ticked row is squared off and a hovered one is not, the wrong
  one of the two fixes went in.
- **Tick and untick while watching the rows below.** Nothing should move.

---

### 1.153 A series video on every occurrence, and one event refusing it (3.96.0)

Put a YouTube link on a series with several upcoming dates and open two of those
event pages. The same video plays above the description on both. Then open one
of them, paste a different YouTube link into its Video field, and check that
event alone changes. Then clear that field, tick **This event has no video** on
it, and check the video is gone from that one page and still on the others.

**Why it needs a person:** the resolver is unit tested, but nothing here can
open an event page on sfaf.org and watch a frame load. What is being checked is
that the page the resolver feeds is the page a visitor sees, and that a
`youtube-nocookie.com` frame is not blocked by anything on the live site.

Also paste embed code into the field and confirm it is refused with the message
naming both services, rather than stripped or accepted.

### 1.154 Two extra pictures on a submission, on both surfaces (3.96.0)

Send a community submission with a featured picture and two others. On the
pending row, check the featured one is the large thumbnail and the two others
appear under **Also sent**, each opening the full size file in a new tab. Open
the event in the editor and check the request panel shows the same three, the
featured one first.

**Then take one of them the whole way.** Download it, size it, and put it into
the event's description with **Insert image**: press the button, upload the
sized file, and check it appears in the description at the full width of the
column with rounded corners, both in the editor and on the published event page.
Then reopen Insert image and check that same picture is now offered in the list
under "Or one already uploaded".

**And check the two folders stay apart.** The picture you just added must NOT
appear in the featured picture picker or on the Images screen, and the Insert
image list must NOT offer any calendar picture or any submitted one.

**Why it needs a person:** it needs a real upload of real files through a
browser, and the folder separation is a claim about what two different pickers
show on a real media library. The build environment has no WordPress, no media
library and no browser upload.
### 1.155 The two format ticks on the real editor screen (3.97.2)

Open an event in the editor and work the two ticks in the Location card.

1. Tick **This is an online event**. The meeting link panel appears and the
   venue and address go.
2. Tick **This is a hybrid event**. The online tick must CLEAR itself, and the
   venue, the address, the meeting link and **Places online** must all be on
   screen together.
3. Untick hybrid. The address stays, the link panel and **Places online** go.
4. Now do it the other way round: hybrid first, then online. The hybrid tick
   must clear, and the address must go.

**And watch Accept RSVPs while you do it.** Start from an event with it OFF.
While hybrid is ticked it must be ticked and greyed, with the Display card's
**RSVP** toggle ticked and greyed too, each saying why. **Unticking hybrid must
give it back OFF**, not leave it ticked. Save from a hybrid state and reopen:
the event must be taking RSVPs.

**Why it needs a person:** `.claude/hybrid-live.php` drives the real `portal.js`
in headless Chrome and asserts all of this, but against markup this repository
reconstructs. What nobody has checked is that `render_event_form()` puts the
same attributes on the same real screen, with the real stylesheet, at a real
window width.

### 1.156 The calendar file on a generated occurrence whose description was edited (3.97.2)

This is the fault 3.97.2 fixed, and it needs data only the site has.

Find a REPEATING event whose **seed** has a manual excerpt, or give one an
excerpt in WP admin and regenerate. Open one of the generated dates, change its
description to something unmistakable, and save. Then, on that date's public
page, press **Add to calendar**.

Open the downloaded `.ics` in a text editor and read `DESCRIPTION`. It must be
**the text you just typed**, not the seed's. Then press the **Google Calendar**
button beside it and check its notes say the same thing.

**Why it needs a person:** it needs a real repeating event with a real seed
excerpt, which is a state no test here can construct: there is no WordPress and
no database, and the excerpt is not a field any screen in this plugin offers.

### 1.157 Count the online and hybrid events whose link delivery is switched off (3.98.0)

3.98.0 defaults both ticks under the meeting link to ON, on a new event and on
one switched to online or hybrid. **It changed no existing event**, so any that
were saved with a tick off still have it off and still send no link.

Run this against the site's database and report the number:

```sql
SELECT p.ID, p.post_title, p.post_status
FROM wp_posts p
WHERE p.post_type = 'uc_event'
  AND p.post_status IN ('publish','draft','pending','future')
  AND EXISTS (SELECT 1 FROM wp_postmeta m WHERE m.post_id = p.ID
              AND m.meta_key IN ('_uc_online','_uc_hybrid') AND m.meta_value = '1')
  AND (
    NOT EXISTS (SELECT 1 FROM wp_postmeta s WHERE s.post_id = p.ID
                AND s.meta_key = '_uc_online_send')
    OR EXISTS (SELECT 1 FROM wp_postmeta s WHERE s.post_id = p.ID
               AND s.meta_key = '_uc_online_send'
               AND (s.meta_value NOT LIKE '%confirmation%' OR s.meta_value NOT LIKE '%reminder%'))
  );
```

**Then Mark decides**, which is why this is a count rather than a migration. An
event whose ticks are off may be off on purpose: the meeting link box also holds
a link somebody keeps for their own reference and sends nowhere, which is one of
the four cases the hint under it names.

**Why it needs a person:** there is no database here, and the answer is a
decision rather than a number.

### 1.158 The nine editor and form changes, on the real screens (3.98.0)

Everything below is asserted here and measured in headless Chrome against
reconstructed markup. What nobody has seen is the real screens.

**In the event editor, on an EXISTING published event:**

1. The button row reads **Delete, Cancel event, Save changes**, with Save
   changes green and the largest control in the row. Press **Cancel event** and
   check it opens rather than doing anything.
2. **Capacity is one row** below the address and the meeting link, with one box.
   Tick **This is a hybrid event** and check a second box appears beside it,
   the two marked **In person** and **Online**, without saving.
3. Put a number in each, save, reopen, and check both came back.
4. The **Video** field: on an event whose series has a video, the box reads
   **"Don't show the series video on this event"** and the preview below the
   field is playing the series video with one line above it. On an event whose
   series has none, **the box is not there at all**.
5. Choose a different series in the picker and check the preview and the box
   both follow it, without saving.
6. Paste a YouTube link into the event's own Video field: the preview switches
   to it and the line goes.
7. Clear the description and check the line **"This event will show the series
   description"** appears under the field, and that the published page then
   shows the series description.

**Every time control**, on all of these screens: the event editor, the schedule
editor, the add-a-date row, the WP admin metabox, the staff request form and
the community form. Each is **an hour list beside a minute list**. Open the
minute list and count: **twelve entries**. Set a time, save, reopen, and check
it came back exactly.

**Then the RSVP form on the public calendar**, on a hybrid event: the format
question is one rounded control in two segments, full width, with the chosen one
filled in dark teal and white text. **Tab to it and use the arrow keys**, which
is the half that would be lost if the radios underneath had been removed.

**Why it needs a person:** there is no WordPress and no browser rendering the
real screens here. The harnesses drive the real scripts against markup this
repository reconstructs; what nobody has checked is that the renderers put the
same things on the same real screens, with the real stylesheet, at a real width.

### 1.159 A time the import left off the five-minute grid (3.98.0)

Find an event whose start or end time is not on a five-minute boundary, or set
one through the database. Open it in the editor.

The minute list must offer **thirteen** entries: the twelve fives and that
event's own minute, marked **(current)**. Save WITHOUT touching the time and
check the stored value is unchanged. Then choose a different minute, save, and
check the extra entry is gone on reopening.

**Why it needs a person:** it needs an event holding such a time, which is a
state no test here can construct, and the thing being checked is that a save
that did not touch the field did not quietly round it.

### 1.160 A category pill on the real sfaf.org embed (3.98.1)

Open a page on sfaf.org carrying the embedded calendar and **press a category
pill**. The list must narrow and **stay narrowed**: no reload, and the address
bar unchanged. Press another, then press **All Events**. Then do the same on
resources.sfaf.org, which runs the other script.

**Why it needs a person:** this is the fault as reported, on the real page,
through the real endpoint, and the one thing the headless check cannot include
is a host page. sfaf.org's own theme and its own scripts are on that page too,
and `.claude/filter-submit-live.php` has neither.

> **AND IT MAY NEED A HARD REFRESH.** embed.js is cached by the browser on the
> host page. If a pill still reloads, check the version the console warns about
> before reporting the fix as not taken.

### 1.161 A pill press with JavaScript switched off (3.98.1)

In the browser's site settings, **block JavaScript for resources.sfaf.org**, open
the calendar, and press a category pill. The page reloads, the address carries
`?uc_cat=<that category>` and the list below is that category. Press **All
Events**: `uc_cat` is empty and every event is back. Tick an organizer and press
**Apply**: the category chosen before it is still in the address and still
applied.

**Why it needs a person:** the headless check reads what the browser would send
by asking the form for it, which is the right question but is not the same thing
as a browser with scripting genuinely off rendering the page that comes back.

### 1.162 The asterisks on both public forms, on the real pages (3.99.0)

Open the community form for any series, and a staff request link. Every field
the form refuses without has an asterisk after its label, in the label's own
colour, and nothing else has one. On the community form: choose **Something
else** under Cost and "What it costs" gains a mark; choose Free and it goes.
Tick **Use my name and email** and the contact marks go with the fields. Pick a
venue and **Street address** loses its mark. The top of each form says "Fields
marked * are required."

**Why it needs a person:** `.claude/required-marks-live.php` renders these
forms in a miniature WordPress with no theme, no TinyMCE and no second plugin on
the page. The real pages have all three.

### 1.163 A screen reader on the description box (3.99.0)

With NVDA or VoiceOver, tab into the **Description** editor on either public
form. It should be announced as required. Then tab to **Who is putting this
on?** on the request form: the group should be announced with "(required)".

**Why it needs a person:** the description is a TinyMCE editor, and the
announcement comes from portal.js copying aria-required onto the editor's own
body once it starts. TinyMCE does not run in the headless check.

### 1.164 Publish a new event that is missing a category (3.99.0)

In caladmin, **New Event**: fill in everything except the category and press
**Publish**. The event is saved as a draft and the message reads "Saved, and
not published. Add a category, then publish." Tick a category, press Publish,
and it goes out. Then make another with **only a title** and press **Save
draft**: it saves, with no message about anything missing. Every field the
publish needs has an asterisk, and the top of the form says "Fields marked *
are required to publish."

**Why it needs a person:** `.claude/publish-gate-test.php` runs the real save
in a miniature WordPress; the redirect, the flash on the real screen and the
real button row are not in it.

### 1.165 Save an already-published event that has no category (3.99.0)

Find a published event with no category or no organizer (the 3.85.0 "hundred"
are the likeliest). Change one word of its description and press **Save
changes**. It must **stay published**, with the ordinary "Event saved."

**Why it needs a person:** the rule is that a publish is held, not that a live
event is taken down, and only the real data has live events missing these.

### 1.166 The EveryAction panel keeps what it is given (3.100.0)

On **Settings & Integrations**, open **EveryAction**. Type the API key, the
username and the password, and press **Save Changes**. After the reload the key
and password boxes are empty and say "Saved. Leave blank to keep it"; the hub
address reads `https://hub.sfaf.org` and the tracker ID `162570`. Change the
**Events per page** setting, save again, and press **Test connection**: it must
still log in with what was saved, not report the key or password missing.

**Why it needs a person:** the checks here save through the real
`sanitize_settings()` in a miniature WordPress; the real options screen, its
redirect and a real database are not in it.

## 2. Needs real conditions

Waiting for an unattended job to fire, a real removal at source, or a real event
with real registrations and real mail.

### 2.20 A real cancellation, with a message for the registrants (3.72.0)

**Register a real address for a test event, then cancel it**, filling in both
boxes: the public **Why, in one line** on the form, and the registrants-only
message in the confirmation's second step.

Then read what arrives, and open the event page beside it.

- The **email** carries both, the public reason first.
- The **event page** carries the reason and **not** the second message.

That separation is the whole reason they are two fields, and it lives in two
places at once, a mail builder and a page template, so only a person with both
in front of them can settle it. `.claude/email-render-test.php` proves the mail
half and can say nothing about the page.

**This cannot be undone.** Use an address you own.

### 2.21 The rejection notice, wording first (3.72.0)

**Read the copy before anybody sends one.** It is the only message this calendar
sends that tells somebody no, it goes to a member of the public with no account,
and it has not been approved. It is in `SFAF_Submissions::send_rejected_notice()`
and the hand-off quotes it in full.

What it deliberately does not do: apologise, invite an appeal it cannot honour,
explain what this calendar is for, or open with thanks. Each of those was
written and taken out again, and each is a reasonable thing to want back.

Once the wording is settled, reject one real test submission with the tick on
and a note filled in, and read what arrives.

### 2.22 The published notice, on a community submission only (3.72.0)

Approve a real community submission with **Email ... that this event is
published** ticked, and confirm it arrives at the submitter and at nobody else,
even where the submitter named several addresses for RSVPs.

Then approve a **staff request** and confirm that tick is not on the screen at
all. A staff requester already has a confirmation saying the team will look at
it and can open caladmin; the notice exists for somebody who has neither.

### 2.23 Who is told a submission arrived (3.72.0)

**This changes who gets an existing email**, so it wants checking on the day it
installs rather than the first time somebody submits.

Open **Settings, Email, Submissions**. It defaults to `websites@sfaf.org`.
Before 3.72.0 that message went to everybody holding Admin on the calendar, so
anybody used to receiving it will stop unless their address is in that box.

Submit one test event through each form and confirm the alert lands where the
setting says and nowhere else. Emptying the box restores the old behaviour.

### 2.24 The "back on" message, wording first (3.73.0)

**Mark has not read this copy and it goes to people outside the calendar team.**
It cannot send without an explicit yes, like every other message in the
confirmation, so nothing is at risk while it waits. It is quoted in full in the
3.73.0 hand-off; this is what it says on an event whose date also moved.

```
Subject:  Back on: <event title>

<Event title> is back on.

<First name>, this event was cancelled and is happening after all.

It has also moved: it was Wednesday, September 16, 2026 and it is now
Thursday, September 17, 2026.

Your registration was kept and still holds, so there is nothing to do if the
new details suit you.

<date, time, place>

Put it back in your calendar   [ Google ]  [ Apple or Outlook ]

See the event page

Cannot make the new date? Cancel your registration so somebody else can take
your place. We will ask you to confirm.
```

On an event put back on its original date the third paragraph is absent and the
last one opens "No longer able to come?" instead. Nothing else changes.

**Then send one.** Cancel a test event with a test address registered, put it
back on, and tick the box. Confirm the message arrives, that the cancel link in
it cancels that registration, and that the calendar buttons carry the date it is
on now rather than the one it was cancelled from.

### 2.25 A real community submission, with the details tick and a chosen picture (3.74.0)

**The form changed in four places at once and only a real submission exercises
them together.** Open the community form at a real series' address.

- **Leave the new tick alone.** The three contact fields are visible; fill them
  in with something different from your own details. Submit, then read the
  pending row and the event page: the public contact must be what you typed, and
  your own address must be nowhere on the page.
- **Submit a second one with the tick ON.** The three fields disappear, and the
  event page must show your name and your email as the contact. **No phone**,
  because the form never asked you for one.
- **Choose a picture from the chooser** rather than uploading. The event should
  arrive already carrying it as its image, which an uploaded file deliberately
  does not do.
- **Read the labels while you are there.** "Enter location manually" and
  "Capacity" are new, and so is the tick's wording.

### 2.26 An upload from the Images screen, on the real server (3.74.0)

**The one thing in this release that writes a file, and the folder rule is the
part that can quietly be wrong.** Upload an image from caladmin > Images.

It must land in **`wp-content/uploads/calendar/`**, not in a dated directory. If
the screen says it went into the general media library, that message is the
check working and something is wrong with the folder filter: say so rather than
uploading more.

Then confirm the same file appears in the picker inside an event, which is the
outcome that matters and the reason the folder rule exists.

### 2.19 Open the notification card on an imported event and an imported occurrence (3.69.0)

**The one thing in the import that could reach real people.** The build proves
the code is right and cannot prove the database is, so this needs a screen.

Open any imported event, then **a generated date of the same event**, which is
the case that needed its own handling because the opt-out is not among the meta
an occurrence inherits. On both, the notification card must show **nobody at
all**, and the creator must be listed as opted out rather than absent. Then
**publish one imported event and register a test address on it**: no alert may
reach anybody, because there is nobody to alert.

If a name appears on either, stop before publishing anything else and say so.
The import's own report would have failed on it, so a name here means the check
and the database disagree.

### 2.18 Delete the import folder from the server (3.69.0)

It is three files that write content and clear events, guarded by
`manage_options` and a confirmation word. Neither guard is a reason to leave it
there after the one time it is needed.

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


### 2.14 A hybrid event registered in both formats, end to end (3.96.0)

Create an event, tick **This is a hybrid event**, give it a venue AND a meeting
link, tick the confirmation delivery, and set **Places in person** to 1 and
**Places online** to 1. Then, from two different addresses:

1. Register choosing **In person**. The confirmation must carry the address and
   **must not carry the meeting link anywhere**, including in the calendar file
   it offers. Open that .ics and read it.
2. Register choosing **Online**. That confirmation must carry the link and say
   "Online Event" where the address would be.
3. Open the form a third time. Both options now read (full) and the form says
   so. Cancel one registration and check that format alone opens again.

**Why it needs a person:** there is no WordPress, no database, no browser and no
mail here, and this is the one path where getting it wrong sends a meeting link
to somebody who said they were coming in person. The gates are unit tested and
sixteen planted faults are caught; what a person is checking is that the message
that actually arrives in an inbox obeys them.

**Also check the morning-of summary and the registration alert.** Both WERE
built for hybrid, and the alert's half was dead until 3.97.3: it names the
registrant's format and counts that format's places, but the person object it
reads never carried a format, so it always fell through to the whole-event
count. The summary was never affected, because it reads the `format` column off
the rows rather than off a person object. Check the alert now says "Online" or
"In person" and that its count matches that format, and that neither message
carries the link.

### 2.15 A hybrid event's meeting link in the calendar file it offers (3.97.2)

3.97.2 widened the calendar file's gate from `is_online()` to
`has_online_format()`, so a HYBRID event's online registrant now gets the link
in the `.ics` attached to their confirmation. Nothing about the token changed,
and that is the half worth checking.

On a hybrid event with a meeting link and the **confirmation** delivery ticked:

1. Register choosing **Online**. Open the `.ics` the confirmation offers. It
   must carry `Join:` in the description and a `CONFERENCE` line, and the
   description must also say the event is in person and online.
2. Register choosing **In person**. That confirmation's `.ics` must carry
   **neither**, and must still carry the address.
3. Take the `?uc_ics=` URL from the ONLINE registrant's mail, strip the `j=`
   parameter, and load it in a private window. It must return the ordinary
   public file with **no link in it at all**.

**Why it needs a person:** there is no mail and no database here, and step 3 is
the one that matters: it is the check that widening WHICH events can carry a
link did not widen WHO is handed one.

### 2.16 The meeting link actually arriving, on all three messages (3.97.3)

3.97.3 fixed the fault where the format never travelled with the registrant, so
no hybrid event ever sent its meeting link. Everything about it is asserted
here and eleven planted faults are caught, but no message has left this machine.

On a hybrid event with a link entered and BOTH delivery ticks on, from two
different addresses:

1. Register **Online**. The confirmation must carry the joining block with the
   link, and must say "Online Event" where the address would be. Open the
   calendar file it offers: it must carry `Join:` and a `CONFERENCE` line.
2. Register **In person**. That confirmation must carry the street address and
   the link **nowhere**, including in its calendar file.
3. **Let the morning-of reminder run** for that event, or trigger it. The online
   registrant's reminder must carry the link; the in-person one must not; and a
   staff member on the notification list must get neither the link nor a cancel
   link, because they hold no place.

**Also read the registration alert** that went out for each. It must name the
format, "Online" or "In person", and its count must be that format's count, not
the whole event's. That sentence was built in 3.96.0 and was dead until now.

**Why it needs a person:** there is no mail here, and the reminder runs from
cron against a real event on a real day. Step 3 is the one nothing else covers:
the reminder's recipient list is a database query, and whether it carries the
format through to the message can only be settled by a message arriving.

### 2.17 Every time in a real email says the zone (3.99.0)

Register for an event, submit the community form, and send a staff request, and
read the mail each produces in a real inbox. Every time reads like "6–7:30 pm
PT". Then move an event that has a registration by an hour, and tell people:
the change email reads "time 6–7 pm PT to 7–8 pm PT". **The event page, the
cards and the calendar file show no zone**, as before.

**Why it needs real conditions:** real mail, sent by the real site, read in a
real client. `.claude/email-zone-test.php` proves every mail call site asks
for the zone and `email-render-test.php` renders the notifications; neither
sends anything.

### 2.30 The first EveryAction fetch, by hand, and one event through to its signup page (3.101.0)

**Before starting:** ideally Val has removed the tracker's duplicates (the 2027
Saturdays under two UUID runs, and the coffee social under two names), or the
queue will hold both copies. Either way, **leave Auto-Import off throughout.**

1. **Settings & Integrations, EveryAction.** Auto-Import events is off. Press
   **Test connection**: it should say Connected.
2. **Pending, Fetch updates.** The report's EveryAction line should say how many
   events were added and how many rows were checked. **If it says the list "may
   be incomplete", stop and tell me**: the tracker's page parameter is not the
   one the build guessed, and nothing past the first hundred rows was read.
3. **The queue.** Open three EveryAction rows. The coffee social should start at
   **10 am in both October and November**. A date in the evening, or one an hour
   out after early November, means the times are converted wrongly. The place
   name should read "Maxfield's House of Caffeine" with the address under it.
   Each program should sit in its own series, named after it. Descriptions are
   mostly empty and marked as needing filling in.
4. **Settings & Integrations again.** Last fetch shows the rows read and the
   number created. Signup links shows "N of M upcoming events have their own
   signup page", and **N should be most of M**. N near zero means the public
   list's markup has changed.
5. **Approve one.** Pick a coffee social date. Give it a category, an organizer,
   a description and a picture, and publish it.
6. **Its page.** The registration button should open **that date's page on
   han.sfaf.org**, not the list and not a different Saturday. Then find an
   event whose button goes to the list instead (one the public list does not show
   yet): the list should open filtered to that event's day.

Report the numbers from steps 2 and 4, the three times from step 3, and where the
two buttons in step 6 went.

**Why it needs real conditions:** the real tracker, the real public list and a
real published event. The build proved each rule against a model hub and a
saved copy of the list, but the tracker rows it used were built from the row
shape as described, because no probe body was ever recorded.
## 3. Blocked on other people

Nothing here can move until somebody outside the build answers.

| Who | What is needed | Status |
|---|---|---|
| **Aaron** | DNS records for `calendar.sfaf.org` so `events@calendar.sfaf.org` can send | Asked. From stays `websites@sfaf.org` meanwhile. It is a setting, so nothing needs deploying when the mailbox exists. |
| **Val** | Remove the tracker's duplicates: **the 2027 Saturdays under two runs of UUIDs**, and **the coffee social under two names** ("50-Plus Saturday AM Coffee Social" and "Saturday AM Coffee Social") | Before Auto-Import goes on. The import copies what the tracker holds and de-duplicates nothing. `PROJECT.md` 3. |
| **Salesforce admin** | Pardot connected app: client ID and secret, Business Unit ID, service user, OAuth flow | Asked. Campaign IDs store; nothing talks to Pardot. |

---

*Delete an item when it has been done. Add one in the group that matches what it
needs from a person, not the release it came from. Keep the count at the top of
this file in step.*
