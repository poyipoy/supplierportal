/**
 * ADASI time picker: a typed HH:MM combobox with hour and minute listboxes.
 * Markup comes from <x-ui.time-picker>; the text input stays the submitted value.
 */

const pad = (value) => String(value).padStart(2, '0');

/** Parse "9:30", "09.30", "0930", "930" or "9" into { hour, minute }; null when not a 24-hour time. */
export function parseTime(raw) {
    const text = String(raw ?? '').trim();
    if (text === '') return null;

    let hour;
    let minute;
    const separated = text.match(/^(\d{1,2})[:.](\d{2})$/);
    if (separated) {
        [hour, minute] = [separated[1], separated[2]];
    } else if (/^\d{1,4}$/.test(text)) {
        if (text.length <= 2) [hour, minute] = [text, '0'];
        else if (text.length === 3) [hour, minute] = [text.slice(0, 1), text.slice(1)];
        else [hour, minute] = [text.slice(0, 2), text.slice(2)];
    } else {
        return null;
    }

    const h = Number(hour);
    const m = Number(minute);
    return h <= 23 && m <= 59 ? { hour: h, minute: m } : null;
}

export const formatTime = ({ hour, minute }) => `${pad(hour)}:${pad(minute)}`;

/** Current time in the business timezone, minutes floored to the picker step. */
export function nowInZone(timeZone, step = 5, date = new Date()) {
    const parts = new Intl.DateTimeFormat('en-GB', { timeZone, hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).formatToParts(date);
    const read = (type) => Number(parts.find((part) => part.type === type)?.value ?? 0);
    const minute = read('minute');
    return { hour: read('hour'), minute: minute - (minute % Math.max(1, step)) };
}

let activePicker = null;

function createPicker(wrapper) {
    const input = wrapper.querySelector('[data-time-input]');
    const panel = wrapper.querySelector('[data-time-panel]');
    const hourList = panel.querySelector('[data-time-list="hour"]');
    const minuteList = panel.querySelector('[data-time-list="minute"]');
    const errorBox = wrapper.querySelector('[data-time-error]');
    const errorText = wrapper.querySelector('[data-time-error-message]');
    const live = panel.querySelector('[data-time-live]');
    const step = Number(wrapper.dataset.timeStep || 5);
    const zone = wrapper.dataset.timeZone || 'Asia/Jakarta';
    const options = (list) => [...list.querySelectorAll('[role="option"]')];
    let open = false;

    const showError = (message) => {
        errorText.textContent = message;
        errorBox.classList.toggle('tw:hidden', !message);
        if (message) input.setAttribute('aria-invalid', 'true');
        else input.removeAttribute('aria-invalid');
    };

    // Selected = exact match; the roving tab stop falls back to the nearest earlier option.
    // With no value yet, the tab stop starts at the current business-time hour (nothing is selected).
    const markList = (list, value) => {
        const items = options(list);
        const anchor = value ?? (list === hourList ? nowInZone(zone, step).hour : null);
        let current = items.find((item) => Number(item.dataset.value) === anchor);
        if (!current && anchor !== null) {
            current = [...items].reverse().find((item) => Number(item.dataset.value) <= anchor);
        }
        const stop = current ?? items[0];
        items.forEach((item) => {
            item.setAttribute('aria-selected', value !== null && Number(item.dataset.value) === value ? 'true' : 'false');
            item.tabIndex = item === stop ? 0 : -1;
        });
        return stop;
    };

    const sync = () => {
        const time = parseTime(input.value);
        const hourItem = markList(hourList, time ? time.hour : null);
        const minuteItem = markList(minuteList, time ? time.minute : null);
        if (open) {
            hourItem.scrollIntoView({ block: 'nearest' });
            minuteItem.scrollIntoView({ block: 'nearest' });
        }
        return { hourItem, minuteItem };
    };

    const position = () => {
        const rect = input.getBoundingClientRect();
        const height = panel.offsetHeight;
        const width = panel.offsetWidth;
        const fitsBelow = window.innerHeight - rect.bottom >= height + 8;
        const top = fitsBelow || rect.top < height + 8 ? rect.bottom + 4 : rect.top - height - 4;
        panel.style.top = `${Math.max(8, top)}px`;
        panel.style.left = `${Math.max(8, Math.min(rect.left, window.innerWidth - width - 8))}px`;
    };

    const onOutside = (event) => {
        if (!wrapper.contains(event.target)) close(false);
    };
    const onViewport = () => position();

    function openPanel(focusList = false) {
        if (input.disabled || input.readOnly) return;
        if (activePicker && activePicker !== api) activePicker.close(false);
        activePicker = api;
        if (!open) {
            open = true;
            panel.hidden = false;
            input.setAttribute('aria-expanded', 'true');
            position();
            requestAnimationFrame(() => panel.classList.add('is-open'));
            document.addEventListener('pointerdown', onOutside, true);
            window.addEventListener('resize', onViewport);
            window.addEventListener('scroll', onViewport, true);
        }
        const { hourItem } = sync();
        if (focusList) hourItem.focus();
    }

    function close(returnFocus) {
        if (!open) return;
        open = false;
        panel.classList.remove('is-open');
        panel.hidden = true;
        input.setAttribute('aria-expanded', 'false');
        document.removeEventListener('pointerdown', onOutside, true);
        window.removeEventListener('resize', onViewport);
        window.removeEventListener('scroll', onViewport, true);
        if (activePicker === api) activePicker = null;
        if (returnFocus) input.focus();
    }

    const commit = (time) => {
        input.value = time ? formatTime(time) : '';
        showError('');
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
        live.textContent = time ? wrapper.dataset.timeSelected.replace(':time', input.value) : '';
        sync();
    };

    // Picking an hour keeps the panel open on the minute column; picking a minute finishes.
    const choose = (item) => {
        const current = parseTime(input.value) ?? { hour: 0, minute: 0 };
        if (item.closest('[data-time-list]') === hourList) {
            commit({ hour: Number(item.dataset.value), minute: current.minute });
            minuteList.querySelector('[tabindex="0"]')?.focus();
        } else {
            commit({ hour: current.hour, minute: Number(item.dataset.value) });
            close(true);
        }
    };

    input.addEventListener('click', () => openPanel(false));
    input.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            openPanel(true);
        } else if (event.key === 'Escape' && open) {
            event.preventDefault();
            close(true);
        }
    });
    input.addEventListener('input', () => {
        if (open) sync();
    });
    input.addEventListener('change', () => {
        if (input.value.trim() === '') {
            showError('');
            return;
        }
        const time = parseTime(input.value);
        if (time) {
            input.value = formatTime(time);
            showError('');
        } else {
            showError(wrapper.dataset.timeInvalid);
        }
    });

    panel.addEventListener('click', (event) => {
        const item = event.target.closest('[role="option"]');
        if (item) choose(item);
    });
    panel.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            event.preventDefault();
            close(true);
            return;
        }
        const item = event.target.closest('[role="option"]');
        if (!item) return;
        const list = item.closest('[data-time-list]');
        const items = options(list);
        const index = items.indexOf(item);
        const move = (target) => {
            if (!target) return;
            items.forEach((option) => { option.tabIndex = option === target ? 0 : -1; });
            target.focus();
            target.scrollIntoView({ block: 'nearest' });
        };
        switch (event.key) {
            case 'ArrowDown': event.preventDefault(); move(items[index + 1]); break;
            case 'ArrowUp': event.preventDefault(); move(items[index - 1]); break;
            case 'Home': event.preventDefault(); move(items[0]); break;
            case 'End': event.preventDefault(); move(items[items.length - 1]); break;
            case 'PageDown': event.preventDefault(); move(items[Math.min(items.length - 1, index + 6)]); break;
            case 'PageUp': event.preventDefault(); move(items[Math.max(0, index - 6)]); break;
            case 'ArrowRight':
                if (list === hourList) { event.preventDefault(); minuteList.querySelector('[tabindex="0"]')?.focus(); }
                break;
            case 'ArrowLeft':
                if (list === minuteList) { event.preventDefault(); hourList.querySelector('[tabindex="0"]')?.focus(); }
                break;
            case 'Enter':
            case ' ':
                event.preventDefault();
                choose(item);
                break;
            default:
        }
    });
    wrapper.addEventListener('focusout', () => {
        setTimeout(() => {
            if (open && !wrapper.contains(document.activeElement)) close(false);
        }, 0);
    });

    panel.querySelector('[data-time-now]')?.addEventListener('click', () => {
        commit(nowInZone(zone, step));
        close(true);
    });
    panel.querySelector('[data-time-clear]')?.addEventListener('click', () => {
        commit(null);
        close(true);
    });
    panel.querySelector('[data-time-done]')?.addEventListener('click', () => close(true));
    input.form?.addEventListener('reset', () => setTimeout(() => { showError(''); sync(); }, 0));

    const api = { close };
    sync();
    return api;
}

export function bootTimePickers(root = document) {
    root.querySelectorAll('[data-adasi-time-picker]:not([data-time-ready])').forEach((wrapper) => {
        wrapper.dataset.timeReady = 'true';
        createPicker(wrapper);
    });
}
