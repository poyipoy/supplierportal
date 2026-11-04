<?php

namespace App\Services\Pricing;

use App\Models\ExchangeRate;
use App\Models\MaterialReferencePrice;
use App\Models\PrItem;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Support\HashidCodec;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Quotation-to-quotation price history for one PR (Gate 1 Historical comparison,
 * Revision 3 + Errata E1-E3, docs/plans/GATE-1-HISTORICAL-COMPARISON-DEEP-CONTRACT-AUDIT-20261030.md).
 *
 * Read-only. One row per (PR Item, participating supplier) (C1); the previous
 * quotation is the most recent earlier quotation of the same supplier whose key
 * dimension matches (C2, amended 2026-11-02: thickness for Flat, outer diameter for
 * Round/Hollow; other dimensions only make the row Indicative); commercial basis comes from each side's PR
 * (C3); statuses follow the C4 precedence; IDR is an identity currency and foreign
 * values convert only through their own quotation snapshot (C5).
 *
 * History candidates come from one set-based query pre-filtered on the
 * participating suppliers and the PR's material set (C8); matching and ranking
 * happen in BCMath so the candidate rows are never multiplied by the PR items.
 * Passing an analysis currency to forPr() opts Historical into annual rates
 * by PO/submission year. The null default preserves the legacy snapshot API.
 * The PO-based PurchaseHistoryPriceService is not used here.
 */
class QuotationHistoryService
{
    private ?string $analysisCurrency = null;

    /** Participating (current) and eligible historical quotation statuses (C1, C4.1, D-2). */
    public const STATUSES = [Quotation::STATUS_SUBMITTED, Quotation::STATUS_ACCEPTED, Quotation::STATUS_REJECTED];

    public const STATUS_AMBIGUOUS = 'ambiguous';

    public const STATUS_NOT_OFFERED = 'not_offered';

    public const STATUS_SPEC_INCOMPLETE = 'spec_incomplete';

    public const STATUS_INSUFFICIENT_HISTORY = 'insufficient_history';

    public const STATUS_DIFFERENT_SPEC = 'different_spec';

    public const STATUS_BASIS_DIFFERS = 'basis_differs';

    public const STATUS_FX_UNAVAILABLE = 'fx_unavailable';

    public const STATUS_INDICATIVE = 'comparable_unverified';

    public const STATUS_COMPARABLE = 'comparable';

    /** Statuses whose row carries a single valid current offer (chart Current points, E1). */
    public const CURRENT_PLOTTABLE = [
        self::STATUS_INSUFFICIENT_HISTORY, self::STATUS_DIFFERENT_SPEC, self::STATUS_BASIS_DIFFERS,
        self::STATUS_FX_UNAVAILABLE, self::STATUS_INDICATIVE, self::STATUS_COMPARABLE,
    ];

    /** Statuses that carry a diff and a percentage (C4). */
    public const PRICED = [self::STATUS_INDICATIVE, self::STATUS_COMPARABLE];

    public const DRAWER_LIMIT = 20;

    private const SCALE = 4;

    private const WORK = 16;

    /**
     * @return array{rows: list<array<string, mixed>>, items: Collection<int, PrItem>, suppliers: list<array{id: int, hash: string, name: string}>, candidates: array<int, list<array<string, mixed>>>, sources: array<string, array<string, mixed>|null>}
     */
    public function forPr(PurchaseRequisition $pr, ?int $supplierId = null, ?string $analysisCurrency = null): array
    {
        $this->analysisCurrency = $analysisCurrency;
        $items = $pr->items()->orderBy('id')->get();
        $quotations = Quotation::query()
            ->where('pr_id', $pr->id)
            ->whereIn('status', self::STATUSES)
            ->with(['supplier:id,name', 'supplier.supplier:id,user_id,company_name', 'items', 'exchange_rate'])
            ->orderBy('id')
            ->get();
        if ($supplierId !== null) {
            $quotations = $quotations->where('supplier_id', $supplierId)->values();
        }

        $bySupplier = $quotations->groupBy('supplier_id');
        $suppliers = $bySupplier->map(fn (Collection $group, $id) => [
            'id' => (int) $id,
            'hash' => HashidCodec::encode((int) $id),
            'name' => self::supplierName($group->first()),
        ])->values()->all();

        $candidates = $this->candidates($pr, $items, array_column($suppliers, 'id'));

        $rows = [];
        $sources = [];
        foreach ($items as $item) {
            foreach ($suppliers as $supplier) {
                $row = $this->row($pr, $item, $supplier, $bySupplier->get($supplier['id']), $candidates[$supplier['id']] ?? []);
                $sources[$item->id.':'.$supplier['id']] = $row['_current_line'];
                unset($row['_current_line']);
                $rows[] = $row;
            }
        }

        return ['rows' => $rows, 'items' => $items, 'suppliers' => $suppliers, 'candidates' => $candidates, 'sources' => $sources];
    }

