<?php
/*
 * This file is part of the Booking Calendar plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Which categories take bookings, and the limits on settings.
 */

require __DIR__ . '/lib/bootstrap.php';

use mindstellar\booking\Config;

$config = new Config(array(1 => 'nights', 2 => 'off', 5 => 'slots'));

pin('a category with its own mode', 'nights', $config->modeFor(1));
pin('a subcategory follows its parent', 'nights', $config->modeFor(10, array(1)));
pin('the nearest parent decides', 'slots', $config->modeFor(11, array(5, 1)));
pin('off stops the walk', '', $config->modeFor(12, array(2, 1)));
pin('no mode anywhere is off', '', $config->modeFor(13, array(14)));

Config::$parents = static fn (int $id): array => $id === 10 ? array(1) : array();
pin('the site walks parents through its tree', 'nights', $config->modeForCategory(10));
Config::$parents = static function (int $id): array {
    throw new RuntimeException('not asked');
};
pin('a category with a mode needs no tree', 'slots', $config->modeForCategory(5));
pin('no categories, no lookups', '', (new Config(array()))->modeForCategory(10));
Config::$parents = null;

pin('stored JSON keeps known modes only', array(1 => 'nights', 3 => 'off'), Config::parseCategories('{"1":"nights","2":"weekly","3":"off","x":"slots"}'));
pin('bad JSON is no categories', array(), Config::parseCategories('nope'));
pin('a value under the minimum takes the default', 60, Config::clamp(0, 5, 720, 60));
pin('a value over the maximum is capped', 720, Config::clamp(9000, 5, 720, 60));
pin('an unknown time zone falls back to UTC', 'UTC', (new Config(array(), 60, 1, 180, 48, true, 5, 'Mars/Olympus'))->tz()->getName());

exit(harness_result());
