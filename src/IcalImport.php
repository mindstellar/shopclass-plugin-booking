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
use Throwable;

/**
 * Reads the busy dates out of another site's calendar file (RFC 5545). Each event closes
 * every local date it touches. Repeating events (RRULE) count only their first time.
 */
final class IcalImport
{
    /** Most dates one calendar can close. */
    public const MAX_DATES = 1000;

    /** Most events read from one calendar; the rest are left out. */
    public const MAX_EVENTS = 2000;

    /**
     * The local dates the calendar's events touch, between $from and $to (both included).
     *
     * @param bool|null $cut set to true when the calendar had more than MAX_EVENTS events
     *
     * @return string[] Y-m-d, sorted
     */
    public static function parse(string $ics, DateTimeZone $siteTz, string $fromDate, string $toDate, ?bool &$cut = null): array
    {
        $cut    = false;
        $text   = str_replace(array("\r\n", "\r"), "\n", $ics);
        $text   = (string) preg_replace("/\n[ \t]/", '', $text);
        $ranges = array();
        $events = 0;
        $event  = null;
        $depth  = 0;
        foreach (explode("\n", $text) as $line) {
            $prop = self::property($line);
            if ($prop === null) {
                continue;
            }
            [$name, $params, $value] = $prop;
            $upper = strtoupper(trim($value));
            if ($name === 'BEGIN') {
                if ($event === null && $upper === 'VEVENT') {
                    $event = array();
                    $depth = 0;
                } elseif ($event !== null) {
                    $depth++;
                }
                continue;
            }
            if ($name === 'END' && $event !== null) {
                if ($depth > 0) {
                    $depth--;
                    continue;
                }
                if ($upper === 'VEVENT') {
                    if (++$events > self::MAX_EVENTS) {
                        $cut = true;
                        break;
                    }
                    $range = self::eventRange($event, $siteTz, $fromDate, $toDate);
                    if ($range !== null) {
                        $ranges[] = $range;
                    }
                    $event = null;
                }
                continue;
            }
            // The event's own properties only, not those of an alarm inside it.
            if ($event !== null && $depth === 0 && !isset($event[$name])) {
                $event[$name] = array($params, trim($value));
            }
        }

        return self::expand($ranges);
    }

    /**
     * Day ranges, merged where they overlap, as sorted dates: each day is written once.
     *
     * @param array<int,array{0:int,1:int}> $ranges first and last day, as days since 1970-01-01
     *
     * @return string[]
     */
    private static function expand(array $ranges): array
    {
        sort($ranges);
        $out  = array();
        $next = PHP_INT_MIN;
        foreach ($ranges as [$first, $last]) {
            for ($day = max($first, $next); $day <= $last; $day++) {
                if (count($out) >= self::MAX_DATES) {
                    return $out;
                }
                $out[] = gmdate('Y-m-d', $day * 86400);
            }
            $next = max($next, $last + 1);
        }

        return $out;
    }

    /**
     * A Y-m-d date as days since 1970-01-01.
     */
    private static function dayNumber(string $date): int
    {
        return intdiv((int) gmmktime(0, 0, 0, (int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4)), 86400);
    }

    /**
     * One content line as [NAME, params (upper-case keys), value], or null for junk.
     *
     * @return array{0:string,1:array<string,string>,2:string}|null
     */
    private static function property(string $line): ?array
    {
        if (!preg_match('/^([A-Za-z0-9-]+)((?:;[A-Za-z0-9-]+=(?:"[^"]*"|[^";:]*)(?:,(?:"[^"]*"|[^";:,]*))*)*):(.*)$/', $line, $m)) {
            return null;
        }
        $params = array();
        if ($m[2] !== '' && preg_match_all('/;([A-Za-z0-9-]+)=("[^"]*"|[^";:]*)/', $m[2], $pm, PREG_SET_ORDER)) {
            foreach ($pm as $p) {
                $params[strtoupper($p[1])] = trim($p[2], '"');
            }
        }

        return array(strtoupper($m[1]), $params, $m[3]);
    }

