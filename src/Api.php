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

use mindstellar\api\ApiCall;
use mindstellar\api\ProblemException;
use mindstellar\api\Response;
use mindstellar\database\UtcDatetime;

/**
 * REST API endpoints under /api/v1/ext/booking/: a listing's calendar, and a signed-in
 * user's booking request. Both go through the same service as the web pages.
 */
final class Api
{
    public const SLUG  = 'booking';
    public const WRITE = 'ext:booking:write';

    /**
     * Register the routes, when core has the API.
     */
    public static function register(): void
    {
        if (!function_exists('osc_api_register_route')) {
            return;
        }
        osc_api_register_route('GET', 'ext/booking/listings/{id}/availability', array(
            'handler'   => array(self::class, 'availability'),
            'auth'      => 'public',
            'scope'     => 'listings:read',
            'plugin'    => self::SLUG,
            'summary'   => 'One month of a listing\'s booking calendar',
            'tags'      => array('Booking'),
            'query'     => array(
                'type'       => 'object',
                'properties' => array('from' => array('type' => 'string', 'pattern' => '^\d{4}-\d{2}-\d{2}$')),
            ),
            'responses' => array(200 => array('type' => 'object')),
        ));
        osc_api_register_route('POST', 'ext/booking/listings/{id}/requests', array(
            'handler'    => array(self::class, 'create'),
            'auth'       => 'user',
            'scope'      => self::WRITE,
            'plugin'     => self::SLUG,
            'summary'    => 'Ask to book a listing',
            'tags'       => array('Booking'),
            'body'       => array(
                'type'                 => 'object',
                'required'             => array('start'),
                'additionalProperties' => false,
                'properties'           => array(
                    'start' => array('type' => 'string', 'maxLength' => 16),
                    'end'   => array('type' => 'string', 'maxLength' => 10),
                    'name'  => array('type' => 'string', 'maxLength' => 100),
                    'email' => array('type' => 'string', 'maxLength' => 150),
                    'phone' => array('type' => 'string', 'maxLength' => 40),
                    'note'  => array('type' => 'string', 'maxLength' => 1000),
                ),
            ),
            'responses'  => array(201 => array('type' => 'object')),
        ));
    }

    /**
     * The api_scopes filter.
     *
     * @param mixed $scopes
     *
     * @return mixed
     */
    public static function scopes($scopes)
    {
        if (is_array($scopes)) {
            $scopes[self::WRITE] = array('description' => 'Send booking requests.', 'audience' => 'user');
        }

        return $scopes;
    }

    /**
     * The api_problem_codes filter.
     *
     * @param mixed $codes
     *
     * @return mixed
     */
    public static function problemCodes($codes)
    {
        if (is_array($codes)) {
            $codes['ext_booking_unavailable'] = array(409, 'Those dates or that time cannot be booked.');
            $codes['ext_booking_invalid']     = array(422, 'The booking request is not valid.');
        }

        return $codes;
    }

    public static function availability(ApiCall $call): Response
    {
        $service = Plugin::service();
        try {
            $ctx = $service->bookable((int) $call->intArg('id'));
        } catch (BookingException $e) {
            throw ProblemException::notFound('This listing does not take bookings.');
        }
        $from = (string) $call->request()->queryString('from');
        $data = $service->month((int) $ctx['item']['pk_i_id'], $ctx['mode'], $ctx['listing'], $from !== '' ? $from : Availability::localDate(time(), $service->config()->tz()));
        $data['timezone'] = $service->config()->tz()->getName();

        return Response::ok($data);
    }

    public static function create(ApiCall $call): Response
    {
        $service = Plugin::service();
        $userId  = (int) $call->userId();
        $input   = $call->input();
        $user    = class_exists('User') ? \User::getInstance()->findByPrimaryKey($userId) : array();
        $user    = is_array($user) ? $user : array();
        try {
            $request = $service->prepare(array(
                'item'  => (int) $call->intArg('id'),
                'start' => (string) ($input['start'] ?? ''),
                'end'   => (string) ($input['end'] ?? ''),
                'name'  => (string) ($input['name'] ?? ($user['s_name'] ?? '')),
                'email' => (string) ($input['email'] ?? ($user['s_email'] ?? '')),
                'phone' => (string) ($input['phone'] ?? ''),
                'note'  => (string) ($input['note'] ?? ''),
            ), $userId);
            $booking = $service->book($request);
        } catch (BookingException $e) {
            if ($e->reason() === 'off') {
                throw ProblemException::notFound($e->getMessage());
            }
            $code = in_array($e->reason(), array('input', 'dates', 'slot', 'min_nights', 'max_nights'), true) ? 'ext_booking_invalid' : 'ext_booking_unavailable';
            throw ProblemException::of($code, $e->getMessage());
        }

        return Response::created(self::shape($booking), osc_api_url('ext/booking/listings/' . (int) $booking['fk_i_item_id'] . '/availability'));
    }

    /**
     * A booking as the API answers it.
     *
     * @param array<string,mixed> $b
     *
     * @return array<string,mixed>
     */
    public static function shape(array $b): array
    {
        return array(
            'id'         => (int) $b['pk_i_id'],
            'listing_id' => (int) $b['fk_i_item_id'],
            'mode'       => (string) $b['s_mode'],
            'status'     => (string) $b['s_status'],
            'start'      => UtcDatetime::rfc3339((int) UtcDatetime::parse((string) $b['dt_start'])),
            'end'        => UtcDatetime::rfc3339((int) UtcDatetime::parse((string) $b['dt_end'])),
            'created_at' => UtcDatetime::rfc3339((int) UtcDatetime::parse((string) $b['dt_created'])),
        );
    }
}
