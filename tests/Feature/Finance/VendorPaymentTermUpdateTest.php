<?php

namespace Tests\Feature\Finance;

use App\Models\LocalFinanceAuditLog;
use App\Models\Supplier;
use App\Models\SupplierScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VendorPaymentTermUpdateTest extends TestCase
{
    use RefreshDatabase;

    private User $vendor;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vendor = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        SupplierScope::create(['supplier_id' => $this->vendor->id, 'scope' => 'local']);
        $this->supplier = Supplier::create([
            'user_id' => $this->vendor->id,
            'company_name' => 'PT Termin Uji',
            'vendor_category' => 'Barang',
            'is_pkp' => true,
            'payment_term_days' => 30,
        ]);
    }

    private function confirmed(User $user): static
    {
        return $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge(['payment_term_days' => 45, 'reason' => 'Kesepakatan kontrak baru 2026'], $overrides);
    }

    public function test_finance_updates_payment_term_with_audit_trail(): void
    {
        $finance = User::factory()->create(['role' => 'finance']);

        $this->confirmed($finance)
            ->put(route('finance.vendor-master.payment-term.update', $this->vendor), $this->payload())
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('finance.vendor-master.show', $this->vendor))
            ->assertSessionHas('success');

        $this->assertSame(45, $this->supplier->fresh()->payment_term_days);

        $audit = LocalFinanceAuditLog::where('action', 'vendor_payment_term_updated')->sole();
        $this->assertSame(Supplier::class, $audit->auditable_type);
        $this->assertSame($this->supplier->id, (int) $audit->auditable_id);
        $this->assertSame($finance->id, (int) $audit->actor_id);
        $this->assertSame(['payment_term_days' => 30], $audit->before_values);
        $this->assertSame(['payment_term_days' => 45], $audit->after_values);
        $this->assertSame('Kesepakatan kontrak baru 2026', $audit->metadata['reason']);
    }

    public function test_admin_can_update_and_edit_page_renders(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->confirmed($admin)
            ->get(route('finance.vendor-master.payment-term.edit', $this->vendor))
            ->assertOk()
            ->assertSee('name="payment_term_days"', false)
            ->assertSee(__('local_procurement.payment_term.impact_none'));

        $this->confirmed($admin)
            ->put(route('finance.vendor-master.payment-term.update', $this->vendor), $this->payload(['payment_term_days' => 60]))
            ->assertSessionHasNoErrors();

        $this->assertSame(60, $this->supplier->fresh()->payment_term_days);
    }

    public function test_detail_page_shows_change_button_only_to_authorized_roles(): void
    {
        $finance = User::factory()->create(['role' => 'finance']);
        $editUrl = route('finance.vendor-master.payment-term.edit', $this->vendor);

        $this->actingAs($finance)->get(route('finance.vendor-master.show', $this->vendor))
            ->assertOk()->assertSee($editUrl, false);
    }

    public function test_other_roles_are_forbidden(): void
    {
        foreach (['supplier', 'purchasing', 'ga', 'qc'] as $role) {
            $user = User::factory()->create(['role' => $role]);

            $this->confirmed($user)->get(route('finance.vendor-master.payment-term.edit', $this->vendor))->assertForbidden();
            $this->confirmed($user)->put(route('finance.vendor-master.payment-term.update', $this->vendor), $this->payload())->assertForbidden();
        }

        // The vendor itself cannot change its own term either.
        $this->confirmed($this->vendor)->put(route('finance.vendor-master.payment-term.update', $this->vendor), $this->payload())->assertForbidden();

        $this->assertSame(30, $this->supplier->fresh()->payment_term_days);
        $this->assertSame(0, LocalFinanceAuditLog::count());
    }

    public function test_password_confirmation_is_required(): void
    {
        $finance = User::factory()->create(['role' => 'finance']);

        $this->actingAs($finance)->get(route('finance.vendor-master.payment-term.edit', $this->vendor))
            ->assertRedirect(route('password.confirm'));
        $this->actingAs($finance)->put(route('finance.vendor-master.payment-term.update', $this->vendor), $this->payload())
            ->assertRedirect(route('password.confirm'));

        $this->assertSame(30, $this->supplier->fresh()->payment_term_days);
    }

    public function test_invalid_input_is_rejected_without_audit(): void
    {
        $finance = User::factory()->create(['role' => 'finance']);
        $url = route('finance.vendor-master.payment-term.update', $this->vendor);

        foreach ([0, 366, -5, 'abc', '7.5', ''] as $days) {
            $this->confirmed($finance)->put($url, $this->payload(['payment_term_days' => $days]))
                ->assertSessionHasErrors('payment_term_days');
        }
        foreach (['', '   ', 'abc', str_repeat('x', 501)] as $reason) {
            $this->confirmed($finance)->put($url, $this->payload(['reason' => $reason]))
                ->assertSessionHasErrors('reason');
        }

        $this->assertSame(30, $this->supplier->fresh()->payment_term_days);
        $this->assertSame(0, LocalFinanceAuditLog::count());
    }

    public function test_unchanged_value_is_rejected_without_audit(): void
    {
        $finance = User::factory()->create(['role' => 'finance']);

        $this->confirmed($finance)
            ->put(route('finance.vendor-master.payment-term.update', $this->vendor), $this->payload(['payment_term_days' => 30]))
            ->assertSessionHasErrors('payment_term_days');

        $this->assertSame(0, LocalFinanceAuditLog::count());
    }

    public function test_only_payment_term_is_written(): void
    {
        $finance = User::factory()->create(['role' => 'finance']);

        $this->confirmed($finance)->put(route('finance.vendor-master.payment-term.update', $this->vendor), $this->payload([
            'company_name' => 'PT Diganti',
            'npwp' => '99.999.999.9-999.999',
            'is_pkp' => false,
            'user_id' => 999999,
        ]))->assertSessionHasNoErrors();

        $fresh = $this->supplier->fresh();
        $this->assertSame('PT Termin Uji', $fresh->company_name);
        $this->assertTrue((bool) $fresh->is_pkp);
        $this->assertSame($this->vendor->id, (int) $fresh->user_id);
        $this->assertSame(45, $fresh->payment_term_days);
    }

    public function test_non_local_or_inactive_vendor_and_raw_ids_are_not_found(): void
    {
        $finance = User::factory()->create(['role' => 'finance']);

        // UserFactory sudah memberi scope import; tanpa scope local vendor ini bukan bagian Vendor Master.
        $importOnly = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        $this->assertFalse($importOnly->isLocalEligible());
        Supplier::create(['user_id' => $importOnly->id, 'company_name' => 'PT Impor', 'payment_term_days' => 30]);

        $this->confirmed($finance)->get(route('finance.vendor-master.payment-term.edit', $importOnly))->assertNotFound();
        $this->confirmed($finance)->put(route('finance.vendor-master.payment-term.update', $importOnly), $this->payload())->assertNotFound();

        $this->vendor->update(['is_active' => false]);
        $this->confirmed($finance)->put(route('finance.vendor-master.payment-term.update', $this->vendor), $this->payload())->assertNotFound();

        $this->confirmed($finance)->put('/finance/vendor-master/'.$this->vendor->id.'/payment-term', $this->payload())->assertNotFound();

        $this->assertSame(0, LocalFinanceAuditLog::count());
    }
}
