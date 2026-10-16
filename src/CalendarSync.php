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

use Closure;
use InvalidArgumentException;
use mindstellar\database\UtcDatetime;
use mindstellar\http\HttpClient;
use mindstellar\http\HttpException;
use RuntimeException;

/**
 * Imports the calendars a seller pasted for a listing and closes their busy dates. Addresses
 * may carry a secret, so they are never logged or written into a result: only the host is.
 */
final class CalendarSync
{
    /** Calendars one listing may import. */
    public const MAX_CALENDARS = 3;

    /** Longest address accepted. */
    public const MAX_URL = 600;

    /** Listings one job run imports before it hands over to the next run. */
    public const BATCH = 10;

    /** Seconds before a listing's calendars are read again. */
    public const EVERY = 3000;

    /** Largest calendar file read, in bytes. */
    public const MAX_BYTES = 2 * 1024 * 1024;

    /** Private, loopback, link-local, shared and reserved networks, as [address, prefix length]. */
    private const BLOCKED_NETS = array(
        array('0.0.0.0', 8), array('10.0.0.0', 8), array('100.64.0.0', 10), array('127.0.0.0', 8),
        array('169.254.0.0', 16), array('172.16.0.0', 12), array('192.0.0.0', 24), array('192.0.2.0', 24),
        array('192.168.0.0', 16), array('198.18.0.0', 15), array('198.51.100.0', 24), array('203.0.113.0', 24),
        array('224.0.0.0', 4), array('240.0.0.0', 4),
        array('::', 128), array('::1', 128), array('fc00::', 7), array('fe80::', 10), array('fec0::', 10),
        array('ff00::', 8), array('2001:db8::', 32), array('100::', 64),
    );

    private BookingStore $store;
    private Config $config;
    private HttpClient $http;
    private string $ownHost;

    /** fn(string $host): string[] -- the host's IPv4 and IPv6 addresses. Tests replace it. */
    private Closure $resolver;

    /** fn(): int */
    private Closure $clock;

    public function __construct(BookingStore $store, Config $config, HttpClient $http, string $ownHost, ?Closure $resolver = null, ?Closure $clock = null)
    {
        $this->store    = $store;
        $this->config   = $config;
        $this->http     = $http;
        $this->ownHost  = $ownHost;
        $this->resolver = $resolver ?? static function (string $host): array {
            $ips = gethostbynamel($host) ?: array();
            foreach ((array) @dns_get_record($host, DNS_AAAA) as $record) {
                if (is_array($record) && isset($record['ipv6'])) {
                    $ips[] = (string) $record['ipv6'];
                }
            }

            return $ips;
        };
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * This site's host name, which a calendar address may not point at.
     */
    public static function siteHost(): string
    {
        return function_exists('osc_base_url') ? (string) (parse_url((string) osc_base_url(), PHP_URL_HOST) ?: '') : '';
    }

    /**
     * The addresses a seller typed, one per line, checked and with webcal:// turned into https://.
     *
     * @return string[]
     * @throws BookingException 'input' naming the line that is wrong
     */
    public static function parseList(string $text, string $ownHost): array
    {
        $urls = array();
        foreach (preg_split('/\R/', $text) ?: array() as $line) {
            $line = trim($line);
            if ($line !== '' && !in_array(self::normalise($line), $urls, true)) {
                $urls[] = self::normalise($line);
            }
        }
        if (count($urls) > self::MAX_CALENDARS) {
            throw new BookingException('input', sprintf(__('You can import up to %d calendars.', 'booking'), self::MAX_CALENDARS));
        }
        foreach ($urls as $n => $url) {
            $why = self::problem($url, $ownHost);
            if ($why !== null) {
                throw new BookingException('input', sprintf(__('Calendar address %1$d: %2$s', 'booking'), $n + 1, $why));
            }
        }

        return $urls;
    }

    /**
     * An address with webcal:// turned into https://.
     */
    public static function normalise(string $url): string
    {
        $url = trim($url);

        return preg_match('#^webcals?://#i', $url) ? 'https://' . (string) preg_replace('#^webcals?://#i', '', $url) : $url;
    }

    /**
     * Why an address cannot be imported, or null when it can. Only the address itself is
     * checked here; where its host points is checked when it is read.
     */
    public static function problem(string $url, string $ownHost): ?string
    {
        if (strlen($url) > self::MAX_URL) {
            return sprintf(__('the address is longer than %d characters.', 'booking'), self::MAX_URL);
        }
        if (!preg_match('#^https://#i', $url)) {
            return __('use an address that starts with https:// or webcal://.', 'booking');
        }
        $parts = preg_match('/[\s\x00-\x1F\x7F]/', $url) ? false : parse_url($url);
        $host  = is_array($parts) ? self::host((string) ($parts['host'] ?? '')) : '';
        if ($host === '' || isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && (int) $parts['port'] !== 443)) {
            return __('that is not a calendar address.', 'booking');
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return self::publicIp($host) ? null : __('it points to a private network.', 'booking');
        }
        if (!preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)+$/', $host) || preg_match('/\.(localhost|local|internal|localdomain|home\.arpa)$/', $host)) {
            return __('that is not a calendar address.', 'booking');
        }
        if ($ownHost !== '' && $host === self::host($ownHost)) {
            return __('it is this site\'s own address. Paste the calendar from the other site.', 'booking');
        }

