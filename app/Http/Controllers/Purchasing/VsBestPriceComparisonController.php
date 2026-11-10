<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Purchasing\Concerns\PriceComparisonSharedTrait;
use App\Services\Pricing\VsBestPriceQueryService;
use App\Services\RegionalDisplayFormatter;
use App\Support\HashidCodec;
use App\Support\Pricing\EvaluationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

class VsBestPriceComparisonController extends Controller
{
    use PriceComparisonSharedTrait;

    public function __construct(
        private ?VsBestPriceQueryService $queryService = null,
        ?RegionalDisplayFormatter $regionalFormatter = null
    ) {
        $this->queryService ??= app(VsBestPriceQueryService::class);
        $this->regionalFormatter = $regionalFormatter ?? app(RegionalDisplayFormatter::class);
    }

    public function vsBestPrice(Request $request): View|JsonResponse
    {
        [$dateFrom, $dateTo] = $this->queryService->vsBestDateRange($request);
        $currency = $this->analysisCurrency($request);
        $context = EvaluationContext::current();
        $evaluation = $this->evaluationMeta($currency, $context);
        $dateFromInput = $request->input('date_from');
        $dateToInput = $request->input('date_to');
        $competitiveThreshold = 2.0;

        $summary = $this->queryService->getCachedDefaultVsBestSummary($competitiveThreshold, $currency, $context);

        $vsBestMaterials = $this->queryService->vsBestMaterialOptions();
        $requestedMaterial = $this->queryService->vsBestMaterialFilter($request);
        $selectedVsBestMaterial = $requestedMaterial === null ? null : $vsBestMaterials->first(
            fn (array $option) => $option['name'] === $requestedMaterial['name'] && $option['shape'] === $requestedMaterial['shape']
        );
        $vsBestMaterialIgnored = $requestedMaterial !== null && $selectedVsBestMaterial === null;

        $viewData = compact(
            'dateFromInput',
            'dateToInput',
            'summary',
            'competitiveThreshold',
            'currency',
            'evaluation',
            'vsBestMaterials',
            'selectedVsBestMaterial',
            'vsBestMaterialIgnored',
        );

        if ($request->ajax()) {
            return response()->json([
                'html' => view('purchasing.comparison._vs_best_content', $viewData)->render(),
                'tab' => 'vs-best',
                'url' => route('purchasing.comparison.vs-best', $request->query()),
            ]);
        }

        return view('purchasing.comparison.vs-best', $viewData);
    }

