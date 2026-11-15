<?php

namespace Tests\Feature\Security;

use App\Models\ExchangeRate;
use App\Models\LocalPurchaseOrder;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\Erp\CanonicalRowHasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\NativeFileFixtures;
use Tests\TestCase;
use ZipArchive;

class RustHybridSecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $supplierA;

    private User $supplierB;

    private User $importSupplier;

    private User $finance;

    private User $purchasing;

    private User $qc;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');

        $this->supplierA = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        $this->supplierA->supplierScopes()->delete();
        $this->supplierA->supplierScopes()->create(['scope' => 'local']);

        $this->supplierB = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        $this->supplierB->supplierScopes()->delete();
        $this->supplierB->supplierScopes()->create(['scope' => 'local']);

        $this->importSupplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);

        $this->finance = User::factory()->create(['role' => 'finance', 'is_active' => true]);
        $this->purchasing = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);
        $this->qc = User::factory()->create(['role' => 'qc', 'is_active' => true]);
    }

    public function test_unauthenticated_and_unauthorized_roles_are_rejected_from_erp_delta_endpoints(): void
    {
        $payload = [
            'rows' => [[
                'doc_no' => 'PO-AUTH-001',
                'item_code' => 'MAT-001',
                'qty' => '5.0000',
                'price' => '100.00',
                'status' => 'ACTIVE',
            ]],
        ];

        $this->getJson(route('local-supplier.erp-delta.hashes'))->assertUnauthorized();
        $this->postJson(route('local-supplier.erp-delta.sync'), $payload)->assertUnauthorized();

        $this->actingAs($this->qc)
            ->postJson(route('finance.local-procurement.erp-delta.sync'), [
                ...$payload,
                'supplier_id' => $this->supplierA->id,
            ])
            ->assertForbidden();

        // Scope isolation: local supplier cannot use import endpoints, and vice versa.
        $this->actingAs($this->supplierA)
            ->postJson(route('supplier.erp-delta.sync'), $payload)
            ->assertForbidden();

        $this->actingAs($this->importSupplier)
            ->postJson(route('local-supplier.erp-delta.sync'), $payload)
            ->assertForbidden();

        $this->actingAs($this->qc)
            ->postJson(route('local-supplier.erp-delta.sync'), $payload)
            ->assertForbidden();

        $inactiveSupplier = User::factory()->create(['role' => 'supplier', 'is_active' => false]);
        $inactiveSupplier->supplierScopes()->delete();
        $inactiveSupplier->supplierScopes()->create(['scope' => 'local']);

        $this->actingAs($inactiveSupplier)
            ->postJson(route('local-supplier.erp-delta.sync'), $payload)
            ->assertUnauthorized();
    }

    public function test_cross_supplier_idor_via_supplier_id_or_po_doc_no_is_rejected_with_403(): void
    {
        // Supplier A owns an import PO, a local PO, and an already synced ERP delta row.
        $rate = ExchangeRate::create([
            'currency' => 'IDR',
            'rate_to_idr' => 1,
            'valid_from' => '2026-11-13',
            'created_by' => $this->purchasing->id,
        ]);

        PurchaseOrder::create([
            'supplier_id' => $this->supplierA->id,
            'currency' => 'IDR',
            'exchange_rate_id' => $rate->id,
            'po_number' => 'PO-OWNED-BY-A',
            'status' => PurchaseOrder::STATUS_DRAFT,
            'created_by' => $this->purchasing->id,
        ]);

        LocalPurchaseOrder::create([
            'supplier_id' => $this->supplierA->id,
            'po_number' => 'LPO-OWNED-BY-A',
            'po_date' => '2026-11-13',
            'total_amount' => '15000.00',
            'currency' => 'IDR',
            'status' => LocalPurchaseOrder::STATUS_OPEN,
            'source' => LocalPurchaseOrder::SOURCE_MANUAL,
            'created_by' => $this->finance->id,
        ]);

        $this->actingAs($this->supplierA)
            ->postJson(route('local-supplier.erp-delta.sync'), [
                'rows' => [[
                    'doc_no' => 'DELTA-OWNED-BY-A',
                    'item_code' => 'MAT-100',
                    'qty' => '2.0000',
                    'price' => '50.00',
                    'status' => 'ACTIVE',
                ]],
            ])
            ->assertOk();

        // Attempt 1: Supplier B explicitly passes Supplier A's supplier_id on hashes & sync.
        $this->actingAs($this->supplierB)
            ->getJson(route('local-supplier.erp-delta.hashes', ['supplier_id' => $this->supplierA->id]))
            ->assertForbidden();

        $this->actingAs($this->supplierB)
            ->postJson(route('local-supplier.erp-delta.sync'), [
                'supplier_id' => $this->supplierA->id,
                'rows' => [[
                    'doc_no' => 'PO-BY-B',
                    'item_code' => 'MAT-01',
                    'qty' => '1.0000',
                    'price' => '10.00',
                    'status' => 'ACTIVE',
                ]],
            ])
            ->assertForbidden();

        // Attempt 2: Supplier B omits supplier_id and tries to hijack Supplier A's PO doc_no.
        foreach (['PO-OWNED-BY-A', 'LPO-OWNED-BY-A', 'DELTA-OWNED-BY-A', 'lpo-owned-by-a'] as $foreignDocNo) {
            $this->actingAs($this->supplierB)
                ->postJson(route('local-supplier.erp-delta.sync'), [
                    'rows' => [[
                        'doc_no' => $foreignDocNo,
                        'item_code' => 'MAT-HIJACK',
                        'qty' => '1.0000',
                        'price' => '10.00',
                        'status' => 'ACTIVE',
                    ]],
                ])
                ->assertForbidden();
        }

        // Attempt 3: Staff user passes mismatched target supplier_id against Supplier A's PO.
        $this->actingAs($this->finance)
            ->postJson(route('finance.local-procurement.erp-delta.sync'), [
                'supplier_id' => $this->supplierB->id,
                'rows' => [[
                    'doc_no' => 'LPO-OWNED-BY-A',
                    'item_code' => 'MAT-01',
                    'qty' => '1.0000',
                    'price' => '10.00',
                    'status' => 'ACTIVE',
                ]],
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('erp_delta_rows', [
            'supplier_id' => $this->supplierB->id,
            'doc_no' => 'LPO-OWNED-BY-A',
        ]);
    }

    public function test_polyglot_and_disguised_files_uploaded_as_pdf_are_rejected(): void
    {
        $validRows = [[
            'doc_no' => 'PO-PDF-001',
            'item_code' => 'MAT-01',
            'qty' => '3.0000',
            'price' => '200.00',
            'status' => 'ACTIVE',
        ]];

        // 1. PHP payload disguised as .pdf (contains PHP opening tag before %PDF-1.4)
        $phpPolyglot = UploadedFile::fake()->createWithContent('evil.pdf', "<?php echo 'polyglot-test'; ?>\n%PDF-1.4\n");
        $this->actingAs($this->supplierA)
            ->post(route('local-supplier.erp-delta.sync'), [
                'rows' => $validRows,
                'document' => $phpPolyglot,
            ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['document']);

        // 2. HTML payload disguised as .pdf
        $htmlPolyglot = UploadedFile::fake()->createWithContent('xss.pdf', "<!doctype html><html><script>alert(1)</script></html>\n");
        $this->actingAs($this->supplierA)
            ->post(route('local-supplier.erp-delta.sync'), [
                'rows' => $validRows,
                'document' => $htmlPolyglot,
            ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['document']);

        // 3. ZIP archive disguised as .pdf
        $zipPath = tempnam(sys_get_temp_dir(), 'poly-zip-');
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('shell.php', '<?php phpinfo();');
        $zip->close();
        $zipDisguisedPdf = new UploadedFile($zipPath, 'archive.pdf', 'application/pdf', null, true);
        try {
            $this->actingAs($this->supplierA)
                ->post(route('local-supplier.erp-delta.sync'), [
                    'rows' => $validRows,
                    'document' => $zipDisguisedPdf,
                ], ['Accept' => 'application/json'])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['document']);
        } finally {
            @unlink($zipPath);
        }

        // 4. Spoofed %PDF-1.4 header without real PDF envelope
        $headerSpoofPdf = UploadedFile::fake()->createWithContent('spoof.pdf', "%PDF-1.4\n<?php echo 'hack'; ?>\n");
        $this->actingAs($this->supplierA)
            ->post(route('local-supplier.erp-delta.sync'), [
                'rows' => $validRows,
                'document' => $headerSpoofPdf,
            ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['document']);

        $this->assertDatabaseCount('erp_delta_rows', 0);

        // 5. Real PDF succeeds cleanly
        $realPdf = NativeFileFixtures::upload('po-attachment.pdf');
        $this->actingAs($this->supplierA)
            ->post(route('local-supplier.erp-delta.sync'), [
                'rows' => $validRows,
                'document' => $realPdf,
            ], ['Accept' => 'application/json'])
            ->assertOk();

        $this->assertDatabaseCount('erp_delta_rows', 1);
    }

    public function test_cwe_1236_formula_injection_payloads_are_rejected_even_when_bypassing_wasm(): void
    {
        $maliciousInputs = [
            ['doc_no' => '=CMD|calc', 'item_code' => 'MAT-1', 'qty' => '1', 'price' => '10', 'status' => 'ACTIVE', 'field' => 'rows.0.doc_no'],
            ['doc_no' => ' =1+1', 'item_code' => 'MAT-1', 'qty' => '1', 'price' => '10', 'status' => 'ACTIVE', 'field' => 'rows.0.doc_no'],
            ['doc_no' => '+SUM(A1:A2)', 'item_code' => 'MAT-1', 'qty' => '1', 'price' => '10', 'status' => 'ACTIVE', 'field' => 'rows.0.doc_no'],
            ['doc_no' => 'PO-1', 'item_code' => '-CMD|calc', 'qty' => '1', 'price' => '10', 'status' => 'ACTIVE', 'field' => 'rows.0.item_code'],
            ['doc_no' => 'PO-1', 'item_code' => '@SUM(1,2)', 'qty' => '1', 'price' => '10', 'status' => 'ACTIVE', 'field' => 'rows.0.item_code'],
            ['doc_no' => 'PO-1', 'item_code' => 'MAT-1', 'qty' => '1', 'price' => '10', 'status' => "\t=1+1", 'field' => 'rows.0.status'],
            ['doc_no' => 'PO-1', 'item_code' => 'MAT-1', 'qty' => '1', 'price' => '10', 'status' => "\r+1+1", 'field' => 'rows.0.status'],
            ['doc_no' => 'PO-1', 'item_code' => 'MAT-1', 'qty' => '1', 'price' => '10', 'status' => " \tABC", 'field' => 'rows.0.status'],
            ['doc_no' => "\u{00A0}=CMD()", 'item_code' => 'MAT-1', 'qty' => '1', 'price' => '10', 'status' => 'ACTIVE', 'field' => 'rows.0.doc_no'],
            ['doc_no' => 'PO-1', 'item_code' => "\u{2003}@SUM(A1)", 'qty' => '1', 'price' => '10', 'status' => 'ACTIVE', 'field' => 'rows.0.item_code'],
            ['doc_no' => 'PO-1', 'item_code' => 'MAT-1', 'qty' => '1', 'price' => '10', 'status' => "\u{00A0}\tABC", 'field' => 'rows.0.status'],
            ['doc_no' => 'PO-1', 'item_code' => 'MAT-1', 'qty' => '=1+1', 'price' => '10', 'status' => 'ACTIVE', 'field' => 'rows.0.qty'],
            ['doc_no' => 'PO-1', 'item_code' => 'MAT-1', 'qty' => '1', 'price' => '-5', 'status' => 'ACTIVE', 'field' => 'rows.0.price'],
        ];

        foreach ($maliciousInputs as $case) {
            $field = $case['field'];
            unset($case['field']);

            $this->actingAs($this->supplierA)
                ->postJson(route('local-supplier.erp-delta.sync'), ['rows' => [$case]])
                ->assertStatus(422)
                ->assertJsonValidationErrors([$field]);
        }

        $this->assertDatabaseCount('erp_delta_rows', 0);

        // Safe values with hyphens/symbols in middle are accepted.
        $this->actingAs($this->supplierA)
            ->postJson(route('local-supplier.erp-delta.sync'), [
                'rows' => [[
                    'doc_no' => 'PO-2026/A&B',
                    'item_code' => 'MAT_X-99',
                    'qty' => '10.5',
                    'price' => '250.75',
                    'status' => 'ON-HOLD',
                ]],
            ])
            ->assertOk();

        $this->assertDatabaseCount('erp_delta_rows', 1);
    }

    public function test_batch_exceeding_500_rows_is_rejected_with_422(): void
    {
        $rows = [];
        for ($i = 1; $i <= 501; $i++) {
            $rows[] = [
                'doc_no' => 'PO-BATCH-'.$i,
                'item_code' => 'MAT-'.$i,
                'qty' => '1.0000',
                'price' => '10.00',
                'status' => 'ACTIVE',
            ];
        }

        $this->actingAs($this->supplierA)
            ->postJson(route('local-supplier.erp-delta.sync'), ['rows' => $rows])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['rows']);

        $this->assertDatabaseCount('erp_delta_rows', 0);
    }

    public function test_tampered_client_row_hash_is_ignored_and_replaced_with_server_xxh3_hash(): void
    {
        $expectedHash = CanonicalRowHasher::hash('PO-001', 'MAT-01', '10.0000', '1500.50', 'ACTIVE');
        $this->assertSame('34956c47a415bb9e', $expectedHash);

        $this->actingAs($this->supplierA)
            ->postJson(route('local-supplier.erp-delta.sync'), [
                'rows' => [[
                    'doc_no' => 'po-001',
                    'item_code' => 'mat-01',
                    'qty' => '10',
                    'price' => '1500.5',
                    'status' => 'active',
                    'row_hash' => '0000000000000000',
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('synced_count', 1);

        $this->assertDatabaseHas('erp_delta_rows', [
            'supplier_id' => $this->supplierA->id,
            'doc_no' => 'PO-001',
            'item_code' => 'MAT-01',
            'qty' => '10.0000',
            'price' => '1500.50',
            'status' => 'ACTIVE',
            'row_hash' => $expectedHash,
            'last_synced_by' => $this->supplierA->id,
        ]);
    }

    public function test_idempotent_batch_retry_executes_atomically_without_duplicate_rows(): void
    {
        $batch = [
            'rows' => [
                [
                    'doc_no' => 'PO-IDEMP-01',
                    'item_code' => 'MAT-01',
                    'qty' => '5.0000',
                    'price' => '100.00',
                    'status' => 'OPEN',
                ],
                [
                    'doc_no' => 'PO-IDEMP-01',
                    'item_code' => 'MAT-02',
                    'qty' => '2.5000',
                    'price' => '250.00',
                    'status' => 'OPEN',
                ],
            ],
        ];

        $this->actingAs($this->supplierA)
            ->postJson(route('local-supplier.erp-delta.sync'), $batch)
            ->assertOk();

        // Identical retry must stay at exactly 2 rows.
        $this->actingAs($this->supplierA)
            ->postJson(route('local-supplier.erp-delta.sync'), $batch)
            ->assertOk();

        $this->assertDatabaseCount('erp_delta_rows', 2);

        // Updated retry updates qty & price & server row_hash cleanly in place.
        $updatedBatch = [
            'rows' => [[
                'doc_no' => 'PO-IDEMP-01',
                'item_code' => 'MAT-01',
                'qty' => '8.0000',
                'price' => '120.00',
                'status' => 'APPROVED',
            ]],
        ];

        $this->actingAs($this->supplierA)
            ->postJson(route('local-supplier.erp-delta.sync'), $updatedBatch)
            ->assertOk();

        $this->assertDatabaseCount('erp_delta_rows', 2);
        $this->assertDatabaseHas('erp_delta_rows', [
            'supplier_id' => $this->supplierA->id,
            'doc_no' => 'PO-IDEMP-01',
            'item_code' => 'MAT-01',
            'qty' => '8.0000',
            'price' => '120.00',
            'status' => 'APPROVED',
            'row_hash' => CanonicalRowHasher::hash('PO-IDEMP-01', 'MAT-01', '8.0000', '120.00', 'APPROVED'),
        ]);
    }

    public function test_scoped_hashes_endpoint_returns_only_authorized_supplier_hashes(): void
    {
        $hashA = CanonicalRowHasher::hash('PO-A-1', 'MAT-A', '1.0000', '10.00', 'ACTIVE');
        $hashB = CanonicalRowHasher::hash('PO-B-1', 'MAT-B', '2.0000', '20.00', 'ACTIVE');

        $this->actingAs($this->supplierA)
            ->postJson(route('local-supplier.erp-delta.sync'), [
                'rows' => [['doc_no' => 'PO-A-1', 'item_code' => 'MAT-A', 'qty' => '1', 'price' => '10', 'status' => 'ACTIVE']],
            ])
            ->assertOk();

        $this->actingAs($this->supplierB)
            ->postJson(route('local-supplier.erp-delta.sync'), [
                'rows' => [['doc_no' => 'PO-B-1', 'item_code' => 'MAT-B', 'qty' => '2', 'price' => '20', 'status' => 'ACTIVE']],
            ])
            ->assertOk();

        $response = $this->actingAs($this->supplierA)
            ->getJson(route('local-supplier.erp-delta.hashes'))
            ->assertOk();

        $cacheControl = strtolower((string) $response->headers->get('Cache-Control', ''));
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertSame([$hashA], $response->json('hashes'));
        $this->assertNotContains($hashB, $response->json('hashes'));

        // Staff queries for Supplier B via staff route.
        $this->actingAs($this->purchasing)
            ->getJson(route('purchasing.local-procurement.erp-delta.hashes', ['supplier_id' => $this->supplierB->id]))
            ->assertOk()
            ->assertExactJson(['hashes' => [$hashB]]);
    }

    public function test_security_headers_include_wasm_unsafe_eval_and_worker_src_csp(): void
    {
        $response = $this->get('/login')->assertOk();
        $csp = (string) $response->headers->get('Content-Security-Policy');

        $this->assertMatchesRegularExpression("/script-src 'self' 'unsafe-eval' 'nonce-[^']+' 'wasm-unsafe-eval'/", $csp);
        $this->assertStringContainsString("worker-src 'self' 'wasm-unsafe-eval'", $csp);
    }
}
