<?php

namespace App\Http\Requests\Supplier;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;

class StoreSupplierShipmentRequest extends FormRequest
{
    public const MTC_RULES = [
        'mtc_files' => 'nullable|array|max:10',
        'mtc_files.*' => 'file|mimes:pdf,jpg,jpeg,png|max:5120',
    ];

    public function authorize(): bool
    {
        return true;
    }

    public static function validateFor(Request $request, bool $allowAction, bool $required = true): array
    {
        self::removeUnselectedRows($request);

        $requiredRule = $required ? 'required' : 'sometimes';
        $rules = [
            'shipment_date' => $requiredRule.'|date',
            'estimated_arrival_date' => $requiredRule.'|date|after_or_equal:shipment_date',
            'notes' => 'nullable|string|max:1000',
            'items' => $requiredRule.'|array|min:1',
            'items.*' => 'required|array',
            'items.*.purchase_order_id' => 'required|integer|exists:purchase_orders,id',
            'items.*.quotation_item_id' => 'required|integer|exists:quotation_items,id',
            'items.*.shipped_qty' => ['required', 'integer', 'min:1'],
            'items.*.actual_weight_kg' => ['required', 'numeric', 'gt:0', 'decimal:0,4'],
        ];
        if ($allowAction) {
            $rules['action'] = 'nullable|string|in:draft,submit';
        }

        $validator = validator($request->all(), $rules);
        $validator->after(function ($validator) use ($request) {
            $seen = [];
            foreach ((array) $request->input('items', []) as $index => $item) {
                if (! is_array($item) || ! isset($item['purchase_order_id'], $item['quotation_item_id'])) {
                    continue;
                }

                $key = (int) $item['purchase_order_id'].':'.(int) $item['quotation_item_id'];
                if (isset($seen[$key])) {
                    $validator->errors()->add("items.{$index}", __('shipments.copy.duplicate_item_allocation_for_the_same_purchase_order_item_is_not_allowed'));
                }
                $seen[$key] = true;
            }
        });

        return $validator->validate();
    }

    public static function removeUnselectedRows(Request $request): void
    {
        $items = $request->input('items');
        if (! is_array($items)) {
            return;
        }

        $items = array_filter($items, static function ($item): bool {
            if (! is_array($item)) {
                return true;
            }

            $qty = $item['shipped_qty'] ?? $item['shipped_quantity'] ?? null;

            return $qty !== null && trim((string) $qty) !== '';
        });

        $request->merge(['items' => $items]);
    }
}
