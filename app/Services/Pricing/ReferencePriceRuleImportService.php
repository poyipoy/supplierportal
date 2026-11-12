<?php

namespace App\Services\Pricing;

use App\Models\ReferencePriceImport;
use App\Models\ReferencePriceRule;
use App\Models\ReferencePriceRuleSet;
use App\Models\ReferencePriceSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

class ReferencePriceRuleImportService
{
    /**
     * Parse the Kategori worksheet from an Excel file into normalized rule definitions.
     *
     * @return list<array{category_key: string, category_name: string, dimension_min: int, dimension_max: int, sort_order: int}>
     */
    public function parseWorkbook(string $filePath, string $sheetName = 'Kategori'): array
    {
        if (! file_exists($filePath)) {
            throw new InvalidArgumentException("Excel file not found: {$filePath}");
        }

        $reader = IOFactory::createReaderForFile($filePath);
        $worksheetNames = $reader->listWorksheetNames($filePath);

        if (! in_array($sheetName, $worksheetNames, true)) {
            throw new InvalidArgumentException("Worksheet [{$sheetName}] not found in workbook.");
        }

        $spreadsheet = $reader->load($filePath);
        $sheet = $spreadsheet->getSheetByName($sheetName);

        if ($sheet === null) {
            throw new InvalidArgumentException("Unable to open worksheet [{$sheetName}].");
        }

        $highestRow = $sheet->getHighestRow();
        $highestCol = $sheet->getHighestColumn();

        // 1. Detect header row containing 'Thick/Dia (mm)' and 'Kategori'
        $headerRow = null;
        $dimCol = null;
        $catCol = null;

        for ($r = 1; $r <= min(20, $highestRow); $r++) {
            for ($c = 'A'; $c <= $highestCol; $c++) {
                $val = trim((string) $sheet->getCell("{$c}{$r}")->getValue());
                if (stripos($val, 'Thick/Dia') !== false || stripos($val, 'Thick') !== false) {
                    $dimCol = $c;
                    $headerRow = $r;
                }
                if (stripos($val, 'Kategori') !== false || stripos($val, 'Category') !== false) {
                    $catCol = $c;
                    $headerRow = $r;
                }
            }
            if ($dimCol !== null && $catCol !== null) {
                break;
            }
        }

        if ($headerRow === null || $dimCol === null || $catCol === null) {
            throw new InvalidArgumentException('Required column headers (Thick/Dia and Kategori) not found.');
        }

        // 2. Iterate data rows and group dimensions under categories
        $groups = [];
        $currentCategoryLabel = null;
        $lastDimension = null;

        for ($r = $headerRow + 1; $r <= $highestRow; $r++) {
            $rawDim = $sheet->getCell("{$dimCol}{$r}")->getValue();
            if ($rawDim === null || trim((string) $rawDim) === '') {
                continue;
            }

            if (! is_numeric($rawDim)) {
                continue;
            }

            $dim = (int) $rawDim;

            $cellCat = $sheet->getCell("{$catCol}{$r}");
            $rawCatVal = null;
            try {
                $rawCatVal = $cellCat->getCalculatedValue();
            } catch (\Throwable) {
                $rawCatVal = $cellCat->getFormattedValue();
            }

            if ($rawCatVal !== null && trim((string) $rawCatVal) !== '') {
                $label = trim((string) $rawCatVal);
                // In some formulas it might evaluate to a range string
                if (preg_match('/^(\d+)\s*[-–—]\s*(\d+)$/', $label, $m)) {
                    $currentCategoryLabel = "{$m[1]}-{$m[2]}";
                    if (! isset($groups[$currentCategoryLabel])) {
                        $groups[$currentCategoryLabel] = [
                            'expected_min' => (int) $m[1],
                            'expected_max' => (int) $m[2],
                            'dimensions' => [],
                        ];
                    }
                }
            }

            if ($currentCategoryLabel !== null) {
                $groups[$currentCategoryLabel]['dimensions'][] = $dim;
            }
        }

        if (empty($groups)) {
            throw new InvalidArgumentException('No category range groups could be parsed from the worksheet.');
        }

        // 3. Validate each group
        $normalizedRules = [];
        $sortOrder = 1;

        foreach ($groups as $catKey => $group) {
            $dims = $group['dimensions'];
            if (empty($dims)) {
                throw new InvalidArgumentException("Category [{$catKey}] has no dimension rows.");
            }

            $actualMin = min($dims);
            $actualMax = max($dims);

            if ($actualMin !== $group['expected_min'] || $actualMax !== $group['expected_max']) {
                throw new InvalidArgumentException(
                    "Category [{$catKey}] range bounds mismatch: expected {$group['expected_min']}-{$group['expected_max']}, found {$actualMin}-{$actualMax}."
                );
            }

            // Check continuity: count must equal (max - min + 1)
            $expectedCount = ($actualMax - $actualMin) + 1;
            if (count($dims) !== $expectedCount) {
                throw new InvalidArgumentException("Category [{$catKey}] has missing or duplicate dimensions in range {$actualMin}-{$actualMax}.");
            }

            $normalizedRules[] = [
                'category_key' => $catKey,
                'category_name' => $catKey,
                'dimension_min' => $actualMin,
                'dimension_max' => $actualMax,
                'sort_order' => $sortOrder++,
            ];
        }

        // 4. Validate no overlaps between parsed categories
        $overlapErrors = $this->validateRuleIntervals($normalizedRules);
        if (! empty($overlapErrors)) {
            throw new InvalidArgumentException('Parsed categories contain overlaps: '.implode('; ', $overlapErrors));
        }

        return $normalizedRules;
    }

