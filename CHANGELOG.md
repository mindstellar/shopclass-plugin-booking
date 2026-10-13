# Changelog

## 0.1.0

### New
- Book a listing for nights (check-in and check-out) or a time slot, chosen per category.
- Calendar on the listing page, keyboard and screen-reader friendly, with no jQuery or library.
- Sellers turn bookings on per listing and set minimum nights, slot length, weekly hours, closed dates and auto-confirm.
- Sellers accept, decline or cancel from their account; buyers cancel from their account or the e-mail.
- Guests confirm their request from an e-mailed link that carries no personal details; guest requests are rate-limited.
- Signed-in and API requests are rate-limited per account, capped in how many may wait, and use the account's e-mail address.
- E-mails to buyer and seller go through the job queue.
- Unanswered requests expire after a set number of hours.
- No double bookings: overlap check under a row lock, and a unique key on each live booking's start.
- Private iCal feed per listing, with no buyer details.
- Bookings are in the member's data export, and are made anonymous when the account is deleted.
- Admin list of all bookings by status.
- REST API: read a listing's calendar, send a booking request.
