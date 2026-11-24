<?php

namespace Tests\Feature;

use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class ExportPreviewCountTest extends TestCase
{
    use RefreshDatabase;

    private User $purchasing;

    private User $supplier;

    private User $otherSupplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->purchasing = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);
        $this->supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        $this->otherSupplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);

        PurchaseOrder::create([
            'supplier_id' => $this->supplier->id,
            'currency' => 'USD',
            'po_number' => 'PO/11/2026/101',
            'status' => 'active',
            'created_by' => $this->purchasing->id,
            'estimated_arrival' => '2099-01-01',
        ]);
        PurchaseOrder::create([
            'supplier_id' => $this->supplier->id,
            'currency' => 'USD',
            'po_number' => 'PO/11/2026/102',
            'status' => 'completed',
            'created_by' => $this->purchasing->id,
            'estimated_arrival' => '2099-01-01',
        ]);
        PurchaseOrder::create([
            'supplier_id' => $this->otherSupplier->id,
            'currency' => 'USD',
            'po_number' => 'PO/11/2026/103',
            'status' => 'active',
            'created_by' => $this->purchasing->id,
            'estimated_arrival' => '2099-01-01',
        ]);
    }

    public function test_preview_count_filters_accurately_and_forces_supplier_tenant_scope(): void
    {
        $this->postJson(route('exports.preview-count'), ['export_key' => 'purchasing.po'])
            ->assertUnauthorized();

        // Purchasing sees all 3 POs unfiltered, or 2 active POs filtered, or 0 for non-matching search
        $response = $this->actingAs($this->purchasing)
            ->postJson(route('exports.preview-count'), ['export_key' => 'purchasing.po'])
            ->assertOk()
            ->assertExactJson([
                'total_rows' => 3,
                'max_rows' => 100000,
                'exceeds_limit' => false,
                'is_empty' => false,
            ]);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $this->actingAs($this->purchasing)
            ->postJson(route('exports.preview-count'), ['export_key' => 'purchasing.po', 'status' => 'active'])
            ->assertOk()
            ->assertJsonPath('total_rows', 2)
            ->assertJsonPath('is_empty', false);

        $this->actingAs($this->purchasing)
            ->postJson(route('exports.preview-count'), ['export_key' => 'purchasing.po', 'po_number' => 'DOES-NOT-EXIST'])
            ->assertOk()
            ->assertJsonPath('total_rows', 0)
            ->assertJsonPath('is_empty', true);

        // Supplier cannot query purchasing.po
        $this->actingAs($this->supplier)
            ->postJson(route('exports.preview-count'), ['export_key' => 'purchasing.po'])
            ->assertForbidden();

        // Supplier preview forces supplier_id = auth user even if foreign supplier hash is supplied
        $this->actingAs($this->supplier)
            ->postJson(route('exports.preview-count'), [
                'export_key' => 'supplier.po',
                'supplier_id' => $this->otherSupplier->hash,
                'status' => 'active',
            ])
            ->assertOk()
            ->assertJsonPath('total_rows', 1);

        // Unknown export_key returns 404
        $this->actingAs($this->purchasing)
            ->postJson(route('exports.preview-count'), ['export_key' => 'unknown.key'])
            ->assertNotFound();
    }

    public function test_preview_count_reports_exceeds_limit_and_enforces_rate_limit(): void
    {
        config(['exports.max_rows' => 2]);

        $this->actingAs($this->purchasing)
            ->postJson(route('exports.preview-count'), ['export_key' => 'purchasing.po'])
            ->assertOk()
            ->assertJsonPath('total_rows', 3)
            ->assertJsonPath('max_rows', 2)
            ->assertJsonPath('exceeds_limit', true)
            ->assertJsonPath('is_empty', false);

        config(['exports.max_rows' => 100000]);

        // Rate limit: 30 requests per minute on exports.preview-count
        $throttled = false;
        for ($i = 0; $i < 35; $i++) {
            $res = $this->actingAs($this->purchasing)
                ->postJson(route('exports.preview-count'), ['export_key' => 'purchasing.po']);
            if ($res->status() === 429) {
                $throttled = true;
                break;
            }
            $res->assertOk();
        }

        $this->assertTrue($throttled, 'Expected exports.preview-count to enforce throttle:30,1.');
        RateLimiter::clear('exports.preview-count:'.$this->purchasing->id);
    }
}
