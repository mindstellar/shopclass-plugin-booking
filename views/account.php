<?php
/*
 * This file is part of the Booking Calendar plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

// The Bookings account page: requests for the seller's listings, their settings, and the
// signed-in user's own bookings. Every form posts to the manage route with the CSRF token.

use mindstellar\booking\Availability;
use mindstellar\booking\Notifier;
use mindstellar\booking\Plugin;
use mindstellar\booking\Status;
use mindstellar\booking\Token;

if (!defined('ABS_PATH')) {
    exit('Direct access is not allowed.');
}

$d        = 'booking';
$service  = Plugin::service();
$store    = $service->store();
$config   = $service->config();
$userId   = (int) osc_logged_user_id();
$notifier = new Notifier($config->tz());
$manage   = Plugin::url(Plugin::ROUTE_MANAGE);
$account  = osc_route_url(Plugin::ROUTE_ACCOUNT);
$itemId   = Params::getParamInt('item');
$today    = Availability::localDate(time(), $config->tz());

/** A one-button form that posts an action on a booking. */
$button = static function (string $do, int $id, string $label, bool $danger = false) use ($manage): string {
    return '<form method="post" action="' . osc_esc_html($manage) . '" class="oe-inline-form nocsrf">'
        . osc_csrf_token_form()
        . '<input type="hidden" name="do" value="' . osc_esc_html($do) . '">'
        . '<input type="hidden" name="id" value="' . $id . '">'
        . '<button type="submit" class="oe-link-btn' . ($danger ? ' oe-danger-link' : '') . '">' . osc_esc_html($label) . '</button></form>';
};

/** One booking as a list row. */
$row = static function (array $b, string $actions) use ($notifier, $d): void {
    $status = (string) $b['s_status'];
    echo '<li class="oe-list-item booking-row-item"><div class="oe-list-body">';
    echo '<h3><a href="' . osc_esc_html((string) osc_item_url_ns((int) $b['fk_i_item_id'])) . '">' . osc_esc_html((string) $b['s_title']) . '</a></h3>';
    echo '<p class="oe-meta"><span class="oe-badge ' . osc_esc_html(Status::badge($status)) . '">' . osc_esc_html(Status::label($status)) . '</span> '
        . osc_esc_html($notifier->when($b)) . '</p>';
    echo '<p class="oe-meta">' . osc_esc_html((string) $b['s_name']) . ' &middot; <a href="mailto:' . osc_esc_html((string) $b['s_email']) . '">'
        . osc_esc_html((string) $b['s_email']) . '</a>'
        . ((string) $b['s_phone'] !== '' ? ' &middot; ' . osc_esc_html((string) $b['s_phone']) : '') . '</p>';
    if ((string) $b['s_note'] !== '') {
        echo '<p class="oe-muted">' . nl2br(osc_esc_html((string) $b['s_note'])) . '</p>';
    }
    if ($actions !== '') {
        echo '<div class="oe-row-actions oe-meta">' . $actions . '</div>';
    }
    echo '</div></li>';
};

$item = $itemId > 0 ? $store->item($itemId) : null;
$mode = $item !== null ? $config->modeForCategory((int) $item['fk_i_category_id']) : '';

