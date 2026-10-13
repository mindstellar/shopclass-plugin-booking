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

/**
 * A member's bookings in core's personal data export, and their removal with the account.
 * Only the member's own requests as a buyer: requests on their listings are other people's data.
 */
final class Privacy
{
    /** Key of the plugin's section in the export. */
    public const SECTION = 'booking_requests';

    /**
     * The personal_data_export filter.
     *
     * @param mixed $out
     * @param mixed $userId
     *
     * @return mixed
     */
    public static function export($out, $userId = 0)
    {
        if (!is_array($out)) {
            return $out;
        }
        $out['data'][self::SECTION] = array(
            'retention' => 'deleted',
            'why'       => 'Booking requests you sent, from your account or as a guest with its e-mail address. Your details are removed from them when the account is deleted.',
            'rows'      => Plugin::service()->store()->buyerRows((int) $userId, self::email((int) $userId)),
        );

        return $out;
    }

    /**
     * The delete_user hook, which core fires before the account row is removed.
     *
     * @param mixed $userId
     */
    public static function deleteUser($userId): void
    {
        Plugin::service()->forgetBuyer((int) $userId);
    }

    /**
     * The account's address, while it still exists.
     */
    private static function email(int $userId): string
    {
        return Plugin::service()->store()->accountEmail($userId);
    }
}
