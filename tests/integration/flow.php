<?php
/*
 * This file is part of the Booking Calendar plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The plugin against a real database: migrations, the request flow, overlap checks under
 * lock, the unique slot key, status changes, expiry, guest links, the feed and uninstall.
 *
 * Needs core's scratch MySQL (see core's tests/lib/scratchdb.php). Usage:
 *   SHOPCLASS_CORE=/path/to/core php tests/integration/flow.php
 */

$core = rtrim(getenv('SHOPCLASS_CORE') ?: dirname(__DIR__, 3) . '/osclass', '/');
require_once $core . '/tests/lib/scratchdb.php';
require_once $core . '/tests/lib/harness.php';

$admin = scratchdb_session('osc_booking_plugin');

if (!defined('OSC_CSRF_SECRET')) {
    define('OSC_CSRF_SECRET', 'test-signing-key-0123456789abcdef');
}
if (!defined('OSC_LOCK_WAIT')) {
    define('OSC_LOCK_WAIT', 0);
}
// Core finds plugin migrations under PLUGINS_PATH/<folder>/, so the checkout is linked in as "booking".
$plugins = sys_get_temp_dir() . '/booking-plugins-' . getmypid() . '/';
@mkdir($plugins, 0700, true);
@symlink(dirname(__DIR__, 2), $plugins . 'booking');
register_shutdown_function(static function () use ($plugins): void {
    @unlink($plugins . 'booking');
    @rmdir($plugins);
});
define('PLUGINS_PATH', $plugins);

require_once $core . '/tests/lib/stubs.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hKv.php';
function osc_plugins_path()
{
    return PLUGINS_PATH;
}
function osc_register_render_target($id, $path)
{
}
function _m($key)
{
    return $key;
}
if (!function_exists('osc_current_user_locale')) {
    function osc_current_user_locale()
    {
        return 'en_US';
    }
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'mindstellar\\booking\\';
    if (strncmp($class, $prefix, strlen($prefix)) === 0) {
        require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});

use mindstellar\booking\Availability as A;
use mindstellar\booking\BookingException;
use mindstellar\booking\BookingService;
use mindstellar\booking\BookingStore;
use mindstellar\booking\Config;
use mindstellar\booking\Ical;
use mindstellar\booking\Notifier;
use mindstellar\booking\Plugin;
use mindstellar\booking\Status;
use mindstellar\database\Db;
use mindstellar\database\DbException;

$p      = DB_TABLE_PREFIX;
$scalar = static fn (string $sql) => $admin->query($sql)->fetch_row()[0] ?? null;
$reason = static function (callable $fn): string {
    try {
        $fn();
    } catch (BookingException $e) {
        return $e->reason();
    }

    return 'none';
};

harness_section('settings page declaration');

require_once ABS_PATH . 'oc-includes/osclass/helpers/hSettings.php';
$threw = null;
try {
    osc_register_settings_page(Config::PAGE, require __DIR__ . '/../../settings.php');
} catch (InvalidArgumentException $e) {
    $threw = $e->getMessage();
}
pin('core accepts the declaration', null, $threw);
$fields = \mindstellar\settings\SettingsPageRegistry::getInstance()->fields(Config::PAGE);
pin('guests may ask until the admin says no', true, (bool) osc_settings_value(Config::PAGE, 'guests'));
pin('an hour slot by default', 60, (int) osc_settings_value(Config::PAGE, 'slot_minutes'));
check('a slot under 5 minutes is refused', osc_settings_validate($fields['slot_minutes'], '3') !== null);
Params::setParam('booking_cat', array('4' => 'nights', '9' => 'slots', '12' => 'weekly', 'x' => 'off'));
pin('the category table is stored as JSON of known modes', '{"4":"nights","9":"slots"}', osc_settings_sanitize($fields['categories']));
Params::setParam('booking_cat', '');
$read = Config::get();
pin('the settings read back with their defaults', array(60, 1, 180, 48, 5), array($read->slotMinutes, $read->minNights, $read->maxDays, $read->expiryHours, $read->guestLimit));
Config::reset();

harness_section('migrations');

