/**
 * Parse a decimal amount string/number into exact integer cents (2 decimal places).
 * Negative or malformed inputs safely return 0.
 *
 * @param {string|number|null|undefined} value
 * @returns {number}
 */
export function parseDecimalToCents(value) {
    if (value === null || value === undefined) {
        return 0;
    }

    const raw = String(value).trim();
    if (raw === '' || !/^\d+(?:\.\d+)?$/.test(raw)) {
        return 0;
    }

    const [wholePart, fracPart = ''] = raw.split('.');
    const paddedFrac = `${fracPart}00`.slice(0, 2);
    const whole = Number.parseInt(wholePart, 10);
    const frac = Number.parseInt(paddedFrac, 10);

    if (!Number.isSafeInteger(whole) || !Number.isSafeInteger(frac)) {
        return 0;
    }

    const cents = whole * 100 + frac;

    return Number.isSafeInteger(cents) ? cents : 0;
}

/**
 * Format integer cents into Indonesian decimal notation (e.g. 10.897.500,25).
 *
 * @param {number} cents
 * @returns {string}
 */
export function formatIdrCents(cents) {
    if (!Number.isFinite(cents)) {
        return '0,00';
    }

    const sign = cents < 0 ? '-' : '';
    const abs = Math.abs(Math.trunc(cents));
    const whole = Math.floor(abs / 100);
    const frac = String(abs % 100).padStart(2, '0');
    const formattedWhole = String(whole).replace(/\B(?=(\d{3})+(?!\d))/g, '.');

    return `${sign}${formattedWhole},${frac}`;
}

/**
 * Compute authoritative preview voucher component totals using integer cents.
 *
 * @param {{ dpp?: string|number, ppn?: string|number, pph?: string|number, bankFee?: string|number }} input
 */
export function computeVoucherTotals({ dpp = 0, ppn = 0, pph = 0, bankFee = 0 } = {}) {
    const dppCents = parseDecimalToCents(dpp);
    const ppnCents = parseDecimalToCents(ppn);
    const pphCents = parseDecimalToCents(pph);
    const bankFeeCents = parseDecimalToCents(bankFee);
    const netCents = dppCents + ppnCents - pphCents - bankFeeCents;

    return {
        dppCents,
        ppnCents,
        pphCents,
        bankFeeCents,
        netCents,
        isPositive: dppCents > 0 && netCents > 0,
    };
}

/**
 * Determine whether destination bank fields apply for the given payment method.
 *
 * @param {string} paymentMethod
 * @returns {boolean}
 */
export function shouldRequireBankFields(paymentMethod) {
    return paymentMethod === 'BANK';
}

/**
 * Alpine.js controller for the manual Payment Voucher creation form.
 *
 * @param {Record<string, any>} config
 */
export function paymentVoucherForm(config = {}) {
    const initialPpn = parseDecimalToCents(config.ppn);
    const initialPph = parseDecimalToCents(config.pph);
    const initialBankFee = parseDecimalToCents(config.bankFee);

    return {
        payeeType: config.payeeType || 'MANUAL',
        supplierHash: config.supplierHash ? String(config.supplierHash) : '',
        employeeId: config.employeeId ? String(config.employeeId) : '',
        paymentMethod: config.paymentMethod || 'BANK',
        dpp: config.dpp ?? '',
        ppn: config.ppn ?? '0',
        pph: config.pph ?? '0',
        bankFee: config.bankFee ?? '0',
        showBreakdown: Boolean(config.forceBreakdown || initialPpn > 0 || initialPph > 0 || initialBankFee > 0),
        supplierMap: config.supplierMap || {},
        employeeMap: config.employeeMap || {},

        init() {
            const syncFromDom = (selector, prop) => {
                const el = this.$el.querySelector(selector);
                if (el && el.value) {
                    this[prop] = String(el.value);
                }
            };
            syncFromDom('select[name="payee_supplier_hash"]', 'supplierHash');
            syncFromDom('select[name="payee_employee_id"]', 'employeeId');
        },

        handleFieldChange(event) {
            const target = event?.target;
            if (!target || !target.name) {
                return;
            }
            if (target.name === 'payee_supplier_hash') {
                this.supplierHash = String(target.value || '');
            } else if (target.name === 'payee_employee_id') {
                this.employeeId = String(target.value || '');
            }
        },

        get selectedSupplier() {
            return this.supplierHash && this.supplierMap[this.supplierHash]
                ? this.supplierMap[this.supplierHash]
                : null;
        },

        get selectedEmployee() {
            return this.employeeId && this.employeeMap[String(this.employeeId)]
                ? this.employeeMap[String(this.employeeId)]
                : null;
        },

        get needsBankFields() {
            return shouldRequireBankFields(this.paymentMethod);
        },

        get totals() {
            return computeVoucherTotals({
                dpp: this.dpp,
                ppn: this.ppn,
                pph: this.pph,
                bankFee: this.bankFee,
            });
        },

        get formattedNet() {
            return formatIdrCents(this.totals.netCents);
        },

        get supplierMissingVerifiedBank() {
            return (
                this.payeeType === 'SUPPLIER'
                && this.needsBankFields
                && this.selectedSupplier !== null
                && !this.selectedSupplier.has_verified_bank
            );
        },
    };
}