    /**
     * Validate intervals for range validity and active overlap.
     *
     * @param  list<array{category_key?: string, dimension_min: int, dimension_max: int, status?: string}>  $rules
     * @return list<string> error messages
     */
    public function validateRuleIntervals(array $rules): array
    {
        $errors = [];
        $activeRules = array_filter($rules, fn ($r) => ($r['status'] ?? ReferencePriceRule::STATUS_ACTIVE) === ReferencePriceRule::STATUS_ACTIVE);
        $items = array_values($activeRules);
        $count = count($items);

        for ($i = 0; $i < $count; $i++) {
            $minA = (int) $items[$i]['dimension_min'];
            $maxA = (int) $items[$i]['dimension_max'];
            $keyA = $items[$i]['category_key'] ?? "Rule #{$i}";

            if ($minA <= 0) {
                $errors[] = "Category [{$keyA}] dimension_min must be positive.";
            }
            if ($maxA < $minA) {
                $errors[] = "Category [{$keyA}] dimension_max ({$maxA}) cannot be less than dimension_min ({$minA}).";
            }

            for ($j = $i + 1; $j < $count; $j++) {
                $minB = (int) $items[$j]['dimension_min'];
                $maxB = (int) $items[$j]['dimension_max'];
                $keyB = $items[$j]['category_key'] ?? "Rule #{$j}";

                // Overlap condition: max(minA, minB) <= min(maxA, maxB)
                if (max($minA, $minB) <= min($maxA, $maxB)) {
                    $errors[] = "Category [{$keyA}] ({$minA}-{$maxA}) overlaps with category [{$keyB}] ({$minB}-{$maxB}).";
                }
            }
        }

        return $errors;
    }

