import { t } from './i18n.js';

let alertAssetsPromise = null;

function readAlertConfig() {
    const node = document.getElementById('adasi-auth-alert-assets');
    if (!node) return null;
    try {
        return JSON.parse(node.textContent || 'null');
    } catch {
        return null;
    }
}

function injectStylesheet(href, integrity) {
    if (!href) return Promise.resolve(true);
    const existing = Array.from(document.querySelectorAll('link[rel="stylesheet"]'))
        .find((link) => link.getAttribute('href') === href || link.href === href);
    if (existing?.dataset.adasiLoaded === '1') return Promise.resolve(true);
    if (existing && existing.dataset.adasiLoading !== '1') {
        existing.remove();
    } else if (existing) {
        return new Promise((resolve) => {
            existing.addEventListener('load', () => resolve(true), { once: true });
            existing.addEventListener('error', () => resolve(false), { once: true });
        });
    }

    return new Promise((resolve) => {
        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = href;
        link.dataset.adasiLoading = '1';
        if (integrity) {
            link.integrity = integrity;
            link.crossOrigin = 'anonymous';
        }
        link.addEventListener('load', () => {
            delete link.dataset.adasiLoading;
            link.dataset.adasiLoaded = '1';
            resolve(true);
        }, { once: true });
        link.addEventListener('error', () => {
            delete link.dataset.adasiLoading;
            link.remove();
            resolve(false);
        }, { once: true });
        document.head.appendChild(link);
    });
}

function injectScript(src, integrity) {
    if (!src) return Promise.resolve(false);
    const existing = Array.from(document.querySelectorAll('script[src]'))
        .find((script) => script.getAttribute('src') === src || script.src === src);
    if (existing?.dataset.adasiLoaded === '1') return Promise.resolve(true);
    if (existing && existing.dataset.adasiLoading !== '1') {
        existing.remove();
    } else if (existing) {
        return new Promise((resolve) => {
            existing.addEventListener('load', () => resolve(true), { once: true });
            existing.addEventListener('error', () => resolve(false), { once: true });
        });
    }

    return new Promise((resolve) => {
        const script = document.createElement('script');
        script.src = src;
        script.async = true;
        script.dataset.adasiLoading = '1';
        if (integrity) {
            script.integrity = integrity;
            script.crossOrigin = 'anonymous';
        }
        script.addEventListener('load', () => {
            delete script.dataset.adasiLoading;
            script.dataset.adasiLoaded = '1';
            resolve(true);
        }, { once: true });
        script.addEventListener('error', () => {
            delete script.dataset.adasiLoading;
            script.remove();
            resolve(false);
        }, { once: true });
        document.head.appendChild(script);
    });
}

function ensureAlertRuntime() {
    if (window.AdasiAlert?.__authLazyBridge !== true && typeof window.AdasiAlert?.confirm === 'function') {
        return Promise.resolve(window.AdasiAlert);
    }
    if (alertAssetsPromise) return alertAssetsPromise;

    const cfg = readAlertConfig();
    if (!cfg) return Promise.resolve(null);

    alertAssetsPromise = (async () => {
        const [swalCssLoaded, alertCssLoaded, swalLoaded] = await Promise.all([
            injectStylesheet(cfg.swalCss, cfg.swalCssIntegrity),
            injectStylesheet(cfg.alertCss),
            injectScript(cfg.swalJs, cfg.swalJsIntegrity),
        ]);
        if (!swalCssLoaded || !alertCssLoaded || !swalLoaded || !window.Swal) {
            alertAssetsPromise = null;
            return null;
        }
        const alertLoaded = await injectScript(cfg.alertJs);
        if (!alertLoaded || window.AdasiAlert?.__authLazyBridge === true) {
            alertAssetsPromise = null;
            return null;
        }
        return window.AdasiAlert;
    })().catch(() => {
        alertAssetsPromise = null;
        return null;
    });

    return alertAssetsPromise;
}

