<?php

namespace App\Imports;

use App\Models\HsCodeRule;
use App\Models\PrItem;
use App\Services\Materials\MaterialResolver;
use App\Services\Materials\PrItemProcessor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class PrItemsImport extends AbstractPreviewImport implements SkipsEmptyRows, ToCollection, WithEvents, WithHeadingRow, WithMultipleSheets
{
    private const HEADINGS = [
        'material_name',
        'shape',
        'quantity',
        'thickness',
        'd_inner',
        'd_outer',
        'width',
        'length',
        'remark',
        'hs_code',
        'weight_needed',
    ];

    private const REQUIRED_HEADINGS = ['material_name', 'quantity'];

    public function __construct(
        private readonly MaterialResolver $materials,
        private readonly PrItemProcessor $processor,
    ) {}

    public function collection(Collection $collection): void
    {
        if (! $this->validateCollectionContract($collection, self::REQUIRED_HEADINGS, self::HEADINGS)) {
            return;
        }

        $materialIndex = $this->materials->activeIndex();
        $rules = HsCodeRule::query()->active()->get();

        foreach ($collection as $index => $row) {
            $rowNumber = $index + 2;
            $raw = $row->toArray();
            $data = [
                'material_name' => trim((string) ($raw['material_name'] ?? '')),
                'shape' => self::nullableText($raw['shape'] ?? null),
                'quantity' => self::nullableNumber($raw['quantity'] ?? null),
                'thickness' => self::nullableNumber($raw['thickness'] ?? null),
                'd_inner' => self::nullableNumber($raw['d_inner'] ?? null),
                'd_outer' => self::nullableNumber($raw['d_outer'] ?? null),
                'width' => self::nullableNumber($raw['width'] ?? null),
                'length' => self::nullableNumber($raw['length'] ?? null),
                'remark' => self::nullableText($raw['remark'] ?? null),
                'legacy_hs_code' => self::nullableText($raw['hs_code'] ?? null),
                'legacy_weight_needed' => self::nullableNumber($raw['weight_needed'] ?? null),
            ];

            $rowErrors = [];
            foreach ($this->formulaColumns($raw, self::HEADINGS) as $formulaColumn) {
                $rowErrors[] = [
                    'column' => $formulaColumn,
                    'message' => 'Excel formulas are not allowed in imported data.',
                ];
            }

            $validator = Validator::make($data, [
                'material_name' => ['required', 'string', 'max:255'],
                'shape' => ['nullable', Rule::in(PrItem::SHAPES)],
                'quantity' => ['required', 'integer', 'min:1'],
                'thickness' => ['nullable', 'numeric', 'gt:0'],
                'd_inner' => ['nullable', 'numeric', 'gt:0'],
                'd_outer' => ['nullable', 'numeric', 'gt:0'],
                'width' => ['nullable', 'numeric', 'gt:0'],
                'length' => ['nullable', 'numeric', 'gt:0'],
                'remark' => ['nullable', 'string', 'max:2000'],
                'legacy_hs_code' => ['nullable', 'string', 'max:100'],
                'legacy_weight_needed' => ['nullable', 'numeric', 'min:0'],
            ]);

            foreach ($validator->errors()->messages() as $column => $messages) {
                $column = str_replace('legacy_', '', $column);
                foreach ($messages as $message) {
                    $rowErrors[] = compact('column', 'message');
                }
            }

            $material = $this->materials->resolveExact($data['material_name'], $materialIndex);
            if ($material === null && ! $validator->errors()->has('material_name')) {
                $rowErrors[] = [
                    'column' => 'material_name',
                    'message' => 'Material was not found by exact master code or alias.',
                ];
            }

            if ($rowErrors !== []) {
                $this->rejectRow($rowNumber, $rowErrors);

                continue;
            }

            $input = [
                'material_master_id' => $material->id,
                'quantity' => (int) $data['quantity'],
                'shape' => $data['shape'],
                'thickness' => $data['thickness'],
                'd_inner' => $data['d_inner'],
                'd_outer' => $data['d_outer'],
                'width' => $data['width'],
                'length' => $data['length'],
                'remark' => $data['remark'],
            ];
            $result = $this->processor->process($input, false, null, null, $rules, $material);

            if (! $result->isValid()) {
                foreach ($result->errors as $column => $message) {
                    $rowErrors[] = compact('column', 'message');
                }
                $this->rejectRow($rowNumber, $rowErrors);

                continue;
            }

            foreach (PrItem::DIMENSION_FIELDS as $field) {
                if ($data[$field] !== null && $result->data[$field] === null) {
                    $this->addWarning(
                        $rowNumber,
                        $field,
                        "The {$field} value was ignored because it is not relevant to shape {$result->data['shape']}."
                    );
                }
            }

            if ($result->weight->status !== 'calculated') {
                $this->addWarning($rowNumber, 'weight_needed', $result->weight->message);
            }
            if ($result->hsCode->status !== 'matched') {
                $this->addWarning($rowNumber, 'hs_code', $result->hsCode->message);
            }

            $this->warnAboutLegacyValues($rowNumber, $data, $result->data);
            $processed = $result->data;
            $processed['manual_hs_code'] = null;
            $this->rows[] = $processed;
        }
    }

    private function warnAboutLegacyValues(int $row, array $input, array $calculated): void
    {
        if ($input['legacy_hs_code'] !== null) {
            $canonical = $this->processor->canonicalHsCode($input['legacy_hs_code']);
            if ($canonical !== $calculated['hs_code']) {
                $this->addWarning(
                    $row,
                    'hs_code',
                    "Supplied HS Code '{$input['legacy_hs_code']}' was ignored; server result is '"
                    .($calculated['hs_code'] ?? 'unresolved')."'."
                );
            }
        }

        if ($input['legacy_weight_needed'] !== null) {
            $supplied = (float) $input['legacy_weight_needed'];
            $server = (float) $calculated['weight_needed'];
            if (abs($supplied - $server) > 0.0001) {
                $this->addWarning(
                    $row,
                    'weight_needed',
                    "Supplied weight '{$supplied}' was ignored; server result is '{$server}'."
                );
            }
        }
    }

    private function rejectRow(int $row, array $errors): void
    {
        $this->markInvalidRow();
        foreach ($errors as $error) {
            $this->addRowError($row, $error['column'], $error['message']);
        }
    }
}
