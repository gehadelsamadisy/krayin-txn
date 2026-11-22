# Shared Krayin Persons columns

This customization adds a **Fields (all admins)** selector to the Persons
list. Choices are stored in Krayin's `core_config` table and apply to every
admin. It includes the standard Person fields (including Job Title and Sales
Owner) and all user-defined Person attributes, such as Address, City,
State/Province, and Postal Code. By default, ID, Name, Emails, and Contact
Numbers are shown; other fields can be enabled from the selector.

The **Contacts > Workers** page lets admins register, view, and edit a worker
roster without creating Krayin login accounts. Worker records include name,
phone, email, notes, active status, a geocoded start/end address, weekly hours,
priority, services, a daily visit cap, calendar color, and multiple time-off
entries. **Company schedule & holidays** sets the shared default work hours
and company-wide holidays. The booking slot finder uses active workers,
addresses, hours, priority, time off, holidays, and visit caps when looking for appointment times. The booking-link form can
filter suggestions by a worker service. Geocoding uses the existing
`GEOCODIO_API_KEY`.

**Schedule** on each worker row opens that worker's week as a calendar
(`contacts/workers/{id}/schedule?date=YYYY-MM-DD`; the Monday–Sunday week
containing that date, default this week in the slot finder's time zone).
Booked visits are blocks in the worker's calendar colour (customer, time,
address; click opens the activity); done visits are faded and cancelled ones
crossed out. Hours outside the worker's work hours are hatched, holidays and
time off are hatched amber, and the 9/11/1/3/5 times still open from now on
are shown as a light tint. Previous/Today/next week, **Jump to date** and a
worker switcher move around. Anyone who can see the Workers list can open it.
Workers with edit permission can use **Book worker** to schedule either an
internal work visit or another personal booking with a title and time, without
attaching a customer. Both reserve the worker's time, reject overlaps and time
off, appear separately from customer visits, and can be marked done from the
activity page. Work visits require a location and appear on the worker's route
map; other bookings do not become route stops.
The schedule stylesheet is included in the admin layout's document head so it
survives Krayin's Vue app mount; its rules are scoped to the worker schedule.

**Routes** (Workers list, a worker's schedule, or "Route ›" on a schedule
day; `contacts/workers/routes?date=YYYY-MM-DD&worker=ID`) shows one day's
route for every active worker, or for one worker, on a map with the stops
listed beside it. It is the Krayin version of `TXN/Routing/routes.py`
(`Support\Contact\WorkerRouteService`): the worker's start address, their
visits in booked-time order (never reordered), then back to the start.
Cancelled visits are left out; done ones are shown faded; visits without an
address are listed under "Not on the map". A visit with an address but no
coordinates is geocoded once and the coordinates are saved on the activity.
Road lines, drive minutes and miles come from one OSRM request per route
(cached 30 days), with the rush-hour factors from `config/slot_finder.php`
`routing`. Each stop shows the drive to it; "Leave by" is when the worker must
set off for the first visit, and a stop is marked late when the drive from the
previous visit can't make its booked time. If OSRM can't be reached, the page
says so and uses straight lines and the slot finder's estimate. The map is
Leaflet with OpenStreetMap tiles (no WebGL needed). Set `OSRM_URL` to use your
own OSRM server instead of the public test server.

Activities whose end time is missing or not after the start are treated as
one-hour visits on the schedule and route pages (an edited start date can
leave the old end behind).

Scheduled activities can be assigned to one or more active workers from the
activity create dialog or edit page. Assignments are shown in the Activities
calendar and list and are checked for worker schedule overlaps when saved.
Each time in a new Cal.com booking link has its own worker (Suggest times
fills them in; a typed time needs one chosen). Krayin saves which worker goes
with which time (`calcom_booking_links`, migration
`2026_11_04_000100_create_calcom_booking_links_table`), and when the booking
syncs, the worker of the booked time is attached to the activity; a
reschedule to another worker's time moves it to that worker, unless staff
changed the worker in Krayin. Links made before this name their single worker
in the event type slug, which still works. Previously created Cal.com bookings and
other unassigned activities can be assigned from the activity edit page.

The lead detail sidebar also handles missing person email and contact-number
lists without failing the page.

The admin logo can be set from **Settings > Configuration > General >
Admin Logo**. The shared header, mobile navigation, and sign-in/reset-password
screens use the same configured image; a custom PNG favicon is also supported.
For this Docker setup, copy customized package files into
`/var/www/laravel-crm` and run `php artisan optimize:clear` in the container.

The patch mirrors the relevant files from the running Krayin installation.
Apply the files under `packages\Webkul\Admin\src` to the same paths in
`/var/www/laravel-crm`, including the new
`Support\Contact\PersonGridColumnPreferences.php` helper. A local Docker image
snapshot was created before the original customization as
`txn/krayin-backup:before-person-columns`; the current version is saved as
`txn/krayin-backup:person-fields-selectable`. The running `krayin-container`
has no bind mount to this workspace, so restarting it does not deploy workspace
changes. After copying changed files into the container, run
`php artisan optimize:clear` from `/var/www/laravel-crm`; alternatively, build
and run an image that includes or mounts these customizations.

## Automation engines and the event log

Pipeline: lead arrives (TXNHome webhook) → lead check → booking link →
customer books → booking in Krayin → follow-ups. The first two are
automatic; the rest can be automated with **engines**, all **off** as shipped
(`config/automation.php`), switched in **Settings > TXN Settings >
Automation** (permission `settings.txn.automation`):

