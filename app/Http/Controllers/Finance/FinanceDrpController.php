<?php

namespace App\Http\Controllers\Finance;

use App\Exports\PaymentBatchDrpExport;
use App\Exports\PaymentBatchTransferExport;
use App\Http\Controllers\Controller;
use App\Models\GaClaim;
use App\Models\LocalInvoice;
use App\Models\PaymentBatch;
use App\Models\PaymentGroup;
use App\Models\PaymentItem;
use App\Models\User;
use App\Services\Payment\PaymentBatchService;
use App\Services\Payment\PaymentVoucherService;
use App\Support\BankTransferMapping;
use App\Support\BusinessTime;
use App\Support\ExportDispatcher;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Vinkla\Hashids\Facades\Hashids;

class FinanceDrpController extends Controller
{
    public function indexSupplier(Request $request)
    {
        $filters = $request->validate([
            'due_date_from' => ['nullable', 'date_format:Y-m-d'],
            'due_date_to' => array_filter(['nullable', 'date_format:Y-m-d', $request->filled('due_date_from') ? 'after_or_equal:due_date_from' : null]),
            'verification_date_from' => ['nullable', 'date_format:Y-m-d'],
            'verification_date_to' => array_filter(['nullable', 'date_format:Y-m-d', $request->filled('verification_date_from') ? 'after_or_equal:verification_date_from' : null]),
        ]);
        $sortColumns = ['due_date' => 'due_date', 'verification_date' => 'ready_to_pay_at'];
        $validSort = is_string($request->query('sort')) && isset($sortColumns[$request->query('sort')]);
        $sort = $validSort ? $request->query('sort') : 'due_date';
        $direction = $validSort && in_array($request->query('direction'), ['asc', 'desc'], true)
            ? $request->query('direction') : 'asc';
        $batches = PaymentBatch::where('batch_type', PaymentBatch::TYPE_SUPPLIER)
            ->withCount('groups')
            ->latest('id')
            ->paginate(15)->withQueryString();

        // Candidate Ready to Pay invoices not yet in active DRP
        $supplier = $this->resolveSupplierFilter($request->query('supplier_id'));
        $eligibleInvoices = LocalInvoice::eligibleForPaymentBatch()
            ->when($supplier, fn ($q) => $q->where('supplier_id', $supplier->id))
            ->when($filters['due_date_from'] ?? null, fn ($q, $date) => $q->where('due_date', '>=', $date))
            ->when($filters['due_date_to'] ?? null, fn ($q, $date) => $q->where('due_date', '<=', $date))
            ->when($filters['verification_date_from'] ?? null, fn ($q, $date) => $q->where('ready_to_pay_at', '>=', BusinessTime::toStorage(BusinessTime::parseDate($date))))
            ->when($filters['verification_date_to'] ?? null, fn ($q, $date) => $q->where('ready_to_pay_at', '<', BusinessTime::toStorage(BusinessTime::parseDate($date)->addDay())))
            ->whereDoesntHave('paymentItem', function ($q) {
                $q->where('status', PaymentItem::STATUS_ACTIVE)
                    ->whereHas('group', fn ($g) => $g->where('status', PaymentGroup::STATUS_UNPAID)
                        ->whereHas('batch', fn ($b) => $b->whereIn('status', PaymentBatch::ACTIVE_STATUSES))
                    );
            })
            ->with(['supplier.supplier', 'supplier.activeSupplierBankAccount', 'currentVerification'])
            ->orderBy($sortColumns[$sort], $direction)
            ->orderBy('id')
            ->paginate(25, ['*'], 'candidate_page')
            ->withQueryString();

        return view('finance.drp.supplier', ['batches' => $batches, 'eligibleInvoices' => $eligibleInvoices, 'supplierFilter' => $supplier, 'suppliers' => User::localEligible()->with('supplier')->orderBy('name')->get(), 'candidateSort' => $sort, 'candidateDirection' => $direction]);
    }

    public function createSupplierBatch(Request $request, PaymentBatchService $service)
    {
        $request->validate([
            'invoice_ids' => 'required|array|min:1',
            'invoice_ids.*' => 'required|integer|exists:local_invoices,id',
            'notes' => 'nullable|string|max:1000',
        ]);

        $batch = $service->createSupplierBatch($request->user(), $request->input('invoice_ids'), $request->input('notes'));

        return redirect()->route('finance.drp.show', $batch)->with('success', __('finance.feedback.batch_created', ['number' => $batch->batch_number]));
    }

