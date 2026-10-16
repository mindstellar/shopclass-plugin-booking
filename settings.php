<?php

/*
 * This file is part of the Booking Calendar plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\booking\Availability;

/*
 * The settings page declaration. Core renders the form, checks CSRF and the admin's
 * capability, validates each field and stores it under the "booking" section.
 */

$d = 'booking';

return array(
    'title'  => __('Booking Calendar', $d),
    'menu'   => 'plugins',
    // Core wraps the help in one <p>, so each fact closes it and opens the next.
    'help'   => implode('</p><p>', array_map('osc_esc_html', array(
        __('Sellers switch bookings on per listing under My account → Bookings, where they also set weekly hours, closed dates and auto-confirm.', $d),
        __('Every booking is listed under Plugins → Bookings.', $d),
        __('E-mails go out through the job queue: within minutes on auto-cron, at once with a cron line or the job worker.', $d),
        __('Times are shown in the site\'s time zone (Settings → General). On a day the clocks change, a slot day has one slot more or less.', $d),
        __('Each listing has a private calendar address (.ics) that sellers can add to Google, Apple or Outlook calendar. It carries no buyer details.', $d),
        __('Two requests for the same time cannot both be accepted.', $d),
    ))),
    'intro'  => __('Let buyers book listings for dates or time slots. Choose which categories take bookings; sellers turn it on for each listing from their account. Deactivating the plugin keeps every booking; uninstalling it deletes all bookings and these settings.', $d),
    'groups' => array(
        array(
            'title'  => __('Categories', $d),
            'fields' => array(
                array(
                    'type'    => 'category_modes',
                    'name'    => 'categories',
                    'label'   => __('Booking by category', $d),
                    'help'    => __('Nights suit rentals: buyers pick check-in and check-out dates. Time slots suit services: buyers pick a time within the seller\'s opening hours. A subcategory follows its parent unless you change it.', $d),
                    'modes'   => array(
                        Availability::NIGHTS => __('Nights', $d),
                        Availability::SLOTS  => __('Time slots', $d),
                    ),
                    'default' => '{}',
                ),
            ),
        ),
        array(
            'title'  => __('Defaults and limits', $d),
            'fields' => array(
                array(
                    'type'     => 'number',
                    'name'     => 'slot_minutes',
                    'label'    => __('Default slot length (minutes)', $d),
                    'help'     => __('Sellers can change it for each listing.', $d),
                    'default'  => 60,
                    'required' => true,
                    'attrs'    => array('min' => 5, 'max' => 720, 'step' => 5),
                    'validate' => static fn ($v) => ((int) $v < 5 || (int) $v > 720) ? __('Use 5 to 720 minutes.', 'booking') : null,
                ),
                array(
                    'type'     => 'number',
                    'name'     => 'min_nights',
                    'label'    => __('Default minimum nights', $d),
                    'default'  => 1,
                    'required' => true,
                    'attrs'    => array('min' => 1, 'max' => Availability::MAX_NIGHTS),
                    'validate' => static fn ($v) => ((int) $v < 1 || (int) $v > Availability::MAX_NIGHTS) ? sprintf(__('Use 1 to %d nights.', 'booking'), Availability::MAX_NIGHTS) : null,
                ),
                array(
                    'type'     => 'number',
                    'name'     => 'max_days',
                    'label'    => __('How far ahead buyers can book (days)', $d),
                    'default'  => 180,
                    'required' => true,
                    'attrs'    => array('min' => 1, 'max' => 730),
                    'validate' => static fn ($v) => ((int) $v < 1 || (int) $v > 730) ? __('Use 1 to 730 days.', 'booking') : null,
                ),
                array(
                    'type'     => 'number',
                    'name'     => 'expiry_hours',
                    'label'    => __('Unanswered requests expire after (hours)', $d),
                    'help'     => __('Checked every hour by cron. A request also expires once its start has passed.', $d),
                    'default'  => 48,
                    'required' => true,
                    'attrs'    => array('min' => 1, 'max' => 720),
                    'validate' => static fn ($v) => ((int) $v < 1 || (int) $v > 720) ? __('Use 1 to 720 hours.', 'booking') : null,
                ),
            ),
        ),
        array(
            'title'  => __('Limits per buyer', $d),
            'fields' => array(
                array(
                    'type'     => 'number',
                    'name'     => 'user_limit',
                    'label'    => __('Requests per hour from one account', $d),
                    'help'     => __('Counts requests from the site and the API. 0 means no limit.', $d),
                    'default'  => 10,
                    'attrs'    => array('min' => 0, 'max' => 1000),
                    'validate' => static fn ($v) => ((int) $v < 0 || (int) $v > 1000) ? __('Use 0 to 1000.', 'booking') : null,
                ),
                array(
                    'type'     => 'number',
                    'name'     => 'open_per_listing',
                    'label'    => __('Waiting requests one buyer may have on one listing', $d),
                    'default'  => 3,
                    'required' => true,
                    'attrs'    => array('min' => 1, 'max' => 100),
                    'validate' => static fn ($v) => ((int) $v < 1 || (int) $v > 100) ? __('Use 1 to 100.', 'booking') : null,
                ),
                array(
                    'type'     => 'number',
                    'name'     => 'open_total',
                    'label'    => __('Waiting requests one buyer may have in all', $d),
                    'default'  => 10,
                    'required' => true,
                    'attrs'    => array('min' => 1, 'max' => 1000),
                    'validate' => static fn ($v) => ((int) $v < 1 || (int) $v > 1000) ? __('Use 1 to 1000.', 'booking') : null,
                ),
            ),
        ),
        array(
            'title'  => __('Guests', $d),
            'fields' => array(
                array(
                    'type'    => 'checkbox',
                    'name'    => 'guests',
                    'label'   => __('Let visitors without an account send requests', $d),
                    'help'    => __('They confirm their e-mail address with a link before the seller sees the request.', $d),
                    'default' => true,
                ),
                array(
                    'type'     => 'number',
                    'name'     => 'guest_limit',
                    'label'    => __('Guest requests per hour from one IP address', $d),
                    'help'     => __('Counted per IP address, or per /64 network for IPv6. 0 means no limit.', $d),
                    'default'  => 5,
                    'depends'  => 'guests',
                    'attrs'    => array('min' => 0, 'max' => 1000),
                    'validate' => static fn ($v) => ((int) $v < 0 || (int) $v > 1000) ? __('Use 0 to 1000.', 'booking') : null,
                ),
            ),
        ),
    ),
);
