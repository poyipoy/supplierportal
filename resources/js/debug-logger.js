const isDev = Boolean(import.meta.env?.DEV);

export function devWarn(...args) {
    if (isDev) {
        console.warn(...args);
    }
}

export function devError(...args) {
    if (isDev) {
        console.error(...args);
    }
}

if (typeof window !== 'undefined') {
    window.AdasiDebug = Object.freeze({
        warn: devWarn,
        error: devError,
    });
}