    public function indexGa(Request $request)
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'claim_type' => ['nullable', Rule::in(GaClaim::CLAIM_TYPES)],
        ]);
        $batches = PaymentBatch::where('batch_type', PaymentBatch::TYPE_GA)
            ->withCount('groups')
            ->latest('id')
            ->paginate(15)->withQueryString();

        $eligibleClaims = GaClaim::eligibleForPaymentBatch()
            ->when($filters['claim_type'] ?? null, fn ($q, $type) => $q->where('claim_type', $type))
            ->when(isset($filters['q']) && $filters['q'] !== '', function ($query) use ($filters) {
                $search = '%'.addcslashes($filters['q'], '\\%_').'%';
                $query->where(fn ($q) => $q->where('claim_number', 'like', $search)
                    ->orWhereHas('employee', fn ($employee) => $employee->where('name', 'like', $search)));
            })
            ->with('employee')
            ->orderBy('ready_to_pay_at')->orderBy('id')
            ->paginate(25, ['*'], 'candidate_page')->withQueryString();

        return view('finance.drp.ga', compact('batches', 'eligibleClaims'));
    }

    public function createGaBatch(Request $request, PaymentBatchService $service)
    {
        $validated = $request->validate([
            'claim_ids' => ['required', 'array', 'min:1'],
            'claim_ids.*' => ['required', 'integer', 'distinct', 'exists:ga_claims,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $batch = $service->createGaBatch($request->user(), $validated['claim_ids'], $validated['notes'] ?? null);

        return redirect()->route('finance.drp.show', $batch)->with('success', __('finance.feedback.batch_created', ['number' => $batch->batch_number]));
    }

    public function show(PaymentBatch $batch, PaymentVoucherService $voucherService)
    {
        $batch->load(['groups.items.payable', 'groups.items.localInvoicePayment.overpayment', 'groups.items.localInvoiceVoucher.payment.transfers', 'creator', 'finalizer']);

        return view('finance.drp.show', [
            'batch' => $batch,
            'voucherService' => $voucherService,
        ]);
    }

    public function finalize(PaymentBatch $batch, Request $request, PaymentBatchService $service)
    {
        $service->finalizeBatch($batch, $request->user());

        return back()->with('success', __('finance.feedback.batch_finalized', ['number' => $batch->batch_number]));
    }

    public function removeItem(PaymentItem $item, Request $request, PaymentBatchService $service)
    {
        $request->validate(['reason' => 'required|string|max:500']);
        $service->removeItem($item, $request->user(), $request->input('reason'));

        return back()->with('success', __('finance.feedback.item_removed'));
    }

    public function cancelBatch(PaymentBatch $batch, Request $request, PaymentBatchService $service)
    {
        $request->validate(['reason' => 'required|string|max:500']);
        $service->cancelBatch($batch, $request->user(), $request->input('reason'));

        return redirect()->route($batch->batch_type === PaymentBatch::TYPE_SUPPLIER ? 'finance.drp.supplier' : 'finance.drp.ga')
            ->with('success', __('finance.feedback.batch_cancelled', ['number' => $batch->batch_number]));
    }

    public function overrideFee(PaymentGroup $group, Request $request, PaymentBatchService $service)
    {
        $request->validate([
            'bank_fee' => 'required|numeric|min:0',
            'reason' => 'required|string|max:500',
        ]);

        $service->overrideGroupFee($group, (float) $request->input('bank_fee'), $request->input('reason'), $request->user());

        return back()->with('success', __('finance.feedback.fee_updated'));
    }

    public function assignVoucher(PaymentGroup $group, Request $request, PaymentVoucherService $service)
    {
        $request->validate([
            'voucher_number' => 'required|string|max:100',
            'voucher_date' => 'required|date',
        ]);

        $service->assignVoucher($group, $request->input('voucher_number'), $request->input('voucher_date'), $request->user());

        return back()->with('success', __('finance.feedback.voucher_updated'));
    }

    public function export(PaymentBatch $batch, Request $request)
    {
        if ($batch->batch_type !== PaymentBatch::TYPE_SUPPLIER) {
            abort(422, __('finance.export_validation.supplier_only'));
        }

        if ($batch->status === PaymentBatch::STATUS_CANCELLED) {
            abort(422, __('finance.export_validation.cancelled'));
        }

        $hasActiveItems = $batch->groups()
            ->where('status', '!=', PaymentGroup::STATUS_CANCELLED)
            ->whereHas('items', function ($q) {
                $q->where('status', PaymentItem::STATUS_ACTIVE)
                    ->where('payable_type', LocalInvoice::class);
            })
            ->exists();

        if (! $hasActiveItems) {
            abort(422, __('finance.export_validation.empty'));
        }

        $exportJob = ExportDispatcher::dispatch(
            __('exports.job_labels.drp', ['number' => $batch->batch_number]),
            PaymentBatchDrpExport::class,
            [$request->user()->id, [$batch->id]],
            "DRP_{$batch->batch_number}.xlsx"
        );

        $message = __('finance.feedback.export_accepted');

        if ($request->wantsJson()) {
            return response()->json([
                'message' => $message,
                'export_job_id' => $exportJob->getRouteKey(),
                'exports_url' => route('exports.index', absolute: false),
                'status_url' => route('exports.status', $exportJob, absolute: false),
                'cancel_url' => route('exports.cancel', $exportJob, absolute: false),
            ], 202);
        }

        return back()->with('info', $message);
    }

    /**
     * POST /finance/drp/export-transfer
     *
     * Bulk export transfer data from multiple DRP batches into a single TARIKAN TRANSFER workbook.
     * Validates ALL selected batches atomically before dispatching.
     */
    public function exportTransferBulk(Request $request)
    {
        $request->validate([
            'batch_ids' => 'required|array|min:1',
            'batch_ids.*' => 'required|string',
        ]);

        // Resolve hashed batch IDs to integers
        $rawIds = $request->input('batch_ids');
        $resolvedIds = [];
        foreach ($rawIds as $hashId) {
            if (ctype_digit((string) $hashId)) {
                abort(422, __('finance.export_validation.raw_ids'));
            }

            try {
                $decoded = Hashids::decode($hashId);
            } catch (\Throwable) {
                $decoded = [];
            }

            if (count($decoded) !== 1 || (int) $decoded[0] <= 0) {
                abort(422, __('finance.export_validation.invalid_id', ['id' => $hashId]));
            }

            $resolvedIds[] = (int) $decoded[0];
        }

        // Deduplicate
        $resolvedIds = array_values(array_unique($resolvedIds));

        if (empty($resolvedIds)) {
            abort(422, __('finance.export_validation.missing_ids'));
        }

        // Load all batches with eager loading
        $batches = PaymentBatch::query()
            ->whereIn('id', $resolvedIds)
            ->with([
                'groups' => fn ($q) => $q
                    ->where('status', '!=', PaymentGroup::STATUS_CANCELLED)
                    ->orderBy('id')
                    ->with([
                        'items' => fn ($q) => $q
                            ->where('status', PaymentItem::STATUS_ACTIVE)
                            ->where('payable_type', LocalInvoice::class)
                            ->orderBy('id')
                            ->with('payable'),
                    ]),
            ])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        // Validate: all requested batches must exist
        if ($batches->count() !== count($resolvedIds)) {
            $foundIds = $batches->pluck('id')->toArray();
            $missing = array_diff($resolvedIds, $foundIds);
            abort(422, __('finance.export_validation.missing_batches', ['ids' => implode(', ', $missing)]));
        }

        // Validate: all batches must be TYPE_SUPPLIER
        $nonSupplier = $batches->filter(fn ($b) => $b->batch_type !== PaymentBatch::TYPE_SUPPLIER);
        if ($nonSupplier->isNotEmpty()) {
            $names = $nonSupplier->pluck('batch_number')->join(', ');
            abort(422, __('finance.export_validation.non_supplier', ['numbers' => $names]));
        }

        // Validate: no CANCELLED batches
        $cancelled = $batches->filter(fn ($b) => $b->status === PaymentBatch::STATUS_CANCELLED);
        if ($cancelled->isNotEmpty()) {
            $names = $cancelled->pluck('batch_number')->join(', ');
            abort(422, __('finance.export_validation.cancelled_batches', ['numbers' => $names]));
        }

        // Validate: every batch must have at least one active supplier item
        foreach ($batches as $batch) {
            $hasActiveItems = $batch->groups->contains(function (PaymentGroup $group) {
                return $group->items->contains(
                    fn (PaymentItem $item) => $item->status === PaymentItem::STATUS_ACTIVE
                        && $item->payable_type === LocalInvoice::class
                );
            });

            if (! $hasActiveItems) {
                abort(422, __('finance.export_validation.empty_batch', ['number' => $batch->batch_number]));
            }
        }

        // Validate: all bank mappings resolve
        $bankFailures = BankTransferMapping::validateBatches($batches);
        if (! empty($bankFailures)) {
            $details = collect($bankFailures)
                ->map(fn ($f) => "[{$f['batch_number']}] bank: {$f['bank_name']}")
                ->join('; ');
            abort(422, __('finance.export_validation.unknown_banks', ['details' => $details]));
        }

        // All validation passed — dispatch single export job
        $fileName = 'DRP_TRANSFER_'.now()->format('Ymd').'.xlsx'; // biz-time:ignore instant filename
        $batchNumbers = $batches->pluck('batch_number')->join(', ');
        $label = __('exports.job_labels.drp_transfer', ['numbers' => $batchNumbers]);

        // Truncate label if too long
        if (strlen($label) > 200) {
            $label = substr($label, 0, 197).'...';
        }

        $exportJob = ExportDispatcher::dispatch(
            $label,
            PaymentBatchTransferExport::class,
            [$request->user()->id, $resolvedIds],
            $fileName
        );

        $message = __('finance.feedback.transfer_export_accepted');

        if ($request->wantsJson()) {
            return response()->json([
                'message' => $message,
                'export_job_id' => $exportJob->getRouteKey(),
                'exports_url' => route('exports.index', absolute: false),
                'status_url' => route('exports.status', $exportJob, absolute: false),
                'cancel_url' => route('exports.cancel', $exportJob, absolute: false),
            ], 202);
        }

        return back()->with('info', $message);
    }

    private function resolveSupplierFilter(mixed $value): ?User
    {
        if ($value === null || $value === '') {
            return null;
        }
        abort_unless(is_string($value) && ! ctype_digit($value), 404);
        $supplier = (new User)->resolveRouteBinding($value);
        abort_unless($supplier instanceof User && $supplier->isLocalEligible(), 404);

        return $supplier;
    }
}
