<?php

namespace App\Imports;

use App\Models\PrItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class PrItemsImport extends AbstractPreviewImport implements ToCollection, WithHeadingRow, SkipsEmptyRows, WithMultipleSheets, WithEvents
{
    private const HEADINGS = [
        'material_name',
        'hs_code',
        'shape',
        'quantity',
        'thickness',
        'd_inner',
        'd_outer',
        'width',
        'length',
        'weight_needed',
        'remark',
    ];

    private const REQUIRED_HEADINGS = [
        'material_name',
        'hs_code',
        'quantity',
        'weight_needed',
    ];

    public function collection(Collection $collection): void
    {
        if (! $this->validateCollectionContract($collection, self::REQUIRED_HEADINGS, self::HEADINGS)) {
            return;
        }

        foreach ($collection as $index => $row) {
            $rowNumber = $index + 2;
            $raw = $row->toArray();
            $data = [
                'material_name' => trim((string) ($raw['material_name'] ?? '')),
                'hs_code' => trim((string) ($raw['hs_code'] ?? '')),
                'shape' => self::nullableText($raw['shape'] ?? null),
                'quantity' => self::nullableNumber($raw['quantity'] ?? null),
                'thickness' => self::nullableNumber($raw['thickness'] ?? null),
                'd_inner' => self::nullableNumber($raw['d_inner'] ?? null),
                'd_outer' => self::nullableNumber($raw['d_outer'] ?? null),
                'width' => self::nullableNumber($raw['width'] ?? null),
                'length' => self::nullableNumber($raw['length'] ?? null),
                'weight_needed' => self::nullableNumber($raw['weight_needed'] ?? null),
                'remark' => self::nullableText($raw['remark'] ?? null),
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
                'hs_code' => ['required', 'string', 'max:100'],
                'shape' => ['nullable', Rule::in(PrItem::SHAPES)],
                'quantity' => ['required', 'integer', 'min:1'],
                'thickness' => ['nullable', 'numeric', 'min:0'],
                'd_inner' => ['nullable', 'numeric', 'min:0'],
                'd_outer' => ['nullable', 'numeric', 'min:0'],
                'width' => ['nullable', 'numeric', 'min:0'],
                'length' => ['nullable', 'numeric', 'min:0'],
                'weight_needed' => ['required', 'numeric', 'min:0.01'],
                'remark' => ['nullable', 'string', 'max:2000'],
            ]);

            foreach ($validator->errors()->messages() as $column => $messages) {
                foreach ($messages as $message) {
                    $rowErrors[] = compact('column', 'message');
                }
            }

            if ($rowErrors !== []) {
                $this->markInvalidRow();
                foreach ($rowErrors as $error) {
                    $this->addRowError($rowNumber, $error['column'], $error['message']);
                }

                continue;
            }

            $sanitized = PrItem::sanitizeMaterialData($data);
            $sanitized['quantity'] = (int) $sanitized['quantity'];
            $sanitized['weight_needed'] = (float) $sanitized['weight_needed'];

            foreach (PrItem::DIMENSION_FIELDS as $field) {
                if ($sanitized[$field] !== null) {
                    $sanitized[$field] = (float) $sanitized[$field];
                } elseif ($data[$field] !== null) {
                    $this->addWarning(
                        $rowNumber,
                        $field,
                        "The {$field} value was ignored because it is not relevant to shape {$sanitized['shape']}."
                    );
                }
            }

            $this->rows[] = $sanitized;
        }
    }
}