    /**
     * Build preview diff comparing incoming rules against current active rule set.
     *
     * @param  list<array{category_key: string, category_name: string, dimension_min: int, dimension_max: int, sort_order: int}>  $incomingRules
     * @return array{
     *     mode: string,
     *     valid: bool,
     *     new: list<array<string, mixed>>,
     *     updated: list<array<string, mixed>>,
     *     unchanged: list<array<string, mixed>>,
     *     removed: list<array<string, mixed>>,
     *     conflicts: list<string>,
     *     warnings: list<string>,
     *     candidate_rules: list<array<string, mixed>>
     * }
     */
    public function buildPreview(array $incomingRules, string $importMode, ?ReferencePriceRuleSet $currentSet = null): array
    {
        $currentRules = $currentSet?->rules->keyBy('category_key')->all() ?? [];

        $new = [];
        $updated = [];
        $unchanged = [];
        $removed = [];
        $conflicts = [];
        $warnings = [];

        $candidateRules = [];

        if ($importMode === ReferencePriceRuleSet::MODE_REPLACE) {
            foreach ($incomingRules as $incoming) {
                $key = $incoming['category_key'];
                if (! isset($currentRules[$key])) {
                    $new[] = $incoming;
                } else {
                    $curr = $currentRules[$key];
                    if ($curr->dimension_min !== (int) $incoming['dimension_min'] || $curr->dimension_max !== (int) $incoming['dimension_max']) {
                        $updated[] = [
                            ...$incoming,
                            'old_min' => $curr->dimension_min,
                            'old_max' => $curr->dimension_max,
                        ];
                    } else {
                        $unchanged[] = $incoming;
                    }
                }
                $candidateRules[] = $incoming + ['status' => ReferencePriceRule::STATUS_ACTIVE];
            }

            foreach ($currentRules as $key => $curr) {
                if (! collect($incomingRules)->contains('category_key', $key)) {
                    $removed[] = [
                        'category_key' => $curr->category_key,
                        'category_name' => $curr->category_name,
                        'dimension_min' => $curr->dimension_min,
                        'dimension_max' => $curr->dimension_max,
                    ];
                }
            }
        } elseif ($importMode === ReferencePriceRuleSet::MODE_MERGE) {
            $merged = [];
            foreach ($currentRules as $key => $curr) {
                $merged[$key] = [
                    'category_key' => $curr->category_key,
                    'category_name' => $curr->category_name,
                    'dimension_min' => $curr->dimension_min,
                    'dimension_max' => $curr->dimension_max,
                    'sort_order' => $curr->sort_order,
                    'status' => $curr->status,
                ];
            }

            foreach ($incomingRules as $incoming) {
                $key = $incoming['category_key'];
                if (! isset($merged[$key])) {
                    $new[] = $incoming;
                    $merged[$key] = $incoming + ['status' => ReferencePriceRule::STATUS_ACTIVE];
                } else {
                    $curr = $merged[$key];
                    if ($curr['dimension_min'] !== (int) $incoming['dimension_min'] || $curr['dimension_max'] !== (int) $incoming['dimension_max']) {
                        $updated[] = [
                            ...$incoming,
                            'old_min' => $curr['dimension_min'],
                            'old_max' => $curr['dimension_max'],
                        ];
                        $merged[$key] = $incoming + ['status' => ReferencePriceRule::STATUS_ACTIVE];
                    } else {
                        $unchanged[] = $incoming;
                    }
                }
            }

            // In merge mode, categories not in incoming are unchanged
            foreach ($currentRules as $key => $curr) {
                if (! collect($incomingRules)->contains('category_key', $key)) {
                    $unchanged[] = [
                        'category_key' => $curr->category_key,
                        'category_name' => $curr->category_name,
                        'dimension_min' => $curr->dimension_min,
                        'dimension_max' => $curr->dimension_max,
                        'sort_order' => $curr->sort_order,
                    ];
                }
            }

            $candidateRules = array_values($merged);
        } else {
            throw new InvalidArgumentException("Unsupported import mode [{$importMode}].");
        }

        // Validate candidate rules for overlaps
        $overlapErrors = $this->validateRuleIntervals($candidateRules);
        if (! empty($overlapErrors)) {
            $conflicts = array_merge($conflicts, $overlapErrors);
        }

        // Sort candidate rules by dimension_min
        usort($candidateRules, fn ($a, $b) => $a['dimension_min'] <=> $b['dimension_min']);
        for ($i = 0; $i < count($candidateRules); $i++) {
            $candidateRules[$i]['sort_order'] = $i + 1;
        }

        return [
            'mode' => $importMode,
            'valid' => empty($conflicts),
            'new' => $new,
            'updated' => $updated,
            'unchanged' => $unchanged,
            'removed' => $removed,
            'conflicts' => $conflicts,
            'warnings' => $warnings,
            'candidate_rules' => $candidateRules,
        ];
    }

