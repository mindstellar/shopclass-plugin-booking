<?php

/*
 * This file is part of the Booking Calendar plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\database\Connection;
use mindstellar\migration\MigrationInterface;
use mindstellar\migration\SchemaProbes;

/**
 * Columns for importing other calendars: each listing's calendar addresses and last result,
 * and a mark on closed dates that came from them. Safe to re-run: a column is added only when missing.
 *
 * @title Booking Calendar: import other calendars
 */
return new class () implements MigrationInterface {
    use SchemaProbes;

    /** table => [column => definition], added in this order. */
    private const COLUMNS = array(
        't_booking_listing' => array(
            's_import'        => "VARCHAR(2000) NOT NULL DEFAULT ''",
            'dt_imported'     => 'DATETIME NULL',
            's_import_status' => "VARCHAR(255) NOT NULL DEFAULT ''",
        ),
        't_booking_blocked' => array(
            'b_imported' => 'TINYINT(1) NOT NULL DEFAULT 0',
        ),
    );

    public function up(Connection $conn): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            $name = DB_TABLE_PREFIX . $table;
            foreach ($columns as $column => $definition) {
                if (!$this->columnExists($conn, $name, $column)) {
                    $conn->execute('ALTER TABLE ' . $name . ' ADD COLUMN ' . $column . ' ' . $definition);
                }
            }
        }
    }
};
