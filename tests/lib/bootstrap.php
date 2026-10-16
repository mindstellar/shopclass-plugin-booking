<?php
/*
 * This file is part of the Booking Calendar plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Loads the plugin's classes and the few core classes they use, with no database. Core is
 * found through SHOPCLASS_CORE, or a sibling checkout named osclass.
 */

require_once __DIR__ . '/harness.php';

function core_path(): string
{
    $path = getenv('SHOPCLASS_CORE') ?: dirname(__DIR__, 3) . '/osclass';
    if (!is_file($path . '/oc-includes/osclass/classes/security/SignedPayload.php')) {
        fwrite(STDERR, "Shopclass core not found at $path. Set SHOPCLASS_CORE to a checkout of 7.0 or later.\n");
        exit(2);
    }

    return rtrim($path, '/');
}

if (!defined('ABS_PATH')) {
    define('ABS_PATH', core_path() . '/');
}
if (!defined('OSC_CSRF_SECRET')) {
    define('OSC_CSRF_SECRET', 'test-signing-key-0123456789abcdef');
}

foreach (array('security/SigningKey', 'security/SignedPayload', 'database/UtcDatetime', 'http/HttpClient', 'http/HttpException') as $file) {
    require_once core_path() . '/oc-includes/osclass/classes/' . $file . '.php';
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'mindstellar\\booking\\';
    if (strncmp($class, $prefix, strlen($prefix)) === 0) {
        require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});

ini_set('error_log', '/dev/null');
ini_set('display_errors', '1');
error_reporting(E_ALL);

if (!function_exists('__')) {
    function __($text, $domain = '')
    {
        return $text;
    }
}
if (!function_exists('osc_esc_html')) {
    function osc_esc_html($s)
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }
}
