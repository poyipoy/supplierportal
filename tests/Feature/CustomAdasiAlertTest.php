<?php

namespace Tests\Feature;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class CustomAdasiAlertTest extends TestCase
{
    public function test_custom_alert_assets_are_loaded_by_application_and_auth_layouts(): void
    {
        $appLayout = file_get_contents(resource_path('views/layouts/app.blade.php'));
        $authLayout = file_get_contents(resource_path('views/layouts/auth.blade.php'));
        $guestLayout = file_get_contents(resource_path('views/layouts/guest.blade.php'));

        foreach ([$appLayout, $authLayout, $guestLayout] as $layout) {
            $this->assertStringContainsString("assets/css/adasi-alert.css", $layout);
            $this->assertStringContainsString("sweetalert2@11.7.32", $layout);
            $this->assertStringContainsString("assets/js/adasi-alert.js", $layout);
        }
    }

    public function test_global_facade_exposes_the_approved_alert_contract(): void
    {
        $runtime = file_get_contents(public_path('assets/js/adasi-alert.js'));

        foreach ([
            'confirm',
            'confirmDanger',
            'prompt',
            'success',
            'error',
            'warning',
            'info',
            'toast',
            'notification',
        ] as $method) {
            $this->assertMatchesRegularExpression('/\\b'.preg_quote($method, '/').'\\s*:/', $runtime);
        }

        $this->assertStringContainsString('window.AdasiAlert = Object.freeze(AdasiAlert)', $runtime);
        $this->assertStringContainsString('allowOutsideClick: false', $runtime);
        $this->assertStringContainsString('window.AdasiToast.show(payload)', $runtime);
        $this->assertStringContainsString('window.__adasiToastQueue.push(payload)', $runtime);
        $this->assertStringNotContainsString('toast: true', $runtime);
        $this->assertStringNotContainsString('adasi-alert-toast', $runtime);
    }

    public function test_blade_views_do_not_call_sweetalert_directly(): void
    {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views')));

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            $this->assertDoesNotMatchRegularExpression(
                '/\\bSwal\\.(?:fire|mixin)\\s*\\(/',
                $contents,
                "Direct SweetAlert call remains in {$file->getPathname()}"
            );
            $this->assertStringNotContainsString('confirmButtonColor', $contents);
            $this->assertStringNotContainsString('cancelButtonColor', $contents);
        }
    }

    public function test_flash_messages_are_escaped_and_validation_errors_remain_inline(): void
    {
        $response = $this
            ->withSession(['success' => '<img src=x onerror=alert(1)>'])
            ->get('/login');

        $response->assertOk();
        $response->assertSee('data-adasi-flash', false);
        $response->assertSee('&lt;img src=x onerror=alert(1)&gt;', false);
        $response->assertDontSee('data-message="<img', false);

        $partial = file_get_contents(resource_path('views/partials/alerts.blade.php'));
        $this->assertStringContainsString('$errors->any()', $partial);
        $this->assertStringContainsString('<x-ui.alert tone="error"', $partial);
        $this->assertStringNotContainsString('alert alert-danger', $partial);
    }

    public function test_export_confirmation_retains_the_single_download_guard(): void
    {
        $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));

        $this->assertStringContainsString('window.exportConfirmationOpen', $layout);
        $this->assertStringContainsString('AdasiAlert.confirm({', $layout);
        $this->assertStringContainsString("let recordsTotal = 'all'", $layout);
        $this->assertStringNotContainsString("recordsTotal = 'seluruh'", $layout);
        $this->assertStringNotContainsString('Buka Bantuan Ini', $layout);
        $this->assertStringNotContainsString("confirmText: 'Tutup'", $layout);
        $this->assertStringContainsString('window.location.href = exportBtn.href', $layout);
        $this->assertSame(1, substr_count($layout, 'window.location.href = exportBtn.href'));
    }

    public function test_pdf_links_use_the_global_confirmation_guard(): void
    {
        $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));

        $this->assertStringContainsString('data-pdf-confirm', $layout);
        $this->assertStringContainsString('window.pdfConfirmationOpen', $layout);
        $this->assertStringContainsString("title: 'Download PDF Document?'", $layout);
        $this->assertStringContainsString("confirmText: 'Yes, Download'", $layout);
        $this->assertStringContainsString("window.open(pdfBtn.href, '_blank'", $layout);

        foreach ([
            'purchasing/po/show.blade.php',
            'supplier/po/show.blade.php',
            'qc/inspections/show.blade.php',
        ] as $view) {
            $contents = file_get_contents(resource_path('views/'.$view));
            $this->assertStringContainsString('data-pdf-confirm', $contents, "PDF confirmation is missing from {$view}");
        }
    }

    public function test_supplier_requested_pr_export_is_removed_but_purchasing_detail_export_remains(): void
    {
        $this->assertTrue(Route::has('purchasing.export.requisitions.detail'));
        $this->assertFalse(Route::has('supplier.export.requisitions.detail'));

        foreach ([
            'supplier/quotations/create.blade.php',
            'supplier/quotations/show.blade.php',
            'supplier/quotations/period.blade.php',
        ] as $view) {
            $contents = file_get_contents(resource_path('views/'.$view));
            $this->assertStringNotContainsString('Export Requested PR', $contents, "Requested PR export remains in {$view}");
            $this->assertStringNotContainsString('supplier.export.requisitions.detail', $contents, "Supplier Requested PR route remains in {$view}");
        }
    }
}
