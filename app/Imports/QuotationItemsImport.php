<?php

namespace App\Imports;

use App\Models\PrItem;
use App\Models\QuotationItem;
use App\Support\Materials\DimensionRange;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class QuotationItemsImport extends AbstractPreviewImport implements SkipsEmptyRows, ToCollection, WithEvents, WithHeadingRow, WithMultipleSheets
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
        'availability',
        'offered_weight_per_unit',
        'price_kg',
        // Human-facing templates commonly normalize "Offer Weight/Kg" to
        // offer_weight_kg; accept the aliases without changing output keys.
        'offer_weight_kg',
        'offer_weight_per_kg',
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
        // Accept the headings from the revised human-readable template while
        // preserving the existing snake_case contract internally.
        $collection = $collection->map(function ($row) {
            $values = $row->toArray();
            if (! array_key_exists('price_per_kg', $values) && array_key_exists('price_kg', $values)) {
                $values['price_per_kg'] = $values['price_kg'];
            }
            if (! array_key_exists('offered_weight_per_unit', $values)) {
                $values['offered_weight_per_unit'] = $values['offer_weight_kg']
                    ?? ($values['offer_weight_per_kg'] ?? null);
            }

            return collect($values);
        });

        if (! $this->validateCollectionContract($collection, self::REQUIRED_HEADINGS, self::HEADINGS)) {
            return;
        }

        $validIds = $this->prItems->keys()->map(fn ($id) => (int) $id)->all();

        foreach ($collection as $index => $row) {
            $rowNumber = $index + 2;
            $raw = $row->toArray();
            $rawAvailability = self::nullableText($raw['availability'] ?? null);
            $availabilityState = $this->parseAvailability($rawAvailability);
            $availabilityProvided = $rawAvailability !== null;
            $weightValue = $raw['offered_weight_per_unit']
                ?? ($raw['offer_weight_kg'] ?? ($raw['offer_weight_per_kg'] ?? null));
            $data = [
                'pr_item_id' => self::nullableNumber($raw['pr_item_id'] ?? null),
                'is_available' => $availabilityState,
                'price_per_kg' => self::nullableNumber($raw['price_per_kg'] ?? null),
                'available_qty' => self::nullableNumber($raw['available_qty'] ?? null),
                'available_thickness' => self::nullableNumber($raw['available_thickness'] ?? null),
                'available_d_inner' => self::nullableNumber($raw['available_d_inner'] ?? null),
                'available_d_outer' => self::nullableNumber($raw['available_d_outer'] ?? null),
                'available_width' => self::nullableNumber($raw['available_width'] ?? null),
                'available_length' => self::nullableNumber($raw['available_length'] ?? null),
                'available_length_input' => self::nullableNumber($raw['available_length'] ?? null),
                'offered_weight_per_unit' => self::nullableNumber($weightValue),
                'notes' => self::nullableText($raw['notes'] ?? null),
            ];

            $rowErrors = [];
            foreach ($this->formulaColumns($raw, self::HEADINGS) as $formulaColumn) {
                $rowErrors[] = [
                    'column' => $formulaColumn,
                    'message' => 'Excel formulas are not allowed in imported data.',
                ];
            }

            if ($availabilityProvided && $availabilityState === null) {
                $rowErrors[] = [
                    'column' => 'availability',
                    'message' => 'Availability must be Available or Not Available.',
                ];
            }

            $validator = Validator::make($data, [
                'pr_item_id' => ['required', 'integer', Rule::in($validIds)],
                'is_available' => ['required', 'boolean'],
                'price_per_kg' => ['nullable', 'numeric'],
                'available_qty' => ['nullable', 'integer'],
                'available_thickness' => ['nullable', 'numeric'],
                'available_d_inner' => ['nullable', 'numeric'],
                'available_d_outer' => ['nullable', 'numeric'],
                'available_width' => ['nullable', 'numeric'],
                // A range is validated by DimensionRange below.
                'available_length_input' => ['nullable'],
                'offered_weight_per_unit' => ['nullable', 'numeric'],
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

            /** @var PrItem|null $prItem */
            $prItem = $prItemId === false ? null : $this->prItems->get((int) $prItemId);
            $length = null;
            $lengthInput = $data['available_length_input'];
            $hasLengthInput = ! ($lengthInput === null || (is_string($lengthInput) && trim($lengthInput) === ''));
            if ($hasLengthInput && $availabilityState !== false) {
                $length = DimensionRange::parse($lengthInput);
                if ($length === null) {
                    $rowErrors[] = [
                        'column' => 'available_length',
                        'message' => 'Length must be a positive number or a valid range such as 2300-2500.',
                    ];
                }
            }

            if ($prItem && $availabilityState !== false) {
                $offeredQty = $data['available_qty'];
                $explicitAvailability = $availabilityProvided;
                if (! $explicitAvailability && ($data['price_per_kg'] === null || $data['price_per_kg'] === '')) {
                    $rowErrors[] = [
                        'column' => 'price_per_kg',
                        'message' => 'The price per kg field is required for a legacy Available row.',
                    ];
                }
                if ($data['price_per_kg'] !== null && (float) $data['price_per_kg'] <= 0) {
                    $rowErrors[] = [
                        'column' => 'price_per_kg',
                        'message' => 'The price per kg field must be greater than zero for an Available row.',
                    ];
                }
                // Every newly imported offer uses the persisted PR quantity
                // as its authoritative ceiling. Existing historical rows are
                // not rewritten by preview/import.
                if ($offeredQty !== null && (int) $offeredQty > $prItem->quantity_value) {
                    $rowErrors[] = [
                        'column' => 'available_qty',
                        'message' => 'The offered quantity cannot exceed the requested quantity of '.$prItem->quantity_value.'.',
                    ];
                }
                if ($offeredQty !== null && (int) $offeredQty < 1) {
                    $rowErrors[] = [
                        'column' => 'available_qty',
                        'message' => 'The offered quantity must be at least 1 for an Available row.',
                    ];
                }
                if ($data['offered_weight_per_unit'] !== null && (float) $data['offered_weight_per_unit'] <= 0) {
                    $rowErrors[] = [
                        'column' => 'offered_weight_per_unit',
                        'message' => 'Offer KG/Unit must be greater than zero for an Available row.',
                    ];
                }
                if ($prItem->shape === PrItem::SHAPE_HOLLOW
                    && is_numeric($data['available_d_inner'] ?? null)
                    && is_numeric($data['available_d_outer'] ?? null)
                    && (float) $data['available_d_inner'] >= (float) $data['available_d_outer']) {
                    $rowErrors[] = [
                        'column' => 'available_d_inner',
                        'message' => 'Inner diameter must be smaller than outer diameter for a Hollow item.',
                    ];
                }
                if ($explicitAvailability && ($data['price_per_kg'] === null || $data['price_per_kg'] === '')) {
                    $rowErrors[] = [
                        'column' => 'price_per_kg',
                        'message' => 'The price per kg field is required for an Available row.',
                    ];
                }
            }

            if ($availabilityState === false) {
                foreach ([
                    'price_per_kg',
                    'available_qty',
                    'available_thickness',
                    'available_d_inner',
                    'available_d_outer',
                    'available_width',
                    'available_length',
                    'offered_weight_per_unit',
                ] as $column) {
                    if (($raw[$column] ?? null) !== null && ($raw[$column] ?? '') !== '') {
                        $this->addWarning($rowNumber, $column, 'The value was ignored because this item is Not Available.');
                    }
                }
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
            $availability = QuotationItem::sanitizeAvailabilityData($data, $prItem);
            $availability['available_qty'] = $availability['available_qty'] === null
                ? null
                : (int) $availability['available_qty'];
            if ($availability['offered_weight_per_unit'] !== null) {
                $availability['offered_weight_per_unit'] = (float) $availability['offered_weight_per_unit'];
                $availability['offered_weight_source'] = QuotationItem::OFFER_WEIGHT_SOURCE_ESTIMATED;
            }

            foreach (PrItem::DIMENSION_FIELDS as $field) {
                $availabilityField = 'available_'.$field;
                if ($availability[$availabilityField] !== null) {
                    $availability[$availabilityField] = (float) $availability[$availabilityField];
                } elseif (($data[$availabilityField] ?? null) !== null) {
                    $this->addWarning(
                        $rowNumber,
                        $availabilityField,
                        "The {$availabilityField} value was ignored because it is not relevant to the requested shape {$prItem->shape}."
                    );
                }
            }

            $this->rows[] = [
                'pr_item_id' => $prItemId,
                'is_available' => $availability['is_available'],
                'availability' => $availability['is_available'] ? 'Available' : 'Not Available',
                'price_per_kg' => $availability['is_available'] && $data['price_per_kg'] !== null
                    ? (float) $data['price_per_kg']
                    : null,
                ...$availability,
                'notes' => $data['notes'],
            ];
        }
    }

    private function parseAvailability(?string $value): ?bool
    {
        if ($value === null) {
            return true;
        }

        $normalized = strtolower(trim($value));
        if (in_array($normalized, ['available', 'yes', 'true', '1'], true)) {
            return true;
        }
        if (in_array($normalized, ['not available', 'unavailable', 'no', 'false', '0'], true)) {
            return false;
        }

        return null;
    }
}
