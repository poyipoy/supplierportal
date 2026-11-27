<?php

namespace App\Services\Pricing;

use App\Models\ExchangeRate;
use App\Models\HistoricalBenchmarkLine;
use App\Models\MasterDataAuditLog;
use App\Models\MaterialMaster;
use App\Models\PrItem;
use App\Models\ReferencePriceImport;
use App\Models\User;
use App\Services\Materials\MaterialResolver;
use App\Support\BusinessTime;
use App\Support\Money;
use App\Support\SpreadsheetCellSanitizer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

class HistoricalBenchmarkImportService
{
    public const MAX_ROWS = 5000;

    public const MUTATION_LOCK_KEY = 'historical_benchmark_import_mutation';

    private const PREVIEW_CONFLICT_LIMIT = 100;

    private const PREVIEW_WARNING_LIMIT = 50;

    private const PREVIEW_SAMPLE_LIMIT = 100;

    private const UPSERT_CHUNK_SIZE = 500;

    public const REQUIRED_HEADERS = [
        'po_number',
        'po_date',
        'supplier_name',
        'material_code',
        'shape',
        'key_dimension',
        'ordered_weight_kg',
        'currency',
        'price_per_kg',
    ];

    public const OPTIONAL_HEADERS = [
        'line_no',
        'pr_number',
        'notes',
    ];

    public function __construct(
        private readonly MaterialResolver $materialResolver,
        private readonly ReferencePriceRuleResolver $ruleResolver,
        private readonly AnnualExchangeRateResolver $annualRates,
    ) {}

