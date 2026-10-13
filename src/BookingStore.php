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

use mindstellar\database\Db;
use mindstellar\database\DbException;
use mindstellar\database\UtcDatetime;

/**
 * The plugin's tables. Every value is bound; times go in and out as UTC.
 */
final class BookingStore
{
    /** Table names, without DB_TABLE_PREFIX. */
    public const TABLES = array('t_booking', 't_booking_blocked', 't_booking_listing');

    /** MySQL's duplicate-key error. */
    private const DUPLICATE = 1062;

    private string $locale;

    /**
     * @param string $locale the language listing titles are read in, when the listing has it
     */
    public function __construct(string $locale = '')
    {
        $this->locale = $locale;
    }

    private static function t(string $table): string
    {
        return DB_TABLE_PREFIX . $table;
    }

    /**
     * A listing's booking settings, or null when the seller never set any.
     *
     * @return array<string,mixed>|null
     */
    public function listing(int $itemId): ?array
    {
        return Db::selectOne('SELECT * FROM ' . self::t('t_booking_listing') . ' WHERE fk_i_item_id = ?', array($itemId));
    }

    /**
     * Save a listing's booking settings and move its version on, so cached calendars are dropped.
     *
     * @param array{enabled:bool,auto_confirm:bool,min_nights:int,slot_minutes:int,hours:string,price_note:string} $s
     */
    public function saveListing(int $itemId, array $s): void
    {
        Db::execute(
            'INSERT INTO ' . self::t('t_booking_listing')
            . ' (fk_i_item_id, b_enabled, b_auto_confirm, i_min_nights, i_slot_minutes, s_hours, s_price_note, s_ical_salt, i_version, dt_updated)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?)'
            . ' ON DUPLICATE KEY UPDATE b_enabled = VALUES(b_enabled), b_auto_confirm = VALUES(b_auto_confirm),'
            . ' i_min_nights = VALUES(i_min_nights), i_slot_minutes = VALUES(i_slot_minutes), s_hours = VALUES(s_hours),'
            . ' s_price_note = VALUES(s_price_note), i_version = i_version + 1, dt_updated = VALUES(dt_updated)',
            array(
                $itemId,
                $s['enabled'] ? 1 : 0,
                $s['auto_confirm'] ? 1 : 0,
                $s['min_nights'],
                $s['slot_minutes'],
                $s['hours'],
                $s['price_note'],
                bin2hex(random_bytes(8)),
                UtcDatetime::format(time()),
            )
        );
    }

    /**
     * Give a listing a new calendar feed address; the old one stops working.
     */
    public function newIcalSalt(int $itemId): void
    {
        Db::execute(
            'UPDATE ' . self::t('t_booking_listing') . ' SET s_ical_salt = ? WHERE fk_i_item_id = ?',
            array(bin2hex(random_bytes(8)), $itemId)
        );
    }

    /**
     * Move a listing's version on, so a calendar cached under the old one is not served again.
     */
    public function bump(int $itemId): void
    {
        Db::execute(
            'UPDATE ' . self::t('t_booking_listing') . ' SET i_version = i_version + 1, dt_updated = ? WHERE fk_i_item_id = ?',
            array(UtcDatetime::format(time()), $itemId)
        );
    }

    /**
     * Dates the seller closed between $from and $to, both included.
     *
     * @return string[]
     */
    public function blocked(int $itemId, string $from, string $to): array
    {
        $rows = Db::select(
            'SELECT d_date FROM ' . self::t('t_booking_blocked') . ' WHERE fk_i_item_id = ? AND d_date BETWEEN ? AND ? ORDER BY d_date',
            array($itemId, $from, $to)
        );

        return array_map(static fn (array $r): string => (string) $r['d_date'], $rows);
    }