    public function vsBestPriceData(Request $request)
    {
        [$dateFrom, $dateTo] = $this->queryService->vsBestDateRange($request);
        $competitiveThreshold = 2.0;
        $currency = $this->analysisCurrency($request);
        $context = EvaluationContext::current();

        $keyword = trim((string) $request->input('search.value', ''));
        $material = $this->queryService->vsBestMaterialFilter($request);
        $returnUrl = route('purchasing.comparison.vs-best', $request->only([
            'date_from',
            'date_to',
            'currency',
            'material_name',
            'shape',
        ]));

        $isUnfilteredDefault = $dateFrom === null && $dateTo === null && $keyword === '' && $material === null;

        if ($isUnfilteredDefault) {
            $summary = Cache::remember(
                $this->queryService->vsBestSummaryCacheKey($currency, $context),
                now()->addMinutes(5),
                function () use ($competitiveThreshold, $currency, $context) {
                    $summaryQuery = $this->queryService->buildVsBestQuery(null, null, $currency, $context);

                    return $this->queryService->buildVsBestSummary($summaryQuery, $competitiveThreshold);
                }
            );
        } else {
            $summaryQuery = $this->queryService->applyVsBestKeywordFilter(
                $this->queryService->buildVsBestQuery($dateFrom, $dateTo, $currency, $context, $material),
                $keyword
            );
            $summary = $this->queryService->buildVsBestSummary($summaryQuery, $competitiveThreshold);
        }

        $dataTable = DataTables::query($this->queryService->buildVsBestQuery($dateFrom, $dateTo, $currency, $context, $material))
            ->filter(function ($query) use ($keyword) {
                $this->queryService->applyVsBestKeywordFilter($query, $keyword);
            })
            ->setFilteredRecords($summary['total_rows']);

        if ($keyword === '') {
            $dataTable->setTotalRecords($summary['total_rows']);
        }

        return $dataTable
            ->addColumn('material_display', function ($row) use ($returnUrl) {
                $prUrl = $this->routeWithReturn(
                    'purchasing.requisitions.show',
                    HashidCodec::encode((int) $row->current_pr_id),
                    $returnUrl
                );

                return '<div class="fw-bold">'.e($row->material_name).'</div>'
                    .'<div class="text-muted small">Qty: '.$this->regionalFormatter->number(number_format((int) ($row->quantity ?? 1), 0, ',', '.'), 'indonesian').'</div>'
                    .'<div class="text-muted small">Berat/unit: '.$this->formatNumber($row->weight_needed).' kg</div>'
                    .'<div class="text-muted small">Total weight: '.$this->formatNumber($row->total_weight).' kg</div>'
                    .'<a href="'.e($prUrl).'" class="small text-primary text-decoration-none">'
                    .e($row->current_pr_number ?: '-')
                    .'</a>';
            })
            ->addColumn('current_price_display', function ($row) use ($currency) {
                return '<div class="fw-bold text-primary">'.$this->formatAnalyticAmount($row->current_price_idr, $currency).'</div>'
                    .'<div class="text-muted small">'.$this->formatPrice($row->current_price, 4).' '.e($row->current_currency).'/kg</div>'
                    .'<div class="text-muted small">'.e($row->current_supplier ?: '-').'</div>'
                    .'<div class="text-muted small">'.e($this->formatDate($row->current_submitted_at) ?? __('purchasing.copy.draft')).'</div>';
            })
            ->addColumn('best_price_display', function ($row) use ($returnUrl, $currency) {
                $html = '<div class="fw-bold text-success">'.$this->formatAnalyticAmount($row->best_price_idr, $currency).'</div>'
                    .'<div class="text-muted small">'.$this->formatPrice($row->best_price, 4).' '.e($row->best_currency).'/kg</div>'
                    .'<div class="text-muted small">'.e($row->best_supplier ?: '-').'</div>';

                if ($row->best_pr_id) {
                    $bestPrUrl = $this->routeWithReturn(
                        'purchasing.requisitions.show',
                        HashidCodec::encode((int) $row->best_pr_id),
                        $returnUrl
                    );
                    $html .= '<a href="'.e($bestPrUrl).'" class="small text-primary text-decoration-none">'
                        .e($row->best_pr_number ?: '-')
                        .'</a>';
                } else {
                    $html .= '<div class="text-muted small">PR: -</div>';
                }

                return $html.'<div class="text-muted small">'.e($this->formatDate($row->best_submitted_at) ?? '-').'</div>';
            })
            ->addColumn('diff_display', function ($row) use ($currency) {
                $diff = $row->diff_idr_per_kg !== null ? (float) $row->diff_idr_per_kg : null;
                $class = $diff > 0 ? 'tw:text-error' : ($diff < 0 ? 'tw:text-success' : 'tw:text-on-surface-variant');

                return '<div class="fw-bold '.$class.'">'.$this->formatAnalyticAmount($diff, $currency, true).'</div>'
                    .'<div class="small '.$class.'">'.$this->formatPercent($row->diff_percent).'</div>';
            })
            ->addColumn('potential_difference_display', fn ($row) => '<span class="fw-bold">'.$this->formatAnalyticAmount($row->potential_difference_idr, $currency).'</span>')
            ->addColumn('status_badge', function ($row) use ($competitiveThreshold) {
                $status = $this->priceCompetitivenessStatus(
                    $row->diff_percent !== null ? (float) $row->diff_percent : null,
                    $competitiveThreshold
                );

                $tone = str_contains($status['class'], 'danger')
                    ? 'error'
                    : (str_contains($status['class'], 'warning')
                        ? 'warning'
                        : (str_contains($status['class'], 'success') ? 'success' : 'neutral'));

                return '<span class="ui-status-chip ui-status-chip--'.$tone.'">'
                    .e($status['label'])
                    .'</span><div class="text-muted small mt-1">'.e($status['recommendation']).'</div>';
            })
            ->addColumn('action', function ($row) use ($returnUrl) {
                $currentQuotationUrl = $this->routeWithReturn(
                    'purchasing.quotations.show',
                    HashidCodec::encode((int) $row->current_quotation_id),
                    $returnUrl
                );
                $html = '<div class="d-inline-flex align-items-center gap-1" role="group" aria-label="'.e(__('purchasing.copy.quotation_comparison_actions')).'">'
                    .'<a href="'.e($currentQuotationUrl).'" class="ui-data-action ui-data-action--primary ui-focus-ring" aria-label="'.e(__('purchasing.copy.view_current_quotation')).'">'.e(__('purchasing.copy.current')).'</a>';

                if ($row->best_quotation_id) {
                    $bestQuotationUrl = $this->routeWithReturn(
                        'purchasing.quotations.show',
                        HashidCodec::encode((int) $row->best_quotation_id),
                        $returnUrl
                    );
                    $html .= '<a href="'.e($bestQuotationUrl).'" class="ui-data-action ui-data-action--success ui-focus-ring" aria-label="'.e(__('purchasing.copy.view_historical_best_quotation')).'">'.e(__('purchasing.copy.best')).'</a>';
                }

                return $html.'</div>';
            })
            ->rawColumns([
                'material_display',
                'current_price_display',
                'best_price_display',
                'diff_display',
                'potential_difference_display',
                'status_badge',
                'action',
            ])
            ->with('summary', $summary)
            ->with('currency', $currency)
            ->make(true);
    }
}
