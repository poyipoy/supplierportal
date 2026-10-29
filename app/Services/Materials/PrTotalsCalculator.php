<?php

namespace App\Services\Materials;

use App\Models\HsCodeRule;
use App\Models\MaterialMaster;
use App\Models\PrItem;
use App\Services\RegionalDisplayFormatter;
use App\Support\Money;

final class PrTotalsCalculator
{
    public function __construct(
        private readonly PrItemProcessor $processor,
        private readonly RegionalDisplayFormatter $formatter,
    ) {}

    /**
     * Calculate per-row weights and grand totals without persisting anything.
     *
     * @param  array<int|string, array<string, mixed>>  $items
     * @return array{
     *     rows: list<array{
     *         index: int,
     *         quantity: int|null,
     *         unit_kg: string|null,
     *         unit_kg_display: string|null,
     *         row_total_kg: string|null,
     *         row_total_kg_display: string|null,
     *         weight_status: string,
     *         hs_code: string|null,
     *         hs_status: string,
     *         hs_source: string,
     *         counted_in_kg: bool
     *     }>,
     *     totals: array{
     *         total_quantity: int,
     *         total_quantity_display: string,
     *         total_kg: string,
     *         total_kg_display: string,
     *         row_count: int,
     *         complete_row_count: int
     *     }
     * }
     */
    public function calculate(array $items, ?int $actorId = null): array
    {
        $materialIds = collect($items)
            ->map(fn ($row) => is_array($row) ? filter_var($row['material_master_id'] ?? null, FILTER_VALIDATE_INT) : false)
            ->filter(fn ($id) => is_int($id) && $id > 0)
            ->unique()
            ->values();

        $materialsById = $materialIds->isEmpty()
            ? collect()
            : MaterialMaster::query()
                ->active()
                ->with('aliases')
                ->whereIn('id', $materialIds)
                ->get()
                ->keyBy('id');

        $rules = $materialsById->isEmpty()
            ? collect()
            : HsCodeRule::query()->active()->get();

        $rows = [];
        $totalQuantity = 0;
        $totalKg = Money::normalize(0, 4);
        $completeRowCount = 0;

        foreach (array_values($items) as $position => $rawRow) {
            $row = is_array($rawRow) ? $rawRow : [];
            $index = isset($row['index']) && is_numeric($row['index']) ? (int) $row['index'] : $position;

            $validQty = $this->parsePositiveInteger($row['quantity'] ?? null);
            if ($validQty !== null) {
                $totalQuantity += $validQty;
            }

            $materialId = filter_var($row['material_master_id'] ?? null, FILTER_VALIDATE_INT);
            $material = is_int($materialId) && $materialId > 0 ? $materialsById->get($materialId) : null;

            $unitKg = null;
            $unitKgDisplay = null;
            $rowTotalKg = null;
            $rowTotalKgDisplay = null;
            $weightStatus = 'incomplete';
            $hsCode = null;
            $hsStatus = 'unmapped_material';
            $hsSource = 'auto';
            $countedInKg = false;

            if ($material instanceof MaterialMaster) {
                $processed = $this->processor->process(
                    [...$row, 'quantity' => $validQty ?? 1],
                    false,
                    $actorId,
                    null,
                    $rules,
                    $material,
                );

                $weightStatus = (string) ($processed->data['weight_calculation_status'] ?? $processed->weight->status);
                $hsCode = $processed->data['hs_code'] ?? null;
                $hsStatus = (string) ($processed->data['hs_code_resolution_status'] ?? $processed->hsCode->status);
                $hsSource = (string) ($processed->data['hs_code_source'] ?? 'auto');

                $resolvedUnitKg = (float) ($processed->data['weight_needed'] ?? 0);
                if (in_array($weightStatus, ['calculated', PrItem::WEIGHT_STATUS_MANUAL], true) && $resolvedUnitKg > 0) {
                    $unitKg = Money::normalize($resolvedUnitKg, 4);
                }
            } elseif ($this->isTruthy($row['weight_manual_override'] ?? false)) {
                $manualRaw = $row['weight_needed'] ?? null;
                if (is_numeric($manualRaw) && (float) $manualRaw > 0) {
                    $unitKg = Money::normalize(round((float) $manualRaw, 4, PHP_ROUND_HALF_UP), 4);
                    $weightStatus = PrItem::WEIGHT_STATUS_MANUAL;
                }
            }

            if ($unitKg !== null) {
                $unitKgDisplay = $this->formatter->money($unitKg, 4, 'international');
                if ($validQty !== null) {
                    $rowTotalKg = Money::multiply($unitKg, $validQty, 4);
                    $rowTotalKgDisplay = $this->formatter->money($rowTotalKg, 4, 'international');
                    $totalKg = Money::add($totalKg, $rowTotalKg, 4);
                    $countedInKg = true;
                    $completeRowCount++;
                }
            }

            $rows[] = [
                'index' => $index,
                'quantity' => $validQty,
                'unit_kg' => $unitKg,
                'unit_kg_display' => $unitKgDisplay,
                'row_total_kg' => $rowTotalKg,
                'row_total_kg_display' => $rowTotalKgDisplay,
                'weight_status' => $weightStatus,
                'hs_code' => $hsCode,
                'hs_status' => $hsStatus,
                'hs_source' => $hsSource,
                'counted_in_kg' => $countedInKg,
            ];
        }

        return [
            'rows' => $rows,
            'totals' => [
                'total_quantity' => $totalQuantity,
                'total_quantity_display' => $this->formatter->number(number_format($totalQuantity, 0, '.', ','), 'international'),
                'total_kg' => $totalKg,
                'total_kg_display' => $this->formatter->money($totalKg, 4, 'international'),
                'row_count' => count($rows),
                'complete_row_count' => $completeRowCount,
            ],
        ];
    }

    private function parsePositiveInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 1 ? $value : null;
        }
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }
        $raw = trim((string) $value);
        if (preg_match('/^[1-9]\d*$/', $raw) !== 1) {
            return null;
        }
        $parsed = filter_var($raw, FILTER_VALIDATE_INT);

        return is_int($parsed) && $parsed >= 1 ? $parsed : null;
    }

    private function isTruthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (! is_scalar($value)) {
            return false;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }
}