| Engine | What it does when on |
| --- | --- |
| Master switch | Off = no engine does anything (staff buttons still work) |
| Email engine | A **new** lead that passes the check (optionally also a Needs Review lead that gets fixed), in a chosen flow (default Standard), gets its booking link automatically: best times suggested, Cal.com link created, email, lead → Contacted, follow-ups scheduled. Never twice for a lead. Reminder / final email / mark Lost each on or off. Cancellation email when a customer cancels a booked visit (once, no follow-ups after it) |
| SMS engine | Texts the automatic booking link, the reminder, the final follow-up and the cancellation notice (requirements A7/A8: same channels as the first message; all on by default, only while the engine is on). Templates on Emails |
| Staff notifications | Plain email to listed staff: lead needs review, out of area, no times found, automatic send failed/blocked, booking cancelled, lead marked Lost |
| Cal.com webhook (own switch) | `POST /txn/calcom-webhook`: bookings, reschedules and cancellations saved at once |

**Follow-up schedule** (`txn_scheduled_messages`, `Support\Automation\MessageScheduler`):
when a booking link is emailed with the Email engine on, each follow-up step
that is on becomes an entry with its exact time (link time + the hours on
Settings > Follow-ups): reminder, final email, mark Lost. Each pending entry
has a delayed job (`Jobs\SendScheduledMessage`, Laravel database queue,
queue `txn-schedule`) that the always-running `krayin-queue` worker fires at
that time. The entry is the truth: a job whose entry is no longer pending, or
whose time changed, does nothing; a claim (pending → sending) makes sure an
entry is never sent twice. Before sending it checks again: follow-ups still
open, lead still in Contacted, step not done, not booked in Cal.com (if
Cal.com can't be asked, it retries 15 minutes later), step still on.

Entries are **cancelled** (with the reason, in the event log) when the
customer books (webhook or Sync), the lead leaves Contacted (any stage move)
or is deleted, a newer link is sent, or the step / engine is switched off;
switching it back on schedules the steps still ahead; new hours re-time the
pending ones. **Leads > Scheduled messages** (permission `leads.scheduled`,
actions `leads.scheduled.edit`) and a Scheduled card on each lead: see the
entries, **Cancel**, **Send now**, **Change time**.
**Leads > Waiting for booking** (permission `leads.waiting`): every lead in
Contacted with when and how the link went out, the times offered, the
follow-up state and the next scheduled step; filters (sent today / 7 days /
over 3 days, planned or not), New link, Send next now, Stop follow-ups.
`php artisan txn:schedule-repair` re-queues a job for every pending entry
(deploy.sh runs it after migrating). `txn:follow-ups` still sends, by hand,
follow-ups that are not on the schedule (sent before it, or with the engine off).

The Safety guards apply to everything: `BookingLinkGuard` (Cal.com guard)
is checked inside `CalcomBookingLinkService::createBookingLink`, so no path
can get around it. Staff edits never send a link by themselves.

Code: `Support\Automation\{Automation, EmailEngine, StaffNotifier}`,
`Support\Contact\BookingLinkSender` (shared by the booking-link page and
automation: `createLink`, `deliver`, `sendAutomatic`), events
`txn.lead.checked` (LeadTriageService::check) and `txn.booking.changed`
(CalcomBookingSyncService::upsert), `Http\Controllers\Contact\CalcomWebhookController`.

**Turning it on (when you decide):**

1. Schedule: nothing to set up. The `krayin-queue` worker (always running)
   listens on `database` / `txn-schedule`; check with
   `docker exec krayin-queue ps aux | grep queue:work`.
2. Cal.com webhook: Krayin must be reachable from the internet over HTTPS.
   In Cal.com: Settings > Developer > Webhooks > New: Subscriber URL
   `https://<krayin>/txn/calcom-webhook`, events Booking Created / Rescheduled /
   Cancelled, a long random secret. Type the same secret on the Automation
   screen and switch the webhook on. A request without the right
   `X-Cal-Signature-256` (HMAC-SHA256 of the body) gets 401; with the webhook
   off, 404. The booking is re-read from the Cal.com API by uid and saved with
   the same `upsert` as the Sync button (which stays as the backup).
3. Engines: switch the master switch and each engine on, on the Automation screen.

**Event log** (`txn_events`, `Support\Contact\PipelineEvents`): every step,
by staff or automation, one readable line with who did it (staff name,
"Automation", "TXNHome webhook", "Cal.com webhook", "Scheduler", "Command"):
leads imported (counts) and checked (summary + one line per lead), times
suggested, link created (times and workers), link sent (email/SMS outcome),
not sent / blocked / failed (with the reason), reminder, final email, fresh
link, follow-ups stopped, marked Lost, booking received / rescheduled /
cancelled (time and worker), syncs, engine switched, staff notified.
Shown at **Leads > Event log** (button on the leads list; permission
`leads.event_log`): totals for the dates, filters, timeline, CSV export; and
in an **Events** card on each lead and contact. Kept forever.

Check: `tests/automation-check.php` (run by `scripts/test-on-copy.sh`).

## Yelp messages (receiving and replying)

A Zap (trigger **Yelp > New Message**, action **Webhooks by Zapier > POST**)
sends every Yelp chat message, the customer's and ours, to
`POST /txn/yelp-message` (`YelpMessageController`, header `X-TXN-Token`),
as JSON: `lead_id, event_id, user_type, user, text, time, attachment_url,
attachment_text`.

- **Where it's saved:** table `yelp_message` in the lead database, next to
  `lead` (`docs/sql/yelp_message.sql`). Krayin writes there with its own login
  `krayin_msg` (SELECT/INSERT/UPDATE on that table only, connection `msg_db`).
  `krayin_ro` stays read-only on `lead`.
- **Rules:**
  - Switch off: 404. Wrong token: 401.
  - No lead id / event id, or neither text nor attachment: 422.
  - The same `event_id` again is kept once ("duplicate").
  - Database unreachable or no login: 503, so the Zap run errors and can be resent.
- **Matching:** a message belongs to the Krayin lead whose title has
  `[TXN-YELP-<lead_id>]`. Messages for leads not in Krayin are kept and
  listed as "No lead in Krayin".
- **Where it shows:**
  - **Yelp messages** in the sidebar and **Leads > Yelp messages** (permission `yelp_messages`): conversation list (All / Unread / No lead in Krayin, search) and the chosen chat. Customer on the left, us on the right, photos inline.
  - A **Yelp messages** card on the lead.
  - A **Yelp messages** button with an unread badge on the leads list.
  - Opening a chat marks it read.
  - **Reply in Yelp** opens the lead's `reply_url` from the lead database.
  - **Starting a chat** (also before any message): **New chat** on Leads >
    Yelp messages searches the Yelp leads in Krayin by contact or lead title;
    a **Yelp chat** button on a Yelp lead's page and on its contact's page
    (one per Yelp lead) opens `?chat=<Yelp lead id>`.
- **Replying from Krayin** (`YelpReplySender`, permission `yelp_messages.reply`):
  - A reply box under the chat (Leads > Yelp messages and the lead card) posts
    to `POST admin/leads/yelp-messages/{chat}/reply`.
  - Krayin can't write in Yelp (Yelp's API is for approved partners), so the
    reply goes to a second Zap: **Webhooks by Zapier > Catch Hook** -> **Yelp
    Leads** send-message action. Krayin posts JSON `lead_id, text, request_id,
    sent_by, sent_at`.
  - Order: switch -> **Yelp reply guard** (Safety; only the Yelp lead IDs in
    "Allowed test Yelp chats"; on unless `TXN_GUARD_YELP=false`) -> reply Zap
    address -> saved in `yelp_message` as ours (event id `krayin-<uuid>`,
    `raw_payload.source = krayin`) -> posted to the hook.
  - Status under the bubble: Sending… / Sent to Zapier · waiting for Yelp /
    ✓ Seen in Yelp / Not sent: <error>. After 30 minutes without Yelp's copy:
    "not seen in Yelp yet: check the reply Zap's history".
  - When the New Message Zap brings our reply back (same chat, same text,
    spacing ignored, within 3 days), the saved row takes Yelp's event id and is
    marked confirmed: the webhook answers "matched" and the chat shows it once.
  - Event log: "Yelp reply sent from Krayin" (with who), "Yelp reply not
    sent", "Yelp reply blocked by the guard".
  - Settings: Automation > Yelp messages (switch "Send replies typed in Krayin
    to Yelp", ships off; reply Zap address, secret); Safety > **Yelp replies**
    (guard, allowed test chats). `.env`: `YELP_REPLY_HOOK`,
    `YELP_TEST_LEAD_IDS`, `TXN_GUARD_YELP`.
- **Event log:** "Yelp message received" / "Yelp message sent by us".
- **Settings:**
  - Settings > TXN Settings > Automation > **Yelp messages** (switch, ships off; token; the webhook address to copy)
  - Connections > **Yelp messages database** (login, password, Test)
  - `.env`: `MSG_DB_USERNAME`, `MSG_DB_PASSWORD`, `YELP_MESSAGES_TOKEN`

Check: `tests/yelp-message-check.php` (run by `scripts/test-on-copy.sh`).

## Customer emails in Mail > Sent

Every customer email Krayin sends is copied into Krayin's own `emails`
table: the booking link, the reminder, the final email and the cancellation
email (`BookingEmail::send`).

- **Where they show:** in folder "sent", so in **Mail > Sent** and on the lead.
- **What's kept:** subject, body, recipient, contact and lead.
- **Only emails that actually left.** One stopped by the email guard is not copied.
- **Staff notifications are not copied.**
- **No workflows:** the copy is written with the Email model directly, without
  Krayin's `email.create` events, so no workflow or participant email reacts to it.
- **Person links:** on any email's page (`mail/view.blade.php`, a copy of
  Krayin's), the linked contact's name, avatar and arrow, and the person on
  the lead card, open the person's page (Krayin opened the edit form in a new tab).
- **"To" in the list:** Mail > Sent, Drafts and Outbox show the recipient
  (the linked contact's name, else the first address) under "To" instead of
  our own name under "From" (`DataGrids/Mail/EmailDataGrid.php`, a copy of
  Krayin's). Inbox and Trash keep "From".

Check: `tests/automation-check.php`.

## Email while testing: one allowed recipient

`app/Providers/AppServiceProvider.php` stops every email whose recipients
(to, cc and bcc) are not all listed in `MAIL_ALLOWED_RECIPIENTS`
(`config/mail_guard.php`, comma-separated); a stopped email is logged as a
warning in `storage/logs/laravel.log`. Empty = nothing is ever sent. This
covers everything Krayin sends: booking links, the "Emails to participants"
workflows, notifications. Contact emails are fake (`@test.invalid`) except
the one test contact (`CALCOM_TEST_PERSON_ID`). Cal.com's own emails to
whoever books are outside Krayin and not covered.

## Email through Microsoft 365 (`MAIL_MAILER=graph`)

`app/Mail/GraphTransport.php` sends Krayin's mail through Exchange Online
with the Microsoft Graph API instead of SMTP (Microsoft is retiring password
logins for SMTP). It signs in as an Entra app (client credentials, `Mail.Send`
application permission), posts the complete MIME message to
`/users/{MAIL_FROM_ADDRESS}/sendMail` (copy in that mailbox's Sent Items) and
caches the app token. The mailer is registered in `AppServiceProvider`, so the
stock `config/mail.php` is unchanged; the guard above applies as with SMTP.

- **Admin setup (once):** a shared mailbox to send from; an app registration
  with `Mail.Send` (Application) + admin consent; a client secret; an
  application access policy restricting the app to that mailbox. Details in
  `config/graph_mail.php`.
- **`.env`:** `MAIL_MAILER=graph`, `MAIL_FROM_ADDRESS=<that mailbox>`,
  `GRAPH_TENANT_ID`, `GRAPH_CLIENT_ID`, `GRAPH_CLIENT_SECRET`. Back to SMTP:
  `MAIL_MAILER=smtp`.
- Every email must come from that mailbox: a different From address is
  refused by Microsoft (403 `ErrorAccessDenied`).
- **The client secret expires** (max. 24 months). Then sending fails with
  "Microsoft 365 sign-in for sending email failed"; create a new secret and
  update `GRAPH_CLIENT_SECRET`.
- Errors reach the same places as SMTP errors: the booking-link page shows
  "copy the link and send it manually", others are in `laravel.log`.
- Check: `tests/graph-mail-check.php` (all HTTP faked, nothing sent).

## SMS (Twilio): one allowed test number

`Support\Contact\SmsSender` sends texts through Twilio's API with Krayin's
own settings (`config/sms.php`: `TWILIO_ACCOUNT_SID`,
`TWILIO_AUTH_TOKEN`, `TWILIO_MESSAGING_SERVICE_SID` or `TWILIO_FROM_NUMBER`
in the Krayin environment). Like the email guard, a text leaves only if the
number is in `SMS_ALLOWED_RECIPIENTS` (comma-separated; empty = nothing is
sent); a blocked text is logged. Each sent text is a "SMS sent to …" note on
the contact and lead with Twilio's message id. Twilio needs a + country code:
US numbers become +1…, a local number written without + (e.g. 01222222222)
can't be texted. **Send booking link** texts the link too when the contact
has such a number. Customer replies still go to the Messaging Service's
webhook (TXNHome), not to Krayin.

## How Suggest times picks the worker and times

`Support\Contact\SlotSuggestionService`, settings in `config/slot_finder.php`.
Every active worker's free 9/11/1/3/5 times in the next 7 days are scored by
the extra driving each adds to that worker's day (drive in + drive out −
the direct drive it replaces). Drive times are road times from one OSRM table
request per suggestion (cached a day; straight-line estimate if OSRM is
unreachable or there are more than `ranking.max_table_points` places). All
workers' times go into one pool and the 3 adding the least driving are
offered (at most 2 on one day), whichever worker each belongs to — a worker
already working in the area or passing by. When two workers are free at the
same hour, that time is offered once, with the one it suits best. About equal
(`route_fit_tolerance_minutes`) → sooner, then the worker with fewer jobs,
then priority. No worker is picked up front: each offered time carries its
worker, and the worker of the time the customer books gets the visit. A time
is "late" when the drive from the previous job or to the next one (with the
Routes page rush-hour factors) doesn't fit; late times are ranked after
reachable ones but still offered if nothing else is left. Times adding more
than `max_extra_drive_minutes` are offered only when nothing cheaper and
reachable is left.

Measured on the demo replay (70 real leads, 2 workers starting at the office),
driving stayed at about 69 min per visit with every setting tried: about half
is the drive out from and back to the office, half between customers who are
far apart. The slot rules can't remove that; workers starting nearer their
area, area days, or more customers per area can.

## Inactive and deleted workers

Contacts > Workers > Edit: "Available for new appointments" off = inactive
(no new suggestions or bookings; history stays). **Delete this worker** (bottom
of the edit page, permission `contacts.workers.delete`) only works for a worker
who was never used: no appointment or task in any state (pivot
`activity_workers` or the older `activities.worker_id`) and no Cal.com booking
link offering them (`calcom_booking_links.slot_workers`). Otherwise it is
refused with the counts, so no booking loses its worker. Their time off is
deleted with them. Check: `tests/worker-delete-check.php` (run by
`scripts/test-on-copy.sh`).

## Worker appointments and demo data

Activities have a `worker_id`, `latitude` and `longitude` (migration
`2026_11_03_000200_add_worker_to_activities`). **Suggest times** plans around
Krayin activities that have a worker and are not done; the demo JSON file is
no longer read. Cal.com bookings arrive without a worker, so they don't block
anyone's time until a worker is set on them.

`php artisan txn:demo-appointments` books demo visits for the real leads and
contacts already in Krayin (`Support\Contact\DemoAppointmentSeeder`). It
replays leads arriving: 7 each evening for 10 evenings from the Sunday before
this week, leads with a street address first. Each one gets the slot finder's
offer and picks a time (usually the first), passing on any time that would
make the worker late on the Routes page; if every time would, it doesn't book.
Two busy blocks are added first. Visits that have already ended are marked
done. Only activities are created (tagged `additional.txn.demo`), linked to
the contact and the lead; contacts and leads are not changed. Running it again
replaces them; `--remove` deletes them (and the made-up `@txn-demo.example`
customers of the earlier demo). It calls Geocodio (cached) and OSRM.

`php artisan txn:check-schedules [--from=YYYY-MM-DD] [--days=45]`
(`Support\Contact\WorkerScheduleChecker`) lists problems in the workers'
schedules: two activities of one worker at the same time, an end not after
the start, customer visits outside work hours or on a holiday, day off or
time off, visits without a located address, and visits the worker reaches
late (Routes page drive times). Cancelled bookings and done busy blocks don't
count; done customer visits do. It exits with 1 when it finds problems.

The Activity model refuses to save an activity whose end is not after its
start, whatever saves it.

After copying the worker roster files, run
`php artisan migrate --force` in `/var/www/laravel-crm`, then clear Laravel's
cached routes and configuration with `php artisan optimize:clear`.

## Cal.com booking links

The Person detail page has a **Send booking link** action. It lets an admin
choose an existing Person email, enter exact one-hour appointment slots and a
time zone, and select currently blank custom text fields to ask on the booking
page. Cal.com receives a private event type with only those date overrides and
one location: the address stored on the contact, marked not public, so the
booking page doesn't show it or ask the customer to type one (Cal.com refuses
an empty `locations` list and adds Cal Video when it is left out). When no
usable street address is stored (only a ZIP, or something like "319"), the
booking page asks for all the address fields (Address, City, State/Province,
Postal Code, even ones already stored) without staff ticking them, and the
location is the partial stored address or
"Customer's home". If Cal.com returns any location other than that address,
no link is created. The link sent to the customer is a single-use
Cal.com private link: it expires after one booking, whatever email is entered.
Rescheduling and cancelling stay available from the Cal.com confirmation
email. Submitting the form creates the link and emails it using Krayin's
configured mail transport.

### Booking link from a lead

The lead page has **Send booking link** (beside Re-check lead). It opens the
same form for the lead's contact with `?lead=ID`; the form shows which lead it
is for, the lead is saved with the link (`calcom_booking_links.lead_id`,
migration `2026_11_05_000300_add_lead_to_calcom_booking_links`), emailing it
moves only that lead to Contacted and returns to the lead page. When that
booking syncs, the visit is attached to that lead only, and only that lead
moves to Appointment Set / Rescheduled / Canceled. Links sent from the contact
page have no lead and cover all of the contact's leads, as before.

### Bookings back into Krayin

`php artisan calcom:sync-bookings` (`Support\Contact\CalcomBookingSyncService`)
reads Cal.com bookings and saves each one as a **meeting** activity linked to
the person and the person's leads. They appear on the Person page, the Lead
page and the Activities calendar. Only bookings made through "Send booking
link" are copied; the event type slug `txn-home-visit-{person id}-...` names
the person. A reschedule moves the same activity to the new time. A
cancellation keeps the activity, titled "Cancelled: ..." and marked done. Times
are stored as America/Chicago wall time. The visit's location and map position
are the address stored on the contact in Krayin (street, else ZIP centre),
never anything typed on Cal.com. The comment holds the attendee's name (never
their email address) and any answers to the requested fields. When a booking
first arrives, its answers are also saved into the person's text fields
(Address, City, State/Province, Postal Code, ...), before the visit is located,
so a newly given street address is the visit's location and map position. The
link only asks for blank fields (or all address fields when the stored address
isn't usable), so an answer replaces what is stored; Krayin keeps the old value
in the person's history. Reschedules don't apply answers again.

### Lead check (normalize, classify, location)

`Support\Contact\LeadTriageService`, settings in `config/lead_triage.php`
(copy to `/var/www/laravel-crm/config/`). Run it with **Re-check lead** (lead
page, under the title), **Check new leads** (Leads list: every lead in Raw
Lead) or `php artisan txn:triage-leads [--lead=ID ...] [--new] [--dry-run]`
(the dry run does everything in a transaction that is rolled back). Both
buttons need the Leads "edit" permission and are added through Krayin's view
hooks (`AppServiceProvider`), so Krayin's lead views are unchanged.

The check also re-runs by itself (`recheckAfterEdit`, listeners in
`AppServiceProvider`) when a contact is saved (all of that contact's leads)
or a lead is saved from its edit form or inline fields, or the Cal.com sync
saves a phone or address the customer gave in the booking form (re-checked
after the stage moves, so a booked lead stays booked), so Missing Info,
Location Check and Next Action follow a new phone or address. Stage changes
don't trigger it, so a lead moved back by hand stays where it was put. If the
re-check fails, the edit still saves and the error goes to `laravel.log`.

1. **Normalize the contact** (`ContactDataNormalizer`): trims text, "N/A"-style
   values become empty, US phones become `+1XXXXXXXXXX`, any other number
   keeps its digits (with `+` if written internationally; anything under 7
   or over 15 digits is kept as typed and doesn't count), emails lowercase, ZIP to 5 digits (a ZIP at the end of the street
   line moves to Postal Code), "Texas" → "TX", all-caps/all-lowercase cities
   → Title Case. Changes go through Krayin, so the contact's history shows them.
2. **Classify the service** from the lead's Service Name (else the title):
   the first matching rule in `services` wins ("gutter" → Gutter; roof
   replacement → roof-report flow; roof repair/inspection/roofing → Roof
   repair; siding; fascia); no match → review.
3. **Check the location**: the stored street (if the map finds it) or the ZIP
   centre, miles from Houston (`slot_finder.service_area`, 120). Out of area is
   never rejected: it goes to review for the out-of-area message.
4. **Missing info**, out of the four TXN needs: name, a street address the map
   can find, a phone (any country), the service.

Results go into the lead fields Service Category, Flow, Location Check,
Missing Info, Next Action and Checked At (migration
`2026_11_05_000200_add_lead_triage_attributes`; written directly, not into the
lead history, since they're recalculated each time), and the full result is
shown under the lead title. Leads in Raw Lead, Needs Review or the two roof
stages:

| Result | What happens |
| --- | --- |
| Service known, standard flow, in area, has an email | Next Action "Send booking link (it asks for: ...)", stays in / goes back to Raw Lead (from Needs Review), "Needs review" tag removed |
| Roof replacement, nothing else wrong | Next Action "Get the roof report ..., then move the lead to Roof Report Received", lead → Roof Replacement pipeline, Waiting for Roof Report (from Raw Lead or Needs Review), "Needs review" tag removed. In Roof Report Received the check treats it like a standard lead ("Send booking link") |
| Unknown service, out of area, no address or ZIP, no email, location lookup failed | Next Action "Review: <reasons>", stage → Needs Review (from Raw Lead or Waiting for Roof Report; a lead in Roof Report Received stays there), tag "Needs review" |

Leads past those stages are checked but not moved or tagged ("None: the lead
is ..."). Running it again changes nothing that is already clean. "Check new
leads" and `txn:triage-leads --new` check every lead in Raw Lead and Needs
Review.

The booking link now also asks for a **phone number** whenever the contact has
no usable one (a Cal.com phone question, `txn_person_{id}_phone`); the sync adds
the answer to the contact's numbers. A stored street the map can't find (e.g.
"24320 WhiteOakDr") makes the link ask for the address again.

The booking-link email is HTML with a plain-text part
(`Resources/views/emails/booking-link.blade.php` and `booking-link-text.blade.php`):
greeting, the lead's service category, the offered times, a "Choose your time"
button and the rep signature. All wording comes from `Support\Contact\BookingEmail`.
After "Create link", the form shows the email with an editable subject, message
(before the times) and closing note, a live preview and "Reset the text"; what
is sent is what was edited. The greeting, times, button and signature can't be
edited, so they always match the link.

TXN's confirmation (requirements A9) is Cal.com's own confirmation email: when
the link is created, the Cal.com event gets the title "Free {service} estimate
| TXN Home Services" and a description with the address, a line for the
lead's service category (`service_lines` in `config/lead_messages.php`; roof
and siding from the requirements, the rest placeholders for Moe) and the
reschedule/cancel note. Cal.com shows these on the booking page and in the
confirmation it sends the moment the customer books, so there is no delayed
second email from Krayin. Sender identity is `config/lead_messages.php`
(`TXN_COMPANY_NAME`, `TXN_REP_NAME`, `TXN_REP_CELL`, `TXN_OFFICE_PHONE`;
defaults from the requirements). "Can't verify sender" / "via brevosend.com"
in inboxes means the From domain (`MAIL_FROM_ADDRESS`) isn't authenticated in
Brevo (Senders & IP → Domains: add its DKIM/DMARC DNS records).

On the Send booking link form every contact detail the booking page can ask
for (phone, address, city, state, ZIP, other text fields) is listed with its
stored value (`CalcomBookingLinkService::askChoices`, phone choice `__phone`).
Missing or unusable ones (no valid phone; address fields when the street
can't be found) are ticked by default (`askableCodes`); the admin can tick a
stored value to ask for it again or untick anything. When the booking syncs,
answers replace the stored values; a phone answer becomes the first number
(old unusable entries dropped, other valid numbers kept). If any address
field is re-asked, the Cal.com description doesn't print the stored address.
Name and email are always on the booking page.

Times can still be added, removed or changed after "Create link": the email
preview and the "Times in this link" list follow the time rows live, and
"Email this link" sends the current rows along. If they differ from the
link's, the link's Cal.com schedule is updated first (PATCH
`/schedules/{id}` overrides, `CalcomBookingLinkService::updateSlots`; the
schedule id is stored in `calcom_booking_links.schedule_id`), then the email
goes out with those times. Links created before this change can't be
updated; create them again.

### Import leads (CSV)

`Support\Contact\LeadCsvImportService`, settings in `config/lead_triage.php`
(`import`). Until leads are read from the lead database directly, **Import
leads (CSV)** on the Leads list (beside Check new leads, Leads "edit"
permission, route `admin.leads.import_csv`) opens the browser's file window;
choosing a CSV (the lead database's `lead` table exported from DBeaver, or
any file with its 14 columns `id, source, source_lead_id, first_name, email,
email_is_proxied, phone, address, city, state_province, postal_code,
service_name, comments, received_at`; extra columns are ignored; 5 MB max)
imports it at once and shows the result above the leads. While it runs, an
"Importing leads…" panel covers the page (file name, elapsed time and a bar
estimated from the elapsed time, since the server sends no progress; it slows
down near the end and stops at 95% until the page returns); when it's done a
"Leads imported: … new, … updated, … unchanged" toast appears. It replaces running
the Python importer while testing.

- The whole file is checked first (source ANGI/YELP, source lead id, first
  name, a valid email, a date for received_at). One bad row and nothing is
  saved; the panel lists every bad row.
- Upsert, never duplicate: a lead is found by its `[TXN-ANGI-…]` /
  `[TXN-YELP-…]` title tag (the importer's marker), and a row repeated in the
  file is skipped. Two Krayin leads with the same tag stop the import.
- New lead: Raw Lead in the default pipeline, source Web, type New Business,
  owner 1, the importer's title and description. Its contact is an existing
  one with the same phone number, else a new contact.
- Existing lead: the source facts (CSV record id, source, source lead id,
  received at, email is proxied) are refreshed. The service name and the
  contact's name, phone and address fields are filled only where empty
  ("N/A"-style values count as empty), so staff corrections and booking-form
  answers stay. Title, stage, pipeline, owner and emails are never changed.
- Emails: while testing a new contact never gets the customer's real address;
  it gets `contact{id}@test.invalid` (like the other contacts) unless the
  address is in `MAIL_ALLOWED_RECIPIENTS`. `LEAD_IMPORT_STORE_REAL_EMAILS=true`
  keeps real addresses, for when real data is imported.
  (Krayin's `persons.unique_id` is owner|email|phone and must be unique, so
  the placeholder used before the id is known is random, and unique_id is
  recomputed after the swap. With real emails, a contact with the same email
  is reused.)
- Everything is saved in one transaction; an unexpected error names the row,
  saves nothing and is logged. Then every new or changed lead goes through the
  lead check, for at most `check_seconds` (200, the server stops requests
  after 300 s); leads not reached stay in Raw Lead and the panel says how
  many. **Check new leads** has the same budget and says how many are left.
  A failed check (e.g. the map lookup) doesn't undo the import: it's counted
  in the panel and logged, and Check new leads retries it.
- Accepts the full lead-database export too (27 columns: `source_business_id`,
  `raw_payload`, … are ignored); quoted fields may span lines.

It replaced the Python importer (`Obsolete/TXN/krayin_csv_importer`).
Re-importing the 118-lead `leads.csv` gives "0 new leads, 0 updated, 118
unchanged". Krayin's activity logger adds its usual history entries for each
new lead and contact.

### Follow-ups (email)

`Support\Contact\FollowUpService`, settings in `config/follow_ups.php`, one row
per emailed booking link in `txn_follow_ups` (migration
`2026_11_08_000200_create_txn_follow_ups_table`). Hours count from when the
link was emailed:

| When (no booking) | What happens |
| --- | --- |
| 24h | Reminder email: the link's times that are still at least 3h away; if none, a fresh link with newly suggested times; if no time can be found, "reply to this email" |
| 72h | Final email: "if you still need help, reply here" (no times) |
| 96h | Lead Contacted → Lost, lost reason "No reply to follow-ups" |

A sequence stops when the customer books (Cal.com is asked before anything is
sent, so an unsynced booking counts), when the lead is no longer in
Contacted (booking synced, or staff moved it), or when a newer link is sent.
Each email is a note on the contact and lead. Emails use the booking-link
template (`BookingEmail`, kinds link / reminder / final) and the email guard.
Nothing runs automatically yet: `php artisan txn:follow-ups` sends what is
due, `--dry-run` shows it without sending or changing anything. SMS
follow-ups come with Twilio.

### TXN Settings (Settings > TXN Settings)

Values staff can change without a code change (`Support\Contact\TxnSettings`,
controller `Settings\TxnSettingsController`, migration
`2026_11_20_000100_create_txn_settings`):

| Screen | Settings |
| --- | --- |
| Company & sender | company name, rep name, rep cell, office phone, email accent colour, SMS signature |
| Services | service list (keywords → category → flow; first match wins), each service's confirmation line, the line for other services |
| Service area & scheduling | area centre (address looked up once, or lat/lon) and radius, out-of-area message (shown on out-of-area leads with a Copy button), slot times, times to offer, days ahead, minimum notice, visit length, most times per day, max extra drive, time zone (a change needs a ticked box: stored appointments keep their clock times); "Ranking & routes (advanced)": road times on/off, most points per road-times request, "near a job" minutes, extra drive counted as equal, road distance factor, average speed, routing server (OSRM), traffic factor outside rush hours, weekday rush hours (from / to / factor rows), drive back to start, days to reuse road routes. Company hours and holidays: Workers > Company schedule |
| Follow-ups | reminder / final / Lost hours (must go up), hours before an offered time, Lost reason |
| Emails | every part of the booking-link, reminder (with / without times) and final emails: per email subject, intro, closing; shared header, greeting, button label, plain-text link line, "button doesn't work" line, signature. Placeholders `{first_name}` `{service}` `{rep_name}` `{company}` `{rep_cell}` `{office_phone}` `{call}` `{timezone}`; `**bold**`; empty part = left out; live preview. Text message (SMS) sent with the booking link: one line, placeholders `{first_name}` `{signature}` `{company}` `{rep_name}` `{rep_cell}` `{service}` `{link}` (`{link}` required), preview with character and SMS-part count |

How it works: a saved value is stored in `txn_settings` under its config path
(e.g. `slot_finder.slot_times`) and put over the config at start-up and before
every queued job (`AppServiceProvider`), so the code keeps reading `config()`.
The config files and `.env` are the defaults; a value equal to its default is
not stored; a stored value that fails the checks is ignored. Every field has
"Reset to default"; each page has "Reset this page". Every change is logged in
`txn_settings_log` (area `settings:<screen>`) and shown on the page.
Permissions: `settings.txn.<screen>` (view) and `.edit` (save). APP_KEY and
Krayin's own database and Redis stay in `.env` on purpose (needed to start). Keys
and logins are on the Connections screen, the guards on Safety. The time zone name customers and staff read ("Central Time": emails, follow-ups, booking-link form) and the centre name in the area check ("... mi from Houston, TX") follow these settings (`TxnSettings::timezoneLabel()`, `areaCentreName()`).

### Lead stages and worker emails

The stages (migrations `2026_11_05_000100_add_txn_lead_stages`,
`2026_11_08_000100_create_roof_replacement_pipeline` and
`2026_11_18_000200_txn_pipeline_v2`), in board order:

| Stage | Code | Set by |
| --- | --- | --- |
| Raw Lead | `new` | import; lead check passed |
| Needs Review | `needs-review` | lead check found a problem |
| Waiting for Roof Report *(roof pipeline)* | `waiting-roof-report` | lead check: roof replacement |
| Roof Report Received *(roof pipeline)* | `roof-report-received` | staff, once the report is attached |
| Contacted | `contacted` | booking link sent |
| Appointment Scheduled | `appointment-set` | Cal.com booking or reschedule |
| Canceled | `appointment-canceled` | Cal.com cancellation |
| Visit Done | `prospect` | staff |
| Estimate Sent | `negotiation` | staff |
| Won - Job Sold | `won` | staff |
| Lost | `lost` | follow-ups ran out (`txn:follow-ups`), or staff |

**Stage rules (roles).** Since migration `2026_11_19_000100_create_txn_stage_roles`
the automation finds its stages by **role**, not by name or code
(`Support\Contact\StageRoles`, table `txn_stage_roles`). Roles: New lead, Needs
review, Waiting for roof report, Roof report received, Contacted, Appointment
scheduled, Canceled; Won and Lost stay Krayin's closing stages (by code). Admins
add, remove, rename and reorder stages in Settings > Pipelines and give roles in
**Settings > TXN Settings > Stage rules** (one stage per role per pipeline;
Waiting for roof report marks the roof pipeline). A stage without a role is
never touched by the automation. A role no stage holds makes that move skip,
and the screen warns about it (also about stages sharing a position, which
hides a board column). Every change is logged in `txn_settings_log`.
Permissions: `settings.txn.stage_rules` (view) and `.edit` (save).

Codes are the old ones (v2 only renamed stages). v2 removed Pre-Qualified and Krayin's
Follow Up (their leads → Raw Lead) and Appointment Rescheduled / Appointment
Confirmed (→ Appointment Scheduled), and moved open leads with the "Needs
review" tag to Needs Review. Every stage and every moved lead was saved first
(tables `txn_stage_snapshots` and `txn_stage_moves`), so rolling the migration
back restores the old stages and puts back each lead that hasn't moved since.

Roof replacement can't be booked before the roof report, so roof leads live
in their own **Roof Replacement** pipeline (same codes, plus the two roof
stages). On the Leads board switch pipelines with Krayin's pipeline selector.
A lead whose service is corrected away from roof replacement goes back to the
default pipeline's Raw Lead. The roof pipeline is found by its stage code, so
it can be renamed in Settings.

`Support\Contact\LeadStageService` moves the person's leads, never won or lost
ones (by stage code, within the lead's own pipeline unless noted):

| Event | Stage |
| --- | --- |
| Lead check found a problem | Needs Review, only from Raw Lead or Waiting for Roof Report |
| Lead check passed after a fix | Raw Lead, only from Needs Review |
| Lead check: roof replacement | Roof Replacement pipeline → Waiting for Roof Report, only from Raw Lead or Needs Review |
| Lead check: roof-pipeline lead whose service is no longer roof replacement | Default pipeline → Raw Lead |
| Booking link emailed (not when sending failed) | Contacted, only from Raw Lead, Needs Review, Roof Report Received or Canceled |
| Booking synced for the first time, or moved to another time | Appointment Scheduled |
| Booking cancelled | Canceled |
| Follow-ups ran out | Lost, only from Contacted |

Visit Done, Estimate Sent and Won are set by staff on the board (Cal.com
visits are read-only in Krayin). Each move is written to the lead's history
("Updated Stage : A → B", with the time), since Krayin logs stage changes made
in its UI but not ones made in code; the v2 migration's own moves are logged
in `txn_stage_moves` instead.

`Support\Contact\WorkerVisitNotifier` emails the workers (their roster email)
after the sync saves the change: booked → the assigned worker; moved → the
worker gets the new time and the old one, and when the new time is another
worker's, the old worker is told it is off their schedule and the new worker
gets "New visit"; cancelled → the assigned worker. A booking already cancelled
when first seen emails nobody. Emails go through Krayin's mail transport, so
the mail guard below applies (worker emails are `@test.invalid`, so they are
logged as not sent while testing). A failed email is logged and doesn't stop
the sync. Changing a visit's worker in Krayin sends no email.

Cal.com-sourced activities are read-only in Krayin except for worker
assignment. Changing the assigned worker is stored as a local override and is
preserved during booking sync; it does not change the Cal.com booking or email
the customer. These activities cannot be deleted or marked done from Krayin.
Customer rescheduling and cancellation remain in Cal.com. The visit's menu
(Person and Lead pages) has **Cancel booking**, which only opens a dialog saying
how: the customer's Cal.com confirmation email or Bookings in Cal.com, then
"Sync Cal.com bookings". Deleting, marking done or editing such a visit
(including bulk actions on the Activities list) is refused with the same
explanation (`ActivityController::CALCOM_CANCEL_MESSAGE`). The separate
internal-appointment cancellation workflow is not part of this change.

Krayin isn't reachable from the internet, so Cal.com webhooks can't reach it,
and the sync is manual by decision (no background job). Bookings come in when
someone clicks **Sync Cal.com** in the top bar (every page) or **Sync Cal.com
bookings** (Person page, Workers list, a worker's Schedule;
`Http\Controllers\Contact\CalcomSyncController`, route
`admin.contacts.calcom_sync`, needs the Persons "view" permission) or runs
`php artisan calcom:sync-bookings`. Until then a new, moved or cancelled booking
is not in Krayin: no visit, no stage change, no worker email. The buttons and
the command share a lock, so two syncs never run at once. Copy
`routes\console.php` to `/var/www/laravel-crm/routes/console.php`.

Run artisan in the container as `www-data`
(`docker exec -u www-data krayin-container php /var/www/laravel-crm/artisan ...`):
cache files and folders created by root can't be written by PHP-FPM, and the
button then fails with a 500 ("Permission denied" in `laravel.log`). Optional environment values:
`CALCOM_SYNC_LOOKBACK_DAYS` (default 30) and `CALCOM_ACTIVITY_USER_ID`, the
owner used when the person has no sales owner (default 1).

The Cal.com API key is read from the Laravel environment through
`config\calcom.php`; never put the key in this patch or browser code. Copy that
config file to `/var/www/laravel-crm/config/calcom.php`, then configure
`CALCOM_API_KEY` in the container (the time zone is set in Settings > TXN Settings > Service area & scheduling)
environment. Clear Laravel's config cache after changing environment values.
Customer link creation and email are disabled by default. For a one-contact
test, set `CALCOM_ALLOW_CUSTOMER_EMAIL=true`,
`CALCOM_TEST_PERSON_ID=<person-id>`, and
`CALCOM_TEST_RECIPIENT_EMAIL=<exact-stored-email>` in the Krayin environment.
The server checks all three and uses a cache-backed single-attempt guard.
Krayin's SMTP settings must also be capable of sending mail. If sending fails
after Cal.com creates the event, the link is shown on the Person page for
manual copying.
