<?php

namespace App\Services\LocalInvoice;

use App\Models\LocalPurchaseOrder;
use App\Models\User;
use App\Support\LocalPoPrNumber;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class LocalPoImportService
{
    public function __construct(
        private LocalProcurementMasterService $masters,
        private LocalFinanceAuditService $audit
    ) {}

    public function recordPreview(User $actor, string $filename, array $result): void
    {
        $this->audit->record($actor, 'po_import_previewed', $actor, null, null, [
            'original_filename' => $filename,
            'checksum' => $result['checksum'] ?? null,
            'previewed_at' => now()->toIso8601String(),
            'row_count' => $result['summary']['source_rows'] ?? count($result['rows'] ?? []),
            'valid' => (bool) ($result['success'] ?? false),
            'error_count' => $result['summary']['invalid'] ?? count($result['errors'] ?? []),
        ]);
    }

    /**
     * $checkDatabase=false skips the registered-PO lookup. Only the confirm
     * stage uses it: LocalProcurementMasterService::createImportBatch() repeats
     * every registered-PO check (cancelled, identity, PR) under row locks.
     */
    public function validate(array $rows, bool $checkDatabase = true): array
    {
        $errors = [];
        $normalized = [];
        $seenHeaders = [];

        $names = array_unique(array_map(fn ($row) => mb_strtolower(trim((string) ($row['supplier_name'] ?? ''))), $rows));
        $suppliers = User::localEligible()->select('users.id')->with('supplier:user_id,company_name')
            ->whereHas('supplier', fn ($q) => $q->whereIn(DB::raw('LOWER(TRIM(company_name))'), $names))->get()
            ->filter(fn (User $user) => filled($user->supplier?->company_name))
            ->groupBy(fn (User $u) => mb_strtolower(trim((string) $u->supplier?->company_name)));

        foreach ($rows as $row) {
            $number = (int) ($row['_row'] ?? 0);
            $rowErrors = [];

            foreach ($row['_formula_columns'] ?? [] as $column) {
                $rowErrors[] = [$column, __('local_procurement.import.formula')];
            }

            foreach (['po_number', 'supplier_name', 'po_date', 'po_amount'] as $field) {
                if (! isset($row[$field]) || $row[$field] === null || $row[$field] === '') {
                    $rowErrors[] = [$field, __('local_procurement.import.field_required')];
                }
            }

            $supplierMatches = collect();
            if (! empty($row['supplier_name'])) {
                $supplierKey = mb_strtolower(trim((string) $row['supplier_name']));
                $supplierMatches = $suppliers->get($supplierKey, collect());
                if ($supplierMatches->count() !== 1) {
                    $rowErrors[] = [
                        'supplier_name',
                        $supplierMatches->isEmpty()
                            ? __('local_procurement.import.supplier_exact', ['name' => $row['supplier_name']])
                            : __('local_procurement.import.supplier_ambiguous', ['name' => $row['supplier_name']]),
                    ];
                }
            }

            $poDate = $this->date($row['po_date'] ?? null);
            if (! empty($row['po_date']) && ! $poDate) {
                $rowErrors[] = ['po_date', __('local_procurement.import.date_invalid')];
            }

            $poAmount = $this->money($row['po_amount'] ?? null);
            if (filled($row['po_amount'] ?? null) && ($poAmount === null || Money::compare($poAmount, Money::ZERO) <= 0)) {
                $rowErrors[] = ['po_amount', __('local_procurement.import.po_amount_positive')];
            }

            $prNumber = null;
            try {
                $prNumber = LocalPoPrNumber::normalize($row['pr_number'] ?? null);
                if ($prNumber !== null && ($row['_pr_header_valid'] ?? true) !== true) {
                    $rowErrors[] = ['pr_number', __('local_procurement.pr_import.header')];
                }
            } catch (ValidationException $exception) {
                $rowErrors[] = ['pr_number', $exception->validator->errors()->first('pr_number')];
            }

            $poKey = mb_strtolower(trim((string) ($row['po_number'] ?? '')));
            if ($poKey !== '') {
                $header = [
                    $supplierMatches->first()?->id,
                    $poDate,
                    $poAmount ?? '0.00',
                ];
                if (isset($seenHeaders[$poKey]) && $seenHeaders[$poKey] !== $header) {
                    $rowErrors[] = ['po_number', __('local_procurement.import.po_header_conflict')];
                }
                $seenHeaders[$poKey] ??= $header;
            }

            foreach ($rowErrors as [$field, $message]) {
                $errors[] = ['row' => $number, 'column' => $field, 'message' => $message];
            }

            $normalized[] = [
                '_row' => $number,
                'po_number' => trim((string) ($row['po_number'] ?? '')),
                'supplier_id' => $supplierMatches->first()?->id,
                'supplier_name' => trim((string) ($row['supplier_name'] ?? '')),
                'po_date' => $poDate,
                'po_amount' => $poAmount ?? '0.00',
                'pr_number' => $prNumber,
                'action' => 'NEW',
            ];
        }

        if ($checkDatabase) {
            $dbResult = $this->checkDatabaseConflicts($normalized);
            $errors = array_merge($errors, $dbResult['errors']);
            $normalized = $dbResult['rows'];
        }
        $prByPo = [];
        foreach ($normalized as $row) {
            $key = mb_strtolower($row['po_number']);
            if ($row['pr_number'] !== null && isset($prByPo[$key]) && LocalPoPrNumber::key($prByPo[$key]) !== LocalPoPrNumber::key($row['pr_number'])) {
                $errors[] = ['row' => $row['_row'], 'column' => 'pr_number', 'message' => __('local_procurement.pr_import.file_conflict', ['po' => $row['po_number']])];
            }
            if ($row['pr_number'] !== null) {
                $prByPo[$key] ??= $row['pr_number'];
            }
        }

        return [
            'success' => $errors === [],
            'rows' => $normalized,
            'errors' => $errors,
            'summary' => $this->summary($normalized, $errors),
        ];
    }

    public function import(User $actor, array $rows, array $metadata = []): array
    {
        return DB::transaction(function () use ($actor, $rows, $metadata) {
            $result = $this->validate($rows);

            if (! $result['success']) {
                throw ValidationException::withMessages([
                    'import_file' => collect($result['errors'])->map(fn ($e) => __('local_procurement.import.row_error', ['row' => $e['row'], 'column' => $e['column'], 'message' => $e['message']]))->all(),
                ]);
            }

            $newPo = 0;
            $existingPo = 0;
            $processedKeys = [];
            $existingMap = LocalPurchaseOrder::forceIndex('local_purchase_orders_po_number_unique')
                ->whereIn('po_number', array_column($result['rows'], 'po_number'))
                ->orderBy('id')->lockForUpdate()->get()->keyBy(fn ($po) => mb_strtolower($po->po_number));

            $prByPo = [];
            foreach ($result['rows'] as $row) {
                if ($row['pr_number'] !== null) {
                    $prByPo[mb_strtolower($row['po_number'])] ??= $row['pr_number'];
                }
            }
            $filledPoPr = 0;

            foreach ($result['rows'] as $row) {
                $key = mb_strtolower($row['po_number']);
                if (isset($processedKeys[$key])) {
                    continue;
                }
                $processedKeys[$key] = true;

                $existing = $existingMap->get($key);
                $prNumber = $prByPo[$key] ?? null;

                if ($existing?->status === LocalPurchaseOrder::STATUS_CANCELLED) {
                    throw ValidationException::withMessages(['import_file' => __('local_procurement.import.po_cancelled', ['po' => $row['po_number'], 'row' => $row['_row']])]);
                }
                if ($existing && ((int) $existing->supplier_id !== (int) $row['supplier_id']
                    || $existing->po_date?->format('Y-m-d') !== $row['po_date']
                    || Money::compare((string) $existing->total_amount, $row['po_amount']) !== 0)) {
                    throw ValidationException::withMessages(['import_file' => __('local_procurement.import.po_existing_conflict', ['po' => $row['po_number']])]);
                }

                if (! $existing) {
                    $this->masters->createPurchaseOrder($actor, [
                        'po_number' => $row['po_number'],
                        'pr_number' => $prNumber,
                        'supplier_id' => $row['supplier_id'],
                        'po_date' => $row['po_date'],
                        'total_amount' => $row['po_amount'],
                        'description' => 'Imported via Infor ERP Purchase Order',
                    ], LocalPurchaseOrder::SOURCE_IMPORT);

                    $newPo++;
                } else {
                    $filledPoPr += $this->masters->fillPurchaseOrderPr($actor, $existing, $prNumber) ? 1 : 0;
                    $existingPo++;
                }
            }

            $this->audit->record($actor, 'po_import_confirmed', $actor, null, null, array_merge($metadata, [
                'confirmed_at' => now()->toIso8601String(),
                'source_row_count' => count($rows),
                'created_po_count' => $newPo,
                'existing_po_count' => $existingPo,
                'po_pr_filled' => $filledPoPr,
            ]));

            return [
                'newPo' => $newPo,
                'existingPo' => $existingPo,
                'filledPoPr' => $filledPoPr,
                'total' => count($rows),
            ];
        });
    }

    private function checkDatabaseConflicts(array $rows): array
    {
        $errors = [];
        $uniquePoKeys = collect($rows)->pluck('po_number')->filter()->map(fn ($v) => mb_strtolower($v))->unique();
        $existingMap = LocalPurchaseOrder::forceIndex('local_purchase_orders_po_number_unique')->whereIn('po_number', $uniquePoKeys->all())
            ->get()
            ->keyBy(fn ($po) => mb_strtolower($po->po_number));

        foreach ($rows as &$row) {
            $key = mb_strtolower($row['po_number']);
            if (! isset($existingMap[$key])) {
                continue;
            }

            $existing = $existingMap[$key];
            if ($existing->status === LocalPurchaseOrder::STATUS_CANCELLED) {
                $errors[] = [
                    'row' => $row['_row'],
                    'column' => 'po_number',
                    'message' => __('local_procurement.import.po_cancelled', ['po' => $row['po_number'], 'row' => $row['_row']]),
                    'code' => 'PO_CANCELLED', 'context' => ['po_number' => $row['po_number']],
                ];
                $row['action'] = 'CONFLICT';

                continue;
            }
            $supplierMatch = (int) $existing->supplier_id === (int) $row['supplier_id'];
            $dateMatch = $existing->po_date?->format('Y-m-d') === $row['po_date'];
            $amountMatch = Money::compare((string) $existing->total_amount, (string) $row['po_amount']) === 0;

            if ($supplierMatch && $dateMatch && $amountMatch) {
                if ($row['pr_number'] !== null && filled($existing->pr_number)
                    && LocalPoPrNumber::key($existing->pr_number) !== LocalPoPrNumber::key($row['pr_number'])) {
                    $errors[] = ['row' => $row['_row'], 'column' => 'pr_number',
                        'message' => __('local_procurement.pr_import.conflict', ['po' => $row['po_number']]),
                        'code' => 'PR_CONFLICT', 'context' => ['po_number' => $row['po_number']]];
                    $row['action'] = 'CONFLICT';
                } else {
                    $row['action'] = $row['pr_number'] !== null && blank($existing->pr_number) ? 'FILL_PR' : 'EXISTING';
                }
            } else {
                $errors[] = [
                    'row' => $row['_row'],
                    'column' => 'po_number',
                    'message' => __('local_procurement.import.po_existing_conflict', ['po' => $row['po_number']]),
                    'code' => 'IDENTITY_CONFLICT', 'context' => ['po_number' => $row['po_number']],
                ];
                $row['action'] = 'CONFLICT';
            }
        }
        unset($row);

        return ['rows' => $rows, 'errors' => $errors];
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

    private function money(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = trim((string) $value);
        if (! preg_match('/^\d{1,18}(?:\.\d{1,2})?$/', $value)) {
            return null;
        }

        return Money::normalize($value);
    }

    private function summary(array $rows, array $errors): array
    {
        $uniquePos = collect($rows)->pluck('po_number')->filter()->map(fn ($v) => mb_strtolower($v))->unique();
        $invalidRows = array_unique(array_column($errors, 'row'));
        $newPos = collect($rows)->filter(fn ($r) => ($r['action'] ?? '') === 'NEW' && ! in_array($r['_row'], $invalidRows, true))->pluck('po_number')->unique()->count();
        $existingPos = collect($rows)->filter(fn ($r) => in_array($r['action'] ?? '', ['EXISTING', 'FILL_PR'], true))->pluck('po_number')->unique()->count();
        $filledPos = collect($rows)->filter(fn ($r) => ($r['action'] ?? '') === 'FILL_PR' && ! in_array($r['_row'], $invalidRows, true))->pluck('po_number')->map(fn ($n) => mb_strtolower($n))->unique()->count();
        $conflicts = collect($rows)->filter(fn ($r) => ($r['action'] ?? '') === 'CONFLICT')->pluck('po_number')->unique()->count();

        return [
            'total_rows' => count($rows),
            'unique_pos' => $uniquePos->count(),
            'new_po' => $newPos,
            'po_pr_filled' => $filledPos,
            'existing_po' => $existingPos,
            'conflicts' => $conflicts,
            'invalid' => count($invalidRows),
        ];
    }
}
