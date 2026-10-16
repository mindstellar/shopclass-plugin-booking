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

// Each main category gets an Off / Nights / Time slots switch; its subcategories fold below it.
// The fields post booking_cat[id] = mode; the hidden field stores them as JSON.
$categoryTable = static function () use ($d): void {
    $saved = Config::parseCategories((string) osc_get_preference('categories', Config::PAGE));
    $modes = array(
        Availability::NIGHTS => __('Nights', $d),
        Availability::SLOTS  => __('Time slots', $d),
    );
    $tree = Category::getInstance()->toTree(true);
    if ($tree === array()) {
        echo '<p>' . osc_esc_html(__('There are no categories yet.', $d)) . '</p>';

        return;
    }

    $flatten = static function (array $nodes, int $depth) use (&$flatten): array {
        $rows = array();
        foreach ($nodes as $node) {
            $rows[] = array((int) $node['pk_i_id'], (string) $node['s_name'], $depth);
            if (!empty($node['categories']) && is_array($node['categories'])) {
                $rows = array_merge($rows, $flatten($node['categories'], $depth + 1));
            }
        }

        return $rows;
    };

    $on = array();
    foreach ($tree as $top) {
        $mode = $saved[(int) $top['pk_i_id']] ?? '';
        if (isset($modes[$mode])) {
            $on[] = $top['s_name'] . ' (' . $modes[$mode] . ')';
        }
    }

    echo '<style>
.booking-cats{display:grid;gap:.5rem;max-width:52rem}
.booking-cats-summary{margin:0 0 .25rem;color:var(--osc-ink-muted,inherit)}
.booking-cat{border:1px solid var(--osc-rule,rgba(127,127,127,.25));border-radius:.375rem;padding:.6rem .75rem}
.booking-cat-head{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:.5rem}
.booking-cat-name{font-weight:600}
.booking-seg{display:inline-flex;margin:0;padding:2px;border:1px solid var(--osc-rule-strong,rgba(127,127,127,.4));border-radius:.25rem}
.booking-seg input{position:absolute;opacity:0;pointer-events:none}
.booking-seg label{padding:.3rem .7rem;border-radius:.25rem;font-size:.875rem;cursor:pointer;white-space:nowrap;color:var(--osc-ink-muted,inherit)}
.booking-seg label:hover{background:var(--osc-chrome-hover,rgba(127,127,127,.12));color:var(--osc-ink,inherit)}
.booking-seg input:checked+label{background:var(--osc-bronze-tint,rgba(127,127,127,.2));color:var(--osc-bronze,inherit);font-weight:600}
.booking-seg input[value=""]:checked+label{background:var(--osc-chrome-hover,rgba(127,127,127,.12));color:var(--osc-ink,inherit)}
.booking-seg input:focus-visible+label{outline:2px solid currentColor;outline-offset:1px}
.booking-subs{margin-top:.5rem}
.booking-subs summary{cursor:pointer;font-size:.875rem;color:var(--osc-ink-muted,inherit)}
.booking-sub{display:flex;align-items:center;justify-content:space-between;gap:.5rem;padding:.3rem 0;border-top:1px solid var(--osc-rule,rgba(127,127,127,.2))}
.booking-sub:first-of-type{margin-top:.4rem}
.booking-sub select{width:auto;min-width:11rem}
</style>';

    echo '<div class="booking-cats"><p class="booking-cats-summary">' . osc_esc_html($on === array()
        ? __('Bookings are off in every category.', $d)
        : sprintf(__('Bookings are on in: %s.', $d), implode(', ', $on))) . '</p>';

    $subChoices = array(
        Config::INHERIT      => __('Same as parent', $d),
        Config::OFF          => __('Off', $d),
        Availability::NIGHTS => __('Nights', $d),
        Availability::SLOTS  => __('Time slots', $d),
    );
    foreach ($tree as $top) {
        $id      = (int) $top['pk_i_id'];
        $current = $saved[$id] ?? Config::INHERIT;
        $name    = (string) $top['s_name'];
        echo '<div class="booking-cat"><div class="booking-cat-head"><span class="booking-cat-name" id="booking-cat-' . $id . '">'
            . osc_esc_html($name) . '</span><fieldset class="booking-seg" aria-labelledby="booking-cat-' . $id . '">';
        foreach (array(Config::INHERIT => __('Off', $d)) + $modes as $value => $label) {
            $inputId = 'booking-cat-' . $id . '-' . ($value === '' ? 'off' : $value);
            $checked = $value === $current || ($value === Config::INHERIT && !isset($modes[$current]));
            echo '<input type="radio" id="' . $inputId . '" name="booking_cat[' . $id . ']" value="' . osc_esc_html($value) . '"'
                . ($checked ? ' checked' : '') . '><label for="' . $inputId . '">' . osc_esc_html($label) . '</label>';
        }
        echo '</fieldset></div>';

        $subs = !empty($top['categories']) && is_array($top['categories']) ? $flatten($top['categories'], 0) : array();
        if ($subs !== array()) {
            $changed = count(array_filter($subs, static fn (array $row): bool => ($saved[$row[0]] ?? '') !== ''));
            echo '<details class="booking-subs"' . ($changed > 0 ? ' open' : '') . '><summary>'
                . osc_esc_html(sprintf(_n('%d subcategory', '%d subcategories', count($subs), $d), count($subs))
                    . ($changed > 0 ? ' · ' . sprintf(_n('%d set on its own', '%d set on their own', $changed, $d), $changed) : ' · ' . __('all follow it', $d)))
                . '</summary>';
            foreach ($subs as [$subId, $subName, $depth]) {
                $subCurrent = $saved[$subId] ?? Config::INHERIT;
                echo '<div class="booking-sub"><label for="booking-cat-' . $subId . '" style="padding-left:' . ($depth * 1.25) . 'rem">'
                    . osc_esc_html($subName) . '</label><select class="form-select form-select-sm" id="booking-cat-' . $subId
                    . '" name="booking_cat[' . $subId . ']">';
                foreach ($subChoices as $value => $label) {
                    echo '<option value="' . osc_esc_html($value) . '"' . ($subCurrent === $value ? ' selected' : '') . '>'
                        . osc_esc_html($label) . '</option>';
                }
                echo '</select></div>';
            }
            echo '</details>';
        }
        echo '</div>';
    }
    echo '</div>';
};

