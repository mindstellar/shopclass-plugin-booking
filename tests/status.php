<?php
/*
 * This file is part of the Booking Calendar plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Who may move a booking from one status to another.
 */

require __DIR__ . '/lib/bootstrap.php';

use mindstellar\booking\Status as S;

harness_section('a pending request');

check('the seller accepts it', S::can(S::PENDING, S::CONFIRMED, S::SELLER));
check('the seller declines it', S::can(S::PENDING, S::DECLINED, S::SELLER));
check('the buyer withdraws it', S::can(S::PENDING, S::CANCELLED, S::BUYER));
check('cron expires it', S::can(S::PENDING, S::EXPIRED, S::SYSTEM));
check('the buyer cannot accept their own request', !S::can(S::PENDING, S::CONFIRMED, S::BUYER));
check('the seller cannot expire it', !S::can(S::PENDING, S::EXPIRED, S::SELLER));
check('cron cannot accept it', !S::can(S::PENDING, S::CONFIRMED, S::SYSTEM));
pin('what the seller can do', array(S::CONFIRMED, S::DECLINED, S::CANCELLED), S::next(S::PENDING, S::SELLER));

harness_section('a confirmed booking');

check('either side cancels it', S::can(S::CONFIRMED, S::CANCELLED, S::BUYER) && S::can(S::CONFIRMED, S::CANCELLED, S::SELLER));
check('the admin cancels it', S::can(S::CONFIRMED, S::CANCELLED, S::ADMIN));
check('it cannot be declined', !S::can(S::CONFIRMED, S::DECLINED, S::SELLER));
check('it cannot expire', !S::can(S::CONFIRMED, S::EXPIRED, S::SYSTEM));
check('it cannot be confirmed again', !S::can(S::CONFIRMED, S::CONFIRMED, S::SELLER));

harness_section('closed bookings');

foreach (array(S::DECLINED, S::CANCELLED, S::EXPIRED) as $closed) {
    check($closed . ' is final', S::isFinal($closed));
    check($closed . ' frees its dates', !S::holds($closed));
    pin('nothing moves a ' . $closed . ' booking', array(), array_merge(S::next($closed, S::SELLER), S::next($closed, S::BUYER), S::next($closed, S::ADMIN), S::next($closed, S::SYSTEM)));
}
check('pending keeps its dates', S::holds(S::PENDING));
check('confirmed keeps its dates', S::holds(S::CONFIRMED));
check('an unknown status moves nowhere', !S::can('paid', S::CONFIRMED, S::ADMIN));
pin('every status has a badge core styles', array('pending', 'paid', 'failed', 'cancelled', 'cancelled'), array_map(array(S::class, 'badge'), S::all()));

exit(harness_result());
