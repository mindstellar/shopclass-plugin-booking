<?php

/*
 * This file is part of the Booking Calendar plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace mindstellar\booking;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Calendar maths with no database: nights, slots from weekly hours, and what is free.
 * Every booking is a [start, end) pair of Unix times; dates are local to the site's time zone.
 */
final class Availability
{
    public const NIGHTS = 'nights';
    public const SLOTS  = 'slots';

    /** Day states the calendar shows. */
    public const FREE    = 'free';
    public const BOOKED  = 'booked';
    public const BLOCKED = 'blocked';
    public const PAST    = 'past';
    public const BEYOND  = 'beyond';
    public const CLOSED  = 'closed';
    public const FULL    = 'full';

    /** The longest stay one request may ask for. */
    public const MAX_NIGHTS = 90;

    /**
     * Whether two [start, end) ranges share any moment.
     */
    public static function overlaps(int $aStart, int $aEnd, int $bStart, int $bEnd): bool
    {
        return $aStart < $bEnd && $bStart < $aEnd;
    }

    /**
     * Whether $date is a real `Y-m-d` date.
     */
    public static function validDate(string $date): bool
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));

        return $d !== false && $d->format('Y-m-d') === $date;
    }

    /**
     * The Unix time local midnight starts on $date.
     */
    public static function dayStart(string $date, DateTimeZone $tz): int
    {
        return (new DateTimeImmutable($date . ' 00:00:00', $tz))->getTimestamp();
    }

    /**
     * The local date a Unix time falls on.
     */
    public static function localDate(int $time, DateTimeZone $tz): string
    {
        return (new DateTimeImmutable('@' . $time))->setTimezone($tz)->format('Y-m-d');
    }

    /**
     * The local wall time of a Unix time, as `Y-m-d\TH:i`.
     */
    public static function localDateTime(int $time, DateTimeZone $tz): string
    {
        return (new DateTimeImmutable('@' . $time))->setTimezone($tz)->format('Y-m-d\TH:i');
    }

    /**
     * $date moved by $days calendar days.
     */
    public static function addDays(string $date, int $days): string
    {
        $d = new DateTimeImmutable($date, new DateTimeZone('UTC'));

        return $d->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
    }

    /**
     * Calendar days from $from to $to; negative when $to is earlier.
     */
    public static function daysBetween(string $from, string $to): int
    {
        $utc = new DateTimeZone('UTC');

        return (int) round(((new DateTimeImmutable($to, $utc))->getTimestamp() - (new DateTimeImmutable($from, $utc))->getTimestamp()) / 86400);
    }

    /**
     * Every date from $from up to, not including, $to.
     *
     * @return string[]
     */
    public static function dates(string $from, string $to): array
    {
        $out = array();
        for ($d = $from; $d < $to; $d = self::addDays($d, 1)) {
            $out[] = $d;
        }

        return $out;
    }

    /**
     * Read weekly hours from what a seller typed or from stored JSON: ISO weekday (1 = Monday)
     * to a sorted list of [open, close] minutes after midnight. Bad or overlapping ranges are dropped.
     *
     * @param array<int|string,mixed>|string $spec
     *
     * @return array<int,array<int,array{0:int,1:int}>>
     */
    public static function parseHours($spec): array
    {
        if (is_string($spec)) {
            $decoded = json_decode($spec, true);
            $spec    = is_array($decoded) ? $decoded : array();
        }
        $hours = array();
        foreach ($spec as $day => $ranges) {
            $day = (int) $day;
            if ($day < 1 || $day > 7) {
                continue;
            }
            if (is_string($ranges)) {
                $ranges = preg_split('/\s*,\s*/', trim($ranges), -1, PREG_SPLIT_NO_EMPTY) ?: array();
            }
            $clean = array();
            foreach ((array) $ranges as $range) {
                $pair = is_array($range) ? $range : self::parseRange((string) $range);
                if ($pair === null || count($pair) !== 2) {
                    continue;
                }
                [$open, $close] = array_map('intval', array_values($pair));
                if ($open < 0 || $close > 1440 || $open >= $close) {
                    continue;
                }
                $clean[] = array($open, $close);
            }
            usort($clean, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
            $kept = array();
            foreach ($clean as $pair) {
                if ($kept !== array() && $pair[0] < $kept[count($kept) - 1][1]) {
                    continue;
                }
                $kept[] = $pair;
            }
            if ($kept !== array()) {
                $hours[$day] = $kept;
            }
        }
        ksort($hours);

        return $hours;
    }

    /**
     * "09:00-17:00" as [540, 1020], or null when it is not a range. "24:00" closes a day.
     *
     * @return array{0:int,1:int}|null
     */
    public static function parseRange(string $range): ?array
    {
        if (!preg_match('/^\s*(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})\s*$/', $range, $m)) {
            return null;
        }
        $open  = (int) $m[1] * 60 + (int) $m[2];
        $close = (int) $m[3] * 60 + (int) $m[4];
        if ((int) $m[2] > 59 || (int) $m[4] > 59 || $open > 1440 || $close > 1440) {
            return null;
        }

        return array($open, $close);
    }

    /**
     * One day's ranges back as the seller typed them: "09:00-12:00, 13:00-17:00".
     *
     * @param array<int,array{0:int,1:int}> $ranges
     */
    public static function formatRanges(array $ranges): string
    {
        $out = array();
        foreach ($ranges as $pair) {
            $out[] = sprintf('%02d:%02d-%02d:%02d', intdiv($pair[0], 60), $pair[0] % 60, intdiv($pair[1], 60), $pair[1] % 60);
        }

        return implode(', ', $out);
    }

    /**
     * The slots of one local day, as [start, end] Unix times. Slots run back to back from each
     * opening time and must end by its closing time, measured in real minutes, so a day with a
     * clock change has one slot more or less. A local time that does not exist starts from the next one.
     *
     * @param array<int,array<int,array{0:int,1:int}>> $hours
     *
     * @return array<int,array{0:int,1:int}>
     */
    public static function slotsForDay(string $date, array $hours, int $minutes, DateTimeZone $tz): array
    {
        if ($minutes < 5) {
            return array();
        }
        $weekday = (int) (new DateTimeImmutable($date, new DateTimeZone('UTC')))->format('N');
        $slots   = array();
        foreach ($hours[$weekday] ?? array() as [$open, $close]) {
            $from = self::wallTime($date, $open, $tz);
            $to   = self::wallTime($date, $close, $tz);
            for ($start = $from; $start + $minutes * 60 <= $to; $start += $minutes * 60) {
                $slots[] = array($start, $start + $minutes * 60);
            }
        }

        return $slots;
    }

    /**
     * The Unix time of $minute minutes after the start of local $date, by the clock on the wall.
     */
    private static function wallTime(string $date, int $minute, DateTimeZone $tz): int
    {
        if ($minute >= 1440) {
            return self::dayStart(self::addDays($date, 1), $tz);
        }

        return (new DateTimeImmutable(sprintf('%s %02d:%02d:00', $date, intdiv($minute, 60), $minute % 60), $tz))->getTimestamp();
    }

    /**
     * The first and last dates a booking may touch: today and today plus $maxDays.
     *
     * @return array{0:string,1:string}
     */
    public static function window(int $now, int $maxDays, DateTimeZone $tz): array
    {
        $today = self::localDate($now, $tz);

        return array($today, self::addDays($today, max(0, $maxDays)));
    }

    /**
     * The state of each night from $from up to $to. A night is the one starting on that date.
     *
     * @param array<int,array{0:int,1:int}> $holds   Bookings that hold time
     * @param array<int,string>             $blocked Dates the seller closed
     *
     * @return array<string,string> date => state
     */
    public static function nightStates(string $from, string $to, array $holds, array $blocked, DateTimeZone $tz, int $now, int $maxDays): array
    {
        [$first, $last] = self::window($now, $maxDays, $tz);
        $blocked        = array_flip($blocked);
        $states         = array();
        foreach (self::dates($from, $to) as $date) {
            if ($date < $first) {
                $states[$date] = self::PAST;
            } elseif ($date > $last) {
                $states[$date] = self::BEYOND;
            } elseif (isset($blocked[$date])) {
                $states[$date] = self::BLOCKED;
            } else {
                $start         = self::dayStart($date, $tz);
                $end           = self::dayStart(self::addDays($date, 1), $tz);
                $states[$date] = self::anyOverlap($start, $end, $holds) ? self::BOOKED : self::FREE;
            }
        }

        return $states;
    }

    /**
     * Each day's slots from $from up to $to, and the day's state.
     *
     * @param array<int,array<int,array{0:int,1:int}>> $hours
     * @param array<int,array{0:int,1:int}>            $holds
     * @param array<int,string>                        $blocked
     *
     * @return array<string,array{state:string,slots:array<int,array{0:string,1:string,2:int}>}>
     *         date => state and slots as [local "H:i", free|booked|past, Unix start]
     */
    public static function slotStates(string $from, string $to, array $hours, int $minutes, array $holds, array $blocked, DateTimeZone $tz, int $now, int $maxDays): array
    {
        [$first, $last] = self::window($now, $maxDays, $tz);
        $blocked        = array_flip($blocked);
        $days           = array();
        foreach (self::dates($from, $to) as $date) {
            if ($date < $first) {
                $days[$date] = array('state' => self::PAST, 'slots' => array());
                continue;
            }
            if ($date > $last) {
                $days[$date] = array('state' => self::BEYOND, 'slots' => array());
                continue;
            }
            if (isset($blocked[$date])) {
                $days[$date] = array('state' => self::BLOCKED, 'slots' => array());
                continue;
            }
            $slots = array();
            $free  = 0;
            foreach (self::slotsForDay($date, $hours, $minutes, $tz) as [$start, $end]) {
                $state = $start <= $now ? self::PAST : (self::anyOverlap($start, $end, $holds) ? self::BOOKED : self::FREE);
                $free += $state === self::FREE ? 1 : 0;
                $slots[] = array((new DateTimeImmutable('@' . $start))->setTimezone($tz)->format('H:i'), $state, $start);
            }
            $state       = $slots === array() ? self::CLOSED : ($free > 0 ? self::FREE : self::FULL);
            $days[$date] = array('state' => $state, 'slots' => $slots);
        }

        return $days;
    }

    /**
     * Why a stay from $checkIn to $checkOut cannot be booked, or null when it can.
     *
     * @param array<int,array{0:int,1:int}> $holds
     * @param array<int,string>             $blocked
     *
     * @return string|null one of 'dates', 'past', 'beyond', 'min_nights', 'max_nights', 'blocked', 'taken'
     */
    public static function checkNights(string $checkIn, string $checkOut, int $minNights, array $holds, array $blocked, DateTimeZone $tz, int $now, int $maxDays): ?string
    {
        if (!self::validDate($checkIn) || !self::validDate($checkOut) || $checkOut <= $checkIn) {
            return 'dates';
        }
        [$first, $last] = self::window($now, $maxDays, $tz);
        if ($checkIn < $first) {
            return 'past';
        }
        if (self::addDays($checkOut, -1) > $last) {
            return 'beyond';
        }
        $nights = self::daysBetween($checkIn, $checkOut);
        if ($nights < max(1, $minNights)) {
            return 'min_nights';
        }
        if ($nights > self::MAX_NIGHTS) {
            return 'max_nights';
        }
        foreach (self::dates($checkIn, $checkOut) as $date) {
            if (in_array($date, $blocked, true)) {
                return 'blocked';
            }
        }
        if (self::anyOverlap(self::dayStart($checkIn, $tz), self::dayStart($checkOut, $tz), $holds)) {
            return 'taken';
        }

        return null;
    }

    /**
     * Why the slot starting at $start cannot be booked, or null when it can. It must be one of
     * its day's slots, so a typed time off the grid is refused.
     *
     * @param array<int,array<int,array{0:int,1:int}>> $hours
     * @param array<int,array{0:int,1:int}>            $holds
     * @param array<int,string>                        $blocked
     *
     * @return string|null one of 'slot', 'past', 'beyond', 'blocked', 'taken'
     */
    public static function checkSlot(int $start, array $hours, int $minutes, array $holds, array $blocked, DateTimeZone $tz, int $now, int $maxDays): ?string
    {
        if ($start <= $now) {
            return 'past';
        }
        $date      = self::localDate($start, $tz);
        [, $last]  = self::window($now, $maxDays, $tz);
        if ($date > $last) {
            return 'beyond';
        }
        if (in_array($date, $blocked, true)) {
            return 'blocked';
        }
        foreach (self::slotsForDay($date, $hours, $minutes, $tz) as [$s, $e]) {
            if ($s === $start) {
                return self::anyOverlap($s, $e, $holds) ? 'taken' : null;
            }
        }

        return 'slot';
    }

    /**
     * The Unix time a local "Y-m-d\TH:i" names, or null when it is not one. A time skipped by
     * a clock change is null, so it can never match a slot.
     */
    public static function parseLocal(string $value, DateTimeZone $tz): ?int
    {
        $value = str_replace(' ', 'T', trim($value));
        $d     = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', substr($value, 0, 16), $tz);
        if ($d === false || $d->format('Y-m-d\TH:i') !== substr($value, 0, 16)) {
            return null;
        }

        return $d->getTimestamp();
    }

    /**
     * Read blocked dates as a seller typed them: one date or "from..to" range per line or comma.
     * Ranges longer than a year are cut to a year.
     *
     * @return string[] sorted, unique dates
     */
    public static function parseBlocked(string $text): array
    {
        $dates = array();
        foreach (preg_split('/[\s,;]+/', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: array() as $part) {
            $range = explode('..', $part, 2);
            $from  = $range[0];
            $to    = $range[1] ?? $from;
            if (!self::validDate($from) || !self::validDate($to) || $to < $from) {
                continue;
            }
            $to = min($to, self::addDays($from, 366));
            for ($d = $from; $d <= $to; $d = self::addDays($d, 1)) {
                $dates[$d] = true;
            }
        }
        $dates = array_keys($dates);
        sort($dates);

        return $dates;
    }

    /**
     * Dates back as ranges for the seller's box: consecutive days become "from..to".
     *
     * @param string[] $dates sorted
     */
    public static function formatBlocked(array $dates): string
    {
        $lines = array();
        $start = $prev = null;
        foreach ($dates as $date) {
            if ($prev !== null && self::addDays($prev, 1) === $date) {
                $prev = $date;
                continue;
            }
            if ($start !== null) {
                $lines[] = $start === $prev ? $start : $start . '..' . $prev;
            }
            $start = $prev = $date;
        }
        if ($start !== null) {
            $lines[] = $start === $prev ? $start : $start . '..' . $prev;
        }

        return implode("\n", $lines);
    }

    /**
     * The first day of the month $date is in.
     */
    public static function monthStart(string $date): string
    {
        return substr($date, 0, 7) . '-01';
    }

    /**
     * The first day of the month after the one $date is in.
     */
    public static function nextMonth(string $date): string
    {
        return (new DateTimeImmutable(self::monthStart($date), new DateTimeZone('UTC')))->add(new DateInterval('P1M'))->format('Y-m-d');
    }

    /**
     * @param array<int,array{0:int,1:int}> $holds
     */
    private static function anyOverlap(int $start, int $end, array $holds): bool
    {
        foreach ($holds as [$s, $e]) {
            if (self::overlaps($start, $end, (int) $s, (int) $e)) {
                return true;
            }
        }

        return false;
    }
}
