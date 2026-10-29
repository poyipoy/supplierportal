<?php

namespace Tests\Support;

use App\Models\PrItem;

/**
 * Gate 2 QC-01: inspections of a known shape must report every relevant dimension. This fills the
 * dimensions a test does not care about from the stored PR item (values a test sets are kept), so
 * existing workflow tests keep testing their own behaviour.
 */
final class QcMeasurements
{
    /** @param list<array<string, mixed>> $items */
    public static function complete(array $items): array
    {
        return array_map(function (array $item): array {
            $prItem = PrItem::query()->find($item['pr_item_id'] ?? null);
            foreach (PrItem::relevantDimensionFields(PrItem::canonicalShape($prItem?->shape)) as $dimension) {
                $item['actual_'.$dimension] ??= (float) ($prItem->{$dimension} ?? 1);
            }

            return $item;
        }, $items);
    }
}
