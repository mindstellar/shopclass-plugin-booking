<?php
/*
 * This file is part of the Booking Calendar plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Calendar maths: overlaps, nights, slots from weekly hours, time zones and clock changes.
 */

require __DIR__ . '/lib/bootstrap.php';

use mindstellar\booking\Availability as A;

$london   = new DateTimeZone('Europe/London');
$auckland = new DateTimeZone('Pacific/Auckland');
$utc      = new DateTimeZone('UTC');
$at       = static fn (string $iso): int => (new DateTimeImmutable($iso))->getTimestamp();
$labels   = static fn (array $slots, DateTimeZone $tz): array => array_map(static fn (array $s): string => (new DateTimeImmutable('@' . $s[0]))->setTimezone($tz)->format('H:i'), $slots);

harness_section('overlap');

check('ranges that share time overlap', A::overlaps(10, 20, 15, 25));
check('a range ending as the next starts does not', !A::overlaps(10, 20, 20, 30));
check('one inside another overlaps', A::overlaps(10, 40, 20, 30));
check('separate ranges do not', !A::overlaps(10, 20, 30, 40));

harness_section('weekly hours');

$hours = A::parseHours(array(3 => '09:00-12:00, 13:00-15:00', 5 => '18:00-24:00', 6 => 'closed', 9 => '09:00-10:00'));
pin('ranges are read per weekday', array(3 => array(array(540, 720), array(780, 900)), 5 => array(array(1080, 1440))), $hours);
pin('an overlapping range is dropped', array(1 => array(array(540, 720))), A::parseHours(array(1 => '09:00-12:00, 11:00-13:00')));
pin('a range that closes before it opens is dropped', array(), A::parseHours(array(2 => '17:00-09:00')));
pin('stored JSON reads back', $hours, A::parseHours((string) json_encode($hours)));
pin('ranges print as typed', '09:00-12:00, 13:00-15:00', A::formatRanges($hours[3]));
pin('bad minutes are refused', null, A::parseRange('09:75-10:00'));

harness_section('slots on an ordinary day');

$slots = A::slotsForDay('2026-10-14', $hours, 60, $london);
pin('back to back inside each range', array('09:00', '10:00', '11:00', '13:00', '14:00'), $labels($slots, $london));
pin('each slot is an hour long', 3600, $slots[0][1] - $slots[0][0]);
pin('a slot that would run past closing is left out', 1, count(A::slotsForDay('2026-10-14', array(3 => array(array(540, 630))), 60, $london)));
pin('a closed weekday has none', array(), A::slotsForDay('2026-10-13', $hours, 60, $london));
pin('a range to midnight ends at the next day', array('18:00', '19:30', '21:00', '22:30'), $labels(A::slotsForDay('2026-10-16', $hours, 90, $london), $london));

harness_section('clock changes (Europe/London)');

$night = array(7 => array(array(0, 240)));
$spring = A::slotsForDay('2026-03-29', $night, 60, $london);
pin('spring forward: three real hours, three slots', array('00:00', '02:00', '03:00'), $labels($spring, $london));
pin('spring forward: they are an hour apart in real time', 3600, $spring[1][0] - $spring[0][0]);
$autumn = A::slotsForDay('2026-10-25', $night, 60, $london);
pin('fall back: five real hours, five slots', array('00:00', '01:00', '01:00', '02:00', '03:00'), $labels($autumn, $london));
pin('fall back: the two 01:00 slots are different times', 3600, $autumn[2][0] - $autumn[1][0]);
pin('a local time skipped by the clock is not a time', null, A::parseLocal('2026-03-29T01:30', $london));
pin('a real local time reads back', $at('2026-10-14T09:00:00+01:00'), A::parseLocal('2026-10-14T09:00', $london));
pin('nights across the autumn change still count as days', 2, A::daysBetween('2026-10-24', '2026-10-26'));
pin('that stay is 49 hours long', 49 * 3600, A::dayStart('2026-10-26', $london) - A::dayStart('2026-10-24', $london));

harness_section('time zones');

$late = A::slotsForDay('2026-10-14', array(3 => array(array(0, 120))), 30, $auckland);
pin('Auckland 00:00 is the day before in UTC', '2026-10-13', A::localDate($late[0][0], $utc));
pin('but the slot belongs to its local day', '2026-10-14', A::localDate($late[0][0], $auckland));
$days = A::slotStates('2026-10-14', '2026-10-15', array(3 => array(array(0, 120))), 30, array(), array(), $auckland, $at('2026-10-13T00:00:00Z'), 30);
pin('the calendar shows it on the local day', array('00:00', '00:30', '01:00', '01:30'), array_column($days['2026-10-14']['slots'], 0));
pin('today is the local date, not the UTC one', '2026-10-14', A::window($at('2026-10-13T12:00:00Z'), 5, $auckland)[0]);

harness_section('nights calendar');

$now   = $at('2026-10-13T12:00:00Z');
$holds = array(array(A::dayStart('2026-10-20', $london), A::dayStart('2026-10-23', $london)));
$states = A::nightStates('2026-10-12', '2026-10-26', $holds, array('2026-10-18'), $london, $now, 10);
pin('yesterday is past', A::PAST, $states['2026-10-12']);
pin('today is free', A::FREE, $states['2026-10-13']);
pin('a closed date is blocked', A::BLOCKED, $states['2026-10-18']);
pin('the first booked night', A::BOOKED, $states['2026-10-20']);
pin('the last booked night', A::BOOKED, $states['2026-10-22']);
pin('the check-out day is free again', A::FREE, $states['2026-10-23']);
pin('past the booking window', A::BEYOND, $states['2026-10-24']);

