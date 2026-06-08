<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\PurchaseRequisition;
use App\Models\PrItem;
use App\Models\Period;
use App\Models\User;
use App\Support\PurchasingNavigation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Yajra\DataTables\Facades\DataTables;

class PurchaseRequisitionController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        if ($request->ajax()) {
            $query = PurchaseRequisition::with(['period', 'items', 'creator'])
                ->withCount('invitedSuppliers')
                ->orderBy('created_at', 'desc');

            if ($request->filled('period_id')) {
                $query->where('period_id', $request->period_id);
            }

            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            return DataTables::eloquent($query)
                ->addIndexColumn()
                ->addColumn('pr_number_display', fn($pr) => $pr->pr_number ?? '-')
                ->addColumn('period_name', fn($pr) => $pr->period->name ?? '-')
                ->addColumn('creator_name', fn($pr) => $pr->creator->name ?? '-')
                ->addColumn('item_count', fn($pr) => $pr->items->count() . ' Item')
                ->addColumn('supplier_count', fn($pr) => $pr->invited_suppliers_count)
                ->addColumn('status_badge', function ($pr) {
                    $badgeClass = match($pr->status) {
                        'draft' => 'bg-secondary',
                        'submitted' => 'bg-primary',
                        'rejected' => 'bg-danger',
                        'bidding' => 'bg-warning text-dark',
                        'completed' => 'bg-success',
                        default => 'bg-secondary'
                    };
                    $statusLabel = match($pr->status) {
                        'draft' => 'Draft',
                        'submitted' => 'Submitted',
                        'rejected' => 'Rejected',
                        'bidding' => 'Bidding',
                        'completed' => 'Completed',
                        default => ucwords(str_replace('_', ' ', $pr->status)),
                    };
                    return '<span class="badge ' . $badgeClass . ' text-uppercase" style="font-size: 0.7rem;">' . $statusLabel . '</span>';
                })
                ->addColumn('created_date', fn($pr) => $pr->created_at->format('d M Y, H:i'))
                ->addColumn('action', function ($pr) {
                    $html = '<a href="' . PurchasingNavigation::toRoute('purchasing.requisitions.show', $pr->id) . '" class="btn btn-sm btn-outline-info" title="Details"><i class="bi bi-eye"></i></a>';
                    if ($pr->created_by === auth()->id() && in_array($pr->status, ['draft', 'rejected'])) {
                        $html .= ' <a href="' . PurchasingNavigation::toRoute('purchasing.requisitions.edit', $pr->id) . '" class="btn btn-sm btn-outline-primary" title="Edit"><i class="bi bi-pencil"></i></a>';
                        $html .= ' <form action="' . route('purchasing.requisitions.destroy', $pr->id) . '" method="POST" class="d-inline delete-form">' . csrf_field() . method_field('DELETE') . '<button type="button" class="btn btn-sm btn-outline-danger btn-delete" title="Delete"><i class="bi bi-trash"></i></button></form>';
                    }
                    return $html;
                })
                ->rawColumns(['status_badge', 'action'])
                ->make(true);
        }

        $periods = Period::orderBy('year', 'desc')->orderBy('month', 'desc')->get();

        return view('purchasing.pr.index', compact('periods'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $periods = Period::where('status', 'open')->orderBy('year', 'desc')->orderBy('month', 'desc')->get();
        $suppliers = User::where('role', 'supplier')
            ->where('is_active', true)
            ->with('supplier')
            ->orderBy('name')
            ->get();

        if ($periods->isEmpty()) {
            return redirect(PurchasingNavigation::backUrl('purchasing.requisitions.index'))
                ->with('error', 'No active open period. Please contact Admin to open a period.');
        }

        return view('purchasing.pr.create', compact('periods', 'suppliers'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $this->prepareMaterialInput($request);

        $validated = $request->validate(
            $this->materialValidationRules(),
            $this->materialValidationMessages()
        );
        $items = $this->sanitizeMaterialItems($validated['items']);

        try {
            DB::beginTransaction();

            $pr = PurchaseRequisition::create([
                'period_id' => $validated['period_id'],
                'created_by' => auth()->id(),
                'pr_number' => $validated['action'] === 'submitted' ? PurchaseRequisition::generatePrNumber() : null,
                'notes' => $request->notes,
                'status' => $validated['action'], // 'draft' or 'submitted'
            ]);

            foreach ($items as $item) {
                $pr->items()->create($item);
            }

            $this->syncInvitedSuppliers($pr, $this->supplierIdsFromValidated($validated));

            DB::commit();

            // Notify admin when PR submitted
            if ($validated['action'] === 'submitted') {
                $admins = \App\Models\User::where('role', 'admin')->get();
                foreach ($admins as $admin) {
                    $admin->notify(new \App\Notifications\SystemNotification(
                        'New Purchase Requisition',
                        "New PR {$pr->pr_number} has been submitted by " . auth()->user()->name,
                        route('purchasing.requisitions.show', $pr->id),
                        'bi-clipboard-plus text-primary',
                    ));
                }
            }

            $message = $validated['action'] === 'submitted'
                ? "Purchase Requisition successfully submitted!"
                : "Purchase Requisition successfully saved as draft.";

            return redirect(PurchasingNavigation::backUrl('purchasing.requisitions.index'))->with('success', $message);

        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withInput()->with('error', 'A system error occurred while saving data: ' . $e->getMessage());
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $pr = PurchaseRequisition::with([
            'period',
            'items',
            'invitedSuppliers.supplier',
            'quotations.supplier',
            'quotations.items.prItem',
            'quotations.exchange_rate',
            'creator',
        ])->findOrFail($id);

        $quotations = $pr->quotations->map(function ($quotation) {
            $quotation->total_amount = $quotation->items->sum(function ($item) {
                $weight = optional($item->prItem)->total_weight ?? 0;

                return (float) $item->price_per_kg * (float) $weight;
            });

            $rate = $quotation->exchange_rate;
            $quotation->total_idr = $rate
                ? $quotation->items->sum(function ($item) use ($rate) {
                    $weight = optional($item->prItem)->total_weight ?? 0;

                    return (float) $item->price_per_kg * (float) $weight * (float) $rate->rate_to_idr;
                })
                : null;

            return $quotation;
        });

        $lowestTotalIdr = $quotations
            ->pluck('total_idr')
            ->filter(fn($total) => $total !== null && $total > 0)
            ->min();

        $submittedQuotationCount = $quotations->where('status', 'submitted')->count();

        return view('purchasing.pr.show', compact(
            'pr',
            'quotations',
            'lowestTotalIdr',
            'submittedQuotationCount'
        ));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        $pr = PurchaseRequisition::with(['period', 'items', 'invitedSuppliers.supplier'])->findOrFail($id);

        if ($pr->created_by !== auth()->id() || !in_array($pr->status, ['draft', 'rejected'])) {
            return redirect(PurchasingNavigation::backUrl('purchasing.requisitions.index'))
                ->with('error', "You cannot edit this requisition.");
        }

        $periods = Period::where('status', 'open')
            ->orWhere('id', $pr->period_id) // Allow keeping current period even if closed
            ->orderBy('year', 'desc')->orderBy('month', 'desc')->get();

        $suppliers = User::where('role', 'supplier')
            ->where('is_active', true)
            ->with('supplier')
            ->orderBy('name')
            ->get();

        return view('purchasing.pr.edit', compact('pr', 'periods', 'suppliers'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $pr = PurchaseRequisition::findOrFail($id);

        if ($pr->created_by !== auth()->id() || !in_array($pr->status, ['draft', 'rejected'])) {
            return redirect(PurchasingNavigation::backUrl('purchasing.requisitions.index'))
                ->with('error', "You cannot edit this requisition.");
        }

        $this->prepareMaterialInput($request);

        $validated = $request->validate(
            $this->materialValidationRules(),
            $this->materialValidationMessages()
        );
        $items = $this->sanitizeMaterialItems($validated['items']);

        try {
            DB::beginTransaction();

            $pr->update([
                'period_id' => $validated['period_id'],
                'pr_number' => ($validated['action'] === 'submitted' && !$pr->pr_number) ? PurchaseRequisition::generatePrNumber() : $pr->pr_number,
                'notes' => $request->notes,
                'status' => $validated['action'],
            ]);

            // For simplicity in dynamic forms: delete all existing items and recreate
            // Alternatively, use an ID-based check, but recreate is safe within transaction.
            $pr->items()->delete();

            foreach ($items as $item) {
                $pr->items()->create($item);
            }

            if ($request->boolean('supplier_selection_present') || $request->has('supplier_id') || $request->has('supplier_ids')) {
                $this->syncInvitedSuppliers($pr, $this->supplierIdsFromValidated($validated));
            }

            DB::commit();

            $message = $validated['action'] === 'submitted'
                ? 'Purchase Requisition successfully submitted!'
                : 'Draft purchase requisition successfully updated.';

            return redirect(PurchasingNavigation::backUrl('purchasing.requisitions.index'))->with('success', $message);

        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withInput()->with('error', 'A system error occurred while saving data: ' . $e->getMessage());
        }
    }

    private function prepareMaterialInput(Request $request): void
    {
        $items = $request->input('items');

        if (is_array($items)) {
            $request->merge([
                'items' => $this->sanitizeMaterialItems($items),
            ]);
        }
    }

    private function materialValidationRules(): array
    {
        return [
            'period_id' => 'required|exists:periods,id',
            'action' => 'required|in:draft,submitted',
            'supplier_selection_present' => 'nullable|boolean',
            'supplier_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')
                    ->where('role', 'supplier')
                    ->where('is_active', true),
            ],
            'supplier_ids' => 'nullable|array',
            'supplier_ids.*' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')
                    ->where('role', 'supplier')
                    ->where('is_active', true),
            ],
            'items' => 'required|array|min:1',
            'items.*.material_name' => 'required|string|max:255',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.weight_needed' => 'required|numeric|min:0.01',
            'items.*.hs_code' => 'required|string|max:100',
            'items.*.shape' => ['nullable', Rule::in(PrItem::SHAPES)],
            'items.*.thickness' => 'nullable|numeric|min:0',
            'items.*.d_inner' => 'nullable|numeric|min:0',
            'items.*.d_outer' => 'nullable|numeric|min:0',
            'items.*.width' => 'nullable|numeric|min:0',
            'items.*.length' => 'nullable|numeric|min:0',
        ];
    }

    private function materialValidationMessages(): array
    {
        return [
            'items.required' => 'At least 1 material must be added.',
            'items.*.material_name.required' => 'Material name is required.',
            'items.*.hs_code.required' => 'HS Code is required.',
            'items.*.quantity.required' => 'Quantity is required.',
            'items.*.quantity.min' => 'Quantity must be at least 1.',
            'items.*.weight_needed.required' => 'Weight per unit is required.',
            'items.*.shape.in' => 'Material shape must be Flat, Round, or Hollow.',
            'supplier_id.exists' => 'The selected supplier must be an active supplier.',
            'supplier_ids.*.exists' => 'The selected supplier must be an active supplier.',
        ];
    }

    private function sanitizeMaterialItems(array $items): array
    {
        $sanitized = [];

        foreach ($items as $index => $item) {
            $sanitized[$index] = PrItem::sanitizeMaterialData(is_array($item) ? $item : []);
        }

        return $sanitized;
    }

    private function supplierIdsFromValidated(array $validated): array
    {
        if (! empty($validated['supplier_id'])) {
            return [(int) $validated['supplier_id']];
        }

        return collect($validated['supplier_ids'] ?? [])
            ->filter()
            ->map(fn ($supplierId) => (int) $supplierId)
            ->all();
    }

    private function syncInvitedSuppliers(PurchaseRequisition $pr, array $supplierIds): void
    {
        $syncData = collect($supplierIds)
            ->filter()
            ->unique()
            ->mapWithKeys(fn ($supplierId) => [
                (int) $supplierId => ['invited_at' => now()],
            ])
            ->all();

        $pr->invitedSuppliers()->sync($syncData);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $pr = PurchaseRequisition::findOrFail($id);

        if ($pr->created_by !== auth()->id() || !in_array($pr->status, ['draft', 'rejected'])) {
            return redirect(PurchasingNavigation::backUrl('purchasing.requisitions.index'))
                ->with('error', "Purchase Requisition cannot be deleted because it has been processed.");
        }

        try {
            DB::beginTransaction();
            $pr->items()->delete();
            $pr->delete();
            DB::commit();

            return redirect(PurchasingNavigation::backUrl('purchasing.requisitions.index'))
                ->with('success', 'Purchase Requisition successfully deleted.');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', 'An error occurred while deleting data.');
        }
    }
}
