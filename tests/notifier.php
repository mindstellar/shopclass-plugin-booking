<?php
/*
 * This file is part of the Booking Calendar plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The e-mails: who gets one, what it carries, and that each is queued once.
 */

require __DIR__ . '/lib/bootstrap.php';

use mindstellar\booking\Notifier;
use mindstellar\booking\Plugin;

$queued   = array();
$notifier = new Notifier(
    new DateTimeZone('Europe/London'),
    static function (string $type, array $payload, array $options) use (&$queued): void {
        $queued[] = array($type, $payload, $options);
    },
    static fn (string $route, array $query = array()): string => 'https://example.test/' . $route . ($query ? '?' . http_build_query($query) : '')
);
$booking = array(
    'pk_i_id'        => 9,
    'fk_i_item_id'   => 7,
    's_mode'         => 'nights',
    'dt_start'       => '2026-10-19 23:00:00',
    'dt_end'         => '2026-10-22 23:00:00',
    's_status'       => 'pending',
    's_name'         => 'Ana <b>',
    's_email'        => 'ana@example.test',
    's_phone'        => '+44 20 7946 0000',
    's_note'         => "Late\n<script>x</script>",
    's_closed_by'    => null,
    's_title'        => 'Cottage & garden',
    's_seller_email' => 'seller@example.test',
    's_seller_name'  => 'Sam',
);

$notifier->booking(Notifier::REQUESTED, $booking);
pin('one mail to each side', 2, count($queued));
[$type, $buyer, $opts] = $queued[0];
pin('sent by the mail job', Notifier::JOB, $type);
pin('the buyer first', 'ana@example.test', $buyer['to']);
pin('queued once per booking, event and person', 'b9:requested:buyer', $opts['unique_key']);
check('the dates are local and counted in nights', strpos($buyer['body'], '2026-10-20 to 2026-10-23 (3 nights)') !== false);
check('the title is escaped', strpos($buyer['body'], 'Cottage &amp; garden') !== false);
check('the buyer can cancel from the mail', strpos($buyer['body'], Plugin::ROUTE_GUEST) !== false && $buyer['secret_link'] === true);
[, $seller, $opts] = $queued[1];
pin('then the seller', 'seller@example.test', $seller['to']);
pin('who can reply to the buyer', 'ana@example.test', $seller['reply_to']);
pin('keyed apart from the buyer', 'b9:requested:seller', $opts['unique_key']);
check('the seller sees the buyer, escaped', strpos($seller['body'], 'Ana &lt;b&gt;') !== false && strpos($seller['body'], '<script>') === false);
check('and a link to answer', strpos($seller['body'], 'Accept or decline the request') !== false);

$queued = array();
$notifier->booking(Notifier::DECLINED, array('s_status' => 'declined', 's_closed_by' => 'seller') + $booking);
check('a closed booking has no cancel link', strpos($queued[0][1]['body'], Plugin::ROUTE_GUEST) === false && $queued[0][1]['secret_link'] === false);
pin('the subject says what happened', 'Booking declined: Cottage & garden', $queued[0][1]['subject']);

$queued = array();
$notifier->booking(Notifier::CANCELLED, array('s_status' => 'cancelled', 's_closed_by' => 'buyer', 's_seller_email' => '') + $booking);
pin('no seller address, one mail', 1, count($queued));

$queued = array();
$notifier->verify(array('item' => 7, 'mode' => 'slots', 'start' => 1_792_000_800, 'end' => 1_792_004_400, 'name' => 'Ana', 'email' => 'ana@example.test'), 'Cottage', 'abc.def');
pin('a guest gets one confirm mail', 1, count($queued));
check('with the link', strpos($queued[0][1]['body'], 'abc.def') !== false && $queued[0][1]['secret_link'] === true);

pin('a slot reads as a day and times', '2026-10-15, 10:00 to 11:00', $notifier->when(array('s_mode' => 'slots', 'dt_start' => '2026-10-15 09:00:00', 'dt_end' => '2026-10-15 10:00:00')));

exit(harness_result());
