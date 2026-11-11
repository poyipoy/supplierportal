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
     * Ready to Ship gate: a supplier may only draft or submit shipment lines
     * whose awarded item is at the Ready to Ship progress stage. Off restores
     * the previous behaviour (progress never blocks shipments).
     */
    public static function requireReadyToShipForShipment(): bool
    {
        return (bool) config('procurement.require_ready_to_ship_for_shipment', true);
    }

    public static function requirePoAcknowledgementBeforeShipment(): bool
    {
        return (bool) config('procurement.require_po_acknowledgement_before_shipment', false);
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
