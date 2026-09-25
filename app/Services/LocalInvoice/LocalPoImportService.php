<?php

namespace App\Services\LocalInvoice;

use App\Models\LocalPurchaseOrder;
use App\Models\User;
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
        $seenHeaders = [];

        $suppliers = User::localEligible()->with('supplier')->get()
            ->filter(fn (User $user) => filled($user->supplier?->company_name))
            ->groupBy(fn (User $u) => mb_strtolower(trim((string) $u->supplier?->company_name)));

        foreach ($rows as $row) {
            $number = (int) ($row['_row'] ?? 0);
            $rowErrors = [];

            foreach ($row['_formula_columns'] ?? [] as $column) {
                $rowErrors[] = [$column, 'Excel formulas are not allowed.'];
            }

            foreach (['po_number', 'supplier_name', 'po_date', 'po_amount'] as $field) {
                if (! isset($row[$field]) || $row[$field] === null || $row[$field] === '') {
                    $rowErrors[] = [$field, 'This field is required.'];
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
                            ? "No exact active Local Supplier match was found for '{$row['supplier_name']}'."
                            : "Supplier name '{$row['supplier_name']}' is ambiguous.",
                    ];
                }
            }

            $poDate = $this->date($row['po_date'] ?? null);
            if (! empty($row['po_date']) && ! $poDate) {
                $rowErrors[] = ['po_date', 'Use a valid date format (YYYY-MM-DD or Excel date).'];
            }

            $poAmount = $this->money($row['po_amount'] ?? null);
            if (! empty($row['po_amount']) && ($poAmount === null || bccomp($poAmount, '0', 2) <= 0)) {
                $rowErrors[] = ['po_amount', 'PO amount must be a positive number with up to two decimal places.'];
            }

            $poKey = mb_strtolower(trim((string) ($row['po_number'] ?? '')));
            if ($poKey !== '') {
                $header = [
                    $supplierMatches->first()?->id,
                    $poDate,
                    $poAmount ?? '0.00',
                ];
                if (isset($seenHeaders[$poKey]) && $seenHeaders[$poKey] !== $header) {
                    $rowErrors[] = ['po_number', 'Repeated PO rows have conflicting header values.'];
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
                'action' => 'NEW',
            ];
        }

        $dbResult = $this->checkDatabaseConflicts($normalized);
        $errors = array_merge($errors, $dbResult['errors']);
        $normalized = $dbResult['rows'];

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
                    'import_file' => collect($result['errors'])->map(fn ($e) => "Row {$e['row']} {$e['column']}: {$e['message']}")->all(),
                ]);
            }

            $newPo = 0;
            $existingPo = 0;
            $processedKeys = [];

            foreach ($result['rows'] as $row) {
                $key = mb_strtolower($row['po_number']);
                if (isset($processedKeys[$key])) {
                    continue;
                }
                $processedKeys[$key] = true;

                $existing = LocalPurchaseOrder::whereRaw('LOWER(po_number) = ?', [$key])
                    ->lockForUpdate()
                    ->first();

                if (! $existing) {
                    $this->masters->createPurchaseOrder($actor, [
                        'po_number' => $row['po_number'],
                        'supplier_id' => $row['supplier_id'],
                        'po_date' => $row['po_date'],
                        'total_amount' => $row['po_amount'],
                        'description' => 'Imported via Infor ERP Purchase Order',
                    ], LocalPurchaseOrder::SOURCE_IMPORT);

                    $newPo++;
                } else {
                    $existingPo++;
                }
            }

            $this->audit->record($actor, 'po_import_confirmed', $actor, null, null, array_merge($metadata, [
                'confirmed_at' => now()->toIso8601String(),
                'source_row_count' => count($rows),
                'created_po_count' => $newPo,
                'existing_po_count' => $existingPo,
            ]));

            return [
                'newPo' => $newPo,
                'existingPo' => $existingPo,
                'total' => count($rows),
            ];
        });
    }

    private function checkDatabaseConflicts(array $rows): array
    {
        $errors = [];
        $uniquePoKeys = collect($rows)->pluck('po_number')->filter()->map(fn ($v) => mb_strtolower($v))->unique();
        $existingMap = LocalPurchaseOrder::whereIn(DB::raw('LOWER(po_number)'), $uniquePoKeys->all())
            ->get()
            ->keyBy(fn ($po) => mb_strtolower($po->po_number));

        foreach ($rows as &$row) {
            $key = mb_strtolower($row['po_number']);
            if (! isset($existingMap[$key])) {
                continue;
            }

            $existing = $existingMap[$key];
            $supplierMatch = (int) $existing->supplier_id === (int) $row['supplier_id'];
            $dateMatch = $existing->po_date?->format('Y-m-d') === $row['po_date'];
            $amountMatch = bccomp((string) $existing->total_amount, (string) $row['po_amount'], 2) === 0;

            if ($supplierMatch && $dateMatch && $amountMatch) {
                $row['action'] = 'EXISTING';
            } else {
                $errors[] = [
                    'row' => $row['_row'],
                    'column' => 'po_number',
                    'message' => "PO '{$row['po_number']}' already exists with conflicting values (Supplier, Date, or Amount differs).",
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

        if (! str_contains($value, '.')) {
            return $value.'.00';
        }

        [$whole, $fraction] = explode('.', $value, 2);

        return $whole.'.'.str_pad($fraction, 2, '0');
    }

    private function summary(array $rows, array $errors): array
    {
        $uniquePos = collect($rows)->pluck('po_number')->filter()->map(fn ($v) => mb_strtolower($v))->unique();
        $newPos = collect($rows)->filter(fn ($r) => ($r['action'] ?? '') === 'NEW')->pluck('po_number')->unique()->count();
        $existingPos = collect($rows)->filter(fn ($r) => ($r['action'] ?? '') === 'EXISTING')->pluck('po_number')->unique()->count();
        $conflicts = collect($rows)->filter(fn ($r) => ($r['action'] ?? '') === 'CONFLICT')->pluck('po_number')->unique()->count();

        return [
            'total_rows' => count($rows),
            'unique_pos' => $uniquePos->count(),
            'new_po' => $newPos,
            'existing_po' => $existingPos,
            'conflicts' => $conflicts,
            'invalid' => count($errors),
        ];
    }
}
