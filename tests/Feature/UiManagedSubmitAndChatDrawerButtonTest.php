<?php

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Models\Period;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UiManagedSubmitAndChatDrawerButtonTest extends TestCase
{
    use RefreshDatabase;

    private User $purchasingUser;

    private User $supplierUser;

    private Period $period;

    private PurchaseRequisition $pr;

    private Quotation $quotation;

    private PurchaseOrder $po;

    protected function setUp(): void
    {
        parent::setUp();

        $this->purchasingUser = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);
        $this->supplierUser = User::factory()->create(['role' => 'supplier', 'is_active' => true]);

        Supplier::create([
            'user_id' => $this->supplierUser->id,
            'company_name' => 'PT Test Supplier UI',
            'address' => 'Industrial Area 1',
            'phone' => '08123456789',
            'category' => 'Local Manufacturer',
        ]);

        $this->period = Period::create([
            'name' => 'Period UI Test',
            'month' => 9,
            'year' => 2026,
            'status' => 'open',
            'created_by' => $this->purchasingUser->id,
        ]);

        $this->pr = PurchaseRequisition::create([
            'period_id' => $this->period->id,
            'created_by' => $this->purchasingUser->id,
            'pr_number' => 'REQ/09/2026/099',
            'notes' => 'Test PR for UI submit guard',
            'status' => 'bidding',
        ]);

        $rate = ExchangeRate::create([
            'currency' => 'USD',
            'rate_to_idr' => 16000,
            'valid_from' => now()->subDay(),
            'created_by' => $this->purchasingUser->id,
        ]);

        $this->quotation = Quotation::create([
            'pr_id' => $this->pr->id,
            'supplier_id' => $this->supplierUser->id,
            'exchange_rate_id' => $rate->id,
            'currency' => 'USD',
            'status' => 'submitted',
            'submitted_at' => now(),
            'validity_period' => now()->addDays(30),
        ]);

        $this->po = PurchaseOrder::create([
            'supplier_id' => $this->supplierUser->id,
            'currency' => 'USD',
            'exchange_rate_id' => $rate->id,
            'po_number' => 'PO/09/2026/099',
            'status' => 'active',
            'created_by' => $this->purchasingUser->id,
            'estimated_arrival' => now()->addDays(14),
        ]);
    }

    public function test_quotation_show_chat_and_revision_forms_have_data_managed_submit(): void
    {
        $response = $this->actingAs($this->purchasingUser)
            ->get(route('purchasing.quotations.show', $this->quotation));

        $response->assertOk();

        // 1. Chat start form must have data-managed-submit alongside data-chat-start-form
        $response->assertSee('data-chat-start-form', false);
        $response->assertSee('data-managed-submit', false);

        // Verify specifically on the chat start form tag
        $content = $response->getContent();
        $this->assertMatchesRegularExpression(
            '/<form[^>]*data-chat-start-form[^>]*data-managed-submit|<form[^>]*data-managed-submit[^>]*data-chat-start-form/',
            $content,
            'Quotation show chat form must declare data-managed-submit'
        );

        // 2. Request revision form must also have data-managed-submit
        $this->assertMatchesRegularExpression(
            '/<form[^>]*id="requestRevisionForm"[^>]*data-managed-submit|<form[^>]*data-managed-submit[^>]*id="requestRevisionForm"/',
            $content,
            'Quotation show requestRevisionForm must declare data-managed-submit'
        );
    }

    public function test_pr_show_chat_form_has_data_managed_submit(): void
    {
        $response = $this->actingAs($this->purchasingUser)
            ->get(route('purchasing.requisitions.show', $this->pr));

        $response->assertOk();
        $content = $response->getContent();

        $this->assertMatchesRegularExpression(
            '/<form[^>]*data-chat-start-form[^>]*data-managed-submit|<form[^>]*data-managed-submit[^>]*data-chat-start-form/',
            $content,
            'PR show supplier discussion form must declare data-managed-submit'
        );
    }

    public function test_po_show_chat_form_has_data_managed_submit(): void
    {
        $response = $this->actingAs($this->purchasingUser)
            ->get(route('purchasing.purchase-orders.show', $this->po));

        $response->assertOk();
        $content = $response->getContent();

        $this->assertMatchesRegularExpression(
            '/<form[^>]*data-chat-start-form[^>]*data-managed-submit|<form[^>]*data-managed-submit[^>]*data-chat-start-form/',
            $content,
            'PO show supplier negotiation form must declare data-managed-submit'
        );
    }

    public function test_notification_panel_mark_all_read_has_data_managed_submit(): void
    {
        $response = $this->actingAs($this->purchasingUser)
            ->get(route('notifications.summary'));

        $response->assertOk();
        $content = $response->getContent();

        $this->assertMatchesRegularExpression(
            '/<form[^>]*data-notification-mark-form[^>]*data-managed-submit|<form[^>]*data-managed-submit[^>]*data-notification-mark-form/',
            $content,
            'Notification panel mark all read form must declare data-managed-submit'
        );
    }

    public function test_chat_drawer_script_integrates_adasi_button_and_offcanvas_hidden_cleanup(): void
    {
        $response = $this->actingAs($this->purchasingUser)
            ->get(route('purchasing.dashboard'));

        $response->assertOk();
        $content = $response->getContent();

        // Must not contain raw hardcoded spinner-border string mutation on submitButton
        $this->assertStringNotContainsString(
            'submitButton.innerHTML = `<span class="spinner-border spinner-border-sm me-1"></span>Opening...`;',
            $content,
            'chat-drawer must not mutate submitButton.innerHTML with hardcoded spinner-border'
        );

        // Must track active opener button and stopLoading in hidden.bs.offcanvas
        $this->assertStringContainsString('activeOpenerButton', $content);
        $this->assertStringContainsString('AdasiButton.stopLoading', $content);
        $this->assertStringContainsString('hidden.bs.offcanvas', $content);
    }

    public function test_historical_filter_form_has_data_managed_submit(): void
    {
        $response = $this->actingAs($this->purchasingUser)
            ->get(route('purchasing.comparison.historical'));

        $response->assertOk();
        $content = $response->getContent();

        $this->assertMatchesRegularExpression(
            '/<form[^>]*id="historicalFilterForm"[^>]*data-managed-submit|<form[^>]*data-managed-submit[^>]*id="historicalFilterForm"/',
            $content,
            'Historical price filter form must declare data-managed-submit'
        );
    }

    public function test_quotations_index_filter_form_has_data_managed_submit(): void
    {
        $response = $this->actingAs($this->purchasingUser)
            ->get(route('purchasing.quotations.index'));

        $response->assertOk();
        $content = $response->getContent();

        $this->assertMatchesRegularExpression(
            '/<form[^>]*id="quotationFilterForm"[^>]*data-managed-submit|<form[^>]*data-managed-submit[^>]*id="quotationFilterForm"/',
            $content,
            'Quotation index filter form must declare data-managed-submit'
        );
    }
}