    /**
     * Replace the closed dates from $today on. Past ones stay as they were.
     *
     * @param string[] $dates
     */
    public function setBlocked(int $itemId, array $dates, string $today): void
    {
        Db::transaction(function () use ($itemId, $dates, $today): void {
            Db::execute('DELETE FROM ' . self::t('t_booking_blocked') . ' WHERE fk_i_item_id = ? AND d_date >= ?', array($itemId, $today));
            $dates = array_values(array_filter($dates, static fn (string $d): bool => $d >= $today));
            foreach (array_chunk($dates, 200) as $chunk) {
                $params = array();
                foreach ($chunk as $date) {
                    $params[] = $itemId;
                    $params[] = $date;
                }
                Db::execute(
                    'INSERT IGNORE INTO ' . self::t('t_booking_blocked') . ' (fk_i_item_id, d_date) VALUES '
                    . implode(', ', array_fill(0, count($chunk), '(?, ?)')),
                    $params
                );
            }
        });
        $this->bump($itemId);
    }

    /**
     * Bookings that keep time between $from and $to, as [start, end] Unix times.
     *
     * @return array<int,array{0:int,1:int}>
     */
    public function holds(int $itemId, int $from, int $to, bool $lock = false): array
    {
        $rows = Db::select(
            'SELECT dt_start, dt_end FROM ' . self::t('t_booking')
            . ' WHERE fk_i_item_id = ? AND b_hold = 1 AND dt_end > ? AND dt_start < ?' . ($lock ? ' FOR UPDATE' : ''),
            array($itemId, UtcDatetime::format($from), UtcDatetime::format($to))
        );

        return array_map(static fn (array $r): array => array((int) UtcDatetime::parse($r['dt_start']), (int) UtcDatetime::parse($r['dt_end'])), $rows);
    }

    /**
     * Add a booking. The listing's settings row is locked first, so two requests for one
     * listing take turns, then the rows that would overlap are read under lock.
     *
     * @param array{item:int,user:?int,mode:string,start:int,end:int,status:string,name:string,email:string,phone:string,note:string} $b
     *
     * @return int the new booking's id
     * @throws BookingException 'off' when the listing takes no bookings, 'taken' when the time is gone
     */
    public function create(array $b): int
    {
        try {
            return Db::transaction(function () use ($b): int {
                $listing = Db::selectOne(
                    'SELECT b_enabled FROM ' . self::t('t_booking_listing') . ' WHERE fk_i_item_id = ? FOR UPDATE',
                    array($b['item'])
                );
                if ($listing === null || (int) $listing['b_enabled'] !== 1) {
                    throw new BookingException('off');
                }
                if ($this->holds($b['item'], $b['start'], $b['end'], true) !== array()) {
                    throw new BookingException('taken');
                }
                $now = UtcDatetime::format(time());
                $id  = Db::insertGetId(
                    'INSERT INTO ' . self::t('t_booking')
                    . ' (fk_i_item_id, fk_i_user_id, s_mode, dt_start, dt_end, s_status, b_hold, s_name, s_email, s_phone, s_note, dt_created, dt_updated)'
                    . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    array(
                        $b['item'],
                        $b['user'] ?: null,
                        $b['mode'],
                        UtcDatetime::format($b['start']),
                        UtcDatetime::format($b['end']),
                        $b['status'],
                        Status::holds($b['status']) ? 1 : null,
                        $b['name'],
                        $b['email'],
                        $b['phone'],
                        $b['note'],
                        $now,
                        $now,
                    )
                );
                $this->bump($b['item']);

                return $id;
            });
        } catch (DbException $e) {
            if ($e->getCode() === self::DUPLICATE) {
                throw new BookingException('taken');
            }
            throw $e;
        }
    }

