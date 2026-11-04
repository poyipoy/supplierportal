<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\PrSupplierInvitationEvent;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\NotificationCategory;
use App\Support\PurchasingNavigation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adds or removes invited suppliers on a requisition that is already
 * submitted or in bidding. The requisition row is locked first (the same order
 * the quotation, award and PO paths use) so concurrent changes and quotation
 * submissions cannot slip past the guards below.
 */
class PurchaseRequisitionSupplierController extends Controller
{
    /** Statuses in which the requisition still accepts quotations. */
    public const MUTABLE_STATUSES = ['submitted', 'bidding'];

    public function __construct(private readonly NotificationService $notifications) {}

    public function store(Request $request, PurchaseRequisition $requisition): RedirectResponse
    {
        $validated = $request->validate([
            'supplier_ids' => ['required', 'array', 'min:1', 'max:50'],
            'supplier_ids.*' => ['required', 'integer', 'distinct'],
        ]);
        $requestedIds = collect($validated['supplier_ids'])->map(fn ($id) => (int) $id)->unique()->values();

        $addedEventIds = DB::transaction(function () use ($requisition, $requestedIds): array {
            $locked = $this->lockMutableRequisition($requisition);

            $eligibleIds = User::importEligible()->whereIn('id', $requestedIds)->pluck('id');
            if ($eligibleIds->count() !== $requestedIds->count()) {
                throw ValidationException::withMessages([
                    'supplier_ids' => __('purchasing.validation.supplier_active'),
                ]);
            }

            $alreadyInvited = $locked->invitedSuppliers()->pluck('users.id');
            $newIds = $requestedIds->diff($alreadyInvited)->values();
            if ($newIds->isEmpty()) {
                throw ValidationException::withMessages([
                    'supplier_ids' => __('purchasing.copy.invitation_suppliers_already_invited'),
                ]);
            }

            $locked->invitedSuppliers()->attach(
                $newIds->mapWithKeys(fn ($id) => [$id => ['invited_at' => now()]])->all()
            );

            return $newIds->mapWithKeys(fn ($supplierId) => [
                $supplierId => PrSupplierInvitationEvent::create([
                    'pr_id' => $locked->id,
                    'supplier_id' => $supplierId,
                    'event' => PrSupplierInvitationEvent::ADDED,
                    'actor_id' => auth()->id(),
                ])->id,
            ])->all();
        });

        $this->notifyInvitedSuppliers($requisition, $addedEventIds);

        return $this->backToShow($request, $requisition)
            ->with('success', __('purchasing.copy.invitation_suppliers_added', ['count' => count($addedEventIds)]));
    }

    public function destroy(Request $request, PurchaseRequisition $requisition, User $supplier): RedirectResponse
    {
        DB::transaction(function () use ($requisition, $supplier): void {
            $locked = $this->lockMutableRequisition($requisition);
            $invitedIds = $locked->invitedSuppliers()->pluck('users.id');

            if (! $invitedIds->contains($supplier->id)) {
                throw ValidationException::withMessages([
                    'supplier' => __('purchasing.copy.invitation_supplier_not_invited'),
                ]);
            }

            if ($invitedIds->count() <= 1) {
                throw ValidationException::withMessages([
                    'supplier' => __('purchasing.copy.invitation_cannot_remove_last'),
                ]);
            }

            if (Quotation::withTrashed()->where('pr_id', $locked->id)->where('supplier_id', $supplier->id)->exists()) {
                throw ValidationException::withMessages([
                    'supplier' => __('purchasing.copy.invitation_cannot_remove_with_quotation'),
                ]);
            }

            $locked->invitedSuppliers()->detach($supplier->id);

            PrSupplierInvitationEvent::create([
                'pr_id' => $locked->id,
                'supplier_id' => $supplier->id,
                'event' => PrSupplierInvitationEvent::REMOVED,
                'actor_id' => auth()->id(),
            ]);
        });

        return $this->backToShow($request, $requisition)
            ->with('success', __('purchasing.copy.invitation_supplier_removed'));
    }

    /**
     * Re-read the requisition under a row lock and refuse the change unless it
     * is still open for quotations and already restricted to an invitation
     * list. A requisition without invitations is visible to every eligible
     * supplier, so adding one would silently exclude the others.
     */
    private function lockMutableRequisition(PurchaseRequisition $requisition): PurchaseRequisition
    {
        $locked = PurchaseRequisition::query()->whereKey($requisition->id)->lockForUpdate()->firstOrFail();

        if (! in_array($locked->status, self::MUTABLE_STATUSES, true)) {
            throw ValidationException::withMessages([
                'supplier_ids' => __('purchasing.copy.invitation_requisition_not_open'),
            ]);
        }

        if (! $locked->invitedSuppliers()->exists()) {
            throw ValidationException::withMessages([
                'supplier_ids' => __('purchasing.copy.invitation_requisition_is_open'),
            ]);
        }

        return $locked;
    }

    /**
     * @param  array<int, int>  $addedEventIds  supplier id => audit event id
     */
    private function notifyInvitedSuppliers(PurchaseRequisition $requisition, array $addedEventIds): void
    {
        foreach (User::query()->whereIn('id', array_keys($addedEventIds))->get() as $supplier) {
            $this->notifications->send(
                $supplier,
                'pr.supplier_invited',
                'pr.supplier_invited:'.$requisition->id.':'.$addedEventIds[$supplier->id],
                'purchasing.notify.pr_invited_title',
                'purchasing.notify.pr_invited_body',
                route('supplier.quotations.create', $requisition, absolute: false),
                'clipboard-plus text-primary',
                [
                    'category' => NotificationCategory::QUOTATION,
                    'pr_id' => $requisition->id,
                    'pr_number' => $requisition->pr_number,
                ],
                ['pr_number' => $requisition->pr_number ?? '-'],
            );
        }
    }

    private function backToShow(Request $request, PurchaseRequisition $requisition): RedirectResponse
    {
        $parameters = [$requisition];
        if (PurchasingNavigation::isSafeUrl($request->input('return_url'))) {
            $parameters['return_url'] = $request->input('return_url');
        }

        return redirect()->route('purchasing.requisitions.show', $parameters);
    }
}
