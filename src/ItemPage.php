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
 * The calendar on the listing page. show_item decides, before the page is drawn, whether this
 * listing shows one and loads the script; item_detail prints it. Other pages pay nothing.
 */
final class ItemPage
{
    /** What item_detail prints, set by show_item for the listing being shown. */
    public static ?array $current = null;

    /**
     * The show_item hook: one settings read for a listing in a booking category, none otherwise.
     *
     * @param mixed $item
     */
    public static function prepare($item): void
    {
        self::$current = null;
        if (!is_array($item) || empty($item['pk_i_id'])) {
            return;
        }
        $config = Config::get();
        $mode   = $config->modeForCategory((int) ($item['fk_i_category_id'] ?? 0));
        if ($mode === '') {
            return;
        }
        $itemId  = (int) $item['pk_i_id'];
        $owner   = (int) ($item['fk_i_user_id'] ?? 0);
        $isOwner = $owner > 0 && osc_is_web_user_logged_in() && (int) osc_logged_user_id() === $owner;
        $listing = Plugin::service()->store()->listing($itemId);
        $on      = $listing !== null && (int) $listing['b_enabled'] === 1;
        if (!$on && !$isOwner) {
            return;
        }
        self::$current = array('item' => $itemId, 'mode' => $mode, 'listing' => $listing, 'owner' => $isOwner, 'on' => $on);
        osc_register_style('booking-calendar', Plugin::asset('assets/booking.css'));
        osc_enqueue_style('booking-calendar');
        if ($on) {
            osc_register_script('booking-calendar', Plugin::asset('assets/booking.js'));
            osc_enqueue_script('booking-calendar');
        }
    }

    /**
     * The item_detail hook: the calendar and the request form, or the owner's link to turn it on.
     *
     * @param mixed $item
     */
    public static function render($item = null): void
    {
        $view = self::$current;
        if ($view === null || (is_array($item) && (int) ($item['pk_i_id'] ?? 0) !== $view['item'])) {
            return;
        }
        self::$current = null;
        $config = Config::get();
        $tz     = $config->tz();
        [$today, $last] = Availability::window(time(), $config->maxDays, $tz);
        $booking = array(
            'item'      => $view['item'],
            'mode'      => $view['mode'],
            'on'        => $view['on'],
            'owner'     => $view['owner'],
            'note'      => (string) ($view['listing']['s_price_note'] ?? ''),
            'minNights' => max(1, (int) ($view['listing']['i_min_nights'] ?? 1)),
            'data'      => Plugin::url(Plugin::ROUTE_AVAILABILITY, array('item' => $view['item'], 'v' => (int) ($view['listing']['i_version'] ?? 0))),
            'action'    => Plugin::url(Plugin::ROUTE_REQUEST),
            'manage'    => Plugin::url(Plugin::ROUTE_ACCOUNT, array('item' => $view['item'])),
            'today'     => $today,
            'last'      => $last,
            'signedIn'  => osc_is_web_user_logged_in(),
            'guests'    => $config->guests,
            'instant'   => !empty($view['listing']['b_auto_confirm']),
        );
        require dirname(__DIR__) . '/views/calendar.php';
    }
}
