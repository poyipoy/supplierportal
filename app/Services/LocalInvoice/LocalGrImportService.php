<?php

namespace App\Services\LocalInvoice;

use App\Models\LocalGoodsReceipt;
use App\Models\LocalPurchaseOrder;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class LocalGrImportService
{
    public function __construct(
        private LocalProcurementMasterService $masters,
        private LocalFinanceAuditService $audit
    ) {}

    public function recordPreview(User $actor, string $filename, array $result): void
    {
        $this->audit->record($actor, 'gr_import_previewed', $actor, null, null, [
            'original_filename' => $filename,
            'previewed_at' => now()->toIso8601String(),
            'source_row_count' => $result['source_row_count'] ?? count($result['rows'] ?? []),
            'consolidated_gr_count' => count($result['rows'] ?? []),
            'valid' => (bool) ($result['success'] ?? false),
            'error_count' => count($result['errors'] ?? []),
        ]);
    }

    public function validate(array $rows): array
    {
        $errors = [];
        $parsedRows = [];

        // 1. Validate individual raw row fields
        foreach ($rows as $row) {
            $number = (int) ($row['_row'] ?? 0);
            $rowErrors = [];

            foreach ($row['_formula_columns'] ?? [] as $column) {
                $rowErrors[] = [$column, 'Excel formulas are not allowed.'];
            }

            foreach (['gr_number', 'po_number', 'qty', 'gr_date'] as $field) {
                if (! isset($row[$field]) || $row[$field] === null || $row[$field] === '') {
                    $rowErrors[] = [$field, 'This field is required.'];
                }
            }

            $grDate = $this->date($row['gr_date'] ?? null);
            if (! empty($row['gr_date']) && ! $grDate) {
                $rowErrors[] = ['gr_date', 'Use a valid date format (YYYY-MM-DD or Excel date).'];
            }

            $qty = $this->decimal($row['qty'] ?? null);
            if (($row['qty'] !== null && $row['qty'] !== '') && ($qty === null || bccomp($qty, '0', 4) <= 0)) {
                $rowErrors[] = ['qty', 'Quantity must be a positive number.'];
            }

            foreach ($rowErrors as [$field, $message]) {
                $errors[] = ['row' => $number, 'column' => $field, 'message' => $message];
            }

            $desc = isset($row['description']) && $row['description'] !== null ? trim((string) $row['description']) : null;
            if ($desc === '') {
                $desc = null;
            }

            if ($rowErrors === []) {
                $parsedRows[] = [
                    '_row' => $number,
                    'gr_number' => trim((string) $row['gr_number']),
                    'po_number' => trim((string) $row['po_number']),
                    'description' => $desc,
                    'qty' => $qty,
                    'gr_date' => $grDate,
                ];
            }
        }

        // 2. Cross-row conflict validation per GR Number
        $grGroups = collect($parsedRows)->groupBy(fn ($r) => mb_strtolower($r['gr_number']));
        foreach ($grGroups as $grKey => $groupRows) {
            $distinctPos = $groupRows->pluck('po_number')->map(fn ($p) => mb_strtolower($p))->unique();
            if ($distinctPos->count() > 1) {
                $originalPos = $groupRows->pluck('po_number')->unique()->implode(', ');
                $firstRow = $groupRows->first()['_row'];
                $errors[] = [
                    'row' => $firstRow,
                    'column' => 'po_number',
                    'message' => "GR '{$groupRows->first()['gr_number']}' is linked to multiple different PO numbers: {$originalPos}.",
                ];
            }

            $distinctDates = $groupRows->pluck('gr_date')->unique();
            if ($distinctDates->count() > 1) {
                $firstRow = $groupRows->first()['_row'];
                $errors[] = [
                    'row' => $firstRow,
                    'column' => 'gr_date',
                    'message' => "GR '{$groupRows->first()['gr_number']}' has conflicting calendar dates: {$distinctDates->implode(', ')}.",
                ];
            }
        }

        // 3. Consolidate ERP lines into single GR master records
        $consolidated = [];
        $uniquePoKeys = [];

        foreach ($grGroups as $grKey => $groupRows) {
            $first = $groupRows->first();
            $totalQty = $groupRows->reduce(fn ($sum, $r) => bcadd($sum, (string) $r['qty'], 4), '0.0000');

            if (bccomp($totalQty, '0', 4) <= 0) {
                $errors[] = [
                    'row' => $first['_row'],
                    'column' => 'qty',
                    'message' => "Aggregated quantity for GR '{$first['gr_number']}' must be greater than zero.",
                ];
            }

            $descriptions = $groupRows->pluck('description')
                ->filter(fn ($d) => $d !== null && trim((string) $d) !== '')
                ->map(fn ($d) => trim((string) $d))
                ->unique()
                ->values();
            $combinedDescription = $descriptions->isNotEmpty() ? $descriptions->implode(', ') : null;
            if ($combinedDescription !== null && mb_strlen($combinedDescription) > 2000) {
                $combinedDescription = mb_substr($combinedDescription, 0, 1997).'...';
            }

            $poKey = mb_strtolower($first['po_number']);
            $uniquePoKeys[] = $poKey;

            $consolidated[] = [
                '_row' => $first['_row'],
                'gr_number' => $first['gr_number'],
                'po_number' => $first['po_number'],
                'description' => $combinedDescription,
                'po_key' => $poKey,
                'po_id' => null,
                'po_status' => null,
                'gr_date' => $first['gr_date'],
                'qty' => $totalQty,
                'source_rows_count' => $groupRows->count(),
                'contributing_rows' => $groupRows->pluck('_row')->all(),
                'action' => 'NEW',
            ];
        }

        // 4. Validate exact PO existence and status
        $poMap = LocalPurchaseOrder::whereIn(DB::raw('LOWER(po_number)'), array_unique($uniquePoKeys))
            ->get()
            ->keyBy(fn ($po) => mb_strtolower($po->po_number));

        $unmatchedPoCount = 0;
        foreach ($consolidated as &$group) {
            $poKey = $group['po_key'];
            if (! isset($poMap[$poKey])) {
                $unmatchedPoCount++;
                $errors[] = [
                    'row' => $group['_row'],
                    'column' => 'po_number',
                    'message' => "GR {$group['gr_number']} references PO {$group['po_number']}, but no matching Local PO exists.",
                ];
                $group['action'] = 'UNMATCHED_PO';

                continue;
            }

            $po = $poMap[$poKey];
            $group['po_id'] = $po->id;
            $group['po_status'] = $po->status;

            if ($po->status !== LocalPurchaseOrder::STATUS_OPEN) {
                $errors[] = [
                    'row' => $group['_row'],
                    'column' => 'po_number',
                    'message' => "New GR cannot be added to a closed or cancelled PO ({$group['po_number']}).",
                ];
                $group['action'] = 'PO_CLOSED';
            }
        }
        unset($group);

        // 5. Database duplicate/conflict checks against existing LocalGoodsReceipt
        $uniqueGrKeys = collect($consolidated)->pluck('gr_number')->map(fn ($g) => mb_strtolower($g))->unique();
        $existingGrMap = LocalGoodsReceipt::whereIn(DB::raw('LOWER(gr_number)'), $uniqueGrKeys->all())
            ->get()
            ->keyBy(fn ($gr) => mb_strtolower($gr->gr_number));

        foreach ($consolidated as &$group) {
            if ($group['action'] === 'UNMATCHED_PO' || $group['action'] === 'PO_CLOSED') {
                continue;
            }

            $grKey = mb_strtolower($group['gr_number']);
            if (! isset($existingGrMap[$grKey])) {
                continue;
            }

            $existing = $existingGrMap[$grKey];
            $poMatch = (int) $existing->local_purchase_order_id === (int) $group['po_id'];
            $dateMatch = $existing->gr_date?->format('Y-m-d') === $group['gr_date'];
            $qtyMatch = bccomp((string) ($existing->qty ?? '0'), (string) $group['qty'], 4) === 0;

            if ($poMatch && $dateMatch && $qtyMatch) {
                $group['action'] = 'EXISTING';
            } else {
                $errors[] = [
                    'row' => $group['_row'],
                    'column' => 'gr_number',
                    'message' => "GR '{$group['gr_number']}' already exists with conflicting data (PO, Date, or Qty differs).",
                ];
                $group['action'] = 'CONFLICT';
            }
        }
        unset($group);

        return [
            'success' => $errors === [],
            'rows' => $consolidated,
            'source_row_count' => count($rows),
            'errors' => $errors,
            'summary' => $this->summary($rows, $consolidated, $errors, $unmatchedPoCount),
        ];
    }

    public function import(User $actor, array $rows, array $metadata = []): array
    {
        return DB::transaction(function () use ($actor, $rows, $metadata) {
            $result = $this->validate($rows);

            if (! $result['success']) {
                throw ValidationException::withMessages([
                    'import_file' => collect($result['errors'])->map(fn ($e) => "Row {$e['row']} {$e['column']}: {$e['message']}")->all(),
                ]);
            }

            $newGr = 0;
            $existingGr = 0;

            foreach ($result['rows'] as $group) {
                if ($group['action'] === 'EXISTING') {
                    $existingGr++;

                    continue;
                }

                $po = LocalPurchaseOrder::whereKey($group['po_id'])
                    ->lockForUpdate()
                    ->firstOrFail();

                $this->masters->createGoodsReceipt($actor, $po, [
                    'gr_number' => $group['gr_number'],
                    'gr_date' => $group['gr_date'],
                    'qty' => $group['qty'],
                    'description' => $group['description'] ?? null,
                    'notes' => "Imported via Infor ERP Goods Receipt ({$group['source_rows_count']} line rows)",
                ], LocalPurchaseOrder::SOURCE_IMPORT);

                $newGr++;
            }

            $this->audit->record($actor, 'gr_import_confirmed', $actor, null, null, array_merge($metadata, [
                'confirmed_at' => now()->toIso8601String(),
                'source_row_count' => count($rows),
                'consolidated_gr_count' => count($result['rows']),
                'created_gr_count' => $newGr,
                'existing_gr_count' => $existingGr,
            ]));

            return [
                'newGr' => $newGr,
                'existingGr' => $existingGr,
                'totalConsolidated' => count($result['rows']),
                'sourceRows' => count($rows),
            ];
        });
    }

    private function date(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            if (is_numeric($value)) {
                return Carbon::instance(ExcelDate::excelToDateTimeObject($value))->format('Y-m-d');
            }

            return Carbon::parse(trim((string) $value))->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    private function decimal(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = trim((string) $value);
        if (! preg_match('/^-?\d+(?:\.\d+)?$/', $value)) {
            return null;
        }

        return $value;
    }

    private function summary(array $sourceRows, array $consolidated, array $errors, int $unmatchedPoCount): array
    {
        $newGr = collect($consolidated)->filter(fn ($r) => ($r['action'] ?? '') === 'NEW')->count();
        $existingGr = collect($consolidated)->filter(fn ($r) => ($r['action'] ?? '') === 'EXISTING')->count();
        $conflicts = collect($consolidated)->filter(fn ($r) => ($r['action'] ?? '') === 'CONFLICT')->count();

        return [
            'source_rows' => count($sourceRows),
            'consolidated_gr' => count($consolidated),
            'new_gr' => $newGr,
            'existing_gr' => $existingGr,
            'conflicts' => $conflicts,
            'unmatched_po' => $unmatchedPoCount,
            'invalid' => count($errors),
        ];
    }
}
