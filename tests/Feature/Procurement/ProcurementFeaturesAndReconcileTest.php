<?php

namespace Tests\Feature\Procurement;

use App\Support\Procurement\ProcurementFeatures;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProcurementFeaturesAndReconcileTest extends TestCase
{
    use RefreshDatabase;

    public function test_defaults_keep_the_ordered_quantity_path_and_surplus_off(): void
    {
        $this->assertTrue(ProcurementFeatures::poGenerationEnabled());
        $this->assertFalse(ProcurementFeatures::orderedQuantityWriteEnabled());
        $this->assertFalse(ProcurementFeatures::surplusOffersAllowed());
        $this->assertFalse(ProcurementFeatures::hasInconsistentFlags());
    }

    public function test_surplus_is_only_effective_together_with_the_write_path(): void
    {
        config(['procurement.allow_offer_surplus' => true, 'procurement.ordered_quantity_write_enabled' => false]);
        $this->assertFalse(ProcurementFeatures::surplusOffersAllowed());
        $this->assertTrue(ProcurementFeatures::hasInconsistentFlags());

        config(['procurement.ordered_quantity_write_enabled' => true]);
        $this->assertTrue(ProcurementFeatures::surplusOffersAllowed());
        $this->assertFalse(ProcurementFeatures::hasInconsistentFlags());

        config(['procurement.allow_offer_surplus' => false]);
        $this->assertFalse(ProcurementFeatures::surplusOffersAllowed());
        $this->assertFalse(ProcurementFeatures::hasInconsistentFlags());
    }

    public function test_reconcile_json_reports_the_kill_switch_state(): void
    {
        $this->assertSame(0, Artisan::call('procurement:reconcile', ['--json' => true]));
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($report['metrics']['po_generation_enabled']);

        config(['procurement.po_generation_enabled' => false]);
        Artisan::call('procurement:reconcile', ['--json' => true]);
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertFalse($report['metrics']['po_generation_enabled']);
    }

    public function test_reconcile_is_read_only(): void
    {
        $writes = [];
        DB::listen(function ($query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete|replace|alter|create|drop|truncate)\b/i', $query->sql) === 1) {
                $writes[] = $query->sql;
            }
        });

        $this->artisan('procurement:reconcile')->assertExitCode(0);
        Artisan::call('procurement:reconcile', ['--json' => true]);

        $this->assertSame([], $writes);
    }
}
