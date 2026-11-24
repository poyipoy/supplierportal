/**
 * ADASI Portal Supplier — Safe DOM HTML Sanitization Utility
 *
 * Uses the native Sanitizer API (`Element.prototype.setHTML`) when supported,
 * and falls back to DOMParser-based node sanitization + `replaceChildren()`
 * so untrusted strings never reach `.innerHTML` without sanitization and
 * sanitized nodes are moved directly without mXSS string re-parsing.
 */

const DANGEROUS_ELEMENTS = new Set([
    'script',
    'iframe',
    'object',
    'embed',
    'applet',
    'base',
    'meta',
    'link',
    'style',
    'form',
    'math',
    'svg',
    'template',
]);

// Active/executable SVG elements blocked even when `allowSvg: true` is enabled for trusted server markup.
const SVG_UNSAFE_ELEMENTS = new Set([
    'foreignobject',
    'animate',
    'animatemotion',
    'animatetransform',
    'set',
    'handler',
]);

const URL_ATTRS = new Set([
    'href',
    'src',
    'action',
    'formaction',
    'xlink:href',
    'poster',
    'background',
]);

function isDangerousUrlValue(value) {
    const normalized = String(value || '')
        .replace(/[\u0000-\u0020]+/g, '')
        .toLowerCase();

    return (
        normalized.startsWith('javascript:')
        || normalized.startsWith('vbscript:')
        || normalized.startsWith('data:text/html')
        || normalized.startsWith('data:image/svg+xml')
    );
}

function isDangerousSrcset(value) {
    const raw = String(value || '');
    if (isDangerousUrlValue(raw)) return true;
    // Check each comma-separated candidate URL in srcset (e.g. "safe.png 1x, javascript:alert(1) 2x").
    return raw.split(',').some((candidate) => {
        const urlPart = candidate.trim().split(/\s+/)[0] || '';
        return isDangerousUrlValue(urlPart);
    });
}

function sanitizeNodeTree(container, blocked, options) {
    if (!container || typeof container.querySelectorAll !== 'function') return;

    const nodes = Array.from(container.querySelectorAll('*'));
    for (const el of nodes) {
        if (el.isConnected === false) continue;
        const tag = el.tagName.toLowerCase();

        if (tag === 'script') {
            const type = (el.getAttribute('type') || '').toLowerCase();
            const isXhtmlNamespace = !el.namespaceURI || el.namespaceURI === 'http://www.w3.org/1999/xhtml';
            const insideForeignContent = typeof el.closest === 'function' && Boolean(el.closest('svg, math'));
            if (options.allowJsonScripts && type === 'application/json' && isXhtmlNamespace && !insideForeignContent) {
                try {
                    const data = JSON.parse(el.textContent || '{}');
                    el.textContent = JSON.stringify(data)
                        .replace(/</g, '\\u003c')
                        .replace(/>/g, '\\u003e')
                        .replace(/&/g, '\\u0026');
                    for (const attr of Array.from(el.attributes)) {
                        const attrName = attr.name.toLowerCase();
                        if (attrName !== 'id' && attrName !== 'type' && !/^data-[a-z0-9-]+$/i.test(attrName)) {
                            el.removeAttribute(attr.name);
                        }
                    }
                    continue;
                } catch {
                    // Invalid JSON payload in script tag: remove it.
                }
            }
            el.remove();
            continue;
        }

        if (tag === 'template') {
            if (!options.allowTemplate) {
                el.remove();
                continue;
            }
            for (const attr of Array.from(el.attributes)) {
                const name = attr.name.toLowerCase();
                if (name.startsWith('on') || name === 'srcdoc') {
                    el.removeAttribute(attr.name);
                }
            }
            if (el.content) {
                sanitizeNodeTree(el.content, blocked, options);
            }
            continue;
        }

        if (blocked.has(tag) || (options.allowSvg && SVG_UNSAFE_ELEMENTS.has(tag))) {
            el.remove();
            continue;
        }

        for (const attr of Array.from(el.attributes)) {
            const name = attr.name.toLowerCase();
            if (name.startsWith('on') || name === 'srcdoc') {
                el.removeAttribute(attr.name);
                continue;
            }
            if (URL_ATTRS.has(name) && isDangerousUrlValue(attr.value)) {
                el.removeAttribute(attr.name);
                continue;
            }
            if (name === 'srcset' && isDangerousSrcset(attr.value)) {
                el.removeAttribute(attr.name);
            }
        }
    }
}

