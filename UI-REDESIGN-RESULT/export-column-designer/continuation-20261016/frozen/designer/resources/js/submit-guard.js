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
    const TIMEOUT_MS = 12000;

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
     * Activate loading spinner on a specific button
     * @param {HTMLElement|string} button
     * @param {Object} [options]
     */
    function startLoading(button, options = {}) {
        const btn = typeof button === 'string' ? document.querySelector(button) : button;
        if (!btn || !(btn instanceof HTMLElement)) return;

        // Skip if already in loading state
        if (btn.dataset.loadingActive === 'true') return;

        // Store original content if not already stored
        if (!btn.dataset.originalHtml) {
            btn.dataset.originalHtml = btn.innerHTML;
        }

        btn.dataset.loadingActive = 'true';
        btn.setAttribute('aria-busy', 'true');
        btn.disabled = true;
        btn.classList.add('is-loading');

        const loadingText = options.text || btn.getAttribute('data-loading-text');

        // Check for leading icon or .ui-icon inside the button
        const iconWrapper = btn.querySelector('.ui-icon')?.closest('span') || btn.querySelector('.ui-icon');
        const spinner = createSpinnerElement();

        if (iconWrapper && iconWrapper.parentNode) {
            // Replace leading icon with spinner
            iconWrapper.style.display = 'none';
            iconWrapper.parentNode.insertBefore(spinner, iconWrapper);
        } else {
            // Prepend spinner before the first child or text
            spinner.classList.add('tw-mr-1.5');
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

        if (btn.dataset.originalHtml) {
            btn.innerHTML = btn.dataset.originalHtml;
            delete btn.dataset.originalHtml;
        } else {
            const spinner = btn.querySelector('.ui-spinner');
            if (spinner) spinner.remove();
            const hiddenIcon = btn.querySelector('.ui-icon');
            if (hiddenIcon) {
                hiddenIcon.style.display = '';
                const wrapper = hiddenIcon.closest('span');
                if (wrapper) wrapper.style.display = '';
            }
        }

        delete btn.dataset.loadingActive;
        btn.removeAttribute('aria-busy');
        btn.disabled = false;
        btn.classList.remove('is-loading');
    }

    /**
     * Disable sibling submit/action buttons in the same form without adding spinners
     * @param {HTMLFormElement} form
     * @param {HTMLElement} activeButton
     */
    function disableSiblingButtons(form, activeButton) {
        const buttons = form.querySelectorAll('button, input[type="submit"], input[type="button"]');
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
        const buttons = form.querySelectorAll('[data-was-disabled-by-submit-guard="true"]');
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

        // Also ensure all buttons in the form are re-enabled
        const allButtons = form.querySelectorAll('button, input[type="submit"], input[type="button"]');
        allButtons.forEach((btn) => {
            btn.disabled = false;
            btn.removeAttribute('aria-disabled');
            delete btn.dataset.wasDisabledBySubmitGuard;
        });

        // Stop loading on any spinning buttons
        const loadingButtons = form.querySelectorAll('[data-loading-active="true"], .is-loading');
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
