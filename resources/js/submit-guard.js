/**
 * ADASI Portal Supplier - Button Submit & Loading State Guard
 *
 * Responsibilities:
 * 1. Prevent double submissions across all standard forms.
 * 2. Ensure ONLY the clicked action button displays a loading spinner.
 * 3. Preserve original button text and design token styling (avoids layout shift / CLS).
 * 4. Disable sibling action buttons without modifying their labels/icons.
 * 5. Mirror submitter name/value so backend actions (e.g. action=draft) remain intact.
 * 6. Recover gracefully on HTML5 validation failures and browser back-button (bfcache) navigation.
 * 7. Expose `window.AdasiButton` for modal-driven and programmatic workflows.
 */

(function (window, document) {
    'use strict';

    const SUBMITTER_MIRROR_ATTR = 'data-submit-submitter-mirror';
    const MANAGED_SUBMIT_ATTR = 'data-managed-submit';
    const NO_AUTO_SPINNER_ATTR = 'data-no-auto-spinner';
    const TIMEOUT_MS = 60000;
    const originalButtonNodes = new WeakMap();

    /**
     * Create the accessible .ui-spinner element
     */
    function createSpinnerElement() {
        const spinner = document.createElement('span');
        spinner.className = 'ui-spinner';
        spinner.setAttribute('aria-hidden', 'true');
        return spinner;
    }

    /**
     * Resolve the icon node (or dedicated icon-only span wrapper) without hiding adjacent button labels.
     * @param {HTMLElement} btn
     * @returns {HTMLElement|null}
     */
    function resolveButtonIconTarget(btn) {
        const icon = btn.querySelector('.ui-icon');
        if (!icon) return null;

        const closestSpan = icon.closest('span');
        const isIconOnlyWrapper = Boolean(
            closestSpan
            && closestSpan !== btn
            && closestSpan.textContent.trim() === ''
            && !closestSpan.querySelector(':scope > span')
        );

        return isIconOnlyWrapper ? closestSpan : icon;
    }

    /**
     * Activate loading spinner on a specific button
     * @param {HTMLElement|string} button
     * @param {Object} [options]
     */
    function startLoading(button, options = {}) {
        const btn = typeof button === 'string' ? document.querySelector(button) : button;
        if (!btn || !(btn instanceof HTMLElement)) return;

        // Skip if already in loading state
        if (btn.dataset.loadingActive === 'true') return;

        // Store cloned child nodes instead of serializing innerHTML
        if (!originalButtonNodes.has(btn)) {
            originalButtonNodes.set(
                btn,
                Array.from(btn.childNodes, (node) => node.cloneNode(true))
            );
        }

        btn.dataset.loadingActive = 'true';
        btn.setAttribute('aria-busy', 'true');
        btn.disabled = true;
        btn.classList.add('is-loading');

        const loadingText = options.text || btn.getAttribute('data-loading-text');

        // Replace only the leading icon (never a wrapper span that also holds button label text).
        const iconTarget = resolveButtonIconTarget(btn);
        const spinner = createSpinnerElement();

        if (iconTarget && iconTarget.parentNode) {
            iconTarget.style.display = 'none';
            iconTarget.parentNode.insertBefore(spinner, iconTarget);
        } else {
            // Prepend spinner before the first child or text
            spinner.classList.add('tw:mr-1.5');
            btn.prepend(spinner);
        }

        // If a specific data-loading-text is provided, update the text node/container
        if (loadingText) {
            const textContainer = btn.querySelector('span:not(.ui-spinner):not(.ui-icon)') || btn;
            if (textContainer && textContainer !== btn) {
                textContainer.textContent = loadingText;
            }
        }
    }

    /**
     * Restore a button from loading state
     * @param {HTMLElement|string} button
     */
    function stopLoading(button) {
        const btn = typeof button === 'string' ? document.querySelector(button) : button;
        if (!btn || !(btn instanceof HTMLElement)) return;

        if (originalButtonNodes.has(btn)) {
            const snapshot = originalButtonNodes.get(btn);
            originalButtonNodes.delete(btn);
            btn.replaceChildren(...snapshot.map((node) => node.cloneNode(true)));
        } else {
            const spinner = btn.querySelector('.ui-spinner');
            if (spinner) spinner.remove();
            const hiddenIcon = btn.querySelector('.ui-icon');
            if (hiddenIcon) {
                hiddenIcon.style.display = '';
                const wrapper = hiddenIcon.closest('span');
                if (wrapper && wrapper !== btn && wrapper.textContent.trim() === '') {
                    wrapper.style.display = '';
                }
            }
        }

        delete btn.dataset.loadingActive;
        btn.removeAttribute('aria-busy');
        btn.disabled = false;
        btn.classList.remove('is-loading');
    }

    function getFormButtons(form, selector = 'button, input[type="submit"], input[type="button"]') {
        const inside = [...form.querySelectorAll(selector)];
        if (!form.id) return inside;

        const escapedId = globalThis.CSS?.escape ? CSS.escape(form.id) : form.id.replace(/["\\]/g, '\\$&');
        const external = [...document.querySelectorAll(`[form="${escapedId}"]`)].filter((el) => el.matches?.(selector));

        return [...new Set([...inside, ...external])];
    }

    /**
     * Disable sibling submit/action buttons in the same form without adding spinners
     * @param {HTMLFormElement} form
     * @param {HTMLElement} activeButton
     */
    function disableSiblingButtons(form, activeButton) {
        const buttons = getFormButtons(form);
        buttons.forEach((btn) => {
            if (btn !== activeButton && !btn.disabled) {
                btn.dataset.wasDisabledBySubmitGuard = 'true';
                btn.disabled = true;
                btn.setAttribute('aria-disabled', 'true');
            }
        });
    }

    /**
     * Re-enable buttons that were disabled by submit guard
     * @param {HTMLFormElement} form
     */
    function enableSiblingButtons(form) {
        const buttons = getFormButtons(form, '[data-was-disabled-by-submit-guard="true"]');
        buttons.forEach((btn) => {
            delete btn.dataset.wasDisabledBySubmitGuard;
            btn.disabled = false;
            btn.removeAttribute('aria-disabled');
        });
    }

    /**
     * Reset an entire form and all its buttons to normal state
     * @param {HTMLFormElement} form
     */
    function resetForm(form) {
        if (!form || !(form instanceof HTMLFormElement)) return;

        form.dataset.submitting = 'false';

        // Clear safety timeout if present
        if (form._submitGuardTimeout) {
            clearTimeout(form._submitGuardTimeout);
            delete form._submitGuardTimeout;
        }

        // Re-enable siblings
        enableSiblingButtons(form);

        // Also ensure all buttons in or attached to the form are re-enabled
        const allButtons = getFormButtons(form);
        allButtons.forEach((btn) => {
            btn.disabled = false;
            btn.removeAttribute('aria-disabled');
            delete btn.dataset.wasDisabledBySubmitGuard;
        });

        // Stop loading on any spinning buttons (including external modal action buttons)
        const loadingButtons = getFormButtons(form, '[data-loading-active="true"], .is-loading');
        loadingButtons.forEach((btn) => stopLoading(btn));

        // Remove mirrored submitter input
        const mirror = form.querySelector(`[${SUBMITTER_MIRROR_ATTR}]`);
        if (mirror) mirror.remove();
    }

    /**
     * Reset all submitting forms across the page
     */
    function resetAll() {
        document.querySelectorAll('form[data-submitting="true"]').forEach((form) => resetForm(form));
    }

    /**
     * Setup global listeners
     */
    function initSubmitGuard() {
        // Track the last clicked button within any form (capture phase)
        document.addEventListener('click', function (e) {
            const btn = e.target.closest('button, input[type="submit"], input[type="button"]');
            if (!btn) return;

            const form = btn.form || btn.closest('form');
            if (form) {
                form._lastClickedButton = btn;
            }
        }, true);

        // Reset if native browser validation stops submission
        document.addEventListener('invalid', function (e) {
            const field = e.target;
            if (field && field.form) {
                resetForm(field.form);
            }
        }, true);

        // Reset forms when restored from browser back-forward cache (bfcache)
        window.addEventListener('pageshow', function () {
            resetAll();
        });
    }

    // Expose public API
    window.AdasiButton = {
        TIMEOUT_MS,
        startLoading,
        stopLoading,
        resetForm,
        resetAll,
        disableSiblingButtons,
        enableSiblingButtons,
    };

    // Auto-initialize when DOM is ready or immediately if already loaded
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initSubmitGuard);
    } else {
        initSubmitGuard();
    }
})(window, document);
