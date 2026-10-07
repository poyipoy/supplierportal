<?php

namespace Tests\Feature;

use App\Http\Controllers\Purchasing\PriceComparisonController;
use App\Imports\QuotationItemsImport;
use App\Models\Conversation;
use App\Models\ExchangeRate;
use App\Models\PrItem;
use App\Models\User;
use App\Support\StatusHelper;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class LocalizationImportRenderingTest extends TestCase
{
    public function test_import_tabs_follow_explicit_locale_without_changing_urls(): void
    {
        app()->setLocale('en');
        $english = Blade::render('<x-purchasing.comparison-tabs active="inter-supplier" />');
        $this->assertStringContainsString('Inter-Supplier', $english);
        app()->setLocale('id');
        $indonesian = Blade::render('<x-purchasing.comparison-tabs active="inter-supplier" />');
        $this->assertStringContainsString('Antar Supplier', $indonesian);
        $this->assertStringContainsString(route('purchasing.comparison.inter-supplier'), $indonesian);
    }

    public function test_dimension_and_conversation_presentation_preserve_machine_values(): void
    {
        app()->setLocale('id');
        $this->assertSame('Ketebalan', PrItem::dimensionLabel('thickness'));
        $this->assertSame('Thickness', PrItem::DIMENSION_LABELS['thickness']);
        $conversation = new Conversation(['status' => Conversation::STATUS_RESOLVED]);
        $viewer = new User(['role' => 'purchasing']);
        $this->assertSame('Selesai', $conversation->statusLabelFor($viewer));
        $this->assertSame(Conversation::STATUS_RESOLVED, $conversation->status);
    }

    public function test_quotation_import_protocol_tokens_stay_fixed_in_both_languages(): void
    {
        $item = new PrItem;
        $item->forceFill(['id' => 7, 'pr_id' => 9, 'shape' => PrItem::SHAPE_FLAT, 'quantity' => 3]);

        foreach (['en', 'id'] as $locale) {
            app()->setLocale($locale);
            $import = new QuotationItemsImport(collect([$item]));
            $import->collection(collect([collect([
                'pr_item_id' => 7,
                'price_per_kg' => null,
                'availability' => 'Not Available',
            ])]));

            $preview = $import->preview();
            $this->assertTrue($preview['success']);
            $this->assertSame('Not Available', $preview['rows'][0]['availability']);
            $this->assertFalse($preview['rows'][0]['is_available']);
            $this->assertSame(7, $preview['rows'][0]['pr_item_id']);
        }
    }

    public function test_import_tracking_and_confirmation_copy_render_by_locale_while_enum_values_stay_raw(): void
    {
        $expected = [
            'en' => ['Draft', 'Yes, Delete', 'Awaiting dispatch', 'Open chat', 'Saving...'],
            'id' => ['Draf', 'Ya, Hapus', 'Menunggu pengiriman', 'Buka percakapan', 'Menyimpan...'],
        ];

        foreach ($expected as $locale => [$draft, $confirmDelete, $dispatch, $openChat, $saving]) {
            app()->setLocale($locale);
            $html = Blade::render(<<<'BLADE'
                <span>{{ __('purchasing.copy.draft') }}</span>
                <span>{{ __('purchasing.audit_ui.confirm_delete') }}</span>
                <span>{{ __('shipments.audit_ui.awaiting_dispatch') }}</span>
                <span>{{ __('purchasing.audit_ui.open_chat') }}</span>
                <span>{{ __('supplier.audit_ui.saving') }}</span>
                BLADE);

            foreach ([$draft, $confirmDelete, $dispatch, $openChat, $saving] as $copy) {
                $this->assertStringContainsString($copy, $html);
            }

            $quotationStatus = 'accepted';
            $this->assertSame(trans('status.quotation.accepted', [], $locale), StatusHelper::quotationLabel($quotationStatus));
            $this->assertSame('accepted', $quotationStatus);
            $this->assertSame('NG', StatusHelper::qcLabel('ng'));
        }
    }

    public function test_currency_option_labels_localize_without_changing_codes_or_constant_api(): void
    {
        $this->assertSame([
            'USD' => 'USD - US Dollar',
            'JPY' => 'JPY - Japanese Yen',
            'IDR' => 'IDR - Indonesian Rupiah',
            'CNY' => 'CNY - Chinese Yuan',
        ], ExchangeRate::CURRENCY_LABELS);

        $expected = [
            'en' => [
                'USD' => 'USD - US Dollar',
                'JPY' => 'JPY - Japanese Yen',
                'IDR' => 'IDR - Indonesian Rupiah',
                'CNY' => 'CNY - Chinese Yuan',
            ],
            'id' => [
                'USD' => 'USD - Dolar Amerika Serikat',
                'JPY' => 'JPY - Yen Jepang',
                'IDR' => 'IDR - Rupiah Indonesia',
                'CNY' => 'CNY - Yuan Tiongkok',
            ],
        ];

        foreach ($expected as $locale => $labels) {
            app()->setLocale($locale);
            $options = ExchangeRate::currencyOptions();
            $this->assertSame(ExchangeRate::CURRENCIES, array_keys($options));
            $this->assertSame($labels, $options);

            $html = Blade::render('<select>@foreach($options as $code => $label)<option value="{{ $code }}">{{ $label }}</option>@endforeach</select>', compact('options'));
            foreach ($labels as $code => $label) {
                $this->assertStringContainsString('<option value="'.$code.'">'.$label.'</option>', $html);
            }
        }
    }

    public function test_price_comparison_structured_status_labels_follow_locale_without_changing_recommendation_logic(): void
    {
        $controller = new PriceComparisonController;
        $method = new \ReflectionMethod(PriceComparisonController::class, 'priceCompetitivenessStatus');

        foreach (['en', 'id'] as $locale) {
            app()->setLocale($locale);
            $notApplicable = $method->invoke($controller, null, 10.0);
            $competitive = $method->invoke($controller, 5.0, 10.0);

            $this->assertSame(__('status.meta.not_applicable'), $notApplicable['label']);
            $this->assertSame(__('status.meta.safe'), $notApplicable['recommendation']);
            $this->assertSame('bg-secondary', $notApplicable['class']);
            $this->assertSame(__('purchasing.copy.competitive'), $competitive['label']);
            $this->assertSame(__('status.meta.safe'), $competitive['recommendation']);
            $this->assertSame('bg-primary', $competitive['class']);
        }

        $source = file_get_contents(app_path('Http/Controllers/Purchasing/PriceComparisonController.php'));
        $this->assertStringContainsString("__('purchasing.copy.quotation_comparison_actions')", $source);
        $this->assertStringContainsString("__('purchasing.copy.view_historical_best_quotation')", $source);
    }

    public function test_requisition_and_comparison_counts_and_actions_use_complete_localized_copy(): void
    {
        $expected = [
            'en' => [
                'one_item' => '1 item',
                'many_items' => '2 items',
                'one_quotation' => '1 quotation',
                'many_quotations' => '2 quotations',
                'delete' => 'Delete requisition',
                'more' => '+2 more',
            ],
            'id' => [
                'one_item' => '1 item',
                'many_items' => '2 item',
                'one_quotation' => '1 penawaran',
                'many_quotations' => '2 penawaran',
                'delete' => 'Hapus permintaan pembelian',
                'more' => '+2 lainnya',
            ],
        ];

        foreach ($expected as $locale => $labels) {
            app()->setLocale($locale);
            $this->assertSame($labels['one_item'], trans_choice('purchasing.copy.item_count', 1));
            $this->assertSame($labels['many_items'], trans_choice('purchasing.copy.item_count', 2));
            $this->assertSame($labels['one_quotation'], trans_choice('purchasing.copy.comparison_quotation_count', 1));
            $this->assertSame($labels['many_quotations'], trans_choice('purchasing.copy.comparison_quotation_count', 2));
            $this->assertSame($labels['delete'], __('purchasing.copy.delete_requisition'));
            $this->assertSame($labels['more'], __('purchasing.copy.more_materials_count', ['count' => 2]));
        }
    }

    public function test_chat_fallbacks_and_short_date_labels_follow_the_account_language(): void
    {
        $conversation = file_get_contents(resource_path('views/conversations/show.blade.php'));
        $drawer = file_get_contents(resource_path('views/partials/chat-drawer.blade.php'));
        $priceHistory = file_get_contents(resource_path('views/supplier/price-history/index.blade.php'));
        $asyncExport = file_get_contents(base_path('public/assets/js/async-export.js'));

        foreach ([$conversation, $drawer, $priceHistory] as $source) {
            $this->assertNotFalse($source);
            $this->assertStringContainsString("window.AdasiI18n.locale === 'id' ? 'id-ID' : 'en-GB'", $source);
        }

        $this->assertNotFalse($asyncExport);
        $this->assertStringContainsString("window.AdasiI18n?.locale === 'id' ? 'id-ID' : 'en-GB'", $asyncExport);
        $this->assertStringContainsString("window.AdasiI18n.t('js.chat.unknown_sender')", $conversation);
        $this->assertStringContainsString("receipt.read_at_display ? 'js.chat.read_at' : 'js.chat.read'", $drawer);
    }
}
