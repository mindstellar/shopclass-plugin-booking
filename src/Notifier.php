<?php

/*
 * This file is part of the Booking Calendar plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace mindstellar\booking;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use mindstellar\database\UtcDatetime;

/**
 * Writes the e-mails and puts them on core's job queue, so a request never waits on the mail server.
 * The mail is written now, with this request's links, and only sent by the job.
 */
final class Notifier
{
    /** Job that sends one e-mail. */
    public const JOB = 'booking.mail';

    public const REQUESTED = 'requested';
    public const CONFIRMED = 'confirmed';
    public const DECLINED  = 'declined';
    public const CANCELLED = 'cancelled';
    public const EXPIRED   = 'expired';
    public const VERIFY    = 'verify';

    private DateTimeZone $tz;

    /** fn(string $type, array $payload, array $options): void */
    private Closure $enqueue;

    /** fn(string $route, array $query): string */
    private Closure $url;

    public function __construct(DateTimeZone $tz, ?Closure $enqueue = null, ?Closure $url = null)
    {
        $this->tz      = $tz;
        $this->enqueue = $enqueue ?? static function (string $type, array $payload, array $options): void {
            osc_job_enqueue($type, $payload, $options);
        };
        $this->url = $url ?? static fn (string $route, array $query = array()): string => Plugin::url($route, $query);
    }

    /**
     * Mail the buyer and the seller about what just happened to a booking.
     *
     * @param array<string,mixed> $booking a row from BookingStore::find()
     */
    public function booking(string $event, array $booking): void
    {
        $id     = (int) $booking['pk_i_id'];
        $buyer  = (string) $booking['s_email'];
        $seller = (string) ($booking['s_seller_email'] ?? '');
        $when   = $this->when($booking);
        $title  = (string) ($booking['s_title'] ?? '');
        $cancel = Status::holds((string) $booking['s_status'])
            ? ($this->url)(Plugin::ROUTE_GUEST, array('t' => Token::cancel($id, $buyer, (int) UtcDatetime::parse((string) $booking['dt_end']))))
            : '';

        [$buyerSubject, $buyerLead] = $this->buyerText($event, $title);
        $body = '<p>' . osc_esc_html($buyerLead) . '</p>' . $this->details($title, $when, (int) $booking['fk_i_item_id']);
        if ($cancel !== '') {
            $body .= '<p><a href="' . osc_esc_html($cancel) . '">' . osc_esc_html(__('Cancel this booking', 'booking')) . '</a></p>';
        }
        $this->queue($id, $event, 'buyer', array(
            'to'          => $buyer,
            'to_name'     => (string) $booking['s_name'],
            'subject'     => $buyerSubject,
            'body'        => $body,
            'secret_link' => $cancel !== '',
        ));

        if ($seller === '') {
            return;
        }
        [$sellerSubject, $sellerLead] = $this->sellerText($event, $title, (string) ($booking['s_closed_by'] ?? ''));
        $body = '<p>' . osc_esc_html($sellerLead) . '</p>' . $this->details($title, $when, (int) $booking['fk_i_item_id'])
            . $this->contact($booking)
            . '<p><a href="' . osc_esc_html(($this->url)(Plugin::ROUTE_ACCOUNT, array())) . '">'
            . osc_esc_html($event === self::REQUESTED && (string) $booking['s_status'] === Status::PENDING
                ? __('Accept or decline the request', 'booking')
                : __('See your bookings', 'booking')) . '</a></p>';
        $this->queue($id, $event, 'seller', array(
            'to'       => $seller,
            'to_name'  => (string) ($booking['s_seller_name'] ?? ''),
            'subject'  => $sellerSubject,
            'body'     => $body,
            'reply_to' => $buyer,
        ));
    }

    /**
     * Mail a guest the link that confirms their request.
     *
     * @param array<string,mixed> $request item, start, end, mode, name, email
     */
    public function verify(array $request, string $title, string $token): void
    {
        $link = ($this->url)(Plugin::ROUTE_GUEST, array('t' => $token));
        $body = '<p>' . osc_esc_html(__('Please confirm your booking request. The seller only sees it once you do.', 'booking')) . '</p>'
            . $this->details($title, $this->when(array(
                's_mode'   => $request['mode'],
                'dt_start' => UtcDatetime::format((int) $request['start']),
                'dt_end'   => UtcDatetime::format((int) $request['end']),
            )), (int) $request['item'])
            . '<p><a href="' . osc_esc_html($link) . '">' . osc_esc_html(__('Confirm my request', 'booking')) . '</a></p>'
            . '<p>' . osc_esc_html(__('The link works for 24 hours. If you did not ask for this, ignore this e-mail.', 'booking')) . '</p>';
        ($this->enqueue)(self::JOB, array(
            'to'          => (string) $request['email'],
            'to_name'     => (string) $request['name'],
            'subject'     => sprintf(__('Confirm your booking request: %s', 'booking'), $title),
            'body'        => $body,
            'secret_link' => true,
        ), array('unique_key' => 'verify:' . sha1($token)));
    }

