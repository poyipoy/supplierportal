/**
 * ADASI Portal Supplier — Regional preferences live sample
 *
 * Pure, dependency-free formatter used by the Customization page to preview
 * timezone/date/time/number choices *before* they are saved. Mirrors the
 * algorithm bootstrapped inline in layouts/app.blade.php (window.AdasiPreferences),
 * reimplemented here (not imported) because that bootstrap script runs before
 * Vite-bundled modules are available and must stay dependency-free. Keep the
 * two in sync manually; tests/js/regional-sample.test.mjs checks parity.
 */

export function monthName(month, locale, registryMonths) {
    if (locale !== 'id' && Array.isArray(registryMonths) && typeof registryMonths[month - 1] === 'string') {
        return registryMonths[month - 1];
    }

    return new Intl.DateTimeFormat(locale === 'id' ? 'id-ID' : 'en-GB', { month: 'short', timeZone: 'UTC' })
        .format(new Date(Date.UTC(2000, month - 1, 1)));
}

/**
 * Supplier Import display zone; mirrors UserPreferenceService::resolveDisplayTimezone().
 * Cases are shared with PHP through tests/Fixtures/import-timezone-resolution-cases.json.
 * @param {{importContext: boolean, timezone?: string, countryCode?: string|null, importTimezone?: string|null,
 *   countryDefaults?: Record<string, string>, knownZone?: (zone: string) => boolean}} input
 */
export function resolveImportTimezone({ importContext, timezone, countryCode, importTimezone, countryDefaults, knownZone = isKnownTimeZone }) {
    const global = typeof timezone === 'string' && timezone !== '' ? timezone : 'system';
    if (!importContext) return global;
    if (importTimezone === 'system' || (typeof importTimezone === 'string' && importTimezone !== '' && knownZone(importTimezone))) {
        return importTimezone;
    }
    const mapped = typeof countryCode === 'string' ? countryDefaults?.[countryCode] : undefined;

    return typeof mapped === 'string' ? mapped : global;
}

export function isKnownTimeZone(zone) {
    if (typeof zone !== 'string' || zone === '' || zone === 'system') return false;
    try {
        new Intl.DateTimeFormat('en-GB', { timeZone: zone });
        return true;
    } catch (error) {
        return false;
    }
}

/** Wall-clock parts of an instant in `zone`, plus its suffix (WIB for Jakarta, otherwise UTC+-HH:MM). */
export function zonedParts(isoValue, zone) {
    const instant = new Date(isoValue);
    const formatted = new Intl.DateTimeFormat('en-GB', {
        timeZone: zone, calendar: 'gregory', numberingSystem: 'latn',
        year: 'numeric', month: '2-digit', day: '2-digit',
        hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23',
    }).formatToParts(instant);
    const values = Object.fromEntries(formatted.map((part) => [part.type, part.value]));
    const parts = {
        year: Number(values.year), month: Number(values.month), day: Number(values.day),
        hour: Number(values.hour) % 24, minute: Number(values.minute),
    };

    return { parts, suffix: zoneSuffix(zone, parts, Number(values.second), instant) };
}

export function zoneSuffix(zone, parts, second, instant) {
    if (zone === 'Asia/Jakarta') return 'WIB';
    const wall = Date.UTC(parts.year, parts.month - 1, parts.day, parts.hour, parts.minute, second);
    const offset = Math.round((wall - Math.floor(instant.getTime() / 1000) * 1000) / 60000);
    const absolute = Math.abs(offset);

    return 'UTC' + (offset < 0 ? '-' : '+') + String(Math.floor(absolute / 60)).padStart(2, '0') + ':' + String(absolute % 60).padStart(2, '0');
}

/**
 * @param {string} isoValue UTC instant, e.g. "2026-10-08T07:30:00Z"
 * @param {{timezone?: string, date_format?: string, time_format?: string}} regional
 * @param {string[]} registryDateFormats allow-listed date_format keys
 * @param {string} locale "en" or "id"
 * @param {string[]} registryMonths localized month abbreviations
 * @returns {string|null} formatted date-time, or null when every field is "system"
 *   (caller should show a "follows current display" notice instead)
 */