return array(
    'title'  => __('Booking Calendar', $d),
    'menu'   => 'plugins',
    'intro'  => __('Let buyers book listings for dates or time slots. Choose which categories take bookings; sellers turn it on for each listing from their account. Deactivating the plugin keeps every booking; uninstalling it deletes all bookings and these settings.', $d),
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
            'title'  => __('Limits per buyer', $d),
            'fields' => array(
                array(
                    'type'     => 'number',
                    'name'     => 'user_limit',
                    'label'    => __('Requests per hour from one account', $d),
                    'help'     => __('Counts requests from the site and the API. 0 means no limit.', $d),
                    'default'  => 10,
                    'attrs'    => array('min' => 0, 'max' => 1000),
                    'validate' => static fn ($v) => ((int) $v < 0 || (int) $v > 1000) ? __('Use 0 to 1000.', 'booking') : null,
                ),
                array(
                    'type'     => 'number',
                    'name'     => 'open_per_listing',
                    'label'    => __('Waiting requests one buyer may have on one listing', $d),
                    'default'  => 3,
                    'required' => true,
                    'attrs'    => array('min' => 1, 'max' => 100),
                    'validate' => static fn ($v) => ((int) $v < 1 || (int) $v > 100) ? __('Use 1 to 100.', 'booking') : null,
                ),
                array(
                    'type'     => 'number',
                    'name'     => 'open_total',
                    'label'    => __('Waiting requests one buyer may have in all', $d),
                    'default'  => 10,
                    'required' => true,
                    'attrs'    => array('min' => 1, 'max' => 1000),
                    'validate' => static fn ($v) => ((int) $v < 1 || (int) $v > 1000) ? __('Use 1 to 1000.', 'booking') : null,
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
                    'label'    => __('Guest requests per hour from one IP address', $d),
                    'help'     => __('Counted per IP address, or per /64 network for IPv6. 0 means no limit.', $d),
                    'default'  => 5,
                    'depends'  => 'guests',
                    'attrs'    => array('min' => 0, 'max' => 1000),
                    'validate' => static fn ($v) => ((int) $v < 0 || (int) $v > 1000) ? __('Use 0 to 1000.', 'booking') : null,
                ),
            ),
        ),
    ),
);
