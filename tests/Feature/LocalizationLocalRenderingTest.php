<?php

namespace Tests\Feature;

use App\Models\GaClaim;
use App\Models\LocalInvoice;
use App\Models\LocalInvoiceGoodsReceipt;
use App\Models\PaymentBatch;
use App\Models\PaymentItem;
use App\Models\User;
use App\Support\StatusHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class LocalizationLocalRenderingTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_role_registers_render_the_account_language_without_changing_destinations(): void
    {
        $cases = [
            ['ga', 'ga.claims.index', 'GA Claim Register', 'Daftar Klaim GA'],
            ['finance', 'finance.invoices.index', 'Invoice', 'Invoice'],
            ['accounting', 'accounting.invoices.index', 'Invoice', 'Invoice'],
        ];
        foreach ($cases as [$role, $route, $english, $indonesian]) {
            $user = User::factory()->create(['role' => $role]);
            foreach (['en' => $english, 'id' => $indonesian] as $locale => $heading) {
                $user->preference()->updateOrCreate([], [...config('user_preferences.defaults'), 'locale' => $locale]);
                app()->forgetScopedInstances();
                $this->actingAs($user)->get(route($route))->assertOk()
                    ->assertSee('lang="'.$locale.'"', false)->assertSee($heading)
                    ->assertSee(route($route), false);
            }
        }
    }

    public function test_authenticated_password_assistance_uses_account_locale_without_adding_reset_flow(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $user->preference()->create([...config('user_preferences.defaults'), 'locale' => 'id']);

        $this->actingAs($user)->get(route('password.request'))->assertOk()
            ->assertSee('lang="id"', false)
            ->assertSeeText('Bantuan Kata Sandi')
            ->assertSeeText('Supplier Portal - Permintaan Bantuan Kata Sandi')
            ->assertDontSee('name="token"', false)
            ->assertDontSee('name="password_confirmation"', false);
    }

    public function test_invoice_po_table_header_uses_the_account_locale(): void
    {
        $user = User::factory()->create(['role' => 'accounting']);

        foreach (['en', 'id'] as $locale) {
            $user->preference()->updateOrCreate([], [...config('user_preferences.defaults'), 'locale' => $locale]);
            app()->forgetScopedInstances();

            $this->actingAs($user)->get(route('accounting.invoices.index'))
                ->assertOk()
                ->assertSee('lang="'.$locale.'"', false)
                ->assertSeeText(trans('local_invoice.table.invoice_po', [], $locale));
        }
    }

    public function test_populated_drp_pages_localize_batch_and_group_status_without_changing_records(): void
    {
        foreach (['finance', 'purchasing'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            foreach (['en', 'id'] as $locale) {
                $user->preference()->updateOrCreate([], [...config('user_preferences.defaults'), 'locale' => $locale]);
                app()->forgetScopedInstances();
                foreach (['SUPPLIER', 'GA'] as $type) {
                    $batch = PaymentBatch::create([
                        'batch_number' => "CLOSURE-{$role}-{$locale}-{$type}", 'batch_type' => $type,
                        'status' => 'PARTIALLY_PAID', 'created_by' => $user->id,
                    ]);
                    $group = $batch->groups()->create([
                        'payee_type' => 'supplier', 'payee_id' => $user->id, 'payee_name' => 'Closure Payee',
                        'bank_name' => 'BCA', 'account_number' => '1234567', 'account_holder_name' => 'Closure Payee',
                        'status' => 'UNPAID',
                    ]);
                    $this->actingAs($user)->get(route($role.'.drp.show', $batch))->assertOk()
                        ->assertSee($locale === 'en' ? 'Partially Paid' : 'Dibayar Sebagian')
                        ->assertSee($locale === 'en' ? 'Unpaid' : 'Belum Dibayar')
                        ->assertSee('Closure Payee')->assertSee('1234567')
                        ->assertSeeText(trans('finance.drp_ui.dpp_amount', [], $locale))
                        ->assertSeeText(trans('finance.drp_ui.ppn_amount', [], $locale));
                    $this->get(route($role.'.drp.'.strtolower($type)))->assertOk()
                        ->assertSee($locale === 'en' ? 'Partially Paid' : 'Dibayar Sebagian');
                    $this->assertSame('PARTIALLY_PAID', $batch->fresh()->status);
                    $this->assertSame('UNPAID', $group->fresh()->status);
                }
            }
        }
    }

    public function test_canonical_claim_types_have_consistent_localized_labels(): void
    {
        $expected = [
            'en' => 'Business Travel',
            'id' => 'Perjalanan Dinas',
        ];
        foreach ($expected as $locale => $label) {
            app()->setLocale($locale);
            $this->assertSame($label, GaClaim::claimTypeLabel(GaClaim::TYPE_BUSINESS_TRAVEL));
            $this->assertSame('Business Travel', GaClaim::TYPE_BUSINESS_TRAVEL);
            $this->assertContains('Business Travel', GaClaim::CLAIM_TYPES);
        }
    }

    public function test_local_dictionaries_use_plain_copy_and_preserve_interpolation(): void
    {
        foreach (['en', 'id'] as $locale) {
            $text = trans_choice('local_invoice.list.summary', 12, [], $locale);
            $this->assertStringContainsString('12', $text);
            $this->assertStringNotContainsString('local_invoice.', $text);
            $this->assertStringNotContainsString('<', $text);
        }
    }

    public function test_invoice_counters_select_complete_singular_and_plural_messages(): void
    {
        $this->assertSame('Displaying 1 invoice.', trans_choice('local_invoice.list.summary', 1, [], 'en'));
        $this->assertSame('Displaying 0 invoices.', trans_choice('local_invoice.list.summary', 0, [], 'en'));
        $this->assertSame('Displaying 12 invoices.', trans_choice('local_invoice.list.summary', 12, [], 'en'));
        $this->assertSame('1 invoice in the active queue', trans_choice('finance.review.outstanding_count', 1, [], 'en'));
        $this->assertSame('2 invoices in the active queue', trans_choice('finance.review.outstanding_count', 2, [], 'en'));
        $this->assertSame('Menampilkan 1 data invoice.', trans_choice('local_invoice.list.summary', 1, [], 'id'));
        $this->assertSame('1 Item Pending Selection', trans_choice('purchasing.page.unselected_count', 1, [], 'en'));
        $this->assertSame('2 Items Pending Selection', trans_choice('purchasing.page.unselected_count', 2, [], 'en'));
    }

    public function test_gr_snapshot_badges_translate_display_without_mutating_states(): void
    {
        foreach (['en' => ['Reserved', 'Consumed', 'Released'], 'id' => ['Direservasi', 'Digunakan', 'Dilepas']] as $locale => $labels) {
            app()->setLocale($locale);
            foreach (['RESERVED', 'CONSUMED', 'RELEASED'] as $index => $state) {
                $snapshot = new LocalInvoiceGoodsReceipt(['state' => $state]);
                $html = Blade::render('<x-ui.status-chip>{{ \App\Support\StatusHelper::localFinanceLabel($snapshot->state) }}</x-ui.status-chip>', ['snapshot' => $snapshot]);
                $this->assertStringContainsString($labels[$index], $html);
                $this->assertSame($state, $snapshot->state);
            }
        }
    }

    public function test_local_invoice_titles_and_goods_receipt_date_labels_use_locale_terms(): void
    {
        foreach ([
            'en' => ['Invoice', 'Date'],
            'id' => ['Invoice', 'Tanggal'],
        ] as $locale => [$invoice, $date]) {
            app()->setLocale($locale);
            $this->assertSame($invoice, __('terms.invoice'));
            $this->assertSame($date, __('local_invoice.labels.date'));
        }

        foreach ([
            'resources/views/finance/invoices/show.blade.php',
            'resources/views/local-invoices/detail.blade.php',
        ] as $path) {
            $source = file_get_contents(base_path($path));
            $this->assertNotFalse($source, $path);
            $this->assertStringContainsString(":title=\"__('terms.invoice').' ", $source, $path);
        }

        $form = file_get_contents(base_path('resources/views/local-invoices/form.blade.php'));
        $this->assertNotFalse($form);
        $this->assertStringContainsString("window.AdasiI18n.t('js.validation.digit_progress'", $form);
        $expectedSublabel = <<<'BLADE'
'sublabel' => $dateFormatted ? __('local_invoice.labels.date') . ': ' . $dateFormatted : null
BLADE;
        $this->assertStringContainsString($expectedSublabel, $form);
    }

    public function test_remaining_operational_status_cells_use_localized_labels_without_changing_codes(): void
    {
        $views = [
            'resources/views/local-supplier/vendor-profile/show.blade.php' => [
                'StatusHelper::localFinanceLabel($b->status)',
            ],
            'resources/views/purchasing/local-vendors/show.blade.php' => [
                'StatusHelper::localFinanceLabel($b->status)',
            ],
            'resources/views/finance/invoices/show.blade.php' => [
                'StatusHelper::localFinanceLabel($invoice->voucher->payment->status)',
            ],
            'resources/views/local-supplier/purchase-orders/show.blade.php' => [
                'StatusHelper::localInvoiceLabel($inv->status)',
            ],
            'resources/views/finance/drp/show.blade.php' => [
                'StatusHelper::localFinanceLabel($item->localInvoicePayment->status)',
                'StatusHelper::localFinanceLabel($item->status)',
            ],
            'resources/views/purchasing/drp/show.blade.php' => [
                'StatusHelper::localFinanceLabel($item->localInvoicePayment->status)',
                'StatusHelper::localFinanceLabel($item->status)',
            ],
            'resources/views/purchasing/shipments/show.blade.php' => [
                'StatusHelper::qcLabel($insp->status)',
            ],
            'resources/views/pdf/qc-inspection-pdf.blade.php' => [
                'StatusHelper::qcLabel($inspection->status)',
                'StatusHelper::qcLabel($item->status)',
            ],
        ];

        foreach ($views as $path => $presenters) {
            $source = file_get_contents(base_path($path));
            $this->assertNotFalse($source, $path);
            foreach ($presenters as $presenter) {
                $this->assertStringContainsString($presenter, $source, $path);
            }
        }

        foreach ([
            'en' => ['Verified', 'Correction Required', 'Removed', 'Waiting for Physical Documents'],
            'id' => ['Terverifikasi', 'Perlu Koreksi', 'Dikeluarkan', 'Menunggu Dokumen Fisik'],
        ] as $locale => $labels) {
            app()->setLocale($locale);
            $this->assertSame($labels[0], StatusHelper::localFinanceLabel('VERIFIED'));
            $this->assertSame($labels[1], StatusHelper::localFinanceLabel('CORRECTION_REQUIRED'));
            $this->assertSame($labels[2], StatusHelper::localFinanceLabel('REMOVED'));
            $this->assertSame($labels[3], StatusHelper::localInvoiceLabel('WAITING_PHYSICAL_DOCUMENT'));
            $this->assertSame('OK', StatusHelper::qcLabel('ok'));
        }

        $this->assertSame('REMOVED', PaymentItem::STATUS_REMOVED);
        $this->assertSame('WAITING_PHYSICAL_DOCUMENT', LocalInvoice::STATUS_WAITING_PHYSICAL_DOCUMENT);
    }
}
