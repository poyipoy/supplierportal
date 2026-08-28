import {
    addLocalMonths,
    compareIso,
    formatDateDisplay,
    formatMonthDisplay,
    isIsoDate,
    isIsoMonth,
    localToday,
    normalizeRange,
    rangePresets,
} from './calendar-core';

const MOBILE_BREAKPOINT = window.matchMedia('(max-width: 991.98px)');
const FOCUSABLE = 'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])';
const instances = new WeakMap();
let activeController = null;
let engineReady = false;
let bootStarted = false;
let mobileScrim = null;

const asIsoDate = (value) => {
    if (typeof value === 'string') return value;
    if (value instanceof Date) return `${value.getFullYear()}-${String(value.getMonth() + 1).padStart(2, '0')}-${String(value.getDate()).padStart(2, '0')}`;
    if (value && typeof value.toString === 'function') return value.toString();
    return '';
};

const isoForGranularity = (value, granularity) => granularity === 'month'
    ? (isIsoMonth(value) ? value : '')
    : (isIsoDate(value) ? value : '');

const panelFocusables = (panel) => [...panel.querySelectorAll(FOCUSABLE)]
    .filter((element) => !element.hidden && element.getClientRects().length > 0);

function ensureMobileScrim() {
    if (mobileScrim) return mobileScrim;

    mobileScrim = document.createElement('div');
    mobileScrim.className = 'ui-calendar-mobile-scrim';
    mobileScrim.hidden = true;
    mobileScrim.addEventListener('click', () => activeController?.close({ restoreFocus: true }));
    document.body.append(mobileScrim);

    return mobileScrim;
}

class CalendarController {
    constructor(wrapper, type) {
        this.wrapper = wrapper;
        this.type = type;
        this.granularity = wrapper.dataset.calendarGranularity || 'day';
        this.native = [...wrapper.querySelectorAll('[data-calendar-native-input]')];
        this.nativeContainer = wrapper.querySelector('[data-calendar-native]');
        this.enhanced = wrapper.querySelector('[data-calendar-enhanced]');
        this.panel = wrapper.querySelector('[data-calendar-panel]');
        this.placeholder = document.createComment('adasi-calendar-panel');
        this.trigger = wrapper.querySelector('[data-calendar-trigger]');
        this.boundaryTriggers = [...wrapper.querySelectorAll('[data-calendar-boundary]')];
        this.dayGrid = wrapper.querySelector('[data-calendar-day-grid]');
        this.monthGrid = wrapper.querySelector('[data-calendar-month-grid]');
        this.yearSelect = wrapper.querySelector('[data-calendar-year]');
        this.error = wrapper.querySelector('[data-calendar-error]');
        this.errorMessage = wrapper.querySelector('[data-calendar-error-message]');
        this.liveRegion = wrapper.querySelector('[data-calendar-live]');
        this.context = wrapper.querySelector('[data-calendar-context]');
        this.monthLabel = wrapper.querySelector('[data-calendar-month-label]');
        this.yearLabel = wrapper.querySelector('[data-calendar-year-label]');
        this.prevBtn = wrapper.querySelector('[data-calendar-prev]');
        this.nextBtn = wrapper.querySelector('[data-calendar-next]');
        this.yearToggleBtn = wrapper.querySelector('[data-calendar-year-toggle]');
        this.yearPanel = wrapper.querySelector('[data-calendar-year-panel]');
        this.calendarBody = wrapper.querySelector('[data-calendar-body]');
        this.daysGrid = wrapper.querySelector('[data-calendar-days-grid]');
        this.todayBtn = wrapper.querySelector('[data-calendar-today]');
        this.viewDate = new Date();
        this.isOpen = false;
        this.activeBoundary = 'start';
        this.displayYear = new Date().getFullYear();
        this.committed = this.readNative();
        this.draft = this.cloneValue(this.committed);

        this.panel.before(this.placeholder);
    }

    initialize() {
        if (!this.native.length || !this.panel || !this.enhanced || this.wrapper.dataset.calendarEnhanced === 'true') return;

        this.native.forEach((input) => {
            input.dataset.calendarRequired = input.required ? 'true' : 'false';
            input.required = false;
            input.addEventListener('change', () => this.syncFromNative());
        });

        this.nativeContainer.hidden = true;
        this.enhanced.hidden = false;
        this.wrapper.dataset.calendarEnhanced = 'true';
        this.wrapper.closest('form')?.querySelectorAll('[data-calendar-native-submit]').forEach((button) => {
            button.hidden = true;
        });
        this.updateDisplay();
        this.installListeners();
        this.installFormValidation();
    }

