<?php

namespace App\Services\LocalInvoice;

use App\Models\LocalGoodsReceipt;
use App\Models\LocalPurchaseOrder;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class LocalPoGrImportService
{
    public function __construct(private LocalProcurementMasterService $masters, private LocalFinanceAuditService $audit) {}

    public function recordPreview(User $actor, string $filename, array $result): void
    {
        $this->audit->record($actor, 'po_gr_import_previewed', $actor, null, null, [
            'original_filename' => $filename,
            'previewed_at' => now()->toIso8601String(),
            'row_count' => count($result['rows'] ?? []),
            'valid' => (bool) ($result['success'] ?? false),
            'error_count' => count($result['errors'] ?? []),
        ]);
    }

    public function validate(array $rows): array
    {
        $errors = [];
        $normalized = [];
        $headers = [];
        $seenGr = [];
        $suppliers = User::localEligible()->with('supplier')->get()
            ->filter(fn (User $user) => filled($user->supplier?->company_name))
            ->groupBy(fn (User $u) => mb_strtolower(trim((string) $u->supplier?->company_name)));

        foreach ($rows as $row) {
            $number = (int) $row['_row'];
            $rowErrors = [];
            foreach ($row['_formula_columns'] as $column) $rowErrors[] = [$column, 'Excel formulas are not allowed.'];
            foreach (['po_number', 'supplier_name', 'po_date', 'po_amount'] as $field) {
                if ($row[$field] === null || $row[$field] === '') $rowErrors[] = [$field, 'This field is required.'];
            }
            $supplierMatches = $suppliers->get(mb_strtolower(trim((string) $row['supplier_name'])), collect());
            if ($supplierMatches->count() !== 1) $rowErrors[] = ['supplier_name', $supplierMatches->isEmpty() ? 'No exact active Local Supplier match was found.' : 'Supplier name is ambiguous.'];
            $poDate = $this->date($row['po_date']);
            if (! $poDate) $rowErrors[] = ['po_date', 'Use a valid date.'];
            $poAmount = $this->money($row['po_amount']);
            if ($poAmount === null || bccomp($poAmount, '0', 2) <= 0) $rowErrors[] = ['po_amount', 'PO amount must be a positive number with up to two decimal places.'];

            $grFields = [$row['gr_number'], $row['gr_date'], $row['gr_amount']];
            $hasAnyGr = collect($grFields)->contains(fn ($v) => $v !== null && $v !== '');
            $hasAllGr = collect($grFields)->every(fn ($v) => $v !== null && $v !== '');
            if ($hasAnyGr && ! $hasAllGr) $rowErrors[] = ['gr_number', 'GR Number, GR Date, and GR Amount must be supplied together.'];
            $grDate = $hasAllGr ? $this->date($row['gr_date']) : null;
            if ($hasAllGr && ! $grDate) $rowErrors[] = ['gr_date', 'Use a valid date.'];
            $grAmount = $hasAllGr ? $this->money($row['gr_amount']) : null;
            if ($hasAllGr && ($grAmount === null || bccomp($grAmount, '0', 2) <= 0)) $rowErrors[] = ['gr_amount', 'GR amount must be a positive number with up to two decimal places.'];

            $poKey = mb_strtolower(trim((string) $row['po_number']));
            $header = [$supplierMatches->first()?->id, $poDate, $poAmount ?? '0.00', trim((string) $row['po_remarks'])];
            if (isset($headers[$poKey]) && $headers[$poKey] !== $header) $rowErrors[] = ['po_number', 'Repeated PO rows have conflicting header values.'];
            $headers[$poKey] ??= $header;
            if ($hasAllGr) {
                $grKey = mb_strtolower(trim((string) $row['gr_number']));
                if (isset($seenGr[$grKey])) $rowErrors[] = ['gr_number', 'Duplicate GR Number in workbook.'];
                $seenGr[$grKey] = true;
            }
            foreach ($rowErrors as [$field, $message]) $errors[] = ['row' => $number, 'column' => $field, 'message' => $message];
            $normalized[] = [
                '_row' => $number, 'po_number' => trim((string) $row['po_number']), 'supplier_id' => $supplierMatches->first()?->id,
                'supplier_name' => trim((string) $row['supplier_name']), 'po_date' => $poDate, 'po_amount' => $poAmount ?? '0.00',
                'po_remarks' => $row['po_remarks'], 'gr_number' => $hasAllGr ? trim((string) $row['gr_number']) : null,
                'gr_date' => $grDate, 'gr_amount' => $grAmount, 'gr_remarks' => $row['gr_remarks'],
            ];
        }
        $errors = array_merge($errors, $this->databaseErrors($normalized));
        return ['success' => $errors === [], 'rows' => $normalized, 'errors' => $errors, 'summary' => $this->summary($normalized, $errors)];
    }

    public function import(User $actor, array $rows, array $metadata = []): array
    {
        return DB::transaction(function () use ($actor, $rows, $metadata) {
            $result = $this->validate($rows);
            if (! $result['success']) throw \Illuminate\Validation\ValidationException::withMessages(['import_file' => collect($result['errors'])->map(fn ($e) => "Row {$e['row']} {$e['column']}: {$e['message']}")->all()]);
            $poCache = [];
            $newPo = $newGr = 0;
            foreach ($result['rows'] as $row) {
                $key = mb_strtolower($row['po_number']);
                if (! isset($poCache[$key])) {
                    $po = LocalPurchaseOrder::whereRaw('LOWER(po_number) = ?', [$key])->lockForUpdate()->first();
                    if (! $po) {
                        $po = $this->masters->createPurchaseOrder($actor, ['po_number' => $row['po_number'], 'supplier_id' => $row['supplier_id'], 'po_date' => $row['po_date'], 'total_amount' => $row['po_amount'], 'description' => $row['po_remarks']], LocalPurchaseOrder::SOURCE_IMPORT);
                        $newPo++;
                    }
                    $poCache[$key] = $po;
                }
                if ($row['gr_number']) {
                    $this->masters->createGoodsReceipt($actor, $poCache[$key], ['gr_number' => $row['gr_number'], 'gr_date' => $row['gr_date'], 'received_amount' => $row['gr_amount'], 'notes' => $row['gr_remarks']], LocalPurchaseOrder::SOURCE_IMPORT);
                    $newGr++;
                }
            }
            $counts = compact('newPo', 'newGr');
            $this->audit->record($actor, 'po_gr_import_confirmed', $actor, null, null, array_merge($metadata, [
                'confirmed_at' => now()->toIso8601String(),
                'row_count' => count($rows),
                'created_po_count' => $newPo,
                'created_gr_count' => $newGr,
            ]));

            return $counts;
        });
    }

    private function databaseErrors(array $rows): array
    {
        $errors = [];
        foreach (collect($rows)->groupBy(fn ($r) => mb_strtolower($r['po_number'])) as $group) {
            $first = $group->first();
            $existing = LocalPurchaseOrder::whereRaw('LOWER(po_number) = ?', [mb_strtolower($first['po_number'])])->first();
            if ($existing) {
                if ((int) $existing->supplier_id !== (int) $first['supplier_id'] || $existing->po_date?->format('Y-m-d') !== $first['po_date'] || bccomp((string) $existing->total_amount, $first['po_amount'], 2) !== 0 || trim((string) $existing->description) !== trim((string) $first['po_remarks'])) {
                    $errors[] = ['row' => $first['_row'], 'column' => 'po_number', 'message' => 'Existing PO header differs; import cannot update master values.'];
                }
                if ($existing->status !== LocalPurchaseOrder::STATUS_OPEN && $group->contains(fn ($r) => $r['gr_number'])) $errors[] = ['row' => $first['_row'], 'column' => 'po_number', 'message' => 'New GR cannot be added to a closed or cancelled PO.'];
            }
            $existingGrTotal = $existing ? (string) $existing->goodsReceipts()->where('status', '!=', LocalGoodsReceipt::STATUS_CANCELLED)->sum('received_amount') : '0.00';
            $incoming = $group->reduce(fn ($sum, $r) => $r['gr_amount'] ? bcadd($sum, $r['gr_amount'], 2) : $sum, '0.00');
            if (bccomp(bcadd($existingGrTotal, $incoming, 2), $first['po_amount'], 2) > 0) $errors[] = ['row' => $first['_row'], 'column' => 'gr_amount', 'message' => 'Cumulative non-cancelled GR amount exceeds the PO amount.'];
        }
        foreach ($rows as $row) if ($row['gr_number'] && LocalGoodsReceipt::whereRaw('LOWER(gr_number) = ?', [mb_strtolower($row['gr_number'])])->exists()) $errors[] = ['row' => $row['_row'], 'column' => 'gr_number', 'message' => 'GR Number already exists.'];
        return $errors;
    }

    private function date(mixed $value): ?string
    {
        try {
            if (is_numeric($value)) return Carbon::instance(ExcelDate::excelToDateTimeObject($value))->format('Y-m-d');
            return Carbon::createFromFormat('Y-m-d', trim((string) $value))->format('Y-m-d');
        } catch (\Throwable) { return null; }
    }
    private function summary(array $rows, array $errors): array
    {
        $poNumbers = collect($rows)->pluck('po_number')->filter()->map(fn ($v) => mb_strtolower($v))->unique();
        $existing = LocalPurchaseOrder::whereIn(DB::raw('LOWER(po_number)'), $poNumbers->all())->count();
        return ['total' => count($rows), 'new_po' => max(0, $poNumbers->count() - $existing), 'existing_po' => $existing, 'new_gr' => collect($rows)->whereNotNull('gr_number')->count(), 'invalid' => count($errors)];
    }

    private function money(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = trim((string) $value);
        if (! preg_match('/^\d{1,18}(?:\.\d{1,2})?$/', $value)) {
            return null;
        }

        if (! str_contains($value, '.')) {
            return $value.'.00';
        }

        [$whole, $fraction] = explode('.', $value, 2);
        return $whole.'.'.str_pad($fraction, 2, '0');
    }
}
