<?php

namespace App\Http\Requests;

use App\Models\PrItem;
use App\Models\PurchaseRequisition;
use App\Models\QuotationItem;
use App\Support\Materials\DimensionRange;
use App\Support\Materials\MaterialDimensionRules;
use App\Support\Procurement\ProcurementFeatures;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * GEN-03 Mode 1: validates the entered quotation form before Export Form builds
 * a file. Reuses the save path's item rules without the commercial terms. It is
 * validated only after Supplier\QuotationController has authorised the PR, so an
 * unauthorised supplier never learns anything from validation errors.
 */
class ExportQuotationFormRequest extends FormRequest
{
    private const POSITIVE_FIELDS = ['available_thickness', 'available_d_inner', 'available_d_outer', 'available_width', 'offered_weight_per_unit', 'price_per_kg'];

    private PurchaseRequisition $requisition;

    public static function validateFor(Request $request, PurchaseRequisition $requisition): array
    {
        $form = static::createFrom($request);
        $form->setContainer(app())->setRedirector(app('redirect'));
        $form->requisition = $requisition;
        $form->validateResolved();

        return $form->validated();
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $prItemIds = $this->requisition->items->pluck('id')->map(fn ($id) => (int) $id)->all();
        $rules = [
            'items' => ['required', 'array', 'size:'.count($prItemIds)],
            'items.*' => ['array'],
            'items.*.pr_item_id' => ['required', 'integer', 'distinct', Rule::in($prItemIds)],
            'items.*.is_available' => ['sometimes', 'boolean'],
            'items.*.available_qty' => ['nullable', 'integer', 'gt:0'],
            'items.*.available_length' => ['nullable'],
            'items.*.available_length_input' => ['nullable'],
            'items.*.notes' => ['nullable', 'string'],
        ];
        foreach (self::POSITIVE_FIELDS as $field) {
            $rules['items.*.'.$field] = ['nullable', 'numeric', 'gt:0'];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'items.*.pr_item_id.in' => __('purchasing.copy.the_pr_item_id_does_not_belong_to_the_current_pr'),
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $prItems = $this->requisition->items->keyBy(fn (PrItem $item) => (int) $item->id);
            foreach ((array) $this->input('items', []) as $index => $row) {
                $prItem = is_array($row) ? $prItems->get((int) filter_var($row['pr_item_id'] ?? null, FILTER_VALIDATE_INT)) : null;
                if (! $prItem || ! QuotationItem::normalizeAvailabilityState($row['is_available'] ?? true)) {
                    continue;
                }

                $lengthField = array_key_exists('available_length_input', $row) ? 'available_length_input' : 'available_length';
                $length = $row[$lengthField] ?? null;
                if (! ($length === null || (is_string($length) && trim($length) === '')) && DimensionRange::parse($length) === null) {
                    $validator->errors()->add("items.{$index}.{$lengthField}", __('purchasing.copy.length_must_be_a_positive_number_or_a_valid_range_such_as_2300_2500'));
                }

                // The form renders no input for a dimension the shape does not use.
                $relevant = PrItem::relevantDimensionFields($prItem->shape);
                foreach ($prItem->shape === null ? [] : PrItem::DIMENSION_FIELDS as $field) {
                    $column = 'available_'.$field;
                    $value = $field === 'length' ? $length : ($row[$column] ?? null);
                    if ($value !== null && $value !== '' && ! in_array($field, $relevant, true)) {
                        $validator->errors()->add("items.{$index}.".($field === 'length' ? $lengthField : $column), __('purchasing.imports.irrelevant_field_rejected', [
                            'field' => $column,
                            'value' => is_scalar($value) ? (string) $value : '',
                            'shape' => $prItem->shape,
                        ]));
                    }
                }

                $qty = $row['available_qty'] ?? null;
                if (is_numeric($qty) && ! ProcurementFeatures::surplusOffersAllowed() && (int) $qty > $prItem->quantity_value) {
                    $validator->errors()->add("items.{$index}.available_qty", __('supplier.errors.quantity_ceiling', ['quantity' => $prItem->quantity_value]));
                }

                if ($prItem->shape === PrItem::SHAPE_HOLLOW
                    && ! MaterialDimensionRules::hasValidHollowDiameterPair($row['available_d_inner'] ?? null, $row['available_d_outer'] ?? null)) {
                    $validator->errors()->add("items.{$index}.available_d_inner", __('purchasing.copy.inner_diameter_must_be_smaller_than_outer_diameter_for_a_hollow_item'));
                }
            }
        }];
    }
}
