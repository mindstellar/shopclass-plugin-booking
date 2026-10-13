<?php
/*
 * This file is part of the Booking Calendar plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

// The page a link from a booking e-mail opens. One button does what the link is for.

use mindstellar\booking\Http\Front;
use mindstellar\booking\Plugin;
use mindstellar\booking\Status;

if (!defined('ABS_PATH')) {
    exit('Direct access is not allowed.');
}

$d     = 'booking';
$guest = Front::$guest;
$url   = Plugin::url(Plugin::ROUTE_GUEST, array('t' => (string) ($guest['token'] ?? '')));

osc_show_flash_message();

if (empty($guest['request']) && empty($guest['booking'])) { ?>
    <p><?php echo osc_esc_html(__('This link has expired or was changed. Links to confirm a request work for 24 hours.', $d)); ?></p>
    <p><a class="oe-btn" href="<?php echo osc_esc_html(osc_base_url()); ?>"><?php echo osc_esc_html(__('Go to the home page', $d)); ?></a></p>
    <?php
    return;
}
?>
<div class="oe-panel booking-guest">
    <p><strong><?php echo osc_esc_html((string) $guest['title']); ?></strong><br>
        <?php echo osc_esc_html((string) $guest['when']); ?></p>
    <form method="post" action="<?php echo osc_esc_html($url); ?>" class="nocsrf oe-actions">
        <?php echo osc_csrf_token_form(); ?>
        <?php if (!empty($guest['request'])) { ?>
            <button type="submit" class="oe-btn"><?php echo osc_esc_html(__('Send my booking request', $d)); ?></button>
        <?php } elseif (Status::isFinal((string) $guest['booking']['s_status'])) { ?>
            <p><?php echo osc_esc_html(sprintf(__('Status: %s', $d), Status::label((string) $guest['booking']['s_status']))); ?></p>
        <?php } else { ?>
            <button type="submit" class="oe-btn oe-btn-danger"><?php echo osc_esc_html(__('Cancel this booking', $d)); ?></button>
        <?php } ?>
    </form>
</div>
