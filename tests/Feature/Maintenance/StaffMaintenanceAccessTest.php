<?php

namespace Tests\Feature\Maintenance;

use App\Models\MaintenanceRecord;
use App\Models\User;
use App\Services\Maintenance\MaintenanceService;
use App\Support\HashidCodec;
use App\Support\Maintenance\MaintenanceModuleRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Maintenance applies to every role except admin. These cases cover the staff
 * roles (purchasing, qc, finance, accounting, ga); the supplier side lives in
 * MaintenanceAccessTest.
 */
class StaffMaintenanceAccessTest extends TestCase
{
    use MaintenanceFixtures;
    use RefreshDatabase;

    private const ROLES = ['purchasing', 'qc', 'finance', 'accounting', 'ga'];

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-10 03:00:00', 'UTC'));
        $this->actor = $this->admin();
    }

    public function test_global_maintenance_shows_the_notice_page_to_every_staff_role(): void
    {
        $this->activeMaintenance($this->actor, [], ['title' => 'Portal upgrade', 'description' => 'The portal pauses.']);

        foreach (self::ROLES as $role) {
            $this->actingAs($this->staff($role))->get(route('profile.edit'))
                ->assertStatus(503)
                ->assertHeader('Cache-Control', 'no-store, private')
                ->assertSee('data-maintenance="global"', false)
                ->assertSee('Portal upgrade')
                ->assertSee('The portal pauses.')
                ->assertSee('10 Nov 2026 10:00 WIB')
                ->assertSee(route('logout'), false);
        }
    }

    public function test_global_maintenance_blocks_each_role_dashboard_and_its_json_requests(): void
    {
        $this->activeMaintenance($this->actor);

        $dashboards = [
            'purchasing' => 'purchasing.dashboard',
            'qc' => 'qc.dashboard',
            'finance' => 'finance.dashboard',
            'accounting' => 'accounting.dashboard',
            'ga' => 'ga.dashboard',
        ];
        foreach ($dashboards as $role => $dashboard) {
            $user = $this->staff($role);
            $this->actingAs($user)->get(route($dashboard))->assertStatus(503)->assertSee('data-maintenance="global"', false);
            $this->actingAs($user)->getJson(route('notifications.unread-count'))
                ->assertStatus(503)
                ->assertJsonPath('success', false)
                ->assertJsonPath('message', __('maintenance.notice.unavailable'))
                ->assertJsonPath('maintenance.scope', MaintenanceRecord::TYPE_GLOBAL);
        }

        $purchasing = $this->staff('purchasing');
        $this->actingAs($purchasing)->post(route('purchasing.quotations.accept', ['id' => HashidCodec::encode(999999)]))->assertStatus(503);
        $this->actingAs($purchasing)->post(route('purchasing.purchase-orders.store'))->assertStatus(503);
    }

    public function test_sign_out_and_account_security_stay_available_to_every_staff_role_during_global_maintenance(): void
    {
        $this->activeMaintenance($this->actor);

        foreach (self::ROLES as $role) {
            $user = $this->staff($role);
            $this->actingAs($user)->get(route('profile.security'))->assertOk();
            $this->actingAs($user)->get(route('session.status'))->assertOk();
            $this->actingAs($user)->post(route('logout'))->assertRedirect();
            $this->assertGuest();
        }
    }

    public function test_public_receipt_verification_and_the_root_redirect_stay_open_for_signed_in_staff(): void
    {
        $this->activeMaintenance($this->actor);
        $this->actingAs($this->staff('purchasing'));

        // Unsigned links are refused by the route's own signature check (403); the gate must not answer 503 first.
        $this->get(route('receipts.verify-supplier', ['receipt' => 999999]))->assertForbidden();
        $this->get(route('receipts.verify-ga', ['receipt' => 999999]))->assertForbidden();
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_module_maintenance_blocks_the_staff_pages_of_the_same_area_only(): void
    {
        $this->activeMaintenance($this->actor, ['import.purchase_orders'], ['title' => 'PO upgrade']);
        $purchasing = $this->staff('purchasing');

        $this->actingAs($purchasing)->get(route('purchasing.purchase-orders.index'))
            ->assertStatus(503)
            ->assertSee('data-maintenance="module"', false)
            ->assertSee('PO upgrade');
        $this->actingAs($purchasing)->getJson(route('purchasing.purchase-orders.index'))
            ->assertStatus(503)
            ->assertJsonPath('maintenance.scope', MaintenanceRecord::TYPE_MODULE)
            ->assertJsonPath('maintenance.notices.0.title', 'PO upgrade');
        $this->actingAs($purchasing)->post(route('purchasing.purchase-orders.store'))->assertStatus(503);
        $this->actingAs($purchasing)->get(route('shared.pdf.purchase-order', ['id' => HashidCodec::encode(999999)]))->assertStatus(503);

        $this->actingAs($purchasing)->get(route('purchasing.dashboard'))->assertOk();
        $this->actingAs($purchasing)->get(route('purchasing.requisitions.index'))->assertOk();
    }

    public function test_one_area_holds_both_its_supplier_and_its_staff_pages(): void
    {
        $this->activeMaintenance($this->actor, ['import.quotations'], ['title' => 'Quotation upgrade']);

        $this->actingAs($this->supplier(['import']))->get(route('supplier.quotations.index'))->assertStatus(503);
        $this->actingAs($this->staff('purchasing'))->get(route('purchasing.quotations.index'))
            ->assertStatus(503)
            ->assertSee('Quotation upgrade');
        $this->actingAs($this->staff('purchasing'))->get(route('purchasing.comparison.inter-supplier', ['pr_id' => HashidCodec::encode(999999)]))->assertStatus(503);
    }

    public function test_local_modules_block_finance_and_accounting_pages_of_the_same_area(): void
    {
        $this->activeMaintenance($this->actor, ['local.invoices', 'local.purchase_orders']);

        $this->actingAs($this->staff('finance'))->get(route('finance.invoices.index'))->assertStatus(503);
        $this->actingAs($this->staff('finance'))->get(route('finance.local-procurement.index'))->assertStatus(503);
        $this->actingAs($this->staff('accounting'))->get(route('accounting.invoices.index'))->assertStatus(503);
        $this->actingAs($this->staff('purchasing'))->get(route('purchasing.local-procurement.index'))->assertStatus(503);

        $this->actingAs($this->staff('finance'))->get(route('finance.dashboard'))->assertOk();
    }

    public function test_a_module_only_blocks_roles_that_work_in_its_core(): void
    {
        $this->activeMaintenance($this->actor, ['import.export_history']);

        // exports.* is shared by both cores: Finance keeps its export history, the import roles do not.
        $this->actingAs($this->staff('finance'))->get(route('exports.index'))->assertOk();
        $this->actingAs($this->staff('purchasing'))->get(route('exports.index'))->assertStatus(503);
        $this->actingAs($this->staff('qc'))->get(route('exports.index'))->assertStatus(503);
    }

    public function test_staff_areas_without_a_module_are_blocked_only_by_global_maintenance(): void
    {
        $this->activeMaintenance($this->actor, app(MaintenanceModuleRegistry::class)->keys(), ['title' => 'Every module']);

        $this->actingAs($this->staff('qc'))->get(route('qc.dashboard'))->assertOk();
        $this->actingAs($this->staff('ga'))->get(route('ga.dashboard'))->assertOk();
        $this->actingAs($this->staff('finance'))->get(route('finance.drp.supplier'))->assertOk();
        $this->actingAs($this->staff('purchasing'))->get(route('purchasing.drp.supplier'))->assertOk();

        $this->activeMaintenance($this->actor);

        $this->actingAs($this->staff('qc'))->get(route('qc.dashboard'))->assertStatus(503);
        $this->actingAs($this->staff('ga'))->get(route('ga.dashboard'))->assertStatus(503);
        $this->actingAs($this->staff('finance'))->get(route('finance.drp.supplier'))->assertStatus(503);
        $this->actingAs($this->staff('purchasing'))->get(route('purchasing.drp.supplier'))->assertStatus(503);
    }

    public function test_drafts_and_released_maintenance_never_block_staff(): void
    {
        $this->service()->create($this->actor, $this->data([], ['estimated_end_at' => null]), MaintenanceService::ACTION_DRAFT);
        $record = $this->activeMaintenance($this->actor);

        $this->actingAs($this->staff('purchasing'))->get(route('purchasing.dashboard'))->assertStatus(503);

        $this->service()->deactivate($this->actor, $record);

        foreach (self::ROLES as $role) {
            $this->actingAs($this->staff($role))->get(route('profile.edit'))->assertOk();
        }
    }
}
