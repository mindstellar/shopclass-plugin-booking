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

/**
 * A booking's statuses, and who may move it from one to another.
 */
final class Status
{
    public const PENDING   = 'pending';
    public const CONFIRMED = 'confirmed';
    public const DECLINED  = 'declined';
    public const CANCELLED = 'cancelled';
    public const EXPIRED   = 'expired';

    public const BUYER  = 'buyer';
    public const SELLER = 'seller';
    public const ADMIN  = 'admin';
    public const SYSTEM = 'system';

    /** from => to => who may make the move. */
    private const MOVES = array(
        self::PENDING => array(
            self::CONFIRMED => array(self::SELLER, self::ADMIN),
            self::DECLINED  => array(self::SELLER, self::ADMIN),
            self::CANCELLED => array(self::BUYER, self::SELLER, self::ADMIN),
            self::EXPIRED   => array(self::SYSTEM),
        ),
        self::CONFIRMED => array(
            self::CANCELLED => array(self::BUYER, self::SELLER, self::ADMIN),
        ),
    );

    /**
     * @return string[]
     */
    public static function all(): array
    {
        return array(self::PENDING, self::CONFIRMED, self::DECLINED, self::CANCELLED, self::EXPIRED);
    }

    /**
     * Whether $actor may move a booking from $from to $to.
     */
    public static function can(string $from, string $to, string $actor): bool
    {
        return in_array($actor, self::MOVES[$from][$to] ?? array(), true);
    }

    /**
     * The statuses $actor may move a booking in $from to.
     *
     * @return string[]
     */
    public static function next(string $from, string $actor): array
    {
        $out = array();
        foreach (self::MOVES[$from] ?? array() as $to => $actors) {
            if (in_array($actor, $actors, true)) {
                $out[] = $to;
            }
        }

        return $out;
    }

    /**
     * Whether a booking in $status keeps its dates from others.
     */
    public static function holds(string $status): bool
    {
        return $status === self::PENDING || $status === self::CONFIRMED;
    }

    /**
     * Whether nothing can happen to a booking in $status any more.
     */
    public static function isFinal(string $status): bool
    {
        return !isset(self::MOVES[$status]);
    }

    /**
     * The status in words.
     */
    public static function label(string $status): string
    {
        switch ($status) {
            case self::PENDING:
                return __('Waiting for reply', 'booking');
            case self::CONFIRMED:
                return __('Confirmed', 'booking');
            case self::DECLINED:
                return __('Declined', 'booking');
            case self::CANCELLED:
                return __('Cancelled', 'booking');
            case self::EXPIRED:
                return __('Expired', 'booking');
        }

        return $status;
    }

    /**
     * The `.oe-badge` modifier core styles for a status.
     */
    public static function badge(string $status): string
    {
        switch ($status) {
            case self::CONFIRMED:
                return 'paid';
            case self::PENDING:
                return 'pending';
            case self::DECLINED:
                return 'failed';
        }

        return 'cancelled';
    }
}
