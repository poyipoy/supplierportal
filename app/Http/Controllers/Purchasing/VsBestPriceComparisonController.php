<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Purchasing\Concerns\PriceComparisonSharedTrait;
use App\Models\PrItem;
use App\Services\Pricing\VsBestPriceQueryService;
use App\Services\RegionalDisplayFormatter;
use App\Support\HashidCodec;
use App\Support\Pricing\EvaluationContext;
use App\Support\ServerTabsResponse;
use App\Support\Ui\RowAction;
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

        if (ServerTabsResponse::wants($request) || $request->ajax()) {
            return ServerTabsResponse::make(
                view('purchasing.comparison._vs_best_content', $viewData)->render(),
                'vs-best',
                $request,
            );
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
            $summaryKey = 'purchasing:vs_best_summary_filtered:v2:'.sha1(json_encode([
                'date' => $context->dateString(),
                'currency' => $currency,
                'threshold' => $competitiveThreshold,
                'date_from' => $dateFrom?->toDateString(),
                'date_to' => $dateTo?->toDateString(),
                'material' => $material,
                'keyword' => $keyword,
            ], JSON_THROW_ON_ERROR));
            $summary = Cache::remember(
                $summaryKey,
                now()->addSeconds(60),
                function () use ($dateFrom, $dateTo, $currency, $context, $material, $keyword, $competitiveThreshold) {
                    $summaryQuery = $this->queryService->applyVsBestKeywordFilter(
                        $this->queryService->buildVsBestQuery($dateFrom, $dateTo, $currency, $context, $material),
                        $keyword
                    );

                    return $this->queryService->buildVsBestSummary($summaryQuery, $competitiveThreshold);
                }
            );
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

                return '<div class="tw:font-bold">'.e($row->material_name).'</div>'
                    .'<div class="tw:text-on-surface-variant tw:text-ui-xs">Qty: '.$this->regionalFormatter->number(number_format((int) ($row->quantity ?? 1), 0, ',', '.'), 'indonesian').'</div>'
                    .'<div class="tw:text-on-surface-variant tw:text-ui-xs">Berat/unit: '.$this->formatNumber($row->weight_needed).' kg</div>'
                    .'<div class="tw:text-on-surface-variant tw:text-ui-xs">Total weight: '.$this->formatNumber($row->total_weight).' kg</div>'
                    .'<a href="'.e($prUrl).'" class="tw:text-ui-xs tw:text-primary tw:no-underline">'
                    .e($row->current_pr_number ?: '-')
                    .'</a>';
            })
            ->addColumn('dimensions_display', function ($row): string {
                return $this->formatVsBestDimensions($row);
            })
            ->orderColumn('dimensions_display', function ($query, string $order): void {
                $direction = strtolower($order) === 'desc' ? 'DESC' : 'ASC';
                $query->orderByRaw("COALESCE(current_pr_items.shape, '') {$direction}")
                    ->orderByRaw("COALESCE(current_pr_items.thickness, current_pr_items.d_outer, 0) {$direction}")
                    ->orderByRaw("COALESCE(current_pr_items.width, current_pr_items.d_inner, 0) {$direction}")
                    ->orderByRaw("COALESCE(current_pr_items.length, 0) {$direction}");
            })
            ->addColumn('current_price_display', function ($row) use ($currency) {
                return '<div class="tw:font-bold tw:text-primary">'.$this->formatAnalyticAmount($row->current_price_idr, $currency).'</div>'
                    .'<div class="tw:text-on-surface-variant tw:text-ui-xs">'.$this->formatPrice($row->current_price, 4).' '.e($row->current_currency).'/kg</div>'
                    .'<div class="tw:text-on-surface-variant tw:text-ui-xs">'.e($row->current_supplier ?: '-').'</div>'
                    .'<div class="tw:text-on-surface-variant tw:text-ui-xs">'.e($this->formatDate($row->current_submitted_at) ?? __('purchasing.copy.draft')).'</div>';
            })
            ->addColumn('best_price_display', function ($row) use ($returnUrl, $currency) {
                $html = '<div class="tw:font-bold tw:text-success">'.$this->formatAnalyticAmount($row->best_price_idr, $currency).'</div>'
                    .'<div class="tw:text-on-surface-variant tw:text-ui-xs">'.$this->formatPrice($row->best_price, 4).' '.e($row->best_currency).'/kg</div>'
                    .'<div class="tw:text-on-surface-variant tw:text-ui-xs">'.e($row->best_supplier ?: '-').'</div>';

                if ($row->best_pr_id) {
                    $bestPrUrl = $this->routeWithReturn(
                        'purchasing.requisitions.show',
                        HashidCodec::encode((int) $row->best_pr_id),
                        $returnUrl
                    );
                    $html .= '<a href="'.e($bestPrUrl).'" class="tw:text-ui-xs tw:text-primary tw:no-underline">'
                        .e($row->best_pr_number ?: '-')
                        .'</a>';
                } else {
                    $html .= '<div class="tw:text-on-surface-variant tw:text-ui-xs">PR: -</div>';
                }

                return $html.'<div class="tw:text-on-surface-variant tw:text-ui-xs">'.e($this->formatDate($row->best_submitted_at) ?? '-').'</div>';
            })
            ->addColumn('diff_display', function ($row) use ($currency) {
                $diff = $row->diff_idr_per_kg !== null ? (float) $row->diff_idr_per_kg : null;
                $class = $diff > 0 ? 'tw:text-error' : ($diff < 0 ? 'tw:text-success' : 'tw:text-on-surface-variant');

                return '<div class="tw:font-bold '.$class.'">'.$this->formatAnalyticAmount($diff, $currency, true).'</div>'
                    .'<div class="tw:text-ui-xs '.$class.'">'.$this->formatPercent($row->diff_percent).'</div>';
            })
            ->addColumn('potential_difference_display', fn ($row) => '<span class="tw:font-bold">'.$this->formatAnalyticAmount($row->potential_difference_idr, $currency).'</span>')
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
                    .'</span><div class="tw:text-on-surface-variant tw:text-ui-xs tw:mt-1">'.e($status['recommendation']).'</div>';
            })
            ->addColumn('action', function ($row) use ($returnUrl) {
                $currentQuotationUrl = $this->routeWithReturn(
                    'purchasing.quotations.show',
                    HashidCodec::encode((int) $row->current_quotation_id),
                    $returnUrl
                );
                $html = '<div class="tw:inline-flex tw:items-center tw:gap-1" role="group" aria-label="'.e(__('purchasing.copy.quotation_comparison_actions')).'">'
                    .RowAction::link($currentQuotationUrl, __('purchasing.copy.current'), 'primary', null, [
                        'aria-label' => __('purchasing.copy.current').' - '.__('purchasing.copy.view_current_quotation'),
                    ]);

                if ($row->best_quotation_id) {
                    $bestQuotationUrl = $this->routeWithReturn(
                        'purchasing.quotations.show',
                        HashidCodec::encode((int) $row->best_quotation_id),
                        $returnUrl
                    );
                    $html .= RowAction::link($bestQuotationUrl, __('purchasing.copy.best'), 'success', null, [
                        'aria-label' => __('purchasing.copy.best').' - '.__('purchasing.copy.view_historical_best_quotation'),
                    ]);
                }

                return $html.'</div>';
            })
            ->rawColumns([
                'material_display',
                'dimensions_display',
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

    private function formatVsBestDimensions(object $row): string
    {
        $shape = PrItem::canonicalShape($row->shape ?? null) ?? ($row->shape ?? null);
        $fields = match ($shape) {
            PrItem::SHAPE_FLAT => ['thickness', 'width', 'length'],
            PrItem::SHAPE_ROUND => ['d_outer', 'length'],
            PrItem::SHAPE_HOLLOW => ['d_outer', 'd_inner', 'length'],
            default => [],
        };

        if ($fields === []) {
            return '<span class="tw:text-on-surface-variant">-</span>';
        }

        $labels = ['thickness' => '', 'width' => '', 'd_outer' => 'Ø', 'd_inner' => 'Ø', 'length' => ''];
        $format = fn ($value) => $value === null || $value === ''
            ? '-'
            : $this->regionalFormatter->number(PrItem::formatDimensionValue($value), 'decimal');

        $requestedParts = [];
        $offeredParts = [];
        $hasOffered = false;

        foreach ($fields as $field) {
            $requestedParts[] = $labels[$field].$format($row->{$field} ?? null);

            $offeredField = 'available_'.$field;
            if ($field === 'length' && ($row->available_length_min ?? null) !== null && ($row->available_length_max ?? null) !== null) {
                $hasOffered = true;
                $offeredParts[] = $format($row->available_length_min).'-'.$format($row->available_length_max);
            } else {
                $offeredVal = $row->{$offeredField} ?? null;
                if ($offeredVal !== null && $offeredVal !== '') {
                    $hasOffered = true;
                }
                $offeredParts[] = $labels[$field].$format($offeredVal);
            }
        }

        $requestedText = implode(' × ', $requestedParts).' mm';
        $shapeLabel = PrItem::shapeLabel($shape);
        $html = '<div class="tw:font-semibold tw:text-on-surface ui-tabular-nums">'.e($requestedText).'</div>';
        if ($shapeLabel !== '') {
            $html .= '<div class="tw:text-on-surface-variant tw:text-ui-xs">'.e($shapeLabel).'</div>';
        }

        if ($hasOffered) {
            $offeredText = implode(' × ', $offeredParts).' mm';
            if ($offeredText !== $requestedText) {
                $html .= '<div class="tw:text-on-surface-variant tw:text-ui-xs ui-tabular-nums">'
                    .e(__('purchasing.copy.offered')).' '.e($offeredText)
                    .'</div>';
            }
        }

        return $html;
    }
}
