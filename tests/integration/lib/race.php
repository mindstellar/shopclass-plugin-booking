<?php
/*
 * This file is part of the Booking Calendar plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The second side of a lock test: runs one store call on its own database connection and
 * prints what happened, so the test can hold a lock in the other process.
 *
 * Usage: php race.php <database> '<json op>'
 *   {"op":"create","item":1,"start":…,"end":…}  or  {"op":"transition","id":1,"to":"declined","actor":"seller"}
 */

$core = rtrim(getenv('SHOPCLASS_CORE') ?: dirname(__DIR__, 4) . '/osclass', '/');
require_once $core . '/tests/lib/scratchdb.php';
scratchdb_bootstrap((string) ($argv[1] ?? ''));
require_once $core . '/tests/lib/stubs.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'mindstellar\\booking\\';
    if (strncmp($class, $prefix, strlen($prefix)) === 0) {
        require __DIR__ . '/../../../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});

use mindstellar\booking\BookingException;
use mindstellar\booking\BookingStore;
use mindstellar\database\Db;

$op    = (array) json_decode((string) ($argv[2] ?? '{}'), true);
$store = new BookingStore();
Db::execute('SET SESSION innodb_lock_wait_timeout = 20');
try {
    if (($op['op'] ?? '') === 'create') {
        $id = $store->create(array(
            'item'   => (int) $op['item'],
            'user'   => null,
            'mode'   => 'nights',
            'start'  => (int) $op['start'],
            'end'    => (int) $op['end'],
            'status' => 'pending',
            'name'   => 'Racer',
            'email'  => 'racer@example.test',
            'phone'  => '',
            'note'   => '',
        ));
        echo 'none:' . $id;
    } else {
        $row = $store->transition((int) $op['id'], (string) $op['to'], (string) $op['actor']);
        echo 'none:' . $row['s_status'];
    }
} catch (BookingException $e) {
    echo $e->reason();
} catch (Throwable $e) {
    echo 'error:' . get_class($e) . ':' . $e->getCode();
}
