<?php

/*
 * This file is part of the Booking Calendar plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\booking\Availability;
use mindstellar\booking\Config;

/*
 * The settings page declaration. Core renders the form, checks CSRF and the admin's
 * capability, validates each field and stores it under the "booking" section.
 */

$d = 'booking';

// The category table posts booking_cat[id] = mode; the hidden field stores it as JSON.
$categoryTable = static function () use ($d): void {
    $saved   = Config::parseCategories((string) osc_get_preference('categories', Config::PAGE));
    $choices = array(
        Config::INHERIT      => __('Same as parent', $d),
        Config::OFF          => __('Off', $d),
        Availability::NIGHTS => __('Nights (date range)', $d),
        Availability::SLOTS  => __('Time slots', $d),
    );
    $rows = array();
    $walk = static function (array $nodes, int $depth) use (&$walk, &$rows): void {
        foreach ($nodes as $node) {
            $rows[] = array((int) $node['pk_i_id'], (string) $node['s_name'], $depth);
            if (!empty($node['categories']) && is_array($node['categories'])) {
                $walk($node['categories'], $depth + 1);
            }
        }
    };
    $walk(Category::getInstance()->toTree(true), 0);

    if ($rows === array()) {
        echo '<p>' . osc_esc_html(__('There are no categories yet.', $d)) . '</p>';

        return;
    }
    echo '<table class="table booking-categories"><thead><tr><th>' . osc_esc_html(__('Category', $d)) . '</th><th>'
        . osc_esc_html(__('Booking', $d)) . '</th></tr></thead><tbody>';
    foreach ($rows as [$id, $name, $depth]) {
        $current = $saved[$id] ?? Config::INHERIT;
        echo '<tr><td>' . str_repeat('&mdash; ', $depth) . '<label for="booking-cat-' . $id . '">' . osc_esc_html($name) . '</label></td><td>'
            . '<select class="form-select form-select-sm" id="booking-cat-' . $id . '" name="booking_cat[' . $id . ']">';
        foreach ($choices as $value => $label) {
            if ($depth === 0 && $value === Config::INHERIT) {
                $label = __('Off', $d);
                $value = Config::INHERIT;
            } elseif ($depth === 0 && $value === Config::OFF) {
                continue;
            }
            echo '<option value="' . osc_esc_html($value) . '"' . ($current === $value || ($depth === 0 && $value === Config::INHERIT && $current === Config::OFF) ? ' selected' : '') . '>'
                . osc_esc_html($label) . '</option>';
        }
        echo '</select></td></tr>';
    }
    echo '</tbody></table>';
};

return array(
    'title'  => __('Booking Calendar', $d),
    'menu'   => 'plugins',
    'intro'  => __('Let buyers book listings for dates or time slots. Choose which categories take bookings; sellers turn it on for each listing from their account.', $d),
    'groups' => array(
        array(
            'title'  => __('Categories', $d),
            'fields' => array(
                array(
                    'type'   => 'custom',
                    'name'   => 'categories_table',
                    'label'  => __('Booking by category', $d),
                    'help'   => __('Nights suit rentals: buyers pick check-in and check-out dates. Time slots suit services: buyers pick a time within the seller\'s opening hours. A subcategory follows its parent unless you change it.', $d),
                    'render' => $categoryTable,
                ),
                array(
                    'type'    => 'hidden',
                    'name'    => 'categories',
                    'default' => '{}',
                    'collect' => static function (array $field): string {
                        $posted = Params::getParam('booking_cat', false, false);
                        $modes  = array();
                        foreach (is_array($posted) ? $posted : array() as $id => $mode) {
                            if ((int) $id > 0 && in_array($mode, array(Availability::NIGHTS, Availability::SLOTS, Config::OFF), true)) {
                                $modes[(int) $id] = (string) $mode;
                            }
                        }
                        ksort($modes);

                        return (string) json_encode((object) $modes);
                    },
                ),
            ),
        ),
        array(
            'title'  => __('Defaults and limits', $d),
            'fields' => array(
                array(
                    'type'     => 'number',
                    'name'     => 'slot_minutes',
                    'label'    => __('Default slot length (minutes)', $d),
                    'help'     => __('Sellers can change it for each listing.', $d),
                    'default'  => 60,
                    'required' => true,
                    'attrs'    => array('min' => 5, 'max' => 720, 'step' => 5),
                    'validate' => static fn ($v) => ((int) $v < 5 || (int) $v > 720) ? __('Use 5 to 720 minutes.', 'booking') : null,
                ),
                array(
                    'type'     => 'number',
                    'name'     => 'min_nights',
                    'label'    => __('Default minimum nights', $d),
                    'default'  => 1,
                    'required' => true,
                    'attrs'    => array('min' => 1, 'max' => Availability::MAX_NIGHTS),
                    'validate' => static fn ($v) => ((int) $v < 1 || (int) $v > Availability::MAX_NIGHTS) ? sprintf(__('Use 1 to %d nights.', 'booking'), Availability::MAX_NIGHTS) : null,
                ),
                array(
                    'type'     => 'number',
                    'name'     => 'max_days',
                    'label'    => __('How far ahead buyers can book (days)', $d),
                    'default'  => 180,
                    'required' => true,
                    'attrs'    => array('min' => 1, 'max' => 730),
                    'validate' => static fn ($v) => ((int) $v < 1 || (int) $v > 730) ? __('Use 1 to 730 days.', 'booking') : null,
                ),
                array(
                    'type'     => 'number',
                    'name'     => 'expiry_hours',
                    'label'    => __('Unanswered requests expire after (hours)', $d),
                    'help'     => __('Checked every hour by cron. A request also expires once its start has passed.', $d),
                    'default'  => 48,
                    'required' => true,
                    'attrs'    => array('min' => 1, 'max' => 720),
                    'validate' => static fn ($v) => ((int) $v < 1 || (int) $v > 720) ? __('Use 1 to 720 hours.', 'booking') : null,
                ),
            ),
        ),
        array(
            'title'  => __('Guests', $d),
            'fields' => array(
                array(
                    'type'    => 'checkbox',
                    'name'    => 'guests',
                    'label'   => __('Let visitors without an account send requests', $d),
                    'help'    => __('They confirm their e-mail address with a link before the seller sees the request.', $d),
                    'default' => true,
                ),
                array(
                    'type'     => 'number',
                    'name'     => 'guest_limit',
                    'label'    => __('Guest requests per hour from one address', $d),
                    'help'     => __('0 means no limit.', $d),
                    'default'  => 5,
                    'depends'  => 'guests',
                    'attrs'    => array('min' => 0, 'max' => 1000),
                    'validate' => static fn ($v) => ((int) $v < 0 || (int) $v > 1000) ? __('Use 0 to 1000.', 'booking') : null,
                ),
            ),
        ),
    ),
);