Plugin::install();
pin('the bookings table exists', 't_booking', (string) $scalar("SELECT REPLACE(TABLE_NAME, '{$p}', '') FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$p}t_booking'"));
pin('the migration is in core\'s ledger under the plugin', 1, (int) $scalar("SELECT COUNT(*) FROM {$p}t_migration WHERE s_migration = 'booking/0001_create_tables.sql'"));
pin('the schema version is recorded', (string) Plugin::SCHEMA, (string) osc_get_preference('schema', Config::PAGE));
check('the unique slot key is there', (int) $scalar("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$p}t_booking' AND INDEX_NAME = 'uq_booking_start'") === 3);
check('the calendar query has its index', (int) $scalar("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$p}t_booking' AND INDEX_NAME = 'idx_booking_holds'") === 3);
$result = \mindstellar\migration\MigrationRunner::forPlugin('booking')->run();
pin('a second run applies nothing', array(), $result['applied']);

harness_section('fixtures');

seed_locale($admin);
seed_currency($admin);
$rentals  = seed_category($admin, 'Rentals');
$cottages = seed_category($admin, 'Cottages', $rentals);
$services = seed_category($admin, 'Services');
$carsCat  = seed_category($admin, 'Cars');
$seller   = seed_user($admin, 'seller', 'seller@example.test');
$buyer    = seed_user($admin, 'buyer', 'buyer@example.test');
$stranger = seed_user($admin, 'eve', 'eve@example.test');
$cottage  = seed_item($admin, $cottages, $seller, 'Sea cottage');
$barber   = seed_item($admin, $services, $seller, 'Haircut');
$car      = seed_item($admin, $carsCat, $seller, 'Old car');
$admin->query("UPDATE {$p}t_item SET s_contact_email = 'seller@example.test'");

Config::$parents = static fn (int $id): array => $id === $cottages ? array($rentals) : array();
$config = new Config(array($rentals => A::NIGHTS, $services => A::SLOTS), 60, 2, 60, 48, true, 5, 'Europe/London', 100, 3, 10);
$mails  = array();
$notifier = new Notifier(
    $config->tz(),
    static function (string $type, array $payload, array $options) use (&$mails): void {
        $mails[] = array('to' => $payload['to'], 'subject' => $payload['subject'], 'key' => $options['unique_key'] ?? '');
    },
    static fn (string $route, array $query = array()): string => 'https://example.test/' . $route . '?' . http_build_query($query)
);
$store   = new BookingStore('en_US');
$service = new BookingService($config, $store, $notifier);
Plugin::setService($service);
$tz    = $config->tz();
$today = A::localDate(time(), $tz);
$day   = static fn (int $n): string => A::addDays($today, $n);
$req   = static fn (array $extra = array()): array => $extra + array('name' => 'Bea Buyer', 'email' => 'buyer@example.test', 'phone' => '', 'note' => 'Hello');

harness_section('seller settings');

pin('a stranger cannot set up the listing', 'owner', $reason(static fn () => $service->saveListing($cottage, $stranger, array('enabled' => true))));
pin('a listing outside booking categories cannot', 'off', $reason(static fn () => $service->saveListing($car, $seller, array('enabled' => true))));
$service->saveListing($cottage, $seller, array('enabled' => true, 'min_nights' => 2, 'price_note' => '<b>80</b> a night', 'blocked' => $day(30) . '..' . $day(31)));
$listing = $store->listing($cottage);
pin('the owner turns it on', 1, (int) $listing['b_enabled']);
pin('tags are taken out of the note', '80 a night', $listing['s_price_note']);
pin('closed dates are stored', array($day(30), $day(31)), $store->blocked($cottage, $today, $day(60)));
check('the feed gets a salt', strlen((string) $listing['s_ical_salt']) === 16);
$service->saveListing($barber, $seller, array('enabled' => true, 'slot_minutes' => 60, 'hours' => array_fill(1, 7, '09:00-12:00')));
$version = (int) $store->listing($barber)['i_version'];
$service->saveListing($barber, $seller, array('enabled' => true, 'slot_minutes' => 60, 'hours' => array_fill(1, 7, '09:00-12:00')));
check('saving again moves the version on', (int) $store->listing($barber)['i_version'] > $version);
pin('weekly hours are stored as minutes', '{"1":[[540,720]]', substr((string) $store->listing($barber)['s_hours'], 0, 16));
check('a subcategory follows its parent', $service->bookable($cottage)['mode'] === A::NIGHTS);
pin('a category without booking is off', 'off', $reason(static fn () => $service->bookable($car)));

harness_section('a stay');

