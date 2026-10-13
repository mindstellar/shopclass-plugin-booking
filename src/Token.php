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

use mindstellar\security\SignedPayload;
use mindstellar\security\SigningKey;

/**
 * Links sent by e-mail, signed with the site's key: a guest confirming a request, a guest
 * cancelling a booking, and a listing's calendar feed.
 */
final class Token
{
    public const REQUEST = 'booking-request';
    public const CANCEL  = 'booking-cancel';

    /** How long a guest has to confirm a request. */
    public const REQUEST_TTL = 86400;

    /**
     * A guest's request, carried whole in the link so nothing is stored until it is confirmed.
     *
     * @param array<string,mixed> $request item, start, end, name, email, phone, note
     */
    public static function request(array $request, ?int $now = null): string
    {
        return SignedPayload::pack(self::REQUEST, $request, self::REQUEST_TTL, 0, $now);
    }

    /**
     * The request in a link, or null when it is forged or expired.
     *
     * @return array<string,mixed>|null
     */
    public static function openRequest(string $token, ?int $now = null): ?array
    {
        return SignedPayload::unpack(self::REQUEST, $token, $now);
    }

    /**
     * A link that cancels one booking, valid until a day after it ends. It names the e-mail it
     * was sent to, so it stops working for a booking re-used under another address.
     */
    public static function cancel(int $bookingId, string $email, int $end, ?int $now = null): string
    {
        $now = $now ?? time();

        return SignedPayload::pack(self::CANCEL, array('b' => $bookingId, 'e' => self::emailTag($email)), max(3600, $end - $now + 86400), 0, $now);
    }

    /**
     * The booking id a cancel link names, or null when it is forged, expired or for another address.
     */
    public static function openCancel(string $token, string $email, ?int $now = null): ?int
    {
        $data = SignedPayload::unpack(self::CANCEL, $token, $now);
        if ($data === null || !hash_equals(self::emailTag($email), (string) ($data['e'] ?? ''))) {
            return null;
        }

        return (int) ($data['b'] ?? 0) ?: null;
    }

    /**
     * The booking id a cancel link names, before its address is checked.
     */
    public static function peekCancel(string $token, ?int $now = null): ?int
    {
        $data = SignedPayload::unpack(self::CANCEL, $token, $now);

        return $data === null ? null : ((int) ($data['b'] ?? 0) ?: null);
    }

    /**
     * The key in a listing's calendar feed address. A new $salt makes the old address stop working.
     */
    public static function ical(int $itemId, string $salt): string
    {
        return substr(hash_hmac('sha256', 'booking-ical|' . $itemId . '|' . $salt, (string) SigningKey::get()), 0, 32);
    }

    /**
     * Whether $key is the feed key of this listing.
     */
    public static function icalValid(int $itemId, string $salt, string $key): bool
    {
        return $salt !== '' && hash_equals(self::ical($itemId, $salt), $key);
    }

    /**
     * A short fingerprint of an address, so a link does not carry it in the clear.
     */
    private static function emailTag(string $email): string
    {
        return substr(hash('sha256', strtolower(trim($email))), 0, 12);
    }
}
