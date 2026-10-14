<?php

namespace App\Http\Controllers;

use App\Models\LocalGoodsReceipt;
use App\Models\LocalPurchaseOrder;
use App\Services\RegionalDisplayFormatter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Yajra\DataTables\Facades\DataTables;

class LocalGoodsReceiptTableController extends Controller
{
    public function __invoke(Request $request, LocalPurchaseOrder $purchaseOrder, RegionalDisplayFormatter $formatter)
    {
        Gate::authorize('view', $purchaseOrder);
        $supplier = str_starts_with($request->route()->getName(), 'local-supplier.');
        $prefix = $supplier ? 'local-supplier.purchase-orders'
            : (str_starts_with($request->route()->getName(), 'purchasing.') ? 'purchasing.local-procurement' : 'finance.local-procurement');
        $statuses = [LocalGoodsReceipt::STATUS_AVAILABLE, LocalGoodsReceipt::STATUS_RESERVED, LocalGoodsReceipt::STATUS_INVOICED];
        if (! $supplier) {
            $statuses[] = LocalGoodsReceipt::STATUS_CANCELLED;
        }
        $request->merge(['length' => $request->input('length', 25), 'start' => $request->input('start', 0), 'draw' => $request->input('draw', 0)]);
        $request->validate(['draw' => ['required', 'integer', 'min:0'], 'start' => ['required', 'integer', 'min:0'],
            'length' => ['required', 'integer', Rule::in([10, 25, 50, 100])],
            'search.value' => ['nullable', 'string', 'max:200'], 'status' => ['nullable', Rule::in($statuses)],
            'order' => ['sometimes', 'array', 'max:1'], 'order.0.column' => ['required_with:order', 'integer', 'min:0', 'max:9'],
            'order.0.dir' => ['required_with:order', Rule::in(['asc', 'desc'])]]);
        // Column names and column-level searches are never selected by the client.
        $request->merge(['columns' => array_map(fn ($name) => ['data' => $name, 'name' => $name,
            'searchable' => false, 'orderable' => true, 'search' => ['value' => '', 'regex' => false]],
            ['DT_RowIndex', 'gr_number', 'gr_date', 'description', 'qty', 'uom', 'status_badge', 'invoice', 'source_label', 'actions'])]);
        $query = $purchaseOrder->goodsReceipts()->with('currentInvoice')
            ->when($supplier, fn ($q) => $q->where('status', '!=', LocalGoodsReceipt::STATUS_CANCELLED));

        return DataTables::eloquent($query)
            ->filter(function ($query) use ($request) {
                if ($request->filled('search.value')) {
                    $search = '%'.addcslashes($request->input('search.value'), '%_\\').'%';
                    $query->where(fn ($q) => $q->where('gr_number', 'like', $search)->orWhere('description', 'like', $search));
                }
                if ($request->filled('status')) {
                    $query->where('status', $request->input('status'));
                }
            })
            ->order(function ($query) use ($request) {
                $column = [1 => 'gr_number', 2 => 'gr_date', 3 => 'description', 4 => 'qty', 5 => 'uom', 6 => 'status'][(int) $request->input('order.0.column', 2)] ?? 'gr_date';
                $query->orderBy($column, $request->input('order.0.dir', 'desc'))->orderBy('id', 'desc');
            })
            ->addIndexColumn()
            ->editColumn('gr_date', fn ($gr) => $formatter->date($gr->gr_date, 'human') ?? '—')
            ->editColumn('qty', fn ($gr) => LocalGoodsReceipt::formatQuantity($gr->qty))
            ->addColumn('status_badge', fn ($gr) => view('finance.local-procurement._gr_status', ['status' => $gr->status])->render())
            ->addColumn('invoice', function ($gr) use ($supplier, $prefix) {
                $route = $supplier ? 'local-supplier.invoices.show'
                    : ($prefix === 'purchasing.local-procurement' ? 'purchasing.local-invoices.show' : 'finance.invoices.show');

                return view('finance.local-procurement._gr_invoice', ['invoice' => $gr->currentInvoice, 'invoiceRoute' => $route])->render();
            })
            ->addColumn('source_label', fn ($gr) => __('local_procurement.sources.'.strtolower($gr->source ?: 'MANUAL')))
            ->addColumn('edit_data', function ($gr) use ($supplier, $prefix) {
                if ($supplier || $gr->status !== LocalGoodsReceipt::STATUS_AVAILABLE) {
                    return null;
                }

                return ['update_url' => route($prefix.'.goods-receipts.update', $gr), 'cancel_url' => route($prefix.'.goods-receipts.cancel', $gr),
                    'gr_number' => $gr->gr_number, 'gr_date' => $gr->gr_date?->format('Y-m-d'), 'qty' => rtrim(rtrim((string) $gr->qty, '0'), '.') ?: '0',
                    'uom' => $gr->uom, 'description' => $gr->description, 'notes' => $gr->notes];
            })
            ->addColumn('actions', fn ($gr) => ! $supplier
                ? view('finance.local-procurement._gr_actions', ['gr' => $gr, 'purchaseOrder' => $purchaseOrder, 'routePrefix' => $prefix])->render() : '')
            ->only(['DT_RowIndex', 'gr_number', 'gr_date', 'description', 'qty', 'uom', 'status_badge', 'invoice', 'source_label', 'edit_data', 'actions'])
            ->escapeColumns(['gr_number', 'gr_date', 'description', 'qty', 'uom', 'source_label'])
            ->rawColumns(['status_badge', 'invoice', 'actions'])
            ->toJson()->header('Cache-Control', 'private, no-store');
    }
}