    /**
     * Move a booking to $to. The listing's row is locked before the booking's, the same order
     * create() takes, so the two never wait on each other.
     *
     * @param callable|null $allow fn(array $booking): bool, a last check under lock, such as ownership
     *
     * @return array<string,mixed> the booking as it now is
     * @throws BookingException 'not_found', 'owner' or 'status'
     */
    public function transition(int $id, string $to, string $actor, ?callable $allow = null): array
    {
        $itemId = (int) Db::scalar('SELECT fk_i_item_id FROM ' . self::t('t_booking') . ' WHERE pk_i_id = ?', array($id));
        if ($itemId <= 0) {
            throw new BookingException('not_found');
        }

        return Db::transaction(function () use ($id, $to, $actor, $allow, $itemId): array {
            Db::selectOne('SELECT fk_i_item_id FROM ' . self::t('t_booking_listing') . ' WHERE fk_i_item_id = ? FOR UPDATE', array($itemId));
            $row = Db::selectOne('SELECT * FROM ' . self::t('t_booking') . ' WHERE pk_i_id = ? FOR UPDATE', array($id));
            if ($row === null) {
                throw new BookingException('not_found');
            }
            if ($allow !== null && !$allow($row)) {
                throw new BookingException('owner');
            }
            if (!Status::can((string) $row['s_status'], $to, $actor)) {
                throw new BookingException('status');
            }
            $now = UtcDatetime::format(time());
            Db::execute(
                'UPDATE ' . self::t('t_booking') . ' SET s_status = ?, b_hold = ?, s_closed_by = ?, dt_updated = ? WHERE pk_i_id = ?',
                array($to, Status::holds($to) ? 1 : null, Status::holds($to) ? null : $actor, $now, $id)
            );
            $this->bump($itemId);
            $row['s_status']    = $to;
            $row['b_hold']      = Status::holds($to) ? '1' : null;
            $row['s_closed_by'] = Status::holds($to) ? null : $actor;
            $row['dt_updated']  = $now;

            return $row;
        });
    }

    /**
     * One booking with its listing's title and owner, or null.
     *
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        $rows = $this->bookings('b.pk_i_id = ?', array($id), 1, 0);

        return $rows[0] ?? null;
    }

    /**
     * Pending requests asked before $askedBefore, or starting before $startsBefore.
     *
     * @return int[]
     */
    public function due(int $askedBefore, int $startsBefore, int $limit = 200): array
    {
        $rows = Db::select(
            'SELECT pk_i_id FROM ' . self::t('t_booking') . ' WHERE s_status = ? AND (dt_created < ? OR dt_start < ?) ORDER BY pk_i_id LIMIT ?',
            array(Status::PENDING, UtcDatetime::format($askedBefore), UtcDatetime::format($startsBefore), $limit)
        );

        return array_map(static fn (array $r): int => (int) $r['pk_i_id'], $rows);
    }

    /**
     * Requests for listings $userId owns, newest dates first.
     *
     * @return array<int,array<string,mixed>>
     */
    public function forSeller(int $userId, string $status = '', int $limit = 50, int $offset = 0): array
    {
        $where  = 'i.fk_i_user_id = ?';
        $params = array($userId);
        if ($status !== '') {
            $where   .= ' AND b.s_status = ?';
            $params[] = $status;
        }

        return $this->bookings($where, $params, $limit, $offset);
    }

    /**
     * Bookings $userId made, newest dates first.
     *
     * @return array<int,array<string,mixed>>
     */
    public function forBuyer(int $userId, int $limit = 50): array
    {
        return $this->bookings('b.fk_i_user_id = ?', array($userId), $limit, 0);
    }

    /**
     * Every booking, for the admin, newest first.
     *
     * @return array<int,array<string,mixed>>
     */
    public function forAdmin(string $status, int $limit, int $offset): array
    {
        return $status === ''
            ? $this->bookings('1 = 1', array(), $limit, $offset, 'b.pk_i_id DESC')
            : $this->bookings('b.s_status = ?', array($status), $limit, $offset, 'b.pk_i_id DESC');
    }

    /**
     * How many bookings there are in each status.
     *
     * @return array<string,int>
     */
    public function countByStatus(): array
    {
        $out = array_fill_keys(Status::all(), 0);
        foreach (Db::select('SELECT s_status, COUNT(*) AS n FROM ' . self::t('t_booking') . ' GROUP BY s_status') as $row) {
            $out[(string) $row['s_status']] = (int) $row['n'];
        }

        return $out;
    }

