<?php

namespace App\Support\Procurement;

/**
 * Single reader of the effective Import-procurement feature flags.
 *
 * Callers must not read config('procurement.*') flags directly: the surplus
 * permission is only effective while the ordered-quantity write path is on,
 * so an inconsistent environment fails closed instead of crashing.
 */
final class ProcurementFeatures
{
    public static function poGenerationEnabled(): bool
    {
        return (bool) config('procurement.po_generation_enabled', true);
    }

    public static function orderedQuantityWriteEnabled(): bool
    {
        return (bool) config('procurement.ordered_quantity_write_enabled', false);
    }

    public static function surplusOffersAllowed(): bool
    {
        return (bool) config('procurement.allow_offer_surplus', false)
            && self::orderedQuantityWriteEnabled();
    }

    /**
     * Mission 05 switch: shipment-level MTC (required to submit) and the
     * retirement of new quotation MTC uploads, enabled or disabled together.
     */
    public static function shipmentMtcEnabled(): bool
    {
        return (bool) config('procurement.shipment_mtc_enabled', true);
    }

    /**
     * Surplus requested without the write path that can order it.
     */
    public static function hasInconsistentFlags(): bool
    {
        return (bool) config('procurement.allow_offer_surplus', false)
            && ! self::orderedQuantityWriteEnabled();
    }
}