$mails = array();
$stay  = $service->book($service->prepare($req(array('item' => $cottage, 'start' => $day(5), 'end' => $day(8))), $buyer, 'buyer@example.test'));
pin('the request waits for the seller', Status::PENDING, $stay['s_status']);
pin('it belongs to the buyer', $buyer, (int) $stay['fk_i_user_id']);
pin('both sides are mailed', array('buyer@example.test', 'seller@example.test'), array_column($mails, 'to'));
pin('it starts at local midnight, stored in UTC', A::dayStart($day(5), $tz), (int) \mindstellar\database\UtcDatetime::parse($stay['dt_start']));
pin('an overlapping stay is refused', 'taken', $reason(static fn () => $service->book($service->prepare($req(array('item' => $cottage, 'start' => $day(7), 'end' => $day(9))), $buyer, 'buyer@example.test'))));
pin('so is one that wraps it', 'taken', $reason(static fn () => $service->book($service->prepare($req(array('item' => $cottage, 'start' => $day(4), 'end' => $day(10))), $buyer, 'buyer@example.test'))));
$next = $service->book($service->prepare($req(array('item' => $cottage, 'start' => $day(8), 'end' => $day(10))), $buyer, 'buyer@example.test'));
pin('one that starts on the check-out day is fine', Status::PENDING, $next['s_status']);
pin('too short a stay', 'min_nights', $reason(static fn () => $service->prepare($req(array('item' => $cottage, 'start' => $day(20), 'end' => $day(21))), $buyer, 'buyer@example.test')));
pin('a stay over closed dates', 'blocked', $reason(static fn () => $service->prepare($req(array('item' => $cottage, 'start' => $day(29), 'end' => $day(32))), $buyer, 'buyer@example.test')));
pin('the seller cannot book their own listing', 'input', $reason(static fn () => $service->prepare($req(array('item' => $cottage, 'start' => $day(20), 'end' => $day(23))), $seller, 'seller@example.test')));
pin('a guest with a bad e-mail address', 'input', $reason(static fn () => $service->prepare($req(array('item' => $cottage, 'start' => $day(20), 'end' => $day(23), 'email' => 'nope')))));
$forced = $service->prepare($req(array('item' => $cottage, 'start' => $day(20), 'end' => $day(23), 'email' => 'relay@victim.test')), $buyer, 'buyer@example.test');
pin('a signed-in buyer always books with their account address', 'buyer@example.test', $forced['email']);
pin('an account without an address cannot book', 'input', $reason(static fn () => $service->prepare($req(array('item' => $cottage, 'start' => $day(20), 'end' => $day(23))), $buyer, '')));

$month = $service->month($cottage, A::NIGHTS, $store->listing($cottage), $day(5));
pin('the calendar shows the booked nights', A::BOOKED, $month['days'][$day(5)]);
pin('and the closed ones', A::BLOCKED, $month['days'][$day(30)] ?? A::BLOCKED);

harness_section('overlap check under lock');

$other = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME, (int) ini_get('mysqli.default_port'));
$other->query('START TRANSACTION');
$other->query("SELECT fk_i_item_id FROM {$p}t_booking_listing WHERE fk_i_item_id = {$cottage} FOR UPDATE");
Db::execute('SET SESSION innodb_lock_wait_timeout = 1');
$waited = null;
try {
    $service->book($service->prepare($req(array('item' => $cottage, 'start' => $day(12), 'end' => $day(14))), $buyer, 'buyer@example.test'));
} catch (DbException $e) {
    $waited = $e->getCode();
}
pin('a second request waits for the listing\'s lock', 1205, $waited);
pin('and wrote nothing', 0, (int) $scalar("SELECT COUNT(*) FROM {$p}t_booking WHERE dt_start = '" . \mindstellar\database\UtcDatetime::format(A::dayStart($day(12), $tz)) . "'"));
$other->query("INSERT INTO {$p}t_booking (fk_i_item_id, s_mode, dt_start, dt_end, s_status, b_hold, dt_created, dt_updated) VALUES ({$cottage}, 'nights', '"
    . \mindstellar\database\UtcDatetime::format(A::dayStart($day(12), $tz)) . "', '" . \mindstellar\database\UtcDatetime::format(A::dayStart($day(13), $tz)) . "', 'confirmed', 1, NOW(), NOW())");
$other->query('COMMIT');
pin('once the first commits, its night is seen as taken', 'taken', $reason(static fn () => $service->book($service->prepare($req(array('item' => $cottage, 'start' => $day(12), 'end' => $day(14))), $buyer, 'buyer@example.test'))));
Db::execute('SET SESSION innodb_lock_wait_timeout = 50');
$other->close();

