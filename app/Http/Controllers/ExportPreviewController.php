<?php

namespace App\Http\Controllers;

use App\Exports\InspectionsExport;
use App\Exports\LocalInvoicesExport;
use App\Exports\PurchaseOrdersExport;
use App\Exports\PurchasingHistoricalPriceExport;
use App\Exports\QuotationsExport;
use App\Exports\RequisitionsExport;
use App\Exports\ShipmentsExport;
use App\Http\Requests\Export\Filters\InspectionExportFilters;
use App\Http\Requests\Export\Filters\LocalInvoiceExportFilters;
use App\Http\Requests\Export\Filters\PurchaseOrderExportFilters;
use App\Http\Requests\Export\Filters\PurchasingPriceHistoryExportFilters;
use App\Http\Requests\Export\Filters\QuotationExportFilters;
use App\Http\Requests\Export\Filters\RequisitionExportFilters;
use App\Http\Requests\Export\Filters\ShipmentExportFilters;
use App\Models\User;
use App\Support\Export\ExportDefinitions;
use App\Support\Export\ExportOptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class ExportPreviewController extends Controller
{
    public function count(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'export_key' => ['required', 'string', 'max:64'],
        ]);

        $exportKey = $validated['export_key'];

        try {
            $definition = ExportDefinitions::get($exportKey);
        } catch (InvalidArgumentException) {
            abort(404);
        }

        /** @var User $user */
        $user = $request->user();
        abort_unless($definition->authorize($user), 403);
        abort_unless(ExportDefinitions::supportsColumns($definition), 422);

        $filterPayload = $request->except(['export_key', '_token', 'options']);
        if ($exportKey === 'accounting.local-invoices' && empty($filterPayload['report'])) {
            $filterPayload['report'] = 'register';
        }
        // Pass an empty options array so filter validators enforce the strict
        // advanced date rules identical to the POST export endpoints.
        $filterRequest = Request::create('/', 'POST', [...$filterPayload, 'options' => []]);

        $isSupplier = str_starts_with($exportKey, 'supplier.');
        $export = match ($definition->exportClass()) {
            PurchaseOrdersExport::class => $this->buildPurchaseOrdersExport($filterRequest, $user, $isSupplier),
            QuotationsExport::class => $this->buildQuotationsExport($filterRequest, $user, $isSupplier),
            RequisitionsExport::class => $this->buildRequisitionsExport($filterRequest),
            ShipmentsExport::class => $this->buildShipmentsExport($filterRequest, $user, $isSupplier),
            InspectionsExport::class => $this->buildInspectionsExport($filterRequest),
            LocalInvoicesExport::class => $this->buildLocalInvoicesExport($filterRequest, $user, str_starts_with($exportKey, 'accounting.')),
            PurchasingHistoricalPriceExport::class => $this->buildPurchasingPriceHistoryExport($filterRequest),
            default => abort(422),
        };

        $audience = explode('.', $exportKey, 2)[0];
        $export->applyOptions(new ExportOptions(ExportDefinitions::defaultKeys($definition), 'xlsx', $audience));

        $totalRows = max(0, $export->progressTotalRows());
        $maxRows = (int) config('exports.max_rows');

        return response()->json([
            'total_rows' => $totalRows,
            'max_rows' => $maxRows,
            'exceeds_limit' => $totalRows > $maxRows,
            'is_empty' => $totalRows === 0,
        ]);
    }

    private function buildPurchaseOrdersExport(Request $filterRequest, User $user, bool $isSupplier): PurchaseOrdersExport
    {
        $filters = PurchaseOrderExportFilters::validated($filterRequest, supplier: $isSupplier);

        return new PurchaseOrdersExport(
            $isSupplier ? (int) $user->getKey() : ($filters['supplier_id'] ?? null),
            $filters['start_date'] ?? null,
            $filters['end_date'] ?? null,
            $filters['po_number'] ?? null,
            $filters['status'] ?? null,
            $filters['search'] ?? null,
        );
    }

    private function buildQuotationsExport(Request $filterRequest, User $user, bool $isSupplier): QuotationsExport
    {
        $filters = QuotationExportFilters::validated($filterRequest, supplier: $isSupplier);

        return $isSupplier
            ? new QuotationsExport($filters, (int) $user->getKey(), true)
            : new QuotationsExport($filters);
    }

    private function buildRequisitionsExport(Request $filterRequest): RequisitionsExport
    {
        $filters = RequisitionExportFilters::validated($filterRequest);

        return new RequisitionsExport(
            $filters['period_id'] ?? null,
            $filters['status'] ?? null,
            $filters['search'] ?? null,
        );
    }

    private function buildShipmentsExport(Request $filterRequest, User $user, bool $isSupplier): ShipmentsExport
    {
        $filters = ShipmentExportFilters::validated($filterRequest, supplier: $isSupplier);

        return new ShipmentsExport(
            $isSupplier ? (int) $user->getKey() : ($filters['supplier_id'] ?? null),
            $filters['status'] ?? null,
            $filters['search'] ?? null,
            $filters['start_date'] ?? null,
            $filters['end_date'] ?? null,
            $filters['shipment_number'] ?? null,
        );
    }

    private function buildInspectionsExport(Request $filterRequest): InspectionsExport
    {
        $filters = InspectionExportFilters::validated($filterRequest);

        return new InspectionsExport(
            $filters['start_date'] ?? null,
            $filters['end_date'] ?? null,
            $filters['status'] ?? null,
        );
    }

    private function buildLocalInvoicesExport(Request $filterRequest, User $user, bool $accounting): LocalInvoicesExport
    {
        $filters = LocalInvoiceExportFilters::validated($filterRequest, accounting: $accounting);
        $payments = $accounting && ($filters['report'] ?? 'register') === 'payments';
        unset($filters['report']);

        return new LocalInvoicesExport((int) $user->getKey(), $filters, $payments);
    }

    private function buildPurchasingPriceHistoryExport(Request $filterRequest): PurchasingHistoricalPriceExport
    {
        $filters = PurchasingPriceHistoryExportFilters::validated($filterRequest);

        return new PurchasingHistoricalPriceExport($filters);
    }
}