    /**
     * Bookings that keep time on a listing and end after $after, for its calendar feed.
     *
     * @return array<int,array<string,mixed>>
     */
    public function feed(int $itemId, int $after, int $limit = 1000): array
    {
        return Db::select(
            'SELECT pk_i_id, s_mode, dt_start, dt_end, s_status, dt_updated FROM ' . self::t('t_booking')
            . ' WHERE fk_i_item_id = ? AND b_hold = 1 AND dt_end > ? ORDER BY dt_start LIMIT ?',
            array($itemId, UtcDatetime::format($after), $limit)
        );
    }

    /**
     * How many requests a buyer has waiting: by account, or by address for a guest, on one
     * listing or, with $itemId 0, on all.
     */
    public function countOpen(int $userId, string $email, int $itemId = 0): int
    {
        $sql    = 'SELECT COUNT(*) FROM ' . self::t('t_booking') . ' WHERE s_status = ?';
        $params = array(Status::PENDING);
        if ($userId > 0) {
            $sql     .= ' AND fk_i_user_id = ?';
            $params[] = $userId;
        } else {
            $sql     .= ' AND fk_i_user_id IS NULL AND s_email = ?';
            $params[] = strtolower($email);
        }
        if ($itemId > 0) {
            $sql     .= ' AND fk_i_item_id = ?';
            $params[] = $itemId;
        }

        return (int) Db::scalar($sql, $params);
    }

    /**
     * Keep a guest's request until they confirm it, under a random id.
     *
     * @param array<string,mixed> $request
     */
    public function keepGuest(string $id, array $request, int $ttl): void
    {
        osc_kv_set(Plugin::FOLDER, 'guest:' . $id, $request, $ttl);
    }

    /**
     * A guest's kept request, or null once it expired or was used.
     *
     * @return array<string,mixed>|null
     */
    public function guest(string $id): ?array
    {
        $request = osc_kv_get(Plugin::FOLDER, 'guest:' . $id);

        return is_array($request) ? $request : null;
    }

    /**
     * Whether a guest's request was already booked.
     */
    public function guestUsed(string $id): bool
    {
        return osc_kv_get(Plugin::FOLDER, 'used:' . $id, false) !== false;
    }

    /**
     * Take a guest's request for booking: true for exactly one caller, which also removes it.
     */
    public function claimGuest(string $id, int $ttl): bool
    {
        if (!osc_kv_claim(Plugin::FOLDER, 'used:' . $id, $ttl)) {
            return false;
        }
        osc_kv_delete(Plugin::FOLDER, 'guest:' . $id);

        return true;
    }

    /**
     * A person's own bookings as a buyer: made from their account, or as a guest with its address.
     *
     * @return array<int,array<string,mixed>>
     */
    public function buyerRows(int $userId, string $email): array
    {
        return Db::select(
            'SELECT b.pk_i_id, b.fk_i_item_id, ' . $this->titleSql('b.fk_i_item_id') . ' AS s_title,'
            . ' b.s_mode, b.dt_start, b.dt_end, b.s_status, b.s_name, b.s_email, b.s_phone, b.s_note, b.dt_created, b.dt_updated'
            . ' FROM ' . self::t('t_booking') . ' b WHERE b.fk_i_user_id = ? OR (b.fk_i_user_id IS NULL AND ? <> \'\' AND b.s_email = ?)'
            . ' ORDER BY b.pk_i_id',
            array_merge($this->titleParams(), array($userId, $email, strtolower($email)))
        );
    }

    /**
     * Strip a deleted account's details from its bookings as a buyer. Ones still holding dates
     * are cancelled, as nobody can be reached about them; the seller keeps the dates' history.
     *
     * @return int how many bookings were changed
     */
    public function anonymiseBuyer(int $userId, string $email): int
    {
        $where  = 'fk_i_user_id = ? OR (fk_i_user_id IS NULL AND ? <> \'\' AND s_email = ?)';
        $params = array($userId, $email, strtolower($email));
        $items  = Db::select('SELECT DISTINCT fk_i_item_id FROM ' . self::t('t_booking') . ' WHERE b_hold = 1 AND (' . $where . ')', $params);
        $now    = UtcDatetime::format(time());
        Db::execute(
            'UPDATE ' . self::t('t_booking') . ' SET s_status = ?, b_hold = NULL, s_closed_by = ?, dt_updated = ?'
            . ' WHERE b_hold = 1 AND (' . $where . ')',
            array_merge(array(Status::CANCELLED, Status::SYSTEM, $now), $params)
        );
        $changed = Db::execute(
            'UPDATE ' . self::t('t_booking') . ' SET fk_i_user_id = NULL, s_name = ?, s_email = \'\', s_phone = \'\', s_note = \'\''
            . ' WHERE ' . $where,
            array_merge(array(__('Deleted user', 'booking')), $params)
        );
        foreach ($items as $row) {
            $this->bump((int) $row['fk_i_item_id']);
        }

        return $changed;
    }

