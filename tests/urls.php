<?php
/*
 * This file is part of the Booking Calendar plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Route addresses: path values go in the path, everything else in an encoded query string.
 */

require __DIR__ . '/lib/bootstrap.php';

use mindstellar\booking\Plugin;

// Core's osc_route_url() with friendly URLs on: placeholders filled, nothing else added.
function osc_route_url($id, $args = array())
{
    $patterns = array(
        Plugin::ROUTE_AVAILABILITY => 'booking/availability/{item}',
        Plugin::ROUTE_ICAL         => 'booking/ical/{item}/{key}.ics',
        Plugin::ROUTE_ACCOUNT      => 'booking/account',
        Plugin::ROUTE_GUEST        => 'booking/guest',
    );
    $url = $patterns[$id];
    foreach ($args as $k => $v) {
        $url = str_replace('{' . $k . '}', (string) $v, $url, $count);
        if ($count === 0) {
            $url .= '&' . $k . '=' . $v;
        }
    }

    return 'https://example.test/' . $url;
}

pin('the listing goes in the calendar path', 'https://example.test/booking/availability/7?v=3', Plugin::url(Plugin::ROUTE_AVAILABILITY, array('item' => 7, 'v' => 3)));
pin('the feed takes the listing and key in its path', 'https://example.test/booking/ical/7/abc.ics', Plugin::url(Plugin::ROUTE_ICAL, array('item' => 7, 'key' => 'abc')));
pin('the account page takes the listing as a query value', 'https://example.test/booking/account?item=7', Plugin::url(Plugin::ROUTE_ACCOUNT, array('item' => 7)));
pin('query values are encoded', 'https://example.test/booking/guest?t=a.b%2Bc', Plugin::url(Plugin::ROUTE_GUEST, array('t' => 'a.b+c')));
pin('no values, no query string', 'https://example.test/booking/account', Plugin::url(Plugin::ROUTE_ACCOUNT));

exit(harness_result());
