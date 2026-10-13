# Booking Calendar

Let buyers book a Shopclass listing for dates or a time slot: holiday rentals, rooms,
equipment, appointments and other services. Buyers see what is free on the listing page,
send a request, and both sides get e-mails as it is accepted, declined or cancelled.

## Requirements

- Shopclass 7.0 or later
- PHP 8.0 or later
- Cron or core's auto-cron, so queued e-mails go out and old requests expire. A Valkey or
  Redis cache and the job worker make both faster, but are not needed.

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
- A guest gets an e-mail with a link. The request is made only when they open it and press
  the button, so the seller never sees unconfirmed addresses. Guests are limited per address
  per hour, and can be switched off.
- The seller accepts or declines from their account. Either side can cancel; the buyer's
  e-mails carry a cancel link.
- Requests nobody answers expire after the hours set in the settings, checked hourly by cron.
- Two requests for the same time cannot both succeed: the overlap check runs inside a
  transaction that locks the listing, and the database refuses two live bookings that start
  at the same moment.

Times are stored in UTC and shown in the site's time zone. Slots follow the clock on the
wall, so a day with a clock change has one slot more or less.

## Calendar feed

Each listing's settings show a private `.ics` address. Add it to Google, Apple or Outlook
calendar to see the bookings there. Ticking *Make a new address* stops the old one.

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
  calendar. Any key.
- `POST /api/v1/ext/booking/listings/{id}/requests`: a booking request for the signed-in
  user, with `start` (a date for nights, `YYYY-MM-DDTHH:MM` local time for a slot), `end`
  (check-out date, nights only), and optional `name`, `email`, `phone`, `note`. Needs the
  `ext:booking:write` scope.

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
