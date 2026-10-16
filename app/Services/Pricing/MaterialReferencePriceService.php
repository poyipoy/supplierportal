<?php

namespace App\Services\Pricing;

use App\Models\MaterialMaster;
use App\Models\MaterialReferencePrice;
use App\Models\User;
use App\Support\BusinessTime;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Write side of the Master Reference Price (§12.2). Every write locks the
 * material_masters row first (serialization point), then checks overlap
 * against active rows of the same material + shape + currency. Prices are
 * never edited or deleted: supersede closes the current version the day
 * before the new one starts and inserts the next version; void keeps the row
 * with a reason.
 */
class MaterialReferencePriceService
{
    /**
     * @param  array{material_master_id: int, shape: string, dimension_min: string|float, dimension_max: string|float, currency: string, price_per_kg: string|float, valid_from: string, valid_until?: ?string, notes?: ?string}  $data
     */
    public function create(array $data, User $actor): MaterialReferencePrice
    {
        return DB::transaction(function () use ($data, $actor): MaterialReferencePrice {
            MaterialMaster::query()->whereKey($data['material_master_id'])->lockForUpdate()->firstOrFail();
            $this->assertWholeDimensions($data);
            $attributes = $this->attributes($data);
            $this->assertNoOverlap($attributes);

            return MaterialReferencePrice::create([
                ...$attributes,
                'status' => MaterialReferencePrice::STATUS_ACTIVE,
                'version_no' => 1,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
        });
    }

    /**
     * Next version of the same key (material, shape, currency, dimension
     * range) from $data['valid_from']; the current version closes the day before.
     *
     * @param  array{price_per_kg: string|float, valid_from: string, valid_until?: ?string, notes?: ?string}  $data
     */
    public function supersede(MaterialReferencePrice $current, array $data, User $actor): MaterialReferencePrice
    {
        return DB::transaction(function () use ($current, $data, $actor): MaterialReferencePrice {
            MaterialMaster::query()->whereKey($current->material_master_id)->lockForUpdate()->firstOrFail();
            $locked = MaterialReferencePrice::query()->whereKey($current->id)->lockForUpdate()->firstOrFail();
            $from = BusinessTime::parseDate($data['valid_from'])->toDateString();

            if ($locked->status !== MaterialReferencePrice::STATUS_ACTIVE) {
                throw ValidationException::withMessages(['reference_price' => __('materials.reference_prices.not_active')]);
            }
            if ($from <= $locked->valid_from->toDateString()
                || ($locked->valid_until !== null && $from > $locked->valid_until->copy()->addDay()->toDateString())) {
                throw ValidationException::withMessages(['valid_from' => __('materials.reference_prices.supersede_date_invalid')]);
            }

            $locked->forceFill([
                'valid_until' => BusinessTime::parseDate($from)->subDay()->toDateString(),
                'updated_by' => $actor->id,
            ])->save();

            $attributes = $this->attributes([
                'material_master_id' => $locked->material_master_id,
                'shape' => $locked->shape,
                'dimension_min' => (string) $locked->dimension_min,
                'dimension_max' => (string) $locked->dimension_max,
                'currency' => $locked->currency,
                'price_per_kg' => $data['price_per_kg'],
                'valid_from' => $from,
                'valid_until' => $data['valid_until'] ?? null,
            ]);
            $this->assertNoOverlap($attributes);

            return MaterialReferencePrice::create([
                ...$attributes,
                'status' => MaterialReferencePrice::STATUS_ACTIVE,
                'supersedes_id' => $locked->id,
                'version_no' => $locked->version_no + 1,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
        });
    }

    public function void(MaterialReferencePrice $price, string $reason, User $actor): MaterialReferencePrice
    {
        return DB::transaction(function () use ($price, $reason, $actor): MaterialReferencePrice {
            MaterialMaster::query()->whereKey($price->material_master_id)->lockForUpdate()->firstOrFail();
            $locked = MaterialReferencePrice::query()->whereKey($price->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== MaterialReferencePrice::STATUS_ACTIVE) {
                throw ValidationException::withMessages(['reference_price' => __('materials.reference_prices.not_active')]);
            }

            $locked->forceFill([
                'status' => MaterialReferencePrice::STATUS_VOID,
                'voided_by' => $actor->id,
                'voided_at' => now(),
                'void_reason' => $reason,
                'updated_by' => $actor->id,
            ])->save();

            return $locked;
        });
    }

    public function updateNotes(MaterialReferencePrice $price, ?string $notes, User $actor): MaterialReferencePrice
    {
        $price->forceFill(['notes' => $notes, 'updated_by' => $actor->id])->save();

        return $price;
    }

    /**
     * New bands use whole-number bounds; the resolver matches fractional offers
     * on their ceiling. Supersede copies the existing bounds and skips this.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertWholeDimensions(array $data): void
    {
        foreach (['dimension_min', 'dimension_max'] as $key) {
            if (! preg_match('/^\d+(\.0+)?$/', (string) ($data[$key] ?? ''))) {
                throw ValidationException::withMessages([$key => __('materials.reference_prices.dimension_whole_number')]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        $shape = (string) $data['shape'];
        $field = MaterialReferencePrice::DIMENSION_FIELD_BY_SHAPE[$shape] ?? null;
        $min = (string) $data['dimension_min'];
        $max = (string) $data['dimension_max'];
        $price = (string) $data['price_per_kg'];
        $from = BusinessTime::parseDate($data['valid_from'])->toDateString();
        $until = empty($data['valid_until']) ? null : BusinessTime::parseDate($data['valid_until'])->toDateString();

        $errors = [];
        if ($field === null) {
            $errors['shape'] = __('materials.reference_prices.shape_invalid');
        }
        if (! is_numeric($min) || ! is_numeric($max) || bccomp($min, '0', 4) <= 0 || bccomp($max, $min, 4) < 0) {
            $errors['dimension_max'] = __('materials.reference_prices.dimension_range_invalid');
        }
        if (! is_numeric($price) || bccomp($price, '0', 4) <= 0) {
            $errors['price_per_kg'] = __('materials.reference_prices.price_invalid');
        }
        if ($until !== null && $until < $from) {
            $errors['valid_until'] = __('materials.reference_prices.validity_invalid');
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'material_master_id' => (int) $data['material_master_id'],
            'shape' => $shape,
            'dimension_field' => $field,
            'dimension_min' => $min,
            'dimension_max' => $max,
            'currency' => strtoupper((string) $data['currency']),
            'price_per_kg' => $price,
            'valid_from' => $from,
            'valid_until' => $until,
        ];
    }

    /**
     * Dimension overlap a.min <= b.max AND b.min <= a.max, and date overlap
     * with an open end treated as 9999-12-31, among active rows of the same key.
     */
    private function assertNoOverlap(array $a): void
    {
        $until = $a['valid_until'] ?? '9999-12-31';
        $conflict = MaterialReferencePrice::query()
            ->where('material_master_id', $a['material_master_id'])
            ->where('shape', $a['shape'])
            ->where('currency', $a['currency'])
            ->where('status', MaterialReferencePrice::STATUS_ACTIVE)
            ->where('dimension_min', '<=', $a['dimension_max'])
            ->where('dimension_max', '>=', $a['dimension_min'])
            ->where('valid_from', '<=', $until)
            ->whereRaw("COALESCE(valid_until, '9999-12-31') >= ?", [$a['valid_from']])
            ->first();

        if ($conflict !== null) {
            throw ValidationException::withMessages(['dimension_min' => __('materials.reference_prices.overlap', [
                'min' => (string) $conflict->dimension_min,
                'max' => (string) $conflict->dimension_max,
                'from' => $conflict->valid_from->toDateString(),
                'until' => $conflict->valid_until?->toDateString() ?? '-',
            ])]);
        }
    }
}
