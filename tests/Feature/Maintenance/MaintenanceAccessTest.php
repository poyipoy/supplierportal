<?php

namespace Tests\Feature\Maintenance;

use App\Models\Attachment;
use App\Models\MaintenanceRecord;
use App\Models\PurchaseOrder;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Maintenance\MaintenanceService;
use App\Support\BusinessTime;
use App\Support\HashidCodec;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaintenanceAccessTest extends TestCase
{
    use MaintenanceFixtures;
    use RefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-10 03:00:00', 'UTC'));
        $this->actor = $this->admin();
    }

    public function test_global_maintenance_keeps_sign_in_and_shows_the_global_page_after_login(): void
    {
        $this->activeMaintenance($this->actor, [], ['title' => 'Portal upgrade', 'description' => 'All supplier features pause.']);
        $supplier = $this->supplier(['import']);

        $this->get(route('login'))->assertOk();
        $this->post('/login', ['email' => $supplier->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs($supplier);

        $this->get(route('dashboard'))
            ->assertStatus(503)
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('data-maintenance="global"', false)
            ->assertSee('Portal upgrade')
            ->assertSee('All supplier features pause.')
            ->assertSee('10 Nov 2026 10:00 WIB')
            ->assertSee('10 Nov 2026 12:00 WIB')
            ->assertSee(route('logout'), false);
        $this->get(route('supplier.announcements.index'))->assertStatus(503);
        $this->get(route('profile.edit'))->assertStatus(503);
    }

    public function test_global_and_module_maintenance_never_block_the_admin(): void
    {
        $this->activeMaintenance($this->actor, ['import.quotations', 'local.invoices']);
        $this->activeMaintenance($this->actor);

        $this->actingAs($this->actor)->get(route('admin.maintenance.index'))->assertOk();
        $this->actingAs($this->actor)->get(route('admin.dashboard'))->assertOk();
        $this->actingAs($this->actor)->get(route('profile.edit'))->assertOk();
        $this->actingAs($this->actor)->getJson(route('notifications.unread-count'))->assertOk();
    }

    public function test_account_security_and_sign_out_stay_available_during_global_maintenance(): void
    {
        $this->activeMaintenance($this->actor);
        $supplier = $this->supplier(['local']);

        $this->actingAs($supplier)->get(route('profile.security'))->assertOk();
        $this->actingAs($supplier)->get(route('session.status'))->assertOk();
        $this->actingAs($supplier)->post(route('logout'))->assertRedirect();
        $this->assertGuest();
    }

    public function test_module_maintenance_blocks_pages_json_and_mutations_of_the_selected_module_only(): void
    {
        $this->activeMaintenance($this->actor, ['import.quotations'], ['title' => 'Quotation upgrade']);
        $supplier = $this->supplier(['import']);
        $this->actingAs($supplier);

        $this->get(route('supplier.quotations.index'))
            ->assertStatus(503)
            ->assertSee('data-maintenance="module"', false)
            ->assertSee('Quotation upgrade');

        $this->getJson(route('supplier.quotations.index'))
            ->assertStatus(503)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', __('maintenance.notice.unavailable'))
            ->assertJsonPath('maintenance.scope', MaintenanceRecord::TYPE_MODULE)
            ->assertJsonPath('maintenance.notices.0.title', 'Quotation upgrade')
            ->assertJsonPath('maintenance.notices.0.starts_at', '10 Nov 2026 10:00 WIB');

        $this->get(route('supplier.quotations.index'), ['X-Requested-With' => 'XMLHttpRequest'])->assertStatus(503)->assertJsonPath('maintenance.scope', 'module');
        $this->post(route('supplier.quotations.store', ['pr_id' => HashidCodec::encode(999999)]), ['items' => []])->assertStatus(503);
        $this->post(route('supplier.export.quotations'))->assertStatus(503);

        $this->get(route('supplier.announcements.index'))->assertOk();
    }

    public function test_local_module_maintenance_only_answers_suppliers_holding_the_local_scope(): void
    {
        $this->activeMaintenance($this->actor, ['local.invoices'], ['title' => 'Invoice upgrade']);
        $localSupplier = $this->supplier(['local']);
        $importSupplier = $this->supplier(['import']);

        $this->actingAs($localSupplier)->get(route('local-supplier.invoices.index'))->assertStatus(503)->assertSee('Invoice upgrade');
        $this->actingAs($localSupplier)->get(route('local-supplier.purchase-orders.search'))->assertStatus(503);
        $this->actingAs($localSupplier)->get(route('local-supplier.information'))->assertOk();

        $this->actingAs($importSupplier)->get(route('local-supplier.invoices.index'))
            ->assertForbidden()
            ->assertDontSee('Invoice upgrade');
    }

    public function test_overlapping_records_keep_a_module_blocked_until_every_record_releases_it(): void
    {
        $first = $this->activeMaintenance($this->actor, ['import.quotations', 'import.adasi_information'], ['title' => 'First window']);
        $second = $this->activeMaintenance($this->actor, ['import.quotations'], ['title' => 'Second window']);
        $this->actingAs($this->supplier(['import']));

        $this->get(route('supplier.quotations.index'))->assertStatus(503)->assertSee('First window')->assertSee('Second window');

        $this->service()->deactivate($this->actor, $first);

        $this->get(route('supplier.quotations.index'))->assertStatus(503)->assertSee('Second window')->assertDontSee('First window');
        $this->get(route('supplier.announcements.index'))->assertOk();

        $this->service()->delete($this->actor, $second);

        $this->get(route('supplier.quotations.index'))->assertDontSee('data-maintenance', false);
    }

    public function test_partial_module_release_opens_only_that_module(): void
    {
        $record = $this->activeMaintenance($this->actor, ['import.adasi_information', 'import.quotations']);
        $this->actingAs($this->supplier(['import']));

        $this->service()->deactivateModule($this->actor, $record, 'import.adasi_information');

        $this->get(route('supplier.announcements.index'))->assertOk();
        $this->get(route('supplier.quotations.index'))->assertStatus(503);
    }

    public function test_global_maintenance_overrides_module_coverage_and_lists_every_relevant_notice(): void
    {
        $global = $this->activeMaintenance($this->actor, [], ['title' => 'Global window']);
        $module = $this->activeMaintenance($this->actor, ['import.quotations'], ['title' => 'Quotation window']);
        $this->actingAs($this->supplier(['import']));

        $this->get(route('supplier.quotations.index'))->assertStatus(503)
            ->assertSee('data-maintenance="global"', false)
            ->assertSee('Global window')
            ->assertSee('Quotation window');
        $this->get(route('supplier.announcements.index'))->assertStatus(503)
            ->assertSee('Global window')
            ->assertDontSee('Quotation window');

        $this->service()->deactivate($this->actor, $module);
        $this->get(route('supplier.quotations.index'))->assertStatus(503)->assertSee('Global window');

        $this->service()->deactivate($this->actor, $global);
        $this->get(route('supplier.announcements.index'))->assertOk();
    }

    public function test_scheduled_maintenance_blocks_at_its_start_without_waiting_for_the_scheduler(): void
    {
        $record = $this->service()->create($this->actor, $this->data(['import.adasi_information'], [
            'starts_at' => BusinessTime::now()->addMinutes(30),
            'estimated_end_at' => BusinessTime::now()->addHours(2),
        ]), MaintenanceService::ACTION_SCHEDULE);
        $this->actingAs($this->supplier(['import']));

        $this->get(route('supplier.announcements.index'))->assertOk();

        $this->travel(30)->minutes();

        $this->get(route('supplier.announcements.index'))->assertStatus(503);
        $this->assertSame(MaintenanceRecord::STATUS_SCHEDULED, $record->fresh()->status);
    }

    public function test_drafts_never_block_suppliers(): void
    {
        $this->service()->create($this->actor, $this->data([], ['estimated_end_at' => null]), MaintenanceService::ACTION_DRAFT);

        $this->actingAs($this->supplier(['import']))->get(route('supplier.announcements.index'))->assertOk();
    }

    public function test_shared_attachment_downloads_follow_the_module_that_owns_the_attachable(): void
    {
        $this->activeMaintenance($this->actor, ['import.shipments']);
        $supplier = $this->supplier(['import']);
        $shipmentFile = $this->attachment(Shipment::class, $supplier);
        $orderFile = $this->attachment(PurchaseOrder::class, $supplier);

        $this->actingAs($supplier)->get(route('attachments.show', $shipmentFile))->assertStatus(503);
        $orderResponse = $this->actingAs($supplier)->get(route('attachments.show', $orderFile));
        $this->assertNotSame(503, $orderResponse->getStatusCode());
    }

    public function test_unnamed_chat_mutation_is_protected_by_its_controller_mapping(): void
    {
        $this->activeMaintenance($this->actor, ['import.chat']);
        $supplier = $this->supplier(['import']);
        $path = route('conversations.unmute', ['id' => HashidCodec::encode(424242)]);

        $this->actingAs($supplier)->post($path)->assertStatus(503);
        $this->actingAs($supplier)->getJson(route('conversations.unread-count'))->assertStatus(503);
    }

    public function test_guests_and_public_registration_are_not_affected(): void
    {
        $this->activeMaintenance($this->actor);

        $this->get(route('login'))->assertOk();
        $this->get(route('supplier.register'))->assertOk();
    }

    private function attachment(string $type, User $uploader): Attachment
    {
        return Attachment::create([
            'attachable_type' => $type,
            'attachable_id' => 987654,
            'file_path' => 'attachments/2026/11/maintenance-test.pdf',
            'file_name' => 'maintenance-test.pdf',
            'file_type' => 'application/pdf',
            'uploaded_by' => $uploader->id,
        ]);
    }
}
