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
 * The plugin's place in the admin: one Booking Calendar group under Plugins, with Bookings and Settings.
 */
final class Admin
{
    /**
     * The admin_menu_init hook.
     */
    public static function menu(): void
    {
        osc_add_admin_submenu_divider('plugins', __('Booking Calendar', 'booking'), 'booking', 'administrator');
        osc_add_admin_submenu_page('plugins', __('Bookings', 'booking'), osc_route_admin_url(Plugin::ROUTE_ADMIN), 'booking-bookings', 'administrator');
        osc_add_admin_submenu_page('plugins', __('Settings', 'booking'), osc_settings_page_url(Config::PAGE), 'settings-page-' . Config::PAGE, 'administrator');
    }

    /**
     * The init_admin hook: the screen's header.
     */
    public static function page(): void
    {
        osc_admin_plugin_page(Plugin::ROUTE_ADMIN, array(
            'title'   => __('Bookings', 'booking'),
            'help'    => __('Every booking on the site. Sellers accept or decline requests from their account; requests nobody answers expire on their own.', 'booking'),
            'actions' => array(
                array('icon' => 'bi-gear-fill', 'url' => osc_settings_page_url(Config::PAGE), 'title' => __('Settings', 'booking')),
            ),
        ));
    }
}