    /**
     * Read-only price history for one (PR Item, supplier), split by key dimension: the 20
     * newest same-key records (selected previous pinned first when it is older, E3) followed
     * by the 20 newest records of another size. Only same-key records carry a difference.
     *
     * @param  array<string, mixed>  $history  output of forPr()
     * @return array{records: list<array<string, mixed>>, total: int, comparable_total: int, other_total: int, pinned: bool, current: ?array<string, mixed>, summary: ?array<string, mixed>}
     */
    public function drawer(array $history, PrItem $item, int $supplierId): array
    {
        $row = collect($history['rows'])->first(fn (array $row) => $row['pr_item_id'] === $item->id && $row['supplier']['id'] === $supplierId);
        $current = $history['sources'][$item->id.':'.$supplierId] ?? null;
        $candidates = $current === null ? [] : $this->matchingCandidates($current, $history['candidates'][$supplierId] ?? []);
        // A Different-specification row only shows a reference; it has no selected previous.
        $selectedLine = ($row['status'] ?? null) === self::STATUS_DIFFERENT_SPEC ? null : ($row['previous']['line_id'] ?? null);

        $comparable = [];
        $others = [];
        foreach ($candidates as $candidate) {
            if ($this->specEqual($current['spec'], $candidate['spec'])) {
                $comparable[] = $candidate;
            } else {
                $others[] = $candidate;
            }
        }

        $newest = array_slice($comparable, 0, self::DRAWER_LIMIT);
        $pinned = false;
        if ($selectedLine !== null && ! in_array($selectedLine, array_column($newest, 'qi_id'), true)) {
            $selected = collect($comparable)->firstWhere('qi_id', $selectedLine);
            if ($selected !== null) {
                array_unshift($newest, $selected);
                $pinned = true;
            }
        }
        $shown = [...$newest, ...array_slice($others, 0, self::DRAWER_LIMIT)];

        return [
            'records' => array_map(fn (array $candidate) => $this->drawerRecord($candidate, $current, $selectedLine), $shown),
            'total' => count($candidates),
            'comparable_total' => count($comparable),
            'other_total' => count($others),
            'pinned' => $pinned,
            'current' => $current === null ? null : [
                'price_per_kg' => $current['price'], 'currency' => $current['currency'], 'ts' => $current['ts'],
                'shape' => $current['shape'], 'requested_spec' => $current['requested'], 'key_dimension' => $this->keyDimension($current['spec']),
                ...($this->analysisCurrency === null ? [] : [
                    'analysis_currency' => $this->analysisCurrency,
                    'analysis_price' => $this->normalizeTo($this->analysisCurrency, $current)[0],
                    'annual_fx' => $this->annualFx($current),
                ]),
            ],
            'summary' => $current === null ? null : $this->drawerSummary($current, $comparable),
        ];
    }

    /**
     * Lowest and highest native price among same-key records in the current currency and terms.
     *
     * @param  list<array<string, mixed>>  $comparable
     * @return array{currency: string, count: int, lowest: array{price_per_kg: string, ts: string}, highest: array{price_per_kg: string, ts: string}}|null
     */
    private function drawerSummary(array $current, array $comparable): ?array
    {
        // Same rule as the difference column: another proven incoterm or port is left out.
        $same = array_values(array_filter($comparable, fn (array $candidate) => ($this->analysisCurrency !== null || $candidate['currency'] === $current['currency'])
            && $this->basisField($current['incoterm'], $candidate['incoterm'])['state'] !== 'different'
            && $this->basisField($current['port'], $candidate['port'])['state'] !== 'different'));
        if ($same === []) {
            return null;
        }
        if ($this->analysisCurrency !== null) {
            $same = array_values(array_filter(array_map(function (array $candidate): array {
                [$candidate['price']] = $this->normalizeTo($this->analysisCurrency, $candidate, 24);

                return $candidate;
            }, $same), fn (array $candidate) => $candidate['price'] !== null));
            if ($same === []) {
                return null;
            }
        }
        $lowest = $highest = $same[0];
        $scale = $this->analysisCurrency === null ? self::SCALE : 24;
        foreach ($same as $candidate) {
            if (bccomp($candidate['price'], $lowest['price'], $scale) < 0) {
                $lowest = $candidate;
            }
            if (bccomp($candidate['price'], $highest['price'], $scale) > 0) {
                $highest = $candidate;
            }
        }

        return [
            'currency' => $this->analysisCurrency ?? $current['currency'],
            'count' => count($same),
            'lowest' => ['price_per_kg' => Money::normalize($lowest['price'], self::SCALE), 'ts' => $lowest['ts']],
            'highest' => ['price_per_kg' => Money::normalize($highest['price'], self::SCALE), 'ts' => $highest['ts']],
        ];
    }

    /**
     * Chart datasets for one PR Item, each with exactly one declared price basis (C5, E1).
     *
     * @param  array<string, mixed>  $history  output of forPr()
     * @return array{current_previous: array<string, mixed>, quote_trend: array<string, mixed>}
     */
    public function charts(array $history, PrItem $item): array
    {
        $rows = array_values(array_filter($history['rows'], fn (array $row) => $row['pr_item_id'] === $item->id));

        return [
            'current_previous' => $this->currentPreviousChart($rows),
            'quote_trend' => $this->trendChart($rows, $history),
        ];
    }

    // ─── Rows ────────────────────────────────────────────────────────────────