/**
 * Sanitize an untrusted HTML string into a detached DocumentFragment using DOMParser.
 * Removes scripts, active/embed elements, `<template>.content` payloads, event handler
 * attributes (`on*`), and dangerous URL schemes.
 *
 * @param {string} html
 * @param {Document} [doc]
 * @param {{ allowSvg?: boolean, allowForm?: boolean, allowJsonScripts?: boolean, allowStyle?: boolean, allowTemplate?: boolean }} [options]
 * @returns {DocumentFragment}
 */
export function sanitizeHtmlToFragment(html, doc = globalThis.document, options = {}) {
    const fragment = doc.createDocumentFragment();
    const raw = String(html ?? '');
    if (raw === '') {
        return fragment;
    }

    const Parser = doc?.defaultView?.DOMParser ?? globalThis.DOMParser;
    if (typeof Parser !== 'function') {
        fragment.append(doc.createTextNode(raw));
        return fragment;
    }

    const isTableChild = /^\s*<(?:tr|tbody|thead|tfoot|td|th)\b/i.test(raw);
    const wrapped = isTableChild ? `<table>${raw}</table>` : raw;
    const parsed = new Parser().parseFromString(wrapped, 'text/html');
    const root = isTableChild ? parsed.body.querySelector('table') : parsed.body;
    if (!root) {
        return fragment;
    }

    const blocked = new Set(DANGEROUS_ELEMENTS);
    if (options.allowSvg) blocked.delete('svg');
    if (options.allowForm) blocked.delete('form');
    if (options.allowStyle) blocked.delete('style');
    if (options.allowTemplate) blocked.delete('template');

    sanitizeNodeTree(root, blocked, options);

    const sourceNodes = isTableChild && root.firstElementChild?.tagName === 'TBODY' && !/^\s*<tbody\b/i.test(raw)
        ? Array.from(root.firstElementChild.childNodes)
        : Array.from(root.childNodes);

    for (const node of sourceNodes) {
        fragment.append(typeof doc.adoptNode === 'function' ? doc.adoptNode(node) : node);
    }

    return fragment;
}

/**
 * Safely insert untrusted HTML into `target`.
 * Uses native `Element.prototype.setHTML()` when available, otherwise sanitizes via
 * `DOMParser` and moves nodes directly with `replaceChildren()`.
 *
 * @param {Element} target
 * @param {string} html
 * @param {{ sanitizer?: object, fallbackOptions?: object }} [options]
 */
export function safeSetHtml(target, html, options = {}) {
    if (!target) return;
    const raw = String(html ?? '');

    if (
        typeof globalThis.Element !== 'undefined'
        && 'setHTML' in globalThis.Element.prototype
        && typeof target.setHTML === 'function'
    ) {
        if (options.sanitizer) {
            target.setHTML(raw, { sanitizer: options.sanitizer });
        } else {
            target.setHTML(raw);
        }
        return;
    }

    const doc = target.ownerDocument || globalThis.document;
    const fragment = sanitizeHtmlToFragment(raw, doc, options.fallbackOptions);
    if (typeof target.replaceChildren === 'function') {
        target.replaceChildren(fragment);
    } else {
        while (target.firstChild) target.removeChild(target.firstChild);
        target.appendChild(fragment);
    }
}

/**
 * Replace an element's contents with a same-origin server-rendered Blade fragment,
 * preserving portal ARIA/data/SVG/form/Alpine `<template>` markup and re-serialized XHTML
 * JSON config scripts while stripping all XSS vectors without `.innerHTML` round-tripping.
 *
 * @param {Element} target
 * @param {string} html
 */
export function replaceWithSanitizedServerFragment(target, html) {
    if (!target) return;
    const doc = target.ownerDocument || globalThis.document;
    const fragment = sanitizeHtmlToFragment(html, doc, {
        allowSvg: true,
        allowForm: true,
        allowJsonScripts: true,
        allowTemplate: true,
        allowStyle: false,
    });
    if (typeof target.replaceChildren === 'function') {
        target.replaceChildren(fragment);
    } else {
        while (target.firstChild) target.removeChild(target.firstChild);
        target.appendChild(fragment);
    }
}
