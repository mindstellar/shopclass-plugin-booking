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

use DateTimeZone;
use mindstellar\database\UtcDatetime;

/**
 * A listing's bookings as an iCalendar feed (RFC 5545), for Google, Apple or Outlook calendars.
 * Stays are all-day events; slots carry UTC times. Nothing about the buyer is in it.
 */
final class Ical
{
    /**
     * @param array<int,array<string,mixed>> $bookings rows from BookingStore::feed()
     */
    public static function build(string $title, string $host, array $bookings, DateTimeZone $tz, int $now): string
    {
        $lines = array(
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Mindstellar//Shopclass Booking Calendar//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:' . self::text($title),
        );
        foreach ($bookings as $b) {
            $start   = (int) UtcDatetime::parse((string) $b['dt_start']);
            $end     = (int) UtcDatetime::parse((string) $b['dt_end']);
            $pending = (string) $b['s_status'] === Status::PENDING;
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:booking-' . (int) $b['pk_i_id'] . '@' . self::text($host);
            $lines[] = 'DTSTAMP:' . gmdate('Ymd\THis\Z', $now);
            if ((string) $b['s_mode'] === Availability::NIGHTS) {
                $lines[] = 'DTSTART;VALUE=DATE:' . str_replace('-', '', Availability::localDate($start, $tz));
                $lines[] = 'DTEND;VALUE=DATE:' . str_replace('-', '', Availability::localDate($end, $tz));
            } else {
                $lines[] = 'DTSTART:' . gmdate('Ymd\THis\Z', $start);
                $lines[] = 'DTEND:' . gmdate('Ymd\THis\Z', $end);
            }
            $lines[] = 'SUMMARY:' . self::text(sprintf(__('Booking #%1$d (%2$s)', 'booking'), (int) $b['pk_i_id'], Status::label((string) $b['s_status'])));
            $lines[] = 'STATUS:' . ($pending ? 'TENTATIVE' : 'CONFIRMED');
            $lines[] = 'TRANSP:OPAQUE';
            $lines[] = 'END:VEVENT';
        }
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map(array(self::class, 'fold'), $lines)) . "\r\n";
    }

    /**
     * Escape text for a property value.
     */
    public static function text(string $value): string
    {
        $value = (string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', '', $value);

        return str_replace(array('\\', ';', ',', "\r\n", "\n", "\r"), array('\\\\', '\;', '\,', '\n', '\n', '\n'), $value);
    }

    /**
     * Fold a line at 75 octets without splitting a UTF-8 character.
     */
    public static function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }
        $out   = '';
        $chunk = '';
        $limit = 75;
        foreach (preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY) ?: str_split($line) as $char) {
            if (strlen($chunk) + strlen($char) > $limit) {
                $out  .= $chunk . "\r\n ";
                $chunk = '';
                $limit = 74;
            }
            $chunk .= $char;
        }

        return $out . $chunk;
    }
}
