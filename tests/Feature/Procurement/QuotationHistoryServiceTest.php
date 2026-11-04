<?php

namespace Tests\Feature\Procurement;

use App\Models\PrItemAward;
use App\Models\Quotation;
use App\Services\Pricing\QuotationHistoryService as Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Golden dataset G1-G28 for the Historical quotation comparison (Gate 1 Rev 3 + E1-E3,
 * section D). Every expected value below is the hand-calculated oracle from the contract,
 * not application output. Purchasing Supervisor sign-off of these values is OUTSTANDING.
 */
class QuotationHistoryServiceTest extends TestCase
{
    use QuotationHistoryFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpQuotationHistory();
    }

    public function test_g1_spec_equal_previous_gives_a_comparable_percentage(): void
    {
        $sa = $this->supplierNamed('S-A');
        $prior = $this->prAt('2026-06-01', [self::skd()]);
        $this->quote($prior, $sa, '2026-06-10', 'USD', 'USD', [3.60], Quotation::STATUS_ACCEPTED);
        $prG = $this->prAt('2026-10-01', [self::skd()]);
        $this->quote($prG, $sa, '2026-10-15', 'USD', 'USD', [3.80]);

        $row = $this->rowFor($this->history($prG), $prG, 0, $sa);

        $this->assertSame(Service::STATUS_COMPARABLE, $row['status']);
        $this->assertSame('3.6000', $row['previous']['price_per_kg']);
        $this->assertSame('0.2000', $row['diff']);
        $this->assertSame('5.5556', $row['diff_pct']); // 0.20 / 3.60
        $this->assertSame('native', $row['comparison']['method']);
        $this->assertSame([1, 1], [$row['history_count'], $row['comparable_count']]);
        $this->assertFalse($row['indicative']);
    }

    public function test_g2_spec_equal_previous_beats_a_more_recent_different_spec(): void
    {
        $sa = $this->supplierNamed('S-A');
        $prior = $this->prAt('2026-06-01', [self::skd()]);
        $this->quote($prior, $sa, '2026-06-10', 'USD', 'USD', [3.60], Quotation::STATUS_ACCEPTED);
        $priorT50 = $this->prAt('2026-09-01', [self::skd(50)]);
        $this->quote($priorT50, $sa, '2026-09-10', 'USD', 'USD', [3.85], Quotation::STATUS_ACCEPTED);
        $prG = $this->prAt('2026-10-01', [self::skd()]);
        $this->quote($prG, $sa, '2026-10-15', 'USD', 'USD', [3.80]);

        $row = $this->rowFor($this->history($prG), $prG, 0, $sa);

        $this->assertSame('3.6000', $row['previous']['price_per_kg']);
        $this->assertSame([2, 1], [$row['history_count'], $row['comparable_count']]);
    }

    public function test_g3_same_material_name_with_another_spec_is_its_own_row(): void
    {
        $sa = $this->supplierNamed('S-A');
        $prior = $this->prAt('2026-06-01', [self::skd()]);
        $this->quote($prior, $sa, '2026-06-10', 'USD', 'USD', [3.60], Quotation::STATUS_ACCEPTED);
        $priorT50 = $this->prAt('2026-09-01', [self::skd(50, 400, 1500)]);
        $this->quote($priorT50, $sa, '2026-09-10', 'USD', 'USD', [3.85], Quotation::STATUS_ACCEPTED);
        $prG = $this->prAt('2026-10-01', [self::skd(), self::skd(50, 400, 1500)]);
        $this->quote($prG, $sa, '2026-10-15', 'USD', 'USD', [3.80, 3.90]);

        $history = $this->history($prG);
        $t30 = $this->rowFor($history, $prG, 0, $sa);
        $t50 = $this->rowFor($history, $prG, 1, $sa);

        $this->assertSame(['3.6000', '5.5556'], [$t30['previous']['price_per_kg'], $t30['diff_pct']]);
        $this->assertSame(['3.8500', '0.0500', '1.2987'], [$t50['previous']['price_per_kg'], $t50['diff'], $t50['diff_pct']]); // 0.05 / 3.85
        $this->assertCount(2, $history['rows']);
    }

    public function test_g4_same_thickness_with_another_width_and_length_is_an_indicative_percentage(): void
    {
        // C2 amended 2026-11-02: only the key dimension (Flat: thickness) must match.
        $sd = $this->supplierNamed('S-D');
        $prior = $this->prAt('2026-06-01', [self::skd()]);
        $this->quote($prior, $sd, '2026-06-10', 'USD', 'USD', [3.60], Quotation::STATUS_ACCEPTED);
        $prG = $this->prAt('2026-10-01', [self::skd(30, 200, 1000)]);
        $this->quote($prG, $sd, '2026-10-15', 'USD', 'USD', [3.80]);

        $row = $this->rowFor($this->history($prG), $prG, 0, $sd);

        $this->assertSame(Service::STATUS_INDICATIVE, $row['status']);
        $this->assertTrue($row['indicative']);
        $this->assertSame([], $row['unverified_fields']);
        $this->assertFalse($row['reference_only']);
        $this->assertSame(['3.6000', '0.2000', '5.5556'], [$row['previous']['price_per_kg'], $row['diff'], $row['diff_pct']]);
        $this->assertSame([
            ['field' => 'width', 'current' => '200', 'previous' => '300'],
            ['field' => 'length', 'current' => '1000', 'previous' => '2000'],
        ], $row['spec_diff']);
        $this->assertSame([1, 1], [$row['history_count'], $row['comparable_count']]);

        $drawer = app(Service::class)->drawer($this->history($prG), $prG->items[0], $sd->id);
        $this->assertSame([[true, true]], array_map(fn (array $record) => [$record['selected'], $record['spec_equal']], $drawer['records']));
    }

    public function test_g4b_another_thickness_never_puts_its_price_next_to_the_current_offer(): void
    {
        // The screenshot case: current Flat 25 x 900 x 2300, only earlier offer 405 x 610 x 4000.
        $sd = $this->supplierNamed('S-D');
        $prior = $this->prAt('2026-06-01', [['material' => 'P20', 'shape' => 'Flat', 'dims' => ['thickness' => 405, 'width' => 610, 'length' => 4000]]]);
        $this->quote($prior, $sd, '2026-06-10', 'USD', 'USD', [38.70], Quotation::STATUS_ACCEPTED);
        $prG = $this->prAt('2026-10-01', [['material' => 'P20', 'shape' => 'Flat', 'dims' => ['thickness' => 25, 'width' => 900, 'length' => 2300]]]);
        $this->quote($prG, $sd, '2026-10-15', 'USD', 'USD', [20.90]);

        $history = $this->history($prG);
        $row = $this->rowFor($history, $prG, 0, $sd);

        $this->assertSame(Service::STATUS_DIFFERENT_SPEC, $row['status']);
        $this->assertNull($row['previous']);
        $this->assertSame([], $row['spec_diff']);
        $this->assertNull($row['diff_pct']);
        $this->assertSame(['field' => 'thickness', 'value' => '25'], $row['key_dimension']);
        $this->assertSame([1, 0], [$row['history_count'], $row['comparable_count']]);

        // Still readable in the drawer, marked as another specification and never selected.
        $drawer = app(Service::class)->drawer($history, $prG->items[0], $sd->id);
        $this->assertSame([[false, false]], array_map(fn (array $record) => [$record['selected'], $record['spec_equal']], $drawer['records']));
        $this->assertFalse($drawer['pinned']);

        $excluded = app(Service::class)->charts($history, $prG->items[0])['current_previous']['excluded'];
        $this->assertSame([['supplier' => $row['supplier']['name'], 'series' => 'previous', 'reason' => Service::STATUS_DIFFERENT_SPEC]], $excluded);
    }

    public function test_g5_cross_currency_converts_both_sides_at_their_own_snapshot(): void
    {
        $sb = $this->supplierNamed('S-B');
        $prior = $this->prAt('2026-08-01', [self::round('S45C', 60)]);
        $this->quote($prior, $sb, '2026-08-12', 'CNY', 'CNY', [13.20], Quotation::STATUS_ACCEPTED);
        $prG = $this->prAt('2026-10-01', [self::round('S45C', 60)]);
        $this->quote($prG, $sb, '2026-10-15', 'JPY', 'JPY', [210]);

        $row = $this->rowFor($this->history($prG), $prG, 0, $sb);

        $this->assertSame(Service::STATUS_COMPARABLE, $row['status']);
        $this->assertSame('idr_conversion', $row['comparison']['method']);
        $this->assertSame(['22680.0000', '29700.0000'], [$row['comparison']['current'], $row['comparison']['previous']]); // 210 x 108, 13.20 x 2,250
        $this->assertSame(['-7020.0000', '-23.6364'], [$row['diff'], $row['diff_pct']]);
        $this->assertSame([true, true], array_column($row['comparison']['fx'], 'stale'), 'E2: both snapshots are older than 31 days before their quotes.');
        $this->assertSame(['snapshot', 'snapshot'], array_column($row['comparison']['fx'], 'method'));
    }

    public function test_g6_a_missing_foreign_snapshot_is_fx_unavailable_never_one_or_zero(): void
    {
        $sb = $this->supplierNamed('S-B');
        $prior = $this->prAt('2026-08-01', [self::round('S45C', 60)]);
        $this->quote($prior, $sb, '2026-08-12', 'CNY', null, [13.20], Quotation::STATUS_ACCEPTED);
        $prG = $this->prAt('2026-10-01', [self::round('S45C', 60)]);
        $this->quote($prG, $sb, '2026-10-15', 'JPY', 'JPY', [210]);

        $row = $this->rowFor($this->history($prG), $prG, 0, $sb);

        $this->assertSame(Service::STATUS_FX_UNAVAILABLE, $row['status']);
        $this->assertSame('13.2000', $row['previous']['price_per_kg']);
        $this->assertTrue($row['reference_only']);
        $this->assertNull($row['diff_pct']);
        $this->assertNull($row['comparison']['previous']);
        $this->assertSame([true, true], [$row['comparison']['fx'][0]['stale'], $row['comparison']['fx'][1]['method'] === 'unavailable']);
    }

    public function test_g7_and_g8_different_thickness_and_different_shape(): void
    {
        $sa = $this->supplierNamed('S-A');
        $prior = $this->prAt('2026-08-01', [
            ['material' => 'SS400', 'shape' => 'Flat', 'dims' => ['thickness' => 16, 'width' => 1500, 'length' => 6000]],
            ['material' => 'SUS304', 'shape' => 'Flat', 'dims' => ['thickness' => 20, 'width' => 1219, 'length' => 2438]],
        ]);
        $this->quote($prior, $sa, '2026-08-03', 'USD', 'USD', [1.18, 4.40], Quotation::STATUS_ACCEPTED);
        $prG = $this->prAt('2026-10-01', [
            ['material' => 'SS400', 'shape' => 'Flat', 'dims' => ['thickness' => 12, 'width' => 1500, 'length' => 6000]],
            self::round('SUS304', 40, 3000),
        ]);
        $this->quote($prG, $sa, '2026-10-15', 'USD', 'USD', [1.15, 4.50]);

        $history = $this->history($prG);
        $g7 = $this->rowFor($history, $prG, 0, $sa);
        $g8 = $this->rowFor($history, $prG, 1, $sa);

        $this->assertSame([Service::STATUS_DIFFERENT_SPEC, null, null], [$g7['status'], $g7['previous'], $g7['diff_pct']]);
        $this->assertSame([Service::STATUS_INSUFFICIENT_HISTORY, null, 0], [$g8['status'], $g8['previous'], $g8['history_count']]);
    }

    public function test_g9_and_g10_not_offered_and_insufficient_history_with_a_single_source(): void
    {
        $sc = $this->supplierNamed('S-C');
        $prG = $this->prAt('2026-10-01', [
            ['material' => '1.2738', 'shape' => 'Flat', 'dims' => ['thickness' => 100, 'width' => 100, 'length' => 100]],
            ['material' => 'SKD61', 'shape' => 'Flat', 'dims' => ['thickness' => 210, 'width' => 320, 'length' => 1000]],
            self::round('S45C', 60),
        ]);
        // G9a: unavailable line; G9b: no line at all for the third item.
        $this->quote($prG, $sc, '2026-10-15', 'IDR', null, [['price' => null, 'available' => false], 21000]);

        $history = $this->history($prG);

        $this->assertSame(Service::STATUS_NOT_OFFERED, $this->rowFor($history, $prG, 0, $sc)['status']);
        $this->assertSame(Service::STATUS_INSUFFICIENT_HISTORY, $this->rowFor($history, $prG, 1, $sc)['status']);
        $g9b = $this->rowFor($history, $prG, 2, $sc);
        $this->assertSame(Service::STATUS_NOT_OFFERED, $g9b['status']);
        $this->assertCount(1, $g9b['sources'], 'A single source is never Ambiguous.');
    }

    public function test_g11_only_purchased_lines_are_history_never_a_newer_rejected_offer(): void
    {
        // Decided 2026-11-02 (replaces D-2): the previous is the last price actually bought (PO).
        $sa = $this->supplierNamed('S-A');
        $p1 = $this->prAt('2026-06-01', [self::skd()]);
        $this->quote($p1, $sa, '2026-06-10', 'USD', 'USD', [3.60], Quotation::STATUS_ACCEPTED);
        $p2 = $this->prAt('2026-06-25', [self::skd()]);
        $this->quote($p2, $sa, '2026-07-01', 'USD', 'USD', [3.50], Quotation::STATUS_REJECTED);
        $p3 = $this->prAt('2026-07-25', [self::skd()]);
        $this->quote($p3, $sa, '2026-08-01', 'USD', 'USD', [3.40]); // submitted, never bought
        $prG = $this->prAt('2026-10-01', [self::skd()]);
        $this->quote($prG, $sa, '2026-10-15', 'USD', 'USD', [3.80]);

        $row = $this->rowFor($this->history($prG), $prG, 0, $sa);

        $this->assertSame(Service::STATUS_COMPARABLE, $row['status']);
        $this->assertSame(['accepted', '3.6000', 'PO/G/'.Quotation::query()->where('pr_id', $p1->id)->value('id')], [$row['previous']['status'], $row['previous']['price_per_kg'], $row['previous']['po_number']]);
        $this->assertSame(['0.2000', '5.5556'], [$row['diff'], $row['diff_pct']]);
        $this->assertSame([1, 1], [$row['history_count'], $row['comparable_count']]);
        $this->assertSame(['submitted', 'accepted'], Quotation::AWARD_ELIGIBLE_STATUSES, 'Award eligibility is unchanged.');
    }

    public function test_g11b_awarded_po_counts_only_the_awarded_line_at_its_ordered_price_and_skips_cancelled_or_unbought(): void
    {
        $sa = $this->supplierNamed('S-A');
        // Awarded PO: only the SKD11 line was awarded, at a frozen ordered price of 3.55.
        $p1 = $this->prAt('2026-06-01', [self::skd(), self::round('S45C', 60)]);
        $q1 = $this->quote($p1, $sa, '2026-06-10', 'USD', 'USD', [3.60, 2.00], Quotation::STATUS_SUBMITTED);
        $po = $this->purchase($q1);
        $line = $q1->items->firstWhere('pr_item_id', $p1->items[0]->id);
        PrItemAward::query()->forceCreate([
            'pr_id' => $p1->id, 'pr_item_id' => $p1->items[0]->id, 'quotation_id' => $q1->id, 'quotation_item_id' => $line->id,
            'supplier_id' => $sa->id, 'purchase_order_id' => $po->id, 'awarded_by' => $this->purchasingUser->id, 'awarded_at' => now(),
            'ordered_qty' => 10, 'ordered_weight_per_unit' => 5, 'ordered_total_weight_kg' => 50, 'ordered_price_per_kg' => 3.55, 'ordered_amount' => 177.5,
        ]);
        // Newer accepted quotation without a PO, and a newer one whose PO was cancelled.
        $p2 = $this->prAt('2026-07-01', [self::skd(), self::round('S45C', 60)]);
        $q2 = $this->quote($p2, $sa, '2026-07-10', 'USD', 'USD', [3.20, 1.90], Quotation::STATUS_SUBMITTED);
        $q2->forceFill(['status' => Quotation::STATUS_ACCEPTED])->save();
        $p3 = $this->prAt('2026-08-01', [self::skd()]);
        $q3 = $this->quote($p3, $sa, '2026-08-10', 'USD', 'USD', [3.10], Quotation::STATUS_SUBMITTED);
        $this->purchase($q3, 'cancelled');

        $prG = $this->prAt('2026-10-01', [self::skd(), self::round('S45C', 60)]);
        $this->quote($prG, $sa, '2026-10-15', 'USD', 'USD', [3.80, 2.10]);
        $history = $this->history($prG);
        $skd = $this->rowFor($history, $prG, 0, $sa);
        $round = $this->rowFor($history, $prG, 1, $sa);

        $this->assertSame(['3.5500', 1], [$skd['previous']['price_per_kg'], $skd['history_count']]);
        $this->assertSame([Service::STATUS_INSUFFICIENT_HISTORY, 0], [$round['status'], $round['history_count']], 'The unawarded line of an awarded PO is not a purchase.');
    }

    public function test_g12_draft_revision_requested_and_deleted_records_are_ignored(): void
    {
        $sa = $this->supplierNamed('S-A');
        $p1 = $this->prAt('2026-06-01', [self::skd()]);
        $this->quote($p1, $sa, '2026-06-10', 'USD', 'USD', [3.60], Quotation::STATUS_ACCEPTED);
        foreach ([Quotation::STATUS_DRAFT, Quotation::STATUS_REVISION_REQUESTED, 'deleted', 'deleted_pr'] as $kind) {
            $pr = $this->prAt('2026-07-25', [self::skd()]);
            $quotation = $this->quote($pr, $sa, '2026-08-01', 'USD', 'USD', [3.10], str_starts_with($kind, 'deleted') ? Quotation::STATUS_ACCEPTED : $kind);
            if ($kind === 'deleted') {
                $quotation->delete();
            }
            if ($kind === 'deleted_pr') {
                $pr->delete();
            }
        }
        $prG = $this->prAt('2026-10-01', [self::skd()]);
        $this->quote($prG, $sa, '2026-10-15', 'USD', 'USD', [3.80]);

        $row = $this->rowFor($this->history($prG), $prG, 0, $sa);

        $this->assertSame(['3.6000', 1], [$row['previous']['price_per_kg'], $row['history_count']]);
    }

    public function test_g13_weight_basis_is_a_note_only(): void
    {
        $sa = $this->supplierNamed('S-A');
        $p1 = $this->prAt('2026-06-01', [self::skd()]);
        $this->quote($p1, $sa, '2026-06-10', 'USD', 'USD', [['price' => 3.60, 'weight_source' => 'estimated']], Quotation::STATUS_ACCEPTED);
        $prG = $this->prAt('2026-10-01', [self::skd()]);
        $this->quote($prG, $sa, '2026-10-15', 'USD', 'USD', [3.80]);

        $row = $this->rowFor($this->history($prG), $prG, 0, $sa);

        $this->assertSame([Service::STATUS_COMPARABLE, '5.5556'], [$row['status'], $row['diff_pct']]);
        $this->assertSame(['current' => 'auto', 'previous' => 'estimated'], $row['notes']['weight_basis']);
    }

    public function test_g14_trend_plots_real_dates_without_averaging(): void
    {
        $sa = $this->supplierNamed('S-A');
        $p1 = $this->prAt('2026-05-25', [self::skd()]);
        $this->quote($p1, $sa, '2026-06-03', 'USD', 'USD', [3.55], Quotation::STATUS_ACCEPTED);
        $p2 = $this->prAt('2026-06-01', [self::skd()]);
        $this->quote($p2, $sa, '2026-06-10', 'USD', 'USD', [3.60], Quotation::STATUS_ACCEPTED);
        $prG = $this->prAt('2026-10-01', [self::skd()]);
        $this->quote($prG, $sa, '2026-10-15', 'USD', 'USD', [3.80]);

        $history = $this->history($prG);
        $trend = app(Service::class)->charts($history, $prG->items[0])['quote_trend'];

        $this->assertSame('USD', $trend['basis']);
        $this->assertFalse($trend['converted']);
        $this->assertSame(['3.5500', '3.6000', '3.8000'], array_column($trend['series'][0]['points'], 'y'));
        $this->assertSame([
            $this->at('2026-06-03')->getTimestampMs(),
            $this->at('2026-06-10')->getTimestampMs(),
            $this->at('2026-10-15')->getTimestampMs(),
        ], array_column($trend['series'][0]['points'], 'x'));
    }

    public function test_g16_resubmission_dates_the_current_offer(): void
    {
        $sa = $this->supplierNamed('S-A');
        $p1 = $this->prAt('2026-06-01', [self::skd()]);
        $this->quote($p1, $sa, '2026-06-10', 'USD', 'USD', [3.60], Quotation::STATUS_ACCEPTED);
        $prG = $this->prAt('2026-10-01', [self::skd()]);
        $this->quote($prG, $sa, '2026-10-18', 'USD', 'USD', [3.80]);

        $row = $this->rowFor($this->history($prG), $prG, 0, $sa);

        $this->assertSame($this->at('2026-10-18')->format('Y-m-d H:i:s'), $row['current']['ts']);
        $this->assertSame(Service::STATUS_COMPARABLE, $row['status']);
    }

    public function test_g17_a_scalar_length_against_an_offered_range_is_indicative(): void
    {
        $sa = $this->supplierNamed('S-A');
        $p1 = $this->prAt('2026-06-01', [self::skd()]);
        $this->quote($p1, $sa, '2026-06-10', 'USD', 'USD', [['price' => 3.60, 'range' => [1800, 2200]]], Quotation::STATUS_ACCEPTED);
        $prG = $this->prAt('2026-10-01', [self::skd()]);
        $this->quote($prG, $sa, '2026-10-15', 'USD', 'USD', [3.80]);

        $row = $this->rowFor($this->history($prG), $prG, 0, $sa);

        $this->assertSame(Service::STATUS_INDICATIVE, $row['status']);
        $this->assertSame([['field' => 'length', 'current' => '2000', 'previous' => '1800-2200']], $row['spec_diff']);
        $this->assertSame('5.5556', $row['diff_pct']);
    }

    public function test_g18_a_silent_legacy_offer_is_as_requested_and_labelled(): void
    {
        $sa = $this->supplierNamed('S-A');
        $p1 = $this->prAt('2026-06-01', [self::skd()]);
        $this->quote($p1, $sa, '2026-06-10', 'USD', 'USD', [['price' => 3.60, 'offered' => null]], Quotation::STATUS_ACCEPTED);
        $prG = $this->prAt('2026-10-01', [self::skd()]);
        $this->quote($prG, $sa, '2026-10-15', 'USD', 'USD', [3.80]);

        $row = $this->rowFor($this->history($prG), $prG, 0, $sa);

        $this->assertSame([Service::STATUS_COMPARABLE, '5.5556'], [$row['status'], $row['diff_pct']]);
        $this->assertSame(['as_requested', 'as_requested', 'as_requested'], array_column($row['previous']['spec']['fields'], 'source'));
        $this->assertSame(['offer', 'offer', 'offer'], array_column($row['current']['spec']['fields'], 'source'));
    }

    public function test_g19_a_proven_incoterm_difference_blocks_the_percentage(): void
    {
        $sa = $this->supplierNamed('S-A');
        $p1 = $this->prAt('2026-06-01', [self::skd()], 'FOB');
        $this->quote($p1, $sa, '2026-06-10', 'USD', 'USD', [3.60], Quotation::STATUS_ACCEPTED);
        $prG = $this->prAt('2026-10-01', [self::skd()]);
        $this->quote($prG, $sa, '2026-10-15', 'USD', 'USD', [3.80]);

        $row = $this->rowFor($this->history($prG), $prG, 0, $sa);

        $this->assertSame(Service::STATUS_BASIS_DIFFERS, $row['status']);
        $this->assertTrue($row['reference_only']);
        $this->assertSame(['current' => 'CIF', 'previous' => 'FOB', 'state' => 'different'], $row['basis']['incoterm']);
        $this->assertNull($row['diff_pct']);
    }

    public function test_g20_missing_previous_terms_give_an_indicative_percentage(): void
    {
        $sa = $this->supplierNamed('S-A');
        $p1 = $this->prAt('2026-06-01', [self::skd()], null, null);
        $this->quote($p1, $sa, '2026-06-10', 'USD', 'USD', [3.60], Quotation::STATUS_ACCEPTED);
        $prG = $this->prAt('2026-10-01', [self::skd()]);
        $this->quote($prG, $sa, '2026-10-15', 'USD', 'USD', [3.80]);

        $row = $this->rowFor($this->history($prG), $prG, 0, $sa);

        $this->assertSame(Service::STATUS_INDICATIVE, $row['status']);
        $this->assertTrue($row['indicative']);
        $this->assertSame(['0.2000', '5.5556'], [$row['diff'], $row['diff_pct']]);
        $this->assertSame([['side' => 'previous', 'field' => 'incoterm'], ['side' => 'previous', 'field' => 'port_of_discharge']], $row['unverified_fields']);
    }

    public function test_g21_two_participating_quotations_are_ambiguous_on_every_item(): void
    {
        $se = $this->supplierNamed('S-E');
        $prior = $this->prAt('2026-06-01', [self::skd()]);
        $this->quote($prior, $se, '2026-06-10', 'USD', 'USD', [3.90], Quotation::STATUS_ACCEPTED);
        $prG = $this->prAt('2026-10-01', [self::skd(), self::round('S45C', 60)]);
        $this->quote($prG, $se, '2026-10-12', 'USD', 'USD', [4.00]);
        $this->quote($prG, $se, '2026-10-14', 'USD', 'USD', [4.10, 2.20], Quotation::STATUS_ACCEPTED);

        $history = $this->history($prG);
        $this->assertCount(2, $history['rows'], 'One row per (PR item, supplier), never one per quotation.');
        foreach ([0, 1] as $index) {
            $row = $this->rowFor($history, $prG, $index, $se);
            $this->assertSame(Service::STATUS_AMBIGUOUS, $row['status'], 'Ambiguity wins over Not offered.');
            $this->assertCount(2, $row['sources']);
            $this->assertNull($row['current']);
            $this->assertNull($row['previous']);
            $this->assertNull($row['diff_pct']);
        }
        $s45c = $this->rowFor($history, $prG, 1, $se);
        $this->assertSame([[null, false], ['2.2000', true]], array_map(fn (array $source) => [$source['price_per_kg'], $source['available']], $s45c['sources']));
    }

    public function test_g22_a_rejected_current_quotation_keeps_its_row(): void
    {
        $sa = $this->supplierNamed('S-A');
        $p1 = $this->prAt('2026-06-01', [self::skd()]);
        $this->quote($p1, $sa, '2026-06-10', 'USD', 'USD', [3.60], Quotation::STATUS_ACCEPTED);
        $prG = $this->prAt('2026-10-01', [self::skd()]);
        $this->quote($prG, $sa, '2026-10-15', 'USD', 'USD', [3.80], Quotation::STATUS_REJECTED);

        $row = $this->rowFor($this->history($prG), $prG, 0, $sa);

        $this->assertSame([Service::STATUS_COMPARABLE, 'rejected', '5.5556'], [$row['status'], $row['current']['status'], $row['diff_pct']]);
    }

    public function test_g23_g24_current_side_states_come_before_history(): void
    {
        $sa = $this->supplierNamed('S-A');
        $prL = $this->prAt('2026-09-01', [
            ['material' => 'SKD11', 'shape' => null, 'dims' => ['thickness' => 30]],
            ['material' => 'SKD11', 'shape' => 'Square', 'dims' => ['thickness' => 30]],
            self::skd(),
        ], null, null);
        $this->quote($prL, $sa, '2026-09-05', 'USD', 'USD', [3.80, 3.80, 3.80]);

        $history = $this->history($prL);

        $this->assertSame(Service::STATUS_SPEC_INCOMPLETE, $this->rowFor($history, $prL, 0, $sa)['status']);
        $this->assertSame(Service::STATUS_SPEC_INCOMPLETE, $this->rowFor($history, $prL, 1, $sa)['status']);
        $this->assertSame(Service::STATUS_INSUFFICIENT_HISTORY, $this->rowFor($history, $prL, 2, $sa)['status'], 'Missing history is never a basis status.');
    }

    public function test_g25_legacy_terms_on_both_sides_are_indicative(): void
    {
        $sa = $this->supplierNamed('S-A');
        $p1 = $this->prAt('2026-06-01', [self::skd()], null, null);
        $this->quote($p1, $sa, '2026-06-10', 'USD', 'USD', [3.60], Quotation::STATUS_ACCEPTED);
        $prL = $this->prAt('2026-09-01', [self::skd()], null, null);
        $this->quote($prL, $sa, '2026-09-05', 'USD', 'USD', [3.80]);

        $row = $this->rowFor($this->history($prL), $prL, 0, $sa);

        $this->assertSame([Service::STATUS_INDICATIVE, '5.5556'], [$row['status'], $row['diff_pct']]);
        $this->assertCount(4, $row['unverified_fields']);
    }

    public function test_g26_mixed_currency_chart_keeps_native_idr_and_never_plots_zero(): void
    {
        [$sa, $sb, $sc, $sd] = [$this->supplierNamed('S-A'), $this->supplierNamed('S-B'), $this->supplierNamed('S-C'), $this->supplierNamed('S-D')];
        $prior = $this->prAt('2026-08-01', [self::skd()]);
        $this->quote($prior, $sa, '2026-08-05', 'USD', 'USD', [3.60], Quotation::STATUS_ACCEPTED);
        $this->quote($prior, $sb, '2026-08-12', 'CNY', 'CNY', [13.20], Quotation::STATUS_ACCEPTED);
        $this->quote($prior, $sd, '2026-08-14', 'USD', null, [3.70], Quotation::STATUS_ACCEPTED);
        $prG = $this->prAt('2026-10-01', [self::skd()]);
        $this->quote($prG, $sa, '2026-10-15', 'USD', 'USD', [3.80]);
        $this->quote($prG, $sb, '2026-10-15', 'JPY', 'JPY', [210]);
        $this->quote($prG, $sc, '2026-10-15', 'IDR', null, [60000]);
        $this->quote($prG, $sd, '2026-10-15', 'USD', null, [3.90]);

        $history = $this->history($prG);
        $this->assertSame('5.5556', $this->rowFor($history, $prG, 0, $sa)['diff_pct']);
        $this->assertSame('-23.6364', $this->rowFor($history, $prG, 0, $sb)['diff_pct']);
        $this->assertSame(Service::STATUS_INSUFFICIENT_HISTORY, $this->rowFor($history, $prG, 0, $sc)['status']);
        $sdRow = $this->rowFor($history, $prG, 0, $sd);
        $this->assertSame([Service::STATUS_COMPARABLE, '0.2000', '5.4054'], [$sdRow['status'], $sdRow['diff'], $sdRow['diff_pct']], 'Same currency stays native without snapshots (0.20 / 3.70).');

        $chart = app(Service::class)->charts($history, $prG->items[0])['current_previous'];
        $this->assertSame('IDR', $chart['basis']);
        $this->assertTrue($chart['converted']);
        $this->assertSame(['S-A', 'S-B', 'S-C', 'S-D'], $chart['labels']);
        $this->assertSame(['67260.0000', '22680.0000', '60000.0000', null], $chart['series']['current']); // 3.80 x 17,700; 210 x 108; IDR identity
        $this->assertSame(['63720.0000', '29700.0000', null, null], $chart['series']['previous']); // 3.60 x 17,700; 13.20 x 2,250
        $this->assertSame('identity', $chart['meta']['current'][2]['method']);
        $this->assertNotContains('0.0000', array_filter([...$chart['series']['current'], ...$chart['series']['previous']]));
        $this->assertContains(['supplier' => 'S-C', 'series' => 'previous', 'reason' => Service::STATUS_INSUFFICIENT_HISTORY], $chart['excluded']);
        $this->assertContains(['supplier' => 'S-D', 'series' => 'current', 'reason' => 'fx_unavailable_chart'], $chart['excluded']);
        $this->assertContains(['supplier' => 'S-D', 'series' => 'previous', 'reason' => 'fx_unavailable_chart'], $chart['excluded']);
    }

    public function test_e1_statuses_1_to_3_plot_nothing_and_4_to_7_plot_current_only(): void
    {
        [$sa, $se] = [$this->supplierNamed('S-A'), $this->supplierNamed('S-E')];
        $p1 = $this->prAt('2026-06-01', [self::skd()], 'FOB');
        $this->quote($p1, $sa, '2026-06-10', 'USD', 'USD', [3.60], Quotation::STATUS_ACCEPTED);
        $prG = $this->prAt('2026-10-01', [self::skd()]);
        $this->quote($prG, $sa, '2026-10-15', 'USD', 'USD', [3.80]);
        $this->quote($prG, $se, '2026-10-12', 'USD', 'USD', [4.00]);
        $this->quote($prG, $se, '2026-10-14', 'USD', 'USD', [4.10]);

        $chart = app(Service::class)->charts($this->history($prG), $prG->items[0])['current_previous'];

        $this->assertSame(['3.8000', null], $chart['series']['current'], 'Basis differs plots Current only; Ambiguous plots nothing.');
        $this->assertSame([null, null], $chart['series']['previous']);
        $this->assertContains(['supplier' => 'S-E', 'series' => 'all', 'reason' => Service::STATUS_AMBIGUOUS], $chart['excluded']);
    }

    public function test_g27_two_lines_for_one_item_are_ambiguous_for_that_item_only(): void
    {
        $sf = $this->supplierNamed('S-F');
        $prG = $this->prAt('2026-10-01', [self::skd(), self::round('S45C', 60)]);
        $this->quote($prG, $sf, '2026-10-15', 'USD', 'USD', [['price' => 3.70, 'duplicate' => true, 'duplicate_price' => 3.75], 2.10]);

        $history = $this->history($prG);
        $skd = $this->rowFor($history, $prG, 0, $sf);

        $this->assertSame(Service::STATUS_AMBIGUOUS, $skd['status']);
        $this->assertSame([[1, '3.7000'], [2, '3.7500']], array_map(fn (array $source) => [$source['line_ordinal'], $source['price_per_kg']], $skd['sources']));
        $this->assertSame(1, count(array_unique(array_column($skd['sources'], 'quotation_hash'))));
        $this->assertSame(Service::STATUS_INSUFFICIENT_HISTORY, $this->rowFor($history, $prG, 1, $sf)['status']);
    }

    public function test_g28_selected_previous_outside_the_20_newest_is_pinned_in_the_drawer(): void
    {
        $sg = $this->supplierNamed('S-G');
        $p1 = $this->prAt('2026-06-01', [self::skd()]);
        $this->quote($p1, $sg, '2026-06-10', 'USD', 'USD', [3.60], Quotation::STATUS_ACCEPTED);
        for ($day = 0; $day < 22; $day++) {
            $date = $this->at('2026-06-11')->addDays($day * 5)->setTimezone('Asia/Jakarta')->toDateString();
            $pr = $this->prAt($date, [self::skd(50)]);
            $this->quote($pr, $sg, $date, 'USD', 'USD', [3.85], Quotation::STATUS_ACCEPTED);
        }
        $prG = $this->prAt('2026-10-01', [self::skd()]);
        $this->quote($prG, $sg, '2026-10-15', 'USD', 'USD', [3.80]);

        $history = $this->history($prG);
        $row = $this->rowFor($history, $prG, 0, $sg);
        $this->assertSame([Service::STATUS_COMPARABLE, '3.6000', '5.5556'], [$row['status'], $row['previous']['price_per_kg'], $row['diff_pct']]);
        $this->assertSame([23, 1], [$row['history_count'], $row['comparable_count']]);
        $this->assertNotNull($row['previous']['quotation_hash']);

        // Same-size records come first and are never crowded out by the 22 other-size ones.
        $drawer = app(Service::class)->drawer($history, $prG->items[0], $sg->id);
        $this->assertCount(21, $drawer['records']);
        $this->assertSame([23, 1, 22], [$drawer['total'], $drawer['comparable_total'], $drawer['other_total']]);
        $this->assertFalse($drawer['pinned']);
        $this->assertTrue($drawer['records'][0]['selected']);
        $this->assertSame(['3.6000', '5.5556'], [$drawer['records'][0]['price_per_kg'], $drawer['records'][0]['diff_pct']]);
        $this->assertSame(1, collect($drawer['records'])->where('selected', true)->count());
        // Another size never carries a difference.
        $this->assertSame([null], collect($drawer['records'])->where('spec_equal', false)->pluck('diff_pct')->unique()->values()->all());
        $this->assertSame(['currency' => 'USD', 'count' => 1, 'lowest' => '3.6000', 'highest' => '3.6000'], [
            'currency' => $drawer['summary']['currency'], 'count' => $drawer['summary']['count'],
            'lowest' => $drawer['summary']['lowest']['price_per_kg'], 'highest' => $drawer['summary']['highest']['price_per_kg'],
        ]);
    }
}
