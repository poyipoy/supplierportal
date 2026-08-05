<?php

namespace App\Imports;

use App\Models\PrItem;
use App\Models\QuotationItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class QuotationItemsImport extends AbstractPreviewImport implements ToCollection, WithHeadingRow, SkipsEmptyRows, WithMultipleSheets, WithEvents
{
    private const HEADINGS = [
        'pr_item_id',
        'material_name',
        'requested_dimension',
        'price_per_kg',
        'available_qty',
        'available_thickness',
        'available_d_inner',
        'available_d_outer',
        'available_width',
        'available_length',
        'notes',
    ];

    private const REQUIRED_HEADINGS = [
        'pr_item_id',
        'price_per_kg',
    ];

    private Collection $prItems;

    /** @var array<int, true> */
    private array $seenPrItemIds = [];

    public function __construct(Collection $prItems)
    {
        $this->prItems = $prItems->keyBy(fn (PrItem $item) => (int) $item->id);
    }

    public function collection(Collection $collection): void
    {
        if (! $this->validateCollectionContract($collection, self::REQUIRED_HEADINGS, self::HEADINGS)) {
            return;
        }

        $validIds = $this->prItems->keys()->map(fn ($id) => (int) $id)->all();

        foreach ($collection as $index => $row) {
            $rowNumber = $index + 2;
            $raw = $row->toArray();
            $data = [
                'pr_item_id' => self::nullableNumber($raw['pr_item_id'] ?? null),
                'price_per_kg' => self::nullableNumber($raw['price_per_kg'] ?? null),
                'available_qty' => self::nullableNumber($raw['available_qty'] ?? null),
                'available_thickness' => self::nullableNumber($raw['available_thickness'] ?? null),
                'available_d_inner' => self::nullableNumber($raw['available_d_inner'] ?? null),
                'available_d_outer' => self::nullableNumber($raw['available_d_outer'] ?? null),
                'available_width' => self::nullableNumber($raw['available_width'] ?? null),
                'available_length' => self::nullableNumber($raw['available_length'] ?? null),
                'notes' => self::nullableText($raw['notes'] ?? null),
            ];

            $rowErrors = [];
            foreach ($this->formulaColumns($raw, self::HEADINGS) as $formulaColumn) {
                $rowErrors[] = [
                    'column' => $formulaColumn,
                    'message' => 'Excel formulas are not allowed in imported data.',
                ];
            }

            $validator = Validator::make($data, [
                'pr_item_id' => ['required', 'integer', Rule::in($validIds)],
                'price_per_kg' => ['required', 'numeric', 'min:0.01'],
                'available_qty' => ['nullable', 'integer', 'min:1'],
                'available_thickness' => ['nullable', 'numeric', 'min:0'],
                'available_d_inner' => ['nullable', 'numeric', 'min:0'],
                'available_d_outer' => ['nullable', 'numeric', 'min:0'],
                'available_width' => ['nullable', 'numeric', 'min:0'],
                'available_length' => ['nullable', 'numeric', 'min:0'],
                'notes' => ['nullable', 'string'],
            ], [
                'pr_item_id.in' => 'The pr_item_id does not belong to the current PR.',
            ]);

            foreach ($validator->errors()->messages() as $column => $messages) {
                foreach ($messages as $message) {
                    $rowErrors[] = compact('column', 'message');
                }
            }

            $prItemId = filter_var($data['pr_item_id'], FILTER_VALIDATE_INT);
            if ($prItemId !== false && isset($this->seenPrItemIds[$prItemId])) {
                $rowErrors[] = [
                    'column' => 'pr_item_id',
                    'message' => 'The pr_item_id is duplicate within this spreadsheet.',
                ];
            }

            if ($rowErrors !== []) {
                $this->markInvalidRow();
                foreach ($rowErrors as $error) {
                    $this->addRowError($rowNumber, $error['column'], $error['message']);
                }

                continue;
            }

            $prItemId = (int) $data['pr_item_id'];
            $this->seenPrItemIds[$prItemId] = true;
            /** @var PrItem $prItem */
            $prItem = $this->prItems->get($prItemId);
            $availability = QuotationItem::sanitizeAvailabilityData($data, $prItem);
            $availability['available_qty'] = $availability['available_qty'] === null
                ? null
                : (int) $availability['available_qty'];

            foreach (PrItem::DIMENSION_FIELDS as $field) {
                $availabilityField = 'available_'.$field;
                if ($availability[$availabilityField] !== null) {
                    $availability[$availabilityField] = (float) $availability[$availabilityField];
                } elseif ($data[$availabilityField] !== null) {
                    $this->addWarning(
                        $rowNumber,
                        $availabilityField,
                        "The {$availabilityField} value was ignored because it is not relevant to the requested shape {$prItem->shape}."
                    );
                }
            }

            $this->rows[] = [
                'pr_item_id' => $prItemId,
                'price_per_kg' => (float) $data['price_per_kg'],
                ...$availability,
                'notes' => $data['notes'],
            ];
        }
    }
}