    /**
     * An account's e-mail address in lower case, or '' when there is no such account.
     */
    public function accountEmail(int $userId): string
    {
        return strtolower((string) Db::scalar('SELECT s_email FROM ' . self::t('t_user') . ' WHERE pk_i_id = ?', array($userId)));
    }

    /**
     * The listing core holds, with its title, or null.
     *
     * @return array<string,mixed>|null
     */
    public function item(int $itemId): ?array
    {
        return Db::selectOne(
            'SELECT i.pk_i_id, i.fk_i_user_id, i.fk_i_category_id, i.s_contact_name, i.s_contact_email,'
            . ' i.b_enabled, i.b_active, i.b_spam, ' . $this->titleSql('i.pk_i_id') . ' AS s_title'
            . ' FROM ' . self::t('t_item') . ' i WHERE i.pk_i_id = ?',
            array_merge($this->titleParams(), array($itemId))
        );
    }

    /**
     * The listings $userId owns, with whether booking is on for each.
     *
     * @return array<int,array<string,mixed>>
     */
    public function sellerItems(int $userId, int $limit = 200): array
    {
        return Db::select(
            'SELECT i.pk_i_id, i.fk_i_category_id, ' . $this->titleSql('i.pk_i_id') . ' AS s_title, l.b_enabled'
            . ' FROM ' . self::t('t_item') . ' i LEFT JOIN ' . self::t('t_booking_listing') . ' l ON l.fk_i_item_id = i.pk_i_id'
            . ' WHERE i.fk_i_user_id = ? ORDER BY i.pk_i_id DESC LIMIT ?',
            array_merge($this->titleParams(), array($userId, $limit))
        );
    }

    /**
     * Remove everything the plugin holds for a deleted listing.
     */
    public function forgetItem(int $itemId): void
    {
        foreach (self::TABLES as $table) {
            Db::execute('DELETE FROM ' . self::t($table) . ' WHERE fk_i_item_id = ?', array($itemId));
        }
    }

    /**
     * @param array<int,mixed> $params
     *
     * @return array<int,array<string,mixed>>
     */
    private function bookings(string $where, array $params, int $limit, int $offset, string $order = 'b.dt_start DESC'): array
    {
        return Db::select(
            'SELECT b.*, i.fk_i_user_id AS i_owner_id, i.s_contact_email AS s_seller_email, i.s_contact_name AS s_seller_name,'
            . ' ' . $this->titleSql('b.fk_i_item_id') . ' AS s_title'
            . ' FROM ' . self::t('t_booking') . ' b JOIN ' . self::t('t_item') . ' i ON i.pk_i_id = b.fk_i_item_id'
            . ' WHERE ' . $where . ' ORDER BY ' . $order . ' LIMIT ? OFFSET ?',
            array_merge($this->titleParams(), $params, array($limit, $offset))
        );
    }

    /**
     * A listing's title, in this store's language when it has one.
     */
    private function titleSql(string $idColumn): string
    {
        return '(SELECT d.s_title FROM ' . self::t('t_item_description') . ' d WHERE d.fk_i_item_id = ' . $idColumn
            . ' ORDER BY d.fk_c_locale_code = ? DESC LIMIT 1)';
    }

    /**
     * @return array<int,string>
     */
    private function titleParams(): array
    {
        return array($this->locale);
    }
}