    /**
     * @param  Collection<int, Quotation>|null  $quotations
     * @param  list<array<string, mixed>>  $supplierCandidates
     * @return array<string, mixed>
     */
    private function row(PurchaseRequisition $pr, PrItem $item, array $supplier, ?Collection $quotations, array $supplierCandidates): array
    {
        $row = [
            'pr_item_id' => $item->id,
            'pr_item_hash' => HashidCodec::encode($item->id),
            'supplier' => $supplier,
            'status' => null,
            'indicative' => false,
            'unverified_fields' => [],
            'sources' => [],
            'current' => null,
            'previous' => null,
            'reference_only' => false,
            'spec_diff' => [],
            'key_dimension' => null,
            'basis' => null,
            'comparison' => null,
            'notes' => null,
            'diff' => null,
            'diff_pct' => null,
            'history_count' => null,
            'comparable_count' => null,
            '_current_line' => null,
        ];

        $quotations ??= collect();
        $lines = $quotations->count() === 1
            ? $quotations->first()->items->where('pr_item_id', $item->id)->sortBy('id')->values()
            : collect();

        // C4 #1: duplicated sources are detected before anything else (never collapsed).
        if ($quotations->count() > 1 || $lines->count() > 1) {
            $row['status'] = self::STATUS_AMBIGUOUS;
            $row['sources'] = $quotations->count() > 1
                ? $quotations->map(fn (Quotation $quotation) => $this->sourceRef($quotation, $quotation->items->where('pr_item_id', $item->id)->sortBy('id')->first(), null))->values()->all()
                : $lines->values()->map(fn (QuotationItem $line, int $index) => $this->sourceRef($quotations->first(), $line, $index + 1))->all();

            return $row;
        }

        $quotation = $quotations->first();
        $line = $lines->first();
        // C4 #2: a single source without a valid offer.
        if ($quotation === null || $line === null || $line->is_available === false || $line->price_per_kg === null || bccomp((string) $line->price_per_kg, '0', self::SCALE) <= 0) {
            $row['status'] = self::STATUS_NOT_OFFERED;
            $row['sources'] = $quotation ? [$this->sourceRef($quotation, $line, null)] : [];

            return $row;
        }

        $current = $this->currentLine($pr, $item, $quotation, $line);
        $row['_current_line'] = $current;
        $row['current'] = $this->side($current);
        $row['sources'] = [$this->sourceRef($quotation, $line, null)];

        // C4 #3: the current specification must be fully resolved.
        if (! $current['spec']['complete']) {
            $row['status'] = self::STATUS_SPEC_INCOMPLETE;

            return $row;
        }

        $candidates = $this->matchingCandidates($current, $supplierCandidates);
        $comparable = array_values(array_filter($candidates, fn (array $candidate) => $this->specEqual($current['spec'], $candidate['spec'])));
        $row['history_count'] = count($candidates);
        $row['comparable_count'] = count($comparable);

        $row['key_dimension'] = $this->keyDimension($current['spec']);

        // C4 #4 / #5. A different key dimension has no previous at all: its price is not
        // put next to the current one (it stays readable in the drawer, marked as another spec).
        if ($candidates === []) {
            $row['status'] = self::STATUS_INSUFFICIENT_HISTORY;

            return $row;
        }
        if ($comparable === []) {
            $row['status'] = self::STATUS_DIFFERENT_SPEC;

            return $row;
        }

        $previous = $comparable[0];
        $row['previous'] = $this->side($previous);
        // Same key dimension, other non-key dimensions (width, length, inner diameter).
        $row['spec_diff'] = $this->specDiff($current['spec'], $previous['spec']);
        $row['basis'] = [
            'incoterm' => $this->basisField($current['incoterm'], $previous['incoterm']),
            'port_of_discharge' => $this->basisField($current['port'], $previous['port']),
        ];
        $row['notes'] = [
            'payment_terms' => ['current' => $current['payment_terms'], 'previous' => $previous['payment_terms']],
            'weight_basis' => ['current' => $current['weight_source'], 'previous' => $previous['weight_source']],
        ];

        // C4 #6: a proven commercial difference blocks the percentage.
        if (in_array('different', array_column($row['basis'], 'state'), true)) {
            $row['status'] = self::STATUS_BASIS_DIFFERS;
            $row['reference_only'] = true;

            return $row;
        }

        // C4 #7 / C5: same currency is native; otherwise both sides go to IDR.
        $comparison = $this->tableComparison($current, $previous);
        $row['comparison'] = $comparison;
        if ($comparison['current'] === null || $comparison['previous'] === null) {
            $row['status'] = self::STATUS_FX_UNAVAILABLE;
            $row['reference_only'] = true;

            return $row;
        }

        // Keep positive sub-cent conversions until after percentage calculation.
        $scale = $this->analysisCurrency === null ? self::WORK : 24;
        $currentValue = $this->analysisCurrency === null ? $comparison['current'] : $this->normalizeTo($this->analysisCurrency, $current, $scale)[0];
        $previousValue = $this->analysisCurrency === null ? $comparison['previous'] : $this->normalizeTo($this->analysisCurrency, $previous, $scale)[0];
        $diff = bcsub($currentValue, $previousValue, $scale);
        $row['diff'] = Money::normalize($diff, self::SCALE);
        $row['diff_pct'] = Money::normalize(bcmul(bcdiv($diff, $previousValue, $scale), '100', $scale), self::SCALE);

        // C4 #8 / #9 (D-1): unknown terms keep the percentage but mark it Indicative.
        foreach ($row['basis'] as $field => $state) {
            foreach (['current', 'previous'] as $side) {
                if ($state[$side] === null) {
                    $row['unverified_fields'][] = ['side' => $side, 'field' => $field];
                }
            }
        }
        $row['indicative'] = $row['unverified_fields'] !== [] || $row['spec_diff'] !== [];
        $row['status'] = $row['indicative'] ? self::STATUS_INDICATIVE : self::STATUS_COMPARABLE;

        return $row;
    }

    /**
     * Earlier candidates with the same material identity and shape class, newest first.
     *
     * @param  array<string, mixed>  $current
     * @param  list<array<string, mixed>>  $supplierCandidates
     * @return list<array<string, mixed>>
     */
    private function matchingCandidates(array $current, array $supplierCandidates): array
    {
        $group = $supplierCandidates[$current['shape_class'] ?? ''] ?? [];
        $name = mb_strtolower(trim((string) $current['material_name']));
        // Same identity rule as sameMaterial(): masters when both sides have one, names otherwise.
        $pool = $current['material_master_id'] === null
            ? ($group['name'][$name] ?? [])
            : [
                ...($group['master'][$current['material_master_id']] ?? []),
                ...array_filter($group['name'][$name] ?? [], fn (array $candidate) => $candidate['material_master_id'] === null),
            ];

        $matching = array_values(array_filter($pool, fn (array $candidate) => $candidate['ts'] < $current['ts']
            && self::sameMaterial($current, $candidate)));

        usort($matching, fn (array $a, array $b) => [$b['ts'], $b['quotation_id'], $b['qi_id']] <=> [$a['ts'], $a['quotation_id'], $a['qi_id']]);

        return $matching;
    }

    // ─── Candidate query (C8) ────────────────────────────────────────────────

