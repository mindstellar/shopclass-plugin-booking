/*
 * This file is part of the Booking Calendar plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The listing page calendar. It fills the form's date fields; the form also works without it.
 */
(function () {
    'use strict';

    var DAY = 86400000;

    function parse(iso) {
        var p = iso.split('-');
        return Date.UTC(+p[0], +p[1] - 1, +p[2]);
    }

    function iso(time) {
        return new Date(time).toISOString().slice(0, 10);
    }

    function addDays(date, n) {
        return iso(parse(date) + n * DAY);
    }

    function monthOf(date) {
        return date.slice(0, 8) + '01';
    }

    function addMonths(month, n) {
        var d = new Date(parse(month));
        d.setUTCMonth(d.getUTCMonth() + n);
        return iso(d.getTime());
    }

    function format(text) {
        var args = Array.prototype.slice.call(arguments, 1);
        var i = 0;
        return text.replace(/%(?:(\d)\$)?[sd]/g, function (m, n) {
            var v = n ? args[+n - 1] : args[i++];
            return v === undefined ? m : String(v);
        });
    }

    function init(root) {
        var msg = JSON.parse(root.getAttribute('data-messages') || '{}');
        var mode = root.getAttribute('data-mode');
        var url = root.getAttribute('data-url');
        var today = root.getAttribute('data-today');
        var last = root.getAttribute('data-last');
        var minNights = parseInt(root.getAttribute('data-min-nights'), 10) || 1;
        var lang = document.documentElement.lang || undefined;

        var box = root.querySelector('[data-booking-calendar]');
        var grid = root.querySelector('[data-booking-grid]');
        var heading = root.querySelector('[data-booking-month]');
        var slotsBox = root.querySelector('[data-booking-slots]');
        var status = root.querySelector('[data-booking-status]');
        var startInput = root.querySelector('[data-booking-start]');
        var endInput = root.querySelector('[data-booking-end]');
        var prev = root.querySelector('[data-booking-prev]');
        var next = root.querySelector('[data-booking-next]');

        var months = {};
        var month = monthOf(today);
        var focusDate = today;
        var sel = {start: null, end: null, day: null, slot: null};

        box.hidden = false;
        say(mode === 'nights' ? msg.checkIn : msg.pickDay);

        function short(date) {
            return new Date(parse(date)).toLocaleDateString(lang, {weekday: 'short', day: 'numeric', month: 'short', timeZone: 'UTC'});
        }

        function say(text) {
            status.textContent = text || '';
        }

        function state(date) {
            var data = months[monthOf(date)];
            if (!data || !data.days[date]) {
                return null;
            }
            var day = data.days[date];
            return typeof day === 'string' ? day : day.state;
        }

        function load(m, then) {
            if (months[m]) {
                then();
                return;
            }
            var sep = url.indexOf('?') === -1 ? '?' : '&';
            fetch(url + sep + 'from=' + m, {credentials: 'same-origin', headers: {Accept: 'application/json'}})
                .then(function (r) {
                    if (!r.ok) {
                        throw new Error(String(r.status));
                    }
                    return r.json();
                })
                .then(function (data) {
                    months[m] = data;
                    then();
                })
                .catch(function () {
                    say(msg.error);
                });
        }

        function rangeFree(from, to) {
            for (var d = from; d < to; d = addDays(d, 1)) {
                var s = state(d);
                if (s !== null && s !== 'free') {
                    return false;
                }
            }
            return true;
        }

        function selectable(date) {
            var s = state(date);
            if (mode === 'slots') {
                return s === 'free';
            }
            if (sel.start && !sel.end && date > sel.start) {
                return date <= addDays(last, 1) && rangeFree(sel.start, date);
            }
            return s === 'free';
        }

        function label(date) {
            var text = new Date(parse(date)).toLocaleDateString(lang, {weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC'});
            var s = state(date);
            return s && msg[s] ? text + ', ' + msg[s] : text;
        }

        function render() {
            var data = months[month];
            heading.textContent = new Date(parse(month)).toLocaleDateString(lang, {month: 'long', year: 'numeric', timeZone: 'UTC'});
            prev.disabled = month <= monthOf(today);
            next.disabled = addMonths(month, 1) > last;
            grid.textContent = '';

            var head = document.createElement('div');
            head.setAttribute('role', 'row');
            head.className = 'booking-row booking-head';
            for (var w = 0; w < 7; w++) {
                var th = document.createElement('span');
                th.setAttribute('role', 'columnheader');
                th.className = 'booking-weekday';
                var name = new Date(Date.UTC(2024, 0, 1 + w));
                th.textContent = name.toLocaleDateString(lang, {weekday: 'short', timeZone: 'UTC'});
                th.setAttribute('aria-label', name.toLocaleDateString(lang, {weekday: 'long', timeZone: 'UTC'}));
                head.appendChild(th);
            }
            grid.appendChild(head);

            var first = parse(month);
            var lead = (new Date(first).getUTCDay() + 6) % 7;
            var end = addMonths(month, 1);
            var row = null;
            var cells = 0;
            if (!focusDate || monthOf(focusDate) !== month) {
                focusDate = month < monthOf(today) || month === monthOf(today) ? today : month;
            }
            for (var i = -lead; ; i++) {
                if (cells % 7 === 0) {
                    if (i >= 0 && iso(first + i * DAY) >= end) {
                        break;
                    }
                    row = document.createElement('div');
                    row.setAttribute('role', 'row');
                    row.className = 'booking-row';
                    grid.appendChild(row);
                }
                cells++;
                var cell = document.createElement('span');
                cell.setAttribute('role', 'gridcell');
                cell.className = 'booking-cell';
                row.appendChild(cell);
                if (i < 0) {
                    continue;
                }
                var date = iso(first + i * DAY);
                if (date >= end) {
                    continue;
                }
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'booking-day booking-day--' + (state(date) || 'unknown');
                btn.textContent = String(+date.slice(8));
                btn.setAttribute('data-date', date);
                btn.setAttribute('aria-label', label(date));
                btn.tabIndex = date === focusDate ? 0 : -1;
                if (!selectable(date)) {
                    btn.setAttribute('aria-disabled', 'true');
                }
                var picked = date === sel.start || date === sel.end || date === sel.day;
                btn.setAttribute('aria-pressed', picked ? 'true' : 'false');
                if (sel.start && sel.end && date > sel.start && date < sel.end) {
                    btn.classList.add('booking-day--range');
                }
                if (date === sel.start) {
                    btn.classList.add('booking-day--start');
                }
                if (date === sel.end) {
                    btn.classList.add('booking-day--end');
                }
                if (date === today) {
                    btn.setAttribute('aria-current', 'date');
                }
                cell.appendChild(btn);
            }
            if (mode === 'slots') {
                renderSlots();
            }
            return data;
        }

        function renderSlots() {
            slotsBox.textContent = '';
            if (!sel.day || monthOf(sel.day) !== month) {
                slotsBox.hidden = true;
                return;
            }
            var day = months[month].days[sel.day];
            var title = document.createElement('h4');
            title.className = 'booking-slots-title';
            title.textContent = format(msg.slotsFor, new Date(parse(sel.day)).toLocaleDateString(lang, {weekday: 'long', day: 'numeric', month: 'long', timeZone: 'UTC'}));
            slotsBox.appendChild(title);
            var list = document.createElement('div');
            list.className = 'booking-slot-list';
            list.setAttribute('role', 'group');
            list.setAttribute('aria-label', title.textContent);
            var any = false;
            (day && day.slots ? day.slots : []).forEach(function (slot) {
                var b = document.createElement('button');
                b.type = 'button';
                b.className = 'booking-slot booking-slot--' + slot[1];
                b.textContent = slot[0];
                b.setAttribute('data-time', slot[0]);
                b.setAttribute('aria-pressed', sel.slot === slot[0] ? 'true' : 'false');
                if (slot[1] !== 'free') {
                    b.disabled = true;
                    b.setAttribute('aria-label', slot[0] + ', ' + (msg[slot[1]] || slot[1]));
                } else {
                    any = true;
                }
                list.appendChild(b);
            });
            slotsBox.appendChild(list);
            if (!any) {
                var p = document.createElement('p');
                p.className = 'booking-hint';
                p.textContent = msg.noSlots;
                slotsBox.appendChild(p);
            }
            slotsBox.hidden = false;
        }

        function show(m, focus) {
            month = m;
            load(m, function () {
                render();
                if (focus) {
                    var b = grid.querySelector('[data-date="' + focusDate + '"]');
                    if (b) {
                        b.focus();
                    }
                }
            });
        }

        function pick(date) {
            if (!selectable(date)) {
                return;
            }
            focusDate = date;
            if (mode === 'slots') {
                sel.day = date;
                sel.slot = null;
                startInput.value = '';
                say(msg.pickTime);
                render();
                var first = slotsBox.querySelector('.booking-slot:not([disabled])');
                if (first) {
                    first.focus();
                }
                return;
            }
            if (sel.start && !sel.end && date > sel.start) {
                var nights = Math.round((parse(date) - parse(sel.start)) / DAY);
                if (nights < minNights) {
                    say(format(msg.minNights, minNights));
                    return;
                }
                sel.end = date;
                startInput.value = sel.start;
                endInput.value = sel.end;
                say(format(msg.chosen, short(sel.start), short(sel.end), nights));
            } else {
                sel.start = date;
                sel.end = null;
                startInput.value = date;
                endInput.value = '';
                endInput.min = addDays(date, minNights);
                say(msg.checkOut);
            }
            render();
            var b = grid.querySelector('[data-date="' + date + '"]');
            if (b) {
                b.focus();
            }
        }

        grid.addEventListener('click', function (e) {
            var b = e.target.closest('[data-date]');
            if (b) {
                pick(b.getAttribute('data-date'));
            }
        });

        grid.addEventListener('keydown', function (e) {
            var b = e.target.closest('[data-date]');
            if (!b) {
                return;
            }
            var date = b.getAttribute('data-date');
            var moves = {ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7};
            var to = null;
            if (moves[e.key] !== undefined) {
                to = addDays(date, moves[e.key]);
            } else if (e.key === 'Home') {
                to = addDays(date, -((new Date(parse(date)).getUTCDay() + 6) % 7));
            } else if (e.key === 'End') {
                to = addDays(date, 6 - ((new Date(parse(date)).getUTCDay() + 6) % 7));
            } else if (e.key === 'PageUp' || e.key === 'PageDown') {
                var m = addMonths(monthOf(date), e.key === 'PageUp' ? -1 : 1);
                to = m.slice(0, 8) + date.slice(8);
                if (monthOf(to) !== m) {
                    to = addDays(addMonths(m, 1), -1);
                }
            } else {
                return;
            }
            e.preventDefault();
            if (to < today) {
                to = today;
            }
            if (to > addDays(last, 1)) {
                return;
            }
            focusDate = to;
            if (monthOf(to) !== month) {
                show(monthOf(to), true);
                return;
            }
            Array.prototype.forEach.call(grid.querySelectorAll('[data-date]'), function (x) {
                x.tabIndex = x.getAttribute('data-date') === to ? 0 : -1;
            });
            var t = grid.querySelector('[data-date="' + to + '"]');
            if (t) {
                t.focus();
            }
        });

        slotsBox.addEventListener('click', function (e) {
            var b = e.target.closest('[data-time]');
            if (!b || b.disabled) {
                return;
            }
            sel.slot = b.getAttribute('data-time');
            startInput.value = sel.day + 'T' + sel.slot;
            Array.prototype.forEach.call(slotsBox.querySelectorAll('[data-time]'), function (x) {
                x.setAttribute('aria-pressed', x === b ? 'true' : 'false');
            });
            say(format(msg.slot, new Date(parse(sel.day)).toLocaleDateString(lang, {day: 'numeric', month: 'long', timeZone: 'UTC'}), sel.slot));
        });

        prev.addEventListener('click', function () {
            show(addMonths(month, -1), false);
        });
        next.addEventListener('click', function () {
            show(addMonths(month, 1), false);
        });

        show(month, false);
    }

    function start() {
        Array.prototype.forEach.call(document.querySelectorAll('[data-booking]'), init);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
