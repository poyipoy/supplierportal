<?php

namespace Tests\Feature;

use App\Support\JsTranslations;
use Tests\TestCase;

class DataTablesLocalizationTest extends TestCase
{
    public function test_both_locales_have_complete_control_chrome_and_machine_placeholders(): void
    {
        foreach (['en' => ['Search:', 'Previous', 'Next'], 'id' => ['Cari:', 'Sebelumnya', 'Berikutnya']] as $locale => $labels) {
            app()->setLocale($locale);
            $dictionary = __('datatables');
            $this->assertSame($labels[0], $dictionary['search']);
            $this->assertSame($labels[1], $dictionary['paginate']['previous']);
            $this->assertSame($labels[2], $dictionary['paginate']['next']);
            foreach (['_MENU_', '_START_', '_END_', '_TOTAL_', '_MAX_'] as $placeholder) {
                $this->assertStringContainsString($placeholder, json_encode($dictionary));
            }
            $payload = JsTranslations::payload();
            $this->assertSame($locale, $payload['locale']);
            $this->assertSame($labels[0], $payload['messages']['datatables.search']);
        }
    }

    public function test_notification_badge_accessible_name_includes_the_visible_count_in_each_locale(): void
    {
        foreach ([
            'en' => ['Notifications', '1 unread notification', '2 unread notifications'],
            'id' => ['Notifikasi', '1 notifikasi belum dibaca', '2 notifikasi belum dibaca'],
        ] as $locale => [$buttonLabel, $one, $many]) {
            $this->assertSame($buttonLabel, trans('js.notification.button_label', [], $locale));
            $this->assertSame($one, trans('js.notification.unread_one', ['count' => 1], $locale));
            $this->assertSame($many, trans('js.notification.unread_many', ['count' => 2], $locale));
        }

        $navbar = file_get_contents(resource_path('views/partials/navbar.blade.php'));
        $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));
        $this->assertStringContainsString('aria-hidden="true">{{ $initNotifCount }}</span>', $navbar);
        $this->assertStringContainsString("window.AdasiI18n.choice('js.notification.unread_count'", $layout);
    }

    public function test_synchronous_trusted_bootstrap_precedes_vite_and_classic_consumers(): void
    {
        foreach (['app', 'auth', 'guest'] as $layout) {
            $source = file_get_contents(resource_path('views/layouts/'.$layout.'.blade.php'));
            $this->assertLessThan(strpos($source, '@vite'), strpos($source, "@include('partials.i18n-bootstrap')"));
        }
        $source = file_get_contents(resource_path('views/layouts/app.blade.php'));
        $this->assertLessThan(strrpos($source, "@stack('scripts')"), strpos($source, 'window.AdasiDataTable = Object.freeze'));
        $this->assertStringContainsString("language: @js(__('datatables'))", $source);
        $this->assertStringContainsString('new MutationObserver((records)', $source);
        $this->assertStringContainsString("node.querySelectorAll('.dataTables_filter')", $source);
        $this->assertStringContainsString('searchInput.id = `${tableId}-search`', $source);
        $this->assertStringContainsString('searchInput.name = `${tableId}_search`', $source);
        $this->assertStringContainsString("searchInput.setAttribute('aria-label', window.AdasiI18n.t('datatables.search'))", $source);
        $this->assertStringContainsString('pageLength: window.AdasiPreferences?.pageSize || 25', $source);
    }
}