    /**
     * Parse and validate a workbook (.xlsx) or .csv file and return a compact preview payload.
     *
     * @return array{
     *     parsed_count: int,
     *     preview: array<string, mixed>,
     *     candidates: list<array<string, mixed>>
     * }
     */
    public function analyzeFile(string $filePath, string $mode, ?string $sheetName = null, ?string $originalExtension = null): array
    {
        if (! is_file($filePath) || ! is_readable($filePath)) {
            throw new InvalidArgumentException(__('materials.reference_prices.historical_import.file_unreadable'));
        }

        if (! in_array($mode, [ReferencePriceImport::MODE_MERGE, ReferencePriceImport::MODE_REPLACE], true)) {
            throw new InvalidArgumentException(__('materials.reference_prices.historical_import.invalid_session'));
        }

        $ext = strtolower(trim((string) ($originalExtension ?: pathinfo($filePath, PATHINFO_EXTENSION))));
        $extracted = $ext === 'csv'
            ? $this->extractCsvRows($filePath)
            : $this->extractSpreadsheetRows($filePath, $sheetName);

        $resolvedSheet = $extracted['sheet_name'];
        $rawRows = $extracted['rows'];

        if ($rawRows === []) {
            throw new InvalidArgumentException(__('materials.reference_prices.historical_import.empty_file'));
        }

        if (count($rawRows) > self::MAX_ROWS) {
            throw new InvalidArgumentException(__('materials.reference_prices.historical_import.too_many_rows', [
                'max' => self::MAX_ROWS,
                'count' => count($rawRows),
            ]));
        }

        $materialIndex = $this->materialResolver->activeIndex();
        $importSuppliersByCompany = $this->buildImportSupplierIndex();
        $activePortalPoNumbers = $this->loadActivePortalPoNumbers();
        $existingLinesByKey = $this->loadExistingLinesByKey();
        $today = BusinessTime::today();

        $conflicts = [];
        $warnings = [];
        $warningTotalCount = 0;
        $candidates = [];
        $seenNaturalKeys = [];

        foreach ($rawRows as $entry) {
            $rowNum = $entry['row_number'];
            $cells = $entry['cells'];
            $formulas = $entry['formulas'];

            if ($formulas !== []) {
                $conflicts[] = __('materials.reference_prices.historical_import.row_formula_rejected', [
                    'row' => $rowNum,
                    'columns' => implode(', ', $formulas),
                ]);

                continue;
            }

            $rowErrors = [];

            $rawPoNumber = trim((string) ($cells['po_number'] ?? ''));
            $poNormalized = HistoricalBenchmarkLine::normalizePoNumber($rawPoNumber);
            if ($poNormalized === '') {
                $rowErrors[] = __('materials.reference_prices.historical_import.err_po_number_required');
            } elseif (mb_strlen($rawPoNumber) > 100) {
                $rowErrors[] = __('materials.reference_prices.historical_import.err_po_number_max');
            } elseif ($this->hasUnsafeIdentifierPrefix($rawPoNumber)) {
                $rowErrors[] = __('materials.reference_prices.historical_import.err_po_number_invalid');
            } elseif (isset($activePortalPoNumbers[$poNormalized])) {
                $rowErrors[] = __('materials.reference_prices.historical_import.err_portal_po_collision', [
                    'po' => SpreadsheetCellSanitizer::text($rawPoNumber, '-'),
                ]);
            }

            $parsedDate = $this->parsePoDate($cells['po_date'] ?? null);
            if ($parsedDate === null) {
                $rowErrors[] = __('materials.reference_prices.historical_import.err_po_date_invalid');
            } elseif ($parsedDate->year < 2000) {
                $rowErrors[] = __('materials.reference_prices.historical_import.err_po_date_year');
            } elseif ($parsedDate->gt($today)) {
                $rowErrors[] = __('materials.reference_prices.historical_import.err_po_date_future');
            }

            $rawSupplierName = trim((string) ($cells['supplier_name'] ?? ''));
            if ($rawSupplierName === '') {
                $rowErrors[] = __('materials.reference_prices.historical_import.err_supplier_required');
            } elseif (mb_strlen($rawSupplierName) > 255) {
                $rowErrors[] = __('materials.reference_prices.historical_import.err_supplier_max');
            }

            $rawMaterialCode = trim((string) ($cells['material_code'] ?? ''));
            $material = null;
            if ($rawMaterialCode === '') {
                $rowErrors[] = __('materials.reference_prices.historical_import.err_material_required');
            } else {
                $material = $this->materialResolver->resolveExact($rawMaterialCode, $materialIndex);
                if (! $material instanceof MaterialMaster) {
                    $rowErrors[] = __('materials.reference_prices.historical_import.err_material_unmapped', [
                        'code' => SpreadsheetCellSanitizer::text($rawMaterialCode, '-'),
                    ]);
                }
            }

            $shape = $this->parseShape($cells['shape'] ?? null);
            if ($shape === null) {
                $rowErrors[] = __('materials.reference_prices.historical_import.err_shape_invalid');
            }

            $keyDimension = $this->parsePositiveDecimal($cells['key_dimension'] ?? null, '10000.0000');
            if ($keyDimension === null) {
                $rowErrors[] = __('materials.reference_prices.historical_import.err_dimension_invalid');
            }

            $orderedWeightKg = $this->parsePositiveDecimal($cells['ordered_weight_kg'] ?? null, '99999999999999.9999');
            if ($orderedWeightKg === null) {
                $rowErrors[] = __('materials.reference_prices.historical_import.err_weight_invalid');
            }

            $currency = $this->parseCurrency($cells['currency'] ?? null);
            if ($currency === null) {
                $rowErrors[] = __('materials.reference_prices.historical_import.err_currency_invalid');
            }

            $pricePerKg = $this->parsePositiveDecimal($cells['price_per_kg'] ?? null, '99999999999.9999');
            if ($pricePerKg === null) {
                $rowErrors[] = __('materials.reference_prices.historical_import.err_price_invalid');
            }

            $lineNo = $this->parseLineNo($cells['line_no'] ?? null);
            if ($lineNo === null) {
                $rowErrors[] = __('materials.reference_prices.historical_import.err_line_no_invalid');
            }

            $rawPrNumber = trim((string) ($cells['pr_number'] ?? ''));
            if ($rawPrNumber !== '' && mb_strlen($rawPrNumber) > 100) {
                $rowErrors[] = __('materials.reference_prices.historical_import.err_pr_number_max');
            } elseif ($rawPrNumber !== '' && $this->hasUnsafeIdentifierPrefix($rawPrNumber)) {
                $rowErrors[] = __('materials.reference_prices.historical_import.err_pr_number_invalid');
            }

            $rawNotes = trim((string) ($cells['notes'] ?? ''));
            if ($rawNotes !== '' && mb_strlen($rawNotes) > 1000) {
                $rowErrors[] = __('materials.reference_prices.historical_import.err_notes_max');
            }

            if ($rowErrors !== []) {
                $conflicts[] = __('materials.reference_prices.historical_import.row_error_prefix', [
                    'row' => $rowNum,
                    'errors' => implode('; ', $rowErrors),
                ]);

                continue;
            }

            /** @var MaterialMaster $material */
            /** @var CarbonImmutable $parsedDate */
            /** @var string $shape */
            /** @var string $keyDimension */
            /** @var string $orderedWeightKg */
            /** @var string $currency */
            /** @var string $pricePerKg */
            /** @var int $lineNo */
            $naturalKey = self::buildNaturalKey($poNormalized, (int) $material->id, $shape, $keyDimension, $lineNo);
            if (isset($seenNaturalKeys[$naturalKey])) {
                $conflicts[] = __('materials.reference_prices.historical_import.row_duplicate_key', [
                    'row' => $rowNum,
                    'first_row' => $seenNaturalKeys[$naturalKey],
                    'po' => SpreadsheetCellSanitizer::text($rawPoNumber, '-'),
                    'material' => $material->material_code,
                ]);

                continue;
            }
            $seenNaturalKeys[$naturalKey] = $rowNum;

            $roundedDimension = ReferencePriceRuleResolver::ceilDimension($keyDimension);
            $poDateStr = $parsedDate->format('Y-m-d');
            $poDatetimeUtc = BusinessTime::toStorage($parsedDate)->format('Y-m-d H:i:s');
            $poYear = (int) $parsedDate->year;

            $supplierLookupKey = mb_strtolower((string) preg_replace('/\s+/u', ' ', $rawSupplierName));
            $linkedSupplierId = $importSuppliersByCompany[$supplierLookupKey] ?? null;

            $sanitizedSupplierName = SpreadsheetCellSanitizer::text($rawSupplierName, '-');
            $sanitizedPrNumber = $rawPrNumber !== '' ? SpreadsheetCellSanitizer::text($rawPrNumber, '') : null;
            $sanitizedNotes = $rawNotes !== '' ? SpreadsheetCellSanitizer::text($rawNotes, '') : null;

            // Non-blocking warnings: annual FX availability & active rule band coverage
            $ruleResult = $this->ruleResolver->resolve($shape, $keyDimension);
            if (! $ruleResult->isMatched()) {
                $warningTotalCount++;
                if (count($warnings) < self::PREVIEW_WARNING_LIMIT) {
                    $warnings[] = __('materials.reference_prices.historical_import.warn_dimension_uncovered', [
                        'row' => $rowNum,
                        'dimension' => $roundedDimension,
                        'shape' => $shape,
                    ]);
                }
            }

            if ($currency !== ExchangeRate::CURRENCY_IDR && $this->annualRates->rate($currency, $poYear) === null) {
                $warningTotalCount++;
                if (count($warnings) < self::PREVIEW_WARNING_LIMIT) {
                    $warnings[] = __('materials.reference_prices.historical_import.warn_missing_annual_rate', [
                        'row' => $rowNum,
                        'currency' => $currency,
                        'year' => $poYear,
                    ]);
                }
            }

            $existing = $existingLinesByKey[$naturalKey] ?? null;
            $changeType = 'new';
            if ($existing !== null) {
                $changeType = $this->isRowUnchanged($existing, [
                    'po_date' => $poDateStr,
                    'supplier_name' => $sanitizedSupplierName,
                    'supplier_id' => $linkedSupplierId,
                    'ordered_weight_kg' => $orderedWeightKg,
                    'currency' => $currency,
                    'price_per_kg' => $pricePerKg,
                    'pr_number' => $sanitizedPrNumber,
                    'notes' => $sanitizedNotes,
                ]) ? 'unchanged' : 'updated';
            }

            $candidates[] = [
                'natural_key' => $naturalKey,
                'change_type' => $changeType,
                'po_number' => $rawPoNumber,
                'po_number_normalized' => $poNormalized,
                'line_no' => $lineNo,
                'po_date' => $poDateStr,
                'po_datetime_utc' => $poDatetimeUtc,
                'po_year' => $poYear,
                'supplier_name' => $sanitizedSupplierName,
                'supplier_id' => $linkedSupplierId,
                'material_master_id' => (int) $material->id,
                'material_code_snapshot' => $material->material_code,
                'shape' => $shape,
                'key_dimension' => $keyDimension,
                'rounded_dimension' => $roundedDimension,
                'category_name' => $ruleResult->isMatched() ? $ruleResult->rule?->category_name : null,
                'ordered_weight_kg' => $orderedWeightKg,
                'currency' => $currency,
                'price_per_kg' => $pricePerKg,
                'pr_number' => $sanitizedPrNumber,
                'notes' => $sanitizedNotes,
            ];
        }

        $newCount = 0;
        $updatedCount = 0;
        $unchangedCount = 0;
        foreach ($candidates as $cand) {
            match ($cand['change_type']) {
                'new' => $newCount++,
                'updated' => $updatedCount++,
                default => $unchangedCount++,
            };
        }

        $removedCount = 0;
        if ($mode === ReferencePriceImport::MODE_REPLACE) {
            foreach ($existingLinesByKey as $existingKey => $_row) {
                if (! isset($seenNaturalKeys[$existingKey])) {
                    $removedCount++;
                }
            }
        }

        $sampleRows = array_map(static fn (array $cand): array => [
            'change_type' => $cand['change_type'],
            'po_number' => SpreadsheetCellSanitizer::text($cand['po_number'], '-'),
            'line_no' => $cand['line_no'],
            'po_date' => $cand['po_date'],
            'supplier_name' => $cand['supplier_name'],
            'supplier_linked' => $cand['supplier_id'] !== null,
            'material_code' => $cand['material_code_snapshot'],
            'shape' => $cand['shape'],
            'key_dimension' => $cand['key_dimension'],
            'rounded_dimension' => $cand['rounded_dimension'],
            'category_name' => $cand['category_name'] ?? '-',
            'ordered_weight_kg' => $cand['ordered_weight_kg'],
            'currency' => $cand['currency'],
            'price_per_kg' => $cand['price_per_kg'],
            'pr_number' => $cand['pr_number'] ?? '-',
        ], array_slice($candidates, 0, self::PREVIEW_SAMPLE_LIMIT));

        $isValid = $conflicts === [] && $candidates !== [];

        return [
            'parsed_count' => count($rawRows),
            'candidates' => $candidates,
            'preview' => [
                'valid' => $isValid,
                'mode' => $mode,
                'sheet_name' => $resolvedSheet,
                'total_rows' => count($rawRows),
                'valid_rows' => count($candidates),
                'counts' => [
                    'new' => $newCount,
                    'updated' => $updatedCount,
                    'unchanged' => $unchangedCount,
                    'removed' => $removedCount,
                    'warnings' => $warningTotalCount,
                ],
                'conflicts' => array_slice($conflicts, 0, self::PREVIEW_CONFLICT_LIMIT),
                'conflicts_truncated' => max(0, count($conflicts) - self::PREVIEW_CONFLICT_LIMIT),
                'warnings' => $warnings,
                'warnings_truncated' => max(0, $warningTotalCount - count($warnings)),
                'sample_rows' => $sampleRows,
                'sample_truncated' => max(0, count($candidates) - count($sampleRows)),
            ],
        ];
    }

