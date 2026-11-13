<?php

namespace App\Services\Pricing;

use App\Models\ReferencePriceImport;
use App\Models\ReferencePriceRule;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
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
                if (preg_match('/^(\d+)\s*[-–—]\s*(\d+)$/', $label, $m)) {
                    $currentCategoryLabel = "{$m[1]}-{$m[2]}";
                } else {
                    $currentCategoryLabel = $label;
                }
            }

            if ($currentCategoryLabel === null) {
                continue;
            }

            if (! isset($groups[$currentCategoryLabel])) {
                $groups[$currentCategoryLabel] = [
                    'category_key' => $currentCategoryLabel,
                    'category_name' => $currentCategoryLabel,
                    'dimensions' => [],
                ];
            }

            $groups[$currentCategoryLabel]['dimensions'][] = $dim;
            $lastDimension = $dim;
        }

        if (empty($groups)) {
            throw new InvalidArgumentException("No valid dimension rows found in worksheet [{$sheetName}].");
        }

        // 3. Normalize into min/max ranges
        $rules = [];
        $order = 1;
        $lastMax = 0;

        foreach ($groups as $label => $data) {
            $dims = $data['dimensions'];
            sort($dims, SORT_NUMERIC);
            $min = (int) min($dims);
            $max = (int) max($dims);

            if (preg_match('/^(\d+)\s*[-–—]\s*(\d+)$/', $label, $m)) {
                $nominalMin = (int) $m[1];
                $nominalMax = (int) $m[2];
                $min = min($min, $nominalMin);
                $max = max($max, $nominalMax);
            }

            $rules[] = [
                'category_key' => $label,
                'category_name' => $label,
                'dimension_min' => $min,
                'dimension_max' => $max,
                'sort_order' => $order++,
            ];
        }

        // 4. Validate sequence continuity
        usort($rules, fn ($a, $b) => $a['dimension_min'] <=> $b['dimension_min']);
        for ($i = 0; $i < count($rules); $i++) {
            $rules[$i]['sort_order'] = $i + 1;
            if ($i > 0) {
                $prevMax = $rules[$i - 1]['dimension_max'];
                $currMin = $rules[$i]['dimension_min'];
                if ($currMin <= $prevMax) {
                    throw new InvalidArgumentException("Overlapping range detected between [{$rules[$i - 1]['category_key']}] (max {$prevMax}) and [{$rules[$i]['category_key']}] (min {$currMin}).");
                }
                if ($currMin > $prevMax + 1) {
                    throw new InvalidArgumentException("Gap detected between [{$rules[$i - 1]['category_key']}] (max {$prevMax}) and [{$rules[$i]['category_key']}] (min {$currMin}).");
                }
            }
        }

        return $rules;
    }

    /**
     * Validate candidate rule list for interval correctness and overlaps among active rules.
     *
     * @param  list<array{category_key?: string, dimension_min: int, dimension_max: int, status?: string}>  $rules
     * @return list<string> Error messages
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
     * Build preview diff comparing incoming rules against current rules.
     *
     * @param  list<array{category_key: string, category_name: string, dimension_min: int, dimension_max: int, sort_order: int}>  $incomingRules
     * @param  iterable<ReferencePriceRule|array<string, mixed>>|null  $existingRules
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
    public function buildPreview(array $incomingRules, string $importMode, ?iterable $existingRules = null): array
    {
        if ($existingRules === null) {
            $currentRules = ReferencePriceRule::query()->get()->keyBy('category_key')->all();
        } elseif ($existingRules instanceof \Illuminate\Support\Collection) {
            $currentRules = $existingRules->keyBy('category_key')->all();
        } else {
            $currentRules = [];
            foreach ($existingRules as $r) {
                if ($r instanceof ReferencePriceRule) {
                    $currentRules[$r->category_key] = $r;
                } elseif (is_array($r) && isset($r['category_key'])) {
                    $currentRules[$r['category_key']] = (object) $r;
                }
            }
        }

        $new = [];
        $updated = [];
        $unchanged = [];
        $removed = [];
        $conflicts = [];
        $warnings = [];

        $candidateRules = [];

        if ($importMode === ReferencePriceImport::MODE_REPLACE) {
            foreach ($incomingRules as $incoming) {
                $key = $incoming['category_key'];
                if (! isset($currentRules[$key])) {
                    $new[] = $incoming;
                } else {
                    $curr = $currentRules[$key];
                    $currMin = is_array($curr) ? (int) $curr['dimension_min'] : (int) $curr->dimension_min;
                    $currMax = is_array($curr) ? (int) $curr['dimension_max'] : (int) $curr->dimension_max;

                    if ($currMin !== (int) $incoming['dimension_min'] || $currMax !== (int) $incoming['dimension_max']) {
                        $updated[] = [
                            ...$incoming,
                            'old_min' => $currMin,
                            'old_max' => $currMax,
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
                        'category_key' => is_array($curr) ? $curr['category_key'] : $curr->category_key,
                        'category_name' => is_array($curr) ? $curr['category_name'] : $curr->category_name,
                        'dimension_min' => is_array($curr) ? (int) $curr['dimension_min'] : (int) $curr->dimension_min,
                        'dimension_max' => is_array($curr) ? (int) $curr['dimension_max'] : (int) $curr->dimension_max,
                    ];
                }
            }
        } elseif ($importMode === ReferencePriceImport::MODE_MERGE) {
            $merged = [];
            foreach ($currentRules as $key => $curr) {
                $merged[$key] = [
                    'category_key' => is_array($curr) ? $curr['category_key'] : $curr->category_key,
                    'category_name' => is_array($curr) ? $curr['category_name'] : $curr->category_name,
                    'dimension_min' => is_array($curr) ? (int) $curr['dimension_min'] : (int) $curr->dimension_min,
                    'dimension_max' => is_array($curr) ? (int) $curr['dimension_max'] : (int) $curr->dimension_max,
                    'sort_order' => is_array($curr) ? (int) ($curr['sort_order'] ?? 0) : (int) ($curr->sort_order ?? 0),
                    'status' => is_array($curr) ? ($curr['status'] ?? ReferencePriceRule::STATUS_ACTIVE) : ($curr->status ?? ReferencePriceRule::STATUS_ACTIVE),
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
                        'category_key' => is_array($curr) ? $curr['category_key'] : $curr->category_key,
                        'category_name' => is_array($curr) ? $curr['category_name'] : $curr->category_name,
                        'dimension_min' => is_array($curr) ? (int) $curr['dimension_min'] : (int) $curr->dimension_min,
                        'dimension_max' => is_array($curr) ? (int) $curr['dimension_max'] : (int) $curr->dimension_max,
                        'sort_order' => is_array($curr) ? (int) ($curr['sort_order'] ?? 0) : (int) ($curr->sort_order ?? 0),
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
     * Commit candidate rules directly into reference_price_rules atomically.
     *
     * @param  list<array{category_key: string, category_name: string, dimension_min: int, dimension_max: int, sort_order?: int, status?: string}>  $candidateRules
     * @return Collection<int, ReferencePriceRule>
     */
    public function commit(ReferencePriceImport $import, array $candidateRules, User $actor): Collection
    {
        return DB::transaction(function () use ($import, $candidateRules, $actor) {
            // Validate no overlaps
            $overlapErrors = $this->validateRuleIntervals($candidateRules);
            if (! empty($overlapErrors)) {
                throw new RuntimeException('Cannot commit rules with overlapping ranges: '.implode('; ', $overlapErrors));
            }

            $candidateKeys = collect($candidateRules)->pluck('category_key')->all();

            if ($import->import_mode === ReferencePriceImport::MODE_REPLACE) {
                ReferencePriceRule::whereNotIn('category_key', $candidateKeys)->delete();
            }

            foreach ($candidateRules as $index => $ruleData) {
                ReferencePriceRule::updateOrCreate(
                    ['category_key' => $ruleData['category_key']],
                    [
                        'category_name' => $ruleData['category_name'],
                        'dimension_min' => (int) $ruleData['dimension_min'],
                        'dimension_max' => (int) $ruleData['dimension_max'],
                        'sort_order' => $ruleData['sort_order'] ?? ($index + 1),
                        'status' => $ruleData['status'] ?? ReferencePriceRule::STATUS_ACTIVE,
                        'updated_by' => $actor->id,
                        'created_by' => DB::raw('COALESCE(created_by, '.(int) $actor->id.')'),
                    ]
                );
            }

            // Update import status
            $import->update([
                'status' => ReferencePriceImport::STATUS_CONFIRMED,
                'confirmed_at' => now(),
            ]);

            return ReferencePriceRule::query()->orderBy('sort_order')->orderBy('dimension_min')->get();
        });
    }
}
