/**
 * ADASI Portal Supplier — Multi-Digit Number Live Formatter Helper
 * Displays a non-destructive floating reader badge for numeric inputs
 * whenever the user types >= 4 digits (>= 1,000) so large numbers are instantly readable.
 */
(function (window, document) {
    'use strict';
    const t = (key, replacements) => window.AdasiI18n.t(key, replacements);

    let activeBadge = null;
    let activeInput = null;

    function formatCompactEn(num) {
        const abs = Math.abs(num);
        if (abs >= 1e12) {
            const val = (num / 1e12).toFixed(2).replace(/\.?0+$/, '');
            return t('js.number.trillion', { value: val });
        }
        if (abs >= 1e9) {
            const val = (num / 1e9).toFixed(2).replace(/\.?0+$/, '');
            return t('js.number.billion', { value: val });
        }
        if (abs >= 1e6) {
            const val = (num / 1e6).toFixed(2).replace(/\.?0+$/, '');
            return t('js.number.million', { value: val });
        }
        if (abs >= 1e3) {
            const val = (num / 1e3).toFixed(2).replace(/\.?0+$/, '');
            return t('js.number.thousand', { value: val });
        }
        return '';
    }

    function formatNumberWithCommas(valStr) {
        if (!valStr) return '';
        const parts = valStr.split('.');
        parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        return parts.join('.');
    }

    function detectUnit(input) {
        if (input.dataset?.unit) {
            return ` ${input.dataset.unit}`;
        }
        const name = (input.name || '').toLowerCase();
        const id = (input.id || '').toLowerCase();
        const cls = (input.className || '').toLowerCase();

        if (name.includes('price') || id.includes('price') || cls.includes('price')) {
            const currSelect = document.querySelector('#quotationCurrency, [name="currency"]');
            return currSelect?.value ? ` ${currSelect.value}` : '';
        }
        if (name.includes('rate') || id.includes('rate')) {
            return ' IDR';
        }
        if (name.includes('weight') || id.includes('weight') || cls.includes('weight')) {
            return ' kg';
        }
        if (name.includes('thick') || name.includes('width') || name.includes('length') || 
            name.includes('inner') || name.includes('outer') || cls.includes('dimension')) {
            return ' mm';
        }
        if (name.includes('qty') || name.includes('quantity')) {
            return ' pcs';
        }
        return '';
    }

    function getOrCreateBadge() {
        if (!activeBadge) {
            activeBadge = document.createElement('div');
            activeBadge.className = 'ui-number-preview-badge';
            activeBadge.setAttribute('aria-hidden', 'true');
            document.body.appendChild(activeBadge);
        }
        return activeBadge;
    }

    function positionBadge(input, badge) {
        const rect = input.getBoundingClientRect();
        const badgeWidth = badge.offsetWidth || 160;
        const badgeHeight = badge.offsetHeight || 26;
        const spaceAbove = rect.top;

        // Position above input if space permits, otherwise below
        let top = spaceAbove > badgeHeight + 6 
            ? rect.top + window.scrollY - badgeHeight - 6 
            : rect.bottom + window.scrollY + 6;

        const style = window.getComputedStyle ? window.getComputedStyle(input) : null;
        const isRightAligned = (style && style.textAlign === 'right') ||
            input.classList.contains('text-end') ||
            input.classList.contains('tw:text-right') ||
            (input.getAttribute('class') || '').includes('text-right');

        let left = isRightAligned
            ? rect.right + window.scrollX - badgeWidth
            : rect.left + window.scrollX;

        // Keep within viewport horizontally
        const maxLeft = window.innerWidth - badgeWidth - 12;
        if (left > maxLeft) left = maxLeft;
        if (left < 10) left = 10;

        badge.style.top = `${top}px`;
        badge.style.left = `${left}px`;
    }

    function updateBadge(input) {
        const raw = String(input.value || '').trim();
        const cleanNum = raw.replace(/[^\d.-]/g, '');
        const num = parseFloat(cleanNum);

        const threshold = input.dataset?.minThreshold
            ? parseFloat(input.dataset.minThreshold)
            : ((input.dataset?.formatNumber === 'true' || input.dataset?.annualRateInput !== undefined) ? 1 : 1000);

        if (!raw || isNaN(num) || num <= 0 || (threshold > 0 && Math.abs(num) < threshold)) {
            hideBadge();
            return;
        }

        const badge = getOrCreateBadge();
        const formatted = formatNumberWithCommas(raw);
        const compact = formatCompactEn(num);
        const unit = detectUnit(input);

        badge.innerHTML = `
            <span class="ui-number-badge__formatted">${formatted}${unit}</span>
            ${compact ? `<span class="ui-number-badge__compact">(${compact})</span>` : ''}
        `;

        badge.classList.add('is-visible');
        activeInput = input;
        positionBadge(input, badge);
    }

    function hideBadge() {
        if (activeBadge) {
            activeBadge.classList.remove('is-visible');
        }
        activeInput = null;
    }

    function isEligibleInput(el) {
        if (!el || el.tagName !== 'INPUT') return false;
        if (el.type === 'number') return true;
        if (el.dataset?.formatNumber === 'true' || el.dataset?.annualRateInput !== undefined) return true;
        const cls = el.className || '';
        return cls.includes('dimension-input') || 
               cls.includes('price-input') || 
               cls.includes('quantity-input') || 
               cls.includes('offered-weight-input') ||
               cls.includes('weight-unit-display') ||
               cls.includes('material-quantity');
    }

    function initNumberHelper() {
        document.addEventListener('input', (e) => {
            if (isEligibleInput(e.target)) {
                updateBadge(e.target);
            }
        }, true);

        document.addEventListener('focusin', (e) => {
            if (isEligibleInput(e.target)) {
                updateBadge(e.target);
            }
        }, true);

        document.addEventListener('focusout', (e) => {
            if (isEligibleInput(e.target)) {
                hideBadge();
            }
        }, true);

        window.addEventListener('scroll', () => {
            if (activeInput && activeBadge && activeBadge.classList.contains('is-visible')) {
                positionBadge(activeInput, activeBadge);
            }
        }, { passive: true });

        window.addEventListener('resize', () => {
            if (activeInput && activeBadge && activeBadge.classList.contains('is-visible')) {
                positionBadge(activeInput, activeBadge);
            }
        }, { passive: true });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initNumberHelper);
    } else {
        initNumberHelper();
    }
})(window, document);
