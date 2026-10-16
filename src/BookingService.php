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

/**
 * The booking rules, shared by the web pages, the REST API and cron. Callers turn their
 * input into plain arrays and show what comes back; nothing here reads the request.
 */
final class BookingService
{
    private Config $config;
    private BookingStore $store;
    private Notifier $notifier;

    /** Rate-limit context for requests from an account, on the site or the API. */
    public const THROTTLE_USER = 'booking_request_user';

    /** Rate-limit context for guest requests, counted per address by core. */
    public const THROTTLE_GUEST = 'booking_request';

    /** What requestOrBook() did with a guest's request: mailed them a link to confirm it. */
    public const ASKED = 'asked';

    /** fn(): int */
    private Closure $clock;

    /** fn(string $context, string $key, int $max, int $window): bool, true while under the limit; counts one. */
    private Closure $limiter;

    /** fn(int $max): bool, true while this visitor's address is under the guest limit; counts one. */
    private Closure $guestGate;

    /** fn(string $type, array $payload, array $options): void -- puts a job on the queue. */
    private Closure $enqueue;

    public function __construct(Config $config, BookingStore $store, Notifier $notifier, ?Closure $clock = null, ?Closure $limiter = null, ?Closure $guestGate = null, ?Closure $enqueue = null)
    {
        $this->config   = $config;
        $this->store    = $store;
        $this->notifier = $notifier;
        $this->clock    = $clock ?? static fn (): int => time();
        $this->limiter  = $limiter ?? static fn (string $context, string $key, int $max, int $window): bool => \mindstellar\security\RateLimit::hit($context, $key, $max, $window);
        $this->guestGate = $guestGate ?? static function (int $max): bool {
            if (\mindstellar\security\ActionThrottle::exceededFor(self::THROTTLE_GUEST, $max)) {
                return false;
            }
            \mindstellar\security\ActionThrottle::record(self::THROTTLE_GUEST);

            return true;
        };
        $this->enqueue = $enqueue ?? static function (string $type, array $payload, array $options): void {
            osc_job_enqueue($type, $payload, $options);
        };
    }

    /**
     * A buyer's request, from the site or the API. A signed-in buyer books at once with their
     * account's address; a guest gets an e-mail with a link that makes the booking.
     *
     * @param array<string,mixed> $input item, start, end, name, email, phone, note
     *
     * @return array{outcome:string,booking:array<string,mixed>|null} outcome is ASKED, or the new booking's status
     * @throws BookingException
     */
    public function requestOrBook(array $input, int $userId, string $accountEmail = ''): array
    {
        if ($userId > 0) {
            $this->throttleUser($userId);
            $booking = $this->book($this->prepare($input, $userId, $accountEmail));

            return array('outcome' => (string) $booking['s_status'], 'booking' => $booking);
        }
        if (!$this->config->guests) {
            throw new BookingException('guests');
        }
        if (!($this->guestGate)($this->config->guestLimit)) {
            throw new BookingException('limit');
        }
        $this->askGuest($this->prepare($input));

        return array('outcome' => self::ASKED, 'booking' => null);
    }

    /**
     * What a link from an e-mail is for: a guest's request to confirm, a booking to cancel, or nothing.
     *
     * @return array{kind:string,token:string,item:int,request:array<string,mixed>|null,booking:array<string,mixed>|null,title:string,when:string}
     *         kind is 'request', 'cancel' or 'none'
     */
    public function resolveLink(string $token): array
    {
        $link = array('kind' => 'none', 'token' => '', 'item' => 0, 'request' => null, 'booking' => null, 'title' => '', 'when' => '');
        if (!preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $token)) {
            return $link;
        }
        $link['token'] = $token;
        $now           = ($this->clock)();
        $id            = Token::openRequest($token, $now);
        if ($id !== null) {
            $request = $this->store->guest($id);
            if ($request === null) {
                return $link;
            }
            $item = $this->store->item((int) $request['item']);

            return array(
                'kind'    => 'request',
                'item'    => (int) $request['item'],
                'request' => $request,
                'title'   => (string) ($item['s_title'] ?? ''),
                'when'    => $this->notifier->when(array(
                    's_mode'   => $request['mode'],
                    'dt_start' => \mindstellar\database\UtcDatetime::format((int) $request['start']),
                    'dt_end'   => \mindstellar\database\UtcDatetime::format((int) $request['end']),
                )),
            ) + $link;
        }
        $bookingId = Token::peekCancel($token, $now);
        $booking   = $bookingId !== null ? $this->store->find($bookingId) : null;
        if ($booking === null || Token::openCancel($token, (string) $booking['s_email'], $now) === null) {
            return $link;
        }

