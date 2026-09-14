<?php

namespace Tests\Feature\LocalInvoice;

use App\Models\LocalInvoice;
use App\Models\Period;
use App\Models\Supplier;
use App\Models\User;
use App\Notifications\SystemNotification;
use App\Support\NotificationCategory;
use App\Support\NotificationDomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Tests\TestCase;

class LocalInvoiceScopeIsolationTest extends TestCase
{
    use RefreshDatabase;

    // ─── Helpers ───

    private function supplierWithScopes(array $scopes): User
    {
        $user = User::factory()->create();
        $user->supplierScopes()->delete();
        foreach ($scopes as $scope) {
            $user->supplierScopes()->create(['scope' => $scope]);
        }
        Supplier::create([
            'user_id' => $user->id,
            'company_name' => 'Vendor '.$user->id,
            'address' => 'Jakarta',
            'phone' => '123',
            'npwp' => '123',
            'category' => 'Parts',
            'payment_term_days' => 30,
        ]);

        return $user;
    }

    private function injectNotification(User $user, string $domain, string $category, string $title = 'Test'): DatabaseNotification
    {
        $event = match ($domain) {
            NotificationDomain::LOCAL => 'local_invoice.submitted',
            NotificationDomain::IMPORT => 'quotation.submitted',
            NotificationDomain::GLOBAL => 'export.completed',
        };

        return $user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => SystemNotification::class,
            'data' => [
                'title' => $title,
                'message' => 'Test message',
                'url' => '#',
                'icon' => 'bell',
                'category' => $category,
                'domain' => $domain,
                'event' => $event,
            ],
        ]);
    }

    // ─── LSI-006: Local-only supplier ───

    public function test_local_only_supplier_cannot_see_import_notifications_in_unread_count(): void
    {
        $supplier = $this->supplierWithScopes(['local']);
        $this->injectNotification($supplier, NotificationDomain::IMPORT, NotificationCategory::QUOTATION, 'Import quotation');
        $this->injectNotification($supplier, NotificationDomain::LOCAL, NotificationCategory::INVOICE, 'Local invoice');
        $this->injectNotification($supplier, NotificationDomain::GLOBAL, NotificationCategory::OTHER, 'Global export');

        $this->actingAs($supplier)->getJson(route('notifications.unread-count'))
            ->assertOk()
            ->assertJsonPath('count', 2); // local + global only
    }

    public function test_local_only_supplier_mark_all_read_does_not_mark_import_notifications(): void
    {
        $supplier = $this->supplierWithScopes(['local']);
        $import = $this->injectNotification($supplier, NotificationDomain::IMPORT, NotificationCategory::QUOTATION);
        $local = $this->injectNotification($supplier, NotificationDomain::LOCAL, NotificationCategory::INVOICE);

        $this->actingAs($supplier)->postJson(route('notifications.mark-all-read'))
            ->assertOk()
            ->assertJsonPath('unread_count', 0);

        $this->assertNotNull($local->fresh()->read_at, 'Local notification should be marked as read');
        $this->assertNull($import->fresh()->read_at, 'Import notification should remain unread');
    }

    public function test_local_only_supplier_mark_read_on_import_notification_returns_403(): void
    {
        $supplier = $this->supplierWithScopes(['local']);
        $import = $this->injectNotification($supplier, NotificationDomain::IMPORT, NotificationCategory::QUOTATION);

        $this->actingAs($supplier)->postJson(route('notifications.read', $import->id))
            ->assertForbidden();

        $this->assertNull($import->fresh()->read_at, 'Import notification should remain unread');
    }

    public function test_local_only_supplier_cannot_access_import_routes(): void
    {
        $supplier = $this->supplierWithScopes(['local']);
        session(['supplier_context' => 'local']);

        $this->actingAs($supplier)->get(route('supplier.dashboard'))
            ->assertForbidden();
    }

    public function test_local_only_supplier_is_excluded_from_pr_create_dropdown(): void
    {
        $localSupplier = $this->supplierWithScopes(['local']);
        $importSupplier = $this->supplierWithScopes(['import']);
        $purchasing = User::factory()->create(['role' => 'purchasing']);
        Period::create(['name' => 'Test', 'year' => 2026, 'status' => 'open', 'created_by' => $purchasing->id]);

        $response = $this->actingAs($purchasing)->get(route('purchasing.requisitions.create'));
        $response->assertOk();
        // It should show the import supplier but not the local-only supplier
        $suppliers = $response->viewData('suppliers');
        $this->assertTrue($suppliers->contains('id', $importSupplier->id), 'Import supplier should be in dropdown');
        $this->assertFalse($suppliers->contains('id', $localSupplier->id), 'Local-only supplier should NOT be in dropdown');
    }

    // ─── LSI-006: Import-only supplier ───

    public function test_import_only_supplier_cannot_see_local_notifications_in_unread_count(): void
    {
        $supplier = $this->supplierWithScopes(['import']);
        $this->injectNotification($supplier, NotificationDomain::LOCAL, NotificationCategory::INVOICE, 'Local invoice');
        $this->injectNotification($supplier, NotificationDomain::IMPORT, NotificationCategory::QUOTATION, 'Import quotation');
        $this->injectNotification($supplier, NotificationDomain::GLOBAL, NotificationCategory::OTHER, 'Global export');

        $this->actingAs($supplier)->getJson(route('notifications.unread-count'))
            ->assertOk()
            ->assertJsonPath('count', 2); // import + global only
    }

    public function test_import_only_supplier_mark_all_read_does_not_mark_local_notifications(): void
    {
        $supplier = $this->supplierWithScopes(['import']);
        $local = $this->injectNotification($supplier, NotificationDomain::LOCAL, NotificationCategory::INVOICE);
        $import = $this->injectNotification($supplier, NotificationDomain::IMPORT, NotificationCategory::QUOTATION);

        $this->actingAs($supplier)->postJson(route('notifications.mark-all-read'))
            ->assertOk()
            ->assertJsonPath('unread_count', 0);

        $this->assertNotNull($import->fresh()->read_at, 'Import notification should be marked as read');
        $this->assertNull($local->fresh()->read_at, 'Local notification should remain unread');
    }

    public function test_import_only_supplier_mark_read_on_local_notification_returns_403(): void
    {
        $supplier = $this->supplierWithScopes(['import']);
        $local = $this->injectNotification($supplier, NotificationDomain::LOCAL, NotificationCategory::INVOICE);

        $this->actingAs($supplier)->postJson(route('notifications.read', $local->id))
            ->assertForbidden();
    }

    public function test_import_only_supplier_can_be_invited_to_pr(): void
    {
        $importSupplier = $this->supplierWithScopes(['import']);
        $purchasing = User::factory()->create(['role' => 'purchasing']);
        Period::create(['name' => 'Test', 'year' => 2026, 'status' => 'open', 'created_by' => $purchasing->id]);

        $response = $this->actingAs($purchasing)->get(route('purchasing.requisitions.create'));
        $response->assertOk();
        $suppliers = $response->viewData('suppliers');
        $this->assertTrue($suppliers->contains('id', $importSupplier->id));
    }

    // ─── LSI-006: Dual-scope supplier ───

    public function test_dual_scope_supplier_in_local_context_sees_local_and_global_only(): void
    {
        $supplier = $this->supplierWithScopes(['local', 'import']);
        session(['supplier_context' => 'local']);

        $this->injectNotification($supplier, NotificationDomain::LOCAL, NotificationCategory::INVOICE, 'Local');
        $this->injectNotification($supplier, NotificationDomain::IMPORT, NotificationCategory::QUOTATION, 'Import');
        $this->injectNotification($supplier, NotificationDomain::GLOBAL, NotificationCategory::OTHER, 'Global');

        // Access a local route first to set context
        $this->actingAs($supplier)->getJson(route('notifications.unread-count'))
            ->assertOk()
            ->assertJsonPath('count', 2); // local + global
    }

    public function test_dual_scope_supplier_in_import_context_sees_import_and_global_only(): void
    {
        $supplier = $this->supplierWithScopes(['local', 'import']);
        session(['supplier_context' => 'import']);

        $this->injectNotification($supplier, NotificationDomain::LOCAL, NotificationCategory::INVOICE, 'Local');
        $this->injectNotification($supplier, NotificationDomain::IMPORT, NotificationCategory::QUOTATION, 'Import');
        $this->injectNotification($supplier, NotificationDomain::GLOBAL, NotificationCategory::OTHER, 'Global');

        $this->actingAs($supplier)->getJson(route('notifications.unread-count'))
            ->assertOk()
            ->assertJsonPath('count', 2); // import + global
    }

    public function test_dual_scope_supplier_mark_all_read_only_affects_current_context(): void
    {
        $supplier = $this->supplierWithScopes(['local', 'import']);
        session(['supplier_context' => 'local']);

        $local = $this->injectNotification($supplier, NotificationDomain::LOCAL, NotificationCategory::INVOICE);
        $import = $this->injectNotification($supplier, NotificationDomain::IMPORT, NotificationCategory::QUOTATION);

        $this->actingAs($supplier)->postJson(route('notifications.mark-all-read'))
            ->assertOk()
            ->assertJsonPath('unread_count', 0);

        $this->assertNotNull($local->fresh()->read_at, 'Local should be marked read in local context');
        $this->assertNull($import->fresh()->read_at, 'Import should remain unread in local context');
    }

    // ─── LSI-006: NotificationDomain unit tests ───

    public function test_notification_domain_resolves_local_from_invoice_id(): void
    {
        $this->assertSame(NotificationDomain::LOCAL, NotificationDomain::resolveDomain(null, ['local_invoice_id' => 1]));
    }

    public function test_notification_domain_resolves_local_from_event(): void
    {
        $this->assertSame(NotificationDomain::LOCAL, NotificationDomain::resolveDomain('local_invoice.submitted'));
    }

    public function test_notification_domain_resolves_import_from_pr_event(): void
    {
        $this->assertSame(NotificationDomain::IMPORT, NotificationDomain::resolveDomain('pr.submitted'));
    }

    public function test_notification_domain_resolves_global_from_export_event(): void
    {
        $this->assertSame(NotificationDomain::GLOBAL, NotificationDomain::resolveDomain('export.completed'));
    }

    public function test_notification_domain_resolves_explicit_domain(): void
    {
        $this->assertSame(NotificationDomain::LOCAL, NotificationDomain::resolveDomain('pr.submitted', ['domain' => NotificationDomain::LOCAL]));
    }

    // ─── LSI-006: Accounting/Finance domain ───

    public function test_accounting_sees_only_local_and_global_notifications(): void
    {
        $operator = User::factory()->create(['role' => 'finance']);
        $this->injectNotification($operator, NotificationDomain::LOCAL, NotificationCategory::INVOICE, 'Local invoice');
        $this->injectNotification($operator, NotificationDomain::IMPORT, NotificationCategory::QUOTATION, 'Import quotation');
        $this->injectNotification($operator, NotificationDomain::GLOBAL, NotificationCategory::OTHER, 'Global');

        $this->actingAs($operator)->getJson(route('notifications.unread-count'))
            ->assertOk()
            ->assertJsonPath('count', 2); // local + global only
    }

    public function test_purchasing_sees_only_import_and_global_notifications(): void
    {
        $purchasing = User::factory()->create(['role' => 'purchasing']);
        $this->injectNotification($purchasing, NotificationDomain::LOCAL, NotificationCategory::INVOICE, 'Local invoice');
        $this->injectNotification($purchasing, NotificationDomain::IMPORT, NotificationCategory::QUOTATION, 'Import quotation');
        $this->injectNotification($purchasing, NotificationDomain::GLOBAL, NotificationCategory::OTHER, 'Global');

        $this->actingAs($purchasing)->getJson(route('notifications.unread-count'))
            ->assertOk()
            ->assertJsonPath('count', 2); // import + global only
    }

    // ─── LSI-003: Admin user management ───

    public function test_admin_users_filter_includes_accounting_and_finance(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        User::factory()->create(['role' => 'ga']);
        User::factory()->create(['role' => 'finance']);

        $response = $this->actingAs($admin)->get(route('admin.users.index'));
        $response->assertOk();
        $response->assertSee('General Affairs');
        $response->assertSee('Finance');
    }

    public function test_admin_datatables_returns_accounting_role_badge(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        User::factory()->create(['role' => 'finance', 'name' => 'Fin User']);

        $response = $this->actingAs($admin)
            ->getJson(route('admin.users.index').'?draw=1&start=0&length=25&search%5Bvalue%5D=Fin+User');
        $response->assertOk();
        $response->assertSee('Finance');
    }

    // ─── LSI-006: Category classification ───

    public function test_local_invoice_notification_classified_as_invoice_category(): void
    {
        $user = User::factory()->create(['role' => 'finance']);
        $notification = $this->injectNotification($user, NotificationDomain::LOCAL, NotificationCategory::INVOICE);

        $this->assertSame(NotificationCategory::INVOICE, NotificationCategory::key($notification));
    }

    public function test_category_options_for_local_user_excludes_import_categories(): void
    {
        $operator = User::factory()->create(['role' => 'finance']);
        $options = NotificationCategory::optionsForUser($operator);

        $this->assertArrayHasKey(NotificationCategory::ALL, $options);
        $this->assertArrayHasKey(NotificationCategory::INVOICE, $options);
        $this->assertArrayHasKey(NotificationCategory::OTHER, $options);
        $this->assertArrayNotHasKey(NotificationCategory::CHAT, $options);
        $this->assertArrayNotHasKey(NotificationCategory::QUOTATION, $options);
        $this->assertArrayNotHasKey(NotificationCategory::DOCUMENT, $options);
    }

    public function test_category_options_for_import_user_excludes_invoice(): void
    {
        $purchasing = User::factory()->create(['role' => 'purchasing']);
        $options = NotificationCategory::optionsForUser($purchasing);

        $this->assertArrayHasKey(NotificationCategory::ALL, $options);
        $this->assertArrayHasKey(NotificationCategory::CHAT, $options);
        $this->assertArrayHasKey(NotificationCategory::QUOTATION, $options);
        $this->assertArrayHasKey(NotificationCategory::DOCUMENT, $options);
        $this->assertArrayHasKey(NotificationCategory::OTHER, $options);
        $this->assertArrayNotHasKey(NotificationCategory::INVOICE, $options);
    }

    public function test_category_options_for_admin_includes_all(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $options = NotificationCategory::optionsForUser($admin);

        $this->assertArrayHasKey(NotificationCategory::ALL, $options);
        $this->assertArrayHasKey(NotificationCategory::CHAT, $options);
        $this->assertArrayHasKey(NotificationCategory::QUOTATION, $options);
        $this->assertArrayHasKey(NotificationCategory::DOCUMENT, $options);
        $this->assertArrayHasKey(NotificationCategory::INVOICE, $options);
        $this->assertArrayHasKey(NotificationCategory::OTHER, $options);
    }

    // ─── LSI-002: Import eligibility scope ───

    public function test_import_eligible_scope_excludes_local_only_suppliers(): void
    {
        $localOnly = $this->supplierWithScopes(['local']);
        $importOnly = $this->supplierWithScopes(['import']);
        $both = $this->supplierWithScopes(['local', 'import']);

        $eligible = User::importEligible()->pluck('id');
        $this->assertTrue($eligible->contains($importOnly->id));
        $this->assertTrue($eligible->contains($both->id));
        $this->assertFalse($eligible->contains($localOnly->id));
    }

    public function test_local_eligible_scope_excludes_import_only_suppliers(): void
    {
        $localOnly = $this->supplierWithScopes(['local']);
        $importOnly = $this->supplierWithScopes(['import']);

        $eligible = User::localEligible()->pluck('id');
        $this->assertTrue($eligible->contains($localOnly->id));
        $this->assertFalse($eligible->contains($importOnly->id));
    }

    public function test_accounting_can_filter_historical_invoices_for_supplier_whose_local_scope_was_revoked(): void
    {
        $supplier = $this->supplierWithScopes(['local']);
        $invoice = LocalInvoice::create([
            'supplier_id' => $supplier->id,
            'submission_number' => 'SUB/09/2026/009',
            'invoice_number' => 'INV-REVOKED-001',
            'invoice_date' => now()->subDay()->toDateString(),
            'po_number' => 'PO-LOCAL-999',
            'invoice_amount' => 750000,
            'tax_amount' => 82500,
            'payment_term_days_snapshot' => 30,
            'status' => 'APPROVED',
            'submitted_at' => now(),
            'revision_number' => 1,
        ]);

        // Revoke local scope (switch supplier to import-only)
        $supplier->supplierScopes()->delete();
        $supplier->supplierScopes()->create(['scope' => 'import']);
        $this->assertFalse($supplier->fresh()->hasSupplierScope('local'));

        $accounting = User::factory()->create(['role' => 'finance', 'is_active' => true]);

        // Accounting should be able to filter by this supplier's hash without 422 error
        $response = $this->actingAs($accounting)->get(route('accounting.invoices.index', ['supplier' => $supplier->hash]));
        $response->assertOk();
        $response->assertSee('INV-REVOKED-001');

        // Verify supplier is present in the composer dropdown variable
        $viewSuppliers = $response->viewData('suppliers');
        $this->assertTrue($viewSuppliers->contains('id', $supplier->id));
    }

    public function test_admin_create_and_edit_user_renders_general_supplier_label_and_conditional_scopes(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        // 1. Create form
        $createResponse = $this->actingAs($admin)->get(route('admin.users.create'));
        $createResponse->assertOk();
        $createResponse->assertSee('Supplier (Material &amp; Invoicing)', false);
        $createResponse->assertDontSee('Supplier (Bidding &amp; Quotations)', false);

        // Check dropdown select exists with the 3 business preset options
        $createResponse->assertSee('id="supplier-scope-preset"', false);
        $createResponse->assertSee('value="import"', false);
        $createResponse->assertSee('value="local"', false);
        $createResponse->assertSee('value="both"', false);

        // Check that checkboxes for scopes are NO LONGER rendered
        $createResponse->assertDontSee('type="checkbox" id="scope-import"', false);
        $createResponse->assertDontSee('type="checkbox" id="scope-local"', false);

        // Check that Supplier Business Access is rendered inside supplier-section, not before it
        $createContent = $createResponse->getContent();
        $supplierSectionPos = strpos($createContent, 'id="supplier-section"');
        $businessAccessPos = strpos($createContent, 'Supplier Business Access');
        $this->assertNotFalse($supplierSectionPos, 'supplier-section should exist');
        $this->assertNotFalse($businessAccessPos, 'Supplier Business Access should exist');
        $this->assertTrue($businessAccessPos > $supplierSectionPos, 'Supplier Business Access must be inside supplier-section, not above it');

        // 2. Edit form for Admin user (non-supplier)
        $editAdminResponse = $this->actingAs($admin)->get(route('admin.users.edit', $admin));
        $editAdminResponse->assertOk();
        $editAdminContent = $editAdminResponse->getContent();
        // The supplier section should be hidden (d-none) for non-supplier
        $this->assertMatchesRegularExpression('/id="supplier-section"[^>]*class="[^"]*d-none/', $editAdminContent);

        // 3. Edit form for Supplier user
        $supplier = $this->supplierWithScopes(['import']);
        $editSupplierResponse = $this->actingAs($admin)->get(route('admin.users.edit', $supplier));
        $editSupplierResponse->assertOk();
        $editSupplierContent = $editSupplierResponse->getContent();
        // The supplier section should NOT have d-none for supplier
        $this->assertDoesNotMatchRegularExpression('/id="supplier-section"[^>]*class="[^"]*d-none/', $editSupplierContent);
        $editSupplierPos = strpos($editSupplierContent, 'id="supplier-section"');
        $editBusinessAccessPos = strpos($editSupplierContent, 'Supplier Business Access');
        $this->assertTrue($editBusinessAccessPos > $editSupplierPos, 'Supplier Business Access must be inside supplier-section in edit view');
        $editSupplierResponse->assertSee('id="supplier-scope-preset"', false);
    }

    public function test_admin_can_create_and_update_supplier_using_scope_preset_dropdown(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        // 1. Create with Dual Access preset ('both')
        $postDataDual = [
            'name' => 'Dual Scope Supplier PT',
            'email' => 'dual-supplier@example.com',
            'password' => 'SecurePass123!@#',
            'password_confirmation' => 'SecurePass123!@#',
            'role' => 'supplier',
            'is_active' => '1',
            'company_name' => 'PT Dual Scope Nusantara',
            'category' => 'Specialty Tool Steel',
            'phone' => '021-5551234',
            'npwp' => '01.234.567.8-901.000',
            'address' => 'Kawasan Industri GIIC Cikarang',
            'supplier_scope_preset' => 'both',
            'payment_term_days' => 45,
            'supplier_scopes_present' => '1',
        ];

        $storeResponse = $this->actingAs($admin)->post(route('admin.users.store'), $postDataDual);
        $storeResponse->assertRedirect(route('admin.users.index'));

        $dualUser = User::where('email', 'dual-supplier@example.com')->firstOrFail();
        $this->assertTrue($dualUser->hasSupplierScope('import'));
        $this->assertTrue($dualUser->hasSupplierScope('local'));
        $this->assertEquals(45, $dualUser->supplier->payment_term_days);

        // 2. Update to Local Invoices Only ('local')
        $updateDataLocal = [
            'name' => 'Local Only Supplier PT',
            'email' => 'dual-supplier@example.com',
            'role' => 'supplier',
            'is_active' => '1',
            'company_name' => 'PT Dual Scope Nusantara',
            'category' => 'Specialty Tool Steel',
            'phone' => '021-5551234',
            'npwp' => '01.234.567.8-901.000',
            'address' => 'Kawasan Industri GIIC Cikarang',
            'supplier_scope_preset' => 'local',
            'payment_term_days' => 30,
            'supplier_scopes_present' => '1',
        ];

        $updateResponse = $this->actingAs($admin)->put(route('admin.users.update', $dualUser), $updateDataLocal);
        $updateResponse->assertRedirect(route('admin.users.index'));

        $dualUser->refresh();
        $this->assertFalse($dualUser->hasSupplierScope('import'));
        $this->assertTrue($dualUser->hasSupplierScope('local'));
        $this->assertEquals(30, $dualUser->supplier->payment_term_days);

        // 3. Update to Import Procurement Only ('import')
        $updateDataImport = [
            'name' => 'Import Only Supplier PT',
            'email' => 'dual-supplier@example.com',
            'role' => 'supplier',
            'is_active' => '1',
            'company_name' => 'PT Dual Scope Nusantara',
            'category' => 'Specialty Tool Steel',
            'phone' => '021-5551234',
            'npwp' => '01.234.567.8-901.000',
            'address' => 'Kawasan Industri GIIC Cikarang',
            'supplier_scope_preset' => 'import',
            'supplier_scopes_present' => '1',
        ];

        $updateImportResponse = $this->actingAs($admin)->put(route('admin.users.update', $dualUser), $updateDataImport);
        $updateImportResponse->assertRedirect(route('admin.users.index'));

        $dualUser->refresh();
        $this->assertTrue($dualUser->hasSupplierScope('import'));
        $this->assertFalse($dualUser->hasSupplierScope('local'));
    }
}
