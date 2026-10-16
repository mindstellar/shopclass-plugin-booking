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

use Closure;
use DateTimeZone;
use Throwable;

/**
 * The admin's settings, read once per request. Tests build one by hand.
 */
final class Config
{
    /** Settings page id, and the preference section its values live in. */
    public const PAGE = 'booking';

    /** Mode a subcategory takes from its parent. */
    public const INHERIT = '';
    public const OFF     = 'off';

    private static ?self $current = null;

    /** fn(int $categoryId): int[] -- a category's parents, nearest first. Tests replace it. */
    public static ?Closure $parents = null;

    /**
     * @param array<int,string> $categories  category id => nights, slots or off
     * @param int               $slotMinutes default slot length for new listings
     * @param int               $minNights   default minimum stay for new listings
     * @param int               $maxDays     how far ahead a booking may be
     * @param int               $expiryHours unanswered requests expire after this
     * @param bool              $guests      whether visitors without an account may ask
     * @param int               $guestLimit  guest requests an hour from one address
     * @param string            $timezone    the site's time zone
     * @param int               $userLimit   requests an hour from one account; 0 means no limit
     * @param int               $openPerListing waiting requests one buyer may have on one listing
     * @param int               $openTotal   waiting requests one buyer may have in all
     */
    public function __construct(
        public array $categories,
        public int $slotMinutes = 60,
        public int $minNights = 1,
        public int $maxDays = 180,
        public int $expiryHours = 48,
        public bool $guests = true,
        public int $guestLimit = 5,
        public string $timezone = 'UTC',
        public int $userLimit = 10,
        public int $openPerListing = 3,
        public int $openTotal = 10
    ) {
    }

    /**
     * The saved settings.
     */
    public static function get(): self
    {
        if (self::$current !== null) {
            return self::$current;
        }

        return self::$current = new self(
            self::parseCategories((string) self::value('categories')),
            self::clamp((int) self::value('slot_minutes'), 5, 720, 60),
            self::clamp((int) self::value('min_nights'), 1, Availability::MAX_NIGHTS, 1),
            self::clamp((int) self::value('max_days'), 1, 730, 180),
            self::clamp((int) self::value('expiry_hours'), 1, 720, 48),
            (bool) self::value('guests'),
            self::clamp((int) self::value('guest_limit'), 0, 1000, 5),
            function_exists('osc_timezone') ? (string) osc_timezone() : 'UTC',
            self::clamp((int) self::value('user_limit'), 0, 1000, 10),
            self::clamp((int) self::value('open_per_listing'), 1, 100, 3),
            self::clamp((int) self::value('open_total'), 1, 1000, 10)
        );
    }

    /**
     * Forget the settings read this request, after a save or in tests.
     */
    public static function reset(?self $config = null): void
    {
        self::$current = $config;
    }

    /**
     * The site's time zone, or UTC when it is unset or unknown.
     */
    public function tz(): DateTimeZone
    {
        try {
            return new DateTimeZone($this->timezone !== '' ? $this->timezone : 'UTC');
        } catch (Throwable $e) {
            return new DateTimeZone('UTC');
        }
    }

    /**
     * The mode for a category, given its id and its parents' ids from nearest to the root.
     * The nearest category with a mode of its own decides.
     *
     * @param int[] $parents
     *
     * @return string nights, slots, or '' when booking is off
     */
    public function modeFor(int $categoryId, array $parents = array()): string
    {
        foreach (array_merge(array($categoryId), $parents) as $id) {
            $mode = $this->categories[(int) $id] ?? self::INHERIT;
            if ($mode === Availability::NIGHTS || $mode === Availability::SLOTS) {
                return $mode;
            }
            if ($mode === self::OFF) {
                return '';
            }
        }

        return '';
    }

    /**
     * The mode for a category on this site, walking its parents through core's category tree.
     */
    public function modeForCategory(int $categoryId): string
    {
        if ($categoryId <= 0 || $this->categories === array()) {
            return '';
        }
        if (isset($this->categories[$categoryId])) {
            return $this->modeFor($categoryId);
        }
        $parents = self::$parents !== null ? (self::$parents)($categoryId) : self::coreParents($categoryId);

        return $this->modeFor($categoryId, $parents);
    }

    /**
     * A category's parents from core's category tree, nearest first.
     *
     * @return int[]
     */
    private static function coreParents(int $categoryId): array
    {
        $parents = array();
        // hierarchy() runs from the category up to the root.
        $chain   = \Category::getInstance()->hierarchy($categoryId);
        foreach (is_array($chain) ? $chain : array() as $row) {
            $id = (int) ($row['pk_i_id'] ?? 0);
            if ($id > 0 && $id !== $categoryId) {
                $parents[] = $id;
            }
        }

        return $parents;
    }

    /**
     * Stored JSON to category id => mode, keeping only modes the plugin knows.
     *
     * @return array<int,string>
     */
    public static function parseCategories(string $json): array
    {
        $out = array();
        foreach ((array) json_decode($json, true) as $id => $mode) {
            if ((int) $id > 0 && in_array($mode, array(Availability::NIGHTS, Availability::SLOTS, self::OFF), true)) {
                $out[(int) $id] = (string) $mode;
            }
        }
        ksort($out);

        return $out;
    }

    /**
     * $value inside [$min, $max], or $default when it is below $min.
     */
    public static function clamp(int $value, int $min, int $max, int $default): int
    {
        if ($value < $min) {
            return $default;
        }

        return min($value, $max);
    }

    /**
     * One saved setting, or its declared default.
     *
     * @return mixed
     */
    private static function value(string $name)
    {
        return osc_settings_value(self::PAGE, $name);
    }
}
