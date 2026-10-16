<?php
/*
 * This file is part of the Booking Calendar plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Reading other sites' calendars: which local dates each kind of event closes.
 */

require __DIR__ . '/lib/bootstrap.php';

use mindstellar\booking\IcalImport;

$london = new DateTimeZone('Europe/London');
$cal    = static fn (string ...$events): string => "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n"
    . implode('', array_map(static fn (string $e): string => "BEGIN:VEVENT\r\n" . $e . "\r\nEND:VEVENT\r\n", $events))
    . "END:VCALENDAR\r\n";
$parse = static fn (string $ics, string $from = '2026-01-01', string $to = '2027-12-31'): array => IcalImport::parse($ics, $london, $from, $to);

harness_section('all-day events');

pin('an all-day stay closes its nights, not the check-out day', array('2026-10-20', '2026-10-21', '2026-10-22'),
    $parse($cal("DTSTART;VALUE=DATE:20261020\r\nDTEND;VALUE=DATE:20261023\r\nSUMMARY:Reserved")));
pin('a date with no VALUE is all-day too', array('2026-10-20'), $parse($cal("DTSTART:20261020\r\nDTEND:20261021")));
pin('no end is one day', array('2026-10-20'), $parse($cal('DTSTART;VALUE=DATE:20261020')));
pin('an end before the start is one day', array('2026-10-20'), $parse($cal("DTSTART;VALUE=DATE:20261020\r\nDTEND;VALUE=DATE:20261019")));
pin('a duration is read', array('2026-10-20', '2026-10-21'), $parse($cal("DTSTART;VALUE=DATE:20261020\r\nDURATION:P2D")));

harness_section('timed events');

pin('a UTC time is read in the site time zone', array('2026-07-02'), $parse($cal("DTSTART:20260701T233000Z\r\nDTEND:20260702T013000Z")));
pin('a timed event closes each date it touches', array('2026-10-20', '2026-10-21'), $parse($cal("DTSTART:20261020T220000Z\r\nDTEND:20261021T030000Z")));
pin('an end exactly at midnight does not close the next day', array('2026-10-20'), $parse($cal("DTSTART:20261020T090000\r\nDTEND:20261021T000000")));
pin('a TZID is used when PHP knows it: 10:00 in Auckland is the evening before in London', array('2026-10-20'), $parse($cal("DTSTART;TZID=Pacific/Auckland:20261021T100000\r\nDTEND;TZID=Pacific/Auckland:20261021T110000")));
pin('a quoted TZID too', array('2026-10-20'), $parse($cal("DTSTART;TZID=\"America/New_York\":20261020T100000\r\nDTEND;TZID=\"America/New_York\":20261020T110000")));
pin('an unknown TZID falls back to the site', array('2026-10-21'), $parse($cal("DTSTART;TZID=Pacific Standard Time:20261021T000000\r\nDTEND;TZID=Pacific Standard Time:20261021T010000")));
pin('a floating time is the site\'s', array('2026-10-21'), $parse($cal("DTSTART:20261021T003000\r\nDTEND:20261021T013000")));
pin('no end is the start date', array('2026-10-21'), $parse($cal('DTSTART:20261021T100000Z')));

harness_section('what is skipped');

pin('a cancelled event', array(), $parse($cal("DTSTART;VALUE=DATE:20261020\r\nSTATUS:CANCELLED")));
pin('a free (transparent) event', array(), $parse($cal("DTSTART;VALUE=DATE:20261020\r\nTRANSP:TRANSPARENT")));
pin('a repeat rule is not expanded', array('2026-10-20'), $parse($cal("DTSTART;VALUE=DATE:20261020\r\nRRULE:FREQ=DAILY;COUNT=5")));
pin('an alarm\'s properties are not the event\'s', array('2026-10-20'), $parse($cal("DTSTART;VALUE=DATE:20261020\r\nBEGIN:VALARM\r\nTRIGGER:-PT15M\r\nSTATUS:CANCELLED\r\nEND:VALARM")));
pin('an event with no start', array(), $parse($cal('SUMMARY:Nothing')));
pin('junk', array(), $parse("<html>nope</html>\n\x00\xff"));
pin('a bad date', array(), $parse($cal('DTSTART;VALUE=DATE:20261341')));

harness_section('reading the file');

pin('folded lines are unfolded', array('2026-10-20'), $parse("BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nDTSTART;VALUE=DA\r\n TE:2026\r\n\t1020\r\nEND:VEVENT\r\nEND:VCALENDAR"));
pin('plain LF line ends work', array('2026-10-20'), $parse("BEGIN:VCALENDAR\nBEGIN:VEVENT\nDTSTART;VALUE=DATE:20261020\nEND:VEVENT\nEND:VCALENDAR\n"));
pin('several events are joined and sorted', array('2026-10-01', '2026-10-20'), $parse($cal('DTSTART;VALUE=DATE:20261020', 'DTSTART;VALUE=DATE:20261001', 'DTSTART;VALUE=DATE:20261020')));
pin('dates are kept inside the window', array('2026-10-02', '2026-10-03'), $parse($cal("DTSTART;VALUE=DATE:20260901\r\nDTEND;VALUE=DATE:20261101"), '2026-10-02', '2026-10-03'));
pin('an event wholly outside is dropped', array(), $parse($cal('DTSTART;VALUE=DATE:20251020'), '2026-10-02', '2026-10-03'));
pin('one calendar closes at most ' . IcalImport::MAX_DATES . ' dates', IcalImport::MAX_DATES, count(IcalImport::parse($cal("DTSTART;VALUE=DATE:20200101\r\nDTEND;VALUE=DATE:20300101"), $london, '2020-01-01', '2029-12-31')));

exit(harness_result());
