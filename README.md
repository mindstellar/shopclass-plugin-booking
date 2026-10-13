# Booking Calendar

Let buyers book a Shopclass listing for dates or a time slot: holiday rentals, rooms,
equipment, appointments and other services. Buyers see what is free on the listing page,
send a request, and both sides get e-mails as it is accepted, declined or cancelled.

## Requirements

- Shopclass 7.0 or later
- PHP 8.0 or later
- Nothing else: it works on plain shared hosting.

E-mails and the expiry of old requests run on core's job queue, so a request never waits on
the mail server. With only core's auto-cron, e-mails go out within minutes as visitors load
pages. With a real cron line or the job worker (`php oc-cli.php jobs:work`), they go out at once.

## Setup

1. Install and activate the plugin, then open **Plugins → Booking Calendar**.
2. Pick a mode for each category that takes bookings:
   - **Nights**: buyers pick a check-in and a check-out date.
   - **Time slots**: buyers pick one fixed-length time inside the seller's weekly hours.

   A subcategory follows its parent unless you set it.
3. Sellers open **My account → Bookings**, choose a listing and tick *Take bookings for this
   listing*. There they set the minimum stay or the slot length and weekly hours, a price
   note, closed dates, and whether requests are confirmed at once.

Listings in other categories show nothing, and pages without a calendar load nothing.

## How a booking goes

- A signed-in buyer's request is stored at once as *waiting for reply*, or *confirmed* when
  the seller chose auto-confirm.
- A guest gets an e-mail with a link. The request is kept on the server and the link carries
  only a random id; the booking is made when they open it and press the button, so the seller
  never sees unconfirmed addresses. Guests are limited per IP address per hour (per /64
  network for IPv6), and can be switched off.
- A signed-in buyer always books with their account's e-mail address. Accounts are limited
  per hour (site and API together), and in how many requests they may have waiting, per
  listing and in all.
- The seller accepts or declines from their account. Either side can cancel; the buyer's
  e-mails carry a cancel link.
- Requests nobody answers expire after the hours set in the settings, checked hourly by cron.
- Two requests for the same time cannot both succeed: the overlap check runs inside a
  transaction that locks the listing, and the database refuses two live bookings that start
  at the same moment.

Times are stored in UTC and shown in the site's time zone. Slots follow the clock on the
wall, so a day with a clock change has one slot more or less.

## Deactivate or uninstall

Deactivating the plugin hides the calendars and keeps every booking. **Uninstalling deletes
all bookings, closed dates, listing settings and the plugin's settings**, and cannot be undone.

## Calendar feed

Each listing's settings show a private `.ics` address. Add it to Google, Apple or Outlook
calendar to see the bookings there. Events are named by booking number and status; no
buyer details are in the feed. Ticking *Make a new address* stops the old one.

## Personal data

A member's data export lists the booking requests they sent, from their account or as a
guest with its address. When the account is deleted, those bookings lose the buyer's name,
address, phone and message, and any still holding dates are cancelled. A deleted listing
takes its bookings with it.

## Admin

**Plugins → Bookings** lists every booking, filtered by status.

## For theme authors

The listing page calendar is printed on the `item_detail` hook, which the bundled themes
fire. Its markup uses classes starting with `booking-` (`.booking`, `.booking-day`,
`.booking-day--free`, `.booking-slot`, `.booking-form` and so on) and its colours come from
custom properties on `.booking` (`--booking-free`, `--booking-booked`, `--booking-pick`).
The account pages use core's `oe-*` classes.

## REST API

With the API on, two endpoints are added:

- `GET /api/v1/ext/booking/listings/{id}/availability?from=YYYY-MM-DD`: one month of the
  calendar. It needs an API key, but any key or token will do, including a public
  (read-only) key.
- `POST /api/v1/ext/booking/listings/{id}/requests`: a booking request for the signed-in
  user, with `start` (a date for nights, `YYYY-MM-DDTHH:MM` local time for a slot), `end`
  (check-out date, nights only), and optional `name`, `phone`, `note`. The account's own
  e-mail address is used. Needs the `ext:booking:write` scope.

## Translations

Copy `languages/booking.pot` to `languages/<locale>/messages.po`, translate it, and compile
it to `messages.mo` in the same folder. After changing strings, run `php tools/make-pot.php`.

## Tests

```bash
./tests/run.sh                         # unit tests, no database
php tests/integration/flow.php         # core's scratch MySQL on port 33061
```

Both load core from `SHOPCLASS_CORE`, or a sibling checkout named `osclass`.

## License

GPL-3.0-or-later. See [LICENSE](LICENSE).
