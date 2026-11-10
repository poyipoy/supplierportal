<?php

namespace Tests\Feature;

use App\Models\LocalFinanceAuditLog;
use App\Models\Supplier;
use App\Models\SupplierChangeRequest;
use App\Models\SupplierScope;
use App\Models\User;
use App\Support\ServerTabsResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportSupplierContactTest extends TestCase
{
    use RefreshDatabase;

    private function supplier(array $scopes = ['import'], array $profile = []): User
    {
        // UserFactory always adds the import scope; replace it with the requested set.
        $user = User::factory()->create(['role' => 'supplier']);
        SupplierScope::where('supplier_id', $user->id)->whereNotIn('scope', $scopes)->delete();
        foreach ($scopes as $scope) {
            SupplierScope::firstOrCreate(['supplier_id' => $user->id, 'scope' => $scope]);
        }
        Supplier::create(array_merge([
            'user_id' => $user->id,
            'company_name' => 'Daido Trading Co.',
            'category' => 'Steel',
        ], $profile));

        return $user;
    }

    private function staff(string $role): User
    {
        return User::factory()->create(['role' => $role]);
    }

    private function contact(array $overrides = []): array
    {
        return array_merge([
            'pic_name' => 'Kenji Sato',
            'pic_email' => 'kenji.sato@daido.example',
            'pic_phone' => '+81 3 1234 5678',
        ], $overrides);
    }

    public function test_import_supplier_sees_company_profile_and_missing_contact_prompt(): void
    {
        $supplier = $this->supplier();

        $this->actingAs($supplier)->get(route('supplier.company-profile.show'))
            ->assertOk()
            ->assertSee('Daido Trading Co.')
            ->assertSee(__('supplier_contact.profile.missing_title'));
    }

    public function test_local_only_supplier_cannot_open_import_company_profile(): void
    {
        $supplier = $this->supplier(['local']);

        $this->actingAs($supplier)->get(route('supplier.company-profile.show'))->assertForbidden();
        $this->actingAs($supplier)->post(route('supplier.company-profile.contact-requests.store'), $this->contact())->assertForbidden();
        $this->assertDatabaseCount('supplier_change_requests', 0);
    }

    public function test_import_supplier_submits_contact_request_with_whitelisted_fields(): void
    {
        $supplier = $this->supplier();
        $purchasing = $this->staff('purchasing');

        $this->actingAs($supplier)
            ->post(route('supplier.company-profile.contact-requests.store'), $this->contact(['bank_name' => 'BCA', 'npwp' => '123']))
            ->assertRedirect(route('supplier.company-profile.show'));

        $request = SupplierChangeRequest::sole();
        $this->assertSame(SupplierChangeRequest::TYPE_CONTACT, $request->change_type);
        $this->assertSame(SupplierChangeRequest::STATUS_PENDING, $request->status);
        $this->assertSame($this->contact(), $request->proposed_data);
        $this->assertSame(['pic_name' => null, 'pic_email' => null, 'pic_phone' => null], $request->current_data_snapshot);
        $this->assertSame(1, $purchasing->notifications()->count());
        $this->assertNull($supplier->supplier->fresh()->pic_name);
    }

    public function test_contact_request_requires_all_fields(): void
    {
        $supplier = $this->supplier();

        $this->actingAs($supplier)
            ->post(route('supplier.company-profile.contact-requests.store'), ['pic_name' => '', 'pic_email' => 'not-an-email'])
            ->assertSessionHasErrors(['pic_name', 'pic_email', 'pic_phone']);
        $this->assertDatabaseCount('supplier_change_requests', 0);
    }

    public function test_contact_request_returns_json_redirect_for_async_forms(): void
    {
        $supplier = $this->supplier();

        $this->actingAs($supplier)
            ->postJson(route('supplier.company-profile.contact-requests.store'), $this->contact())
            ->assertOk()
            ->assertJson(['redirect' => route('supplier.company-profile.show')]);
    }

    public function test_new_submission_supersedes_the_pending_one(): void
    {
        $supplier = $this->supplier();

        $this->actingAs($supplier)->post(route('supplier.company-profile.contact-requests.store'), $this->contact());
        $this->actingAs($supplier)->post(route('supplier.company-profile.contact-requests.store'), $this->contact(['pic_name' => 'Yuki Tanaka']));

        $this->assertSame(1, SupplierChangeRequest::pending()->count());
        $this->assertSame('Yuki Tanaka', SupplierChangeRequest::pending()->sole()->proposed_data['pic_name']);
        $this->assertSame(1, SupplierChangeRequest::where('status', SupplierChangeRequest::STATUS_CANCELLED)->count());
    }

    public function test_purchasing_approves_contact_request_and_supplier_is_notified(): void
    {
        $supplier = $this->supplier();
        $purchasing = $this->staff('purchasing');
        $this->actingAs($supplier)->post(route('supplier.company-profile.contact-requests.store'), $this->contact());
        $request = SupplierChangeRequest::sole();

        $this->actingAs($purchasing)
            ->post(route('purchasing.import-suppliers.contact-requests.approve', $request))
            ->assertRedirect()
            ->assertSessionHas('success');

        $profile = $supplier->supplier->fresh();
        $this->assertSame('Kenji Sato', $profile->pic_name);
        $this->assertSame('kenji.sato@daido.example', $profile->pic_email);
        $this->assertSame(SupplierChangeRequest::STATUS_APPROVED, $request->fresh()->status);
        $this->assertTrue(LocalFinanceAuditLog::where('action', 'vendor_contact_approved')->exists());
        $this->assertSame(1, $supplier->notifications()->count());
    }

    public function test_reject_requires_notes_and_keeps_contact(): void
    {
        $supplier = $this->supplier(['import'], ['pic_name' => 'Old PIC']);
        $purchasing = $this->staff('purchasing');
        $this->actingAs($supplier)->post(route('supplier.company-profile.contact-requests.store'), $this->contact());
        $request = SupplierChangeRequest::sole();

        $this->actingAs($purchasing)
            ->post(route('purchasing.import-suppliers.contact-requests.reject', $request), ['notes' => ''])
            ->assertSessionHasErrors('notes');

        $this->actingAs($purchasing)
            ->post(route('purchasing.import-suppliers.contact-requests.reject', $request), ['notes' => 'Phone number unreachable'])
            ->assertRedirect();

        $this->assertSame(SupplierChangeRequest::STATUS_REJECTED, $request->fresh()->status);
        $this->assertSame('Old PIC', $supplier->supplier->fresh()->pic_name);
        $this->assertSame(1, $supplier->notifications()->count());
    }

    public function test_direct_update_cancels_pending_request_and_is_audited(): void
    {
        $supplier = $this->supplier();
        $purchasing = $this->staff('purchasing');
        $this->actingAs($supplier)->post(route('supplier.company-profile.contact-requests.store'), $this->contact());

        $this->actingAs($purchasing)
            ->patch(route('purchasing.import-suppliers.contact.update', $supplier), $this->contact([
                'pic_name' => 'Hiro Mori',
                'reason' => 'Confirmed by phone with the supplier sales office',
            ]))
            ->assertRedirect();

        $this->assertSame('Hiro Mori', $supplier->supplier->fresh()->pic_name);
        $this->assertSame(0, SupplierChangeRequest::pending()->count());
        $this->assertTrue(LocalFinanceAuditLog::where('action', 'vendor_contact_updated')->exists());
    }

    public function test_direct_update_requires_reason(): void
    {
        $supplier = $this->supplier();

        $this->actingAs($this->staff('purchasing'))
            ->patch(route('purchasing.import-suppliers.contact.update', $supplier), $this->contact())
            ->assertSessionHasErrors('reason');
        $this->assertNull($supplier->supplier->fresh()->pic_name);
    }

    public function test_stale_request_is_not_applied(): void
    {
        $supplier = $this->supplier();
        $purchasing = $this->staff('purchasing');
        $this->actingAs($supplier)->post(route('supplier.company-profile.contact-requests.store'), $this->contact());
        $request = SupplierChangeRequest::sole();

        // Simulates a change applied through another path, e.g. a Local profile approval.
        $supplier->supplier->update(['pic_name' => 'Changed Elsewhere']);

        $this->actingAs($purchasing)
            ->post(route('purchasing.import-suppliers.contact-requests.approve', $request))
            ->assertSessionHas('error');

        $this->assertSame('Changed Elsewhere', $supplier->supplier->fresh()->pic_name);
        $this->assertSame(SupplierChangeRequest::STATUS_PENDING, $request->fresh()->status);
    }

    public function test_finance_and_other_suppliers_cannot_reach_import_contact_routes(): void
    {
        $supplier = $this->supplier();
        $other = $this->supplier();
        $this->actingAs($supplier)->post(route('supplier.company-profile.contact-requests.store'), $this->contact());
        $request = SupplierChangeRequest::sole();

        $this->actingAs($this->staff('finance'))
            ->post(route('purchasing.import-suppliers.contact-requests.approve', $request))
            ->assertForbidden();
        $this->actingAs($other)
            ->get(route('purchasing.import-suppliers.show', $supplier))
            ->assertForbidden();
        $this->actingAs($other)->get(route('supplier.company-profile.show'))
            ->assertOk()
            ->assertDontSee('Kenji Sato');
    }

    public function test_contact_requests_stay_out_of_finance_and_local_vendor_queues(): void
    {
        $supplier = $this->supplier(['import', 'local']);
        $this->actingAs($supplier)->post(route('supplier.company-profile.contact-requests.store'), $this->contact());
        $request = SupplierChangeRequest::sole();

        $this->actingAs($this->staff('finance'))->get(route('finance.vendor-master.index'))
            ->assertOk()
            ->assertDontSee(route('finance.vendor-change-requests.approve', $request), false);
        $this->actingAs($this->staff('purchasing'))->get(route('purchasing.local-vendors.index'))
            ->assertOk()
            ->assertDontSee(route('purchasing.local-vendors.change-requests.approve', $request), false);
    }

    public function test_purchasing_directory_lists_import_suppliers_and_filters_pending(): void
    {
        $pending = $this->supplier(['import'], ['company_name' => 'Pending Steel Ltd']);
        $this->supplier(['import'], ['company_name' => 'Quiet Metals Ltd']);
        $this->supplier(['local'], ['company_name' => 'Local Only Tbk']);
        $this->actingAs($pending)->post(route('supplier.company-profile.contact-requests.store'), $this->contact());
        $purchasing = $this->staff('purchasing');

        // The work queue (awaiting review) is the default tab.
        $this->actingAs($purchasing)->get(route('purchasing.import-suppliers.index'))
            ->assertOk()
            ->assertSee('Pending Steel Ltd')
            ->assertDontSee('Quiet Metals Ltd');

        $this->actingAs($purchasing)->get(route('purchasing.import-suppliers.index', ['contact' => 'all']))
            ->assertOk()
            ->assertSee('Pending Steel Ltd')
            ->assertSee('Quiet Metals Ltd')
            ->assertDontSee('Local Only Tbk');

        $this->actingAs($purchasing)->get(route('purchasing.import-suppliers.index', ['contact' => 'missing', 'q' => 'Quiet']))
            ->assertOk()
            ->assertSee('Quiet Metals Ltd')
            ->assertDontSee('Pending Steel Ltd');

        $this->actingAs($purchasing)->get(route('purchasing.import-suppliers.show', $pending))
            ->assertOk()
            ->assertSee('Kenji Sato');
    }

    public function test_directory_tabs_return_server_tabs_fragment_without_full_page(): void
    {
        $this->supplier(['import'], ['company_name' => 'Quiet Metals Ltd']);
        $purchasing = $this->staff('purchasing');

        $response = $this->actingAs($purchasing)
            ->withHeader(ServerTabsResponse::HEADER, '1')
            ->getJson(route('purchasing.import-suppliers.index', ['contact' => 'missing']))
            ->assertOk()
            ->assertJsonPath('tab', 'missing');

        $this->assertStringContainsString('Quiet Metals Ltd', $response->json('html'));
        $this->assertStringNotContainsString('<html', $response->json('html'));
        $this->assertStringContainsString('aria-current="page"', $response->json('nav'));
        $this->assertStringContainsString('data-tab-name="missing"', $response->json('nav'));

        $this->actingAs($purchasing)
            ->get(route('purchasing.import-suppliers.index', ['contact' => 'unknown']))
            ->assertSessionHasErrors('contact');
    }

    public function test_raw_integer_vendor_id_is_rejected(): void
    {
        $supplier = $this->supplier();

        $this->actingAs($this->staff('purchasing'))
            ->get('/purchasing/import-suppliers/'.$supplier->id)
            ->assertNotFound();
    }

    public function test_local_profile_request_cannot_be_approved_from_import_queue(): void
    {
        $supplier = $this->supplier(['import']);
        $request = SupplierChangeRequest::create([
            'supplier_id' => $supplier->id,
            'change_type' => SupplierChangeRequest::TYPE_PROFILE,
            'current_data_snapshot' => [],
            'proposed_data' => ['company_name' => 'Renamed'],
            'status' => SupplierChangeRequest::STATUS_PENDING,
            'requested_by' => $supplier->id,
            'requested_at' => now(),
        ]);

        $this->actingAs($this->staff('purchasing'))
            ->post(route('purchasing.import-suppliers.contact-requests.approve', $request))
            ->assertNotFound();
        $this->assertSame('Daido Trading Co.', $supplier->supplier->fresh()->company_name);
    }

    public function test_purchasing_can_register_import_supplier_with_minimal_fields(): void
    {
        $purchasing = $this->staff('purchasing');

        $this->actingAs($purchasing)
            ->get(route('purchasing.import-suppliers.create'))
            ->assertOk()
            ->assertSee(__('supplier_contact.register.title'));

        $response = $this->actingAs($purchasing)
            ->post(route('purchasing.import-suppliers.store'), [
                'company_name' => '  Nippon Special Steel Corp.  ',
                'email' => '  SALES@NipponSteel.example  ',
                'password' => 'StrongPass#2026!',
                'password_confirmation' => 'StrongPass#2026!',
            ]);

        $user = User::where('email', 'sales@nipponsteel.example')->firstOrFail();
        $response->assertRedirect(route('purchasing.import-suppliers.show', $user))
            ->assertSessionHas('success', __('supplier_contact.register.created'));

        $this->assertSame('Nippon Special Steel Corp.', $user->name);
        $this->assertSame('supplier', $user->role);
        $this->assertTrue((bool) $user->is_active);
        $this->assertSame(User::ACCOUNT_STATUS_ACTIVE, $user->account_status);
        $this->assertTrue((bool) $user->must_change_password);
        $this->assertSame(['import'], $user->supplierScopes()->pluck('scope')->all());
        $this->assertTrue($user->isImportEligible());
        $this->assertFalse($user->isLocalEligible());
        $this->assertSame('Nippon Special Steel Corp.', $user->supplier->company_name);
        $this->assertNull($user->supplier->address);
        $this->assertNull($user->supplier->pic_name);
        $this->assertTrue(LocalFinanceAuditLog::where('action', 'import_supplier_registered')->where('actor_id', $purchasing->id)->exists());
        $this->assertSame(1, $user->notifications()->where('data->event', 'auth.password_set_by_admin')->count());
    }

    public function test_non_purchasing_roles_cannot_register_import_supplier(): void
    {
        foreach (['finance', 'qc'] as $role) {
            $staff = $this->staff($role);
            $this->actingAs($staff)->get(route('purchasing.import-suppliers.create'))->assertForbidden();
            $this->actingAs($staff)->post(route('purchasing.import-suppliers.store'), [
                'company_name' => 'Blocked Co.',
                'email' => $role.'-blocked@example.com',
                'password' => 'StrongPass#2026!',
                'password_confirmation' => 'StrongPass#2026!',
            ])->assertForbidden();
        }

        $supplier = $this->supplier();
        $this->actingAs($supplier)->get(route('purchasing.import-suppliers.create'))->assertForbidden();
        $this->assertNull(User::where('email', 'finance-blocked@example.com')->first());
    }

    public function test_register_import_supplier_validates_duplicate_email_and_password_policy(): void
    {
        $existing = $this->supplier();
        $purchasing = $this->staff('purchasing');

        $this->actingAs($purchasing)
            ->post(route('purchasing.import-suppliers.store'), [
                'company_name' => 'Duplicate Ltd',
                'email' => strtoupper($existing->email),
                'password' => 'short',
                'password_confirmation' => 'mismatch',
            ])
            ->assertSessionHasErrors(['email', 'password']);
    }

    public function test_company_details_can_be_completed_by_supplier_proposal_or_purchasing_direct_edit(): void
    {
        $supplier = $this->supplier(['import'], ['address' => null, 'phone' => null, 'category' => null]);
        $purchasing = $this->staff('purchasing');

        // 1. Import supplier proposes contact + company details (address, phone, category)
        $this->actingAs($supplier)
            ->post(route('supplier.company-profile.contact-requests.store'), $this->contact([
                'address' => '1-2-3 Marunouchi, Chiyoda-ku, Tokyo',
                'phone' => '+81 3 9999 0000',
                'category' => 'Alloy Steel',
            ]))
            ->assertRedirect(route('supplier.company-profile.show'));

        $changeRequest = SupplierChangeRequest::sole();
        $this->assertSame('1-2-3 Marunouchi, Chiyoda-ku, Tokyo', $changeRequest->proposed_data['address']);
        $this->assertSame('+81 3 9999 0000', $changeRequest->proposed_data['phone']);
        $this->assertSame('Alloy Steel', $changeRequest->proposed_data['category']);

        $this->actingAs($purchasing)
            ->post(route('purchasing.import-suppliers.contact-requests.approve', $changeRequest))
            ->assertRedirect();

        $profile = $supplier->supplier->fresh();
        $this->assertSame('1-2-3 Marunouchi, Chiyoda-ku, Tokyo', $profile->address);
        $this->assertSame('+81 3 9999 0000', $profile->phone);
        $this->assertSame('Alloy Steel', $profile->category);
        $this->assertSame('Kenji Sato', $profile->pic_name);

        // 2. Purchasing edits company details and contact directly
        $this->actingAs($purchasing)
            ->patch(route('purchasing.import-suppliers.contact.update', $supplier), $this->contact([
                'company_name' => 'Daido Trading International Co.',
                'address' => 'Nagoya, Aichi, Japan',
                'phone' => '+81 52 111 2222',
                'category' => 'Tool Steel',
                'reason' => 'Updated from signed PO header sheet',
            ]))
            ->assertRedirect();

        $profile->refresh();
        $this->assertSame('Daido Trading International Co.', $profile->company_name);
        $this->assertSame('Daido Trading International Co.', $supplier->fresh()->name);
        $this->assertSame('Nagoya, Aichi, Japan', $profile->address);
        $this->assertSame('+81 52 111 2222', $profile->phone);
        $this->assertSame('Tool Steel', $profile->category);
    }
}