    /**
     * @param  Collection<int, PrItem>  $items
     * @param  list<int>  $supplierIds
     * @return array<int, list<array<string, mixed>>>
     */
    private function candidates(PurchaseRequisition $pr, Collection $items, array $supplierIds): array
    {
        $masters = $items->pluck('material_master_id')->filter()->unique()->values()->all();
        $names = $items->pluck('material_name')->filter(fn ($name) => trim((string) $name) !== '')->unique()->values()->all();
        if ($supplierIds === [] || ($masters === [] && $names === [])) {
            return [];
        }

        // C8: driven from the PR's material set (pri_material_name_index), then each line's
        // quotation by primary key. STRAIGHT_JOIN (MySQL and MariaDB) keeps that order; left to
        // itself the optimizer started from purchase_requisitions and examined every PR's lines.
        // History = what was actually bought (decided 2026-11-02, replaces D-2): the awarded line
        // of a live PO, or every line of a legacy PO without awards, as PurchaseHistoryPriceService.
        // Price is the frozen ordered price when present; FX is the PO's own snapshot.
        $rows = DB::table('pr_items as pi')
            ->join('quotation_items as qi', 'qi.pr_item_id', '=', 'pi.id')
            ->join('quotations as q', 'q.id', '=', 'qi.quotation_id')
            ->join('purchase_requisitions as pr', 'pr.id', '=', 'q.pr_id')
            ->join('po_quotations as pq', 'pq.quotation_id', '=', 'q.id')
            ->join('purchase_orders as po', 'po.id', '=', 'pq.po_id')
            ->leftJoin('pr_item_awards as a', function ($join): void {
                $join->on('a.quotation_item_id', '=', 'qi.id')->on('a.purchase_order_id', '=', 'po.id');
            })
            ->leftJoin('exchange_rates as er', 'er.id', '=', 'q.exchange_rate_id')
            ->leftJoin('exchange_rates as po_er', 'po_er.id', '=', 'po.exchange_rate_id')
            ->whereIn('q.supplier_id', $supplierIds)
            ->whereIn('q.status', self::STATUSES)
            ->whereNull('q.deleted_at')
            ->whereNull('pr.deleted_at')
            ->whereNull('po.deleted_at')
            ->where('po.status', '<>', 'cancelled')
            ->where(fn ($line) => $line->whereNotNull('a.id')->orWhereNotExists(fn ($awards) => $awards
                ->selectRaw('1')->from('pr_item_awards as po_awards')->whereColumn('po_awards.purchase_order_id', 'po.id')))
            ->where('q.pr_id', '<>', $pr->id)
            ->where(fn ($available) => $available->whereNull('qi.is_available')->orWhere('qi.is_available', true))
            ->whereRaw('(CASE WHEN a.ordered_amount IS NOT NULL THEN a.ordered_price_per_kg ELSE qi.price_per_kg END) > 0')
            ->where(function ($material) use ($masters, $names): void {
                if ($masters !== []) {
                    $material->orWhereIn('pi.material_master_id', $masters);
                }
                if ($names !== []) {
                    $material->orWhereIn('pi.material_name', $names);
                }
            })
            ->selectRaw('STRAIGHT_JOIN q.id as quotation_id')
            // Only what matching, status and the row need; drawer-only fields (notes, amount,
            // quantity, delivery, validity, PO number) are read for at most 21 lines in drawer().
            ->addSelect([
                'q.supplier_id', 'q.status', 'q.currency', 'q.payment_terms',
                'pr.pr_number', 'pr.incoterm', 'pr.port_of_discharge', 'po.po_number',
                'qi.id as qi_id', 'qi.offered_weight_source',
                'qi.available_thickness', 'qi.available_d_inner', 'qi.available_d_outer', 'qi.available_width',
                'qi.available_length', 'qi.available_length_min', 'qi.available_length_max',
                'pi.material_master_id', 'pi.material_name', 'pi.shape',
                'pi.thickness', 'pi.d_inner', 'pi.d_outer', 'pi.width', 'pi.length',
            ])
            ->selectRaw('CASE WHEN a.ordered_amount IS NOT NULL THEN a.ordered_price_per_kg ELSE qi.price_per_kg END as price_per_kg')
            ->selectRaw('COALESCE(po_er.rate_to_idr, er.rate_to_idr) as rate_to_idr')
            ->selectRaw('CASE WHEN po_er.id IS NOT NULL THEN po_er.valid_from ELSE er.valid_from END as rate_valid_from')
            ->selectRaw('COALESCE(q.submitted_at, q.created_at) as ts')
            ->addSelect('po.created_at as rate_timestamp')
            ->get();

        // Indexed once per supplier by shape class, master id and normalized name, so each
        // (PR item, supplier) row reads only its own material group instead of the whole history.
        $index = [];
        foreach ($rows as $row) {
            $line = $this->candidateLine((array) $row);
            $group = &$index[(int) $row->supplier_id][$line['shape_class'] ?? ''];
            if ($line['material_master_id'] !== null) {
                $group['master'][$line['material_master_id']][] = $line;
            }
            $name = mb_strtolower(trim((string) $line['material_name']));
            if ($name !== '') {
                $group['name'][$name][] = $line;
            }
            unset($group);
        }

        return $index;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function candidateLine(array $row): array
    {
        $offered = [];
        foreach (PrItem::DIMENSION_FIELDS as $field) {
            $offered[$field] = $row['available_'.$field];
        }
        $requested = array_intersect_key($row, array_flip(PrItem::DIMENSION_FIELDS));

        return [
            'quotation_id' => (int) $row['quotation_id'],
            'qi_id' => (int) $row['qi_id'],
            'pr_number' => $row['pr_number'],
            'po_number' => $row['po_number'],
            'status' => $row['status'],
            'currency' => $row['currency'],
            'price' => self::fixed($row['price_per_kg']),
            'weight_source' => $row['offered_weight_source'],
            'payment_terms' => $row['payment_terms'],
            'rate' => $row['rate_to_idr'] === null ? null : self::fixed($row['rate_to_idr']),
            'rate_valid_from' => $row['rate_valid_from'] === null ? null : substr((string) $row['rate_valid_from'], 0, 10),
            'incoterm' => $row['incoterm'],
            'port' => $row['port_of_discharge'],
            'ts' => self::timestamp($row['ts']),
            'rate_year' => $this->analysisCurrency === null ? null : app(AnnualExchangeRateResolver::class)->year($row['rate_timestamp']),
            'material_master_id' => $row['material_master_id'] === null ? null : (int) $row['material_master_id'],
            'material_name' => $row['material_name'],
            'shape_class' => self::shapeClass($row['shape']),
            'shape' => $row['shape'],
            'requested' => $requested,
            'spec' => $this->effectiveSpec($row['shape'], $offered, $row['available_length_min'], $row['available_length_max'], $requested),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function currentLine(PurchaseRequisition $pr, PrItem $item, Quotation $quotation, QuotationItem $line): array
    {
        $offered = [];
        $requested = [];
        foreach (PrItem::DIMENSION_FIELDS as $field) {
            $offered[$field] = $line->{'available_'.$field};
            $requested[$field] = $item->{$field};
        }
        $rate = $quotation->exchange_rate;

        return [
            'quotation_id' => (int) $quotation->id,
            'qi_id' => (int) $line->id,
            'pr_number' => $pr->pr_number,
            'status' => $quotation->status,
            'currency' => $quotation->currency,
            'price' => self::fixed($line->price_per_kg),
            'amount' => $line->amount === null ? null : self::fixed($line->amount),
            'available_qty' => $line->available_qty,
            'weight_per_unit' => $line->offered_weight_per_unit ?? $item->weight_needed,
            'weight_source' => $line->offered_weight_source,
            'notes' => $line->notes,
            'payment_terms' => $quotation->payment_terms,
            'estimated_delivery' => $quotation->estimated_delivery?->toDateString(),
            'validity_period' => $quotation->validity_period?->toDateString(),
            'rate' => $rate === null ? null : self::fixed($rate->rate_to_idr),
            'rate_valid_from' => $rate?->valid_from?->toDateString(),
            'incoterm' => $pr->incoterm,
            'port' => $pr->port_of_discharge,
            'po_number' => null,
            'ts' => self::timestamp($quotation->getRawOriginal('submitted_at') ?? $quotation->getRawOriginal('created_at')),
            'rate_year' => $this->analysisCurrency === null ? null : app(AnnualExchangeRateResolver::class)->year($quotation->getRawOriginal('submitted_at') ?? $quotation->getRawOriginal('created_at')),
            'material_master_id' => $item->material_master_id === null ? null : (int) $item->material_master_id,
            'material_name' => $item->material_name,
            'shape_class' => self::shapeClass($item->shape),
            'shape' => $item->shape,
            'requested' => $requested,
            'spec' => $this->effectiveSpec($item->shape, $offered, $line->available_length_min, $line->available_length_max, $requested),
        ];
    }

    // ─── Specification (C2) ──────────────────────────────────────────────────

    /**
     * Effective value per relevant field, mirroring QuotationItem::orderedSpecification():
     * offered value, else an offered length range, else the PR value ("as requested").
     *
     * @param  array<string, mixed>  $offered
     * @param  array<string, mixed>  $requested
     * @return array{canonical: ?string, complete: bool, fields: array<string, array<string, mixed>>}
     */
    private function effectiveSpec(?string $shape, array $offered, mixed $lengthMin, mixed $lengthMax, array $requested): array
    {
        $canonical = PrItem::canonicalShape($shape);
        $fields = [];
        $complete = $canonical !== null;

        foreach (PrItem::relevantDimensionFields($canonical) as $field) {
            if ($field === 'length' && $lengthMin !== null && $lengthMax !== null) {
                $fields[$field] = ['kind' => 'range', 'min' => self::dimension($lengthMin), 'max' => self::dimension($lengthMax), 'source' => 'range'];

                continue;
            }
            $value = self::dimension($offered[$field] ?? null);
            $source = 'offer';
            if ($value === null) {
                $value = self::dimension($requested[$field] ?? null);
                $source = $value === null ? 'unresolved' : 'as_requested';
            }
            if ($value === null) {
                $complete = false;
            }
            $fields[$field] = ['kind' => 'scalar', 'value' => $value, 'source' => $source];
        }

        return ['canonical' => $canonical, 'complete' => $complete, 'fields' => $fields];
    }

    /**
     * Same canonical shape and the same key dimension (thickness for Flat, outer diameter
     * for Round/Hollow, as MaterialReferencePrice::DIMENSION_FIELD_BY_SHAPE) within
     * QuotationItem::AVAILABILITY_TOLERANCE; an unresolved key is never equal. Other
     * dimensions may differ: specDiff() lists them and the row becomes Indicative.
     */
    private function specEqual(array $current, array $candidate): bool
    {
        if ($current['canonical'] === null || $current['canonical'] !== $candidate['canonical']) {
            return false;
        }
        $key = MaterialReferencePrice::DIMENSION_FIELD_BY_SHAPE[$current['canonical']] ?? null;

        return $key !== null && isset($current['fields'][$key]) && $this->fieldEqual($current['fields'][$key], $candidate['fields'][$key] ?? null);
    }

    /**
     * @return array{field: string, value: string}|null
     */
    private function keyDimension(array $spec): ?array
    {
        $key = MaterialReferencePrice::DIMENSION_FIELD_BY_SHAPE[$spec['canonical']] ?? null;

        return $key === null || ! isset($spec['fields'][$key]) ? null : ['field' => $key, 'value' => self::fieldDisplay($spec['fields'][$key])];
    }

    private function fieldEqual(array $a, ?array $b): bool
    {
        if ($b === null || $a['kind'] !== $b['kind']) {
            return false;
        }
        if ($a['kind'] === 'range') {
            return self::within($a['min'], $b['min']) && self::within($a['max'], $b['max']);
        }

        return $a['value'] !== null && $b['value'] !== null && self::within($a['value'], $b['value']);
    }

    /**
     * @return list<array{field: string, current: string, previous: string}>
     */
    private function specDiff(array $current, array $previous): array
    {
        $diff = [];
        foreach ($current['fields'] as $field => $value) {
            $other = $previous['fields'][$field] ?? null;
            if (! $this->fieldEqual($value, $other)) {
                $diff[] = ['field' => $field, 'current' => self::fieldDisplay($value), 'previous' => self::fieldDisplay($other)];
            }
        }

        return $diff;
    }

    // ─── Basis (C3) and currency (C5) ────────────────────────────────────────

    /**
     * @return array{current: ?string, previous: ?string, state: 'same'|'different'|'unknown'}
     */
    private function basisField(?string $current, ?string $previous): array
    {
        $current = $current === null || trim($current) === '' ? null : $current;
        $previous = $previous === null || trim($previous) === '' ? null : $previous;

        return [
            'current' => $current,
            'previous' => $previous,
            'state' => $current === null || $previous === null ? 'unknown' : ($current === $previous ? 'same' : 'different'),
        ];
    }

    /**
     * @return array{currency: string, method: 'native'|'idr_conversion', current: ?string, previous: ?string, fx: list<array<string, mixed>>}
     */
    private function tableComparison(array $current, array $previous): array
    {
        if ($this->analysisCurrency !== null) {
            return [
                'currency' => $this->analysisCurrency, 'method' => 'annual',
                'current' => $this->normalizeTo($this->analysisCurrency, $current)[0],
                'previous' => $this->normalizeTo($this->analysisCurrency, $previous)[0],
                'fx' => [$this->annualFx($current), $this->annualFx($previous)],
            ];
        }
        if ($current['currency'] === $previous['currency']) {
            return ['currency' => $current['currency'], 'method' => 'native', 'current' => $current['price'], 'previous' => $previous['price'], 'fx' => []];
        }

        $fx = [];
        $values = [];
        foreach (['current' => $current, 'previous' => $previous] as $side => $line) {
            [$values[$side], $entry] = $this->toIdr($line);
            $fx[] = ['side' => $side] + $entry;
        }

        return ['currency' => ExchangeRate::CURRENCY_IDR, 'method' => 'idr_conversion', 'current' => $values['current'], 'previous' => $values['previous'], 'fx' => $fx];
    }

    /**
     * IDR is the identity; a foreign price needs its own positive snapshot rate.
     *
     * @return array{0: ?string, 1: array<string, mixed>}
     */
    private function toIdr(array $line): array
    {
        if ($line['currency'] === ExchangeRate::CURRENCY_IDR) {
            return [$line['price'], ['currency' => 'IDR', 'method' => 'identity', 'rate_to_idr' => null, 'valid_from' => null, 'stale' => false]];
        }
        $entry = ['currency' => $line['currency'], 'method' => 'snapshot', 'rate_to_idr' => $line['rate'], 'valid_from' => $line['rate_valid_from'], 'stale' => $this->stale($line)];
        if ($line['rate'] === null || bccomp($line['rate'], '0', self::SCALE) <= 0) {
            return [null, ['method' => 'unavailable'] + $entry];
        }

        return [Money::normalize(bcmul($line['price'], $line['rate'], self::WORK), self::SCALE), $entry];
    }

    /** D-3 (info only): the snapshot is older than the configured days before the quotation date. */
    private function stale(array $line): bool
    {
        if ($line['rate_valid_from'] === null) {
            return false;
        }
        $timezone = config('app.business_timezone', 'Asia/Jakarta');
        $days = (int) config('procurement.historical_comparison.stale_rate_days', 31);
        $quoteDate = CarbonImmutable::parse($line['ts'], 'UTC')->setTimezone($timezone)->startOfDay();

        return CarbonImmutable::parse($line['rate_valid_from'], $timezone)->lt($quoteDate->subDays($days));
    }

    // ─── Charts (C5, E1) ─────────────────────────────────────────────────────

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function currentPreviousChart(array $rows): array
    {
        $plotted = [];
        $excluded = [];
        foreach ($rows as $index => $row) {
            if (! in_array($row['status'], self::CURRENT_PLOTTABLE, true)) {
                $excluded[] = ['supplier' => $row['supplier']['name'], 'series' => 'all', 'reason' => $row['status']];

                continue;
            }
            $plotted[] = ['index' => $index, 'series' => 'current', 'line' => $row['current'], 'indicative' => false];
            if (in_array($row['status'], self::PRICED, true)) {
                $plotted[] = ['index' => $index, 'series' => 'previous', 'line' => $row['previous'], 'indicative' => $row['indicative']];
            } else {
                $excluded[] = ['supplier' => $row['supplier']['name'], 'series' => 'previous', 'reason' => $row['status']];
            }
        }

        $basis = $this->chartBasis(array_column(array_column($plotted, 'line'), 'currency'));
        $labels = array_map(fn (array $row) => $row['supplier']['name'], $rows);
        $series = ['current' => array_fill(0, count($rows), null), 'previous' => array_fill(0, count($rows), null)];
        $meta = ['current' => array_fill(0, count($rows), null), 'previous' => array_fill(0, count($rows), null)];

        foreach ($plotted as $point) {
            [$value, $method] = $this->normalizeTo($basis, $point['line']);
            if ($value === null) {
                $excluded[] = ['supplier' => $labels[$point['index']], 'series' => $point['series'], 'reason' => 'fx_unavailable_chart'];

                continue;
            }
            $series[$point['series']][$point['index']] = $value;
            $meta[$point['series']][$point['index']] = $this->pointMeta($point['line'], $method, $point['indicative']);
        }

        return [
            'basis' => $basis,
            'converted' => $this->isConverted($basis, array_column(array_column($plotted, 'line'), 'currency')),
            'labels' => $labels,
            'series' => $series,
            'meta' => $meta,
            'excluded' => $excluded,
        ];
    }

    /**
     * Per supplier: the current point plus spec-equal earlier lines without a proven
     * basis difference, at their real timestamps; never averaged (C5).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function trendChart(array $rows, array $history): array
    {
        $collected = [];
        foreach ($rows as $row) {
            $current = $history['sources'][$row['pr_item_id'].':'.$row['supplier']['id']] ?? null;
            if ($current === null || ! in_array($row['status'], self::CURRENT_PLOTTABLE, true)) {
                continue;
            }
            $points = [['line' => $current, 'indicative' => false]];
            $counts = ['different_spec' => 0, 'basis_differs' => 0, 'fx_unavailable_chart' => 0];
            foreach ($this->matchingCandidates($current, $history['candidates'][$row['supplier']['id']] ?? []) as $candidate) {
                if (! $this->specEqual($current['spec'], $candidate['spec'])) {
                    $counts['different_spec']++;

                    continue;
                }
                $states = [
                    $this->basisField($current['incoterm'], $candidate['incoterm'])['state'],
                    $this->basisField($current['port'], $candidate['port'])['state'],
                ];
                if (in_array('different', $states, true)) {
                    $counts['basis_differs']++;

                    continue;
                }
                $points[] = ['line' => $candidate, 'indicative' => in_array('unknown', $states, true) || $this->specDiff($current['spec'], $candidate['spec']) !== []];
            }
            $collected[] = ['supplier' => $row['supplier']['name'], 'points' => $points, 'counts' => $counts];
        }

        $currencies = [];
        foreach ($collected as $group) {
            foreach ($group['points'] as $point) {
                $currencies[] = $point['line']['currency'];
            }
        }
        $basis = $this->chartBasis($currencies);

        $series = [];
        $excluded = [];
        foreach ($collected as $group) {
            $points = [];
            foreach ($group['points'] as $point) {
                [$value, $method] = $this->normalizeTo($basis, $point['line']);
                if ($value === null) {
                    $group['counts']['fx_unavailable_chart']++;

                    continue;
                }
                $points[] = [
                    'x' => CarbonImmutable::parse($point['line']['ts'], 'UTC')->getTimestampMs(),
                    'y' => $value,
                ] + $this->pointMeta($point['line'], $method, $point['indicative']);
            }
            usort($points, fn (array $a, array $b) => $a['x'] <=> $b['x']);
            $series[] = ['supplier' => $group['supplier'], 'points' => $points];
            foreach ($group['counts'] as $reason => $count) {
                if ($count > 0) {
                    $excluded[] = ['supplier' => $group['supplier'], 'reason' => $reason, 'count' => $count];
                }
            }
        }

        return ['basis' => $basis, 'converted' => $this->isConverted($basis, $currencies), 'series' => $series, 'excluded' => $excluded];
    }

    /** One declared basis: the shared native currency, otherwise IDR. */
    private function chartBasis(array $currencies): string
    {
        if ($this->analysisCurrency !== null) {
            return $this->analysisCurrency;
        }
        $unique = array_values(array_unique($currencies));

        return count($unique) === 1 ? $unique[0] : ExchangeRate::CURRENCY_IDR;
    }

    /**
     * @return array{0: ?string, 1: string}
     */
    private function normalizeTo(string $basis, array $line, int $scale = self::SCALE): array
    {
        if ($this->analysisCurrency !== null) {
            return [app(AnnualExchangeRateResolver::class)->convert($line['price'], $line['currency'], $basis, $line['rate_year'], $scale), 'annual'];
        }
        if ($line['currency'] === $basis) {
            return [$line['price'], $basis === ExchangeRate::CURRENCY_IDR ? 'identity' : 'native'];
        }
        // Mixed currencies always resolve to an IDR basis.
        [$value, $entry] = $this->toIdr($line);

        return [$value, $entry['method']];
    }

    private function isConverted(string $basis, array $currencies): bool
    {
        if ($this->analysisCurrency !== null) {
            return count(array_filter($currencies, fn ($code) => $code !== $basis)) > 0;
        }

        return $basis === ExchangeRate::CURRENCY_IDR && count(array_unique($currencies)) > 1;
    }

    /**
     * @return array<string, mixed>
     */
    private function pointMeta(array $line, string $method, bool $indicative): array
    {
        return [
            'native' => $line['price_per_kg'] ?? $line['price'],
            'currency' => $line['currency'],
            'method' => $method,
            'rate_to_idr' => $method === 'snapshot' ? ($line['rate'] ?? null) : null,
            'valid_from' => $method === 'snapshot' ? ($line['rate_valid_from'] ?? null) : null,
            'quotation_hash' => $line['quotation_hash'] ?? HashidCodec::encode($line['quotation_id']),
            'pr_number' => $line['pr_number'],
            'status' => $line['status'],
            'ts' => $line['ts'],
            'indicative' => $indicative,
            ...($this->analysisCurrency === null ? [] : ['annual_fx' => $this->annualFx($line)]),
        ];
    }

    // ─── Presentation helpers ────────────────────────────────────────────────

    /**
     * One side of a row; keeps what charts need to normalize without a raw line.
     *
     * @return array<string, mixed>
     */
    private function side(array $line): array
    {
        return [
            'quotation_hash' => HashidCodec::encode($line['quotation_id']),
            'line_id' => $line['qi_id'],
            'pr_number' => $line['pr_number'],
            'po_number' => $line['po_number'] ?? null,
            'status' => $line['status'],
            'price' => $line['price'],
            'price_per_kg' => $line['price'],
            'currency' => $line['currency'],
            'rate' => $line['rate'],
            'rate_valid_from' => $line['rate_valid_from'],
            'ts' => $line['ts'],
            'spec' => $line['spec'],
            'incoterm' => $line['incoterm'],
            'port_of_discharge' => $line['port'],
            'weight_basis' => $line['weight_source'],
            ...($this->analysisCurrency === null ? [] : [
                'rate_year' => $line['rate_year'],
                'analysis_currency' => $this->analysisCurrency,
                'analysis_price' => $this->normalizeTo($this->analysisCurrency, $line)[0],
                'annual_fx' => $this->annualFx($line),
            ]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sourceRef(Quotation $quotation, ?QuotationItem $line, ?int $ordinal): array
    {
        return [
            'quotation_hash' => $quotation->getRouteKey(),
            'status' => $quotation->status,
            'ts' => self::timestamp($quotation->getRawOriginal('submitted_at') ?? $quotation->getRawOriginal('created_at')),
            'line_ordinal' => $ordinal,
            'price_per_kg' => $line?->price_per_kg === null ? null : self::fixed($line->price_per_kg),
            'currency' => $quotation->currency,
            'available' => $line !== null && $line->is_available !== false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * One price-history line. The difference (current vs this record) exists only for the
     * same key dimension and the same commercial basis, with the row table's currency rule (C5).
     *
     * @return array<string, mixed>
     */
    private function drawerRecord(array $candidate, ?array $current, ?int $selectedLine): array
    {
        $specEqual = $current !== null && $this->specEqual($current['spec'], $candidate['spec']);
        $basisDiffers = $current !== null && in_array('different', [
            $this->basisField($current['incoterm'], $candidate['incoterm'])['state'],
            $this->basisField($current['port'], $candidate['port'])['state'],
        ], true);

        $diffPct = null;
        $converted = false;
        if ($specEqual && ! $basisDiffers) {
            $comparison = $this->tableComparison($current, $candidate);
            $scale = $this->analysisCurrency === null ? self::WORK : 24;
            $currentValue = $this->analysisCurrency === null ? $comparison['current'] : $this->normalizeTo($this->analysisCurrency, $current, $scale)[0];
            $previousValue = $this->analysisCurrency === null ? $comparison['previous'] : $this->normalizeTo($this->analysisCurrency, $candidate, $scale)[0];
            if ($currentValue !== null && $previousValue !== null && bccomp($previousValue, '0', $scale) > 0) {
                $diffPct = Money::normalize(bcmul(bcdiv(bcsub($currentValue, $previousValue, $scale), $previousValue, $scale), '100', $scale), self::SCALE);
                $converted = $this->analysisCurrency === null ? $comparison['method'] === 'idr_conversion'
                    : $current['currency'] !== $this->analysisCurrency || $candidate['currency'] !== $this->analysisCurrency;
            }
        }

        return [
            'quotation_hash' => HashidCodec::encode($candidate['quotation_id']),
            'pr_number' => $candidate['pr_number'],
            'po_number' => $candidate['po_number'],
            'status' => $candidate['status'],
            'ts' => $candidate['ts'],
            'selected' => $candidate['qi_id'] === $selectedLine,
            'spec_equal' => $specEqual,
            'key_dimension' => $this->keyDimension($candidate['spec']),
            'shape' => $candidate['shape'],
            'price_per_kg' => $candidate['price'],
            'currency' => $candidate['currency'],
            'incoterm' => $candidate['incoterm'],
            'port_of_discharge' => $candidate['port'],
            'basis_differs' => $basisDiffers,
            'diff_pct' => $diffPct,
            'diff_converted' => $converted,
            ...($this->analysisCurrency === null ? [] : [
                'analysis_currency' => $this->analysisCurrency,
                'analysis_price' => $this->normalizeTo($this->analysisCurrency, $candidate)[0],
                'annual_fx' => $this->annualFx($candidate),
            ]),
        ];
    }

    private function annualFx(array $line): array
    {
        $resolver = app(AnnualExchangeRateResolver::class);
        $year = $line['rate_year'];
        $target = $this->analysisCurrency;
        if ($line['currency'] === $target) {
            return ['year' => $year, 'currency' => $target, 'identity' => true];
        }
        $source = $resolver->rate($line['currency'], $year);
        $targetRate = $resolver->rate($target, $year);

        return ['year' => $year, 'currency' => $line['currency'], 'identity' => false,
            'rate' => $source, 'target' => $target, 'target_rate' => $targetRate,
            'revision' => $resolver->metadata()[$year][$line['currency']]['revision'] ?? null,
        ];
    }

    private static function sameMaterial(array $current, array $candidate): bool
    {
        if ($current['material_master_id'] !== null && $candidate['material_master_id'] !== null) {
            return $current['material_master_id'] === $candidate['material_master_id'];
        }

        $name = mb_strtolower(trim((string) $current['material_name']));

        return $name !== '' && $name === mb_strtolower(trim((string) $candidate['material_name']));
    }

    private static function shapeClass(?string $shape): ?string
    {
        $shape = $shape === null ? '' : strtolower(trim($shape));

        return $shape === '' ? null : $shape;
    }

    private static function dimension(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : self::fixed($value);
    }

    /**
     * DB DECIMAL(x,4) values already arrive as fixed 4-decimal strings; only other shapes
     * go through Money::normalize (same result, without the per-value BCMath cost).
     */
    private static function fixed(mixed $value): string
    {
        $value = (string) $value;

        return preg_match('/^-?\d+\.\d{4}$/', $value) === 1 ? $value : Money::normalize($value, self::SCALE);
    }

    private static function within(?string $a, ?string $b): bool
    {
        if ($a === null || $b === null) {
            return false;
        }
        $difference = bcsub($a, $b, self::SCALE);
        $absolute = str_starts_with($difference, '-') ? substr($difference, 1) : $difference;

        return bccomp($absolute, Money::normalize((string) QuotationItem::AVAILABILITY_TOLERANCE, self::SCALE), self::SCALE) <= 0;
    }

    private static function fieldDisplay(?array $value): string
    {
        if ($value === null) {
            return '-';
        }

        return $value['kind'] === 'range'
            ? self::trimNumber($value['min']).'-'.self::trimNumber($value['max'])
            : self::trimNumber($value['value']);
    }

    private static function trimNumber(?string $value): string
    {
        return $value === null ? '-' : rtrim(rtrim($value, '0'), '.');
    }

    /** UTC storage timestamp as 'Y-m-d H:i:s' (lexically comparable). */
    private static function timestamp(mixed $value): string
    {
        $value = (string) $value;

        // MySQL/MariaDB DATETIME columns already arrive in this form; parse anything else.
        return preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value) === 1
            ? $value
            : CarbonImmutable::parse($value, 'UTC')->format('Y-m-d H:i:s');
    }

    private static function supplierName(Quotation $quotation): string
    {
        $company = trim((string) $quotation->supplier?->supplier?->company_name);

        return $company !== '' ? $company : (string) $quotation->supplier?->name;
    }
}
