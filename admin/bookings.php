<?php
/*
 * This file is part of the Booking Calendar plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

// Plugins > Bookings: every booking, filtered by status, 50 a page.

use mindstellar\booking\Notifier;
use mindstellar\booking\Plugin;
use mindstellar\booking\Status;

if (!defined('ABS_PATH')) {
    exit('Direct access is not allowed.');
}

$d       = 'booking';
$service = Plugin::service();
$store   = $service->store();
$status  = (string) Params::getParamString('status');
if (!in_array($status, Status::all(), true)) {
    $status = '';
}
$page     = max(1, Params::getParamInt('p'));
$perPage  = 50;
$counts   = $store->countByStatus();
$total    = $status === '' ? array_sum($counts) : $counts[$status];
$rows     = $store->forAdmin($status, $perPage, ($page - 1) * $perPage);
$notifier = new Notifier($service->config()->tz());
$link     = static fn (string $s, int $p = 1): string => osc_route_admin_url(Plugin::ROUTE_ADMIN, array_filter(array('status' => $s, 'p' => $p > 1 ? $p : null)));

osc_admin_page_head(__('Bookings', $d));

$tabs = array(array('label' => __('All', $d), 'url' => $link(''), 'active' => $status === '', 'count' => array_sum($counts)));
foreach (Status::all() as $s) {
    $tabs[] = array('label' => Status::label($s), 'url' => $link($s), 'active' => $status === $s, 'count' => $counts[$s]);
}
echo '<div class="mb-3">';
// Core 7.0's tab strip; an older core draws the same links as a link group.
function_exists('osc_admin_tab_nav') ? osc_admin_tab_nav($tabs) : osc_admin_link_group($tabs);
echo '</div>';

echo '<div class="table-contains-actions osc-table-stack"><table class="table"><thead><tr>';
foreach (array(__('Status', $d), __('ID', $d), __('Listing', $d), __('When', $d), __('Buyer', $d), __('Asked', $d)) as $title) {
    echo '<th>' . osc_esc_html($title) . '</th>';
}
echo '</tr></thead><tbody>';
if ($rows === array()) {
    osc_admin_table_empty(6, array('icon' => 'bi-calendar-check', 'title' => __('No bookings', $d), 'text' => __('Bookings show here once buyers send requests.', $d)));
}
foreach ($rows as $b) {
    echo '<tr>';
    echo '<td>';
    osc_admin_status(Status::holds((string) $b['s_status']) ? 'active' : 'inactive', Status::label((string) $b['s_status']));
    echo '</td>';
    echo '<td>' . (int) $b['pk_i_id'] . '</td>';
    echo '<td><a href="' . osc_esc_html((string) osc_item_url_ns((int) $b['fk_i_item_id'])) . '" target="_blank" rel="noopener">' . osc_esc_html((string) $b['s_title']) . '</a></td>';
    echo '<td>' . osc_esc_html($notifier->when($b)) . '</td>';
    echo '<td>' . osc_esc_html((string) $b['s_name']) . '<br><a href="mailto:' . osc_esc_html((string) $b['s_email']) . '">' . osc_esc_html((string) $b['s_email']) . '</a>'
        . ((string) $b['s_phone'] !== '' ? '<br>' . osc_esc_html((string) $b['s_phone']) : '') . '</td>';
    echo '<td>' . osc_admin_when((string) $b['dt_created'] . ' UTC') . '</td>';
    echo '</tr>';
}
echo '</tbody></table></div>';

$pages = (int) ceil($total / $perPage);
if ($pages > 1) {
    echo '<nav aria-label="' . osc_esc_html(__('Pages', $d)) . '"><ul class="pagination">';
    for ($p = 1; $p <= $pages; $p++) {
        echo '<li class="page-item' . ($p === $page ? ' active' : '') . '"><a class="page-link" href="' . osc_esc_html($link($status, $p)) . '"'
            . ($p === $page ? ' aria-current="page"' : '') . '>' . $p . '</a></li>';
    }
    echo '</ul></nav>';
}
