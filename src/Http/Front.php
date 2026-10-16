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

namespace mindstellar\booking\Http;

use mindstellar\booking\Availability;
use mindstellar\booking\BookingException;
use mindstellar\booking\BookingService;
use mindstellar\booking\Plugin;
use mindstellar\booking\Status;
use Params;
use View;

/**
 * The public routes: the calendar's data, a request, a guest's link, the account page's
 * actions and the calendar feed. Each reads the request and calls the service.
 */
final class Front
{
    /** Seconds a month of the calendar is kept in core's cache. */
    private const CACHE_TTL = 300;

    /**
     * GET booking/availability/{item}?from=Y-m-d&v=version: one month of the calendar as JSON.
     * The address carries the listing's version, so a cache may keep the answer: any change
     * gives the page a new address.
     */
    public static function availability(): void
    {
        $service = Plugin::service();
        try {
            $ctx = $service->bookable(Params::getParamInt('item'));
        } catch (BookingException $e) {
            self::json(array('error' => $e->reason()), 404, 'no-store');

            return;
        }
        $itemId  = (int) $ctx['item']['pk_i_id'];
        $version = (int) $ctx['listing']['i_version'];
        [$today, $last] = Availability::window(time(), $service->config()->maxDays, $service->config()->tz());
        $from           = Availability::clampMonth((string) Params::getParamString('from'), $today, $last);
        $key            = 'booking:month:' . $itemId . ':' . $version . ':' . $today . ':' . $from;

        $found = false;
        $data  = osc_cache_get($key, $found);
        if (!$found || !is_array($data)) {
            $data = $service->month($itemId, $ctx['mode'], $ctx['listing'], $from);
            osc_cache_set($key, $data, self::CACHE_TTL);
        }
        $data['version'] = $version;
        $current         = Params::getParamInt('v') === $version;
        self::json($data, 200, $current ? 'public, max-age=60, s-maxage=' . self::CACHE_TTL : 'public, max-age=0, s-maxage=30');
    }

    /**
     * POST booking/request: a buyer asks for dates. The service decides what happens.
     */
    public static function request(): void
    {
        $itemId = Params::getParamInt('item');
        $back   = ($itemId > 0 ? (string) osc_item_url_ns($itemId) : osc_base_url()) . '#booking';
        if (!self::isPost()) {
            osc_redirect_to($back);
        }
        osc_csrf_check();

        $userId = osc_is_web_user_logged_in() ? (int) osc_logged_user_id() : 0;
        try {
            $result = Plugin::service()->requestOrBook(array(
                'item'  => $itemId,
                'start' => Params::getParamString('start'),
                'end'   => Params::getParamString('end'),
                'name'  => Params::getParamString('name'),
                'email' => Params::getParamString('email'),
                'phone' => Params::getParamString('phone'),
                'note'  => Params::getParamString('note'),
            ), $userId, $userId > 0 ? (string) osc_logged_user_email() : '');
            if ($result['outcome'] === BookingService::ASKED) {
                osc_add_flash_info_message(__('Almost done: we sent you an e-mail. Open the link in it to send your request.', 'booking'));
            } else {
                self::flashBooked($result['outcome']);
            }
        } catch (BookingException $e) {
            osc_add_flash_error_message($e->getMessage());
        }
        osc_redirect_to($back);
    }

    /**
     * GET or POST booking/guest?t=token: a link from an e-mail. GET shows what it will do and a
     * button, so a mail scanner that opens links changes nothing; the POST does it.
     */
    public static function guest(): void
    {
        $service = Plugin::service();
        $token   = (string) Params::getParamString('t');

        if (self::isPost()) {
            osc_csrf_check();
            try {
                $result = $service->actOnLink($token);
                if ($result['outcome'] === Status::CANCELLED) {
                    osc_add_flash_ok_message(__('The booking is cancelled. We let the seller know.', 'booking'));
                } else {
                    self::flashBooked($result['outcome']);
                }
                osc_redirect_to(osc_item_url_ns((int) $result['booking']['fk_i_item_id']));
            } catch (BookingException $e) {
                osc_add_flash_error_message($e->getMessage());
                osc_redirect_to(Plugin::url(Plugin::ROUTE_GUEST, array('t' => $token)));
            }
        }

        $link    = $service->resolveLink($token);
        $heading = array(
            'request' => __('Confirm your booking request', 'booking'),
            'cancel'  => __('Cancel your booking', 'booking'),
            'none'    => __('This link does not work', 'booking'),
        )[$link['kind']];
        if ($link['kind'] === 'none') {
            http_response_code(404);
        }
        header('Cache-Control: private, no-store');
        header('Referrer-Policy: no-referrer');
        View::newInstance()->_exportVariableToView('booking_link', $link);
        osc_gui_view('', dirname(__DIR__, 2) . '/views/guest.php', array('heading' => $heading, 'title' => $heading));
    }