    /**
     * Commit candidate rules into a new active version atomically.
     *
     * @param  list<array{category_key: string, category_name: string, dimension_min: int, dimension_max: int, sort_order?: int, status?: string}>  $candidateRules
     */
    public function commit(ReferencePriceImport $import, array $candidateRules, User $actor): ReferencePriceRuleSet
    {
        return DB::transaction(function () use ($import, $candidateRules, $actor) {
            // Lock setting singleton
            $setting = ReferencePriceSetting::query()->lockForUpdate()->first();
            if (! $setting) {
                $setting = ReferencePriceSetting::create(['updated_by' => $actor->id]);
            }

            // Validate no overlaps
            $overlapErrors = $this->validateRuleIntervals($candidateRules);
            if (! empty($overlapErrors)) {
                throw new RuntimeException('Cannot commit rules with overlapping ranges: '.implode('; ', $overlapErrors));
            }

            $nextVersion = (ReferencePriceRuleSet::max('version_no') ?? 0) + 1;

            $newRuleSet = ReferencePriceRuleSet::create([
                'version_no' => $nextVersion,
                'status' => ReferencePriceRuleSet::STATUS_ACTIVE,
                'import_mode' => $import->import_mode,
                'source_file_name' => $import->source_file_name,
                'source_file_hash' => $import->source_file_hash,
                'source_sheet' => $import->source_sheet,
                'created_by' => $actor->id,
                'activated_by' => $actor->id,
                'activated_at' => now(),
            ]);

            foreach ($candidateRules as $index => $ruleData) {
                ReferencePriceRule::create([
                    'rule_set_id' => $newRuleSet->id,
                    'category_key' => $ruleData['category_key'],
                    'category_name' => $ruleData['category_name'],
                    'dimension_min' => (int) $ruleData['dimension_min'],
                    'dimension_max' => (int) $ruleData['dimension_max'],
                    'sort_order' => $ruleData['sort_order'] ?? ($index + 1),
                    'status' => $ruleData['status'] ?? ReferencePriceRule::STATUS_ACTIVE,
                    'created_by' => $actor->id,
                    'updated_by' => $actor->id,
                ]);
            }

            // Archive all other rule sets
            ReferencePriceRuleSet::where('id', '<>', $newRuleSet->id)
                ->where('status', ReferencePriceRuleSet::STATUS_ACTIVE)
                ->update(['status' => ReferencePriceRuleSet::STATUS_ARCHIVED]);

            // Update setting pointer
            $setting->update([
                'active_rule_set_id' => $newRuleSet->id,
                'updated_by' => $actor->id,
            ]);

            // Update import status
            $import->update([
                'status' => ReferencePriceImport::STATUS_CONFIRMED,
                'confirmed_rule_set_id' => $newRuleSet->id,
                'confirmed_at' => now(),
            ]);

            return $newRuleSet->load('rules');
        });
    }

    /**
     * Atomically activate a specific rule set version.
     */
    public function activateVersion(ReferencePriceRuleSet $ruleSet, User $actor): void
    {
        DB::transaction(function () use ($ruleSet, $actor) {
            $setting = ReferencePriceSetting::query()->lockForUpdate()->first();
            if (! $setting) {
                $setting = ReferencePriceSetting::create(['updated_by' => $actor->id]);
            }

            // Validate the rule set has no internal overlaps among active rules
            $activeRules = $ruleSet->activeRules->map(fn ($r) => [
                'category_key' => $r->category_key,
                'dimension_min' => $r->dimension_min,
                'dimension_max' => $r->dimension_max,
                'status' => $r->status,
            ])->all();

            $overlapErrors = $this->validateRuleIntervals($activeRules);
            if (! empty($overlapErrors)) {
                throw new RuntimeException('Cannot activate rule set with overlapping ranges: '.implode('; ', $overlapErrors));
            }

            ReferencePriceRuleSet::where('id', '<>', $ruleSet->id)
                ->where('status', ReferencePriceRuleSet::STATUS_ACTIVE)
                ->update(['status' => ReferencePriceRuleSet::STATUS_ARCHIVED]);

            $ruleSet->update([
                'status' => ReferencePriceRuleSet::STATUS_ACTIVE,
                'activated_by' => $actor->id,
                'activated_at' => now(),
            ]);

            $setting->update([
                'active_rule_set_id' => $ruleSet->id,
                'updated_by' => $actor->id,
            ]);
        });
    }
}
