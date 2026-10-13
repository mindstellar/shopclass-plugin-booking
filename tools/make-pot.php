<?php
/*
 * This file is part of the Booking Calendar plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Writes languages/booking.pot from every literal __('…', 'booking') and _e('…', 'booking'), or with the domain in \$d.
 * Usage: php tools/make-pot.php
 */

$root  = dirname(__DIR__);
$files = array('index.php', 'settings.php', 'views/account.php', 'views/calendar.php', 'views/guest.php', 'admin/bookings.php');
$it    = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    if ($file->getExtension() === 'php') {
        $files[] = substr($file->getPathname(), strlen($root) + 1);
    }
}
sort($files);

$strings = array();
$pattern = '/\b(?:__|_e)\(\s*\'((?:\\\\.|[^\'\\\\])*)\'\s*,\s*(?:\'booking\'|\$d)\s*\)/';
foreach ($files as $file) {
    foreach (file($root . '/' . $file) as $n => $line) {
        if (preg_match_all($pattern, $line, $m)) {
            foreach ($m[1] as $raw) {
                $text             = str_replace(array("\\'", '\\\\'), array("'", '\\'), $raw);
                $strings[$text][] = $file . ':' . ($n + 1);
            }
        }
    }
}

preg_match('/^Version:\s*(\S+)/m', (string) file_get_contents($root . '/index.php'), $v);
$esc = static fn (string $s): string => addcslashes($s, "\"\\\n");
$out = "msgid \"\"\nmsgstr \"\"\n"
    . '"Project-Id-Version: Booking Calendar ' . ($v[1] ?? '') . "\\n\"\n"
    . "\"Report-Msgid-Bugs-To: https://github.com/mindstellar/shopclass-plugin-booking/issues\\n\"\n"
    . "\"MIME-Version: 1.0\\n\"\n\"Content-Type: text/plain; charset=UTF-8\\n\"\n"
    . "\"Content-Transfer-Encoding: 8bit\\n\"\n\"Plural-Forms: nplurals=2; plural=(n != 1);\\n\"\n\"Language: \\n\"\n";
ksort($strings);
foreach ($strings as $text => $refs) {
    $out .= "\n#: " . implode("\n#: ", array_unique($refs)) . "\nmsgid \"" . $esc($text) . "\"\nmsgstr \"\"\n";
}
file_put_contents($root . '/languages/booking.pot', $out);
echo count($strings) . " strings\n";