harness_section('slots and the unique key');

$slotAt = $day(3) . 'T10:00';
$slot   = $service->book($service->prepare($req(array('item' => $barber, 'start' => $slotAt)), $buyer, 'buyer@example.test'));
pin('an hour is booked', 3600, (int) \mindstellar\database\UtcDatetime::parse($slot['dt_end']) - (int) \mindstellar\database\UtcDatetime::parse($slot['dt_start']));
pin('the same slot again is refused', 'taken', $reason(static fn () => $service->book($service->prepare($req(array('item' => $barber, 'start' => $slotAt)), $buyer, 'buyer@example.test'))));
pin('a time off the grid is refused', 'slot', $reason(static fn () => $service->prepare($req(array('item' => $barber, 'start' => $day(3) . 'T10:30')), $buyer, 'buyer@example.test')));
$dupe = 0;
try {
    $admin->query("INSERT INTO {$p}t_booking (fk_i_item_id, s_mode, dt_start, dt_end, s_status, b_hold, dt_created, dt_updated) VALUES ({$barber}, 'slots', '{$slot['dt_start']}', '{$slot['dt_end']}', 'pending', 1, NOW(), NOW())");
} catch (mysqli_sql_exception $e) {
    $dupe = $e->getCode();
}
pin('the database itself refuses a second live booking at the same start', 1062, $dupe);
$closed = $admin->query("INSERT INTO {$p}t_booking (fk_i_item_id, s_mode, dt_start, dt_end, s_status, b_hold, dt_created, dt_updated) VALUES ({$barber}, 'slots', '{$slot['dt_start']}', '{$slot['dt_end']}', 'declined', NULL, NOW(), NOW())");
check('but keeps any number of closed ones', (bool) $closed);
$slots = $service->month($barber, A::SLOTS, $store->listing($barber), $day(3))['days'][$day(3)]['slots'];
pin('the calendar shows the slot as booked', array('10:00', A::BOOKED), $slots[1]);

harness_section('status changes');

$mails = array();
pin('another seller cannot accept it', 'owner', $reason(static fn () => $service->move((int) $stay['pk_i_id'], Status::CONFIRMED, Status::SELLER, $stranger)));
pin('the buyer cannot accept it', 'status', $reason(static fn () => $service->move((int) $stay['pk_i_id'], Status::CONFIRMED, Status::BUYER, $buyer)));
$done = $service->move((int) $stay['pk_i_id'], Status::CONFIRMED, Status::SELLER, $seller);
pin('the seller accepts it', Status::CONFIRMED, $done['s_status']);
pin('both sides hear', array('b' . $stay['pk_i_id'] . ':confirmed:buyer', 'b' . $stay['pk_i_id'] . ':confirmed:seller'), array_column($mails, 'key'));
pin('another user cannot cancel it', 'owner', $reason(static fn () => $service->move((int) $stay['pk_i_id'], Status::CANCELLED, Status::BUYER, $stranger)));
$gone = $service->move((int) $stay['pk_i_id'], Status::CANCELLED, Status::BUYER, $buyer);
pin('the buyer cancels it', Status::CANCELLED, $gone['s_status']);
pin('it no longer holds its dates', null, $gone['b_hold']);
pin('it says who closed it', Status::BUYER, $gone['s_closed_by']);
pin('a cancelled booking cannot be accepted', 'status', $reason(static fn () => $service->move((int) $stay['pk_i_id'], Status::CONFIRMED, Status::SELLER, $seller)));
$again = $service->book($service->prepare($req(array('item' => $cottage, 'start' => $day(5), 'end' => $day(7))), $buyer, 'buyer@example.test'));
pin('its dates can be booked again', Status::PENDING, $again['s_status']);
pin('a guest cancels with the address the link was sent to', Status::CANCELLED, $service->move((int) $again['pk_i_id'], Status::CANCELLED, Status::BUYER, 0, 'BUYER@example.test')['s_status']);

harness_section('expiry');

