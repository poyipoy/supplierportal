<?php

namespace App\Http\Requests\Supplier;

use App\Models\ExchangeRate;
use App\Models\PrItem;
use App\Models\PurchaseRequisition;
use App\Models\QuotationItem;
use App\Services\Procurement\QuotationSubmissionService;
use App\Support\Materials\DimensionRange;
use App\Support\Materials\MaterialDimensionRules;
use App\Support\Procurement\ProcurementFeatures;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class StoreQuotationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validate quotation payload against the resolved PurchaseRequisition.
     *
     * @return array{validated: array<string, mixed>, allUnavailable: bool}
     */
    public static function validateFor(
        Request $request,
        PurchaseRequisition $pr,
        QuotationSubmissionService $submissionService,
    ): array {
        $rawItems = $request->input('items', []);
        $allUnavailable = is_array($rawItems)
            && count($rawItems) > 0
            && collect($rawItems)->every(function ($rawItem) {
                if (! is_array($rawItem)) {
                    return false;
                }
                $availabilityInput = array_key_exists('is_available', $rawItem)
                    ? $rawItem['is_available']
                    : ($rawItem['availability'] ?? true);

                return ! QuotationItem::normalizeAvailabilityState($availabilityInput);
            });

        $requireCommercialTerms = $request->action === 'submitted' && ! $allUnavailable;

        $validator = Validator::make($request->all(), [
            'action' => 'required|in:draft,submitted',
            'currency' => [
                $requireCommercialTerms ? 'required' : 'nullable',
                Rule::in(ExchangeRate::CURRENCIES),
            ],
            'estimated_delivery' => $requireCommercialTerms ? 'required|date' : 'nullable|date',
            'payment_terms' => $requireCommercialTerms ? 'required|string|max:100' : 'nullable|string|max:100',
            'validity_period' => $requireCommercialTerms
                ? 'required|date|after_or_equal:today'
                : 'nullable|date',
            'general_notes' => 'nullable|string',
            'items' => ['required', 'array', 'min:1', 'size:'.$pr->items->count()],
            'items.*.pr_item_id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('pr_items', 'id')->where(fn ($query) => $query->where('pr_id', $pr->id)),
            ],
            'items.*.is_available' => ['sometimes', 'boolean'],
            'items.*.price_per_kg' => 'nullable|numeric',
            'items.*.notes' => 'nullable|string',
            'items.*.available_qty' => 'nullable|integer',
            'items.*.available_thickness' => 'nullable|numeric',
            'items.*.available_d_inner' => 'nullable|numeric',
            'items.*.available_d_outer' => 'nullable|numeric',
            'items.*.available_width' => 'nullable|numeric',
            'items.*.available_length' => 'nullable',
            'items.*.available_length_input' => 'nullable',
            'items.*.offered_weight_per_unit' => 'nullable|numeric',
            'items.*.offered_weight_manual_override' => ['sometimes', 'boolean'],
            'items.*.mtc_file' => ProcurementFeatures::shipmentMtcEnabled() ? 'prohibited' : 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
            'items.*.copy_from_attachment_id' => ProcurementFeatures::shipmentMtcEnabled() ? 'prohibited' : 'nullable|integer',
            'items.*.keep_existing_attachment' => ['sometimes', 'boolean'],
        ], [
            'currency.required' => __('supplier.copy.currency_is_required'),
            'currency.in' => __('supplier.copy.currency_is_invalid'),
            'payment_terms.required' => __('supplier.copy.payment_terms_are_required'),
            'validity_period.required' => __('supplier.copy.quotation_validity_is_required_when_submitting_the_final_quotation'),
            'validity_period.after_or_equal' => __('supplier.copy.quotation_validity_cannot_be_earlier_than_today'),
            'items.*.mtc_file.mimes' => __('supplier.copy.the_mtc_file_must_be_pdf_jpg_jpeg_or_png'),
            'items.*.mtc_file.max' => __('supplier.copy.the_mtc_file_size_must_not_exceed_5mb'),
            'items.*.mtc_file.prohibited' => __('shipments.mtc.quotation_upload_retired'),
            'items.*.copy_from_attachment_id.prohibited' => __('shipments.mtc.quotation_upload_retired'),
        ]);

        $validator->after(function ($validator) use ($request, $pr, $submissionService): void {
            $prItems = $pr->items->keyBy(fn (PrItem $item) => (int) $item->id);
            $rawItems = $request->input('items', []);
            $isSubmitted = $request->input('action') === 'submitted';

            foreach (is_array($rawItems) ? $rawItems : [] as $index => $rawItem) {
                if (! is_array($rawItem)) {
                    continue;
                }

                $prItemId = filter_var($rawItem['pr_item_id'] ?? null, FILTER_VALIDATE_INT);
                /** @var PrItem|null $prItem */
                $prItem = $prItemId === false ? null : $prItems->get((int) $prItemId);
                if (! $prItem) {
                    continue;
                }

                $hasExplicitAvailability = array_key_exists('is_available', $rawItem)
                    || (array_key_exists('availability', $rawItem)
                        && trim((string) ($rawItem['availability'] ?? '')) !== '');
                $legacyPayload = ! $hasExplicitAvailability;
                $availabilityInput = array_key_exists('is_available', $rawItem)
                    ? $rawItem['is_available']
                    : ($rawItem['availability'] ?? true);
                $isAvailable = QuotationItem::normalizeAvailabilityState($availabilityInput);
                $rawAvailability = strtolower(trim((string) ($rawItem['availability'] ?? '')));
                if (array_key_exists('availability', $rawItem)
                    && ! array_key_exists('is_available', $rawItem)
                    && $rawAvailability !== ''
                    && ! in_array($rawAvailability, [
                        'available', 'yes', 'true', '1', 'not available', 'unavailable', 'no', 'false', '0',
                    ], true)) {
                    $validator->errors()->add(
                        "items.{$index}.availability",
                        __('supplier.copy.availability_must_be_available_or_not_available')
                    );
                }
                $price = $rawItem['price_per_kg'] ?? null;

                if (! $isAvailable) {
                    continue;
                }

                if ($price === null || $price === '' || ! is_numeric($price) || (float) $price <= 0) {
                    $validator->errors()->add(
                        "items.{$index}.price_per_kg",
                        $legacyPayload
                            ? __('supplier.copy.the_price_per_kg_field_is_required')
                            : __('supplier.copy.the_price_per_kg_field_must_be_greater_than_zero_for_an_available_item')
                    );
                }

                $offeredQty = $rawItem['available_qty'] ?? null;
                $offeredQty = $offeredQty === null || $offeredQty === '' ? null : (int) $offeredQty;

                if ($offeredQty !== null && $offeredQty < 1) {
                    $validator->errors()->add(
                        "items.{$index}.available_qty",
                        __('supplier.copy.the_offered_quantity_must_be_at_least_1_for_an_available_item')
                    );
                }

                if ($offeredQty !== null
                    && ! ProcurementFeatures::surplusOffersAllowed()
                    && $offeredQty > $prItem->quantity_value) {
                    $validator->errors()->add(
                        "items.{$index}.available_qty",
                        __('supplier.errors.quantity_ceiling', ['quantity' => $prItem->quantity_value])
                    );
                }

                if (! $legacyPayload) {
                    $rawWeight = $rawItem['offered_weight_per_unit'] ?? null;
                    if ($rawWeight !== null && $rawWeight !== '' && (! is_numeric($rawWeight) || (float) $rawWeight <= 0)) {
                        $validator->errors()->add(
                            "items.{$index}.offered_weight_per_unit",
                            __('supplier.copy.offer_kg_unit_must_be_greater_than_zero_for_an_available_item')
                        );
                    }
                    foreach (PrItem::relevantDimensionFields($prItem->shape) as $field) {
                        $value = $rawItem['available_'.$field] ?? null;
                        if ($value !== null && $value !== '' && is_numeric($value) && (float) $value <= 0) {
                            $validator->errors()->add(
                                "items.{$index}.available_{$field}",
                                __('supplier.copy.offered_dimensions_must_be_greater_than_zero_for_an_available_item')
                            );
                        }
                    }
                    if ($prItem->shape === PrItem::SHAPE_HOLLOW
                        && ! MaterialDimensionRules::hasValidHollowDiameterPair($rawItem['available_d_inner'] ?? null, $rawItem['available_d_outer'] ?? null)) {
                        $validator->errors()->add(
                            "items.{$index}.available_d_inner",
                            __('supplier.copy.inner_diameter_must_be_smaller_than_outer_diameter_for_a_hollow_item')
                        );
                    }
                }

                foreach (PrItem::relevantDimensionFields($prItem->shape) as $field) {
                    $value = $rawItem['available_'.$field] ?? null;
                    if ($value !== null && $value !== '' && is_numeric($value) && (float) $value < 0) {
                        $validator->errors()->add(
                            "items.{$index}.available_{$field}",
                            __('supplier.copy.offered_dimensions_cannot_be_negative')
                        );
                    }
                }

                $lengthInput = array_key_exists('available_length_input', $rawItem)
                    ? $rawItem['available_length_input']
                    : ($rawItem['available_length'] ?? null);
                if (($lengthInput === null || $lengthInput === '')
                    && ($rawItem['available_length_min'] ?? null) !== null
                    && ($rawItem['available_length_max'] ?? null) !== null) {
                    $lengthInput = (string) $rawItem['available_length_min'].'-'.(string) $rawItem['available_length_max'];
                }
                $hasLengthInput = ! ($lengthInput === null || (is_string($lengthInput) && trim($lengthInput) === ''));
                $length = DimensionRange::parse($lengthInput);
                if ($hasLengthInput && $length === null) {
                    $validator->errors()->add(
                        "items.{$index}.available_length_input",
                        __('supplier.copy.length_must_be_a_positive_number_or_a_valid_range_such_as_2300_2500')
                    );
                }

                $offer = $submissionService->resolveOfferedWeight($prItem, $rawItem, $length, $legacyPayload);
                if ($isSubmitted && ! $legacyPayload) {
                    if ($offeredQty === null) {
                        $validator->errors()->add(
                            "items.{$index}.available_qty",
                            __('supplier.copy.the_offered_quantity_is_required_for_an_available_item_when_submitting_the_final_quotation')
                        );
                    }
                    if ($offer['weight'] === null) {
                        $validator->errors()->add(
                            "items.{$index}.offered_weight_per_unit",
                            $offer['error'] ?? __('supplier.copy.offer_kg_unit_is_required_for_an_available_item_when_submitting_the_final_quotation')
                        );
                    }
                    if ($price === null || $price === '') {
                        $validator->errors()->add(
                            "items.{$index}.price_per_kg",
                            __('supplier.copy.the_price_per_kg_field_is_required_for_an_available_item_when_submitting_the_final_quotation')
                        );
                    }
                }
            }
        });

        return [
            'validated' => $validator->validate(),
            'allUnavailable' => $allUnavailable,
        ];
    }
}
