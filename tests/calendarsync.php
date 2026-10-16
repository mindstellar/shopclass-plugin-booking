<?php
/*
 * This file is part of the Booking Calendar plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Calendar addresses a seller pastes: what is accepted, and that nothing reaches this
 * site, a private network or a plain-HTTP server.
 */

require __DIR__ . '/lib/bootstrap.php';

use mindstellar\booking\BookingException;
use mindstellar\booking\BookingStore;
use mindstellar\booking\CalendarSync;
use mindstellar\booking\Config;
use mindstellar\http\HttpClient;
use mindstellar\http\HttpException;

final class FakeHttp implements HttpClient
{
    /** @var array<int,string> */
    public array $asked = array();

    /** @var array{status:int,headers:array<string,string>,body:string}|HttpException */
    public $answer;

    public function send(string $method, string $url, array $headers = [], ?string $body = null, array $options = []): array
    {
        $this->asked[] = $method . ' ' . $url . ' ' . json_encode($options);
        if ($this->answer instanceof HttpException) {
            throw $this->answer;
        }

        return $this->answer;
    }
}

$own   = 'shop.example.com';
$why   = static fn (string $url): ?string => CalendarSync::problem(CalendarSync::normalise($url), $own);
$error = static function (string $text) use ($own): string {
    try {
        CalendarSync::parseList($text, $own);
    } catch (BookingException $e) {
        return $e->getMessage();
    }

    return 'none';
};

harness_section('addresses a seller saves');

pin('an https address is fine', null, $why('https://www.airbnb.com/calendar/ical/123.ics?s=abc'));
pin('webcal:// becomes https://', 'https://calendar.google.com/x/basic.ics', CalendarSync::normalise('webcal://calendar.google.com/x/basic.ics'));
pin('so a webcal address is fine', null, $why('webcal://calendar.google.com/x/basic.ics'));
check('plain http is refused', $why('http://www.airbnb.com/calendar.ics') !== null);
check('ftp is refused', $why('ftp://example.com/a.ics') !== null);
check('a file path is refused', $why('file:///etc/passwd') !== null);
check('this site is refused', strpos((string) $why('https://SHOP.example.com./booking/ical/1/abc.ics'), 'own address') !== false);
check('localhost is refused', $why('https://localhost/a.ics') !== null);
check('a .local name is refused', $why('https://printer.local/a.ics') !== null);
check('a loopback address is refused', $why('https://127.0.0.1/a.ics') !== null);
check('a private address is refused', $why('https://10.1.2.3/a.ics') !== null && $why('https://192.168.1.1/a.ics') !== null);
check('the cloud metadata address is refused', $why('https://169.254.169.254/latest') !== null);
check('IPv6 loopback is refused', $why('https://[::1]/a.ics') !== null);
check('a public IP is fine', $why('https://93.184.216.34/a.ics') === null);
check('a password in the address is refused', $why('https://user:pw@example.com/a.ics') !== null);
check('another port is refused', $why('https://example.com:8080/a.ics') !== null);
check('port 443 is fine', $why('https://example.com:443/a.ics') === null);
check('a space in the address is refused', $why('https://example.com/a b.ics') !== null);
check('an over-long address is refused', $why('https://example.com/' . str_repeat('a', CalendarSync::MAX_URL)) !== null);
pin('blank lines and repeats are dropped', array('https://a.example.com/1.ics', 'https://b.example.com/2.ics'), CalendarSync::parseList("\n https://a.example.com/1.ics \r\n\r\nhttps://a.example.com/1.ics\nwebcal://b.example.com/2.ics\n", $own));
pin('an empty box is no calendars', array(), CalendarSync::parseList("  \n", $own));
check('four calendars are refused', strpos($error("https://a.example.com/1\nhttps://a.example.com/2\nhttps://a.example.com/3\nhttps://a.example.com/4"), 'up to 3') !== false);
check('the refusal names the line', strpos($error("https://a.example.com/1\nhttp://a.example.com/2"), 'Calendar address 2') === 0);

harness_section('which IP addresses are public');

foreach (array('127.0.0.1', '10.0.0.1', '172.16.5.4', '192.168.0.1', '169.254.1.1', '100.64.0.1', '100.127.255.255', '0.0.0.0', '224.0.0.1', '255.255.255.255',
    '::1', '::', 'fc00::1', 'fd12:3456::1', 'fe80::1', 'febf::1', 'ff02::1', '::ffff:127.0.0.1', '::ffff:10.0.0.1', '64:ff9b::7f00:1', '2001:db8::1') as $ip) {
    pin($ip . ' is not public', false, CalendarSync::publicIp($ip));
}
foreach (array('8.8.8.8', '1.1.1.1', '100.128.0.1', '2606:4700:4700::1111', '::ffff:8.8.8.8') as $ip) {
    pin($ip . ' is public', true, CalendarSync::publicIp($ip));
}

harness_section('reading a calendar');

$http  = new FakeHttp();
$dns   = array('cal.example.com' => array('93.184.216.34'), 'inside.example.com' => array('93.184.216.34', '10.0.0.5'), 'v6.example.com' => array('fe80::1'));
$sync  = new CalendarSync(new BookingStore(), new Config(array()), $http, $own, static fn (string $host): array => $dns[$host] ?? array());
$fail  = static function (string $url) use ($sync): string {
    try {
        $sync->fetch($url);
    } catch (RuntimeException $e) {
        return $e->getMessage();
    }

    return 'none';
};
$http->answer = array('status' => 200, 'headers' => array(), 'body' => "BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n");
pin('a public host is read', "BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n", $sync->fetch('webcal://cal.example.com/a.ics?key=secret'));
check('over https, with a 10 s timeout and a 2 MB cap', strpos($http->asked[0], 'GET https://cal.example.com/a.ics?key=secret {"connect_timeout":5,"timeout":10,"max_bytes":2097152}') === 0, $http->asked[0]);
$http->asked = array();
check('a host with any private address is refused', strpos($fail('https://inside.example.com/a.ics'), 'private') !== false);
check('so is one that points to IPv6 link-local', strpos($fail('https://v6.example.com/a.ics'), 'private') !== false);
check('a host that does not resolve is refused', strpos($fail('https://nowhere.example.com/a.ics'), 'could not be found') !== false);
check('plain http is refused when read too', $fail('http://cal.example.com/a.ics') !== 'none');
check('and this site', $fail('https://shop.example.com/a.ics') !== 'none');
pin('none of those reached the network', array(), $http->asked);
$http->answer = array('status' => 404, 'headers' => array(), 'body' => 'BEGIN:VCALENDAR');
check('an answer other than 200 is an error', strpos($fail('https://cal.example.com/a.ics'), '404') !== false);
$http->answer = array('status' => 200, 'headers' => array(), 'body' => '<html>Sign in</html>');
check('a page that is not a calendar is an error', strpos($fail('https://cal.example.com/a.ics'), 'calendar file') !== false);
$http->answer = new HttpException('cal.example.com could not be reached');
$msg = $fail('https://cal.example.com/a.ics?key=secret');
check('no answer is an error', $msg !== 'none');
check('and no error carries the address', strpos($msg, 'secret') === false && strpos($msg, '/a.ics') === false);

exit(harness_result());
