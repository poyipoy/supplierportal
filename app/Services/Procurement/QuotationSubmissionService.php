<?php

namespace App\Services\Procurement;

use App\Enums\PurchaseRequisitionStatus;
use App\Enums\QuotationStatus;
use App\Models\Attachment;
use App\Models\ExchangeRate;
use App\Models\PrItem;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\User;
use App\Services\FileSecurity\FileAccessGuard;
use App\Services\FileSecurity\FileInspectionService;
use App\Services\Materials\MaterialWeightCalculator;
use App\Services\NotificationService;
use App\Support\Materials\DimensionRange;
use App\Support\NotificationCategory;
use App\Support\Procurement\ProcurementFeatures;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class QuotationSubmissionService
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly MaterialWeightCalculator $weightCalculator,
    ) {}

    /**
     * Persist a draft or submitted quotation with row-level locks, MTC handling, and notifications.
     */
    public function save(
        Request $request,
        PurchaseRequisition $pr,
        int|string $prId,
        array $validated,
        bool $allUnavailable,
    ) {
        $quotation = Quotation::where('pr_id', $prId)
            ->where('supplier_id', auth()->id())
            ->first();

        $supplierCurrency = ! empty($validated['currency'])
            ? $validated['currency']
            : ($quotation?->currency ?? 'USD');

        $wasRevisionRequested = $quotation?->status === Quotation::STATUS_REVISION_REQUESTED;

        if ($quotation && ! $quotation->canBeRevisedBySupplier()) {
            return redirect()->route('supplier.quotations.show', $quotation)
                ->with('error', __('supplier.copy.this_quotation_has_already_been_submitted_and_cannot_be_changed'));
        }

        $newlyCreatedFiles = [];

        try {
            DB::beginTransaction();

            $pr = PurchaseRequisition::whereKey($pr->id)->lockForUpdate()->firstOrFail();
            if (! in_array($pr->status, ['submitted', 'bidding'], true)) {
                throw new \RuntimeException(__('supplier.copy.this_requisition_is_no_longer_available_for_quotation'));
            }
            $lockedPrItems = PrItem::where('pr_id', $pr->id)
                ->orderBy('id')->lockForUpdate()->get();
            $quotation = Quotation::where('pr_id', $pr->id)
                ->where('supplier_id', auth()->id())->lockForUpdate()->first();
            if ($quotation && (! $quotation->canBeRevisedBySupplier()
                || $quotation->isReferencedByAwardOrPurchaseOrder())) {
                throw new \RuntimeException(__('supplier.copy.this_quotation_is_locked_or_used_by_an_award_or_purchase_order_and_cannot_be_changed'));
            }
            $wasRevisionRequested = $quotation?->status === Quotation::STATUS_REVISION_REQUESTED;

            if ($quotation) {
                Gate::authorize('update', $quotation);
            }

            $nextStatus = $request->action === 'submitted'
                ? Quotation::STATUS_SUBMITTED
                : ($wasRevisionRequested ? Quotation::STATUS_REVISION_REQUESTED : Quotation::STATUS_DRAFT);

            $exchangeRateId = $quotation?->currency === $supplierCurrency
                ? $quotation?->exchange_rate_id
                : null;
            if ($request->action === 'submitted') {
                $rate = ExchangeRate::latestRate($supplierCurrency);
                if (! $rate) {
                    if (! $allUnavailable) {
                        DB::rollBack();

                        return back()
                            ->withInput()
                            ->with('error', __('supplier.errors.rate_missing', ['currency' => $supplierCurrency]));
                    }
                    $exchangeRateId = null;
                } else {
                    $exchangeRateId = $rate->id;
                }
            }

            $currentPrItemIds = $lockedPrItems->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->sort()
                ->values()
                ->all();
            $validatedPrItemIds = collect($validated['items'])
                ->pluck('pr_item_id')
                ->map(fn ($id) => (int) $id)
                ->sort()
                ->values()
                ->all();
            if ($currentPrItemIds !== $validatedPrItemIds) {
                throw new \RuntimeException(__('supplier.copy.the_requisition_items_changed_while_the_quotation_was_being_saved_please_reload_and_try_again'));
            }

            $paymentTerms = $allUnavailable ? null : ($validated['payment_terms'] ?? null);
            $estimatedDelivery = $allUnavailable ? null : ($request->filled('estimated_delivery') ? $request->estimated_delivery : null);
            $validityPeriod = $allUnavailable ? null : ($request->filled('validity_period') ? $request->validity_period : null);

            if (! $quotation) {
                $quotation = Quotation::create([
                    'pr_id' => $prId,
                    'supplier_id' => auth()->id(),
                    'currency' => $supplierCurrency,
                    'status' => $nextStatus,
                    'submitted_at' => $request->action === 'submitted' ? now() : null,
                    'exchange_rate_id' => $exchangeRateId,
                    'estimated_delivery' => $estimatedDelivery,
                    'payment_terms' => $paymentTerms,
                    'validity_period' => $validityPeriod,
                    'general_notes' => $request->general_notes,
                ]);
            } else {
                $quotationAttributes = [
                    'currency' => $supplierCurrency,
                    'submitted_at' => $request->action === 'submitted' ? now() : $quotation->submitted_at,
                    'exchange_rate_id' => $exchangeRateId,
                    'estimated_delivery' => $estimatedDelivery,
                    'payment_terms' => $paymentTerms,
                    'validity_period' => $validityPeriod,
                    'general_notes' => $request->general_notes,
                ];
                $nextStatusEnum = QuotationStatus::tryFromWithLegacy($nextStatus);
                if ($quotation->statusEnum() !== $nextStatusEnum) {
                    $quotation = app(QuotationStateEngine::class)->transition(
                        model: $quotation,
                        target: $nextStatusEnum,
                        actor: auth()->user(),
                        metadata: ['attributes' => $quotationAttributes]
                    );
                } else {
                    $quotation->update($quotationAttributes);
                }
            }

            $existingItemAttachments = $quotation ? $quotation->items()
                ->with('attachments')
                ->get()
                ->keyBy('pr_item_id')
                ->map(fn ($item) => $item->attachments) : collect();

            $currentQuotationAttachmentMap = [];
            foreach ($existingItemAttachments as $prAttachments) {
                foreach ($prAttachments as $att) {
                    $currentQuotationAttachmentMap[$att->id] = $att;
                }
            }

            if ($quotation) {
                $quotation->items()->delete();
            }

            $oldFilesToDelete = [];
            $newlyCreatedFiles = [];

            foreach ($validated['items'] as $index => $itemData) {
                /** @var PrItem $prItem */
                $prItem = PrItem::query()
                    ->with('materialMaster')
                    ->whereKey((int) $itemData['pr_item_id'])
                    ->where('pr_id', $pr->id)
                    ->firstOrFail();

                $rawItem = $request->input("items.{$index}", $itemData);
                $rawItem = is_array($rawItem) ? $rawItem : $itemData;
                $hasExplicitAvailability = array_key_exists('is_available', $rawItem)
                    || (array_key_exists('availability', $rawItem)
                        && trim((string) ($rawItem['availability'] ?? '')) !== '');
                $legacyPayload = ! $hasExplicitAvailability;
                $availabilityInput = array_key_exists('is_available', $rawItem)
                    ? $rawItem['is_available']
                    : ($rawItem['availability'] ?? true);
                $isAvailable = QuotationItem::normalizeAvailabilityState($availabilityInput);
                $priceValue = $isAvailable && (($rawItem['price_per_kg'] ?? null) !== null && ($rawItem['price_per_kg'] ?? '') !== '')
                    ? (float) $rawItem['price_per_kg']
                    : null;
                $lengthInput = array_key_exists('available_length_input', $rawItem)
                    ? $rawItem['available_length_input']
                    : ($rawItem['available_length'] ?? null);
                if (($lengthInput === null || $lengthInput === '')
                    && ($rawItem['available_length_min'] ?? null) !== null
                    && ($rawItem['available_length_max'] ?? null) !== null) {
                    $lengthInput = (string) $rawItem['available_length_min'].'-'.(string) $rawItem['available_length_max'];
                }
                $length = DimensionRange::parse($lengthInput);
                $availability = QuotationItem::sanitizeAvailabilityData($rawItem, $prItem);
                $offeredQty = $rawItem['available_qty'] ?? null;
                $offeredQty = $offeredQty === null || $offeredQty === '' ? null : (int) $offeredQty;
                $offer = $isAvailable
                    ? $this->resolveOfferedWeight($prItem, $rawItem, $length, $legacyPayload)
                    : ['weight' => null, 'source' => null, 'error' => null];
                if ($isAvailable
                    && $offeredQty !== null
                    && ! ProcurementFeatures::surplusOffersAllowed()
                    && $offeredQty > $prItem->quantity_value) {
                    throw new \RuntimeException(__('supplier.copy.the_offered_quantity_no_longer_fits_the_current_requested_quantity_please_reload_the_requisition_and'));
                }
                if ($request->action === 'submitted'
                    && $isAvailable
                    && ! $legacyPayload
                    && ($offeredQty === null || $offer['weight'] === null || $priceValue === null)) {
                    throw new \RuntimeException(__('supplier.copy.the_available_offer_changed_while_it_was_being_saved_please_reload_the_requisition_and_complete_the'));
                }
                $availability['offered_weight_per_unit'] = $offer['weight'];
                $availability['offered_weight_source'] = $offer['source'];
                $offerTotalWeight = $isAvailable && $offeredQty !== null && $offer['weight'] !== null
                    ? round($offeredQty * $offer['weight'], 4, PHP_ROUND_HALF_UP)
                    : null;
                $amount = ! $isAvailable
                    ? 0.0
                    : ($legacyPayload
                        ? (QuotationItem::calculateRequestedAmount($prItem, $priceValue) ?? 0.0)
                        : ($offerTotalWeight === null
                            ? 0.0
                            : QuotationItem::calculateOfferAmount($offerTotalWeight, $priceValue)));
                $offer = [
                    ...$availability,
                    'price_per_kg' => $priceValue,
                    'offer_total_weight' => $offerTotalWeight,
                    'amount' => $amount,
                ];
                $offerFields = $offer;
                unset($offerFields['price_per_kg'], $offerFields['amount'], $offerFields['offer_total_weight']);

                $quotationItem = $quotation->items()->create([
                    'pr_item_id' => $prItem->id,
                    'price_per_kg' => $offer['price_per_kg'],
                    'amount' => $offer['amount'],
                    'notes' => $itemData['notes'] ?? null,
                    ...$offerFields,
                ]);

                $mtcFile = $request->file("items.{$index}.mtc_file");
                $copyAttachmentId = filter_var($rawItem['copy_from_attachment_id'] ?? null, FILTER_VALIDATE_INT);
                $copyAttachmentId = $copyAttachmentId === false ? null : $copyAttachmentId;
                $keepExisting = filter_var($rawItem['keep_existing_attachment'] ?? true, FILTER_VALIDATE_BOOLEAN);
                if (ProcurementFeatures::shipmentMtcEnabled()) {
                    $mtcFile = null;
                    $copyAttachmentId = null;
                    $keepExisting = true;
                }

                if ($mtcFile && $mtcFile->isValid()) {
                    $this->storeMtcAttachment($quotationItem, $mtcFile, $newlyCreatedFiles);
                    if ($existingItemAttachments->has($prItem->id)) {
                        foreach ($existingItemAttachments->get($prItem->id) as $oldAttachment) {
                            $oldFilesToDelete[] = $oldAttachment->file_path;
                            $oldAttachment->delete();
                        }
                    }
                } elseif ($copyAttachmentId) {
                    $newAttachment = $this->cloneMtcAttachment($quotationItem, $copyAttachmentId, $currentQuotationAttachmentMap, $newlyCreatedFiles);
                    if ($existingItemAttachments->has($prItem->id)) {
                        foreach ($existingItemAttachments->get($prItem->id) as $oldAttachment) {
                            if (! $newAttachment || $oldAttachment->file_path !== $newAttachment->file_path) {
                                $oldFilesToDelete[] = $oldAttachment->file_path;
                            }
                            $oldAttachment->delete();
                        }
                    }
                } elseif ($keepExisting && $existingItemAttachments->has($prItem->id)) {
                    foreach ($existingItemAttachments->get($prItem->id) as $attachment) {
                        app(FileAccessGuard::class)->assertReadable($attachment);
                        $attachment->update([
                            'attachable_id' => $quotationItem->id,
                        ]);
                    }
                } elseif ($existingItemAttachments->has($prItem->id)) {
                    foreach ($existingItemAttachments->get($prItem->id) as $oldAttachment) {
                        $oldFilesToDelete[] = $oldAttachment->file_path;
                        $oldAttachment->delete();
                    }
                }
            }

            if ($request->action === 'submitted' && $pr->status === 'submitted') {
                app(PurchaseRequisitionStateEngine::class)->transition(
                    model: $pr,
                    target: PurchaseRequisitionStatus::BIDDING,
                    actor: auth()->user()
                );
            }

            if ($request->action === 'submitted') {
                $hasAvailable = $quotation->items()->where('is_available', true)->exists();
                $finalStatusEnum = $hasAvailable ? QuotationStatus::SUBMITTED : QuotationStatus::ALL_UNAVAILABLE;
                if ($quotation->statusEnum() !== $finalStatusEnum) {
                    $quotation = app(QuotationStateEngine::class)->transition(
                        model: $quotation,
                        target: $finalStatusEnum,
                        actor: auth()->user()
                    );
                }
            }

            DB::commit();

            foreach ($oldFilesToDelete as $oldFilePath) {
                if ($oldFilePath && Storage::disk('private')->exists($oldFilePath)) {
                    Storage::disk('private')->delete($oldFilePath);
                }
            }

            if ($request->action === 'submitted') {
                $purchasingUsers = User::where('role', 'purchasing')->where('is_active', true)->get();
                $title = $wasRevisionRequested ? 'supplier.notify.revised_title' : 'supplier.copy.new_quotation_received';
                $message = $wasRevisionRequested
                    ? 'supplier.notify.revised_body'
                    : 'supplier.notify.submitted_body';

                $event = $wasRevisionRequested ? 'quotation.revised' : 'quotation.submitted';
                $submittedKey = $quotation->submitted_at?->format('YmdHis.u') ?? $quotation->updated_at?->format('YmdHis.u');
                $this->notifications->send(
                    $purchasingUsers,
                    $event,
                    "{$event}:{$quotation->id}:{$submittedKey}",
                    $title,
                    $message,
                    route('purchasing.requisitions.show', $pr, absolute: false),
                    'mail-check text-success',
                    [
                        'category' => NotificationCategory::QUOTATION,
                        'quotation_id' => $quotation->id,
                        'pr_id' => $pr->id,
                        'pr_number' => $pr->pr_number,
                    ],
                    ['name' => auth()->user()->name, 'pr_number' => $pr->pr_number],
                );
            }

            $msg = $request->action === 'submitted'
                ? ($wasRevisionRequested ? 'Revised quotation has been resubmitted.' : __('supplier.copy.quotation_successfully_sent'))
                : ($wasRevisionRequested ? __('supplier.copy.revised_quotation_draft_successfully_saved') : __('supplier.copy.draft_quotation_successfully_saved'));

            if ($request->expectsJson()) {
                $request->session()->flash('success', $msg);

                return response()->json(['redirect' => route('supplier.quotations.period', $pr->period_id)]);
            }

            return redirect()->route('supplier.quotations.period', $pr->period_id)->with('success', $msg);
        } catch (ValidationException $e) {
            DB::rollBack();
            foreach ($newlyCreatedFiles as $newFile) {
                Storage::disk('private')->delete($newFile);
            }
            throw $e;
        } catch (\DomainException|\InvalidArgumentException|\RuntimeException $e) {
            DB::rollBack();

            foreach ($newlyCreatedFiles as $newFile) {
                if ($newFile && Storage::disk('private')->exists($newFile)) {
                    Storage::disk('private')->delete($newFile);
                }
            }

            if ($request->expectsJson()) {
                throw ValidationException::withMessages(['items' => $e->getMessage()]);
            }

            return back()->withInput()->with('error', __('supplier.errors.save_with_reason', ['message' => $e->getMessage()]));
        } catch (Throwable $e) {
            DB::rollBack();
            report($e);

            foreach ($newlyCreatedFiles as $newFile) {
                if ($newFile && Storage::disk('private')->exists($newFile)) {
                    Storage::disk('private')->delete($newFile);
                }
            }

            if ($request->expectsJson()) {
                throw $e;
            }

            return back()->withInput()->with('error', __('supplier.copy.failed_to_save_quotation_an_unexpected_error_occurred_please_try_again'));
        }
    }

    /**
     * @return array{weight: ?float, source: ?string, error: ?string}
     */
    public function resolveOfferedWeight(
        PrItem $prItem,
        array $item,
        ?DimensionRange $length,
        bool $legacyPayload,
    ): array {
        $rawWeight = $item['offered_weight_per_unit'] ?? null;
        $hasWeight = $rawWeight !== null && $rawWeight !== '' && is_numeric($rawWeight) && (float) $rawWeight > 0;
        $manualOverride = $this->booleanInput($item['offered_weight_manual_override'] ?? false);

        if ($length?->isRange()) {
            if ($hasWeight) {
                return [
                    'weight' => round((float) $rawWeight, 4, PHP_ROUND_HALF_UP),
                    'source' => QuotationItem::OFFER_WEIGHT_SOURCE_ESTIMATED,
                    'error' => null,
                ];
            }

            return [
                'weight' => $legacyPayload ? (float) $prItem->weight_needed : null,
                'source' => null,
                'error' => __('supplier.copy.a_length_range_requires_a_supplier_provided_offer_kg_unit_marked_as_estimated'),
            ];
        }

        $sanitized = QuotationItem::sanitizeAvailabilityData($item, $prItem);
        $dimensions = [];
        foreach (PrItem::relevantDimensionFields($prItem->shape) as $field) {
            $dimensions[$field] = $field === 'length'
                ? $length?->exact
                : ($sanitized['available_'.$field] ?? null);
        }
        $hasCompleteGeometry = collect(PrItem::relevantDimensionFields($prItem->shape))
            ->every(fn (string $field) => isset($dimensions[$field]) && is_numeric($dimensions[$field]) && (float) $dimensions[$field] > 0);

        if ($manualOverride) {
            if ($hasWeight) {
                return [
                    'weight' => round((float) $rawWeight, 4, PHP_ROUND_HALF_UP),
                    'source' => QuotationItem::OFFER_WEIGHT_SOURCE_ESTIMATED,
                    'error' => null,
                ];
            }

            return [
                'weight' => null,
                'source' => null,
                'error' => __('supplier.copy.offer_kg_unit_must_be_greater_than_zero_when_manual_override_is_selected'),
            ];
        }

        if ($hasCompleteGeometry && $prItem->materialMaster) {
            $calculation = $this->weightCalculator->calculate(
                $prItem->materialMaster,
                $prItem->shape,
                $dimensions,
                1,
            );

            if ($calculation->isCalculated()
                && $calculation->unitKg !== null
                && (float) $calculation->unitKg > 0) {
                if ($hasWeight && abs((float) $rawWeight - (float) $calculation->unitKg) > QuotationItem::AVAILABILITY_TOLERANCE) {
                    return [
                        'weight' => round((float) $rawWeight, 4, PHP_ROUND_HALF_UP),
                        'source' => QuotationItem::OFFER_WEIGHT_SOURCE_ESTIMATED,
                        'error' => null,
                    ];
                }

                return [
                    'weight' => (float) $calculation->unitKg,
                    'source' => QuotationItem::OFFER_WEIGHT_SOURCE_AUTO,
                    'error' => null,
                ];
            }
        }

        if ($hasWeight) {
            return [
                'weight' => round((float) $rawWeight, 4, PHP_ROUND_HALF_UP),
                'source' => QuotationItem::OFFER_WEIGHT_SOURCE_ESTIMATED,
                'error' => null,
            ];
        }

        if ($legacyPayload) {
            return [
                'weight' => (float) $prItem->weight_needed,
                'source' => null,
                'error' => null,
            ];
        }

        return [
            'weight' => null,
            'source' => null,
            'error' => __('supplier.copy.complete_the_offered_dimensions_or_provide_offer_kg_unit_before_saving_this_available_item'),
        ];
    }

    private function booleanInput(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN) ?? false;
    }

    private function storeMtcAttachment(QuotationItem $quotationItem, UploadedFile $file, array &$newlyCreatedFiles = []): void
    {
        $fileName = $file->hashName();
        $path = 'attachments/'.now()->format('Y/m').'/'.$fileName; // biz-time:ignore storage path

        $stored = app(FileInspectionService::class)->storeUpload($file, 'mtc', $path, 'items');

        $newlyCreatedFiles[] = $path;

        $quotationItem->attachments()->create([
            'file_path' => $stored['file_path'],
            'file_name' => $stored['file_name'],
            'file_type' => $stored['file_type'],
            'file_inspection_id' => $stored['file_inspection_id'],
            'uploaded_by' => auth()->id(),
        ]);
    }

    private function cloneMtcAttachment(
        QuotationItem $targetItem,
        int $sourceAttachmentId,
        array $currentQuotationAttachmentMap,
        array &$newlyCreatedFiles = []
    ): ?Attachment {
        $sourceAttachment = $currentQuotationAttachmentMap[$sourceAttachmentId] ?? null;

        if (! $sourceAttachment) {
            $sourceAttachment = Attachment::query()
                ->whereKey($sourceAttachmentId)
                ->where('uploaded_by', auth()->id())
                ->where('attachable_type', QuotationItem::class)
                ->whereHasMorph('attachable', [QuotationItem::class], function ($q) {
                    $q->whereHas('quotation', function ($q2) {
                        $q2->where('supplier_id', auth()->id());
                    });
                })
                ->first();
        }

        if (! $sourceAttachment) {
            return null;
        }

        app(FileAccessGuard::class)->assertReadable($sourceAttachment);

        $sourcePath = $sourceAttachment->file_path;
        if (! $sourcePath || str_contains($sourcePath, '..') || ! str_starts_with($sourcePath, 'attachments/')) {
            return null;
        }

        if (! Storage::disk('private')->exists($sourcePath)) {
            return null;
        }

        $ext = strtolower(pathinfo($sourceAttachment->file_name, PATHINFO_EXTENSION));
        if (! in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'], true)) {
            return null;
        }

        $targetDirectory = 'attachments/'.now()->format('Y/m'); // biz-time:ignore storage path
        $targetFileName = Str::random(40).'.'.$ext;
        $targetPath = $targetDirectory.'/'.$targetFileName;

        $stored = app(FileInspectionService::class)->storePath(
            Storage::disk('private')->path($sourcePath), $sourceAttachment->file_name, 'mtc', $targetPath, 'items'
        );
        $newlyCreatedFiles[] = $targetPath;

        return $targetItem->attachments()->create([
            'file_path' => $stored['file_path'],
            'file_name' => $stored['file_name'],
            'file_type' => $stored['file_type'],
            'file_inspection_id' => $stored['file_inspection_id'],
            'uploaded_by' => auth()->id(),
        ]);
    }
}