    /**
     * The booking's dates or time, in the site's time zone.
     *
     * @param array<string,mixed> $booking
     */
    public function when(array $booking): string
    {
        $start = (int) UtcDatetime::parse((string) $booking['dt_start']);
        $end   = (int) UtcDatetime::parse((string) $booking['dt_end']);
        if ((string) $booking['s_mode'] === Availability::NIGHTS) {
            $in     = Availability::localDate($start, $this->tz);
            $out    = Availability::localDate($end, $this->tz);
            $nights = Availability::daysBetween($in, $out);

            return sprintf(
                __('%1$s to %2$s (%3$d nights)', 'booking'),
                self::date($in),
                self::date($out),
                $nights
            );
        }
        $from = (new DateTimeImmutable('@' . $start))->setTimezone($this->tz);
        $to   = (new DateTimeImmutable('@' . $end))->setTimezone($this->tz);

        return sprintf(__('%1$s, %2$s to %3$s', 'booking'), self::date($from->format('Y-m-d')), $from->format('H:i'), $to->format('H:i'));
    }

    /**
     * A local date in the site's date format.
     */
    public static function date(string $date): string
    {
        return function_exists('osc_format_date') && function_exists('osc_date_format')
            ? (string) osc_format_date($date . ' 00:00:00', osc_date_format())
            : $date;
    }

    /**
     * @return array{0:string,1:string} subject and first line
     */
    private function buyerText(string $event, string $title): array
    {
        switch ($event) {
            case self::REQUESTED:
                return array(sprintf(__('Booking request sent: %s', 'booking'), $title), __('Your booking request was sent. The seller will accept or decline it.', 'booking'));
            case self::CONFIRMED:
                return array(sprintf(__('Booking confirmed: %s', 'booking'), $title), __('Your booking is confirmed.', 'booking'));
            case self::DECLINED:
                return array(sprintf(__('Booking declined: %s', 'booking'), $title), __('Sorry, the seller declined your booking request.', 'booking'));
            case self::CANCELLED:
                return array(sprintf(__('Booking cancelled: %s', 'booking'), $title), __('This booking was cancelled.', 'booking'));
        }

        return array(sprintf(__('Booking request expired: %s', 'booking'), $title), __('The seller did not answer your booking request in time, so it expired.', 'booking'));
    }

    /**
     * @return array{0:string,1:string} subject and first line
     */
    private function sellerText(string $event, string $title, string $by): array
    {
        switch ($event) {
            case self::REQUESTED:
                return array(sprintf(__('New booking request: %s', 'booking'), $title), __('You have a new booking request.', 'booking'));
            case self::CONFIRMED:
                return array(sprintf(__('Booking confirmed: %s', 'booking'), $title), __('This booking is confirmed.', 'booking'));
            case self::DECLINED:
                return array(sprintf(__('Booking declined: %s', 'booking'), $title), __('This booking request was declined.', 'booking'));
            case self::CANCELLED:
                return array(
                    sprintf(__('Booking cancelled: %s', 'booking'), $title),
                    $by === Status::BUYER ? __('The buyer cancelled this booking.', 'booking') : __('This booking was cancelled.', 'booking')
                );
        }

        return array(sprintf(__('Booking request expired: %s', 'booking'), $title), __('A booking request expired before it was answered.', 'booking'));
    }

    private function details(string $title, string $when, int $itemId): string
    {
        $link = function_exists('osc_item_url_ns') ? (string) osc_item_url_ns($itemId) : '';

        return '<p><strong>' . ($link !== '' ? '<a href="' . osc_esc_html($link) . '">' . osc_esc_html($title) . '</a>' : osc_esc_html($title))
            . '</strong><br>' . osc_esc_html($when) . '</p>';
    }

    /**
     * @param array<string,mixed> $booking
     */
    private function contact(array $booking): string
    {
        $lines = array(
            sprintf(__('Name: %s', 'booking'), (string) $booking['s_name']),
            sprintf(__('E-mail: %s', 'booking'), (string) $booking['s_email']),
        );
        if ((string) $booking['s_phone'] !== '') {
            $lines[] = sprintf(__('Phone: %s', 'booking'), (string) $booking['s_phone']);
        }
        $html = '<p>' . implode('<br>', array_map('osc_esc_html', $lines)) . '</p>';
        if ((string) $booking['s_note'] !== '') {
            $html .= '<p>' . nl2br(osc_esc_html((string) $booking['s_note'])) . '</p>';
        }

        return $html;
    }

    /**
     * @param array<string,mixed> $mail
     */
    private function queue(int $id, string $event, string $role, array $mail): void
    {
        ($this->enqueue)(self::JOB, $mail, array('unique_key' => 'b' . $id . ':' . $event . ':' . $role));
    }
}
