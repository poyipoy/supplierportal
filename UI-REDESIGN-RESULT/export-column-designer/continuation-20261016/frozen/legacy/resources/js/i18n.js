export const t = (key, replacements = {}) => globalThis.window?.AdasiI18n?.t(key, replacements) ?? key;
export const choice = (key, count, replacements = {}) => globalThis.window?.AdasiI18n?.choice(key, count, replacements) ?? key;
