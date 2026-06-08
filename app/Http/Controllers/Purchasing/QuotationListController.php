<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Quotation;
use App\Models\User;
use App\Models\ExchangeRate;
use App\Models\PurchaseRequisition;
use App\Notifications\SystemNotification;
use App\Support\NotificationCategory;
use App\Support\PurchasingNavigation;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class QuotationListController extends Controller
{
    /**
     * List all incoming quotations for Purchasing.
     */
    public function index(Request $request)
    {
        $request->validate([
            'date_from' => 'nullable|date_format:Y-m',
            'date_to' => 'nullable|date_format:Y-m',
            'currency' => ['nullable', Rule::in(ExchangeRate::CURRENCIES)],
        ]);

        if ($request->filled('date_from') && $request->filled('date_to') && $request->date_to < $request->date_from) {
            return back()
                ->withInput()
                ->withErrors(['date_to' => 'End date cannot be before start date']);
        }

        $query = Quotation::with(['supplier', 'purchaseRequisition.period', 'items'])
            ->whereIn('status', ['submitted', 'revision_requested', 'accepted', 'rejected']);

        // Filter: Number PR
        if ($request->filled('pr_number')) {
            $query->whereHas('purchaseRequisition', function ($q) use ($request) {
                $q->where('pr_number', 'like', '%' . trim($request->pr_number) . '%');
            });
        }

        // Filter: quotation submitted date range.
        if ($request->filled('date_from')) {
            $from = Carbon::createFromFormat('Y-m', $request->date_from)->startOfMonth();
            $query->where('submitted_at', '>=', $from);
        }

        if ($request->filled('date_to')) {
            $to = Carbon::createFromFormat('Y-m', $request->date_to)->endOfMonth();
            $query->where('submitted_at', '<=', $to);
        }

        // Filter: Supplier
        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->supplier_id);
        }

        // Filter: Status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Filter: currency.
        if ($request->filled('currency')) {
            $query->where('currency', $request->currency);
        }

        $quotations = $query->orderByDesc('submitted_at')
            ->paginate(20)
            ->appends($request->except(PurchasingNavigation::RETURN_URL_KEY));

        $suppliers = User::where('role', 'supplier')->orderBy('name')->get();

        return view('purchasing.quotations.index', compact('quotations', 'suppliers'));
    }

    /**
     * Show quotation details.
     */
    public function show($id)
    {
        $quotation = Quotation::with([
            'supplier.supplier',
            'purchaseRequisition.period',
            'items.prItem',
            'items.attachments',
            'exchange_rate',
            'attachments',
            'purchaseOrders',
            'reviewer',
        ])->findOrFail($id);

        // Use the quotation exchange-rate snapshot for consistent history conversion.
        $quotationRate = $quotation->exchange_rate;
        $latestRate = ExchangeRate::latestRate($quotation->currency);

        // Check whether a PO can be created.
        $canCreatePo = in_array($quotation->status, [Quotation::STATUS_SUBMITTED, Quotation::STATUS_ACCEPTED], true)
            && $quotation->purchaseOrders->isEmpty()
            && $quotation->purchaseRequisition->status !== 'completed'
            && !$quotation->isExpired();

        $canRequestRevision = $quotation->canRequestRevision()
            && $quotation->purchaseRequisition->status !== 'completed';
        $chatAvailable = in_array($quotation->status, ['submitted', 'revision_requested', 'accepted'], true);
        $supplierDisplayName = $quotation->supplier->supplier->company_name
            ?? $quotation->supplier->name
            ?? 'Supplier';

        return view('purchasing.quotations.show', compact(
            'quotation',
            'quotationRate',
            'latestRate',
            'canCreatePo',
            'canRequestRevision',
            'chatAvailable',
            'supplierDisplayName'
        ));
    }

    public function accept(Request $request, $id)
    {
        $quotation = Quotation::with(['purchaseRequisition', 'purchaseOrders'])->findOrFail($id);

        if (! $quotation->canApproveBy(auth()->user())) {
            return back()->with('error', 'This quotation cannot be accepted.');
        }

        if ($quotation->isExpired()) {
            return back()->with('error', 'This quotation has expired. Ask the supplier to submit a revision before accepting it.');
        }

        $quotation->update([
            'status' => Quotation::STATUS_ACCEPTED,
            'reviewed_at' => now(),
            'reviewed_by' => auth()->id(),
            'reviewer_notes' => $request->input('reviewer_notes'),
        ]);

        return back()->with('success', 'Quotation successfully accepted.');
    }

    public function reject(Request $request, $id)
    {
        $request->validate([
            'reviewer_notes' => 'required|string|max:1000',
        ], [
            'reviewer_notes.required' => 'Rejection notes are required.',
        ]);

        $quotation = Quotation::with('purchaseOrders')->findOrFail($id);

        if (! $quotation->canApproveBy(auth()->user())) {
            return back()->with('error', 'This quotation cannot be rejected.');
        }

        $quotation->update([
            'status' => Quotation::STATUS_REJECTED,
            'reviewed_at' => now(),
            'reviewed_by' => auth()->id(),
            'reviewer_notes' => $request->reviewer_notes,
        ]);

        return back()->with('success', 'Quotation successfully rejected.');
    }

    /**
     * Ask the supplier to revise an expired quotation.
     */
    public function requestRevision(Request $request, $id)
    {
        $request->validate([
            'revision_note' => 'required|string|max:1000',
        ], [
            'revision_note.required' => 'Revision notes are required.',
        ]);

        $quotation = Quotation::with([
            'supplier.supplier',
            'purchaseRequisition',
            'purchaseOrders',
        ])->findOrFail($id);

        if ($quotation->purchaseRequisition->status === 'completed') {
            return back()->with('error', 'The PR is completed. A quotation revision cannot be requested.');
        }

        if (!$quotation->canRequestRevision()) {
            return back()->with('error', 'A revision can only be requested for submitted quotations that have expired and have not been used to create a PO.');
        }

        $revisionNote = trim((string) $request->input('revision_note', ''));
        $supplierName = $quotation->supplier->supplier->company_name
            ?? $quotation->supplier->name
            ?? 'Supplier';

        DB::transaction(function () use ($quotation, $revisionNote, $supplierName) {
            $quotation->update([
                'status' => Quotation::STATUS_REVISION_REQUESTED,
                'reviewed_at' => now(),
                'reviewed_by' => auth()->id(),
                'reviewer_notes' => $revisionNote !== '' ? $revisionNote : null,
            ]);

            $conversation = Conversation::firstOrCreate([
                'conversable_type' => PurchaseRequisition::class,
                'conversable_id' => $quotation->pr_id,
                'purchasing_user_id' => auth()->id(),
                'supplier_user_id' => $quotation->supplier_id,
            ]);

            $message = 'Please revise the quotation for PR '
                . ($quotation->purchaseRequisition->pr_number ?? '#' . $quotation->pr_id)
                . ' because the quotation validity has expired.';

            if ($revisionNote !== '') {
                $message .= "\n\nRevision notes: " . $revisionNote;
            }

            $conversation->messages()->create([
                'sender_id' => auth()->id(),
                'body' => $message,
            ]);

            $quotation->supplier->notify(new SystemNotification(
                'Quotation Revision Requested',
                'Purchasing requested a quotation revision for PR :pr_number.',
                route('supplier.quotations.show', $quotation->id),
                'bi-arrow-repeat text-warning',
                [
                    'category' => NotificationCategory::QUOTATION,
                    'quotation_id' => $quotation->id,
                    'pr_id' => $quotation->pr_id,
                    'pr_number' => $quotation->purchaseRequisition->pr_number,
                    'revision_note' => $revisionNote,
                ],
                [
                    'pr_number' => $quotation->purchaseRequisition->pr_number ?? '-',
                    'supplier' => $supplierName,
                ]
            ));
        });

        $showParameters = [$quotation->id];
        if (PurchasingNavigation::isSafeUrl($request->input('return_url'))) {
            $showParameters['return_url'] = $request->input('return_url');
        }

        return redirect()->route('purchasing.quotations.show', $showParameters)
            ->with('success', 'Quotation revision request has been sent to the supplier.');
    }
}