$old = $service->book($service->prepare($req(array('item' => $barber, 'start' => $day(4) . 'T09:00')), $buyer, 'buyer@example.test'));
$admin->query("UPDATE {$p}t_booking SET dt_created = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 3 DAY) WHERE pk_i_id = " . (int) $old['pk_i_id']);
$mails   = array();
$expired = $service->expire();
pin('a request older than the limit expires', 1, $expired);
pin('it is marked expired', Status::EXPIRED, $store->find((int) $old['pk_i_id'])['s_status']);
pin('both sides hear', 2, count($mails));
pin('a fresh request stays', Status::PENDING, $store->find((int) $slot['pk_i_id'])['s_status']);
pin('running it again does nothing', 0, $service->expire());
pin('the expired slot is free again', Status::PENDING, $service->book($service->prepare($req(array('item' => $barber, 'start' => $day(4) . 'T09:00')), $buyer, 'buyer@example.test'))['s_status']);

harness_section('guests');

$mails = array();
$guest = $service->prepare($req(array('item' => $cottage, 'start' => $day(15), 'end' => $day(17), 'name' => 'Gus', 'email' => 'gus@example.test')));
$token = $service->askGuest($guest);
pin('nothing is booked yet', 0, (int) $scalar("SELECT COUNT(*) FROM {$p}t_booking WHERE s_email = 'gus@example.test'"));
pin('the guest gets the link', array('gus@example.test'), array_column($mails, 'to'));
check('the link carries none of their details', strpos((string) base64_decode(strtr(explode('.', $token)[0], '-_', '+/')), 'gus') === false);
$kept = $store->guest((string) \mindstellar\booking\Token::openRequest($token));
pin('the request waits on the server', 'gus@example.test', $kept['email'] ?? null);
$made = $service->confirmGuest($token);
pin('the link makes the booking', 'gus@example.test', $made['s_email']);
pin('with no account', null, $made['fk_i_user_id']);
pin('the kept request is gone', null, $store->guest((string) \mindstellar\booking\Token::openRequest($token)));
pin('the link works once', 'used', $reason(static fn () => $service->confirmGuest($token)));
pin('a broken link does nothing', 'expired', $reason(static fn () => $service->confirmGuest($token . 'x')));

harness_section('auto-confirm');

$service->saveListing($cottage, $seller, array('enabled' => true, 'auto_confirm' => true, 'min_nights' => 2));
$mails = array();
$auto  = $service->book($service->prepare($req(array('item' => $cottage, 'start' => $day(40), 'end' => $day(42))), $buyer, 'buyer@example.test'));
pin('the booking is confirmed at once', Status::CONFIRMED, $auto['s_status']);
check('and the mails say so', strpos((string) $mails[0]['subject'], 'confirmed') !== false);
pin('saving cleared the closed dates left out of the box', array(), $store->blocked($cottage, $today, $day(60)));

harness_section('lists and the feed');

pin('the seller sees requests for their listings', 10, count($store->forSeller($seller)));
pin('a stranger sees none', 0, count($store->forSeller($stranger)));
check('titles come with them', $store->forSeller($seller)[0]['s_title'] !== '');
pin('the buyer sees their own', 7, count($store->forBuyer($buyer)));
$counts = $store->countByStatus();
pin('the admin counts by status', array(4, 2, 1, 2, 1), array($counts['pending'], $counts['confirmed'], $counts['declined'], $counts['cancelled'], $counts['expired']));
$ics = Ical::build('Sea cottage', 'example.test', $store->feed($cottage, time() - 86400), $tz, time());
pin('the feed lists the stays that hold dates', 4, substr_count($ics, 'BEGIN:VEVENT'));
check('the feed names bookings by number', strpos($ics, 'SUMMARY:Booking #') !== false);
check('and carries no buyer name', strpos($ics, 'Bea Buyer') === false && strpos($ics, 'Gus') === false);

harness_section('limits per buyer');

