<?php

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Models\Period;
use App\Models\PoDocument;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PoDocumentStatusValidationTest extends TestCase
{
    use RefreshDatabase;

    private User $purchasing;
    private User $supplier;
    private PurchaseOrder $po;
    private PoDocument $document;

    protected function setUp(): void
    {
        parent::setUp();

        $this->purchasing = User::factory()->create(['role' => 'purchasing']);
        $this->supplier = User::factory()->create(['role' => 'supplier']);
        Supplier::create([
            'user_id' => $this->supplier->id,
            'company_name' => 'Test Supplier',
        ]);

        $period = Period::create([
            'name' => 'PO Doc Period',
            'month' => 9,
            'year' => 2026,
            'status' => 'open',
            'created_by' => $this->purchasing->id,
        ]);

        $rate = ExchangeRate::create([
            'currency' => 'USD',
            'rate_to_idr' => 16000,
            'valid_from' => now()->subDay(),
            'created_by' => $this->purchasing->id,
        ]);

        $pr = PurchaseRequisition::create([
            'period_id' => $period->id,
            'created_by' => $this->purchasing->id,
            'pr_number' => 'REQ/09/2026/001',
            'status' => 'submitted',
        ]);

        $quotation = Quotation::create([
            'pr_id' => $pr->id,
            'supplier_id' => $this->supplier->id,
            'currency' => 'USD',
            'exchange_rate_id' => $rate->id,
            'status' => Quotation::STATUS_SUBMITTED,
            'submitted_at' => now(),
        ]);

        $this->po = PurchaseOrder::create([
            'po_number' => 'PO/09/2026/001',
            'supplier_id' => $this->supplier->id,
            'currency' => 'USD',
            'exchange_rate_id' => $rate->id,
            'status' => 'active',
            'created_by' => $this->purchasing->id,
            'estimated_arrival' => now()->addDays(14),
        ]);

        $this->po->quotations()->attach($quotation->id);

        $this->document = PoDocument::create([
            'po_id' => $this->po->id,
            'doc_type' => 'invoice',
            'status' => 'pending',
        ]);
    }

    public function test_all_valid_po_document_statuses_are_accepted(): void
    {
        foreach (PoDocument::STATUSES as $validStatus) {
            $response = $this->actingAs($this->purchasing)
                ->putJson(route('purchasing.po-documents.update', $this->document), [
                    'status' => $validStatus,
                ]);

            $response->assertOk()
                ->assertJson([
                    'success' => true,
                    'doc' => [
                        'id' => $this->document->id,
                        'status' => $validStatus,
                    ],
                ]);

            $this->assertSame($validStatus, $this->document->fresh()->status);
        }
    }

    public function test_invalid_status_value_is_rejected_with_422(): void
    {
        $response = $this->actingAs($this->purchasing)
            ->putJson(route('purchasing.po-documents.update', $this->document), [
                'status' => 'invalid_status_value',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        // Database remains unchanged
        $this->assertSame('pending', $this->document->fresh()->status);
    }

    public function test_null_status_value_is_rejected_with_422(): void
    {
        $response = $this->actingAs($this->purchasing)
            ->putJson(route('purchasing.po-documents.update', $this->document), [
                'status' => null,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        $this->assertSame('pending', $this->document->fresh()->status);
    }

    public function test_empty_string_status_is_rejected_with_422(): void
    {
        $response = $this->actingAs($this->purchasing)
            ->putJson(route('purchasing.po-documents.update', $this->document), [
                'status' => '',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        $this->assertSame('pending', $this->document->fresh()->status);
    }

    public function test_integer_status_is_rejected_with_422(): void
    {
        $response = $this->actingAs($this->purchasing)
            ->putJson(route('purchasing.po-documents.update', $this->document), [
                'status' => 12345,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        $this->assertSame('pending', $this->document->fresh()->status);
    }

    public function test_malicious_string_status_is_rejected_with_422_without_query_exception(): void
    {
        $response = $this->actingAs($this->purchasing)
            ->putJson(route('purchasing.po-documents.update', $this->document), [
                'status' => "pending'; DROP TABLE po_documents; --",
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        $this->assertSame('pending', $this->document->fresh()->status);
    }
}
