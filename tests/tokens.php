<?php
/*
 * This file is part of the Booking Calendar plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Signed links: a guest's request, a cancel link and a calendar feed key.
 */

require __DIR__ . '/lib/bootstrap.php';

use mindstellar\booking\Token;

$now = 1_800_000_000;
$id  = Token::newId();

harness_section('a guest request');

check('the id is random hex', preg_match('/^[a-f0-9]{32}$/', $id) === 1 && $id !== Token::newId());
$token = Token::request($id, $now);
pin('it opens to the id', $id, Token::openRequest($token, $now + 60));
pin('it still works a second before a day is up', $id, Token::openRequest($token, $now + 86399));
pin('it has expired a second after', null, Token::openRequest($token, $now + 86401));
pin('it carries the id and its expiry, nothing else', array('g', 'x'), array_keys((array) json_decode((string) base64_decode(strtr(explode('.', $token)[0], '-_', '+/')), true)));
[$payload, $sig] = explode('.', $token);
$forged          = rtrim(strtr(base64_encode((string) json_encode(array('g' => Token::newId(), 'x' => $now + 999))), '+/', '-_'), '=') . '.' . $sig;
pin('a changed id is refused', null, Token::openRequest($forged, $now));
pin('a broken one is refused', null, Token::openRequest('nonsense', $now));
pin('it is not a cancel link', null, Token::peekCancel($token, $now));

harness_section('a cancel link');

$end    = $now + 5 * 86400;
$cancel = Token::cancel(42, 'Ana@Example.test ', $end, $now);
pin('it names the booking for its address', 42, Token::openCancel($cancel, 'ana@example.test', $now));
pin('not for another address', null, Token::openCancel($cancel, 'eve@example.test', $now));
pin('it works until a day after the booking ends', 42, Token::openCancel($cancel, 'ana@example.test', $end + 86000));
pin('then it expires', null, Token::openCancel($cancel, 'ana@example.test', $end + 86401));
pin('it is not a request', null, Token::openRequest($cancel, $now));
check('the address is not in it', strpos((string) base64_decode(strtr(explode('.', $cancel)[0], '-_', '+/')), 'ana') === false);

harness_section('a calendar feed key');

$key = Token::ical(7, 'aaaaaaaaaaaaaaaa');
check('it is 32 hex characters', preg_match('/^[a-f0-9]{32}$/', $key) === 1);
check('it matches its listing', Token::icalValid(7, 'aaaaaaaaaaaaaaaa', $key));
check('not another listing', !Token::icalValid(8, 'aaaaaaaaaaaaaaaa', $key));
check('a new salt stops it', !Token::icalValid(7, 'bbbbbbbbbbbbbbbb', $key));
check('no salt, no feed', !Token::icalValid(7, '', Token::ical(7, '')));

exit(harness_result());