$service->saveListing($cottage, $seller, array('enabled' => true, 'min_nights' => 2));
$carl    = seed_user($admin, 'carl', 'carl@example.test');
$limited = new BookingService(new Config(array($rentals => A::NIGHTS, $services => A::SLOTS), 60, 2, 60, 48, true, 5, 'Europe/London', 2, 2, 3), $store, $notifier);
$asCarl  = static fn (array $in): array => $limited->prepare($req($in), $carl, 'carl@example.test');
$limited->throttleUser($carl);
$limited->throttleUser($carl);
pin('a third request in the hour is refused', 'limit', $reason(static fn () => $limited->throttleUser($carl)));
pin('another account has its own count', 'none', $reason(static fn () => $limited->throttleUser($stranger)));
$limited->book($asCarl(array('item' => $cottage, 'start' => $day(50), 'end' => $day(52))));
$limited->book($asCarl(array('item' => $cottage, 'start' => $day(52), 'end' => $day(54))));
pin('a third waiting request on one listing is refused', 'open', $reason(static fn () => $limited->book($asCarl(array('item' => $cottage, 'start' => $day(54), 'end' => $day(56))))));
$limited->book($asCarl(array('item' => $barber, 'start' => $day(5) . 'T09:00')));
pin('so is one past the total', 'open', $reason(static fn () => $limited->book($asCarl(array('item' => $barber, 'start' => $day(5) . 'T10:00')))));
$service->move((int) $store->forBuyer($carl)[0]['pk_i_id'], Status::CANCELLED, Status::BUYER, $carl);
pin('cancelling one makes room', Status::PENDING, $limited->book($asCarl(array('item' => $barber, 'start' => $day(5) . 'T10:00')))['s_status']);

harness_section('bounds');

$service->saveListing($cottage, $seller, array('enabled' => true, 'min_nights' => 2, 'blocked' => '2000-01-01..2099-12-31'));
$blocked = $store->blocked($cottage, '1900-01-01', '2999-12-31');
pin('closed dates start today', $today, $blocked[0]);
pin('and stop at the last bookable day', $day(60), end($blocked));
$service->saveListing($cottage, $seller, array('enabled' => true, 'min_nights' => 2, 'blocked' => ''));
pin('a month far ahead shows the last bookable month', substr($day(60), 0, 7), $service->month($cottage, A::NIGHTS, $store->listing($cottage), '2099-05-01')['month']);
pin('a month in the past shows this month', substr($today, 0, 7), $service->month($cottage, A::NIGHTS, $store->listing($cottage), '1999-05-01')['month']);

harness_section('personal data');

$mails = array();
$asGuest = $service->confirmGuest($service->askGuest($service->prepare($req(array('item' => $cottage, 'start' => $day(44), 'end' => $day(46), 'email' => 'buyer@example.test')))));
osc_add_filter('personal_data_export', array(\mindstellar\booking\Privacy::class, 'export'));
$export = osc_apply_filter('personal_data_export', array('data' => array()), $buyer);
$rows   = $export['data']['booking_requests']['rows'] ?? array();
$ids    = array_map('intval', array_column($rows, 'pk_i_id'));
pin('the buyer gets every booking from their account', count($store->forBuyer($buyer)) + 1, count($rows));
check('and the guest one made with their address', in_array((int) $asGuest['pk_i_id'], $ids, true));
check('but nobody else\'s', !in_array('gus@example.test', array_column($rows, 's_email'), true) && !in_array('carl@example.test', array_column($rows, 's_email'), true));
pin('it says how long it is kept', 'deleted', $export['data']['booking_requests']['retention']);
pin('a seller gets none of their buyers\' requests', array(), osc_apply_filter('personal_data_export', array('data' => array()), $seller)['data']['booking_requests']['rows']);
osc_add_hook('delete_user', array(\mindstellar\booking\Privacy::class, 'deleteUser'));
osc_run_hook('delete_user', $buyer);
pin('deleting the account leaves no booking with their details', 0, (int) $scalar("SELECT COUNT(*) FROM {$p}t_booking WHERE fk_i_user_id = {$buyer} OR s_email = 'buyer@example.test'"));
pin('their bookings stay for the seller, anonymous', 'Deleted user', $store->find((int) $asGuest['pk_i_id'])['s_name']);
pin('and the ones still holding dates are cancelled', 0, (int) $scalar("SELECT COUNT(*) FROM {$p}t_booking WHERE s_name = 'Deleted user' AND b_hold = 1"));
pin('other buyers are untouched', 'gus@example.test', $store->find((int) $made['pk_i_id'])['s_email']);

harness_section('one rule for site and API');