    installListeners() {
        this.trigger?.addEventListener('click', () => this.open());
        this.boundaryTriggers.forEach((button) => {
            button.addEventListener('click', () => {
                this.activeBoundary = button.dataset.calendarBoundary || 'start';
                this.open();
            });
        });
        this.panel.querySelector('[data-calendar-close]')?.addEventListener('click', () => this.close({ restoreFocus: true }));
        this.panel.querySelector('[data-calendar-cancel]')?.addEventListener('click', () => this.close({ restoreFocus: true }));
        this.panel.querySelector('[data-calendar-clear]')?.addEventListener('click', () => this.clearDraft());
        this.panel.querySelector('[data-calendar-today]')?.addEventListener('click', () => this.onTodayClick());
        this.panel.querySelector('[data-calendar-apply]')?.addEventListener('click', () => this.commitRange());
        this.wrapper.addEventListener('adasi:calendar-reset', () => this.syncFromNative());

        if (this.type === 'single') {
            this.installSingleCalendar();
        } else {
            if (this.dayGrid) this.installDayGrid();
            if (this.monthGrid) this.installMonthGrid();
        }

        document.addEventListener('pointerdown', (event) => {
            if (!this.isOpen || this.isMobile() || this.panel.contains(event.target) || this.wrapper.contains(event.target)) return;
            this.close({ restoreFocus: false });
        });
        document.addEventListener('keydown', (event) => this.onKeydown(event));
        window.addEventListener('resize', () => this.reposition());
        MOBILE_BREAKPOINT.addEventListener('change', () => this.handleViewportChange());
    }

    installFormValidation() {
        const form = this.wrapper.closest('form');
        if (!form) return;

        form.addEventListener('submit', (event) => {
            if (!this.isRequired() || this.hasRequiredValue()) return;
            event.preventDefault();
            this.setError('Complete the required date field before continuing.');
            this.focusTrigger();
        });

        form.addEventListener('submit', (event) => {
            const error = this.validationError();
            if (!error) return;
            event.preventDefault();
            this.setError(error);
            this.focusTrigger();
        });
    }

    installSingleCalendar() {
        this.prevBtn?.addEventListener('click', () => this.onPrevClick());
        this.nextBtn?.addEventListener('click', () => this.onNextClick());
        this.yearToggleBtn?.addEventListener('click', () => this.toggleYearPanel());
        this.daysGrid?.addEventListener('click', (event) => {
            const button = event.target.closest('[data-calendar-day]');
            if (!button || button.disabled) return;
            this.draft = button.dataset.calendarDay || '';
            this.commitSingle();
        });
    }

    onPrevClick() {
        if (this.yearPanel && !this.yearPanel.hidden) {
            this.viewDate = new Date(this.viewDate.getFullYear() - 9, this.viewDate.getMonth(), 1);
            this.buildYearPanel();
            if (this.yearLabel) this.yearLabel.textContent = this.viewDate.getFullYear();
            this.announce(`Years ${this.viewDate.getFullYear() - 4} to ${this.viewDate.getFullYear() + 4}.`);
            return;
        }
        this.viewDate = new Date(this.viewDate.getFullYear(), this.viewDate.getMonth() - 1, 1);
        this.renderSingleCalendar();
        this.announce(`${this.monthLabel?.textContent} ${this.viewDate.getFullYear()}.`);
    }

    onNextClick() {
        if (this.yearPanel && !this.yearPanel.hidden) {
            this.viewDate = new Date(this.viewDate.getFullYear() + 9, this.viewDate.getMonth(), 1);
            this.buildYearPanel();
            if (this.yearLabel) this.yearLabel.textContent = this.viewDate.getFullYear();
            this.announce(`Years ${this.viewDate.getFullYear() - 4} to ${this.viewDate.getFullYear() + 4}.`);
            return;
        }
        this.viewDate = new Date(this.viewDate.getFullYear(), this.viewDate.getMonth() + 1, 1);
        this.renderSingleCalendar();
        this.announce(`${this.monthLabel?.textContent} ${this.viewDate.getFullYear()}.`);
    }

