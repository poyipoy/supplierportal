<?php

namespace App\Http\Requests\Supplier;

use App\Support\Procurement\PreshipmentValidation;
use App\Support\Procurement\ProcurementFeatures;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;

class StoreSupplierShipmentRequest extends FormRequest
{
    public const MTC_RULES = [
        'mtc_files' => 'nullable|array|max:10',
        'mtc_files.*' => 'file|mimes:pdf,jpg,jpeg,png|max:5120',
        'commercial_invoice_file' => 'nullable|file|mimes:pdf,jpg,jpeg,png,xlsx,doc,docx|max:10240',
    ];

    public function authorize(): bool
    {
        return true;
    }

    public static function validateFor(Request $request, bool $allowAction, bool $required = true): array
    {
        self::removeUnselectedRows($request);
        self::normalizePreshipmentPayload($request);

        $requiredRule = $required ? 'required' : 'sometimes';
        $requireCompletePsi = $allowAction
            && $request->input('action') === 'submit'
            && ProcurementFeatures::requirePreshipmentForShipment();
        $supplierId = (int) ($request->user()?->id ?? 0);

        $rules = [
            'shipment_date' => $requiredRule.'|date',
            'estimated_arrival_date' => $requiredRule.'|date|after_or_equal:shipment_date',
            // E6: the Commercial Invoice number is mandatory on the shipment form.
            'invoice_number' => ($required ? 'required' : 'nullable').'|string|max:100',
            // E11: supplier's own source document number.
            'supplier_reference' => 'nullable|string|max:120',
            'notes' => 'nullable|string|max:1000',
            'items' => $requiredRule.'|array|min:1',
            'items.*' => 'required|array',
            'items.*.purchase_order_id' => 'required|integer|exists:purchase_orders,id',
            'items.*.quotation_item_id' => 'required|integer|exists:quotation_items,id',
            'items.*.shipped_qty' => ['required', 'integer', 'min:1'],
            'items.*.actual_weight_kg' => ['required', 'numeric', 'gt:0', 'decimal:0,4'],
            ...self::preshipmentRules($requireCompletePsi, $supplierId),
        ];
        if ($allowAction) {
            $rules['action'] = 'nullable|string|in:draft,submit';
        }

        $validator = validator($request->all(), $rules);
        $validator->after(function ($validator) use ($request, $requireCompletePsi, $supplierId) {
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

            if (! $requireCompletePsi && is_array($request->input('preshipment'))) {
                PreshipmentValidation::validateMasterLocationActionOnDraft(
                    $validator,
                    $request->input('preshipment'),
                    $supplierId
                );
            }
        });

        return $validator->validate();
    }

    /**
     * Normalize `preshipment` on the incoming request before validation runs so
     * "Same as Exporter" copies Block 1 into Block 2 server-side first.
     */
    public static function normalizePreshipmentPayload(Request $request): void
    {
        $raw = $request->input('preshipment');
        if (! is_array($raw)) {
            return;
        }

        $request->merge([
            'preshipment' => PreshipmentValidation::normalizeInput($raw),
        ]);
    }

    /**
     * PSI rules delegated to the single source of truth in PreshipmentValidation.
     *
     * @return array<string, mixed>
     */
    public static function preshipmentRules(bool $requireComplete = false, int $supplierId = 0): array
    {
        return PreshipmentValidation::shipmentRules($requireComplete, $supplierId, true);
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
