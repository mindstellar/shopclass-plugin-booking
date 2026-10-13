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
use mindstellar\booking\Ical;
use mindstellar\booking\Notifier;
use mindstellar\booking\Plugin;
use mindstellar\booking\Status;
use mindstellar\booking\Token;
use mindstellar\database\UtcDatetime;
use mindstellar\security\ActionThrottle;
use Params;

/**
 * The public routes: the calendar's data, a request, a guest's link, the account page's
 * actions and the calendar feed. Each reads the request and calls the service.
 */
final class Front
{
    /** Throttle context for guest requests. */
    public const THROTTLE = 'booking_request';

    /** Seconds a month of the calendar is kept in core's cache. */
    private const CACHE_TTL = 300;

    /** What a guest's link page shows, read by views/guest.php. */
    public static array $guest = array();

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
        $from    = (string) Params::getParamString('from');
        $from    = Availability::validDate($from) ? Availability::monthStart($from) : '';
        $today   = Availability::localDate(time(), $service->config()->tz());
        $key     = 'booking:month:' . $itemId . ':' . $version . ':' . $today . ':' . $from;

        $found = false;
        $data  = osc_cache_get($key, $found);
        if (!$found || !is_array($data)) {
            $data = $service->month($itemId, $ctx['mode'], $ctx['listing'], $from !== '' ? $from : $today);
            osc_cache_set($key, $data, self::CACHE_TTL);
        }
        $data['version'] = $version;
        $current         = Params::getParamInt('v') === $version;
        self::json($data, 200, $current ? 'public, max-age=60, s-maxage=' . self::CACHE_TTL : 'public, max-age=0, s-maxage=30');
    }

    /**
     * POST booking/request: a buyer asks for dates. A signed-in buyer books at once; a guest
     * gets an e-mail with a link that makes the booking.
     */
    public static function request(): void
    {
        $itemId = Params::getParamInt('item');
        $back   = ($itemId > 0 ? (string) osc_item_url_ns($itemId) : osc_base_url()) . '#booking';
        if (!self::isPost()) {
            osc_redirect_to($back);
        }
        osc_csrf_check();

        $service = Plugin::service();
        $userId  = osc_is_web_user_logged_in() ? (int) osc_logged_user_id() : 0;
        $input   = array(
            'item'  => $itemId,
            'start' => Params::getParamString('start'),
            'end'   => Params::getParamString('end'),
            'name'  => Params::getParamString('name'),
            'email' => Params::getParamString('email'),
            'phone' => Params::getParamString('phone'),
            'note'  => Params::getParamString('note'),
        );
        try {
            if ($userId === 0) {
                if (!$service->config()->guests) {
                    throw new BookingException('guests');
                }
                if (ActionThrottle::exceededFor(self::THROTTLE, $service->config()->guestLimit)) {
                    throw new BookingException('limit');
                }
            }
            $request = $service->prepare($input, $userId);
            if ($userId > 0) {
                $booking = $service->book($request);
                osc_add_flash_ok_message($booking['s_status'] === Status::CONFIRMED
                    ? __('Your booking is confirmed. We sent you an e-mail with the details.', 'booking')
                    : __('Your request was sent. The seller will accept or decline it, and we will e-mail you.', 'booking'));
            } else {
                $service->askGuest($request);
                ActionThrottle::record(self::THROTTLE);
                osc_add_flash_info_message(__('Almost done: we sent you an e-mail. Open the link in it to send your request.', 'booking'));
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
        $token   = (string) Params::getParam('t', false, false);
        if (!preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $token)) {
            $token = '';
        }
        $request = $token !== '' ? Token::openRequest($token) : null;
        $cancel  = $token !== '' && $request === null ? Token::peekCancel($token) : null;
        $booking = $cancel !== null ? $service->store()->find($cancel) : null;
        if ($booking !== null && Token::openCancel($token, (string) $booking['s_email']) === null) {
            $booking = null;
        }

        if (self::isPost() && ($request !== null || $booking !== null)) {
            osc_csrf_check();
            try {
                if ($request !== null) {
                    $made = $service->confirmGuest($token, static fn (string $t): bool => osc_kv_claim(Plugin::FOLDER, 'used:' . sha1($t), 2 * Token::REQUEST_TTL));
                    osc_add_flash_ok_message($made['s_status'] === Status::CONFIRMED
                        ? __('Your booking is confirmed. We sent you an e-mail with the details.', 'booking')
                        : __('Your request was sent. The seller will accept or decline it, and we will e-mail you.', 'booking'));
                    osc_redirect_to(osc_item_url_ns((int) $made['fk_i_item_id']));
                }
                $done = $service->move((int) $booking['pk_i_id'], Status::CANCELLED, Status::BUYER, 0, (string) $booking['s_email']);
                osc_add_flash_ok_message(__('The booking is cancelled. We let the seller know.', 'booking'));
                osc_redirect_to(osc_item_url_ns((int) $done['fk_i_item_id']));
            } catch (BookingException $e) {
                osc_add_flash_error_message($e->getMessage());
                $back = $request !== null ? (int) $request['item'] : (int) $booking['fk_i_item_id'];
                osc_redirect_to(osc_item_url_ns($back));
            }
        }

        $title = '';
        if ($request !== null) {
            $item  = $service->store()->item((int) $request['item']);
            $title = (string) ($item['s_title'] ?? '');
        } elseif ($booking !== null) {
            $title = (string) $booking['s_title'];
        }
        $notifier = new Notifier($service->config()->tz());
        $when     = '';
        if ($request !== null) {
            $when = $notifier->when(array(
                's_mode'   => $request['mode'],
                'dt_start' => UtcDatetime::format((int) $request['start']),
                'dt_end'   => UtcDatetime::format((int) $request['end']),
            ));
        } elseif ($booking !== null) {
            $when = $notifier->when($booking);
        }
        self::$guest = array('token' => $token, 'request' => $request, 'booking' => $booking, 'title' => $title, 'when' => $when);
        $heading = $request !== null ? __('Confirm your booking request', 'booking')
            : ($booking !== null ? __('Cancel your booking', 'booking') : __('This link does not work', 'booking'));
        if ($request === null && $booking === null) {
            http_response_code(404);
        }
        header('Cache-Control: private, no-store');
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
                    ));
                    if (Params::getParamBool('new_feed')) {
                        $service->store()->newIcalSalt($itemId);
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
        $service = Plugin::service();
        $itemId  = Params::getParamInt('item');
        $listing = $itemId > 0 ? $service->store()->listing($itemId) : null;
        if ($listing === null || !Token::icalValid($itemId, (string) $listing['s_ical_salt'], (string) Params::getParamString('key'))) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            header('Cache-Control: no-store');
            echo 'Not found';

            return;
        }
        $item = $service->store()->item($itemId);
        $now  = time();
        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: inline; filename="booking-' . $itemId . '.ics"');
        header('Cache-Control: private, max-age=300');
        header('X-Robots-Tag: noindex');
        echo Ical::build(
            (string) ($item['s_title'] ?? ''),
            (string) (parse_url((string) osc_base_url(), PHP_URL_HOST) ?: 'localhost'),
            $service->store()->feed($itemId, $now - 30 * 86400),
            $service->config()->tz(),
            $now
        );
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