if ($item !== null && (int) $item['fk_i_user_id'] === $userId && $mode !== '') {
    // One listing's settings.
    $listing = $store->listing($itemId);
    $hours   = Availability::parseHours((string) ($listing['s_hours'] ?? ''));
    $blocked = Availability::formatBlocked($store->blocked($itemId, $today, Availability::addDays($today, 800)));
    $feed    = $listing !== null && (string) $listing['s_ical_salt'] !== ''
        ? Plugin::url(Plugin::ROUTE_ICAL, array('item' => $itemId, 'key' => Token::ical($itemId, (string) $listing['s_ical_salt'])))
        : '';
    $days = array(1 => __('Monday', $d), 2 => __('Tuesday', $d), 3 => __('Wednesday', $d), 4 => __('Thursday', $d), 5 => __('Friday', $d), 6 => __('Saturday', $d), 7 => __('Sunday', $d));
    ?>
    <p><a href="<?php echo osc_esc_html($account); ?>">&larr; <?php echo osc_esc_html(__('All bookings', $d)); ?></a></p>
    <h2><?php echo osc_esc_html((string) $item['s_title']); ?></h2>
    <form method="post" action="<?php echo osc_esc_html($manage); ?>" class="nocsrf booking-settings">
        <?php echo osc_csrf_token_form(); ?>
        <input type="hidden" name="do" value="listing">
        <input type="hidden" name="item" value="<?php echo (int) $itemId; ?>">
        <fieldset class="oe-group">
            <legend><?php echo osc_esc_html(__('Bookings', $d)); ?></legend>
            <label class="oe-check"><input type="checkbox" name="enabled" value="1" <?php echo !empty($listing['b_enabled']) ? 'checked' : ''; ?>>
                <?php echo osc_esc_html(__('Take bookings for this listing', $d)); ?></label>
            <label class="oe-check"><input type="checkbox" name="auto_confirm" value="1" <?php echo !empty($listing['b_auto_confirm']) ? 'checked' : ''; ?>>
                <?php echo osc_esc_html(__('Confirm requests at once, without asking me', $d)); ?></label>
            <div class="oe-field">
                <label class="oe-label" for="booking-price-note"><?php echo osc_esc_html(__('Price note', $d)); ?></label>
                <input class="oe-input" type="text" id="booking-price-note" name="price_note" maxlength="200"
                       value="<?php echo osc_esc_html((string) ($listing['s_price_note'] ?? '')); ?>" aria-describedby="booking-price-note-hint">
                <p class="oe-hint" id="booking-price-note-hint"><?php echo osc_esc_html(__('Shown above the calendar, such as "80 a night, cleaning 30".', $d)); ?></p>
            </div>
        </fieldset>
        <?php if ($mode === Availability::NIGHTS) { ?>
            <fieldset class="oe-group">
                <legend><?php echo osc_esc_html(__('Stays', $d)); ?></legend>
                <div class="oe-field">
                    <label class="oe-label" for="booking-min-nights"><?php echo osc_esc_html(__('Minimum nights', $d)); ?></label>
                    <input class="oe-input" type="number" id="booking-min-nights" name="min_nights" min="1" max="<?php echo Availability::MAX_NIGHTS; ?>"
                           value="<?php echo (int) ($listing['i_min_nights'] ?? $config->minNights); ?>">
                </div>
            </fieldset>
        <?php } else { ?>
            <fieldset class="oe-group">
                <legend><?php echo osc_esc_html(__('Opening hours', $d)); ?></legend>
                <div class="oe-field">
                    <label class="oe-label" for="booking-slot-minutes"><?php echo osc_esc_html(__('Length of one booking, in minutes', $d)); ?></label>
                    <input class="oe-input" type="number" id="booking-slot-minutes" name="slot_minutes" min="5" max="720" step="5"
                           value="<?php echo (int) ($listing['i_slot_minutes'] ?? $config->slotMinutes); ?>">
                </div>
                <p class="oe-hint"><?php echo osc_esc_html(__('For each day, the hours you take bookings, such as 09:00-12:00, 13:00-17:00. Leave a day empty to close it.', $d)); ?></p>
                <div class="oe-grid">
                    <?php foreach ($days as $n => $name) { ?>
                        <div class="oe-field">
                            <label class="oe-label" for="booking-hours-<?php echo $n; ?>"><?php echo osc_esc_html($name); ?></label>
                            <input class="oe-input" type="text" id="booking-hours-<?php echo $n; ?>" name="hours_<?php echo $n; ?>" placeholder="09:00-17:00"
                                   value="<?php echo osc_esc_html(Availability::formatRanges($hours[$n] ?? array())); ?>">
                        </div>
                    <?php } ?>
                </div>
            </fieldset>
        <?php } ?>
        <fieldset class="oe-group">
            <legend><?php echo osc_esc_html(__('Closed dates', $d)); ?></legend>
            <div class="oe-field">
                <label class="oe-label" for="booking-blocked"><?php echo osc_esc_html(__('Dates nobody can book', $d)); ?></label>
                <textarea class="oe-input" id="booking-blocked" name="blocked" rows="4" aria-describedby="booking-blocked-hint"><?php echo osc_esc_html($blocked); ?></textarea>
                <p class="oe-hint" id="booking-blocked-hint"><?php echo osc_esc_html(__('One per line: a date like 2026-12-24, or a range like 2026-12-24..2026-12-31.', $d)); ?></p>
            </div>
        </fieldset>
        <?php if ($feed !== '') { ?>
            <fieldset class="oe-group">
                <legend><?php echo osc_esc_html(__('Calendar feed', $d)); ?></legend>
                <div class="oe-field">
                    <label class="oe-label" for="booking-feed"><?php echo osc_esc_html(__('Add this address to Google, Apple or Outlook calendar', $d)); ?></label>
                    <input class="oe-input" type="text" id="booking-feed" readonly value="<?php echo osc_esc_html($feed); ?>">
                    <p class="oe-hint"><?php echo osc_esc_html(__('Keep it private: anyone with it sees your bookings.', $d)); ?></p>
                </div>
                <label class="oe-check"><input type="checkbox" name="new_feed" value="1">
                    <?php echo osc_esc_html(__('Make a new address and stop the old one', $d)); ?></label>
            </fieldset>
        <?php } ?>
        <div class="oe-actions"><button type="submit" class="oe-btn"><?php echo osc_esc_html(__('Save', $d)); ?></button></div>
    </form>
    <?php
    return;
}