$mails  = array();
$result = $service->requestOrBook($req(array('item' => $cottage, 'start' => $day(25), 'end' => $day(27), 'email' => 'relay@victim.test')), $stranger, 'eve@example.test');
pin('a signed-in request books at once', Status::PENDING, $result['outcome']);
pin('with the account address', 'eve@example.test', $result['booking']['s_email']);
$result = $service->requestOrBook($req(array('item' => $cottage, 'start' => $day(33), 'end' => $day(35), 'email' => 'hal@example.test')), 0);
pin('a guest request asks them to confirm', BookingService::ASKED, $result['outcome']);
pin('and books nothing yet', null, $result['booking']);
$shut = new BookingService(new Config(array($rentals => A::NIGHTS), 60, 2, 60, 48, false, 5, 'Europe/London'), $store, $notifier);
pin('guests can be switched off', 'guests', $reason(static fn () => $shut->requestOrBook($req(array('item' => $cottage, 'start' => $day(33), 'end' => $day(35), 'email' => 'hal@example.test')), 0)));
$gated = new BookingService($config, $store, $notifier, null, null, static fn (int $max): bool => false);
pin('a guest over the address limit is refused', 'limit', $reason(static fn () => $gated->requestOrBook($req(array('item' => $cottage, 'start' => $day(33), 'end' => $day(35), 'email' => 'hal@example.test')), 0)));
pin('the address limit does not apply to accounts', Status::PENDING, $gated->requestOrBook($req(array('item' => $cottage, 'start' => $day(36), 'end' => $day(38))), $stranger, 'eve@example.test')['outcome']);

harness_section('e-mail links');

$link = $service->askGuest($service->prepare($req(array('item' => $cottage, 'start' => $day(47), 'end' => $day(49), 'name' => 'Ida', 'email' => 'ida@example.test'))));
$seen = $service->resolveLink($link);
pin('a request link is read as one', array('request', $cottage, 'Sea cottage'), array($seen['kind'], $seen['item'], $seen['title']));
check('with its dates in words', $seen['when'] !== '');
$done = $service->actOnLink($link);
pin('pressing the button sends the request', Status::PENDING, $done['outcome']);
pin('then the link does nothing', 'none', $service->resolveLink($link)['kind']);
pin('and says it was used', 'used', $reason(static fn () => $service->actOnLink($link)));
$cancelLink = \mindstellar\booking\Token::cancel((int) $done['booking']['pk_i_id'], 'ida@example.test', time() + 86400);
pin('a cancel link is read as one', 'cancel', $service->resolveLink($cancelLink)['kind']);
pin('and cancels the booking', Status::CANCELLED, $service->actOnLink($cancelLink)['outcome']);
pin('a cancel link for another address does nothing', 'none', $service->resolveLink(\mindstellar\booking\Token::cancel((int) $done['booking']['pk_i_id'], 'eve@example.test', time() + 86400))['kind']);
pin('junk does nothing', 'none', $service->resolveLink('<script>.x')['kind']);
pin('and pressing it says it expired', 'expired', $reason(static fn () => $service->actOnLink('nonsense')));

harness_section('feed address');

$salt = (string) $store->listing($cottage)['s_ical_salt'];
pin('only the owner can change it', 'owner', $reason(static fn () => $service->resetFeed($cottage, $stranger)));
pin('which leaves it as it was', $salt, (string) $store->listing($cottage)['s_ical_salt']);
$service->resetFeed($cottage, $seller);
check('the owner gets a new one', (string) $store->listing($cottage)['s_ical_salt'] !== $salt);
pin('the old address stops working', null, $service->feed($cottage, \mindstellar\booking\Token::ical($cottage, $salt), 'example.test'));
check('the new one works', strpos((string) $service->feed($cottage, \mindstellar\booking\Token::ical($cottage, (string) $store->listing($cottage)['s_ical_salt']), 'example.test'), 'BEGIN:VCALENDAR') === 0);

harness_section('cleanup');

osc_add_hook('after_delete_item', array(Plugin::class, 'itemDeleted'));
osc_run_hook('after_delete_item', $barber, array('pk_i_id' => $barber));
pin('a deleted listing takes its bookings', 0, (int) $scalar("SELECT COUNT(*) FROM {$p}t_booking WHERE fk_i_item_id = {$barber}"));
pin('and its settings', null, $store->listing($barber));
osc_kv_set(Plugin::FOLDER, 'probe', 1);
Plugin::uninstall();
pin('uninstall drops the tables', 0, (int) $scalar("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE '{$p}t\\_booking%'"));
pin('forgets the migrations', 0, (int) $scalar("SELECT COUNT(*) FROM {$p}t_migration WHERE s_migration LIKE 'booking/%'"));
pin('and clears its stored keys', null, osc_kv_get(Plugin::FOLDER, 'probe'));
Plugin::install();
pin('a reinstall creates them again', 3, (int) $scalar("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE '{$p}t\\_booking%'"));

exit(harness_result());
