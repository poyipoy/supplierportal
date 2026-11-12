<?php

namespace Tests\Feature\Purchasing;

use App\Models\Period;
use App\Models\PurchaseRequisition;
use App\Models\User;
use App\Support\PurchasingNavigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RequisitionShowBackButtonTest extends TestCase
{
    use RefreshDatabase;

    private User $purchasingUser;

    private PurchaseRequisition $pr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->purchasingUser = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);

        $period = Period::create([
            'name' => 'Period Test Back Button',
            'month' => 11,
            'year' => 2026,
            'status' => 'open',
            'created_by' => $this->purchasingUser->id,
        ]);

        $this->pr = PurchaseRequisition::create([
            'pr_number' => 'PR-TEST-BACK-001',
            'period_id' => $period->id,
            'status' => 'draft',
            'created_by' => $this->purchasingUser->id,
        ]);
    }

    public function test_pr_detail_page_renders_back_button(): void
    {
        $response = $this->actingAs($this->purchasingUser)
            ->get(route('purchasing.requisitions.show', $this->pr));

        $response->assertOk();
        $response->assertSee(__('purchasing.copy.back_to_requisitions'));
        $response->assertSee(PurchasingNavigation::backUrl('purchasing.requisitions.index'), false);
    }

    public function test_pr_detail_page_honors_safe_return_url(): void
    {
        $customReturn = '/purchasing/requisitions?status=draft';

        $response = $this->actingAs($this->purchasingUser)
            ->get(route('purchasing.requisitions.show', [
                $this->pr,
                PurchasingNavigation::RETURN_URL_KEY => $customReturn,
            ]));

        $response->assertOk();
        $response->assertSee($customReturn, false);
    }
}
