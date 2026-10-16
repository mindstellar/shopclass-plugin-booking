<?php
/*
 * This file is part of the Booking Calendar plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

// The calendar and request form on a listing page. $booking comes from ItemPage::render().

use mindstellar\booking\Availability;
use mindstellar\booking\Plugin;

if (!defined('ABS_PATH')) {
    exit('Direct access is not allowed.');
}

$d      = 'booking';
$nights = $booking['mode'] === Availability::NIGHTS;
$uid    = 'booking-' . (int) $booking['item'];

if (!$booking['on']) {
    if ($booking['owner']) { ?>
<section class="booking booking-off" id="booking">
    <p class="booking-owner"><?php echo osc_esc_html(__('Bookings are off for this listing.', $d)); ?>
        <a href="<?php echo osc_esc_html($booking['manage']); ?>"><?php echo osc_esc_html(__('Turn on bookings', $d)); ?></a></p>
</section>
    <?php }

    return;
}

$messages = array(
    'prev'      => __('Previous month', $d),
    'next'      => __('Next month', $d),
    'free'      => __('available', $d),
    'booked'    => __('booked', $d),
    'blocked'   => __('closed', $d),
    'closed'    => __('closed', $d),
    'full'      => __('fully booked', $d),
    'past'      => __('past', $d),
    'beyond'    => __('not open yet', $d),
    'checkIn'   => __('Pick your check-in date.', $d),
    'checkOut'  => __('Now pick your check-out date.', $d),
    'chosen'    => __('%1$s to %2$s, %3$d nights', $d),
    'pickTime'  => __('Now pick a time.', $d),
    'minNights' => __('The minimum stay is %d nights.', $d),
    'pickDay'   => __('Pick a day to see its times.', $d),
    'slotsFor'  => __('Times on %s', $d),
    'noSlots'   => __('No free times on this day.', $d),
    'slot'      => __('%1$s at %2$s', $d),
    'error'     => __('The calendar could not be loaded. You can still type your dates below.', $d),
);
?>
<section class="booking booking--<?php echo osc_esc_html($booking['mode']); ?>" id="booking" aria-labelledby="<?php echo $uid; ?>-title"
         data-booking
         data-mode="<?php echo osc_esc_html($booking['mode']); ?>"
         data-url="<?php echo osc_esc_html($booking['data']); ?>"
         data-today="<?php echo osc_esc_html($booking['today']); ?>"
         data-last="<?php echo osc_esc_html($booking['last']); ?>"
         data-min-nights="<?php echo (int) $booking['minNights']; ?>"
         data-messages="<?php echo osc_esc_html((string) json_encode($messages)); ?>">
    <div class="booking-header">
        <h2 class="booking-title" id="<?php echo $uid; ?>-title"><?php echo osc_esc_html($nights ? __('Check availability', $d) : __('Book a time', $d)); ?></h2>
        <?php if ($booking['note'] !== '') { ?>
            <p class="booking-note"><?php echo osc_esc_html($booking['note']); ?></p>
        <?php } ?>
        <?php if ($nights && $booking['minNights'] > 1) { ?>
            <p class="booking-hint"><?php echo osc_esc_html(sprintf(__('Minimum stay: %d nights.', $d), $booking['minNights'])); ?></p>
        <?php } ?>
    </div>
    <?php $flash = osc_get_flash_message(Plugin::FLASH);
    foreach (is_array($flash) ? $flash : array() as $message) {
        $type = (string) ($message['type'] ?? ''); ?>
        <p class="booking-message booking-message--<?php echo osc_esc_html($type); ?>" role="<?php echo $type === 'error' ? 'alert' : 'status'; ?>"><?php echo osc_esc_html((string) ($message['msg'] ?? '')); ?></p>
    <?php } ?>

    <div class="booking-body">
        <div class="booking-calendar" data-booking-calendar hidden>
            <div class="booking-nav">
                <h3 class="booking-month" data-booking-month aria-live="polite"></h3>
                <button type="button" class="booking-prev" data-booking-prev aria-label="<?php echo osc_esc_html($messages['prev']); ?>">&lsaquo;</button>
                <button type="button" class="booking-next" data-booking-next aria-label="<?php echo osc_esc_html($messages['next']); ?>">&rsaquo;</button>
            </div>
            <div class="booking-grid" data-booking-grid role="grid" aria-labelledby="<?php echo $uid; ?>-title"></div>
            <ul class="booking-legend" aria-hidden="true">
                <li class="booking-legend-free"><?php echo osc_esc_html(__('Available', $d)); ?></li>
                <li class="booking-legend-booked"><?php echo osc_esc_html(__('Booked', $d)); ?></li>
                <li class="booking-legend-closed"><?php echo osc_esc_html(__('Closed', $d)); ?></li>
            </ul>
            <div class="booking-slots" data-booking-slots hidden></div>
        </div>

        <form class="booking-form nocsrf" method="post" action="<?php echo osc_esc_html($booking['action']); ?>">
            <?php echo osc_csrf_token_form(); ?>
            <input type="hidden" name="item" value="<?php echo (int) $booking['item']; ?>">
            <p class="booking-status" data-booking-status role="status" aria-live="polite"></p>
            <div class="booking-when">
                <?php if ($nights) { ?>
                    <label class="booking-field"><span><?php echo osc_esc_html(__('Check-in', $d)); ?></span>
                        <input type="date" name="start" required data-booking-start
                               min="<?php echo osc_esc_html($booking['today']); ?>" max="<?php echo osc_esc_html($booking['last']); ?>"></label>
                    <label class="booking-field"><span><?php echo osc_esc_html(__('Check-out', $d)); ?></span>
                        <input type="date" name="end" required data-booking-end
                               min="<?php echo osc_esc_html($booking['today']); ?>"></label>
                <?php } else { ?>
                    <label class="booking-field"><span><?php echo osc_esc_html(__('Date and time', $d)); ?></span>
                        <input type="datetime-local" name="start" required data-booking-start
                               min="<?php echo osc_esc_html($booking['today']); ?>T00:00"></label>
                <?php } ?>
            </div>
            <?php if ($booking['signedIn'] || $booking['guests']) { ?>
                <?php if ($booking['signedIn']) { ?>
                    <p class="booking-as"><?php printf(
                        osc_esc_html(__('Booking as %1$s, %2$s', $d)),
                        '<strong>' . osc_esc_html((string) osc_logged_user_name()) . '</strong>',
                        osc_esc_html((string) osc_logged_user_email())
                    ); ?></p>
                <?php } ?>
                <div class="booking-contact">
                    <label class="booking-field"><span><?php echo osc_esc_html(__('Your name', $d)); ?></span>
                        <input type="text" name="name" required maxlength="100" autocomplete="name"
                               value="<?php echo osc_esc_html($booking['signedIn'] ? (string) osc_logged_user_name() : ''); ?>"></label>
                    <?php if (!$booking['signedIn']) { ?>
                        <label class="booking-field"><span><?php echo osc_esc_html(__('E-mail', $d)); ?></span>
                            <input type="email" name="email" required maxlength="150" autocomplete="email"></label>
                    <?php } ?>
                    <label class="booking-field"><span><?php echo osc_esc_html(__('Phone (optional)', $d)); ?></span>
                        <input type="tel" name="phone" maxlength="40" autocomplete="tel"
                               value="<?php echo osc_esc_html($booking['signedIn'] ? (string) osc_logged_user_phone() : ''); ?>"></label>
                    <label class="booking-field booking-field-note"><span><?php echo osc_esc_html(__('Message to the seller (optional)', $d)); ?></span>
                        <textarea name="note" rows="2" maxlength="1000"></textarea></label>
                </div>
                <button type="submit" class="booking-submit"><?php echo osc_esc_html(__('Send booking request', $d)); ?></button>
                <p class="booking-fine"><?php echo osc_esc_html(!$booking['signedIn']
                    ? __('We will e-mail you a link to confirm your request.', $d)
                    : ($booking['instant'] ? __('Your booking is confirmed at once. You pay nothing here.', $d) : __('The seller accepts or declines by e-mail. You pay nothing here.', $d))); ?></p>
            <?php } else { ?>
                <a class="booking-submit" href="<?php echo osc_esc_html(osc_user_login_url()); ?>"><?php echo osc_esc_html(__('Sign in to book', $d)); ?></a>
                <p class="booking-fine"><?php echo osc_esc_html(__('Sign in to send a booking request.', $d)); ?></p>
            <?php } ?>
        </form>
    </div>
    <?php if ($booking['owner']) { ?>
        <p class="booking-owner"><a href="<?php echo osc_esc_html($booking['manage']); ?>"><?php echo osc_esc_html(__('Manage bookings for this listing', $d)); ?></a></p>
    <?php } ?>
</section>
