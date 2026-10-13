<?php
/*
 * This file is part of the Booking Calendar plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The calendar feed: all-day stays, UTC slots, escaping and line folding.
 */

require __DIR__ . '/lib/bootstrap.php';

use mindstellar\booking\Ical;

$tz  = new DateTimeZone('Europe/London');
$ics = Ical::build('Cottage; sea view, garden', 'example.test', array(
    array('pk_i_id' => 1, 's_mode' => 'nights', 'dt_start' => '2026-10-19 23:00:00', 'dt_end' => '2026-10-22 23:00:00', 's_status' => 'confirmed', 's_name' => 'Ana', 'dt_updated' => ''),
    array('pk_i_id' => 2, 's_mode' => 'slots', 'dt_start' => '2026-10-14 08:00:00', 'dt_end' => '2026-10-14 09:00:00', 's_status' => 'pending', 's_name' => 'Bo', 'dt_updated' => ''),
), $tz, 1_800_000_000);
$lines = explode("\r\n", $ics);

check('lines end in CRLF', substr($ics, -2) === "\r\n" && strpos(str_replace("\r\n", '', $ics), "\n") === false);
pin('it starts as a calendar', 'BEGIN:VCALENDAR', $lines[0]);
check('the name is escaped', in_array('X-WR-CALNAME:Cottage\; sea view\, garden', $lines, true));
check('a stay is all-day, in local dates', in_array('DTSTART;VALUE=DATE:20261020', $lines, true) && in_array('DTEND;VALUE=DATE:20261023', $lines, true));
check('a slot is in UTC', in_array('DTSTART:20261014T080000Z', $lines, true));
check('a request is tentative', in_array('STATUS:TENTATIVE', $lines, true));
check('each event has a stable id', in_array('UID:booking-1@example.test', $lines, true));
pin('two events', 2, substr_count($ics, 'BEGIN:VEVENT'));

harness_section('folding');

$long   = 'SUMMARY:' . str_repeat('é', 60);
$folded = Ical::fold($long);
$ok     = true;
foreach (explode("\r\n", $folded) as $part) {
    $ok = $ok && strlen($part) <= 75 && mb_check_encoding($part, 'UTF-8');
}
check('no line is longer than 75 octets, and no character is split', $ok);
pin('unfolding gives the line back', $long, str_replace("\r\n ", '', $folded));
pin('newlines become \n', 'a\nb', Ical::text("a\r\nb"));

exit(harness_result());