    /**
     * POST booking/manage: what a signed-in user does on the account page.
     */
    public static function manage(): void
    {
        $account = osc_route_url(Plugin::ROUTE_ACCOUNT);
        if (!osc_is_web_user_logged_in()) {
            osc_redirect_to(osc_user_login_url());
        }
        if (!self::isPost()) {
            osc_redirect_to($account);
        }
        osc_csrf_check();

        $service = Plugin::service();
        $userId  = (int) osc_logged_user_id();
        $do      = (string) Params::getParamString('do');
        $id      = Params::getParamInt('id');
        $itemId  = Params::getParamInt('item');
        $back    = $account;
        try {
            switch ($do) {
                case 'listing':
                    $hours = array();
                    for ($day = 1; $day <= 7; $day++) {
                        $hours[$day] = (string) Params::getParamString('hours_' . $day);
                    }
                    $service->saveListing($itemId, $userId, array(
                        'enabled'      => Params::getParamBool('enabled'),
                        'auto_confirm' => Params::getParamBool('auto_confirm'),
                        'min_nights'   => Params::getParamInt('min_nights'),
                        'slot_minutes' => Params::getParamInt('slot_minutes'),
                        'hours'        => $hours,
                        'price_note'   => Params::getParamString('price_note'),
                        'blocked'      => Params::getParamString('blocked'),
                    ) + (Params::existParam('import') ? array('import' => (string) Params::getParamString('import', false, false, false)) : array()));
                    if (Params::getParamBool('new_feed')) {
                        $service->resetFeed($itemId, $userId);
                    }
                    $back = Plugin::url(Plugin::ROUTE_ACCOUNT, array('item' => $itemId));
                    osc_add_flash_ok_message(__('Booking settings saved.', 'booking'));
                    break;
                case 'confirm':
                case 'decline':
                case 'cancel':
                    $to = array('confirm' => Status::CONFIRMED, 'decline' => Status::DECLINED, 'cancel' => Status::CANCELLED)[$do];
                    $service->move($id, $to, Status::SELLER, $userId);
                    osc_add_flash_ok_message(__('Booking updated. We e-mailed the buyer.', 'booking'));
                    break;
                case 'buyer_cancel':
                    $service->move($id, Status::CANCELLED, Status::BUYER, $userId);
                    osc_add_flash_ok_message(__('The booking is cancelled. We let the seller know.', 'booking'));
                    break;
            }
        } catch (BookingException $e) {
            osc_add_flash_error_message($e->getMessage());
            if ($do === 'listing' && $itemId > 0) {
                $back = Plugin::url(Plugin::ROUTE_ACCOUNT, array('item' => $itemId));
            }
        }
        osc_redirect_to($back);
    }

    /**
     * GET booking/ical/{item}/{key}.ics: the listing's bookings for a calendar app.
     */
    public static function ical(): void
    {
        $itemId = Params::getParamInt('item');
        $ics    = Plugin::service()->feed($itemId, (string) Params::getParamString('key'), (string) (parse_url((string) osc_base_url(), PHP_URL_HOST) ?: 'localhost'));
        if ($ics === null) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            header('Cache-Control: no-store');
            echo 'Not found';

            return;
        }
        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: inline; filename="booking-' . $itemId . '.ics"');
        header('Cache-Control: private, max-age=300');
        header('X-Robots-Tag: noindex');
        echo $ics;
    }

    /**
     * Tell the buyer how their booking stands.
     */
    private static function flashBooked(string $status): void
    {
        osc_add_flash_ok_message($status === Status::CONFIRMED
            ? __('Your booking is confirmed. We sent you an e-mail with the details.', 'booking')
            : __('Your request was sent. The seller will accept or decline it, and we will e-mail you.', 'booking'));
    }

    /**
     * @param array<string,mixed> $data
     */
    private static function json(array $data, int $status, string $cache): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: ' . $cache);
        header('X-Robots-Tag: noindex');
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function isPost(): bool
    {
        return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST';
    }
}
