<?php

namespace App\Http\Requests\Supplier;

use App\Models\Shipment;
use App\Support\Procurement\PreshipmentValidation;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class UpdateShipmentPreshipmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $id = $this->route('id');
        $shipment = $id instanceof Shipment ? $id : Shipment::findOrFail($id);

        return $this->user()?->can('updatePreshipment', $shipment) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $raw = $this->input('preshipment');
        if (is_array($raw)) {
            $this->merge([
                'preshipment' => PreshipmentValidation::normalizeInput($raw),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $id = $this->route('id');
        $shipment = $id instanceof Shipment ? $id : Shipment::find($id);
        $isSubmitted = $shipment instanceof Shipment && $shipment->status === Shipment::STATUS_SUBMITTED;

        return PreshipmentValidation::shipmentRules(
            requireComplete: $isSubmitted,
            supplierId: (int) ($this->user()?->id ?? 0),
            allowMasterControl: ! $isSubmitted,
        );
    }

    public function withValidator(Validator $validator): void
    {
        $id = $this->route('id');
        $shipment = $id instanceof Shipment ? $id : Shipment::find($id);
        if ($shipment instanceof Shipment && $shipment->status === Shipment::STATUS_SUBMITTED) {
            return;
        }

        $validator->after(function (Validator $validator): void {
            if (is_array($this->input('preshipment'))) {
                PreshipmentValidation::validateMasterLocationActionOnDraft(
                    $validator,
                    $this->input('preshipment'),
                    (int) ($this->user()?->id ?? 0)
                );
            }
        });
    }
}
