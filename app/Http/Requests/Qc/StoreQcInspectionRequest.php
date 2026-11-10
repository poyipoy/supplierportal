<?php

namespace App\Http\Requests\Qc;

use App\Models\PrItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

class StoreQcInspectionRequest extends FormRequest
{
    public const ACTUAL_FIELD_BY_DIMENSION = [
        'thickness' => 'actual_thickness',
        'd_inner' => 'actual_d_inner',
        'd_outer' => 'actual_d_outer',
        'width' => 'actual_width',
        'length' => 'actual_length',
        'weight' => 'actual_weight',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @param  Collection<int, PrItem>  $poPrItems
     * @return array<string, mixed>
     */
    public static function validateFor(Request $request, Collection $poPrItems): array
    {
        self::prepareInspectionInput($request, $poPrItems);

        return $request->validate(
            self::rulesFor($request, $poPrItems),
            self::messagesFor(),
        );
    }

    /**
     * @param  Collection<int, PrItem>  $poPrItems
     * @return array<string, mixed>
     */
    public static function rulesFor(Request $request, Collection $poPrItems): array
    {
        $rules = [
            'items' => 'required|array',
            'items.*.pr_item_id' => ['required', Rule::in($poPrItems->keys()->all())],
            'items.*.actual_thickness' => 'nullable|numeric',
            'items.*.actual_d_inner' => 'nullable|numeric',
            'items.*.actual_d_outer' => 'nullable|numeric',
            'items.*.actual_width' => 'nullable|numeric',
            'items.*.actual_length' => 'nullable|numeric',
            'items.*.actual_weight' => 'nullable|numeric|gt:0|max:999999.9999',
            'items.*.status' => 'required|in:ok,ng',
            'items.*.notes' => 'nullable|string',
            'attachments' => 'nullable|array',
            'attachments.*' => 'nullable|array',
            'attachments.*.*' => 'nullable|file|mimes:jpg,jpeg,png|max:10240',
        ];

        foreach ($request->input('items', []) as $index => $itemData) {
            // QC-01: every dimension of a known shape is measured, for OK and NG alike. The shape is
            // the stored PR item's; a missing or unknown shape keeps the weight-only inspection.
            $shape = PrItem::canonicalShape($poPrItems->get($itemData['pr_item_id'] ?? null)?->shape);
            foreach (PrItem::relevantDimensionFields($shape) as $dimension) {
                $rules["items.{$index}.".self::ACTUAL_FIELD_BY_DIMENSION[$dimension]] = 'required|numeric|gt:0|max:999999.9999';
            }
            if (($itemData['status'] ?? null) === 'ng') {
                $rules["attachments.{$index}"] = 'required|array|min:1';
                $rules["attachments.{$index}.*"] = 'required|file|mimes:jpg,jpeg,png|max:10240';
            }
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public static function messagesFor(): array
    {
        return [
            'items.*.pr_item_id.in' => __('qc.copy.the_inspected_material_does_not_match_this_po'),
            'attachments.*.required' => __('qc.copy.evidence_photos_are_required_for_every_ng_item'),
            'attachments.*.min' => __('qc.copy.evidence_photos_are_required_for_every_ng_item'),
            'attachments.*.*.required' => __('qc.copy.evidence_photos_are_required_for_every_ng_item'),
            'attachments.*.*.mimes' => __('qc.copy.ng_evidence_photos_must_be_jpg_jpeg_or_png_files'),
            'attachments.*.*.max' => __('qc.copy.each_ng_evidence_photo_must_not_exceed_10mb'),
        ];
    }

    /**
     * @param  Collection<int, PrItem>  $poPrItems
     */
    public static function prepareInspectionInput(Request $request, Collection $poPrItems): void
    {
        $items = $request->input('items');

        if (! is_array($items)) {
            return;
        }

        $prepared = [];
        foreach ($items as $index => $itemData) {
            if (! is_array($itemData)) {
                continue;
            }

            $prItem = $poPrItems->get((int) ($itemData['pr_item_id'] ?? 0));
            $prepared[$index] = array_merge(
                $itemData,
                self::sanitizeActualMeasurements($itemData, $prItem)
            );
        }

        $request->merge(['items' => $prepared]);
    }

    /**
     * @param  array<string, mixed>  $itemData
     * @return array<string, mixed>
     */
    public static function sanitizeActualMeasurements(array $itemData, ?PrItem $prItem): array
    {
        $relevantDimensions = $prItem
            ? PrItem::relevantDimensionFields(PrItem::canonicalShape($prItem->shape))
            : [];
        $relevantDimensions[] = 'weight';

        $measurements = [];
        foreach (self::ACTUAL_FIELD_BY_DIMENSION as $dimension => $field) {
            $measurements[$field] = in_array($dimension, $relevantDimensions, true)
                ? ($itemData[$field] ?? null)
                : null;
        }

        return $measurements;
    }
}
