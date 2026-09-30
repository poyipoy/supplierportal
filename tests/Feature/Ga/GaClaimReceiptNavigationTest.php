<?php

namespace Tests\Feature\Ga;

use App\Models\Employee;
use App\Models\GaClaim;
use App\Models\User;
use App\Services\Ga\GaClaimService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GaClaimReceiptNavigationTest extends TestCase
{
    use RefreshDatabase;

    private User $ga;

    private User $finance;

    private User $supplier;

    private GaClaim $claim;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');

        $this->ga = User::factory()->create(['role' => 'ga', 'is_active' => true]);
        $this->finance = User::factory()->create(['role' => 'finance', 'is_active' => true]);
        $this->supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);

        $employee = Employee::create([
            'name' => 'Budi GA',
            'department' => 'Operations',
            'bank_name' => 'BCA',
            'account_number' => '1234567890',
            'account_holder_name' => 'Budi GA',
            'is_active' => true,
        ]);

        $service = app(GaClaimService::class);
        $this->claim = $service->submitClaim(
            $this->ga,
            [
                'employee_id' => $employee->id,
                'claim_type' => GaClaim::TYPE_REIMBURSE_CLAIM,
                'claim_date' => '2026-09-20',
                'amount' => 500000,
                'description' => 'Operasional Test',
            ],
            [
                'supporting' => UploadedFile::fake()->create('note.pdf', 100),
            ]
        );
    }

    public function test_finance_can_open_ga_claim_receipt_without_403_and_return_to_detail(): void
    {
        // 1. Finance views the detail page and sees the receipt button
        $detailResponse = $this->actingAs($this->finance)
            ->get(route('finance.ga-claims.show', $this->claim));

        $detailResponse->assertOk();
        $detailResponse->assertSee(route('ga.claims.receipt', $this->claim));

        // 2. Finance clicks the receipt button -> Must NOT return 403 Forbidden
        $receiptResponse = $this->actingAs($this->finance)
            ->get(route('ga.claims.receipt', $this->claim));

        $receiptResponse->assertOk();

        // 3. Receipt view contains link back to Finance detail
        $receiptResponse->assertSee(route('finance.ga-claims.show', $this->claim));
    }

    public function test_ga_can_open_receipt_and_see_link_back_to_ga_detail(): void
    {
        $receiptResponse = $this->actingAs($this->ga)
            ->get(route('ga.claims.receipt', $this->claim));

        $receiptResponse->assertOk();
        $receiptResponse->assertSee(route('ga.claims.show', $this->claim));
    }

    public function test_unauthorized_user_cannot_access_ga_claim_receipt(): void
    {
        $response = $this->actingAs($this->supplier)
            ->get(route('ga.claims.receipt', $this->claim));

        $response->assertForbidden();
    }
}