    /**
     * @param array<string,array{0:array<string,string>,1:string}> $event
     *
     * @return array{0:int,1:int}|null first and last day inside [$from, $to], as days since 1970-01-01
     */
    private static function eventRange(array $event, DateTimeZone $tz, string $from, string $to): ?array
    {
        if (!isset($event['DTSTART'])
            || strtoupper($event['STATUS'][1] ?? '') === 'CANCELLED'
            || strtoupper($event['TRANSP'][1] ?? '') === 'TRANSPARENT'
        ) {
            return null;
        }
        $start = self::moment($event['DTSTART'], $tz);
        if ($start === null) {
            return null;
        }
        $end = isset($event['DTEND']) ? self::moment($event['DTEND'], $tz) : null;
        if ($end === null && isset($event['DURATION'])) {
            $end = self::after($start, $event['DURATION'][1]);
        }
        $first = $start['time']->format('Y-m-d');
        if ($start['date']) {
            // An all-day end is the day after the last one.
            $last = $end !== null && $end['time'] > $start['time'] ? $end['time']->modify('-1 day')->format('Y-m-d') : $first;
        } elseif ($end === null || $end['time'] <= $start['time']) {
            $last = $first;
        } else {
            $local = $end['time']->setTimezone($tz);
            $last  = $local->format('H:i:s') === '00:00:00' ? $local->modify('-1 day')->format('Y-m-d') : $local->format('Y-m-d');
        }
        if (!$start['date']) {
            $first = $start['time']->setTimezone($tz)->format('Y-m-d');
        }
        $first = max($first, $from);
        $last  = min($last, $to);

        return $first <= $last ? array(self::dayNumber($first), self::dayNumber($last)) : null;
    }

    /**
     * A DTSTART or DTEND value as a time, and whether it is a date with no time.
     *
     * @param array{0:array<string,string>,1:string} $prop
     *
     * @return array{time:DateTimeImmutable,date:bool}|null
     */
    private static function moment(array $prop, DateTimeZone $siteTz): ?array
    {
        [$params, $value] = $prop;
        $value            = strtoupper($value);
        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $value, $m) && strtoupper($params['VALUE'] ?? 'DATE') === 'DATE') {
            $time = DateTimeImmutable::createFromFormat('!Y-m-d', $m[1] . '-' . $m[2] . '-' . $m[3], new DateTimeZone('UTC'));

            return $time !== false && checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? array('time' => $time, 'date' => true) : null;
        }
        if (!preg_match('/^(\d{8})T(\d{6})(Z?)$/', $value, $m)) {
            return null;
        }
        $zone = $m[3] === 'Z' ? new DateTimeZone('UTC') : self::zone($params['TZID'] ?? '', $siteTz);
        $time = DateTimeImmutable::createFromFormat('!YmdHis', $m[1] . $m[2], $zone);

        return $time !== false ? array('time' => $time, 'date' => false) : null;
    }

    /**
     * The named zone when PHP knows it, else the site's.
     */
    private static function zone(string $name, DateTimeZone $siteTz): DateTimeZone
    {
        $name = trim($name, '/ ');
        if ($name === '') {
            return $siteTz;
        }
        try {
            return new DateTimeZone($name);
        } catch (Throwable $e) {
            return $siteTz;
        }
    }

    /**
     * $start moved on by a DURATION value such as P3D or PT1H30M, or null.
     *
     * @param array{time:DateTimeImmutable,date:bool} $start
     *
     * @return array{time:DateTimeImmutable,date:bool}|null
     */
    private static function after(array $start, string $duration): ?array
    {
        $duration = strtoupper(ltrim(trim($duration), '+'));
        if (!preg_match('/^P(?:\d+W|(?:\d+D)?(?:T(?:\d+H)?(?:\d+M)?(?:\d+S)?)?)$/', $duration) || $duration === 'P' || $duration === 'PT') {
            return null;
        }
        try {
            return array('time' => $start['time']->add(new DateInterval($duration)), 'date' => $start['date']);
        } catch (Throwable $e) {
            return null;
        }
    }
}
