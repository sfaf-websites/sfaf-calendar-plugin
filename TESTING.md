# TESTING.md, SFAF Calendar

**The manual testing backlog.** Everything here is a claim about code that has
never run: there is no WordPress, no database, no browser and no mail in the
build environment, so none of this can be settled by the test suite or by
reading the repository. Each item says what to do and why it needs a person.

**A finished test is DELETED from this file, not marked done.** This is a
backlog, not a record of what has been checked. What a test proved, if it is
worth keeping, belongs in `PROJECT.md`.

**Outstanding: 142 items.** Quick 116, needs real conditions 23, blocked on other
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
