<?php

namespace Tests\Feature\LocalInvoice;

use App\Exports\LocalInvoicesExport;
use App\Jobs\ProcessExportJob;
use App\Models\ExportJob;
use App\Models\LocalInvoice;
use App\Models\LocalInvoiceDocument;
use App\Models\Supplier;
use App\Models\User;
use App\Notifications\LocalInvoice\RevisionRequiredNotification;
use App\Notifications\SystemNotification;
use App\Services\LocalInvoice\InvoicePhysicalReceiptService;
use App\Services\LocalInvoice\InvoiceSubmissionService;
use App\Services\LocalInvoice\InvoiceVerificationService;
use App\Services\NotificationUrlResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LocalInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private User $supplier;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        Notification::fake();
        $this->supplier = $this->supplier(['local']);
        $this->operator = User::factory()->create(['role' => 'finance']);
    }

    private function supplier(array $scopes): User
    {
        $user = User::factory()->create();
        $user->supplierScopes()->delete();
        foreach ($scopes as $scope) {
            $user->supplierScopes()->create(['scope' => $scope]);
        }
        Supplier::create(['user_id' => $user->id, 'company_name' => 'Local Vendor '.$user->id, 'address' => 'Jakarta', 'phone' => '123', 'npwp' => '123', 'category' => 'Parts', 'payment_term_days' => 30, 'is_pkp' => true]);

        return $user;
    }

    private function data(array $overrides = []): array
    {
        return array_merge([
            'invoice_number' => 'INV-001',
            'invoice_date' => '2026-09-08',
            'po_source' => 'MANUAL',
            'po_number' => 'PO-MANUAL-01',
            'manual_po_number' => 'PO-MANUAL-01',
            'manual_gr_reference' => 'GR-MANUAL-01',
            'invoice_amount' => '100000.25',
            'tax_amount' => '11000.03',
            'tax_invoice_number' => '010.000-26.12345678',
        ], $overrides);
    }

    private function files(): array
    {
        return ['invoice' => UploadedFile::fake()->createWithContent('invoice.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF"), 'tax_invoice' => UploadedFile::fake()->image('tax.png')];
    }

    private function submit(): LocalInvoice
    {
        $this->actingAs($this->supplier)->post(route('local-supplier.invoices.store'), array_merge($this->data(), $this->files()))->assertSessionHasNoErrors()->assertRedirect();

        return LocalInvoice::sole();
    }

    public static function scopes(): array
    {
        return [[['import'], true, false], [['local'], false, true], [['import', 'local'], true, true]];
    }

    #[DataProvider('scopes')]
    public function test_scope_matrix(array $scopes, bool $import, bool $local): void
    {
        $user = $this->supplier($scopes);
        $this->actingAs($user)->get(route('supplier.dashboard'))->assertStatus($import ? 200 : 403);
        $this->get(route('local-supplier.dashboard'))->assertStatus($local ? 200 : 403);
        $this->withSession(['supplier_context' => 'import'])->get(route('supplier.quotations.index'))->assertStatus($import ? 200 : 403);
    }

    public function test_submission_is_authoritative_private_and_receipted(): void
    {
        $payload = array_merge($this->data(), $this->files(), ['supplier_id' => $this->operator->id, 'status' => 'APPROVED', 'payment_term_days_snapshot' => 1, 'currency' => 'USD', 'due_date' => '2000-01-01']);
        $this->actingAs($this->supplier)->post(route('local-supplier.invoices.store'), $payload)->assertSessionHasNoErrors();
        $invoice = LocalInvoice::sole();
        $this->assertSame($this->supplier->id, $invoice->supplier_id);
        $this->assertSame('WAITING_PHYSICAL_DOCUMENT', $invoice->status);
        $this->assertSame('IDR', $invoice->currency);
        $this->assertSame(30, $invoice->payment_term_days_snapshot);
        $this->assertNull($invoice->due_date);
        $this->assertSame('100000.25', $invoice->invoice_amount);
        $this->assertSame('11000.03', $invoice->tax_amount);
        $this->assertCount(1, $invoice->revisions);
        $this->assertNotNull($invoice->receipt);
        foreach ($invoice->documents as $document) {
            Storage::disk('private')->assertExists($document->file_path);
            $this->assertStringStartsWith('local-invoices/', $document->file_path);
        }
        $this->get(route('local-supplier.invoices.receipt', $invoice))->assertOk()->assertSee($invoice->receipt->receipt_number);
        $this->get(route('local-supplier.invoices.show', $invoice))->assertOk()->assertSee('Waiting Physical Document');
    }

    public static function legacyMutationActions(): array
    {
        return [
            ['physical-verification'],
            ['start-review'],
            ['request-revision'],
            ['reject'],
            ['approve'],
            ['schedule-payment'],
            ['complete-payment'],
        ];
    }

    #[DataProvider('legacyMutationActions')]
    public function test_legacy_accounting_mutation_routes_are_disabled(string $action): void
    {
        $invoice = $this->submit();
        $this->actingAs($this->operator)
            ->post("/accounting/invoices/{$invoice->id}/{$action}")
            ->assertNotFound();
    }

    public function test_legacy_accounting_invoice_show_is_strictly_read_only(): void
    {
        $invoice = $this->submit();
        $this->actingAs($this->operator)
            ->get(route('accounting.invoices.show', $invoice))
            ->assertOk()
            ->assertDontSee('Verify Physical Documents')
            ->assertDontSee('Approve Invoice')
            ->assertDontSee('Schedule Payment')
            ->assertDontSee('Complete Payment');
    }

    public function test_required_files_mime_size_and_missing_files(): void
    {
        $this->actingAs($this->supplier)->post(route('local-supplier.invoices.store'), $this->data())->assertSessionHasErrors(['invoice', 'tax_invoice']);
        $temporary = tmpfile();
        fwrite($temporary, '<?php echo 1;');
        try {
            $disguised = new UploadedFile(stream_get_meta_data($temporary)['uri'], 'bad.pdf', 'application/pdf', null, true);
            $this->post(route('local-supplier.invoices.store'), array_merge($this->data(), $this->files(), ['invoice' => $disguised]))->assertSessionHasErrors('invoice');
        } finally {
            fclose($temporary);
        }
        $this->post(route('local-supplier.invoices.store'), array_merge($this->data(), $this->files(), ['invoice' => UploadedFile::fake()->create('large.pdf', 10241, 'application/pdf')]))->assertSessionHasErrors('invoice');
        $this->assertDatabaseCount('local_invoices', 0);
        $invoice = $this->submit();
        $document = $invoice->documents()->first();
        $this->get(route('local-invoice-documents.show', $document))->assertOk();
        Storage::disk('private')->delete($document->file_path);
        $this->get(route('local-invoice-documents.show', $document))->assertNotFound();
    }

    public function test_database_failure_compensates_only_new_files(): void
    {
        $invoice = $this->submit();
        $paths = Storage::disk('private')->allFiles();
        app(InvoicePhysicalReceiptService::class)->recordReceipt($this->operator, $invoice);
        app(InvoiceVerificationService::class)->requestRevision($invoice, 'Revise', $this->operator);
        $listener = function () {
            throw new \RuntimeException('Injected database write failure');
        };
        LocalInvoiceDocument::creating($listener);
        try {
            app(InvoiceSubmissionService::class)->resubmit($this->supplier, $invoice, $this->data(), $this->files());
            $this->fail('Expected injected failure');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Injected database write failure', $exception->getMessage());
        } finally {
            LocalInvoiceDocument::flushEventListeners();
        }
        $this->assertEqualsCanonicalizing($paths, Storage::disk('private')->allFiles());
        $this->assertSame('NEED_REVISION', $invoice->fresh()->status);
        $this->assertSame(1, $invoice->revisions()->count());
    }

    public function test_duplicate_invoice_numbers_are_per_supplier_but_po_reference_is_not_unique(): void
    {
        $invoice = $this->submit();
        $this->post(route('local-supplier.invoices.store'), array_merge($this->data(), $this->files()))->assertSessionHasErrors('invoice_number');
        $this->post(route('local-supplier.invoices.store'), array_merge($this->data(['invoice_number' => 'INV-002', 'tax_invoice_number' => '010.000-26.12345679']), $this->files()))->assertSessionHasNoErrors();
        $other = $this->supplier(['local']);
        $this->actingAs($other)->post(route('local-supplier.invoices.store'), array_merge($this->data(), $this->files()))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('local_invoices', 3);
        $this->assertSame(3, LocalInvoice::distinct()->count('submission_number'));
    }

    public function test_context_resolution_and_navigation(): void
    {
        $this->actingAs($this->supplier)->get(route('dashboard'))->assertRedirect(route('local-supplier.dashboard', absolute: false));
        $this->get(route('local-supplier.dashboard'))->assertSee('Submit Invoice')->assertDontSee('Quotation Period');
        $both = $this->supplier(['import', 'local']);
        $this->actingAs($both)->withSession(['supplier_context' => null])->get(route('dashboard'))->assertRedirect(route('supplier-context.index', absolute: false));
        $this->post(route('supplier-context.store'), ['context' => 'local'])->assertRedirect(route('local-supplier.dashboard', absolute: false));
        $this->get(route('local-supplier.dashboard'))->assertSee('Switch Portal');
        $this->post(route('supplier-context.store'), ['context' => 'import'])->assertRedirect(route('supplier.dashboard', absolute: false));
        $this->actingAs($this->supplier)->post(route('supplier-context.store'), ['context' => 'import'])->assertForbidden();
    }

    public function test_local_notification_urls_are_role_and_ownership_safe(): void
    {
        $invoice = $this->submit();
        $notification = new DatabaseNotification(['data' => ['local_invoice_id' => $invoice->id, 'url' => '/supplier/dashboard']]);
        $resolver = app(NotificationUrlResolver::class);
        $this->assertSame(route('local-supplier.invoices.show', $invoice, absolute: false), $resolver->resolve($notification, $this->supplier));
        $this->assertSame(route('finance.invoices.show', $invoice, absolute: false), $resolver->resolve($notification, $this->operator));
        $other = $this->supplier(['local']);
        $this->assertStringNotContainsString('/invoices/', $resolver->resolve($notification, $other));
    }

    public function test_overdue_upcoming_and_completed_classification(): void
    {
        $invoice = $this->submit();
        $invoice->forceFill(['status' => 'APPROVED', 'due_date' => today()->subDay()]);
        $this->assertSame('Overdue', $invoice->paymentCategory());
        $invoice->due_date = today()->addDays(6);
        $this->assertSame('Due < 7 Days', $invoice->paymentCategory());
        $invoice->forceFill(['status' => 'PAYMENT_SCHEDULED', 'due_date' => today()->addDays(7)]);
        $this->assertSame('Scheduled', $invoice->paymentCategory());
        $invoice->forceFill(['status' => 'COMPLETED', 'due_date' => today()->subDay()]);
        $this->assertSame('Completed', $invoice->paymentCategory());
    }

    public function test_receipt_lookup_filters_and_reports_render(): void
    {
        $invoice = $this->submit();
        $this->actingAs($this->operator)->get(route('accounting.physical-verification', ['q' => $invoice->receipt->receipt_number]))->assertOk()->assertSee($invoice->invoice_number);
        $this->get(route('accounting.invoices.index', ['q' => 'DOES-NOT-EXIST']))->assertOk()->assertDontSee($invoice->submission_number);
        $this->get(route('accounting.payment-schedule'))->assertOk()->assertDontSee($invoice->submission_number);
        $this->get(route('accounting.reports'))->assertOk();
    }

    public function test_plain_ids_and_unauthenticated_access_are_rejected(): void
    {
        $invoice = $this->submit();
        $this->get('/local-supplier/invoices/'.$invoice->id)->assertNotFound();
        auth()->logout();
        $this->get(route('local-invoice-documents.show', $invoice->documents()->first()))->assertRedirect(route('login'));
    }

    public function test_notification_events_and_revision_email(): void
    {
        $invoice = $this->submit();
        Notification::assertSentTo($this->operator, SystemNotification::class);
        app(InvoicePhysicalReceiptService::class)->recordReceipt($this->operator, $invoice);
        Notification::assertSentTo($this->supplier, SystemNotification::class);
        app(InvoiceVerificationService::class)->requestRevision($invoice, 'Please replace Faktur Pajak', $this->operator);
        Notification::assertSentTo($this->supplier, RevisionRequiredNotification::class, fn ($notification) => $notification->reason === 'Please replace Faktur Pajak' && $notification->url === route('local-supplier.invoices.show', $invoice));
    }

    public function test_storage_write_failure_rolls_back_submission(): void
    {
        Storage::shouldReceive('disk')->with('private')->andReturn($disk = \Mockery::mock());
        $disk->shouldReceive('put')->once()->andReturn(false);
        $disk->shouldReceive('delete')->once()->andReturn(true);
        try {
            app(InvoiceSubmissionService::class)->submit($this->supplier, $this->data(), $this->files());
            $this->fail('Expected storage failure');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Unable to store document.', $exception->getMessage());
        }
        $this->assertDatabaseCount('local_invoices', 0);
        $this->assertDatabaseCount('local_invoice_documents', 0);
        $this->assertDatabaseCount('local_invoice_receipts', 0);
    }

    public function test_missing_payment_term_and_forged_snapshot_are_rejected(): void
    {
        $this->supplier->supplier->update(['payment_term_days' => null]);
        $this->actingAs($this->supplier)->post(route('local-supplier.invoices.store'), array_merge($this->data(), $this->files(), ['payment_term_days_snapshot' => 30]))->assertSessionHasErrors('payment_term_days');
        $this->assertDatabaseCount('local_invoices', 0);
    }

    public function test_admin_configures_local_both_and_internal_roles(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $payload = ['name' => 'Configured Supplier', 'email' => 'configured@example.test', 'password' => 'LongPassword!1234', 'password_confirmation' => 'LongPassword!1234', 'role' => 'supplier', 'company_name' => 'Configured', 'address' => 'Jakarta', 'phone' => '123', 'npwp' => '123', 'category' => 'Parts', 'is_active' => 1, 'supplier_scopes_present' => 1];
        $this->actingAs($admin)->post(route('admin.users.store'), $payload)->assertSessionHasErrors('supplier_scopes');
        $this->post(route('admin.users.store'), array_merge($payload, ['supplier_scopes' => ['local']]))->assertSessionHasErrors('payment_term_days');
        $this->post(route('admin.users.store'), array_merge($payload, ['supplier_scopes' => ['local'], 'payment_term_days' => 45]))->assertSessionHasNoErrors();
        $configured = User::where('email', 'configured@example.test')->firstOrFail();
        $this->assertTrue($configured->hasSupplierScope('local'));
        $this->assertFalse($configured->hasSupplierScope('import'));
        $this->assertEquals(45, $configured->supplier->payment_term_days);
        $this->put(route('admin.users.update', $configured), array_merge($payload, ['supplier_scopes' => ['import', 'local'], 'payment_term_days' => 60]))->assertSessionHasNoErrors();
        $this->assertTrue($configured->hasSupplierScope('import'));
        foreach (['accounting', 'finance'] as $role) {
            $this->post(route('admin.users.store'), array_merge($payload, ['role' => $role, 'email' => $role.'@example.test']))->assertSessionHasNoErrors();
            $this->assertDatabaseHas('users', ['email' => $role.'@example.test', 'role' => $role]);
        }
    }

    public function test_local_export_uses_existing_queue_and_preserves_filters(): void
    {
        $invoice = $this->submit();
        Bus::fake();
        $this->actingAs($this->operator)->post(route('accounting.reports.export'), ['report' => 'register', 'q' => $invoice->invoice_number])->assertRedirect(route('exports.index'));
        $job = ExportJob::sole();
        $this->assertSame(LocalInvoicesExport::class, $job->export_class);
        $this->assertSame($this->operator->id, $job->user_id);
        $this->assertSame('private', $job->disk);
        Bus::assertDispatched(ProcessExportJob::class);
        $export = new LocalInvoicesExport(...$job->export_args);
        $this->assertSame(1, $export->progressTotalRows());
        $this->assertSame($invoice->submission_number, $export->map($export->query()->first())[0]);
        $this->actingAs($this->supplier)->get(route('exports.status', $job))->assertForbidden();
        $this->post(route('accounting.reports.export'), ['report' => 'register'])->assertForbidden();
    }

    public function test_decimal_limits_optional_supporting_file_and_exact_values(): void
    {
        $this->actingAs($this->supplier)->post(route('local-supplier.invoices.store'), array_merge($this->data(['invoice_amount' => '1000000000000000000.00']), $this->files()))->assertSessionHasErrors('invoice_amount');
        $this->post(route('local-supplier.invoices.store'), array_merge($this->data(['tax_amount' => '1.001']), $this->files()))->assertSessionHasErrors('tax_amount');
        $this->post(route('local-supplier.invoices.store'), array_merge($this->data(['invoice_amount' => '999999999999999999.99']), $this->files(), ['supporting' => UploadedFile::fake()->image('support.jpg')]))->assertSessionHasNoErrors();
        $invoice = LocalInvoice::sole();
        $this->assertSame('999999999999999999.99', $invoice->invoice_amount);
        $this->assertSame(3, $invoice->documents()->count());
        $this->get(route('local-supplier.invoices.show', $invoice))->assertOk()->assertSee('999999999999999999.99');
    }

    public function test_revoking_scope_blocks_all_local_document_and_import_shared_routes(): void
    {
        $invoice = $this->submit();
        $this->supplier->supplierScopes()->delete();
        $this->actingAs($this->supplier)->get(route('local-supplier.invoices.show', $invoice))->assertForbidden();
        $this->get(route('local-invoice-documents.show', $invoice->documents()->first()))->assertForbidden();
        $this->get(route('attachments.show', 1))->assertForbidden();
        $this->get(route('conversations.drawer.index'))->assertForbidden();
        $this->get(route('shared.pdf.purchase-order', $invoice))->assertForbidden();
    }

    public function test_submit_and_resubmit_redirect_directly_to_receipt(): void
    {
        $response = $this->actingAs($this->supplier)->post(
            route('local-supplier.invoices.store'),
            array_merge($this->data(), $this->files())
        );
        $response->assertSessionHasNoErrors();
        $invoice = LocalInvoice::sole();
        $response->assertRedirect(route('local-supplier.invoices.receipt', $invoice));

        $this->get(route('local-supplier.invoices.receipt', $invoice))
            ->assertOk()
            ->assertSee($invoice->receipt->receipt_number)
            ->assertSee('Cetak Tanda Terima')
            ->assertSee('Lihat Detail Invoice');

        app(InvoicePhysicalReceiptService::class)->recordReceipt($this->operator, $invoice);
        app(InvoiceVerificationService::class)->requestRevision($invoice, 'Please revise tax document', $this->operator);
        $this->assertSame('NEED_REVISION', $invoice->fresh()->status);

        $resubmitResponse = $this->actingAs($this->supplier)->post(
            route('local-supplier.invoices.resubmit', $invoice),
            array_merge($this->data(), $this->files())
        );
        $resubmitResponse->assertSessionHasNoErrors();
        $resubmitResponse->assertRedirect(route('local-supplier.invoices.receipt', $invoice));
    }
}