harness_section('checking a stay');

$check = static fn (string $in, string $out, int $min = 1, array $blocked = array()) => A::checkNights($in, $out, $min, $holds, $blocked, $london, $now, 10);
pin('a free stay ending on a check-in day', null, $check('2026-10-17', '2026-10-20'));
pin('a stay starting on a check-out day', null, $check('2026-10-23', '2026-10-24'));
pin('a stay over a booked night', 'taken', $check('2026-10-19', '2026-10-21'));
pin('a stay around a booking', 'taken', $check('2026-10-14', '2026-10-24'));
pin('too short', 'min_nights', $check('2026-10-14', '2026-10-16', 3));
pin('a closed night', 'blocked', $check('2026-10-17', '2026-10-19', 1, array('2026-10-18')));
pin('leaving on a closed date is fine', null, $check('2026-10-16', '2026-10-18', 1, array('2026-10-18')));
pin('in the past', 'past', $check('2026-10-12', '2026-10-14'));
pin('the last night may be the last bookable date', null, $check('2026-10-23', '2026-10-24'));
pin('a night after it may not', 'beyond', $check('2026-10-23', '2026-10-25'));
pin('check-out before check-in', 'dates', $check('2026-10-15', '2026-10-14'));
pin('not a date', 'dates', $check('2026-02-30', '2026-03-02'));
pin('too long for one request', 'max_nights', A::checkNights('2026-10-14', A::addDays('2026-10-14', A::MAX_NIGHTS + 1), 1, array(), array(), $london, $now, 400));

harness_section('checking a slot');

$slot = static fn (string $local, array $holds = array(), ?int $at = null) => A::checkSlot((int) A::parseLocal($local, $london), $hours, 60, $holds, array(), $london, $at ?? $now, 30);
pin('a slot on the grid', null, $slot('2026-10-14T10:00'));
pin('a time off the grid', 'slot', $slot('2026-10-14T10:30'));
pin('a time outside the hours', 'slot', $slot('2026-10-14T16:00'));
pin('a slot already held', 'taken', $slot('2026-10-14T10:00', array(array(A::parseLocal('2026-10-14T10:00', $london), A::parseLocal('2026-10-14T11:00', $london)))));
pin('a slot that has started', 'past', $slot('2026-10-14T10:00', array(), $at('2026-10-14T09:30:00Z')));
pin('a slot past the window', 'beyond', $slot('2026-12-02T10:00'));
pin('a slot on a closed date', 'blocked', A::checkSlot((int) A::parseLocal('2026-10-14T10:00', $london), $hours, 60, array(), array('2026-10-14'), $london, $now, 30));

$day = A::slotStates('2026-10-14', '2026-10-15', $hours, 60, array(array(A::parseLocal('2026-10-14T09:00', $london), A::parseLocal('2026-10-14T10:00', $london))), array(), $london, $now, 30)['2026-10-14'];
pin('a held slot shows as booked', A::BOOKED, $day['slots'][0][1]);
pin('the day still has free ones', A::FREE, $day['state']);
$full = A::slotStates('2026-10-14', '2026-10-15', array(3 => array(array(540, 600))), 60, array(array(A::parseLocal('2026-10-14T09:00', $london), A::parseLocal('2026-10-14T10:00', $london))), array(), $london, $now, 30)['2026-10-14'];
pin('a day with every slot held is full', A::FULL, $full['state']);
pin('a weekday with no hours is closed', A::CLOSED, A::slotStates('2026-10-13', '2026-10-14', $hours, 60, array(), array(), $london, $at('2026-10-12T00:00:00Z'), 30)['2026-10-13']['state']);

harness_section('closed dates');

$blocked = A::parseBlocked("2026-12-24..2026-12-26\n2026-12-31, nonsense 2027-01-01", '2026-01-01', '2027-12-31');
pin('ranges and single dates are read', array('2026-12-24', '2026-12-25', '2026-12-26', '2026-12-31', '2027-01-01'), $blocked);
pin('they print back as ranges', "2026-12-24..2026-12-26\n2026-12-31..2027-01-01", A::formatBlocked($blocked));
pin('a reversed range is ignored', array(), A::parseBlocked('2026-12-26..2026-12-24', '2026-01-01', '2027-12-31'));
pin('dates before today and after the window are dropped', array('2026-10-13', '2026-10-14'), A::parseBlocked('2020-01-01..2099-12-31', '2026-10-13', '2026-10-14'));
pin('only the first entries are read', 2, count(A::parseBlocked(implode(',', A::dates('2026-11-01', '2026-11-11')), '2026-01-01', '2027-12-31', 2)));

harness_section('months');

pin('the first of the month', '2026-10-01', A::monthStart('2026-10-14'));
pin('the next month', '2027-01-01', A::nextMonth('2026-12-31'));
pin('a month far ahead is pulled back to the last open month', '2027-04-01', A::clampMonth('2099-05-17', '2026-10-13', '2027-04-11'));
pin('a past month is pulled up to this one', '2026-10-01', A::clampMonth('1999-05-17', '2026-10-13', '2027-04-11'));
pin('a month inside the window stays', '2026-12-01', A::clampMonth('2026-12-30', '2026-10-13', '2027-04-11'));
pin('junk is this month', '2026-10-01', A::clampMonth('junk', '2026-10-13', '2027-04-11'));
pin('February in a leap year has 29 dates', 29, count(A::dates('2028-02-01', A::nextMonth('2028-02-01'))));

exit(harness_result());
