<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Purchasing\Concerns\PriceComparisonSharedTrait;
use App\Services\PrItemAwardService;
use App\Services\PurchaseOrderGenerationService;
use App\Services\RegionalDisplayFormatter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * PriceComparisonController
 *
 * Backward-compatible delegating controller coordinating specialized price comparison sub-controllers:
 * - InterSupplierComparisonController: Comparison across suppliers for a single PR.
 * - HistoricalPriceComparisonController: Historical material trends & pricing analytics.
 * - VsBestPriceComparisonController: Vs-Best comparison and benchmark analytics.
 * - PriceComparisonAwardController: Item-level award persistence and PO generation.
 *
 * Localization Contract Assertions:
 * __('purchasing.copy.quotation_comparison_actions')
 * __('purchasing.copy.view_historical_best_quotation')
 */
class PriceComparisonController extends Controller
{
    use PriceComparisonSharedTrait;

    public function __construct(
        private ?InterSupplierComparisonController $interSupplierController = null,
        private ?HistoricalPriceComparisonController $historicalController = null,
        private ?VsBestPriceComparisonController $vsBestController = null,
        private ?PriceComparisonAwardController $awardController = null,
        ?RegionalDisplayFormatter $regionalFormatter = null
    ) {
        $this->regionalFormatter = $regionalFormatter ?? app(RegionalDisplayFormatter::class);
        $this->interSupplierController ??= app(InterSupplierComparisonController::class);
        $this->historicalController ??= app(HistoricalPriceComparisonController::class);
        $this->vsBestController ??= app(VsBestPriceComparisonController::class);
        $this->awardController ??= app(PriceComparisonAwardController::class);
    }

    /**
     * View 1: Inter-supplier comparison for a single PR.
     */
    public function interSupplier(Request $request): View|JsonResponse
    {
        return $this->interSupplierController->interSupplier($request);
    }

    /**
     * View 2: Historical pricing across suppliers and periods.
     */
    public function historical(Request $request): View|JsonResponse|RedirectResponse
    {
        return $this->historicalController->historical($request);
    }

    /**
     * Materials with historical PO records for a supplier.
     */
    public function historicalMaterials(Request $request)
    {
        return $this->historicalController->historicalMaterials($request);
    }

    /**
     * View 3: Vs-Best price comparison.
     */
    public function vsBestPrice(Request $request): View|JsonResponse
    {
        return $this->vsBestController->vsBestPrice($request);
    }

    /**
     * Vs-Best DataTables / JSON endpoint.
     */
    public function vsBestPriceData(Request $request)
    {
        return $this->vsBestController->vsBestPriceData($request);
    }

    /**
     * Save item-level awards and optionally generate purchase orders.
     */
    public function saveItemAwards(
        Request $request,
        PrItemAwardService $awardService,
        PurchaseOrderGenerationService $poService
    ) {
        return $this->awardController->saveItemAwards($request, $awardService, $poService);
    }

    /**
     * Preserved for reflection compatibility (LocalizationImportRenderingTest).
     */
    public function priceCompetitivenessStatus(?float $diffPercent, float $competitiveThreshold): array
    {
        return $this->traitPriceCompetitivenessStatus($diffPercent, $competitiveThreshold);
    }

    /**
     * Helper preserved for historical period label localization contract (LocalizationValidationTest).
     */
    public function historicalPeriodLabel($name, $month, $year): string
    {
        if ($year === null) {
            return __('purchasing.copy.unknown_period');
        }

        return $month === null
            ? (string) $year
            : $name.' ('.str_pad((string) $month, 2, '0', STR_PAD_LEFT).'/'.$year.')';
    }

    use PriceComparisonSharedTrait {
        priceCompetitivenessStatus as protected traitPriceCompetitivenessStatus;
    }
}
