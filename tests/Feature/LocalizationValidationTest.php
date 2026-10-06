<?php

namespace Tests\Feature;

use App\Http\Requests\Auth\SupplierRegistrationRequest;
use App\Models\GaClaim;
use App\Models\LocalInvoice;
use App\Models\MaterialMaster;
use App\Models\PrItem;
use App\Models\Quotation;
use App\Services\LocalInvoice\LocalPoReferenceService;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class LocalizationValidationTest extends TestCase
{
    public function test_registration_and_nested_preference_attributes_are_human_readable_in_both_languages(): void
    {
        foreach (['en' => ['company name', 'contact person email', 'account number', 'hidden dashboard panel', 'notification delivery mode'], 'id' => ['nama perusahaan', 'email penanggung jawab', 'nomor rekening', 'panel dashboard tersembunyi', 'mode pengiriman notifikasi']] as $locale => $labels) {
            app()->setLocale($locale);
            $validator = Validator::make([
                'dashboard' => ['hidden' => [null]], 'notification_delivery' => ['event' => 'invalid'],
            ], [
                'company_name' => 'required', 'pic_email' => 'required', 'account_number' => 'required',
                'dashboard.hidden.*' => 'required', 'notification_delivery.*' => 'in:normal,silent,off',
            ]);
            $this->assertTrue($validator->fails());
            foreach (['company_name', 'pic_email', 'account_number', 'dashboard.hidden.0', 'notification_delivery.event'] as $i => $field) {
                $message = $validator->errors()->first($field);
                $this->assertStringContainsString($labels[$i], $message);
                $this->assertStringNotContainsString($field, $message);
            }
        }
    }

    public function test_required_email_length_confirmation_and_wildcard_attributes_follow_locale(): void
    {
        foreach (['en' => 'required', 'id' => 'wajib diisi'] as $locale => $expected) {
            app()->setLocale($locale);
            $validator = Validator::make([], ['email' => 'required|email']);
            $this->assertTrue($validator->fails());
            $this->assertStringContainsString($expected, $validator->errors()->first('email'));
            $this->assertStringNotContainsString('validation.', $validator->errors()->first('email'));
            $this->assertStringContainsString($locale === 'en' ? 'email address' : 'alamat email', $validator->errors()->first('email'));
            $validator = Validator::make(['email' => 'invalid', 'password' => 'a', 'password_confirmation' => 'b'], ['email' => 'email', 'password' => 'min:8|confirmed']);
            $this->assertTrue($validator->fails());
            $this->assertCount(2, $validator->errors()->get('password'));
            $this->assertStringContainsString('8', $validator->errors()->first('password'));
            $validator = Validator::make(['items' => [['quantity' => null]]], ['items.*.quantity' => 'required']);
            $this->assertTrue($validator->fails());
            $this->assertStringContainsString($locale === 'en' ? 'item quantity' : 'jumlah item', $validator->errors()->first());
        }
    }

    public function test_auth_security_and_registration_attributes_are_friendly_in_both_languages(): void
    {
        $request = new SupplierRegistrationRequest;
        $rules = $request->rules();
        $this->assertArrayHasKey('cf-turnstile-response', $rules);

        $fields = [
            'cf-turnstile-response' => ['security verification response', 'respons verifikasi keamanan'],
            'reference' => ['registration reference', 'referensi pendaftaran'],
            'access_key' => ['registration access key', 'kunci akses pendaftaran'],
            'session_token' => ['session', 'sesi'],
        ];

        foreach (['en' => 0, 'id' => 1] as $locale => $labelIndex) {
            app()->setLocale($locale);
            $data = [
                'cf-turnstile-response' => str_repeat('x', 2049),
                'reference' => '',
                'access_key' => '',
                'session_token' => '',
            ];
            $validator = Validator::make($data, [
                'cf-turnstile-response' => $rules['cf-turnstile-response'],
                'reference' => 'required',
                'access_key' => 'required',
                'session_token' => 'required',
            ]);

            $this->assertTrue($validator->fails());
            foreach ($fields as $field => $labels) {
                $message = $validator->errors()->first($field);
                $this->assertStringContainsString($labels[$labelIndex], $message);
                $this->assertDoesNotMatchRegularExpression('/(?:cf-turnstile-response|access_key|session_token)/', $message);
            }
        }
    }

    public function test_local_purchase_order_validation_errors_use_the_account_locale(): void
    {
        $service = app(LocalPoReferenceService::class);
        $cases = [
            'Internal PO reference is required.' => [
                'en' => 'Internal PO reference is required.',
                'id' => 'Referensi PO internal wajib diisi.',
            ],
            'Manual Goods Receipt (GR) reference is required.' => [
                'en' => 'Manual Goods Receipt (GR) reference is required.',
                'id' => 'Referensi GR manual wajib diisi.',
            ],
            'Internal PO [PO-LANG-123] does not exist or does not belong to this supplier.' => [
                'en' => 'Internal PO [PO-LANG-123] does not exist or does not belong to this supplier.',
                'id' => 'PO internal [PO-LANG-123] tidak ditemukan atau bukan milik supplier ini.',
            ],
        ];

        foreach ($cases as $exceptionMessage => $translations) {
            foreach ($translations as $locale => $expected) {
                app()->setLocale($locale);
                $message = $service->messageForDisplay(new \InvalidArgumentException($exceptionMessage), 'PO-LANG-123', 'INTERNAL');
                $this->assertSame($expected, $message);
            }
        }
    }

    public function test_historical_comparison_fallback_period_uses_a_locale_key(): void
    {
        $source = file_get_contents(base_path('app/Http/Controllers/Purchasing/PriceComparisonController.php'));
        $this->assertNotFalse($source);
        $this->assertStringContainsString("return __('purchasing.copy.unknown_period');", $source);
        $this->assertStringNotContainsString("return 'Unknown';", $source);
        $this->assertSame('Unknown period', trans('purchasing.copy.unknown_period', [], 'en'));
        $this->assertSame('Periode tidak diketahui', trans('purchasing.copy.unknown_period', [], 'id'));
    }

    public function test_stale_status_and_history_fallbacks_are_localized(): void
    {
        foreach (['en', 'id'] as $locale) {
            app()->setLocale($locale);
            $quotation = new Quotation;
            $quotation->status = 'legacy_unknown';

            $this->assertSame(
                trans('status.meta.unknown_value', ['value' => 'legacy_unknown'], $locale),
                $quotation->statusLabel(),
            );
            $this->assertSame(trans('common.unknown', [], $locale), LocalInvoice::eventLabel('legacy_unknown_event'));
            $this->assertSame(trans('common.unknown', [], $locale), GaClaim::eventLabel('legacy_unknown_event'));
        }

        app()->setLocale('en');
    }

    public function test_hs_category_and_shape_labels_localize_without_changing_machine_values(): void
    {
        foreach ([
            'en' => ['Alloy Steel', 'Honed Tube Steel', 'Flat', 'Hollow', 'Steel', 'Non-Daido', 'Unknown'],
            'id' => ['Baja Paduan', 'Baja untuk Pipa Honed', 'Rata', 'Berongga', 'Baja', 'Non-Daido', 'Tidak Diketahui'],
        ] as $locale => $labels) {
            app()->setLocale($locale);
            $this->assertSame($labels[0], MaterialMaster::hsCategoryLabel('alloy_steel'));
            $this->assertSame($labels[1], MaterialMaster::hsCategoryLabel('honed_tube_steel'));
            $this->assertSame($labels[2], PrItem::shapeLabel('Flat'));
            $this->assertSame($labels[3], PrItem::shapeLabel('Hollow'));
            $this->assertSame($labels[4], MaterialMaster::densityProfileLabel('steel'));
            $this->assertSame($labels[5], MaterialMaster::manufacturerScopeLabel('non_daido'));
            $this->assertSame($labels[6], MaterialMaster::hsCategoryLabel('forged_category'));
            $this->assertSame('alloy_steel', MaterialMaster::HS_CATEGORY_ALLOY);
            $this->assertSame('Flat', PrItem::SHAPE_FLAT);
        }

        app()->setLocale('en');
    }
}
