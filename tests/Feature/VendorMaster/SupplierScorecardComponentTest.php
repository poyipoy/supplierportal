<?php

namespace Tests\Feature\VendorMaster;

use App\Data\VendorMaster\SupplierScorecardData;
use Tests\TestCase;

class SupplierScorecardComponentTest extends TestCase
{
    public function test_scorecard_component_removes_grade_badge_pill_and_includes_accessibility_attributes(): void
    {
        $scorecard = new SupplierScorecardData(
            supplierUserId: 101,
            scope: 'import',
            compositeScore: '85.50',
            grade: 'A',
            hasData: true,
            metrics: [
                'otd' => [
                    'key' => 'otd',
                    'label_key' => 'scorecard.metrics.otd',
                    'percentage' => '90.00',
                    'numerator' => 9,
                    'denominator' => 10,
                    'has_data' => true,
                ],
                'quality' => [
                    'key' => 'quality',
                    'label_key' => 'scorecard.metrics.quality',
                    'percentage' => '100.00',
                    'numerator' => 5,
                    'denominator' => 5,
                    'has_data' => true,
                ],
            ],
        );

        $view = $this->blade('<x-ui.supplier-scorecard :scorecard="$scorecard" />', [
            'scorecard' => $scorecard,
        ]);

        // 1. Grade badge pill must not be rendered
        $view->assertDontSee('Grade A');
        $view->assertDontSee(__('scorecard.grade_badge', ['grade' => 'A']));

        // 2. data-scorecard-grade attribute is preserved on section for backward compatibility
        $view->assertSee('data-scorecard-grade="A"', false);
        $view->assertSee('data-supplier-scorecard="import"', false);

        // 3. Screen-reader accessible composite score is present
        $view->assertSee('<span class="tw:sr-only">'.__('scorecard.composite_score').': 85.50%</span>', false);

        // 4. Progress bar has role="progressbar" and WCAG attributes
        $view->assertSee('role="progressbar"', false);
        $view->assertSee('aria-valuenow="90"', false);
        $view->assertSee('aria-valuemin="0"', false);
        $view->assertSee('aria-valuemax="100"', false);
        $view->assertSee('aria-valuetext="90.00%"', false);
        $view->assertSee('aria-label="'.__('scorecard.metrics.otd').'"', false);

        // 5. Section has accessible title via aria-labelledby
        $view->assertSee('aria-labelledby="scorecard-title-import-default"', false);
        $view->assertSee('id="scorecard-title-import-default"', false);
    }
}