    /**
     * Atomically re-verify and commit a previewed historical benchmark import.
     */
    public function commit(ReferencePriceImport $import, User $actor): array
    {
        $privatePathToClean = null;

        $summary = Cache::lock(self::MUTATION_LOCK_KEY, 30)->block(10, function () use ($import, $actor, &$privatePathToClean): array {
            $freshImport = ReferencePriceImport::query()->whereKey($import->id)->firstOrFail();

            if ($freshImport->import_type !== ReferencePriceImport::TYPE_HISTORICAL_BENCHMARK) {
                throw new InvalidArgumentException(__('materials.reference_prices.historical_import.invalid_session'));
            }

            if ((int) $freshImport->uploaded_by !== (int) $actor->id) {
                throw new InvalidArgumentException(__('materials.reference_prices.historical_import.invalid_session'));
            }

            if ($freshImport->status !== ReferencePriceImport::STATUS_PREVIEWED) {
                throw new InvalidArgumentException(__('materials.reference_prices.historical_import.session_expired'));
            }

            $fullPath = Storage::path($freshImport->private_storage_path);
            if (! is_file($fullPath) || ! is_readable($fullPath)) {
                throw new InvalidArgumentException(__('materials.reference_prices.historical_import.file_unreadable'));
            }

            $actualHash = hash_file('sha256', $fullPath);
            if (! is_string($actualHash) || ! hash_equals((string) $freshImport->source_file_hash, $actualHash)) {
                throw new InvalidArgumentException(__('materials.reference_prices.historical_import.file_tampered'));
            }

            $ext = strtolower(pathinfo((string) $freshImport->source_file_name, PATHINFO_EXTENSION));
            $sheetName = $freshImport->source_sheet !== '' ? $freshImport->source_sheet : null;

            // Re-validate under the cross-import mutex before opening the transaction so we read the latest committed DB state.
            $analysis = $this->analyzeFile($fullPath, $freshImport->import_mode, $sheetName, $ext);
            if (empty($analysis['preview']['valid']) || empty($analysis['candidates'])) {
                $freshImport->update(['preview_diff' => $analysis['preview']]);
                throw new InvalidArgumentException(__('materials.reference_prices.historical_import.confirm_conflicts_detected'));
            }

            return DB::transaction(function () use ($freshImport, $actor, $analysis, &$privatePathToClean): array {
                $lockedImport = ReferencePriceImport::query()
                    ->whereKey($freshImport->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (
                    $lockedImport->import_type !== ReferencePriceImport::TYPE_HISTORICAL_BENCHMARK
                    || (int) $lockedImport->uploaded_by !== (int) $actor->id
                    || $lockedImport->status !== ReferencePriceImport::STATUS_PREVIEWED
                ) {
                    throw new InvalidArgumentException(__('materials.reference_prices.historical_import.session_expired'));
                }

                $now = now();
                $actorId = (int) $actor->id;
                $importId = (int) $lockedImport->id;
                $candidates = $analysis['candidates'];
                $counts = $analysis['preview']['counts'];

                $payloads = array_map(static fn (array $cand): array => [
                    'import_id' => $importId,
                    'po_number' => $cand['po_number'],
                    'po_number_normalized' => $cand['po_number_normalized'],
                    'line_no' => $cand['line_no'],
                    'po_date' => $cand['po_date'],
                    'po_datetime_utc' => $cand['po_datetime_utc'],
                    'po_year' => $cand['po_year'],
                    'supplier_name' => $cand['supplier_name'],
                    'supplier_id' => $cand['supplier_id'],
                    'material_master_id' => $cand['material_master_id'],
                    'material_code_snapshot' => $cand['material_code_snapshot'],
                    'shape' => $cand['shape'],
                    'key_dimension' => $cand['key_dimension'],
                    'rounded_dimension' => $cand['rounded_dimension'],
                    'ordered_weight_kg' => $cand['ordered_weight_kg'],
                    'currency' => $cand['currency'],
                    'price_per_kg' => $cand['price_per_kg'],
                    'pr_number' => $cand['pr_number'],
                    'notes' => $cand['notes'],
                    'created_by' => $actorId,
                    'updated_by' => $actorId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $candidates);

                if ($lockedImport->import_mode === ReferencePriceImport::MODE_REPLACE) {
                    HistoricalBenchmarkLine::query()->delete();
                    foreach (array_chunk($payloads, self::UPSERT_CHUNK_SIZE) as $chunk) {
                        HistoricalBenchmarkLine::query()->insert($chunk);
                    }
                } else {
                    $updateColumns = [
                        'import_id',
                        'po_number',
                        'po_date',
                        'po_datetime_utc',
                        'po_year',
                        'supplier_name',
                        'supplier_id',
                        'material_code_snapshot',
                        'rounded_dimension',
                        'ordered_weight_kg',
                        'currency',
                        'price_per_kg',
                        'pr_number',
                        'notes',
                        'updated_by',
                        'updated_at',
                    ];

                    foreach (array_chunk($payloads, self::UPSERT_CHUNK_SIZE) as $chunk) {
                        HistoricalBenchmarkLine::query()->upsert(
                            $chunk,
                            ['po_number_normalized', 'material_master_id', 'shape', 'key_dimension', 'line_no'],
                            $updateColumns
                        );
                    }
                }

                $lockedImport->update([
                    'status' => ReferencePriceImport::STATUS_CONFIRMED,
                    'preview_diff' => $analysis['preview'],
                    'confirmed_at' => $now,
                ]);

                MasterDataAuditLog::record(
                    $lockedImport,
                    MasterDataAuditLog::CREATED,
                    null,
                    [
                        'import_type' => ReferencePriceImport::TYPE_HISTORICAL_BENCHMARK,
                        'import_mode' => $lockedImport->import_mode,
                        'source_file_name' => $lockedImport->source_file_name,
                        'source_file_hash' => $lockedImport->source_file_hash,
                        'total_rows' => count($payloads),
                        'counts' => $counts,
                    ],
                    $actor
                );

                $privatePathToClean = $lockedImport->private_storage_path;

                return $counts;
            });
        });

        $this->resetBenchmarkCaches();

        if ($privatePathToClean !== null && $privatePathToClean !== '') {
            try {
                Storage::delete($privatePathToClean);
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $summary;
    }

    public function resetBenchmarkCaches(): void
    {
        if (app()->resolved(ReferencePriceRuleResolver::class)) {
            app(ReferencePriceRuleResolver::class)->reset();
        }
        if (app()->resolved(HistoricalReferencePriceService::class)) {
            app(HistoricalReferencePriceService::class)->clearMemo();
        }
        app()->forgetInstance(ReferencePriceRuleResolver::class);
        app()->forgetInstance(HistoricalReferencePriceService::class);
        app()->forgetInstance(ReferencePriceBenchmarkResolver::class);
    }

    public static function buildNaturalKey(
        string $poNormalized,
        int $materialMasterId,
        string $shape,
        string $keyDimension,
        int $lineNo
    ): string {
        return implode('|', [
            $poNormalized,
            $materialMasterId,
            $shape,
            Money::normalize($keyDimension, 4),
            $lineNo,
        ]);
    }

    /**
     * @return array{sheet_name: string, rows: list<array{row_number: int, cells: array<string, mixed>, formulas: list<string>}>}
     */
    private function extractSpreadsheetRows(string $filePath, ?string $sheetName): array
    {
        $reader = IOFactory::createReaderForFile($filePath);
        $worksheetNames = $reader->listWorksheetNames($filePath);

        if ($worksheetNames === []) {
            throw new InvalidArgumentException(__('materials.reference_prices.historical_import.empty_file'));
        }

        $targetSheet = ($sheetName !== null && trim($sheetName) !== '')
            ? trim($sheetName)
            : $worksheetNames[0];

        if (! in_array($targetSheet, $worksheetNames, true)) {
            throw new InvalidArgumentException(__('materials.reference_prices.historical_import.sheet_not_found', [
                'sheet' => SpreadsheetCellSanitizer::text($targetSheet, '-'),
            ]));
        }

        if (method_exists($reader, 'setLoadSheetsOnly')) {
            $reader->setLoadSheetsOnly([$targetSheet]);
        }

        $spreadsheet = $reader->load($filePath);
        try {
            $sheet = $spreadsheet->getSheetByName($targetSheet);
            if ($sheet === null) {
                throw new InvalidArgumentException(__('materials.reference_prices.historical_import.sheet_not_found', [
                    'sheet' => SpreadsheetCellSanitizer::text($targetSheet, '-'),
                ]));
            }

            $highestRow = $sheet->getHighestDataRow();
            $highestColIndex = min(100, Coordinate::columnIndexFromString($sheet->getHighestDataColumn()));

            $headerRow = null;
            $columnMap = [];

            for ($r = 1; $r <= min(10, $highestRow); $r++) {
                $candidateMap = [];
                for ($c = 1; $c <= $highestColIndex; $c++) {
                    $colLetter = Coordinate::stringFromColumnIndex($c);
                    $rawHeader = (string) ($sheet->getCell("{$colLetter}{$r}")->getValue() ?? '');
                    $normHeader = $this->normalizeHeader($rawHeader);
                    if ($normHeader !== '' && ! isset($candidateMap[$normHeader])) {
                        $candidateMap[$normHeader] = $colLetter;
                    }
                }

                if (isset($candidateMap['po_number']) || isset($candidateMap['material_code'])) {
                    $headerRow = $r;
                    $columnMap = $candidateMap;
                    break;
                }
            }

            if ($headerRow === null) {
                throw new InvalidArgumentException(__('materials.reference_prices.historical_import.missing_headers', [
                    'headers' => implode(', ', self::REQUIRED_HEADERS),
                ]));
            }

            $missingHeaders = array_values(array_diff(self::REQUIRED_HEADERS, array_keys($columnMap)));
            if ($missingHeaders !== []) {
                throw new InvalidArgumentException(__('materials.reference_prices.historical_import.missing_headers', [
                    'headers' => implode(', ', $missingHeaders),
                ]));
            }

            $allKnownColumns = array_merge(self::REQUIRED_HEADERS, self::OPTIONAL_HEADERS);
            $rows = [];

            for ($r = $headerRow + 1; $r <= $highestRow; $r++) {
                $cells = [];
                $formulas = [];
                $hasAnyContent = false;

                foreach ($allKnownColumns as $headerName) {
                    $colLetter = $columnMap[$headerName] ?? null;
                    if ($colLetter === null) {
                        $cells[$headerName] = null;

                        continue;
                    }

                    $cell = $sheet->getCell("{$colLetter}{$r}");
                    $extractedCell = $this->readSpreadsheetCell($cell, $headerName);
                    if ($extractedCell['is_formula']) {
                        $formulas[] = $headerName;
                        $hasAnyContent = true;
                    }

                    $val = $extractedCell['value'];
                    if ($val !== null && trim((string) $val) !== '') {
                        $hasAnyContent = true;
                    }
                    $cells[$headerName] = $val;
                }

                if (! $hasAnyContent) {
                    continue;
                }

                $rows[] = [
                    'row_number' => $r,
                    'cells' => $cells,
                    'formulas' => $formulas,
                ];

                if (count($rows) > self::MAX_ROWS) {
                    break;
                }
            }

            return [
                'sheet_name' => $targetSheet,
                'rows' => $rows,
            ];
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    /**
     * @return array{is_formula: bool, value: mixed}
     */
    private function readSpreadsheetCell(Cell $cell, string $headerName): array
    {
        $raw = $cell->getValue();

        if ($cell->isFormula() || (is_string($raw) && preg_match('/^[\s\v\0\x{FEFF}]*=/u', $raw) === 1)) {
            return ['is_formula' => true, 'value' => null];
        }

        if ($headerName === 'po_date' && $raw !== null && is_numeric($raw) && ExcelDate::isDateTime($cell)) {
            try {
                return [
                    'is_formula' => false,
                    'value' => ExcelDate::excelToDateTimeObject((float) $raw)->format('Y-m-d'),
                ];
            } catch (Throwable) {
                return ['is_formula' => false, 'value' => (string) $raw];
            }
        }

        if (is_float($raw) || is_int($raw)) {
            return ['is_formula' => false, 'value' => (string) $raw];
        }

        return ['is_formula' => false, 'value' => $raw !== null ? (string) $raw : null];
    }

    /**
     * @return array{sheet_name: string, rows: list<array{row_number: int, cells: array<string, mixed>, formulas: list<string>}>}
     */
    private function extractCsvRows(string $filePath): array
    {
        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            throw new InvalidArgumentException(__('materials.reference_prices.historical_import.file_unreadable'));
        }

        try {
            $firstLine = fgets($handle);
            if ($firstLine === false) {
                throw new InvalidArgumentException(__('materials.reference_prices.historical_import.empty_file'));
            }

            $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
            rewind($handle);

            $headerRow = null;
            $columnMap = [];
            $lineNo = 0;

            while ($lineNo < 10 && ($data = fgetcsv($handle, 0, $delimiter)) !== false) {
                $lineNo++;
                if ($lineNo === 1 && isset($data[0])) {
                    $data[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $data[0]) ?? (string) $data[0];
                }

                $candidateMap = [];
                foreach ($data as $idx => $rawHeader) {
                    $normHeader = $this->normalizeHeader((string) $rawHeader);
                    if ($normHeader !== '' && ! isset($candidateMap[$normHeader])) {
                        $candidateMap[$normHeader] = $idx;
                    }
                }

                if (isset($candidateMap['po_number']) || isset($candidateMap['material_code'])) {
                    $headerRow = $lineNo;
                    $columnMap = $candidateMap;
                    break;
                }
            }

            if ($headerRow === null) {
                throw new InvalidArgumentException(__('materials.reference_prices.historical_import.missing_headers', [
                    'headers' => implode(', ', self::REQUIRED_HEADERS),
                ]));
            }

            $missingHeaders = array_values(array_diff(self::REQUIRED_HEADERS, array_keys($columnMap)));
            if ($missingHeaders !== []) {
                throw new InvalidArgumentException(__('materials.reference_prices.historical_import.missing_headers', [
                    'headers' => implode(', ', $missingHeaders),
                ]));
            }

            $allKnownColumns = array_merge(self::REQUIRED_HEADERS, self::OPTIONAL_HEADERS);
            $rows = [];

            while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
                $lineNo++;
                $cells = [];
                $formulas = [];
                $hasAnyContent = false;

                foreach ($allKnownColumns as $headerName) {
                    $idx = $columnMap[$headerName] ?? null;
                    $raw = ($idx !== null && array_key_exists($idx, $data)) ? (string) $data[$idx] : null;

                    if ($raw !== null && preg_match('/^[\s\v\0\x{FEFF}]*=/u', $raw) === 1) {
                        $formulas[] = $headerName;
                        $hasAnyContent = true;
                        $cells[$headerName] = null;

                        continue;
                    }

                    if ($raw !== null && trim($raw) !== '') {
                        $hasAnyContent = true;
                    }
                    $cells[$headerName] = $raw;
                }

                if (! $hasAnyContent) {
                    continue;
                }

                $rows[] = [
                    'row_number' => $lineNo,
                    'cells' => $cells,
                    'formulas' => $formulas,
                ];

                if (count($rows) > self::MAX_ROWS) {
                    break;
                }
            }

            return [
                'sheet_name' => 'CSV',
                'rows' => $rows,
            ];
        } finally {
            fclose($handle);
        }
    }

    private function normalizeHeader(string $rawHeader): string
    {
        $clean = preg_replace('/^\xEF\xBB\xBF/', '', trim($rawHeader)) ?? '';
        $normalized = strtolower(preg_replace('/[^a-z0-9]+/', '_', $clean) ?? '');

        return trim($normalized, '_');
    }

    private function hasUnsafeIdentifierPrefix(string $value): bool
    {
        return preg_match('/^[\s\v\0\x{FEFF}]*[\t\r=+\-@]/u', $value) === 1;
    }

    private function parsePoDate(mixed $raw): ?CarbonImmutable
    {
        if ($raw === null) {
            return null;
        }

        $str = trim((string) $raw);
        if ($str === '') {
            return null;
        }

        if (! preg_match('/^(\d{4})[-\/](\d{2})[-\/](\d{2})$/', $str, $m)) {
            return null;
        }

        $year = (int) $m[1];
        $month = (int) $m[2];
        $day = (int) $m[3];

        if (! checkdate($month, $day, $year)) {
            return null;
        }

        return BusinessTime::parseDate(sprintf('%04d-%02d-%02d', $year, $month, $day));
    }

    private function parseShape(mixed $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        return match (strtolower(trim((string) $raw))) {
            'flat' => PrItem::SHAPE_FLAT,
            'round' => PrItem::SHAPE_ROUND,
            'hollow' => PrItem::SHAPE_HOLLOW,
            default => null,
        };
    }

    private function parseCurrency(mixed $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $upper = strtoupper(trim((string) $raw));

        return in_array($upper, ExchangeRate::CURRENCIES, true) ? $upper : null;
    }

    private function parsePositiveDecimal(mixed $raw, string $maxAllowed): ?string
    {
        if ($raw === null) {
            return null;
        }

        $clean = trim((string) $raw);
        if ($clean === '' || ! preg_match('/^\d+(?:\.\d{1,8})?$/', $clean)) {
            return null;
        }

        $normalized = Money::normalize($clean, 4);
        if (bccomp($normalized, '0.0000', 4) <= 0 || bccomp($normalized, $maxAllowed, 4) > 0) {
            return null;
        }

        return $normalized;
    }

    private function parseLineNo(mixed $raw): ?int
    {
        if ($raw === null || trim((string) $raw) === '') {
            return 1;
        }

        $clean = trim((string) $raw);
        if (! preg_match('/^\d+$/', $clean)) {
            return null;
        }

        $val = (int) $clean;

        return ($val >= 1 && $val <= 10000) ? $val : null;
    }

    /**
     * Map normalized company_name -> unique supplier user_id for Import Suppliers.
     *
     * @return array<string, int>
     */
    private function buildImportSupplierIndex(): array
    {
        $rows = DB::table('users as u')
            ->join('suppliers as s', 's.user_id', '=', 'u.id')
            ->join('supplier_scopes as sc', function ($join): void {
                $join->on('sc.supplier_id', '=', 'u.id')
                    ->where('sc.scope', '=', 'import');
            })
            ->where('u.role', 'supplier')
            ->select(['u.id as user_id', 's.company_name'])
            ->get();

        $counts = [];
        $mapped = [];
        foreach ($rows as $row) {
            $name = trim((string) ($row->company_name ?? ''));
            if ($name === '') {
                continue;
            }
            $key = mb_strtolower((string) preg_replace('/\s+/u', ' ', $name));
            $counts[$key] = ($counts[$key] ?? 0) + 1;
            $mapped[$key] = (int) $row->user_id;
        }

        $unique = [];
        foreach ($mapped as $key => $userId) {
            if (($counts[$key] ?? 0) === 1) {
                $unique[$key] = $userId;
            }
        }

        return $unique;
    }

    /**
     * @return array<string, true>
     */
    private function loadActivePortalPoNumbers(): array
    {
        $numbers = DB::table('purchase_orders')
            ->whereNull('deleted_at')
            ->where('status', '<>', 'cancelled')
            ->pluck('po_number');

        $set = [];
        foreach ($numbers as $poNum) {
            $norm = HistoricalBenchmarkLine::normalizePoNumber((string) $poNum);
            if ($norm !== '') {
                $set[$norm] = true;
            }
        }

        return $set;
    }

    /**
     * @return array<string, object>
     */
    private function loadExistingLinesByKey(): array
    {
        $rows = DB::table('historical_benchmark_lines')->get([
            'id',
            'po_number_normalized',
            'material_master_id',
            'shape',
            'key_dimension',
            'line_no',
            'po_date',
            'supplier_name',
            'supplier_id',
            'ordered_weight_kg',
            'currency',
            'price_per_kg',
            'pr_number',
            'notes',
        ]);

        $map = [];
        foreach ($rows as $row) {
            $key = self::buildNaturalKey(
                (string) $row->po_number_normalized,
                (int) $row->material_master_id,
                (string) $row->shape,
                (string) $row->key_dimension,
                (int) $row->line_no
            );
            $map[$key] = $row;
        }

        return $map;
    }

    /**
     * @param  array<string, mixed>  $incoming
     */
    private function isRowUnchanged(object $existing, array $incoming): bool
    {
        return substr((string) $existing->po_date, 0, 10) === $incoming['po_date']
            && (string) $existing->supplier_name === (string) $incoming['supplier_name']
            && ($existing->supplier_id !== null ? (int) $existing->supplier_id : null) === $incoming['supplier_id']
            && bccomp((string) $existing->ordered_weight_kg, (string) $incoming['ordered_weight_kg'], 4) === 0
            && (string) $existing->currency === (string) $incoming['currency']
            && bccomp((string) $existing->price_per_kg, (string) $incoming['price_per_kg'], 4) === 0
            && ($existing->pr_number ?? null) === $incoming['pr_number']
            && ($existing->notes ?? null) === $incoming['notes'];
    }
}
