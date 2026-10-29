// PR form totals (PUR-02): Total KG per row and Grand Total Qty/KG, exact like the server's
// Money::multiply(weight_needed, quantity, 4): inputs normalized half-up to 12 dp, multiplied,
// then rounded half-up to 4 dp. Values are scaled BigInts so decimals never pass through floats.
const SCALE = 4;
const WORKING = SCALE + 8;

/** Parse a decimal input ("12.5", "12,5", 12.5) to a scaled BigInt, half-up; null when unusable. */
export function toScaled(value, scale = SCALE) {
    const text = String(value ?? '').trim().replace(',', '.');
    const match = text.match(/^(-?)(\d*)(?:\.(\d*))?$/);
    if (!match || (match[2] === '' && (match[3] ?? '') === '')) return null;
    const fraction = (match[3] ?? '').padEnd(scale + 1, '0');
    let scaled = BigInt(match[2] || '0') * 10n ** BigInt(scale) + BigInt(fraction.slice(0, scale) || '0');
    if (Number(fraction[scale]) >= 5) scaled += 1n;

    return match[1] === '-' ? -scaled : scaled;
}

/** Round a non-negative scaled BigInt from `from` decimals down to `to` decimals, half-up. */
function rescale(scaled, from, to) {
    const divisor = 10n ** BigInt(from - to);

    return (scaled + divisor / 2n) / divisor;
}

/** Whole pieces as the form submits them; blank, invalid or below 1 counts as no quantity. */
export function quantityOf(value) {
    const text = String(value ?? '').trim();
    if (!/^\d+(?:[.,]\d*)?$/.test(text)) return null;
    const quantity = Math.trunc(Number(text.replace(',', '.')));

    return quantity >= 1 ? quantity : null;
}

/** Row Total KG as a scaled BigInt, or null when the quantity or unit weight is missing. */
export function rowTotal(quantity, unitKg) {
    const pieces = quantityOf(quantity);
    const unit = toScaled(unitKg, WORKING);
    if (pieces === null || unit === null || unit < 0n) return null;

    return rescale(unit * BigInt(pieces), WORKING, SCALE);
}

/** Grand totals over [{ quantity, unitKg }]; rows without a quantity contribute zero. */
export function grandTotals(rows) {
    let quantity = 0;
    let weight = 0n;
    for (const row of rows) {
        const pieces = quantityOf(row.quantity);
        if (pieces === null) continue;
        quantity += pieces;
        weight += rowTotal(pieces, row.unitKg) ?? 0n;
    }

    return { quantity, weight };
}

/** Canonical "1234.5678" text for a scaled BigInt (always four decimals, like the server). */
export function toDecimalString(scaled) {
    const negative = scaled < 0n;
    const digits = (negative ? -scaled : scaled).toString().padStart(SCALE + 1, '0');

    return `${negative ? '-' : ''}${digits.slice(0, -SCALE)}.${digits.slice(-SCALE)}`;
}

if (typeof window !== 'undefined') {
    window.AdasiPrTotals = Object.freeze({ toScaled, quantityOf, rowTotal, grandTotals, toDecimalString });
}
