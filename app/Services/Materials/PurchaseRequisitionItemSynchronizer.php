<?php

namespace App\Services\Materials;

use App\Models\HsCodeRule;
use App\Models\PrItem;
use App\Models\PurchaseRequisition;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class PurchaseRequisitionItemSynchronizer
{
    public function __construct(private readonly PrItemProcessor $processor) {}

    public function sync(
        PurchaseRequisition $pr,
        array $inputs,
        bool $submitting,
        int $actorId,
    ): Collection {
        $existing = $pr->items()->withCount(['quotationItems', 'qcItems'])->get()->keyBy('id');
        $activeRules = HsCodeRule::query()->active()->get();
        $processed = collect();
        $seenIds = [];
        $errors = [];

        foreach (array_values($inputs) as $index => $input) {
            $input = is_array($input) ? $input : [];
            $itemId = isset($input['id']) && $input['id'] !== '' ? (int) $input['id'] : null;
            $current = $itemId !== null ? $existing->get($itemId) : null;

            if ($itemId !== null && $current === null) {
                $errors["items.{$index}.id"] = 'The selected item does not belong to this requisition.';

                continue;
            }
            if ($itemId !== null && in_array($itemId, $seenIds, true)) {
                $errors["items.{$index}.id"] = 'The same requisition item cannot be submitted twice.';

                continue;
            }
            if ($itemId !== null) {
                $seenIds[] = $itemId;
            }

            $result = $this->processor->process($input, $submitting, $actorId, $current, $activeRules);
            foreach ($result->errors as $field => $message) {
                $errors["items.{$index}.{$field}"] = $message;
            }

            $processed->push([
                'existing' => $current,
                'data' => $result->data,
            ]);
        }

        $omitted = $existing->except($seenIds);
        foreach ($omitted as $item) {
            if ($item->quotation_items_count > 0 || $item->qc_items_count > 0) {
                $errors['items'] = 'A material already referenced by a quotation or QC record cannot be removed.';
                break;
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $saved = collect();
        foreach ($processed as $entry) {
            /** @var PrItem|null $item */
            $item = $entry['existing'];
            if ($item === null) {
                $item = $pr->items()->create($entry['data']);
            } else {
                $item->update($entry['data']);
            }
            $saved->push($item->fresh());
        }

        foreach ($omitted as $item) {
            $item->delete();
        }

        return $saved;
    }

    public function reprocessForSubmission(PurchaseRequisition $pr, int $actorId): Collection
    {
        $inputs = $pr->items()->get()->map(function (PrItem $item) {
            $input = $item->only([
                'material_master_id',
                'quantity',
                'shape',
                'thickness',
                'd_inner',
                'd_outer',
                'width',
                'length',
                'remark',
            ]);
            $input['id'] = $item->id;
            $input['hs_code'] = $item->hs_code;
            $input['hs_code_manual_override'] = $item->hs_code_source === PrItem::HS_SOURCE_MANUAL;
            $input['weight_needed'] = $item->weight_needed;
            $input['weight_manual_override'] = $item->weight_calculation_status === PrItem::WEIGHT_STATUS_MANUAL;

            return $input;
        })->all();

        if ($inputs === []) {
            throw ValidationException::withMessages([
                'items' => 'At least one material is required before submitting.',
            ]);
        }

        return $this->sync($pr, $inputs, true, $actorId);
    }
}
