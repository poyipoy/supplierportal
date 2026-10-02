<?php

namespace Tests\Feature;

use App\Models\LocalInvoice;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RegionalInvoiceCalendarDisplayTest extends TestCase
{
    use RefreshDatabase;

    private User $supplier;

    private User $finance;

    private User $systemFinance;

    private User $accounting;

    private LocalInvoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        Notification::fake();
        $this->supplier = User::factory()->create(['role' => 'supplier']);
        $this->supplier->supplierScopes()->create(['scope' => 'local']);
        Supplier::create(['user_id' => $this->supplier->id, 'company_name' => 'Regional Local Supplier', 'address' => 'Jakarta', 'phone' => '123', 'npwp' => '1234567890123456', 'category' => 'Parts', 'payment_term_days' => 30, 'is_pkp' => true]);
        $this->finance = User::factory()->create(['role' => 'finance']);
        $this->systemFinance = User::factory()->create(['role' => 'finance']);
        $this->accounting = User::factory()->create(['role' => 'accounting']);

        $pdf = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF";
        $payload = [
            'invoice_number' => 'INV-REGIONAL-001',
            'invoice_date' => '2026-09-28',
            'po_source' => 'MANUAL',
            'po_number' => 'PO-REGIONAL-001',
            'manual_po_number' => 'PO-REGIONAL-001',
            'manual_gr_reference' => 'GR-REGIONAL-001',
            'invoice_amount' => '100000.25',
            'tax_amount' => '11000.03',
            'tax_invoice_number' => '010.000-26.12345678',
            'invoice' => UploadedFile::fake()->createWithContent('invoice.pdf', $pdf),
            'tax_invoice' => UploadedFile::fake()->image('tax.png'),
        ];
        $this->actingAs($this->supplier)->post(route('local-supplier.invoices.store'), $payload)->assertSessionHasNoErrors();
        $this->invoice = LocalInvoice::sole();
        $this->invoice->forceFill(['status' => 'PAYMENT_SCHEDULED', 'due_date' => '2026-10-05', 'scheduled_payment_date' => '2026-10-08'])->save();
    }

    public function test_finance_due_and_scheduled_calendar_dates_follow_preference_while_amounts_and_submitted_time_stay_fixed(): void
    {
        $baseline = $this->actingAs($this->systemFinance)->get(route('finance.invoices.index'))->assertOk()->getContent();
        $legacySubmittedCell = $this->cell($baseline, $this->invoice->submission_number, 3);
        $this->assertNotSame('', $legacySubmittedCell);

        $this->preference($this->finance, 'indonesian', 'dmy');
        app()->forgetScopedInstances(); // Simulate the next request boundary after changing accounts.
        $lookups = $this->watchPreferences();
        $after = $this->actingAs($this->finance)->get(route('finance.invoices.index'))->assertOk()->getContent();
        $financeDueCell = $this->cell($after, $this->invoice->submission_number, 4);
        $this->assertTrue(str_contains($financeDueCell, '05/10/2026'), 'Finance due date should use DMY display.');
        $this->assertTrue(str_contains($after, 'Rp 100.000'), 'Invoice amount remains fixed.');
        $this->assertTrue(str_contains($after, 'PPN: Rp 11.000'), 'PPN remains fixed.');
        $this->assertSame($legacySubmittedCell, $this->cell($after, $this->invoice->submission_number, 3), 'Submitted timestamp cell remains byte-identical across Regional settings.');
        $this->assertSame(1, $lookups->count);
        $this->assertSame('2026-10-05', $this->invoice->fresh()->due_date->toDateString());
        $this->assertSame('2026-10-08', $this->invoice->fresh()->scheduled_payment_date->toDateString());
    }

    public function test_accounting_and_local_supplier_shared_table_only_change_the_due_date_label(): void
    {
        $this->preference($this->accounting, 'indonesian', 'iso');
        $this->preference($this->supplier, 'indonesian', 'human');
        $accountingQueries = $this->watchPreferences();
        $accounting = $this->actingAs($this->accounting)->get(route('accounting.payment-schedule'))->assertOk()->getContent();
        $this->assertSame(1, $accountingQueries->count);
        $this->assertTrue(str_contains($accounting, '2026-10-05'), 'Accounting due date should use ISO display.');
        $this->assertTrue(str_contains($accounting, '2026-10-08'), 'Scheduled date should use ISO display.');
        $this->assertTrue(str_contains($accounting, 'Rp 100.000'), 'Accounting amount remains fixed.');
        $supplierQueries = $this->watchPreferences();
        $supplier = $this->actingAs($this->supplier)->get(route('local-supplier.invoices.index'))->assertOk()->getContent();
        $this->assertSame(1, $supplierQueries->count);
        $this->assertStringNotContainsString('05/10/2026', $supplier);
        $this->assertTrue(str_contains($supplier, 'Rp 100.000'), 'Supplier amount remains fixed.');
        $this->assertStringContainsString($this->invoice->hash, $supplier);
    }

    private function preference(User $user, string $number, string $date): void
    {
        $defaults = config('user_preferences.defaults');
        unset($defaults['sidebar_revision']);
        $user->preference()->create([...$defaults, 'timezone' => 'Asia/Jakarta', 'date_format' => $date, 'time_format' => '12h', 'number_format' => $number]);
    }

    private function watchPreferences(): object
    {
        $counter = (object) ['count' => 0];
        DB::listen(function ($query) use ($counter): void {
            if (str_contains(strtolower($query->sql), 'user_preferences')) {
                $counter->count++;
            }
        });

        return $counter;
    }

    private function cell(string $html, string $key, int $index): string
    {
        if (! preg_match('/<tr[^>]*>.*?'.preg_quote($key, '/').'.*?<\/tr>/s', $html, $row)) {
            return '';
        }
        preg_match_all('/<td[^>]*>(.*?)<\/td>/s', $row[0], $cells);

        return $cells[1][$index] ?? '';
    }
}