        return array(
            'kind'    => 'cancel',
            'item'    => (int) $booking['fk_i_item_id'],
            'booking' => $booking,
            'title'   => (string) $booking['s_title'],
            'when'    => $this->notifier->when($booking),
        ) + $link;
    }

    /**
     * Do what a link from an e-mail is for, once its owner pressed the button.
     *
     * @return array{outcome:string,booking:array<string,mixed>} outcome is the booking's new status
     * @throws BookingException 'expired' or 'used' for a link that does nothing, or why it could not be done
     */
    public function actOnLink(string $token): array
    {
        $link    = $this->resolveLink($token);
        $booking = $link['kind'] === 'cancel'
            ? $this->move((int) $link['booking']['pk_i_id'], Status::CANCELLED, Status::BUYER, 0, (string) $link['booking']['s_email'])
            : $this->confirmGuest($token);

        return array('outcome' => (string) $booking['s_status'], 'booking' => $booking);
    }

    /**
     * A listing's calendar feed, or null when the key is not this listing's.
     */
    public function feed(int $itemId, string $key, string $host): ?string
    {
        $listing = $itemId > 0 ? $this->store->listing($itemId) : null;
        if ($listing === null || !Token::icalValid($itemId, (string) $listing['s_ical_salt'], $key)) {
            return null;
        }
        $item = $this->store->item($itemId);
        $now  = ($this->clock)();

        return Ical::build((string) ($item['s_title'] ?? ''), $host, $this->store->feed($itemId, $now - 30 * 86400), $this->config->tz(), $now);
    }

    /**
     * Give a seller's listing a new calendar feed address; the old one stops working.
     *
     * @throws BookingException 'owner'
     */
    public function resetFeed(int $itemId, int $userId): void
    {
        $item = $this->store->item($itemId);
        if ($item === null || $userId <= 0 || (int) $item['fk_i_user_id'] !== $userId) {
            throw new BookingException('owner');
        }
        $this->store->newIcalSalt($itemId);
    }

    /**
     * Count one request from a signed-in account, refusing it past the hourly limit.
     *
     * @throws BookingException 'limit'
     */
    public function throttleUser(int $userId): void
    {
        if ($userId > 0 && $this->config->userLimit > 0
            && !($this->limiter)(self::THROTTLE_USER, 'u' . $userId, $this->config->userLimit, 3600)
        ) {
            throw new BookingException('limit');
        }
    }

    /**
     * The store, for plain reads from views and routes. Writes go through this service.
     */
    public function store(): BookingStore
    {
        return $this->store;
    }

    public function config(): Config
    {
        return $this->config;
    }

    /**
     * A listing that takes bookings: core's row, the seller's settings and the mode.
     *
     * @return array{item:array<string,mixed>,listing:array<string,mixed>,mode:string}
     * @throws BookingException 'off' when it does not take bookings
     */
    public function bookable(int $itemId): array
    {
        $item = $itemId > 0 ? $this->store->item($itemId) : null;
        if ($item === null || (int) $item['b_enabled'] !== 1 || (int) $item['b_active'] !== 1 || (int) $item['b_spam'] === 1) {
            throw new BookingException('off');
        }
        $mode    = $this->config->modeForCategory((int) $item['fk_i_category_id']);
        $listing = $mode === '' ? null : $this->store->listing($itemId);
        if ($listing === null || (int) $listing['b_enabled'] !== 1) {
            throw new BookingException('off');
        }

        return array('item' => $item, 'listing' => $listing, 'mode' => $mode);
    }

    /**
     * What a month of the calendar shows, from the first of the month $date is in.
     *
     * @param array<string,mixed> $listing
     *
     * @return array<string,mixed>
     */
    public function month(int $itemId, string $mode, array $listing, string $date): array
    {
        $tz             = $this->config->tz();
        $now            = ($this->clock)();
        [$first, $last] = Availability::window($now, $this->config->maxDays, $tz);
        $from           = Availability::clampMonth($date, $first, $last);
        $to             = Availability::nextMonth($from);
        $blocked        = $this->store->blocked($itemId, $from, $to, $this->config->import);
        $holds          = $this->store->holds($itemId, Availability::dayStart($from, $tz), Availability::dayStart($to, $tz));
        $out            = array(
            'mode'  => $mode,
            'month' => substr($from, 0, 7),
            'today' => $first,
            'last'  => $last,
        );
        if ($mode === Availability::NIGHTS) {
            $out['min_nights'] = max(1, (int) $listing['i_min_nights']);
            $out['days']       = Availability::nightStates($from, $to, $holds, $blocked, $tz, $now, $this->config->maxDays);

            return $out;
        }
        $minutes             = $this->slotMinutes($listing);
        $out['slot_minutes'] = $minutes;
        $out['days']         = array();
        $states              = Availability::slotStates($from, $to, Availability::parseHours((string) $listing['s_hours']), $minutes, $holds, $blocked, $tz, $now, $this->config->maxDays);
        foreach ($states as $day => $state) {
            $out['days'][$day] = array(
                'state' => $state['state'],
                'slots' => array_map(static fn (array $s): array => array($s[0], $s[1]), $state['slots']),
            );
        }

        return $out;
    }

    /**
     * Check a request and turn it into a booking's values. Dates are local: a check-in and a
     * check-out date for nights, a "Y-m-d\TH:i" start for a slot.
     *
     * @param array<string,mixed> $input        item, start, end, name, email, phone, note
     * @param string              $accountEmail a signed-in buyer's own address, used in place of any typed one
     *
     * @return array{item:int,user:?int,mode:string,start:int,end:int,status:string,name:string,email:string,phone:string,note:string}
     * @throws BookingException
     */
    public function prepare(array $input, int $userId = 0, string $accountEmail = ''): array
    {
        $ctx     = $this->bookable((int) ($input['item'] ?? 0));
        $listing = $ctx['listing'];
        $tz      = $this->config->tz();
        $now     = ($this->clock)();

        $name  = self::clean((string) ($input['name'] ?? ''), 100);
        $email = strtolower(trim($userId > 0 ? $accountEmail : (string) ($input['email'] ?? '')));
        $phone = self::clean((string) ($input['phone'] ?? ''), 40);
        $note  = self::clean((string) ($input['note'] ?? ''), 1000, true);
        if ($name === '' || strlen($email) > 150 || filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || ($phone !== '' && !preg_match('/^[0-9 +().\/-]{3,40}$/', $phone))
        ) {
            throw new BookingException('input', __('Please give your name and a valid e-mail address.', 'booking'));
        }
        if ((int) $ctx['item']['fk_i_user_id'] > 0 && (int) $ctx['item']['fk_i_user_id'] === $userId) {
            throw new BookingException('input', __('You cannot book your own listing.', 'booking'));
        }

        if ($ctx['mode'] === Availability::NIGHTS) {
            $in      = trim((string) ($input['start'] ?? ''));
            $out     = trim((string) ($input['end'] ?? ''));
            $blocked = Availability::validDate($in) && Availability::validDate($out) && $out > $in
                ? $this->store->blocked((int) $ctx['item']['pk_i_id'], $in, Availability::addDays($out, -1), $this->config->import)
                : array();
            $why = Availability::checkNights($in, $out, (int) $listing['i_min_nights'], array(), $blocked, $tz, $now, $this->config->maxDays);
            if ($why !== null) {
                throw new BookingException($why);
            }
            $start = Availability::dayStart($in, $tz);
            $end   = Availability::dayStart($out, $tz);
        } else {
            $start   = Availability::parseLocal((string) ($input['start'] ?? ''), $tz);
            $minutes = $this->slotMinutes($listing);
            if ($start === null) {
                throw new BookingException('slot');
            }
            $day     = Availability::localDate($start, $tz);
            $blocked = $this->store->blocked((int) $ctx['item']['pk_i_id'], $day, $day, $this->config->import);
            $why     = Availability::checkSlot($start, Availability::parseHours((string) $listing['s_hours']), $minutes, array(), $blocked, $tz, $now, $this->config->maxDays);
            if ($why !== null) {
                throw new BookingException($why);
            }
            $end = $start + $minutes * 60;
        }

        return array(
            'item'   => (int) $ctx['item']['pk_i_id'],
            'user'   => $userId > 0 ? $userId : null,
            'mode'   => $ctx['mode'],
            'start'  => $start,
            'end'    => $end,
            'status' => (int) $listing['b_auto_confirm'] === 1 ? Status::CONFIRMED : Status::PENDING,
            'name'   => $name,
            'email'  => $email,
            'phone'  => $phone,
            'note'   => $note,
        );
    }

    /**
     * Make a booking from a prepared request and mail both sides.
     *
     * @param array{item:int,user:?int,mode:string,start:int,end:int,status:string,name:string,email:string,phone:string,note:string} $request
     *
     * @return array<string,mixed> the booking
     * @throws BookingException 'taken' when someone got there first
     */
    public function book(array $request): array
    {
        $userId = (int) ($request['user'] ?? 0);
        if ($this->store->countOpen($userId, $request['email'], $request['item']) >= $this->config->openPerListing
            || $this->store->countOpen($userId, $request['email']) >= $this->config->openTotal
        ) {
            throw new BookingException('open');
        }
        $id      = $this->store->create($request);
        $booking = (array) $this->store->find($id);
        $this->notifier->booking($request['status'] === Status::CONFIRMED ? Notifier::CONFIRMED : Notifier::REQUESTED, $booking);

        return $booking;
    }

    /**
     * A guest's request: it is kept aside under a random id, and the link in their e-mail
     * carries only that id. Nothing reaches the seller until they open it.
     *
     * @param array<string,mixed> $request from prepare()
     *
     * @return string the token in the link
     */
    public function askGuest(array $request): string
    {
        $ctx = $this->bookable((int) $request['item']);
        $id  = Token::newId();
        $this->store->keepGuest($id, array(
            'item'  => $request['item'],
            'mode'  => $request['mode'],
            'start' => $request['start'],
            'end'   => $request['end'],
            'name'  => $request['name'],
            'email' => $request['email'],
            'phone' => $request['phone'],
            'note'  => $request['note'],
        ), Token::REQUEST_TTL);
        $token = Token::request($id, ($this->clock)());
        $this->notifier->verify($request, (string) $ctx['item']['s_title'], $token);

        return $token;
    }

    /**
     * A guest followed their link: check the request again, as time has passed, and book it.
     *
     * @return array<string,mixed> the booking
     * @throws BookingException
     */
    public function confirmGuest(string $token): array
    {
        $id   = Token::openRequest($token, ($this->clock)());
        $data = $id === null ? null : $this->store->guest($id);
        if ($id !== null && $data === null && $this->store->guestUsed($id)) {
            throw new BookingException('used', __('This request was already confirmed.', 'booking'));
        }
        if ($data === null) {
            throw new BookingException('expired', __('That link has expired. Please send the request again.', 'booking'));
        }
        $tz    = $this->config->tz();
        $input = $data;
        if (($data['mode'] ?? '') === Availability::NIGHTS) {
            $input['start'] = Availability::localDate((int) $data['start'], $tz);
            $input['end']   = Availability::localDate((int) $data['end'], $tz);
        } else {
            $input['start'] = Availability::localDateTime((int) $data['start'], $tz);
        }
        $request = $this->prepare($input);
        if (!$this->store->claimGuest((string) $id, 2 * Token::REQUEST_TTL)) {
            throw new BookingException('used', __('This request was already confirmed.', 'booking'));
        }

        return $this->book($request);
    }

    /**
     * Move a booking on and mail both sides. A seller may act only on their own listing, a
     * buyer only on their own booking.
     *
     * @param int    $userId the signed-in user, for the seller and buyer checks
     * @param string $email  the address a guest's cancel link was sent to
     *
     * @return array<string,mixed> the booking as it now is
     * @throws BookingException
     */
    public function move(int $id, string $to, string $actor, int $userId = 0, string $email = ''): array
    {
        if ($actor === Status::SELLER || $actor === Status::BUYER) {
            $before = $this->store->find($id);
            if ($before === null) {
                throw new BookingException('not_found');
            }
            $mine = $actor === Status::SELLER
                ? $userId > 0 && (int) $before['i_owner_id'] === $userId
                : ($userId > 0 && (int) $before['fk_i_user_id'] === $userId)
                    || ($email !== '' && hash_equals(strtolower((string) $before['s_email']), strtolower($email)));
            if (!$mine) {
                throw new BookingException('owner');
            }
        }
        $this->store->transition($id, $to, $actor);
        $booking = (array) $this->store->find($id);
        $this->notifier->booking(self::event($to), $booking);

        return $booking;
    }

    /**
     * Expire requests nobody answered in time, or whose start has passed. Returns how many.
     */
    public function expire(): int
    {
        $now   = ($this->clock)();
        $count = 0;
        foreach ($this->store->due($now - $this->config->expiryHours * 3600, $now) as $id) {
            try {
                $this->store->transition($id, Status::EXPIRED, Status::SYSTEM);
            } catch (BookingException $e) {
                continue;
            }
            $count++;
            $booking = $this->store->find($id);
            if ($booking !== null) {
                $this->notifier->booking(Notifier::EXPIRED, $booking);
            }
        }

        return $count;
    }

    /**
     * Save a seller's settings for one of their listings.
     *
     * @param array<string,mixed> $input enabled, auto_confirm, min_nights, slot_minutes, hours (weekday => text), price_note, blocked,
     *                                   import (calendar addresses, one per line)
     *
     * @throws BookingException 'owner', 'off', or 'input' for a calendar address that cannot be imported
     */
    public function saveListing(int $itemId, int $userId, array $input): void
    {
        $item = $this->store->item($itemId);
        if ($item === null || $userId <= 0 || (int) $item['fk_i_user_id'] !== $userId) {
            throw new BookingException('owner');
        }
        if ($this->config->modeForCategory((int) $item['fk_i_category_id']) === '') {
            throw new BookingException('off');
        }
        $import = $this->config->import && array_key_exists('import', $input)
            ? CalendarSync::parseList((string) $input['import'], CalendarSync::siteHost())
            : null;
        $hours = array();
        foreach ((array) ($input['hours'] ?? array()) as $day => $text) {
            $hours[(int) $day] = (string) $text;
        }
        $this->store->saveListing($itemId, array(
            'enabled'      => !empty($input['enabled']),
            'auto_confirm' => !empty($input['auto_confirm']),
            'min_nights'   => Config::clamp((int) ($input['min_nights'] ?? 0), 1, Availability::MAX_NIGHTS, $this->config->minNights),
            'slot_minutes' => Config::clamp((int) ($input['slot_minutes'] ?? 0), 5, 720, $this->config->slotMinutes),
            'hours'        => (string) json_encode((object) Availability::parseHours($hours)),
            'price_note'   => self::clean((string) ($input['price_note'] ?? ''), 200),
        ));
        [$today, $last] = Availability::window(($this->clock)(), $this->config->maxDays, $this->config->tz());
        $this->store->setBlocked($itemId, Availability::parseBlocked((string) ($input['blocked'] ?? ''), $today, $last), $today);
        if ($import !== null && $this->store->setImport($itemId, implode("\n", $import))) {
            if ($import === array()) {
                $this->store->replaceImported($itemId, array(), $today);
            } else {
                ($this->enqueue)(Plugin::JOB_IMPORT, array('item' => $itemId), array('unique_key' => 'item:' . $itemId));
            }
        }
    }

    /**
     * A listing was deleted: its bookings and settings go with it.
     */
    public function forgetListing(int $itemId): void
    {
        if ($itemId > 0) {
            $this->store->forgetItem($itemId);
        }
    }

    /**
     * An account is being deleted: strip its details from its bookings as a buyer.
     */
    public function forgetBuyer(int $userId): void
    {
        if ($userId > 0) {
            $this->store->anonymiseBuyer($userId, $this->store->accountEmail($userId));
        }
    }

    /**
     * The slot length a listing uses: its own, else the site's default.
     *
     * @param array<string,mixed> $listing
     */
    public function slotMinutes(array $listing): int
    {
        return Config::clamp((int) ($listing['i_slot_minutes'] ?? 0), 5, 720, $this->config->slotMinutes);
    }

    /**
     * The mail event for a status.
     */
    public static function event(string $status): string
    {
        switch ($status) {
            case Status::CONFIRMED:
                return Notifier::CONFIRMED;
            case Status::DECLINED:
                return Notifier::DECLINED;
            case Status::CANCELLED:
                return Notifier::CANCELLED;
            case Status::EXPIRED:
                return Notifier::EXPIRED;
        }

        return Notifier::REQUESTED;
    }

    /**
     * Text with tags and control characters taken out, cut to $max characters.
     */
    public static function clean(string $text, int $max, bool $multiline = false): string
    {
        $text = strip_tags($text);
        $text = (string) preg_replace($multiline ? '/[^\P{C}\n]+/u' : '/\p{C}+/u', ' ', $text);
        $text = trim($multiline ? (string) preg_replace('/[ \t]+/', ' ', $text) : (string) preg_replace('/\s+/', ' ', $text));

        return function_exists('mb_substr') ? mb_substr($text, 0, $max) : substr($text, 0, $max);
    }
}