function nativeFallback(method, options = {}) {
    const safeOptions = options && typeof options === 'object' ? options : {};
    const text = safeOptions.text === undefined || safeOptions.text === null ? '' : String(safeOptions.text);
    const title = safeOptions.title === undefined || safeOptions.title === null ? '' : String(safeOptions.title);

    if (method === 'confirm' || method === 'confirmDanger') {
        const confirmed = window.confirm(`${title || t('js.actions.confirmation')}\n\n${text}`);
        return Promise.resolve({ isConfirmed: confirmed, isDenied: false, isDismissed: !confirmed, value: undefined });
    }
    if (method === 'prompt') {
        const value = window.prompt(title || t('js.actions.input'), String(safeOptions.initialValue ?? ''));
        return Promise.resolve({ isConfirmed: value !== null, isDenied: false, isDismissed: value === null, value });
    }
    const fallbackTitleKeys = {
        success: 'js.toast.completed',
        error: 'js.toast.failed',
        warning: 'js.toast.attention',
        info: 'js.toast.update',
    };
    const fallbackTitle = title || t(fallbackTitleKeys[method] || 'js.toast.update');
    window.alert(`${fallbackTitle}\n\n${text}`);
    return Promise.resolve({ isConfirmed: true, isDenied: false, isDismissed: false, value: undefined });
}

function dispatchToast(rawOptions = {}) {
    const options = rawOptions && typeof rawOptions === 'object' ? rawOptions : {};
    const type = options.type === undefined || options.type === null ? 'info' : String(options.type);
    const actions = typeof options.onClick === 'function'
        ? [{
            label: options.actionLabel === undefined || options.actionLabel === null ? t('js.actions.view') : String(options.actionLabel),
            variant: 'primary',
            onClick: options.onClick,
        }]
        : options.actions;
    const payload = {
        type,
        title: options.title === undefined || options.title === null ? '' : String(options.title),
        message: String(options.text ?? options.message ?? ''),
        timestamp: options.timestamp,
        autoClose: Number(options.duration) > 0 ? Number(options.duration) : undefined,
        actions,
    };

    if (window.AdasiToast) {
        const id = window.AdasiToast.show(payload);
        return Promise.resolve({ isConfirmed: false, isDenied: false, isDismissed: true, value: undefined, id });
    }
    window.__adasiToastQueue = window.__adasiToastQueue || [];
    window.__adasiToastQueue.push(payload);
    return Promise.resolve({ isConfirmed: false, isDenied: false, isDismissed: true, value: undefined });
}

export function flushAuthFlashToasts() {
    document.querySelectorAll('[data-adasi-flash]').forEach((element) => {
        if (element.dataset.adasiFlashConsumed === '1') return;
        element.dataset.adasiFlashConsumed = '1';
        dispatchToast({
            type: element.dataset.type || 'info',
            title: element.dataset.title || '',
            text: element.dataset.message || '',
            duration: Number(element.dataset.duration) || undefined,
        });
    });
}

export function installAuthAlertBridge() {
    if (!window.AdasiAlert) {
        const invokeModal = async (method, options) => {
            const runtime = await ensureAlertRuntime();
            if (runtime && typeof runtime[method] === 'function') {
                return runtime[method](options);
            }
            return nativeFallback(method, options);
        };

        window.AdasiAlert = Object.freeze({
            __authLazyBridge: true,
            confirm: (options) => invokeModal('confirm', options),
            confirmDanger: (options) => invokeModal('confirmDanger', options),
            prompt: (options) => invokeModal('prompt', options),
            success: (options) => invokeModal('success', options),
            error: (options) => invokeModal('error', options),
            warning: (options) => invokeModal('warning', options),
            info: (options) => invokeModal('info', options),
            toast: dispatchToast,
            notification: (options) => {
                const opts = options && typeof options === 'object' ? options : {};
                return dispatchToast({ ...opts, type: String(opts.type ?? 'info'), duration: opts.duration || 5000 });
            },
        });
    }

    flushAuthFlashToasts();
}
