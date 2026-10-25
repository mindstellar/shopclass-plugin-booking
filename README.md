# Booking Calendar

Lets buyers book a Shopclass listing for dates or a time slot: holiday rentals, rooms, equipment, appointments and other services. Buyers see what is free, send a request, and both sides get e-mails when it is accepted, declined or cancelled.

## Requirements

- Shopclass 7.0.0 or later
- PHP 8.0 or later
- No outside accounts. It works on plain shared hosting.

## Install

1. Open Plugins → Browse (or upload the zip from the GitHub release).
2. Install.
3. Activate.

## Set up

1. Open Plugins → Booking Calendar → Settings.
2. Under **Categories**, set **Booking by category** for each category that takes bookings:
   - **Nights**: buyers pick a check-in and a check-out date.
   - **Time slots**: buyers pick one fixed-length time inside the seller's weekly hours.
   - A subcategory follows its parent unless you set it.
3. Under **Defaults and limits**, set **Slot length (minutes)**, **Minimum nights**, **Book ahead (days)** and **Request expiry (hours)**. Tick **Let sellers import calendars** to allow imports (off by default).
4. Under **Limits per buyer**, set **Requests per hour**, **Open per listing** and **Open in total**. 0 means no limit for the hourly field.
5. Under **Guests**, tick **Allow guest requests** and set **Guest requests per hour**.
6. Sellers open My account → Bookings, choose a listing and tick **Take bookings for this listing**. They set the minimum stay or slot length, weekly hours, a price note, closed dates and auto-confirm.

Listings in other categories show nothing. Pages without a calendar load nothing.

## How it works

- A signed-in buyer's request is stored as waiting for reply, or confirmed if the seller chose auto-confirm. Buyers always book with their account e-mail.
- A guest gets an e-mail with a link. The booking is made when they open it and press the button, so sellers never see unconfirmed addresses.
- The seller accepts or declines from their account. Either side can cancel. Buyer e-mails carry a cancel link.
- Unanswered requests expire after the set hours. Cron checks hourly.
- Two requests for the same time cannot both succeed. The overlap check runs in a transaction that locks the listing, and the database refuses two live bookings with the same start.
- Plugins → Booking Calendar → Bookings lists every booking, filtered by status.
- E-mails and expiry run on core's job queue. With auto-cron, e-mails go out within minutes. With a cron line or `php oc-cli.php jobs:work`, they go out at once.

### Calendar feed

Each listing's settings show a private `.ics` address for Google, Apple or Outlook calendar. Events show the booking number and status, with no buyer details. Ticking the new-address box stops the old address.

### Import other calendars

A seller can paste up to 3 `.ics` addresses (Airbnb, Booking.com, Google Calendar) in a listing's settings. Cron reads them every hour and closes the busy dates here.

- A busy all-day event closes its nights. The check-out day stays free.
- A timed event closes each day it touches.
- Cancelled and free events are skipped. A repeating event counts only its first time.
- Imported dates are kept apart from the seller's own. Clearing the addresses opens them again.
- If a calendar cannot be read, earlier imported dates stay and the seller sees why.
- Only public `https://` (or `webcal://`) addresses are read. Not this site, private networks or plain HTTP.
- Files over 2 MB are refused. Only the first 2000 events are read.
- Listings are read 10 at a time, oldest first. A run stops starting new ones after 15 seconds.
- Addresses are shown only to the seller and never logged.
- With the setting off, the box is hidden, nothing is read, and imported dates are ignored.

### Personal data

- A member's data export lists the requests they sent.
- When an account is deleted, its bookings lose the buyer's name, address, phone and message, and bookings still holding dates are cancelled.
- A deleted listing takes its bookings with it.

## Notes / limits

- Times are stored in UTC and shown in the site's time zone. On a clock-change day a slot day has one slot more or less.
- Guest requests are limited per IP address per hour (per /64 for IPv6).
- Deactivating hides the calendars and keeps every booking.
- Uninstalling deletes all bookings, closed dates, listing settings and plugin settings. It cannot be undone.

## For developers

### Theme styling

The listing calendar prints on the `item_detail` hook. Its classes start with `booking-` (`.booking`, `.booking-day`, `.booking-day--free`, `.booking-slot`, `.booking-form`). Account pages use core's `oe-*` classes.

Colours are custom properties on `.booking`:

| Property | What it colours |
|---|---|
| `--booking-pick` | Chosen days and slots, the submit button, focus rings |
| `--booking-pick-text` | Text on `--booking-pick` |
| `--booking-free` | Free days and slots |
| `--booking-booked` | Booked days |
| `--booking-muted` | Hover on the month arrows |
| `--booking-line` | Borders |
| `--booking-soft` | Hints, legend, closed days |
| `--booking-ok` | The success message |
| `--booking-error` | The error message |
| `--booking-radius` | Corner radius of the card |

Most are mixed from `--booking-pick`, so one is often enough:

```css
.booking { --booking-pick: #b45309; }
```

Most rules are `.booking .booking-x`, so a theme rule needs the same two classes. Button text colours use `!important`, so override them the same way.

To replace the stylesheet, remove it after the plugin adds it on `show_item`:

```php
osc_add_hook('show_item', function () { osc_remove_style('booking-calendar'); }, 10);
```

### REST API

- `GET /api/v1/ext/booking/listings/{id}/availability?from=YYYY-MM-DD` returns one month. Any API key or token works, including a read-only key.
- `POST /api/v1/ext/booking/listings/{id}/requests` makes a request for the signed-in user. Needs the `ext:booking:write` scope. Fields: `start` (a date for nights, `YYYY-MM-DDTHH:MM` local time for a slot), `end` (check-out date, nights only), and optional `name`, `phone`, `note`. The account's e-mail is used.

### Translations

Copy `languages/booking.pot` to `languages/<locale>/messages.po`, translate it, and compile it to `messages.mo` in the same folder. After changing strings, run `php tools/make-pot.php`.

### Tests

```bash
./tests/run.sh                         # unit tests, no database
php tests/integration/flow.php         # core's scratch MySQL on port 33061
```

Both load core from `SHOPCLASS_CORE`, or a sibling checkout named `osclass`.

## Support

https://github.com/mindstellar/shopclass-plugin-booking/issues

## License

GPL-3.0-or-later. See [LICENSE](LICENSE).
