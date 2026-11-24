<?php

namespace Tests\Feature\Security;

use App\Models\Attachment;
use App\Models\AuthRememberToken;
use App\Models\ClaimAttachment;
use App\Models\Conversation;
use App\Models\ExchangeRate;
use App\Models\ExportJob;
use App\Models\GaClaimDocument;
use App\Models\LocalInvoiceDocument;
use App\Models\LocalProcurementImport;
use App\Models\MaterialClaim;
use App\Models\MaterialMaster;
use App\Models\Message;
use App\Models\PaymentVoucherDocument;
use App\Models\Period;
use App\Models\PrItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\QcInspection;
use App\Models\Quotation;
use App\Models\ReferencePriceImport;
use App\Models\Shipment;
use App\Models\ShipmentDocument;
use App\Models\Supplier;
use App\Models\SupplierMasterDocument;
use App\Models\SupplierRegistrationAccess;
use App\Models\SupplierRegistrationAttempt;
use App\Models\SupplierScope;
use App\Models\User;
use App\Services\Pricing\ReferencePriceRuleImportService;
use App\Services\Procurement\MaterialClaimStateEngine;
use App\Services\Qc\QcInspectionSubmissionService;
use App\Services\ShipmentService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use PDOException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class DevToolsPayloadLeakSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_html_pages_in_production_mode_leak_zero_secrets_sql_traces_html_comments_or_ngrok_headers(): void
    {
        config()->set('app.env', 'production');
        config()->set('broadcasting.connections.pusher.secret', 'pusher-private-secret-sentinel-9001');
        config()->set('auth_security.turnstile.secret_key', 'turnstile-private-secret-sentinel-9002');

        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $purchasing = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);
        $qc = User::factory()->create(['role' => 'qc', 'is_active' => true]);
        $finance = User::factory()->create(['role' => 'finance', 'is_active' => true]);
        $supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        SupplierScope::firstOrCreate(['supplier_id' => $supplier->id, 'scope' => 'import']);

        $responses = [
            'login' => $this->get('https://adasi-portal.test/login'),
            'supplier.register' => $this->get('https://adasi-portal.test/supplier/register'),
            'admin.dashboard' => $this->actingAs($admin)->get('https://adasi-portal.test'.route('admin.dashboard', absolute: false)),
            'purchasing.dashboard' => $this->actingAs($purchasing)->get('https://adasi-portal.test'.route('purchasing.dashboard', absolute: false)),
            'supplier.dashboard' => $this->actingAs($supplier)->get('https://adasi-portal.test'.route('supplier.dashboard', absolute: false)),
            'qc.dashboard' => $this->actingAs($qc)->get('https://adasi-portal.test'.route('qc.dashboard', absolute: false)),
            'finance.dashboard' => $this->actingAs($finance)->get('https://adasi-portal.test'.route('finance.dashboard', absolute: false)),
        ];

        $forbiddenSubstrings = [
            (string) config('app.key'),
            (string) config('database.connections.mysql.password'),
            'pusher-private-secret-sentinel-9001',
            'turnstile-private-secret-sentinel-9002',
            'ngrok-skip-browser-warning',
            '<!--',
            'SQLSTATE[',
            'vendor/laravel',
        ];

        foreach ($responses as $label => $response) {
            $response->assertOk();
            $html = (string) $response->getContent();

            foreach ($forbiddenSubstrings as $secret) {
                if ($secret === '') {
                    continue;
                }
                $this->assertStringNotContainsString($secret, $html, "Response [{$label}] leaked forbidden substring.");
            }

            $this->assertNoStoreAndHttpOnlyCookies($response, $label);
        }
    }

    public function test_datatables_and_chat_json_endpoints_enforce_bopla_projection_without_sensitive_fields(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
        ]);
        User::factory()->create([
            'role' => 'purchasing',
            'is_active' => true,
            'two_factor_last_used_step' => 58999123,
            'auth_session_version' => 7,
        ]);
        $purchasing = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);
        $qc = User::factory()->create(['role' => 'qc', 'is_active' => true]);
        $supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        SupplierScope::firstOrCreate(['supplier_id' => $supplier->id, 'scope' => 'import']);
        Supplier::create([
            'user_id' => $supplier->id,
            'company_name' => 'PT Zero Trust Vendor',
            'nib' => '1234567890123',
            'nib_fingerprint' => 'nib-hmac-fingerprint-sentinel',
            'npwp' => '1234567890123456',
            'tax_identity_fingerprint' => 'npwp-hmac-fingerprint-sentinel',
        ]);

        $period = Period::create([
            'name' => 'BOPLA Guard Period',
            'month' => 9,
            'year' => 2026,
            'status' => 'open',
            'created_by' => $admin->id,
        ]);
        $rate = ExchangeRate::create([
            'currency' => 'USD',
            'rate_to_idr' => 16000,
            'valid_from' => now(),
            'created_by' => $admin->id,
        ]);
        $pr = PurchaseRequisition::create([
            'period_id' => $period->id,
            'created_by' => $purchasing->id,
            'pr_number' => 'REQ/09/2026/777',
            'status' => 'submitted',
        ]);
        $item = PrItem::create([
            'pr_id' => $pr->id,
            'hs_code' => '7209.16.00',
            'material_name' => 'SPCC-SD',
            'shape' => PrItem::SHAPE_FLAT,
            'thickness' => 2,
            'width' => 1000,
            'length' => 2000,
            'quantity' => 2,
            'weight_needed' => 100,
        ]);
        $quotation = Quotation::create([
            'pr_id' => $pr->id,
            'supplier_id' => $supplier->id,
            'currency' => 'USD',
            'exchange_rate_id' => $rate->id,
            'status' => 'submitted',
            'payment_terms' => 'CONFIDENTIAL-NET-90-TERMS',
            'reviewer_notes' => 'INTERNAL-BUYER-CONFIDENTIAL-NOTE',
            'submitted_at' => now(),
            'validity_period' => now()->addWeek(),
        ]);
        $quotation->items()->create([
            'pr_item_id' => $item->id,
            'price_per_kg' => 3.25,
            'amount' => 325,
        ]);

        $poWaiting = PurchaseOrder::create([
            'supplier_id' => $supplier->id,
            'currency' => 'USD',
            'exchange_rate_id' => $rate->id,
            'po_number' => 'PO/09/2026/771',
            'status' => 'waiting_qc',
            'actual_arrival' => now(),
            'sales_contract_notes' => 'INTERNAL-SALES-CONTRACT-SECRET',
            'created_by' => $purchasing->id,
        ]);
        $poWaiting->quotations()->attach($quotation->id);

        $shipment = Shipment::create([
            'shipment_number' => 'SHP-2026-771',
            'supplier_id' => $supplier->id,
            'status' => Shipment::STATUS_ARRIVED,
            'shipment_date' => now()->subDays(2),
            'estimated_arrival_date' => now()->subDay(),
            'actual_arrival_date' => now(),
            'created_by' => $supplier->id,
        ]);
        $shipmentDoc = ShipmentDocument::create([
            'shipment_id' => $shipment->id,
            'doc_type' => ShipmentDocument::DOC_TYPE_INVOICE,
            'status' => 'pending',
        ]);
        Attachment::create([
            'attachable_type' => ShipmentDocument::class,
            'attachable_id' => $shipmentDoc->id,
            'file_path' => 'private/shipments/confidential-invoice-path.pdf',
            'file_name' => 'invoice.pdf',
            'file_type' => 'application/pdf',
            'uploaded_by' => $supplier->id,
        ]);

        $inspection = QcInspection::create([
            'po_id' => $poWaiting->id,
            'inspected_by' => $qc->id,
            'status' => 'ng',
            'inspected_at' => now(),
        ]);
        MaterialClaim::create([
            'inspection_id' => $inspection->id,
            'po_id' => $poWaiting->id,
            'submitted_by' => $purchasing->id,
            'supplier_id' => $supplier->id,
            'status' => 'pending',
            'description' => 'Surface defect',
            'deadline' => now()->addDays(7),
        ]);

        $pendingApplicant = User::factory()->create([
            'role' => 'supplier',
            'account_status' => User::ACCOUNT_STATUS_PENDING,
            'is_active' => false,
        ]);
        SupplierRegistrationAccess::create([
            'user_id' => $pendingApplicant->id,
            'registration_reference' => 'REG-BOPLA-001',
            'token_hash' => 'reg-access-token-hash-sentinel-secret',
            'issued_at' => now(),
            'expires_at' => now()->addDays(7),
        ]);
        SupplierRegistrationAttempt::create([
            'user_id' => $pendingApplicant->id,
            'attempt_number' => 1,
            'status' => SupplierRegistrationAttempt::STATUS_PENDING,
            'submission_snapshot' => ['private_doc' => 'private/registrations/secret-nib.pdf'],
            'submission_checksum' => 'snapshot-checksum-sentinel',
            'submitted_at' => now(),
        ]);

        // 1. QC Waiting & History DataTables must never leak commercial quotations or user auth state
        $qcWaiting = $this->actingAs($qc)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->getJson(route('qc.inspections.data-waiting'));
        $qcWaiting->assertOk();
        $qcWaitingRow = $qcWaiting->json('data.0');
        $this->assertSame(
            ['po_number_display', 'supplier_name', 'arrival_date', 'item_count', 'action'],
            array_keys($qcWaitingRow),
        );
        $this->assertStringNotContainsString('CONFIDENTIAL-NET-90-TERMS', $qcWaiting->getContent());
        $this->assertStringNotContainsString('INTERNAL-BUYER-CONFIDENTIAL-NOTE', $qcWaiting->getContent());

        $qcHistory = $this->actingAs($qc)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->getJson(route('qc.inspections.data-history'));
        $qcHistory->assertOk();
        $this->assertSame(
            ['po_number', 'supplier_name', 'inspected_date', 'status_badge', 'inspector_name', 'action'],
            array_keys($qcHistory->json('data.0')),
        );

        // 2. External Supplier Claims & Shipments DataTables must never leak internal contract notes or private file paths
        $supplierClaims = $this->actingAs($supplier)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->getJson(route('supplier.claims.index'));
        $supplierClaims->assertOk();
        $this->assertSame(
            ['id', 'claim_id', 'po_number', 'created_date', 'deadline_display', 'status_badge', 'action'],
            array_keys($supplierClaims->json('data.0')),
        );
        $this->assertStringNotContainsString('INTERNAL-SALES-CONTRACT-SECRET', $supplierClaims->getContent());
        $this->assertArrayNotHasKey('submitted_by', $supplierClaims->json('data.0'));

        $supplierShipments = $this->actingAs($supplier)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->getJson(route('supplier.shipments.index'));
        $supplierShipments->assertOk();
        $this->assertStringNotContainsString('confidential-invoice-path.pdf', $supplierShipments->getContent());
        $this->assertArrayNotHasKey('documents', $supplierShipments->json('data.0'));

        // 3. Supplier Registration Review & Admin Users DataTables must never leak token_hash, snapshots, or MFA step counters
        $this->flushSession();
        $regFeed = $this->actingAs($admin)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->getJson(route('supplier-registrations.index'));
        $regFeed->assertOk();
        $this->assertStringNotContainsString('reg-access-token-hash-sentinel-secret', $regFeed->getContent());
        $this->assertStringNotContainsString('secret-nib.pdf', $regFeed->getContent());
        $this->assertStringNotContainsString('snapshot-checksum-sentinel', $regFeed->getContent());

        $usersFeed = $this->actingAs($admin)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->getJson(route('admin.users.index'));
        $usersFeed->assertOk();
        $this->assertStringNotContainsString('58999123', $usersFeed->getContent());
        $this->assertStringNotContainsString('nib-hmac-fingerprint-sentinel', $usersFeed->getContent());
        $this->assertStringNotContainsString('npwp-hmac-fingerprint-sentinel', $usersFeed->getContent());

        // 4. Chat Drawer & Latest Messages must omit raw integer sender_id and expose Hashid attachment IDs only
        $this->flushSession();
        $conversation = Conversation::create([
            'conversable_type' => PurchaseRequisition::class,
            'conversable_id' => $pr->id,
            'purchasing_user_id' => $purchasing->id,
            'supplier_user_id' => $supplier->id,
            'status' => Conversation::STATUS_OPEN,
        ]);
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $purchasing->id,
            'body' => 'Negotiation update',
        ]);
        $chatAttachment = Attachment::create([
            'attachable_type' => Message::class,
            'attachable_id' => $message->id,
            'file_path' => 'private/chat/secret-drawing.pdf',
            'file_name' => 'drawing.pdf',
            'file_type' => 'application/pdf',
            'uploaded_by' => $purchasing->id,
        ]);

        $drawer = $this->actingAs($supplier)->getJson(route('conversations.drawer.show', $conversation));
        $drawer->assertOk();
        $msgPayload = $drawer->json('messages.0');
        $this->assertArrayNotHasKey('sender_id', $msgPayload);
        $this->assertArrayNotHasKey('id', $msgPayload['sender']);
        $this->assertFalse($msgPayload['is_me']);
        $this->assertSame($chatAttachment->getRouteKey(), $msgPayload['attachments'][0]['id']);
        $this->assertStringNotContainsString('secret-drawing.pdf', $drawer->getContent());

        // 5. PrItemController projects explicit business fields without raw foreign keys
        $this->flushSession();
        $draftPr = PurchaseRequisition::create([
            'period_id' => $period->id,
            'created_by' => $purchasing->id,
            'pr_number' => 'REQ/09/2026/778',
            'status' => 'draft',
        ]);
        $materialMaster = MaterialMaster::create([
            'material_code' => 'SCM440-GUARD',
            'normalized_code' => 'scm440guard',
            'hs_category' => 'alloy_steel',
            'density_profile' => 'steel',
            'manufacturer_scope' => 'any',
            'is_active' => true,
        ]);
        $prItemRes = $this->actingAs($purchasing)->postJson(route('purchasing.pr-items.store'), [
            'pr_id' => $draftPr->id,
            'material_master_id' => $materialMaster->id,
            'material_name' => 'SCM440-GUARD',
            'hs_code' => '7228.30.10',
            'quantity' => 2,
            'shape' => PrItem::SHAPE_ROUND,
            'd_outer' => 100,
            'length' => 1000,
            'weight_needed' => 61.67,
        ]);
        $prItemRes->assertOk()->assertJsonPath('success', true);
        $this->assertArrayNotHasKey('id', $prItemRes->json('item'));
        $this->assertArrayNotHasKey('pr_id', $prItemRes->json('item'));
        $this->assertArrayNotHasKey('material_master_id', $prItemRes->json('item'));
    }

    public function test_exception_boundaries_never_leak_sqlstate_table_names_or_private_storage_paths(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $purchasing = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);
        $qc = User::factory()->create(['role' => 'qc', 'is_active' => true]);
        $supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        SupplierScope::firstOrCreate(['supplier_id' => $supplier->id, 'scope' => 'import']);

        $period = Period::create(['name' => 'Err Period', 'month' => 9, 'year' => 2026, 'status' => 'open', 'created_by' => $admin->id]);
        $rate = ExchangeRate::create(['currency' => 'USD', 'rate_to_idr' => 16000, 'valid_from' => now(), 'created_by' => $admin->id]);
        $pr = PurchaseRequisition::create(['period_id' => $period->id, 'created_by' => $purchasing->id, 'pr_number' => 'REQ/09/2026/888', 'status' => 'submitted']);
        $item = PrItem::create([
            'pr_id' => $pr->id,
            'hs_code' => '7209.16.00',
            'material_name' => 'SPCC',
            'shape' => PrItem::SHAPE_FLAT,
            'thickness' => 2,
            'width' => 1000,
            'length' => 2000,
            'weight_needed' => 100,
        ]);
        $quotation = Quotation::create([
            'pr_id' => $pr->id,
            'supplier_id' => $supplier->id,
            'currency' => 'USD',
            'exchange_rate_id' => $rate->id,
            'status' => 'submitted',
            'submitted_at' => now(),
            'validity_period' => now()->addWeek(),
        ]);
        $quotation->items()->create(['pr_item_id' => $item->id, 'price_per_kg' => 2, 'amount' => 200]);
        $po = PurchaseOrder::create([
            'supplier_id' => $supplier->id,
            'currency' => 'USD',
            'exchange_rate_id' => $rate->id,
            'po_number' => 'PO/09/2026/888',
            'status' => 'waiting_qc',
            'created_by' => $purchasing->id,
        ]);
        $po->quotations()->attach($quotation->id);

        $syntheticSqlException = new QueryException(
            'mysql',
            'insert into `secret_internal_table` (`col`) values (?)',
            ['secret-param'],
            new PDOException('SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry'),
        );

        // 1. Reference Price Workbook Import Preview: internal parser/path exception sanitized
        $tmpXlsx = tempnam(sys_get_temp_dir(), 'adasi_ref_').'.xlsx';
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->setTitle('Kategori')->setCellValue('A1', 'Kategori');
        (new Xlsx($spreadsheet))->save($tmpXlsx);
        $uploadedXlsx = new UploadedFile($tmpXlsx, 'rules.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $this->mock(ReferencePriceRuleImportService::class)
            ->shouldReceive('parseWorkbook')
            ->once()
            ->andThrow(new \RuntimeException('Failed reading '.storage_path('app/private/reference_price_imports/secret.xlsx').' SQLSTATE[HY000]'));

        $importRes = $this->actingAs($admin)->postJson(route('admin.reference-prices.rules.import-preview'), [
            'file' => $uploadedXlsx,
            'import_mode' => ReferencePriceImport::MODE_REPLACE,
        ]);
        @unlink($tmpXlsx);
        $importRes->assertStatus(422);
        $this->assertStringNotContainsString('SQLSTATE', $importRes->getContent());
        $this->assertStringNotContainsString('reference_price_imports', $importRes->getContent());

        // 2. QC Inspection Store: QueryException never caught as 422 validation message
        $this->mock(QcInspectionSubmissionService::class)
            ->shouldReceive('submit')
            ->once()
            ->andThrow($syntheticSqlException);

        $qcRes = $this->actingAs($qc)->postJson(route('qc.inspections.store', $po), [
            'items' => [[
                'pr_item_id' => $item->id,
                'status' => 'ok',
                'actual_thickness' => 2,
                'actual_width' => 1000,
                'actual_length' => 2000,
            ]],
        ]);
        $qcRes->assertStatus(500);
        $this->assertStringNotContainsString('SQLSTATE', $qcRes->getContent());
        $this->assertStringNotContainsString('secret_internal_table', $qcRes->getContent());

        // 3. Supplier Shipment Document Upload: QueryException never leaks to external supplier
        $shipment = Shipment::create([
            'shipment_number' => 'SHP-ERR-001',
            'supplier_id' => $supplier->id,
            'status' => Shipment::STATUS_DRAFT,
            'shipment_date' => now(),
            'estimated_arrival_date' => now()->addWeek(),
            'created_by' => $supplier->id,
        ]);
        $doc = ShipmentDocument::create([
            'shipment_id' => $shipment->id,
            'doc_type' => ShipmentDocument::DOC_TYPE_INVOICE,
            'status' => 'pending',
        ]);
        $this->mock(ShipmentService::class)
            ->shouldReceive('uploadDocument')
            ->once()
            ->andThrow($syntheticSqlException);

        $shipRes = $this->actingAs($supplier)->postJson(
            route('supplier.shipments.documents.upload', [$shipment, $doc]),
            ['file' => UploadedFile::fake()->create('invoice.pdf', 32, 'application/pdf')],
        );
        $shipRes->assertStatus(500);
        $this->assertStringNotContainsString('SQLSTATE', $shipRes->getContent());
        $this->assertStringNotContainsString('secret_internal_table', $shipRes->getContent());

        // 4. Supplier Claim Respond: QueryException never leaks to external supplier
        $inspection = QcInspection::create([
            'po_id' => $po->id,
            'inspected_by' => $qc->id,
            'status' => 'ng',
            'inspected_at' => now(),
        ]);
        $claim = MaterialClaim::create([
            'inspection_id' => $inspection->id,
            'po_id' => $po->id,
            'submitted_by' => $purchasing->id,
            'supplier_id' => $supplier->id,
            'status' => 'pending',
            'description' => 'NG claim',
            'deadline' => now()->addWeek(),
        ]);
        $this->mock(MaterialClaimStateEngine::class)
            ->shouldReceive('transition')
            ->once()
            ->andThrow($syntheticSqlException);

        $claimRes = $this->actingAs($supplier)->postJson(route('supplier.claims.respond', $claim), [
            'supplier_response' => 'We will replace the lot.',
        ]);
        $claimRes->assertStatus(500);
        $this->assertStringNotContainsString('SQLSTATE', $claimRes->getContent());
        $this->assertStringNotContainsString('secret_internal_table', $claimRes->getContent());
    }

    public function test_eloquent_models_hide_hashes_session_counters_and_private_paths_on_serialization(): void
    {
        $cases = [
            [(new User)->forceFill(['name' => 'A', 'two_factor_last_used_step' => 123, 'auth_session_version' => 2, 'must_change_password' => true, 'two_factor_confirmed_at' => now()]), ['two_factor_last_used_step', 'auth_session_version', 'must_change_password', 'two_factor_confirmed_at']],
            [(new SupplierRegistrationAccess)->forceFill(['registration_reference' => 'R1', 'token_hash' => 'secret']), ['token_hash']],
            [(new AuthRememberToken)->forceFill(['ip_address' => '127.0.0.1', 'token_hash' => 'h', 'session_id' => 's', 'device_hash' => 'd']), ['token_hash', 'session_id', 'device_hash']],
            [(new LocalProcurementImport)->forceFill(['status' => 'QUEUED', 'token_hash' => 'h', 'owner_job_key' => 'k', 'failure' => 'trace']), ['token_hash', 'owner_job_key', 'failure']],
            [(new Supplier)->forceFill(['company_name' => 'PT X', 'nib_fingerprint' => 'f1', 'tax_identity_fingerprint' => 'f2']), ['nib_fingerprint', 'tax_identity_fingerprint']],
            [(new SupplierRegistrationAttempt)->forceFill(['status' => 'PENDING', 'submission_snapshot' => ['a' => 1], 'submission_checksum' => 'c']), ['submission_snapshot', 'submission_checksum']],
            [(new ExportJob)->forceFill(['status' => 'completed', 'file_path' => 'p', 'disk' => 'private', 'export_class' => 'X', 'export_args' => [], 'export_options' => [], 'error_message' => 'err']), ['file_path', 'disk', 'export_class', 'export_args', 'export_options', 'error_message']],
            [(new ReferencePriceImport)->forceFill(['status' => 'previewed', 'private_storage_path' => 'p', 'source_file_hash' => 'h']), ['private_storage_path', 'source_file_hash']],
            [(new Attachment)->forceFill(['file_name' => 'a.pdf', 'file_path' => 'p', 'file_inspection_id' => 9]), ['file_path', 'file_inspection_id']],
            [(new SupplierMasterDocument)->forceFill(['original_filename' => 'a.pdf', 'file_path' => 'p', 'file_inspection_id' => 9]), ['file_path', 'file_inspection_id']],
            [(new LocalInvoiceDocument)->forceFill(['original_filename' => 'a.pdf', 'file_path' => 'p', 'file_inspection_id' => 9]), ['file_path', 'file_inspection_id']],
            [(new GaClaimDocument)->forceFill(['original_filename' => 'a.pdf', 'file_path' => 'p', 'file_inspection_id' => 9]), ['file_path', 'file_inspection_id']],
            [(new PaymentVoucherDocument)->forceFill(['original_filename' => 'a.pdf', 'file_path' => 'p', 'file_inspection_id' => 9]), ['file_path', 'file_inspection_id']],
            [(new ClaimAttachment)->forceFill(['claim_id' => 1, 'file_path' => 'p']), ['file_path']],
        ];

        foreach ($cases as [$model, $hiddenKeys]) {
            $serialized = $model->toArray();
            foreach ($hiddenKeys as $hiddenKey) {
                $this->assertArrayNotHasKey($hiddenKey, $serialized, $model::class." leaked [{$hiddenKey}] in toArray().");
            }
        }
    }

    public function test_sensitive_routes_and_legacy_fallback_enforce_no_store_and_httponly_cookies(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $this->assertNoStoreAndHttpOnlyCookies($this->get('/supplier/registration/access'), 'supplier.registration.access-form');
        $this->assertNoStoreAndHttpOnlyCookies($this->actingAs($user)->get(route('profile.security')), 'profile.security');
        $this->assertNoStoreAndHttpOnlyCookies($this->actingAs($user)->getJson(route('notifications.unread-count')), 'notifications.unread-count');
        $this->assertNoStoreAndHttpOnlyCookies($this->actingAs($user)->getJson(route('conversations.unread-count')), 'conversations.unread-count');

        $optionsFallback = $this->call('OPTIONS', '/login');
        $optionsFallback->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', (string) $optionsFallback->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $optionsFallback->headers->get('Cache-Control'));
    }

    private function assertNoStoreAndHttpOnlyCookies(TestResponse $response, string $context): void
    {
        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cacheControl, "[{$context}] missing no-store Cache-Control.");
        $this->assertStringContainsString('private', $cacheControl, "[{$context}] missing private Cache-Control.");

        foreach ($response->headers->getCookies() as $cookie) {
            $this->assertNotSame('XSRF-TOKEN', $cookie->getName(), "[{$context}] must not emit non-HttpOnly XSRF-TOKEN cookie.");
            $this->assertTrue($cookie->isHttpOnly(), "[{$context}] cookie [{$cookie->getName()}] must be HttpOnly.");
        }
    }
}
