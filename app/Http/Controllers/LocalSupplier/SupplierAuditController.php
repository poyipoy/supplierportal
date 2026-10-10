<?php

namespace App\Http\Controllers\LocalSupplier;

use App\Http\Controllers\Controller;
use App\Http\Requests\SupplierAudit\SaveSupplierAuditAnswersRequest;
use App\Models\SupplierAudit;
use App\Services\RegionalDisplayFormatter;
use App\Services\SupplierAudit\SupplierAuditAnswerService;
use App\Services\SupplierAudit\SupplierAuditInvoiceGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class SupplierAuditController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('viewAny', SupplierAudit::class);

        $user = $request->user();
        $activeAudit = SupplierAudit::query()
            ->where('supplier_id', $user->id)
            ->active()
            ->with(['answers', 'latestResult'])
            ->latest('id')
            ->first();

        $history = SupplierAudit::query()
            ->where('supplier_id', $user->id)
            ->when($activeAudit, fn ($query) => $query->whereKeyNot($activeAudit->id))
            ->latest('id')
            ->paginate(10)
            ->withQueryString();

        return view('local-supplier.supplier-audits.index', [
            'activeAudit' => $activeAudit,
            'progress' => $activeAudit?->progress(),
            'history' => $history,
        ]);
    }

    public function show(SupplierAudit $supplierAudit)
    {
        Gate::authorize('view', $supplierAudit);

        $supplierAudit->load(['answers', 'latestResult']);

        return view('local-supplier.supplier-audits.show', [
            'audit' => $supplierAudit,
            'sections' => $supplierAudit->answerSections(),
            'progress' => $supplierAudit->progress(),
        ]);
    }

    public function edit(Request $request, SupplierAudit $supplierAudit, SupplierAuditInvoiceGate $invoiceGate): mixed
    {
        Gate::authorize('view', $supplierAudit);

        if (! Gate::allows('fill', $supplierAudit)) {
            return redirect()->route('local-supplier.supplier-audits.show', $supplierAudit)
                ->with('info', __('supplier_audit.errors.locked'));
        }

        $supplierAudit->load('answers');

        return view('local-supplier.supplier-audits.edit', [
            'audit' => $supplierAudit,
            'steps' => $supplierAudit->answerSteps(),
            'progress' => $supplierAudit->progress(),
            'invoiceBlocked' => (bool) $invoiceGate->blockingAudit($request->user())?->is($supplierAudit),
        ]);
    }

    /**
     * Autosave draft (U2): hanya baris yang berubah, selalu sebagai draft. Submit tetap lewat update().
     */
    public function autosave(SaveSupplierAuditAnswersRequest $request, SupplierAudit $supplierAudit, SupplierAuditAnswerService $service, RegionalDisplayFormatter $formatter): JsonResponse
    {
        abort_if($request->isSubmit(), 422);

        $audit = $service->save($request->user(), $supplierAudit, (array) $request->validated()['answers'], false);
        $savedAt = now();

        return response()->json([
            'saved_at' => $savedAt->toIso8601String(),
            'saved_label' => $formatter->businessTime($savedAt, 'H:i'),
            'progress' => $audit->progress(),
            'status' => $audit->status,
        ]);
    }

    public function update(SaveSupplierAuditAnswersRequest $request, SupplierAudit $supplierAudit, SupplierAuditAnswerService $service): JsonResponse|RedirectResponse
    {
        $submit = $request->isSubmit();
        $audit = $service->save($request->user(), $supplierAudit, (array) $request->validated()['answers'], $submit);

        $url = $submit
            ? route('local-supplier.supplier-audits.show', $audit)
            : route('local-supplier.supplier-audits.edit', $audit);
        $message = __($submit ? 'supplier_audit.flash.submitted' : 'supplier_audit.flash.draft_saved');

        if ($request->expectsJson()) {
            session()->flash('success', $message);

            return response()->json(['redirect' => $url]);
        }

        return redirect()->to($url)->with('success', $message);
    }
}
