import './debug-logger.js';
import Alpine from 'alpinejs';
import { registerToastCenter } from './toast-center';
import { installAuthAlertBridge } from './auth-alert-bridge';
import { bootAuthGuestSession } from './auth-guest-session';
import { bootAuthLoginSplashForms } from './auth-login-splash';
import './asset-protection';
import './form-a11y';
import './submit-guard';

window.Alpine = Alpine;

const hasMarker = (selector) => document.querySelector(selector) !== null;

const gatedComponents = [
    ['[x-data^="adasiFileUploadComponent"]', () => import('./file-upload')
        .then((module) => Alpine.data('adasiFileUploadComponent', module.adasiFileUploadComponent))],
    ['[x-data^="searchableSelect"]', () => import('./searchable-select')
        .then((module) => Alpine.data('searchableSelect', module.searchableSelect))],
].filter(([selector]) => hasMarker(selector)).map(([, load]) => load());

if (gatedComponents.length) {
    await Promise.allSettled(gatedComponents);
}

registerToastCenter(Alpine);
installAuthAlertBridge();

Alpine.start();

const bootDeferredAuthModules = () => {
    if (hasMarker('[data-auth-login-page]')) {
        bootAuthGuestSession(window, document);
    }
    if (hasMarker('form[data-login-splash-form]')) {
        bootAuthLoginSplashForms();
    }
    if (hasMarker('[data-password-assistance]')) import('./password-assistance');
    if (hasMarker('form[data-async-submit]')) import('./async-form-submit');
    if (hasMarker('form[data-locale-switcher], [data-locale-option]')) import('./locale-switcher');
    if (hasMarker('[role="dialog"]')) import('./overlay-modality');
    if (hasMarker('select:not([multiple]):not([data-native-select="true"]):not([x-ref="nativeSelect"]):not([x-ref="nativeMultiSelect"])')) {
        import('./adasi-select').then((module) => {
            window.AdasiSelect = module.AdasiSelect;
            module.bootAdasiSelects(document);
        });
    }
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootDeferredAuthModules, { once: true });
} else {
    bootDeferredAuthModules();
}

const acknowledgeAuthSplashCore = () => {
    queueMicrotask(() => Alpine.nextTick(() => window.AdasiAuthSplash?.markCoreReady()));
};

if (document.readyState !== 'complete' && !window.AdasiAuthSplash?.domReady) {
    document.addEventListener('DOMContentLoaded', acknowledgeAuthSplashCore, { once: true });
} else {
    acknowledgeAuthSplashCore();
}