        return null;
    }

    /**
     * Whether an IP address is on the public internet.
     */
    public static function publicIp(string $ip): bool
    {
        $ip = self::host($ip);
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        $bin = (string) @inet_pton($ip);
        if ($bin === '') {
            return false;
        }
        // An IPv4 address inside IPv6 (::ffff:a.b.c.d, 64:ff9b::a.b.c.d) is judged as IPv4.
        if (strlen($bin) === 16 && (strncmp($bin, str_repeat("\0", 10) . "\xff\xff", 12) === 0 || strncmp($bin, "\x00\x64\xff\x9b" . str_repeat("\0", 8), 12) === 0)) {
            return self::publicIp((string) inet_ntop(substr($bin, 12)));
        }
        foreach (self::BLOCKED_NETS as [$net, $bits]) {
            $netBin = (string) inet_pton($net);
            if (strlen($netBin) === strlen($bin) && self::inNet($bin, $netBin, $bits)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Import the calendars of one listing, or of the next batch due. Does nothing while the
     * admin has the import switched off.
     *
     * @return int how many listings were imported
     */
    public function run(int $itemId = 0): int
    {
        if (!$this->config->import) {
            return 0;
        }
        if ($itemId > 0) {
            $listing = $this->store->listing($itemId);
            if ($listing === null || (int) $listing['b_enabled'] !== 1 || (string) ($listing['s_import'] ?? '') === '') {
                return 0;
            }
            $this->listing($itemId, (string) $listing['s_import']);

            return 1;
        }
        $rows = $this->store->importDue(($this->clock)() - self::EVERY, self::BATCH);
        foreach ($rows as $row) {
            $this->listing((int) $row['fk_i_item_id'], (string) $row['s_import']);
        }

        return count($rows);
    }

    /**
     * Read every calendar of a listing and close their dates. When one cannot be read, the
     * dates imported before are kept, as a fault there says nothing about the dates.
     *
     * @return string the result, as the seller sees it
     */
    public function listing(int $itemId, string $import): string
    {
        $tz             = $this->config->tz();
        [$today, $last] = Availability::window(($this->clock)(), $this->config->maxDays, $tz);
        $urls           = array_values(array_filter(array_map('trim', explode("\n", $import)), static fn (string $u): bool => $u !== ''));
        $dates          = array();
        foreach (array_slice($urls, 0, self::MAX_CALENDARS) as $n => $url) {
            try {
                $body = $this->fetch($url);
            } catch (RuntimeException $e) {
                $status = sprintf(
                    __('Could not read calendar %1$d (%2$s): %3$s Dates imported before are kept.', 'booking'),
                    $n + 1,
                    self::host((string) parse_url($url, PHP_URL_HOST)),
                    $e->getMessage()
                );

                return $this->done($itemId, $status);
            }
            foreach (IcalImport::parse($body, $tz, $today, $last) as $date) {
                $dates[$date] = true;
            }
        }
        $this->store->replaceImported($itemId, array_keys($dates), $today);

        return $this->done($itemId, sprintf(__('Calendars read: %1$d. Dates closed: %2$d.', 'booking'), count($urls), count($dates)));
    }

    /**
     * One calendar's text.
     *
     * @throws RuntimeException with a reason that names no part of the address
     */
    public function fetch(string $url): string
    {
        $url = self::normalise($url);
        $why = self::problem($url, $this->ownHost);
        if ($why !== null) {
            throw new RuntimeException($why);
        }
        $host = self::host((string) parse_url($url, PHP_URL_HOST));
        $ips  = filter_var($host, FILTER_VALIDATE_IP) !== false ? array($host) : ($this->resolver)($host);
        if ($ips === array()) {
            throw new RuntimeException(__('the address could not be found.', 'booking'));
        }
        foreach ($ips as $ip) {
            if (!self::publicIp((string) $ip)) {
                throw new RuntimeException(__('it points to a private network.', 'booking'));
            }
        }
        try {
            $answer = $this->http->send('GET', $url, array('Accept' => 'text/calendar, */*'), null, array(
                'connect_timeout' => 5,
                'timeout'         => 10,
                'max_bytes'       => self::MAX_BYTES,
            ));
        } catch (HttpException $e) {
            throw new RuntimeException(__('no answer came, or the file is over 2 MB.', 'booking'));
        } catch (InvalidArgumentException $e) {
            throw new RuntimeException(__('that is not a calendar address.', 'booking'));
        }
        if ((int) $answer['status'] !== 200) {
            throw new RuntimeException(sprintf(__('the other site answered with error %d.', 'booking'), (int) $answer['status']));
        }
        if (stripos((string) $answer['body'], 'BEGIN:VCALENDAR') === false) {
            throw new RuntimeException(__('the address does not give a calendar file.', 'booking'));
        }

        return (string) $answer['body'];
    }

    private function done(int $itemId, string $status): string
    {
        $status = function_exists('mb_substr') ? mb_substr($status, 0, 255) : substr($status, 0, 255);
        $this->store->importDone($itemId, $status, UtcDatetime::format(($this->clock)()));

        return $status;
    }

    /**
     * A host name in lower case, without brackets or a trailing dot.
     */
    private static function host(string $host): string
    {
        return rtrim(strtolower(trim($host, '[] ')), '.');
    }

    private static function inNet(string $ip, string $net, int $bits): bool
    {
        $bytes = intdiv($bits, 8);
        if (strncmp($ip, $net, $bytes) !== 0) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($ip[$bytes]) & $mask) === (ord($net[$bytes]) & $mask);
    }
}
