const ISO_DATE = /^\d{4}-(\d{2})-(\d{2})$/;
const ISO_MONTH = /^\d{4}-(\d{2})$/;

const pad = (value) => String(value).padStart(2, '0');

export const isIsoDate = (value) => {
    const match = String(value || '').match(ISO_DATE);
    if (!match) return false;

    const date = new Date(Number(value.slice(0, 4)), Number(match[1]) - 1, Number(match[2]));
    return date.getFullYear() === Number(value.slice(0, 4))
        && date.getMonth() === Number(match[1]) - 1
        && date.getDate() === Number(match[2]);
};

export const isIsoMonth = (value) => {
    const match = String(value || '').match(ISO_MONTH);
    return Boolean(match) && Number(match[1]) >= 1 && Number(match[1]) <= 12;
};

export const compareIso = (left, right) => String(left || '').localeCompare(String(right || ''));

export const localToday = (date = new Date()) => `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;

export const formatDateDisplay = (value, locale = 'en-GB') => {
    if (!isIsoDate(value)) return 'Choose date';
    const [year, month, day] = value.split('-').map(Number);

    return new Intl.DateTimeFormat(locale, {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    }).format(new Date(year, month - 1, day));
};

export const formatMonthDisplay = (value, locale = 'en-GB') => {
    if (!isIsoMonth(value)) return 'Any time';
    const [year, month] = value.split('-').map(Number);

    return new Intl.DateTimeFormat(locale, {
        month: 'short',
        year: 'numeric',
    }).format(new Date(year, month - 1, 1));
};

export const addLocalDays = (value, amount) => {
    if (!isIsoDate(value)) return '';
    const [year, month, day] = value.split('-').map(Number);
    const date = new Date(year, month - 1, day);
    date.setDate(date.getDate() + amount);
    return localToday(date);
};

export const addLocalMonths = (value, amount) => {
    if (!isIsoMonth(value)) return '';
    const [year, month] = value.split('-').map(Number);
    const date = new Date(year, month - 1 + amount, 1);
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}`;
};

export const startOfLocalMonth = (value) => {
    if (!isIsoDate(value)) return '';
    return `${value.slice(0, 7)}-01`;
};

export const rangePresets = (granularity, date = new Date()) => {
    if (granularity === 'month') {
        const currentMonth = `${date.getFullYear()}-${pad(date.getMonth() + 1)}`;
        const yearStart = `${date.getFullYear()}-01`;

        return [
            { id: 'last-3-months', label: 'Last 3 months', start: addLocalMonths(currentMonth, -2), end: currentMonth },
            { id: 'last-6-months', label: 'Last 6 months', start: addLocalMonths(currentMonth, -5), end: currentMonth },
            { id: 'this-year', label: 'This year', start: yearStart, end: currentMonth },
        ];
    }

    const today = localToday(date);

    return [
        { id: 'today', label: 'Today', start: today, end: today },
        { id: 'last-7-days', label: 'Last 7 days', start: addLocalDays(today, -6), end: today },
        { id: 'last-30-days', label: 'Last 30 days', start: addLocalDays(today, -29), end: today },
        { id: 'this-month', label: 'This month', start: startOfLocalMonth(today), end: today },
    ];
};

export const normalizeRange = (start, end) => ({
    start: start || '',
    end: end || '',
});
