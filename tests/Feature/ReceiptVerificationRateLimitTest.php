<?php

namespace Tests\Feature;

use App\Models\LocalInvoice;
use App\Models\LocalInvoiceReceipt;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class ReceiptVerificationRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_unsigned_receipt_verification_request_is_rejected(): void
    {
        $response = $this->get('/verify-receipt/supplier/REC-12345');
        $response->assertStatus(403);
    }

    public function test_unsigned_ga_receipt_verification_request_is_rejected(): void
    {
        $response = $this->get('/verify-receipt/ga/REC-GA-12345');
        $response->assertStatus(403);
    }

    public function test_valid_signed_supplier_receipt_verification_renders_successfully(): void
    {
        $user = User::factory()->create(['role' => 'supplier', 'name' => 'Supplier John']);
        $user->supplierScopes()->create(['scope' => 'local']);
        Supplier::create([
            'user_id' => $user->id,
            'company_name' => 'PT Supplier Utama',
            'address' => 'Jl. Industri No. 1',
            'phone' => '021-123456',
            'npwp' => '01.234.567.8-901.000',
            'category' => 'Local Material',
            'payment_term_days' => 30,
        ]);

        $invoice = LocalInvoice::create([
            'supplier_id' => $user->id,
            'created_by' => $user->id,
            'invoice_number' => 'INV-2026-001',
            'submission_number' => 'SUB-2026-001',
            'po_number' => 'PO-LOCAL-001',
            'invoice_date' => '2026-09-01',
            'due_date' => '2026-10-01',
            'status' => LocalInvoice::STATUS_WAITING_PHYSICAL_DOCUMENT,
            'invoice_amount' => '1000000.00',
            'tax_amount' => '110000.00',
            'submitted_at' => now(),
        ]);

        $receipt = LocalInvoiceReceipt::create([
            'local_invoice_id' => $invoice->id,
            'receipt_number' => 'REC-20260901-0001',
            'issued_at' => now(),
            'received_by' => 'Cashier 1',
        ]);

        $signedUrl = URL::signedRoute('receipts.verify-supplier', ['receipt' => $receipt->receipt_number]);

        $response = $this->get($signedUrl);
        $response->assertOk()
            ->assertViewIs('receipts.verify-supplier')
            ->assertSee($receipt->receipt_number);
    }

    public function test_receipt_verification_routes_are_rate_limited(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $response = $this->getJson('/verify-receipt/supplier/PROBE-'.$i);
            $response->assertStatus(403);
        }

        // 61st request in the same 1-minute window must be throttled with 429
        $response = $this->getJson('/verify-receipt/supplier/PROBE-61');
        $response->assertStatus(429);
    }
}
