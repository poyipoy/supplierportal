<?php

namespace Tests\Feature\PaymentVoucher;

use App\Http\Middleware\LimitOperationalRequests;
use App\Models\PaymentVoucher;
use App\Models\User;
use App\Support\Maintenance\MaintenanceModuleRegistry;
use App\Support\Maintenance\RouteClassification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use ReflectionMethod;
use Tests\TestCase;

class ManualPaymentVoucherPdfTest extends TestCase
{
    use RefreshDatabase;

    private const FORBIDDEN_COLOURS = [
        '#1F5FA6', '#1a5290', '#C0392B', '#22c55e', '#ef4444',
        '#dcfce7', '#fee2e2', '#166534', '#991b1b', '#86efac', '#fca5a5',
    ];

    public function test_pdf_is_monochrome_pnr261178_fixed_english_and_locale_invariant(): void
    {
        $financeVoucher = new PaymentVoucher([
            'voucher_number' => 'VC/2611/0001',
            'voucher_date' => '2026-11-11',
            'source_department' => PaymentVoucher::DEPT_FINANCE,
            'status' => PaymentVoucher::STATUS_FINAL,
            'payment_method' => PaymentVoucher::METHOD_BANK,
            'payee_type' => PaymentVoucher::PAYEE_MANUAL,
            'payee_name_snapshot' => 'PT Ekspedisi Pelabuhan Nusantara Mandiri Sejahtera Abadi',
            'bank_name_snapshot' => 'BANK BCA',
            'bank_account_snapshot' => '5220310053',
            'bank_account_holder_snapshot' => 'PT Ekspedisi Pelabuhan',
            'npwp_snapshot' => '01.234.567.8-901.000',
            'reference_number' => 'PO/11/2026/001 · INV-991',
            'description' => 'Pembayaran jasa kepabeanan kontainer 40ft dan biaya handling gudang Cikarang',
            'notes' => 'Mohon lampirkan bukti transfer saat arsip',
            'dpp_amount' => '10000000.00',
            'ppn_amount' => '1100000.00',
            'pph_amount' => '200000.00',
            'bank_fee' => '2500.00',
            'net_amount' => '10897500.00',
            'terbilang_snapshot' => 'Sepuluh Juta Delapan Ratus Sembilan Puluh Tujuh Ribu Lima Ratus Rupiah',
            'issued_at' => now(),
        ]);
        $financeVoucher->setRelation('creator', new User(['name' => 'Siti Finance']));

        app()->setLocale('en');
        $english = view('pdf.manual-payment-voucher', ['voucher' => $financeVoucher])->render();
        app()->setLocale('id');
        $indonesian = view('pdf.manual-payment-voucher', ['voucher' => $financeVoucher])->render();
        app()->setLocale('en');

        $this->assertSame($english, $indonesian);

        foreach (self::FORBIDDEN_COLOURS as $colour) {
            $this->assertStringNotContainsStringIgnoringCase($colour, $english);
        }

        $this->assertStringNotContainsString('border-radius', $english);
        $this->assertStringContainsString("font-family: 'PO Noto Sans'", $english);
        $this->assertStringContainsString('size: A4 portrait', $english);
        $this->assertStringContainsString('Page   1   of   1', $english);
        $this->assertStringContainsString('class="doc-number-label">VOUCHER NO.<', $english);
        $this->assertStringContainsString('PAYMENT VOUCHER', $english);
        $this->assertStringContainsString('FINANCE &amp; ACCOUNTS PAYABLE DEPARTMENT', $english);
        $this->assertStringContainsString('10.897.500,00', $english);
        $this->assertStringContainsString('PPh / Withholding', $english);
        $this->assertStringContainsString('Bank Fee', $english);
        $this->assertStringContainsString('Sepuluh Juta Delapan Ratus', $english);
        $this->assertStringNotContainsString('Date DRP', $english);
        $this->assertStringNotContainsString('VOID / CANCELLED', $english);

        // Purchasing simple KAS voucher renders Purchasing letterhead cleanly without zero-tax noise rows
        $purchasingVoucher = new PaymentVoucher([
            'voucher_number' => 'VC/2611/0002',
            'voucher_date' => '2026-11-11',
            'source_department' => PaymentVoucher::DEPT_PURCHASING,
            'status' => PaymentVoucher::STATUS_CANCELLED,
            'payment_method' => PaymentVoucher::METHOD_KAS,
            'payee_type' => PaymentVoucher::PAYEE_MANUAL,
            'payee_name_snapshot' => 'Kurir Operasional',
            'reference_number' => null,
            'description' => 'Pembelian materai operasional pengadaan',
            'dpp_amount' => '250000.00',
            'ppn_amount' => '0.00',
            'pph_amount' => '0.00',
            'bank_fee' => '0.00',
            'net_amount' => '250000.00',
            'terbilang_snapshot' => 'Dua Ratus Lima Puluh Ribu Rupiah',
            'void_reason' => 'Nominal berubah',
            'voided_at' => now(),
            'issued_at' => now(),
        ]);
        $purchasingVoucher->setRelation('creator', new User(['name' => 'Andi Purchasing']));
        $purchasingVoucher->setRelation('voider', new User(['name' => 'Andi Purchasing']));

        $purchasingHtml = view('pdf.manual-payment-voucher', ['voucher' => $purchasingVoucher])->render();
        $this->assertStringContainsString('PURCHASING PROCUREMENT DEPARTMENT', $purchasingHtml);
        $this->assertStringContainsString('VOID / CANCELLED', $purchasingHtml);
        $this->assertStringContainsString('Nominal berubah', $purchasingHtml);
        $this->assertStringNotContainsString('PPh / Withholding', $purchasingHtml);
    }

