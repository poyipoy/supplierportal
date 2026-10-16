<?php

namespace App\Support\Procurement;

use App\Models\PrItemAward;
use App\Models\QuotationItem;
use App\Support\Money;
use LogicException;

/**
 * One resolved ordered PO line (Gate 1 section 11.2). Built only by
 * PurchaseOrder::orderedLines(); consumers must read qty/KG/amount from here
 * instead of re-deriving them from the offer.
 *
 * kg, pricePerKg and amount are decimal strings at storage scale (4).
 */
final readonly class OrderedLine
{
    public const SOURCE_FROZEN = 'frozen';

    public const SOURCE_SELECTED = 'selected';

    public const SOURCE_LEGACY_AWARD = 'legacy_award';

    public const SOURCE_LEGACY_PO = 'legacy_po';

    public const SCALE = 4;

    public function __construct(
        public QuotationItem $quotationItem,
        public ?PrItemAward $award,
        public int $qty,
        public string $kg,
        public ?string $pricePerKg,
        public string $amount,
        public string $source,
    ) {}

    /**
     * Precedence 1: values frozen at PO finalization. Never recomputed (I-17).
     */
    public static function frozen(QuotationItem $item, PrItemAward $award): self
    {
        if ($award->ordered_qty === null || $award->ordered_total_weight_kg === null || $award->ordered_amount === null) {
            throw new LogicException("Award {$award->id} has incomplete frozen ordered values.");
        }

        return new self(
            $item,
            $award,
            (int) $award->ordered_qty,
            Money::normalize($award->ordered_total_weight_kg, self::SCALE),
            $award->ordered_price_per_kg === null ? null : Money::normalize($award->ordered_price_per_kg, self::SCALE),
            Money::normalize($award->ordered_amount, self::SCALE),
            self::SOURCE_FROZEN,
        );
    }

    /**
     * Precedence 2: Purchasing-selected quantity before finalization; KG and
     * amount follow the offer's weight per unit (PR weight as fallback) and price.
     */
    public static function selected(QuotationItem $item, PrItemAward $award): self
    {
        $qty = (int) $award->ordered_qty;
        $weightPerUnit = $item->offered_weight_per_unit ?? $item->prItem?->weight_needed ?? 0;
        $kg = Money::multiply($qty, $weightPerUnit, self::SCALE);
        $price = self::price($item);

        return new self(
            $item,
            $award,
            $qty,
            $kg,
            $price,
            Money::multiply($kg, $price ?? 0, self::SCALE),
            self::SOURCE_SELECTED,
        );
    }

    /**
     * Precedence 3 (legacy award) and 4 (pre-award PO): today's offer-based
     * values, reproduced exactly from the existing accessors.
     */
    public static function legacy(QuotationItem $item, ?PrItemAward $award): self
    {
        return new self(
            $item,
            $award,
            $item->fulfillment_quantity,
            Money::normalize($item->offered_total_weight ?? $item->prItem?->total_weight ?? 0, self::SCALE),
            self::price($item),
            Money::normalize($item->resolved_amount, self::SCALE),
            $award === null ? self::SOURCE_LEGACY_PO : self::SOURCE_LEGACY_AWARD,
        );
    }

    private static function price(QuotationItem $item): ?string
    {
        return $item->price_per_kg === null ? null : Money::normalize($item->price_per_kg, self::SCALE);
    }
}
