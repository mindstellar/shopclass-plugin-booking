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

use RuntimeException;

/**
 * A booking that cannot be made or changed. The code is a short reason, such as 'taken'.
 */
final class BookingException extends RuntimeException
{
    private string $reason;

    public function __construct(string $reason, string $message = '')
    {
        parent::__construct($message !== '' ? $message : self::describe($reason));
        $this->reason = $reason;
    }

    /**
     * The short reason: taken, past, beyond, dates, min_nights, max_nights, blocked, slot,
     * off, owner, not_found, status, input, limit or open.
     */
    public function reason(): string
    {
        return $this->reason;
    }

    /**
     * What to tell the visitor.
     */
    public static function describe(string $reason): string
    {
        switch ($reason) {
            case 'taken':
                return __('Someone else just booked that. Please pick another date or time.', 'booking');
            case 'past':
                return __('That time has already passed.', 'booking');
            case 'beyond':
                return __('That is too far ahead to book yet.', 'booking');
            case 'dates':
                return __('Pick a check-in date and a later check-out date.', 'booking');
            case 'min_nights':
                return __('That stay is shorter than the minimum.', 'booking');
            case 'max_nights':
                return __('That stay is too long for one request.', 'booking');
            case 'blocked':
                return __('The seller has closed some of those dates.', 'booking');
            case 'slot':
                return __('Pick one of the times shown on the calendar.', 'booking');
            case 'off':
                return __('This listing does not take bookings.', 'booking');
            case 'owner':
                return __('You can only manage bookings for your own listings.', 'booking');
            case 'not_found':
                return __('That booking was not found.', 'booking');
            case 'status':
                return __('That booking cannot be changed that way any more.', 'booking');
            case 'limit':
                return __('Too many requests from your address. Please try again later.', 'booking');
            case 'open':
                return __('You already have the most waiting requests allowed. Wait for the seller to reply, or cancel one.', 'booking');
            case 'guests':
                return __('Please sign in to send a booking request.', 'booking');
        }

        return __('Please check the details and try again.', 'booking');
    }
}