    public function test_print_endpoints_serve_pdf_with_same_origin_framing_and_download_options(): void
    {
        $finance = User::factory()->create(['role' => 'finance', 'is_active' => true, 'account_status' => User::ACCOUNT_STATUS_ACTIVE]);
        $purchasing = User::factory()->create(['role' => 'purchasing', 'is_active' => true, 'account_status' => User::ACCOUNT_STATUS_ACTIVE]);

        $voucher = PaymentVoucher::create([
            'voucher_number' => 'VC/2611/0099',
            'voucher_date' => '2026-11-11',
            'source_department' => PaymentVoucher::DEPT_FINANCE,
            'status' => PaymentVoucher::STATUS_FINAL,
            'payment_method' => PaymentVoucher::METHOD_KAS,
            'payee_type' => PaymentVoucher::PAYEE_MANUAL,
            'payee_name_snapshot' => 'Toko ATK Cikarang',
            'description' => 'Biaya ATK Administrasi',
            'dpp_amount' => '150000.00',
            'ppn_amount' => '0.00',
            'pph_amount' => '0.00',
            'bank_fee' => '0.00',
            'net_amount' => '150000.00',
            'terbilang_snapshot' => 'Seratus Lima Puluh Ribu Rupiah',
            'created_by' => $finance->id,
            'issued_at' => now(),
        ]);

        $inlineFinance = $this->actingAs($finance)->get(route('finance.payment-vouchers.print', $voucher));
        $inlineFinance->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Content-Security-Policy', "frame-ancestors 'self'")
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $downloadPurchasing = $this->actingAs($purchasing)->get(route('purchasing.payment-vouchers.print', ['paymentVoucher' => $voucher, 'download' => 1]));
        $downloadPurchasing->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('attachment', (string) $downloadPurchasing->headers->get('Content-Disposition'));
    }

    public function test_routes_use_expected_rate_limit_and_maintenance_classification(): void
    {
        $policy = new ReflectionMethod(LimitOperationalRequests::class, 'policy');
        $policy->setAccessible(true);
        $middleware = new LimitOperationalRequests;

        foreach (['finance.payment-vouchers.print', 'purchasing.payment-vouchers.print'] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, $name);
            $request = Request::create('/'.$route->uri(), 'GET');
            $request->setRouteResolver(fn () => $route);
            $this->assertSame('pdf', $policy->invoke($middleware, $request), $name);
        }

        foreach (['finance.payment-vouchers.store', 'purchasing.payment-vouchers.store'] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, $name);
            $request = Request::create('/'.$route->uri(), 'POST');
            $request->setRouteResolver(fn () => $route);
            $this->assertSame('uploads', $policy->invoke($middleware, $request), $name);
        }

        $registry = app(MaintenanceModuleRegistry::class);
        foreach ([
            'finance.payment-vouchers.index',
            'finance.payment-vouchers.store',
            'finance.payment-vouchers.print',
            'purchasing.payment-vouchers.index',
            'purchasing.payment-vouchers.store',
            'purchasing.payment-vouchers.print',
        ] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, $name);
            $this->assertSame(RouteClassification::PORTAL, $registry->classifyRoute($route)->kind, $name);
        }
    }
}
