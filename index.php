<?php
/*
Plugin Name: Booking Calendar
Plugin URI: https://github.com/mindstellar/shopclass-plugin-booking
Description: Let buyers book a listing for dates or a time slot, with an availability calendar, seller approval, e-mails and a calendar feed. Choose the categories in its settings.
Version: 0.1.0
Author: Navjot Tomer (Mindstellar)
Author URI: https://mindstellar.com
Short Name: booking
Requires Shopclass: 7.0.0
Tested up to: 7.0
Requires PHP: 8.0
Support URI: https://github.com/mindstellar/shopclass-plugin-booking/issues
*/

/*
 * This file is part of the Booking Calendar plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\booking\Admin;
use mindstellar\booking\Api;
use mindstellar\booking\Config;
use mindstellar\booking\Http\Front;
use mindstellar\booking\ItemPage;
use mindstellar\booking\Plugin;
use mindstellar\booking\Privacy;

if (!defined('ABS_PATH')) {
    exit('Direct access is not allowed.');
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'mindstellar\\booking\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

osc_register_plugin(osc_plugin_path(__FILE__), array(Plugin::class, 'install'));
osc_add_hook(osc_plugin_path(__FILE__) . '_uninstall', array(Plugin::class, 'uninstall'));

// Shopclass 6.x has no plugin migrations or job queue, so the plugin stays off there.
if (!class_exists('mindstellar\\migration\\MigrationRunner') || !function_exists('osc_job_enqueue')) {
    osc_add_hook('admin_page_header', static function (): void {
        echo '<div class="flashmessage flashmessage-warning" role="status">'
            . osc_esc_html(__('Booking Calendar needs Shopclass 7.0 and is switched off. Update Shopclass to use it.', 'booking'))
            . '</div>';
    }, 10);

    return;
}

osc_register_settings_page(Config::PAGE, require __DIR__ . '/settings.php');
osc_add_hook(osc_plugin_path(__FILE__) . '_configure', static function () {
    osc_redirect_to(osc_settings_page_url(Config::PAGE));
});

// An update ships new migrations; they run on the first request after it.
osc_add_hook('init', array(Plugin::class, 'upgrade'));
osc_add_hook('init_admin', array(Plugin::class, 'upgrade'));

Plugin::routes(osc_plugin_folder(__FILE__));
osc_add_hook(Plugin::ROUTE_AVAILABILITY, array(Front::class, 'availability'));
osc_add_hook(Plugin::ROUTE_REQUEST, array(Front::class, 'request'));
osc_add_hook(Plugin::ROUTE_GUEST, array(Front::class, 'guest'));
osc_add_hook(Plugin::ROUTE_MANAGE, array(Front::class, 'manage'));
osc_add_hook(Plugin::ROUTE_ICAL, array(Front::class, 'ical'));

// The listing page: show_item decides and loads the script, item_detail prints the calendar.
osc_add_hook('show_item', array(ItemPage::class, 'prepare'));
osc_add_hook('item_detail', array(ItemPage::class, 'render'));
osc_add_filter('user_menu_filter', array(Plugin::class, 'menu'));
osc_add_hook('after_delete_item', array(Plugin::class, 'itemDeleted'));

// A member's bookings go into their data export, and their details leave with the account.
osc_add_filter('personal_data_export', array(Privacy::class, 'export'));
osc_add_hook('delete_user', array(Privacy::class, 'deleteUser'));

// E-mails and expiry run on core's job queue; cron or the worker drains it.
osc_add_hook('register_jobs', array(Plugin::class, 'registerJobs'));
osc_add_hook('cron_hourly', array(Plugin::class, 'hourly'));

// Plugins > Bookings.
osc_add_hook('admin_menu_init', array(Admin::class, 'menu'));
osc_add_hook('init_admin', array(Admin::class, 'page'));

// The REST API under /api/v1/ext/booking/.
osc_add_filter('api_scopes', array(Api::class, 'scopes'));
osc_add_filter('api_problem_codes', array(Api::class, 'problemCodes'));
Api::register();