// Requests for the seller's listings.
$status = (string) Params::getParamString('status');
$status = $status === '' ? Status::PENDING : ($status === 'all' ? '' : $status);
$tabs = array(Status::PENDING => __('Waiting', $d), Status::CONFIRMED => __('Confirmed', $d), '' => __('All', $d));
if (!array_key_exists($status, $tabs)) {
    $status = '';
}
$requests = $store->forSeller($userId, $status, 50);
$items    = array_values(array_filter(
    $store->sellerItems($userId),
    static fn (array $i): bool => $config->modeForCategory((int) $i['fk_i_category_id']) !== ''
));
$mine = $store->forBuyer($userId, 50);

if ($items !== array() || $requests !== array()) { ?>
    <h2><?php echo osc_esc_html(__('Requests for your listings', $d)); ?></h2>
    <nav class="oe-tabs" aria-label="<?php echo osc_esc_html(__('Filter requests', $d)); ?>">
        <?php foreach ($tabs as $key => $label) { ?>
            <a href="<?php echo osc_esc_html($key === '' ? Plugin::url(Plugin::ROUTE_ACCOUNT, array('status' => 'all')) : Plugin::url(Plugin::ROUTE_ACCOUNT, array('status' => $key))); ?>"
                <?php echo $key === $status ? 'aria-current="page"' : ''; ?>><?php echo osc_esc_html($label); ?></a>
        <?php } ?>
    </nav>
    <?php if ($requests === array()) { ?>
        <p class="oe-empty"><?php echo osc_esc_html(__('No requests here.', $d)); ?></p>
    <?php } else { ?>
        <ul class="oe-list">
            <?php foreach ($requests as $b) {
                $actions = '';
                foreach (Status::next((string) $b['s_status'], Status::SELLER) as $to) {
                    if ($to === Status::CONFIRMED) {
                        $actions .= $button('confirm', (int) $b['pk_i_id'], __('Accept', $d)) . ' ';
                    } elseif ($to === Status::DECLINED) {
                        $actions .= $button('decline', (int) $b['pk_i_id'], __('Decline', $d), true) . ' ';
                    } elseif ($to === Status::CANCELLED && (string) $b['s_status'] === Status::CONFIRMED) {
                        $actions .= $button('cancel', (int) $b['pk_i_id'], __('Cancel booking', $d), true) . ' ';
                    }
                }
                $row($b, trim($actions));
            } ?>
        </ul>
    <?php } ?>

    <h2><?php echo osc_esc_html(__('Your listings', $d)); ?></h2>
    <?php if ($items === array()) { ?>
        <p class="oe-empty"><?php echo osc_esc_html(__('None of your listings is in a category that takes bookings.', $d)); ?></p>
    <?php } else { ?>
        <ul class="oe-list">
            <?php foreach ($items as $i) { ?>
                <li class="oe-list-item"><div class="oe-list-body">
                    <h3><a href="<?php echo osc_esc_html((string) osc_item_url_ns((int) $i['pk_i_id'])); ?>"><?php echo osc_esc_html((string) $i['s_title']); ?></a></h3>
                    <p class="oe-meta">
                        <span class="oe-badge <?php echo (int) $i['b_enabled'] === 1 ? 'paid' : 'cancelled'; ?>"><?php echo osc_esc_html((int) $i['b_enabled'] === 1 ? __('Taking bookings', $d) : __('Off', $d)); ?></span>
                        <a href="<?php echo osc_esc_html(Plugin::url(Plugin::ROUTE_ACCOUNT, array('item' => (int) $i['pk_i_id']))); ?>"><?php echo osc_esc_html(__('Booking settings', $d)); ?></a>
                    </p>
                </div></li>
            <?php } ?>
        </ul>
    <?php } ?>
<?php } ?>

<h2><?php echo osc_esc_html(__('Your bookings', $d)); ?></h2>
<?php if ($mine === array()) { ?>
    <p class="oe-empty"><?php echo osc_esc_html(__('You have not booked anything yet.', $d)); ?></p>
<?php } else { ?>
    <ul class="oe-list">
        <?php foreach ($mine as $b) {
            $row($b, Status::can((string) $b['s_status'], Status::CANCELLED, Status::BUYER) ? $button('buyer_cancel', (int) $b['pk_i_id'], __('Cancel', $d), true) : '');
        } ?>
    </ul>
<?php } ?>
