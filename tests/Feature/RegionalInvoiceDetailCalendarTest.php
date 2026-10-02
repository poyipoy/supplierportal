<?php

namespace Tests\Feature;

use App\Exports\LocalInvoicesExport;
use App\Models\LocalGoodsReceipt;
use App\Models\LocalInvoice;
use App\Models\LocalInvoiceGoodsReceipt;
use App\Models\LocalInvoicePayment;
use App\Models\LocalInvoicePaymentTransfer;
use App\Models\LocalInvoiceReceipt;
use App\Models\LocalInvoiceStatusHistory;
use App\Models\LocalInvoiceVoucher;
use App\Models\LocalPurchaseOrder;
use App\Models\PaymentBatch;
use App\Models\PaymentGroup;
use App\Models\PaymentItem;
use App\Models\Supplier;
use App\Models\User;
use App\Services\RegionalDisplayFormatter;
use Carbon\Carbon;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class RegionalInvoiceDetailCalendarTest extends TestCase
{
    use RefreshDatabase;

    private User $supplier;

    private User $accounting;

    private User $finance;

    private User $purchasing;

    private LocalInvoice $invoice;

    private LocalPurchaseOrder $po;

    private LocalInvoicePayment $payment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-30T12:00:00Z'));
        $this->supplier = User::factory()->create(['role' => 'supplier']);
        $this->supplier->supplierScopes()->create(['scope' => 'local']);
        Supplier::create(['user_id' => $this->supplier->id, 'company_name' => 'Regional Calendar Supplier', 'vendor_category' => 'Barang', 'is_pkp' => true, 'payment_term_days' => 30]);
        $this->accounting = User::factory()->create(['role' => 'accounting']);
        $this->finance = User::factory()->create(['role' => 'finance']);
        $this->purchasing = User::factory()->create(['role' => 'purchasing']);
        $this->po = LocalPurchaseOrder::create(['supplier_id' => $this->supplier->id, 'po_number' => 'PO-CALENDAR-001', 'po_date' => '2026-09-29', 'currency' => 'IDR', 'total_amount' => '2000000.50', 'status' => 'OPEN']);
        $this->invoice = LocalInvoice::create([
            'supplier_id' => $this->supplier->id, 'submission_number' => 'SUB-CALENDAR-001', 'invoice_number' => 'INV-CALENDAR-001',
            'invoice_date' => '2026-09-28', 'invoice_amount' => '1250000.50', 'tax_amount' => '137500.06', 'ppn_scheme' => '11%',
            'status' => LocalInvoice::STATUS_READY_TO_PAY, 'po_source' => 'INTERNAL', 'po_number' => $this->po->po_number,
            'local_purchase_order_id' => $this->po->id, 'due_date' => '2026-10-02', 'scheduled_payment_date' => '2026-10-05',
            'scheduled_physical_delivery_date' => '2026-09-30', 'submitted_at' => '2026-09-28 23:35:00', 'payment_term_days_snapshot' => 30,
        ]);
        $this->addGoodsReceipt(1, '2026-09-30');
        $this->createPayment();
        $this->addTransfer(1, '2026-10-06');
        LocalInvoiceReceipt::create(['local_invoice_id' => $this->invoice->id, 'receipt_number' => 'RCPT-CALENDAR-001', 'issued_at' => '2026-09-28 23:35:00']);
        LocalInvoiceStatusHistory::create(['local_invoice_id' => $this->invoice->id, 'from_status' => LocalInvoice::STATUS_UNDER_VERIFICATION, 'to_status' => $this->invoice->status, 'actor_id' => $this->finance->id, 'event' => 'approved', 'notes' => 'Calendar history remains unchanged', 'created_at' => '2026-09-28 23:35:00']);
    }

    public function test_only_exact_substantive_invoice_views_receive_the_scoped_formatter(): void
    {
        $this->actingAs($this->accounting);
        foreach (['local-invoices.detail', 'finance.invoices.show', 'purchasing.local-vendors.invoice-show'] as $target) {
            $view = view($target);
            app('view')->callComposer($view);
            $this->assertArrayHasKey('regionalFormatter', $view->getData(), $target);
            $this->assertSame(app(RegionalDisplayFormatter::class), $view->getData()['regionalFormatter']);
        }
        foreach (['accounting.invoices.show', 'local-supplier.invoices.show', 'local-invoices.scripts', 'local-invoices.form', 'local-supplier.invoices.receipt'] as $excluded) {
            $view = view($excluded);
            app('view')->callComposer($view);
            $this->assertArrayNotHasKey('regionalFormatter', $view->getData(), $excluded);
        }
    }

    public function test_all_authorized_invoice_audiences_use_system_human_dmy_and_iso_without_shifting_calendar_dates(): void
    {
        foreach ($this->audiences() as [$user, $route, $portal]) {
            foreach (['system' => 'd M Y', 'human' => 'd M Y', 'dmy' => 'd/m/Y', 'iso' => 'Y-m-d'] as $format => $pattern) {
                foreach (['system', 'Asia/Jakarta'] as $timezone) {
                    $this->preference($user, $format, $timezone);
                    $html = $this->page($user, $route);
                    $this->assertSame(Carbon::parse('2026-09-28')->format($pattern), $this->label($html, $portal === 'shared' ? 'Tanggal Invoice' : 'Tanggal Invoice:'));
                    if ($portal === 'shared') {
                        $this->assertSame(Carbon::parse('2026-09-29')->format($pattern), $this->label($html, 'Tanggal PO'));
                        $this->assertSame(Carbon::parse('2026-09-30')->format($pattern), $this->rowCell($html, 'GR-CALENDAR-001', 1));
                        $this->assertSame(Carbon::parse('2026-10-06')->format($pattern), $this->rowCell($html, 'BANK-CALENDAR-001', 1));
                        if ($user->role !== 'supplier') {
                            $this->assertSame(Carbon::parse('2026-10-02')->format($pattern), $this->label($html, 'Jatuh Tempo:'));
                            $this->assertSame(Carbon::parse('2026-10-05')->format($pattern), $this->label($html, 'Jadwal Bayar ADASI:'));
                        }
                    } elseif ($portal === 'finance') {
                        $this->assertSame(Carbon::parse('2026-09-29')->format($pattern), $this->label($html, 'PO Date'));
                        $this->assertSame(Carbon::parse('2026-09-30')->format($pattern), $this->rowCell($html, 'GR-CALENDAR-001', 1));
                        $this->assertSame(Carbon::parse('2026-10-02')->format($pattern), $this->label($html, 'Jatuh Tempo:'));
                    } else {
                        $this->assertSame(Carbon::parse('2026-10-02')->format($pattern), $this->label($html, 'Jatuh Tempo:'));
                    }
                }
            }
        }
        $this->assertSame('2026-09-28', $this->invoice->fresh()->getRawOriginal('invoice_date'));
        $this->assertSame('2026-09-28 23:35:00', $this->invoice->fresh()->getRawOriginal('submitted_at'));
    }

    public function test_calendar_boundaries_and_leap_day_keep_the_same_day_under_system_and_jakarta(): void
    {
        foreach (['2026-09-30', '2026-12-31', '2027-01-01', '2028-02-29'] as $calendar) {
            $this->invoice->update(['invoice_date' => $calendar, 'due_date' => $calendar, 'scheduled_payment_date' => $calendar]);
            $this->po->update(['po_date' => $calendar]);
            $this->po->goodsReceipts()->update(['gr_date' => $calendar]);
            $this->payment->transfers()->update(['transfer_date' => $calendar]);
            foreach (['system', 'Asia/Jakarta'] as $timezone) {
                $this->preference($this->accounting, 'iso', $timezone);
                $html = $this->page($this->accounting, 'accounting.invoices.show');
                foreach (['Tanggal Invoice', 'Tanggal PO', 'Jatuh Tempo:', 'Jadwal Bayar ADASI:'] as $label) {
                    $this->assertSame($calendar, $this->label($html, $label));
                }
                $this->assertSame($calendar, $this->rowCell($html, 'GR-CALENDAR-001', 1));
                $this->assertSame($calendar, $this->rowCell($html, 'BANK-CALENDAR-001', 1));
                $this->assertSame($calendar, $this->invoice->fresh()->getRawOriginal('invoice_date'));
                $this->assertSame($calendar, $this->po->fresh()->getRawOriginal('po_date'));
                $this->assertSame($calendar, $this->payment->transfers()->first()->getRawOriginal('transfer_date'));
            }
        }
    }

    public function test_nullable_dates_keep_placeholders_and_unscheduled_row_omission(): void
    {
        $this->invoice->update(['due_date' => null, 'scheduled_payment_date' => null]);
        foreach (['system', 'dmy', 'iso'] as $format) {
            foreach ([$this->accounting, $this->finance, $this->purchasing] as $user) {
                $this->preference($user, $format);
            }
            $shared = $this->page($this->accounting, 'accounting.invoices.show');
            $this->assertSame('—', $this->label($shared, 'Jatuh Tempo:'));
            $this->assertStringNotContainsString('Jadwal Bayar ADASI:', $shared);
            foreach ([[$this->finance, 'finance.invoices.show'], [$this->purchasing, 'purchasing.local-invoices.show']] as [$user, $route]) {
                $this->assertSame('Menunggu Kasir', $this->label($this->page($user, $route), 'Jatuh Tempo:'));
            }
        }
        $this->assertNull($this->invoice->fresh()->getRawOriginal('due_date'));
        $this->assertNull($this->invoice->fresh()->getRawOriginal('scheduled_payment_date'));

        // Required DATE columns stay non-null in the database; exercise defensive view guards in memory.
        $invoice = $this->loadedInvoice();
        $invoice->localPurchaseOrder->po_date = null;
        $invoice->goodsReceiptHistories->first()->setRelation('goodsReceipt', null);
        $invoice->payment->transfers->first()->transfer_date = null;
        $shared = $this->renderLoaded($this->accounting, 'accounting.invoices.show', $invoice);
        $this->assertSame('—', $this->label($shared, 'Tanggal PO'));
        $this->assertSame('—', $this->rowCell($shared, 'GR-CALENDAR-001', 1));
        $this->assertSame('—', $this->rowCell($shared, 'BANK-CALENDAR-001', 1));
        $invoice->invoice_date = null;
        $finance = $this->renderLoaded($this->finance, 'finance.invoices.show', $invoice);
        $this->assertSame('—', $this->label($finance, 'Tanggal Invoice:'));
        $this->assertSame('—', $this->label($finance, 'PO Date'));
        $this->assertSame('—', $this->rowCell($finance, 'GR-CALENDAR-001', 1));
        $purchasing = $this->renderLoaded($this->purchasing, 'purchasing.local-vendors.invoice-show', $invoice);
        $this->assertSame('—', $this->label($purchasing, 'Tanggal Invoice:'));
        $this->assertSame('2026-09-28', $this->invoice->fresh()->getRawOriginal('invoice_date'));
    }

    public function test_preferences_preserve_machine_values_financial_cells_history_and_business_results(): void
    {
        foreach ($this->audiences() as [$user, $route, $portal]) {
            $this->preference($user, 'system', 'system');
            $before = $this->page($user, $route);
            $raw = $this->businessSnapshot();
            $machine = $this->machineSnapshot($before);
            $money = $this->moneyTexts($before);
            $history = $this->historyBlock($before, 'Calendar history remains unchanged');
            $this->assertNotSame([], $money);
            $this->assertNotSame('', $history);
            foreach (['dmy', 'iso'] as $format) {
                $this->preference($user, $format);
                $after = $this->page($user, $route);
                $this->assertSame($machine, $this->machineSnapshot($after));
                $this->assertSame($money, $this->moneyTexts($after));
                $afterHistory = $this->historyBlock($after, 'Calendar history remains unchanged');
                $this->assertNotSame('', $afterHistory);
                $this->assertStringContainsString('Calendar history remains unchanged', $afterHistory);
                $this->assertSame($raw, $this->businessSnapshot());
                if ($portal === 'shared') {
                    $this->assertSame('1,2345', $this->rowCell($after, 'GR-CALENDAR-001', 2));
                    $this->assertSame($this->rowCell($before, 'BANK-CALENDAR-001', 4), $this->rowCell($after, 'BANK-CALENDAR-001', 4));
                } elseif ($portal === 'finance') {
                    $this->assertSame('30 Sep 2026 (Wednesday)', $this->label($after, 'Jadwal Kirim Fisik:'));
                } else {
                    $this->assertSame($this->label($before, 'Tanggal Kirim Fisik:'), $this->label($after, 'Tanggal Kirim Fisik:'));
                }
            }
        }
    }

    public function test_display_schedule_and_machine_date_picker_remain_independent(): void
    {
        $this->preference($this->accounting, 'dmy');
        $html = $this->page($this->accounting, 'accounting.invoices.show');
        $this->assertSame('05/10/2026', $this->label($html, 'Jadwal Bayar ADASI:'));

        // The current Accounting controller exposes no workflow actions. Exercise its retained picker
        // fragment directly rather than inventing the obsolete schedule-payment endpoint around it.
        $source = file_get_contents(resource_path('views/local-invoices/detail.blade.php'));
        $this->assertSame(1, preg_match('/<x-ui\.date-picker\s+name="scheduled_payment_date".*?\/>/s', $source, $fragment));
        $picker = Blade::render($fragment[0], ['invoice' => $this->invoice, 'errors' => new ViewErrorBag]);
        $input = $this->document($picker)->query('//input[@name="scheduled_payment_date"]')->item(0);
        $this->assertNotNull($input);
        $this->assertSame('2026-10-02', $input->getAttribute('value'));
    }

    public function test_owner_scope_role_and_hashid_boundaries_are_unchanged(): void
    {
        $foreign = User::factory()->create(['role' => 'supplier']);
        $foreign->supplierScopes()->create(['scope' => 'local']);
        $import = User::factory()->create(['role' => 'supplier']);
        $import->supplierScopes()->firstOrCreate(['scope' => 'import']);
        foreach ([$foreign, $import] as $user) {
            $this->preference($user, 'iso');
            $this->actingAs($user)->get(route('local-supplier.invoices.show', $this->invoice))->assertForbidden();
        }
        $this->actingAs($this->supplier)->get(route('finance.invoices.show', $this->invoice))->assertForbidden();
        $this->actingAs($this->accounting)->get(route('finance.invoices.show', $this->invoice))->assertForbidden();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->preference($admin, 'iso');
        foreach (['finance.invoices.show', 'accounting.invoices.show'] as $route) {
            $html = $this->page($admin, $route);
            $this->assertSame('2026-09-28', $this->label($html, $route === 'finance.invoices.show' ? 'Tanggal Invoice:' : 'Tanggal Invoice'));
        }
        $this->actingAs($admin)->get(route('purchasing.local-invoices.show', $this->invoice))->assertForbidden();
        foreach ($this->audiences() as [$user, $route]) {
            $this->actingAs($user)->get(route($route, $this->invoice->id))->assertNotFound();
        }

        $this->supplier->update(['is_active' => false]);
        $this->actingAs($this->supplier)
            ->get(route('local-supplier.invoices.show', $this->invoice))
            ->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_one_preference_lookup_is_reused_for_single_and_multiple_gr_and_transfer_rows(): void
    {
        $counter = (object) ['count' => 0];
        DB::listen(function ($query) use ($counter): void {
            if (str_starts_with(strtolower(ltrim($query->sql)), 'select') && str_contains(strtolower($query->sql), 'user_preferences')) {
                $counter->count++;
            }
        });
        foreach ([1, 4] as $rows) {
            if ($rows > 1) {
                for ($i = 2; $i <= $rows; $i++) {
                    $this->addGoodsReceipt($i, '2026-09-30');
                    $this->addTransfer($i, '2026-10-06');
                }
            }
            foreach ($this->audiences() as [$user, $route, $portal]) {
                $this->preference($user, 'iso');
                $counter->count = 0;
                $html = $this->page($user, $route);
                $this->assertSame(1, $counter->count, $route.' with '.$rows.' related rows');
                if ($portal !== 'purchasing') {
                    $this->assertSame('2026-09-30', $this->rowCell($html, sprintf('GR-CALENDAR-%03d', $rows), 1));
                }
                if ($portal === 'shared') {
                    $this->assertSame('2026-10-06', $this->rowCell($html, sprintf('BANK-CALENDAR-%03d', $rows), 1));
                }
            }
        }
    }

    public function test_receipt_semantics_and_invoice_export_rows_ignore_regional_date_preferences(): void
    {
        $this->preference($this->accounting, 'system', 'system');
        $receiptBefore = $this->actingAs($this->accounting)->get(route('accounting.invoices.receipt', $this->invoice))->assertOk()->getContent();
        $export = new LocalInvoicesExport($this->accounting->id);
        $rowBefore = $export->map($this->loadedInvoice());
        foreach (['dmy', 'iso'] as $format) {
            $this->preference($this->accounting, $format);
            $receiptAfter = $this->actingAs($this->accounting)->get(route('accounting.invoices.receipt', $this->invoice))->assertOk()->getContent();
            $before = $this->document($receiptBefore)->query('//*[contains(concat(" ", normalize-space(@class), " "), " local-invoice-receipt ")]')->item(0);
            $after = $this->document($receiptAfter)->query('//*[contains(concat(" ", normalize-space(@class), " "), " local-invoice-receipt ")]')->item(0);
            $this->assertNotNull($before);
            $this->assertNotNull($after);
            $this->assertSame($before->ownerDocument->saveHTML($before), $after->ownerDocument->saveHTML($after));
            $this->assertSame($rowBefore, $export->map($this->loadedInvoice()));
        }
        $this->assertSame('1250000.50', $rowBefore[7]);
        $this->assertSame('137500.06', $rowBefore[8]);
        $this->assertSame('2026-10-02', $rowBefore[13]);
        $this->assertSame('2026-10-05', $rowBefore[14]);
    }

    private function audiences(): array
    {
        return [
            [$this->accounting, 'accounting.invoices.show', 'shared'],
            [$this->supplier, 'local-supplier.invoices.show', 'shared'],
            [$this->finance, 'finance.invoices.show', 'finance'],
            [$this->purchasing, 'purchasing.local-invoices.show', 'purchasing'],
        ];
    }

    private function preference(User $user, string $date, string $timezone = 'Asia/Jakarta'): void
    {
        $user->preference()->updateOrCreate([], [...config('user_preferences.defaults'), 'timezone' => $timezone, 'date_format' => $date, 'time_format' => '12h', 'number_format' => 'international']);
        app()->forgetScopedInstances();
    }

    private function page(User $user, string $route): string
    {
        app()->forgetScopedInstances();

        return $this->actingAs($user)->get(route($route, $this->invoice))->assertOk()->getContent();
    }

    private function addGoodsReceipt(int $sequence, ?string $date): void
    {
        $gr = LocalGoodsReceipt::create(['local_purchase_order_id' => $this->po->id, 'gr_number' => sprintf('GR-CALENDAR-%03d', $sequence), 'gr_date' => $date, 'qty' => '1.2345', 'status' => 'INVOICED', 'current_invoice_id' => $this->invoice->id]);
        LocalInvoiceGoodsReceipt::create(['local_invoice_id' => $this->invoice->id, 'revision_number' => 1, 'local_purchase_order_id' => $this->po->id, 'local_goods_receipt_id' => $gr->id, 'gr_number_snapshot' => $gr->gr_number, 'gr_qty_snapshot' => '1.2345', 'state' => LocalInvoiceGoodsReceipt::STATE_CONSUMED]);
    }

    private function createPayment(): void
    {
        $batch = PaymentBatch::create(['batch_number' => 'DRP-CALENDAR-001', 'batch_type' => 'SUPPLIER', 'status' => 'FINALIZED', 'created_by' => $this->finance->id]);
        $group = PaymentGroup::create(['payment_batch_id' => $batch->id, 'payee_type' => 'supplier', 'payee_id' => $this->supplier->id, 'payee_name' => 'Regional Calendar Supplier', 'bank_name' => 'BCA', 'account_number' => '1234567890', 'account_holder_name' => 'Regional Calendar Supplier', 'subtotal_amount' => '1387500.56', 'net_payment_amount' => '1387500.56']);
        $item = PaymentItem::create(['payment_group_id' => $group->id, 'payable_type' => LocalInvoice::class, 'payable_id' => $this->invoice->id, 'amount' => '1387500.56']);
        $voucher = LocalInvoiceVoucher::create([
            'local_invoice_id' => $this->invoice->id, 'payment_batch_id' => $batch->id, 'payment_group_id' => $group->id, 'payment_item_id' => $item->id,
            'voucher_number' => 'VB-CALENDAR-001', 'voucher_date' => '2026-10-06', 'payment_method' => 'BANK', 'supplier_name_snapshot' => 'Regional Calendar Supplier',
            'bank_name_snapshot' => 'BCA', 'bank_account_snapshot' => '1234567890', 'bank_account_holder_snapshot' => 'Regional Calendar Supplier',
            'invoice_number_snapshot' => $this->invoice->invoice_number, 'po_number_snapshot' => $this->po->po_number, 'gr_references_snapshot' => 'GR-CALENDAR-001',
            'dpp_snapshot' => '1250000.50', 'ppn_snapshot' => '137500.06', 'pph_snapshot' => '0.00', 'net_payable_snapshot' => '1387500.56', 'amount' => '1387500.56',
            'terbilang_snapshot' => 'Snapshot unchanged', 'finalized_by' => $this->finance->id, 'finalized_at' => '2026-09-30 12:00:00',
        ]);
        $this->payment = LocalInvoicePayment::create(['local_invoice_id' => $this->invoice->id, 'local_invoice_voucher_id' => $voucher->id, 'payment_item_id' => $item->id, 'expected_amount' => '1387500.56', 'actual_paid_total' => '1000.25', 'status' => 'CORRECTION_REQUIRED', 'created_by' => $this->finance->id, 'updated_by' => $this->finance->id]);
    }

    private function addTransfer(int $sequence, string $date): void
    {
        LocalInvoicePaymentTransfer::create(['local_invoice_payment_id' => $this->payment->id, 'sequence_no' => $sequence, 'transfer_type' => $sequence === 1 ? 'PRIMARY' : 'CORRECTION', 'primary_guard' => $sequence === 1 ? $this->payment->id : null, 'amount' => '1000.25', 'transfer_reference' => sprintf('BANK-CALENDAR-%03d', $sequence), 'transfer_date' => $date, 'entered_by' => $this->finance->id, 'correction_reason' => $sequence === 1 ? null : 'Bank correction unchanged']);
    }

    private function loadedInvoice(): LocalInvoice
    {
        return $this->invoice->fresh(['supplier.supplier', 'receipt', 'revisions.documents', 'statusHistories.actor', 'physicalVerifications.actor', 'currentVerification.verifier', 'localPurchaseOrder', 'goodsReceiptHistories.goodsReceipt', 'payment.transfers', 'voucher.payment.transfers']);
    }

    private function renderLoaded(User $user, string $view, LocalInvoice $invoice, array $workflowActions = []): string
    {
        $this->actingAs($user);
        app()->forgetScopedInstances();

        return view($view, ['invoice' => $invoice, 'verification' => null, 'workflowActions' => $workflowActions, 'errors' => new ViewErrorBag])->render();
    }

    private function businessSnapshot(): array
    {
        $invoice = $this->invoice->fresh();

        return [
            'invoice' => $invoice->getAttributes(), 'po' => $this->po->fresh()->getAttributes(),
            'goods_receipts' => $this->po->goodsReceipts()->orderBy('id')->get()->map->getAttributes()->all(),
            'gr_histories' => $invoice->goodsReceiptHistories()->orderBy('id')->get()->map->getAttributes()->all(),
            'payment' => $this->payment->fresh()->getAttributes(),
            'transfers' => $this->payment->transfers()->orderBy('sequence_no')->get()->map->getAttributes()->all(),
            'status_history' => $invoice->statusHistories()->orderBy('id')->get()->map->getAttributes()->all(),
            'overdue' => $invoice->isOverdue(), 'remaining_days' => $invoice->remainingDays(),
        ];
    }

    private function machineSnapshot(string $html): array
    {
        $xpath = $this->document($html);
        $attributes = [];
        foreach ($xpath->query('//main//*') as $node) {
            $values = [];
            foreach ($node->attributes as $attribute) {
                if (in_array($attribute->name, ['id', 'name', 'value', 'href', 'action', 'method', 'type', 'checked', 'selected', 'disabled'], true) || str_starts_with($attribute->name, 'data-')) {
                    $values[$attribute->name] = $attribute->value;
                }
            }
            if ($values !== []) {
                $attributes[] = [$node->nodeName, $values];
            }
        }
        $scripts = [];
        foreach ($xpath->query('//script') as $script) {
            if (str_contains($script->textContent, 'local-workflow-form') || str_contains($script->textContent, 'data-print-receipt')) {
                $scripts[] = $script->textContent;
            }
        }

        return ['attributes' => $attributes, 'invoice_scripts' => $scripts];
    }

    private function moneyTexts(string $html): array
    {
        $texts = [];
        foreach ($this->document($html)->query('//*[not(*) and contains(text(), "Rp ")]') as $node) {
            $texts[] = trim($node->textContent);
        }

        return $texts;
    }

    private function historyBlock(string $html, string $marker): string
    {
        $node = $this->document($html)->query('//*[normalize-space(text())="'.$marker.'"]')->item(0);

        return $node ? $node->ownerDocument->saveHTML($node->parentNode) : '';
    }

    private function document(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }

    private function label(string $html, string $label): string
    {
        $nodes = $this->document($html)->query('//*[normalize-space(text())="'.$label.'"]/following-sibling::*[1]');
        $this->assertGreaterThan(0, $nodes->length, 'Missing label '.$label);

        return trim($nodes->item(0)->textContent);
    }

    private function rowCell(string $html, string $key, int $cell): string
    {
        $nodes = $this->document($html)->query('//tr[td[normalize-space(.)="'.$key.'"]]/td');
        $this->assertGreaterThan($cell, $nodes->length, 'Missing row '.$key);

        return trim($nodes->item($cell)->textContent);
    }
}