    buildYearPanel() {
        if (!this.yearPanel) return;
        const centerYear = this.viewDate.getFullYear();
        const startYear = centerYear - 4;
        let html = '';
        for (let y = startYear; y < startYear + 9; y++) {
            const isCurrent = (y === centerYear);
            html += `<button type="button" class="ui-calendar-year-btn${isCurrent ? ' is-current' : ''}" data-calendar-pick-year="${y}">${y}</button>`;
        }
        this.yearPanel.innerHTML = html;
        this.yearPanel.querySelectorAll('[data-calendar-pick-year]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const selectedYear = parseInt(btn.dataset.calendarPickYear, 10);
                this.viewDate = new Date(selectedYear, this.viewDate.getMonth(), 1);
                this.toggleYearPanel(false);
                this.renderSingleCalendar();
                this.announce(`Year ${selectedYear} selected.`);
            });
        });
    }

    toggleYearPanel(force) {
        if (!this.yearPanel || !this.calendarBody) return;
        const willOpen = force !== undefined ? force : this.yearPanel.hidden;
        if (willOpen) this.buildYearPanel();
        this.yearPanel.hidden = !willOpen;
        this.calendarBody.hidden = willOpen;
        this.yearToggleBtn?.setAttribute('aria-expanded', String(willOpen));
        this.yearToggleBtn?.classList.toggle('is-active', willOpen);
    }

    renderSingleCalendar() {
        if (!this.daysGrid) return;
        const MONTH_NAMES = [
            'January', 'February', 'March', 'April', 'May', 'June',
            'July', 'August', 'September', 'October', 'November', 'December'
        ];
        const year = this.viewDate.getFullYear();
        const month = this.viewDate.getMonth();

        if (this.monthLabel) this.monthLabel.textContent = MONTH_NAMES[month];
        if (this.yearLabel) this.yearLabel.textContent = year;

        // Monday-first grid
        const firstOfMonth = new Date(year, month, 1);
        const startOffset = (firstOfMonth.getDay() + 6) % 7; // 0 = Monday
        const daysInMonth = new Date(year, month + 1, 0).getDate();
        const daysInPrev = new Date(year, month, 0).getDate();

        const today = new Date();
        const isToday = (d) => year === today.getFullYear() && month === today.getMonth() && d === today.getDate();

        let html = '';

        // Trailing days of previous month
        for (let i = startOffset; i > 0; i--) {
            const dayNum = daysInPrev - i + 1;
            html += `<button type="button" class="ui-calendar-day is-outside is-disabled" disabled tabindex="-1">${dayNum}</button>`;
        }

        // Current month days
        for (let d = 1; d <= daysInMonth; d++) {
            const iso = `${year}-${String(month + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
            const classes = ['ui-calendar-day'];
            const todayMatch = isToday(d);
            const selectedMatch = (this.draft === iso);
            const disabledMatch = this.isDayDisallowed(iso);

            if (todayMatch) classes.push('is-today');
            if (selectedMatch) classes.push('is-selected');
            if (disabledMatch) classes.push('is-disabled');

            const attrs = [
                `type="button"`,
                `class="${classes.join(' ')}"`,
                `data-calendar-day="${iso}"`,
                `aria-label="${d} ${MONTH_NAMES[month]} ${year}"`,
            ];
            if (disabledMatch) {
                attrs.push('disabled', 'tabindex="-1"');
            } else {
                attrs.push('tabindex="0"');
            }
            if (selectedMatch) {
                attrs.push('aria-selected="true"');
            }

            html += `<button ${attrs.join(' ')}>${d}</button>`;
        }

        // Leading days of next month to fill grid to multiple of 7
        const totalCells = startOffset + daysInMonth;
        const trailing = (7 - (totalCells % 7)) % 7;
        for (let d = 1; d <= trailing; d++) {
            html += `<button type="button" class="ui-calendar-day is-outside is-disabled" disabled tabindex="-1">${d}</button>`;
        }

        this.daysGrid.innerHTML = html;
    }

    onTodayClick() {
        const todayIso = localToday();
        if (this.isDayDisallowed(todayIso)) {
            this.setError('Today is outside the allowed date range.');
            return;
        }
        this.draft = todayIso;
        const [y, m, d] = todayIso.split('-').map(Number);
        this.viewDate = new Date(y, m - 1, d);
        this.commitSingle();
    }

    onDayGridKeydown(event) {
        const button = event.target.closest('[data-calendar-day]');
        if (!button) return;
        const buttons = [...this.daysGrid.querySelectorAll('[data-calendar-day]:not([disabled])')];
        const current = buttons.indexOf(button);
        if (current < 0) return;
        const movement = { ArrowRight: 1, ArrowLeft: -1, ArrowDown: 7, ArrowUp: -7 }[event.key];
        if (movement !== undefined) {
            event.preventDefault();
            const nextIndex = current + movement;
            if (nextIndex >= 0 && nextIndex < buttons.length) {
                buttons[nextIndex]?.focus();
            }
            return;
        }
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            button.click();
        }
    }

    installDayGrid() {
        this.dayGrid.addEventListener('change', () => {
            if (this.type !== 'single') return;
            const value = isoForGranularity(this.dayGrid.value, 'day');
            if (!value) return;
            this.draft = value;
            this.commitSingle();
        });

        this.dayGrid.addEventListener('rangestart', (event) => this.selectRangeStart(asIsoDate(event.detail)));
        this.dayGrid.addEventListener('rangeend', (event) => this.selectRangeEnd(asIsoDate(event.detail)));
    }

    installMonthGrid() {
        this.panel.querySelector('[data-calendar-year-previous]')?.addEventListener('click', () => {
            this.displayYear -= 1;
            this.renderMonthGrid();
            this.announce(`Calendar year ${this.displayYear}.`);
        });
        this.panel.querySelector('[data-calendar-year-next]')?.addEventListener('click', () => {
            this.displayYear += 1;
            this.renderMonthGrid();
            this.announce(`Calendar year ${this.displayYear}.`);
        });
        this.yearSelect?.addEventListener('change', () => {
            this.displayYear = Number(this.yearSelect.value);
            this.renderMonthGrid();
            this.announce(`Calendar year ${this.displayYear}.`);
        });
        this.monthGrid.addEventListener('click', (event) => {
            const button = event.target.closest('[data-calendar-month]');
            if (!button || button.disabled) return;
            this.selectMonth(button.dataset.calendarMonth || '');
        });
        this.monthGrid.addEventListener('keydown', (event) => this.onMonthGridKeydown(event));
    }

    onKeydown(event) {
        if (!this.isOpen) return;
        if (event.key === 'Escape') {
            event.preventDefault();
            this.close({ restoreFocus: true });
            return;
        }
        if (this.type === 'single' && this.daysGrid && this.daysGrid.contains(document.activeElement)) {
            this.onDayGridKeydown(event);
            return;
        }
        if (event.key !== 'Tab' || !this.isMobile()) return;

        const items = panelFocusables(this.panel);
        if (!items.length) return;
        const first = items[0];
        const last = items[items.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    }

    onMonthGridKeydown(event) {
        const button = event.target.closest('[data-calendar-month]');
        if (!button) return;
        const buttons = [...this.monthGrid.querySelectorAll('[data-calendar-month]:not([disabled])')];
        const current = buttons.indexOf(button);
        if (current < 0) return;
        const movement = { ArrowRight: 1, ArrowLeft: -1, ArrowDown: 4, ArrowUp: -4 }[event.key];
        if (movement === undefined) return;
        event.preventDefault();
        buttons[(current + movement + buttons.length) % buttons.length]?.focus();
    }

    open() {
        if (this.isOpen) {
            this.reposition();
            return;
        }

        activeController?.close({ restoreFocus: false });
        activeController = this;
        this.committed = this.readNative();
        this.draft = this.cloneValue(this.committed);
        this.activeBoundary = this.type === 'range' ? this.activeBoundary : 'start';
        this.clearError();
        this.movePanelToLayer();
        this.isOpen = true;
        this.wrapper.dataset.calendarOpen = 'true';
        this.panel.hidden = false;
        this.syncPanel();
        this.reposition();
        this.announce(this.type === 'range' ? `Select ${this.activeBoundary === 'start' ? 'a start' : 'an end'} ${this.granularity === 'month' ? 'month' : 'date'}.` : 'Select a date.');

        window.requestAnimationFrame(() => {
            this.panel.classList.add('is-open');
            this.focusInitialCalendarTarget();
        });
    }

    close({ restoreFocus = false } = {}) {
        if (!this.isOpen) return;

        this.isOpen = false;
        this.wrapper.dataset.calendarOpen = 'false';
        this.panel.classList.remove('is-open');
        this.trigger?.setAttribute('aria-expanded', 'false');
        this.boundaryTriggers.forEach((button) => button.setAttribute('aria-expanded', 'false'));
        if (this.type === 'single') {
            this.toggleYearPanel(false);
        }
        if (this.isMobile()) {
            document.body.classList.remove('ui-calendar-sheet-open');
            const scrim = ensureMobileScrim();
            scrim.classList.remove('is-open');
            window.setTimeout(() => { scrim.hidden = true; }, 220);
        }
        window.setTimeout(() => {
            if (!this.isOpen) {
                this.panel.hidden = true;
                this.placeholder.after(this.panel);
            }
        }, this.prefersReducedMotion() ? 0 : 160);
        if (activeController === this) activeController = null;
        if (restoreFocus) this.focusTrigger();
    }

    movePanelToLayer() {
        document.body.append(this.panel);
        if (this.isMobile()) {
            const scrim = ensureMobileScrim();
            scrim.hidden = false;
            document.body.classList.add('ui-calendar-sheet-open');
            window.requestAnimationFrame(() => scrim.classList.add('is-open'));
        }
    }

    reposition() {
        if (!this.isOpen || this.panel.hidden) return;
        const mobile = this.isMobile();
        this.panel.classList.toggle('ui-calendar-panel--sheet', mobile);
        this.panel.classList.toggle('ui-calendar-panel--popover', !mobile);
        this.panel.setAttribute('aria-modal', String(mobile));
        this.dayGrid?.setAttribute('months', mobile ? '1' : this.type === 'range' ? '2' : '1');
        this.trigger?.setAttribute('aria-expanded', 'true');
        this.boundaryTriggers.forEach((button) => button.setAttribute('aria-expanded', 'true'));
        if (mobile) return;

        const anchor = this.type === 'single'
            ? this.trigger
            : this.boundaryTriggers.find((button) => button.dataset.calendarBoundary === this.activeBoundary) || this.boundaryTriggers[0];
        if (!anchor) return;

        const rect = anchor.getBoundingClientRect();
        const panelRect = this.panel.getBoundingClientRect();
        const gap = parseFloat(getComputedStyle(this.panel).getPropertyValue('--ui-calendar-anchor-gap')) || 8;
        const top = rect.bottom + panelRect.height + gap > window.innerHeight && rect.top > panelRect.height
            ? Math.max(gap, rect.top - panelRect.height - gap)
            : Math.min(window.innerHeight - panelRect.height - gap, rect.bottom + gap);
        const left = Math.min(Math.max(gap, rect.left), window.innerWidth - panelRect.width - gap);
        this.panel.style.top = `${Math.round(top)}px`;
        this.panel.style.left = `${Math.round(left)}px`;
    }

    handleViewportChange() {
        if (!this.isOpen) return;
        if (this.isMobile()) {
            this.movePanelToLayer();
        } else {
            document.body.classList.remove('ui-calendar-sheet-open');
            const scrim = ensureMobileScrim();
            scrim.classList.remove('is-open');
            scrim.hidden = true;
        }
        this.reposition();
    }

    readNative() {
        if (this.type === 'single') return isoForGranularity(this.native[0]?.value, 'day');
        return normalizeRange(
            isoForGranularity(this.native[0]?.value, this.granularity),
            isoForGranularity(this.native[1]?.value, this.granularity),
        );
    }

    cloneValue(value) {
        return this.type === 'single' ? value : { ...value };
    }

    isRequired() {
        return this.wrapper.dataset.calendarRequired === 'true';
    }

    hasRequiredValue() {
        return this.type === 'single'
            ? Boolean(this.native[0]?.value)
            : Boolean(this.native[0]?.value && this.native[1]?.value);
    }

    validationError() {
        const values = this.type === 'single'
            ? [this.native[0]?.value]
            : [this.native[0]?.value, this.native[1]?.value];

        for (const [index, value] of values.entries()) {
            if (!value) continue;
            const input = this.native[index];
            if (input?.min && compareIso(value, input.min) < 0) return 'Choose a date within the allowed range.';
            if (input?.max && compareIso(value, input.max) > 0) return 'Choose a date within the allowed range.';
        }
        if (this.type === 'range' && values[0] && values[1] && compareIso(values[1], values[0]) < 0) {
            return 'The end date cannot be before the start date.';
        }
        return '';
    }

    isDayDisallowed(value) {
        if (!isIsoDate(value)) return true;
        const input = this.type === 'single'
            ? this.native[0]
            : this.native[this.activeBoundary === 'end' ? 1 : 0];
        if (input?.min && compareIso(value, input.min) < 0) return true;
        if (input?.max && compareIso(value, input.max) > 0) return true;
        return this.type === 'range'
            && this.activeBoundary === 'end'
            && Boolean(this.draft.start)
            && compareIso(value, this.draft.start) < 0;
    }

    setNativeValue(input, value) {
        if (!input) return;
        input.value = value || '';
    }

    emitNativeCommit(input) {
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
    }

    commitSingle() {
        this.setNativeValue(this.native[0], this.draft);
        this.committed = this.draft;
        this.updateDisplay();
        this.emitNativeCommit(this.native[0]);
        this.clearError();
        this.close({ restoreFocus: true });
    }

    commitRange() {
        if (this.draft.start && this.draft.end && compareIso(this.draft.end, this.draft.start) < 0) {
            this.setError('The end date cannot be before the start date.');
            return;
        }
        this.setNativeValue(this.native[0], this.draft.start);
        this.setNativeValue(this.native[1], this.draft.end);
        this.committed = this.cloneValue(this.draft);
        this.updateDisplay();
        this.clearError();
        this.wrapper.dispatchEvent(new CustomEvent('adasi:date-range-commit', {
            bubbles: true,
            detail: { start: this.committed.start, end: this.committed.end, granularity: this.granularity },
        }));
        this.close({ restoreFocus: true });
    }

    clearDraft() {
        if (this.type === 'single') {
            this.draft = '';
            this.commitSingle();
            return;
        }
        this.draft = { start: '', end: '' };
        this.syncPanel();
        this.announce('Date range cleared. Select Apply to use the cleared range.');
    }

    selectRangeStart(value) {
        if (!isIsoDate(value)) return;
        if (this.activeBoundary === 'end') {
            if (this.draft.start && compareIso(value, this.draft.start) < 0) return;
            this.draft.end = value;
            this.dayGrid.value = this.draft.start ? `${this.draft.start}/${value}` : `${value}/${value}`;
            this.dayGrid.tentative = '';
            this.activeBoundary = 'start';
            this.syncPanel();
            this.announce('End date selected. Select Apply to use this range.');
            return;
        }
        const next = value;
        const oldEnd = this.draft.end;
        this.draft.start = next;
        this.draft.end = oldEnd && compareIso(next, oldEnd) > 0 ? '' : oldEnd;
        this.activeBoundary = 'end';
        this.dayGrid.tentative = next;
        this.dayGrid.value = this.draft.end ? `${this.draft.start}/${this.draft.end}` : '';
        this.syncPanel();
        this.announce(this.draft.end ? 'Start date updated. Select Apply to use this range.' : 'Start date selected. Select an end date.');
    }

    selectRangeEnd(value) {
        if (!isIsoDate(value)) return;
        if (!this.draft.start) {
            this.draft.end = value;
            this.dayGrid.value = `${value}/${value}`;
        } else if (compareIso(value, this.draft.start) >= 0) {
            this.draft.end = value;
            this.dayGrid.value = `${this.draft.start}/${value}`;
        }
        this.dayGrid.tentative = '';
        this.activeBoundary = 'start';
        this.syncPanel();
        this.announce(this.draft.start ? 'End date selected. Select Apply to use this range.' : 'End date selected. Select Apply to use this range.');
    }

    selectMonth(value) {
        if (!isIsoMonth(value)) return;
        if (this.activeBoundary === 'start') {
            this.draft.start = value;
            if (this.draft.end && compareIso(value, this.draft.end) > 0) this.draft.end = '';
            this.activeBoundary = 'end';
            this.announce('Start month selected. Select an end month.');
        } else {
            if (this.draft.start && compareIso(value, this.draft.start) < 0) return;
            this.draft.end = value;
            this.activeBoundary = 'start';
            this.announce('End month selected. Select Apply to use this range.');
        }
        this.renderMonthGrid();
        this.updatePanelContext();
    }

    syncPanel() {
        if (this.type === 'single') {
            if (this.draft && isIsoDate(this.draft)) {
                const [y, m, d] = this.draft.split('-').map(Number);
                this.viewDate = new Date(y, m - 1, d);
            } else {
                this.viewDate = new Date();
            }
            this.toggleYearPanel(false);
            this.renderSingleCalendar();
            return;
        }

        this.updatePanelContext();
        this.renderPresets();
        if (this.granularity === 'month') {
            const focused = this.activeBoundary === 'end' ? this.draft.end : this.draft.start;
            this.displayYear = Number((focused || `${new Date().getFullYear()}-01`).slice(0, 4));
            this.renderMonthGrid();
            return;
        }
        if (this.dayGrid) {
            this.dayGrid.value = this.draft.start && this.draft.end ? `${this.draft.start}/${this.draft.end}` : '';
            this.dayGrid.tentative = this.draft.start && !this.draft.end ? this.draft.start : '';
            this.dayGrid.focusedDate = this.draft[this.activeBoundary] || this.draft.start || this.draft.end || localToday();
            this.dayGrid.isDateDisallowed = (date) => this.isDayDisallowed(asIsoDate(date));
        }
    }

    updatePanelContext() {
        if (!this.context) return;
        const label = this.activeBoundary === 'end' ? 'end' : 'start';
        this.context.textContent = `Select ${label} ${this.granularity === 'month' ? 'month' : 'date'}`;
    }

    renderPresets() {
        const container = this.panel.querySelector('[data-calendar-presets]');
        if (!container || container.dataset.calendarBound === 'true') return;
        container.dataset.calendarBound = 'true';
        rangePresets(this.granularity).forEach((preset) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'ui-calendar-preset';
            button.textContent = preset.label;
            button.addEventListener('click', () => {
                this.draft = { start: preset.start, end: preset.end };
                this.activeBoundary = 'start';
                this.syncPanel();
                this.announce(`${preset.label} selected. Select Apply to use this range.`);
            });
            container.append(button);
        });
    }

    renderMonthGrid() {
        if (!this.monthGrid || !this.yearSelect) return;
        const currentYear = new Date().getFullYear();
        const minYear = Number((this.native[0]?.min || '').slice(0, 4)) || currentYear - 40;
        const maxYear = Number((this.native[1]?.max || this.native[0]?.max || '').slice(0, 4)) || currentYear + 40;
        const lower = Math.min(minYear, this.displayYear - 20);
        const upper = Math.max(maxYear, this.displayYear + 20);
        this.yearSelect.replaceChildren(...Array.from({ length: upper - lower + 1 }, (_, index) => {
            const option = document.createElement('option');
            option.value = String(lower + index);
            option.textContent = String(lower + index);
            option.selected = lower + index === this.displayYear;
            return option;
        }));

        const formatter = new Intl.DateTimeFormat('en-GB', { month: 'short' });
        this.monthGrid.replaceChildren(...Array.from({ length: 12 }, (_, index) => {
            const value = `${this.displayYear}-${String(index + 1).padStart(2, '0')}`;
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'ui-month-grid__month';
            button.dataset.calendarMonth = value;
            button.textContent = formatter.format(new Date(this.displayYear, index, 1));
            button.setAttribute('role', 'gridcell');
            const selectedStart = this.draft.start === value;
            const selectedEnd = this.draft.end === value;
            const inRange = this.draft.start && this.draft.end && compareIso(value, this.draft.start) > 0 && compareIso(value, this.draft.end) < 0;
            if (selectedStart) button.dataset.rangeStart = 'true';
            if (selectedEnd) button.dataset.rangeEnd = 'true';
            if (inRange) button.dataset.rangeInner = 'true';
            const min = this.activeBoundary === 'end' && this.draft.start ? this.draft.start : (this.native[this.activeBoundary === 'end' ? 1 : 0]?.min || '');
            const max = this.native[this.activeBoundary === 'end' ? 1 : 0]?.max || '';
            button.disabled = Boolean((min && compareIso(value, min) < 0) || (max && compareIso(value, max) > 0));
            button.setAttribute('aria-pressed', String(selectedStart || selectedEnd || inRange));
            return button;
        }));
    }

    updateDisplay() {
        if (this.type === 'single') {
            const display = this.wrapper.querySelector('[data-calendar-display]');
            const displayValue = formatDateDisplay(this.committed);
            if (display) display.textContent = displayValue;
            const label = this.trigger?.dataset.calendarLabel || 'Choose date';
            this.trigger?.setAttribute('aria-label', this.committed ? `${label}: ${displayValue}` : label);
            return;
        }
        this.wrapper.querySelector('[data-calendar-display="start"]')?.replaceChildren(document.createTextNode(this.formatRangeValue(this.committed.start)));
        this.wrapper.querySelector('[data-calendar-display="end"]')?.replaceChildren(document.createTextNode(this.formatRangeValue(this.committed.end)));
    }

    formatRangeValue(value) {
        if (!value) return 'Any time';
        return this.granularity === 'month' ? formatMonthDisplay(value) : formatDateDisplay(value);
    }

    syncFromNative() {
        this.committed = this.readNative();
        this.draft = this.cloneValue(this.committed);
        this.updateDisplay();
        if (this.isOpen) this.syncPanel();
        this.clearError();
    }

    setError(message) {
        if (!this.error || !this.errorMessage) return;
        this.errorMessage.textContent = message;
        this.error.classList.remove('tw-hidden');
        this.wrapper.dataset.calendarInvalid = 'true';
        const target = this.type === 'single' ? this.trigger : this.boundaryTriggers[0];
        target?.setAttribute('aria-invalid', 'true');
    }

    clearError() {
        this.error?.classList.add('tw-hidden');
        this.wrapper.dataset.calendarInvalid = 'false';
        this.trigger?.removeAttribute('aria-invalid');
        this.boundaryTriggers.forEach((button) => button.removeAttribute('aria-invalid'));
    }

    announce(message) {
        if (this.liveRegion) this.liveRegion.textContent = message;
    }

    focusInitialCalendarTarget() {
        if (this.granularity === 'month') {
            this.monthGrid?.querySelector('[aria-pressed="true"]:not([disabled]), [data-calendar-month]:not([disabled])')?.focus();
            return;
        }
        if (this.type === 'single') {
            const target = this.daysGrid?.querySelector('.ui-calendar-day.is-selected:not([disabled]), .ui-calendar-day.is-today:not([disabled]), .ui-calendar-day:not([disabled])');
            target?.focus({ preventScroll: true });
            return;
        }
        const date = this.draft[this.activeBoundary] || this.draft.start || this.draft.end;
        if (date && this.dayGrid) this.dayGrid.focusedDate = date;
        window.setTimeout(() => this.dayGrid?.focus({ target: 'day', preventScroll: true }), 40);
    }

    focusTrigger() {
        const trigger = this.type === 'single'
            ? this.trigger
            : this.boundaryTriggers.find((button) => button.dataset.calendarBoundary === this.activeBoundary) || this.boundaryTriggers[0];
        trigger?.focus({ preventScroll: true });
    }

    isMobile() {
        return MOBILE_BREAKPOINT.matches;
    }

    prefersReducedMotion() {
        return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    }
}

export function initializeAdasiCalendars(root = document) {
    if (!engineReady) return;
    root.querySelectorAll?.('[data-adasi-date-picker]').forEach((wrapper) => {
        if (!instances.has(wrapper)) {
            const controller = new CalendarController(wrapper, 'single');
            instances.set(wrapper, controller);
            controller.initialize();
        }
    });
    root.querySelectorAll?.('[data-adasi-date-range]').forEach((wrapper) => {
        if (!instances.has(wrapper)) {
            const controller = new CalendarController(wrapper, 'range');
            instances.set(wrapper, controller);
            controller.initialize();
        }
    });
}

export function resetAdasiCalendar(target) {
    const wrapper = target instanceof Element
        ? target.closest('[data-adasi-date-picker], [data-adasi-date-range]') || target
        : null;
    const controller = wrapper ? instances.get(wrapper) : null;
    controller?.syncFromNative();
}

window.AdasiCalendar = Object.freeze({
    initialize: initializeAdasiCalendars,
    reset: resetAdasiCalendar,
});

export async function bootAdasiCalendars() {
    if (bootStarted) return;
    bootStarted = true;
    try {
        await import('cally');
        engineReady = true;
        initializeAdasiCalendars();
    } catch (error) {
        engineReady = true;
        initializeAdasiCalendars();
    }
}
