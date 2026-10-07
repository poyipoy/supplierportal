(function (window, document) {
    'use strict';
    let payload = {};
    try { payload = JSON.parse(document.getElementById('adasi-i18n')?.textContent || '{}'); } catch { /* Safe English default. */ }
    const messages = payload.messages && typeof payload.messages === 'object' ? payload.messages : {};
    const own = (object, key) => Object.prototype.hasOwnProperty.call(object, key);
    const interpolate = (text, replacements) => String(text).replace(/:([a-zA-Z_][a-zA-Z0-9_]*)/g, (placeholder, key) => own(replacements, key) ? String(replacements[key]) : placeholder);
    const t = (key, replacements = {}) => own(messages, key) && typeof messages[key] === 'string'
        ? interpolate(messages[key], replacements) : String(key);
    const choice = (key, count, replacements = {}) => {
        const forms = own(messages, key) ? messages[key] : null;
        if (!forms || typeof forms !== 'object') return t(key, { ...replacements, count });
        const branch = Number(count) === 0 ? 'zero' : Number(count) === 1 ? 'one' : 'other';
        return interpolate(own(forms, branch) ? forms[branch] : forms.other || key, { count, ...replacements });
    };
    window.AdasiI18n = Object.freeze({ locale: payload.locale === 'id' ? 'id' : 'en', t, choice });
})(window, document);
