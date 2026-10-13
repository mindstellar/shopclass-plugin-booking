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

use mindstellar\job\Job;
use mindstellar\migration\MigrationRunner;
use RuntimeException;

/**
 * The wiring: install, upgrade and uninstall, routes, jobs and cron.
 */
final class Plugin
{
    public const VERSION = '0.1.0';

    /** Bump with every new migration file. */
    public const SCHEMA = 1;

    /** The plugin's folder, its migrations' scope in core's ledger, and its key-value group. */
    public const FOLDER = 'booking';

    public const ROUTE_AVAILABILITY = 'booking-availability';
    public const ROUTE_REQUEST      = 'booking-request';
    public const ROUTE_GUEST        = 'booking-guest';
    public const ROUTE_MANAGE       = 'booking-manage';
    public const ROUTE_ICAL         = 'booking-ical';
    public const ROUTE_ACCOUNT      = 'booking-account';
    public const ROUTE_ADMIN        = 'booking-admin';

    /** Hourly job that expires unanswered requests. */
    public const JOB_EXPIRE = 'booking.expire';

    private static ?BookingService $service = null;

    /**
     * Create the tables.
     */
    public static function install(): void
    {
        self::migrate();
    }

    /**
     * Run migrations an update added. A request with nothing to do costs one preference read.
     */
    public static function upgrade(): void
    {
        if ((int) osc_get_preference('schema', Config::PAGE) >= self::SCHEMA) {
            return;
        }
        self::migrate();
    }

    /**
     * Drop the tables, the settings and the stored link claims.
     */
    public static function uninstall(): void
    {
        BookingStore::drop();
        MigrationRunner::forPlugin(self::FOLDER)->forget();
        osc_db_execute('DELETE FROM ' . DB_TABLE_PREFIX . 't_preference WHERE s_section = ?', array(Config::PAGE));
        osc_reset_preferences();
        osc_kv_delete_group(self::FOLDER);
    }

    /**
     * Apply the plugin's migrations and record the schema version. A failure is logged and
     * tried again on the next request.
     */
    public static function migrate(): void
    {
        $runner = MigrationRunner::forPlugin(self::FOLDER);
        $runner->ensureLedger();
        $result = $runner->run();
        if ($result['ok']) {
            osc_set_preference('schema', (string) self::SCHEMA, Config::PAGE, 'INTEGER');
            osc_reset_preferences();

            return;
        }
        if ($result['failed'] !== null) {
            error_log('Booking Calendar: migration ' . $result['failed'] . ' failed: ' . $result['error']);
        }
    }

    /**
     * The service on this site's settings and database.
     */
    public static function service(): BookingService
    {
        if (self::$service === null) {
            $config        = Config::get();
            $locale        = function_exists('osc_current_user_locale') ? (string) osc_current_user_locale() : '';
            self::$service = new BookingService($config, new BookingStore($locale), new Notifier($config->tz()));
        }

        return self::$service;
    }

    /**
     * Replace the service, in tests.
     */
    public static function setService(?BookingService $service): void
    {
        self::$service = $service;
    }

    /**
     * Register the routes. File routes render inside the theme; hook routes answer on their own.
     */
    public static function routes(string $folder): void
    {
        $end = '(?:\?.*)?$';
        osc_add_route_hook(self::ROUTE_AVAILABILITY, 'booking/availability/([0-9]+)' . $end, 'booking/availability/{item}');
        osc_add_route_hook(self::ROUTE_REQUEST, 'booking/request' . $end, 'booking/request');
        osc_add_route_hook(self::ROUTE_GUEST, 'booking/guest' . $end, 'booking/guest');
        osc_add_route_hook(self::ROUTE_MANAGE, 'booking/manage' . $end, 'booking/manage');
        osc_add_route_hook(self::ROUTE_ICAL, 'booking/ical/([0-9]+)/([a-f0-9]{32})\.ics' . $end, 'booking/ical/{item}/{key}.ics');
        osc_add_route(
            self::ROUTE_ACCOUNT,
            'booking/account' . $end,
            'booking/account',
            $folder . 'views/account.php',
            true,
            'custom',
            'custom',
            __('Bookings', 'booking')
        );
        osc_add_route(self::ROUTE_ADMIN, 'booking/admin/bookings' . $end, 'booking/admin/bookings', $folder . 'admin/bookings.php');
    }

    /**
     * A route's address with $query added, encoded.
     *
     * @param array<string,scalar> $query
     */
    public static function url(string $route, array $query = array()): string
    {
        $routes  = \Rewrite::getInstance()->getRoutes();
        $pattern = (string) ($routes[$route]['url'] ?? '');
        $path    = array();
        foreach (array('item', 'key') as $name) {
            if (isset($query[$name]) && strpos($pattern, '{' . $name . '}') !== false) {
                $path[$name] = rawurlencode((string) $query[$name]);
                unset($query[$name]);
            }
        }
        $url = (string) osc_route_url($route, $path);
        if ($query === array()) {
            return $url;
        }

        return $url . (strpos($url, '?') === false ? '?' : '&') . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * A file of the plugin's, as a web address.
     */
    public static function asset(string $path): string
    {
        return osc_asset_url_versioned(osc_plugin_url(dirname(__DIR__) . '/index.php') . $path);
    }

    /**
     * Register the job handlers. Cron does not run init, so each builds what it needs itself.
     */
    public static function registerJobs(): void
    {
        osc_job_register_handler(Notifier::JOB, static function (Job $job): void {
            $mail = (array) $job->payload();
            if (empty($mail['to'])) {
                return;
            }
            if (!osc_sendMail($mail)) {
                throw new RuntimeException('the mail could not be sent');
            }
        });
        osc_job_register_handler(self::JOB_EXPIRE, static function (Job $job): void {
            self::service()->expire();
        });
        osc_job_describe(Notifier::JOB, __('Send a booking e-mail', 'booking'), static function (array $payload): string {
            return (string) ($payload['subject'] ?? '');
        });
        osc_job_describe(self::JOB_EXPIRE, __('Expire unanswered booking requests', 'booking'));
    }

    /**
     * Queue the expiry check, once.
     */
    public static function hourly(): void
    {
        osc_job_ensure(self::JOB_EXPIRE);
    }

    /**
     * A deleted listing takes its bookings and settings with it.
     *
     * @param mixed $itemId
     */
    public static function itemDeleted($itemId): void
    {
        if ((int) $itemId > 0) {
            self::service()->store()->forgetItem((int) $itemId);
        }
    }

    /**
     * The account nav entry.
     *
     * @param mixed $items
     *
     * @return mixed
     */
    public static function menu($items)
    {
        if (!is_array($items)) {
            return $items;
        }
        $items[] = array(
            'name'  => __('Bookings', 'booking'),
            'url'   => osc_route_url(self::ROUTE_ACCOUNT),
            'class' => 'opt_booking',
        );

        return $items;
    }
}