export function computeDateTimeSample(isoValue, regional, registryDateFormats, locale, registryMonths) {
    const match = typeof isoValue === 'string'
        ? isoValue.match(/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.\d+)?Z$/)
        : null;
    if (!match) return null;

    const timezone = isKnownTimeZone(regional.timezone) ? regional.timezone : 'system';
    const dateFormat = Array.isArray(registryDateFormats) && registryDateFormats.includes(regional.date_format)
        ? regional.date_format : 'system';
    const timeFormat = ['system', '24h', '12h'].includes(regional.time_format) ? regional.time_format : 'system';
    const explicit = timezone !== 'system' || dateFormat !== 'system' || timeFormat !== 'system';
    if (!explicit) return null;

    let parts = {
        year: Number(match[1]), month: Number(match[2]), day: Number(match[3]),
        hour: Number(match[4]), minute: Number(match[5]),
    };

    let suffix = '';
    if (timezone !== 'system') {
        ({ parts, suffix } = zonedParts(isoValue, timezone));
    }

    const year = String(parts.year).padStart(4, '0');
    const month = String(parts.month).padStart(2, '0');
    const day = String(parts.day).padStart(2, '0');

    let dateText;
    if (dateFormat === 'iso') dateText = `${year}-${month}-${day}`;
    else if (dateFormat === 'dmy') dateText = `${day}/${month}/${year}`;
    else dateText = `${day} ${monthName(parts.month, locale, registryMonths)} ${year}`;

    let timeText;
    if (timeFormat === '12h') {
        const hour = parts.hour % 12 || 12;
        timeText = `${hour}:${String(parts.minute).padStart(2, '0')} ${parts.hour < 12 ? 'AM' : 'PM'}`;
    } else {
        timeText = `${String(parts.hour).padStart(2, '0')}:${String(parts.minute).padStart(2, '0')}`;
    }

    return `${dateText} ${timeText}${suffix ? ' ' + suffix : ''}`;
}

/**
 * @param {string} text numeric literal already written in `sourceProfile` notation
 * @param {string} numberFormat target profile key ("international" | "indonesian" | "system")
 * @param {Record<string, {decimal: string, group: string}>} numberProfiles
 * @param {string} [sourceProfile]
 */
export function computeNumberSample(text, numberFormat, numberProfiles, sourceProfile = 'international') {
    if (!['international', 'indonesian'].includes(numberFormat)) return text;

    const source = numberProfiles?.[sourceProfile];
    const destination = numberProfiles?.[numberFormat];
    if (!source || !destination) return text;

    const match = typeof text === 'string' ? text.match(/^(\s*)([+-]?)([\d.,]+)(\s*)$/) : null;
    if (!match) return text;

    const decimalParts = match[3].split(source.decimal);
    if (decimalParts.length > 2 || (decimalParts.length === 2 && !/^\d+$/.test(decimalParts[1]))) return text;

    const integer = decimalParts[0];
    const groups = source.group ? integer.split(source.group) : [integer];
    if (groups.length > 1 && (!/^\d{1,3}$/.test(groups[0]) || groups.slice(1).some((group) => !/^\d{3}$/.test(group)))) return text;
    if (groups.some((group) => !/^\d+$/.test(group))) return text;

    const digits = groups.join('');
    const grouped = destination.group ? digits.replace(/\B(?=(\d{3})+(?!\d))/g, destination.group) : digits;
    const fraction = decimalParts.length === 2 ? destination.decimal + decimalParts[1] : '';

    return match[1] + match[2] + grouped + fraction + match[4];
}

/**
 * @param {object} options
 * @param {string} options.sampleIso
 * @param {string} options.sampleNumber
 * @param {{timezone?: string, date_format?: string, time_format?: string, number_format?: string}} options.regional
 * @param {{date_formats: string[], number_profiles: object, months: string[]}} options.registry
 * @param {string} options.locale
 * @param {string} options.systemNotice copy shown when the date/time portion has no explicit preference
 */
export function computeRegionalSample({ sampleIso, sampleNumber, regional, registry, locale, systemNotice }) {
    const dateTime = computeDateTimeSample(sampleIso, regional, registry?.date_formats, locale, registry?.months);
    const number = computeNumberSample(sampleNumber, regional?.number_format, registry?.number_profiles);

    return `${dateTime ?? systemNotice} · ${number}`;
}

if (typeof window !== 'undefined') {
    window.AdasiRegionalSample = Object.freeze({ computeRegionalSample, computeDateTimeSample, computeNumberSample, resolveImportTimezone });
}
