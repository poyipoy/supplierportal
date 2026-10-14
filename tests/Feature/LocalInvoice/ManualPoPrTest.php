<?php

namespace Tests\Feature\LocalInvoice;

use App\Models\LocalPurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ManualPoPrTest extends TestCase
{
    use RefreshDatabase;

    private function vendor(): User
    {
        $user = User::factory()->create(['role' => 'supplier']);
        $user->supplierScopes()->create(['scope' => 'local']);
        Supplier::create(['user_id' => $user->id, 'company_name' => 'Manual PR Vendor', 'category' => 'Parts', 'is_pkp' => false, 'payment_term_days' => 30]);

        return $user;
    }

    private function data(User $supplier, array $extra = []): array
    {
        return array_merge(['po_number' => 'MANUAL-PR-PO', 'supplier_id' => $supplier->id, 'po_date' => '2026-10-14', 'total_amount' => '1000.00'], $extra);
    }

    public function test_manual_create_preserves_new_existing_empty_and_leading_zero_pr(): void
    {
        $vendor = $this->vendor();
        $this->actingAs(User::factory()->create(['role' => 'finance']));
        foreach ([' 000PR-01 ', '000PR-01', '', '0'] as $i => $value) {
            $data = $this->data($vendor, ['po_number' => 'MANUAL-'.$i, 'pr_number' => $value]);
            $this->post(route('finance.local-procurement.store'), $data)->assertRedirect();
            $this->assertDatabaseHas('local_purchase_orders', ['po_number' => 'MANUAL-'.$i, 'pr_number' => trim($value) === '' ? null : trim($value)]);
        }
    }

    public function test_edit_fills_empty_pr_and_refuses_replacement_and_erasure(): void
    {
        $vendor = $this->vendor();
        $po = LocalPurchaseOrder::create($this->data($vendor, ['currency' => 'IDR', 'status' => 'OPEN']));
        $this->actingAs(User::factory()->create(['role' => 'finance']));
        $this->put(route('finance.local-procurement.update', $po), $this->data($vendor, ['pr_number' => ' PR-EDIT ']))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('PR-EDIT', $po->fresh()->pr_number);
        foreach (['PR-DIFFERENT', ''] as $value) {
            $this->putJson(route('finance.local-procurement.update', $po), $this->data($vendor, ['pr_number' => $value]))->assertUnprocessable()->assertJsonValidationErrors('pr_number');
            $this->assertSame('PR-EDIT', $po->fresh()->pr_number);
        }
        $this->put(route('finance.local-procurement.update', $po), $this->data($vendor))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('PR-EDIT', $po->fresh()->pr_number);
        $this->assertDatabaseHas('local_finance_audit_logs', ['action' => 'po_pr_filled', 'auditable_id' => $po->id]);
    }

    public function test_invalid_pr_is_rejected_and_old_input_is_retained(): void
    {
        $vendor = $this->vendor();
        $this->actingAs(User::factory()->create(['role' => 'finance']));
        foreach (['=1+1', 'PR-A;PR-B', "PR-A\nPR-B", str_repeat('a', 101)] as $pr) {
            $this->postJson(route('finance.local-procurement.store'), $this->data($vendor, ['pr_number' => $pr]))->assertUnprocessable()->assertJsonValidationErrors('pr_number');
        }
        $this->post(route('finance.local-procurement.store'), $this->data($vendor, ['pr_number' => 'PR-KEEP', 'total_amount' => '-1']))->assertRedirect()->assertSessionHasInput('pr_number', 'PR-KEEP');
        $this->assertDatabaseCount('local_purchase_orders', 0);
    }

    public function test_search_is_cross_supplier_case_unique_bounded_and_metadata_only(): void
    {
        $vendor = $this->vendor();
        foreach (array_merge(['PR-SHARED', 'pr-shared'], array_map(fn ($n) => 'PR-'.str_pad($n, 3, '0', STR_PAD_LEFT), range(1, 25))) as $i => $pr) {
            LocalPurchaseOrder::create($this->data($i === 0 ? $vendor : $this->vendor(), ['po_number' => 'SUGGEST-'.$i, 'pr_number' => $pr, 'currency' => 'IDR', 'status' => 'OPEN']));
        }
        foreach (['finance', 'purchasing', 'admin'] as $role) {
            $prefix = $role === 'purchasing' ? 'purchasing' : 'finance';
            $this->actingAs(User::factory()->create(['role' => $role]));
            $response = $this->getJson(route($prefix.'.local-procurement.pr-suggestions', ['q' => 'PR']))->assertOk()->assertJsonCount(20, 'data');
            foreach ($response->json('data') as $entry) {
                $this->assertSame(['pr_number'], array_keys($entry));
            }
            $this->getJson(route($prefix.'.local-procurement.pr-suggestions', ['q' => 'shared']))->assertOk()->assertJsonCount(1, 'data');
            $this->getJson(route($prefix.'.local-procurement.pr-suggestions', ['q' => 'P']))->assertOk()->assertExactJson(['data' => []]);
            $this->getJson(route($prefix.'.local-procurement.pr-suggestions', ['q' => '%_']))->assertOk()->assertExactJson(['data' => []]);
        }
        $this->actingAs($vendor)->getJson(route('finance.local-procurement.pr-suggestions', ['q' => 'PR']))->assertForbidden();
    }

    public function test_form_uses_optional_free_input_mode_and_existing_pr_is_readonly(): void
    {
        $vendor = $this->vendor();
        $this->actingAs(User::factory()->create(['role' => 'finance']));
        $this->get(route('finance.local-procurement.create'))->assertOk()->assertSee('role="combobox"', false)->assertSee('name="pr_number"', false);
        $po = LocalPurchaseOrder::create($this->data($vendor, ['pr_number' => 'PR-LOCKED', 'currency' => 'IDR', 'status' => 'OPEN']));
        $this->get(route('finance.local-procurement.edit', $po))->assertOk()->assertSee('PR-LOCKED')->assertSee('readonly', false);
    }

    public function test_manual_pr_browser_fixtures_render_for_both_locales(): void
    {
        $vendor = $this->vendor();
        $po = LocalPurchaseOrder::create($this->data($vendor, ['pr_number' => 'PR-REGISTERED', 'currency' => 'IDR', 'status' => 'OPEN']));
        foreach (['en', 'id'] as $locale) {
            $actor = User::factory()->create(['role' => 'finance']);
            $actor->preference()->updateOrCreate([], [...config('user_preferences.defaults'), 'locale' => $locale]);
            $this->actingAs($actor);
            $pages = ['create' => $this->get(route('finance.local-procurement.create'))->assertOk(),
                'edit' => $this->get(route('finance.local-procurement.edit', $po))->assertOk()];
            if (getenv('MANUAL_PR_UI_FIXTURES') === '1') {
                $root = storage_path('framework/testing/manual-pr-ui');
                File::ensureDirectoryExists($root);
                foreach ($pages as $name => $response) {
                    file_put_contents($root.'/'.$locale.'-'.$name.'.html', str_replace([url('/'), config('app.url')], '', $response->getContent()));
                }
            }
        }
    }
}
